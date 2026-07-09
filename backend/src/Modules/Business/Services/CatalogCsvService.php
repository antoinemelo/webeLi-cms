<?php

declare(strict_types=1);

namespace App\Modules\Business\Services;

use App\Core\Database;
use App\Modules\Business\Catalog\CatalogPricingService;
use App\Modules\Business\Repositories\CatalogBrandRepository;
use App\Modules\Business\Repositories\CatalogCategoryRepository;
use App\Modules\Business\Repositories\CatalogOptionRepository;
use App\Modules\Business\Repositories\CatalogProductRepository;
use App\Modules\Business\Repositories\CatalogVariantRepository;
use InvalidArgumentException;
use Throwable;

final class CatalogCsvService
{
    private const EXPORT_DELIMITER = ';';
    private const MAX_IMPORT_BYTES = 1048576;
    private const HEADERS = [
        'product_id', 'product_name', 'product_slug', 'type', 'status', 'brand', 'category', 'sku_base',
        'is_public', 'is_ecommerce_enabled', 'is_pos_enabled', 'is_catalogue_enabled', 'base_purchase_price',
        'base_sale_price', 'currency', 'tax_class', 'options', 'variant_id', 'variant_sku',
        'variant_barcode', 'variant_name', 'variant_options', 'purchase_adjustment_type',
        'purchase_adjustment_value', 'sale_adjustment_type', 'sale_adjustment_value',
        'computed_purchase_price', 'computed_regular_sale_price', 'computed_final_sale_price',
        'stock_quantity',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly CatalogBrandRepository $brands,
        private readonly CatalogCategoryRepository $categories,
        private readonly CatalogProductRepository $products,
        private readonly CatalogVariantRepository $variants,
        private readonly CatalogOptionRepository $options,
        private readonly CatalogPricingService $pricing,
    ) {}

    /** @param array<string,mixed> $filters */
    public function exportProductsCsv(int $siteId, bool $includePurchasePrices, array $filters = []): string
    {
        $rows = [self::HEADERS];
        [$where, $params] = $this->exportWhere($siteId, $filters);
        $records = $this->db->all(
            'SELECT p.*, b.name AS brand_name, c.name AS category_name, v.id AS variant_id, v.sku AS variant_sku,
                    v.barcode AS variant_barcode, v.name AS variant_name, v.stock_quantity,
                    bp_purchase.amount AS base_purchase_price, bp_purchase.currency AS purchase_currency,
                    bp_sale.amount AS base_sale_price, bp_sale.currency AS sale_currency,
                    adj_purchase.adjustment_type AS purchase_adjustment_type, adj_purchase.adjustment_value AS purchase_adjustment_value,
                    adj_sale.adjustment_type AS sale_adjustment_type, adj_sale.adjustment_value AS sale_adjustment_value
             FROM business_products p
             LEFT JOIN business_product_brands b ON b.id = p.brand_id
             LEFT JOIN business_product_categories c ON c.id = p.category_id
             LEFT JOIN business_product_variants v ON v.product_id = p.id AND v.archived_at IS NULL
             LEFT JOIN business_product_base_prices bp_purchase ON bp_purchase.product_id = p.id AND bp_purchase.price_kind = "purchase" AND bp_purchase.valid_from IS NULL
             LEFT JOIN business_product_base_prices bp_sale ON bp_sale.product_id = p.id AND bp_sale.price_kind = "sale" AND bp_sale.valid_from IS NULL
             LEFT JOIN business_product_variant_price_adjustments adj_purchase ON adj_purchase.variant_id = v.id AND adj_purchase.price_kind = "purchase" AND adj_purchase.valid_from IS NULL
             LEFT JOIN business_product_variant_price_adjustments adj_sale ON adj_sale.variant_id = v.id AND adj_sale.price_kind = "sale" AND adj_sale.valid_from IS NULL
             ' . $where . '
             ORDER BY p.updated_at DESC, p.id DESC, v.sort_order ASC, v.id ASC',
            $params
        );

        foreach ($records as $record) {
            $summary = [];
            if (!empty($record['variant_id'])) {
                try {
                    $summary = $this->pricing->pricingSummary((int) $record['variant_id']);
                } catch (Throwable) {
                    $summary = [];
                }
            }
            $rows[] = [
                $record['id'] ?? '',
                $record['name'] ?? '',
                $record['slug'] ?? '',
                $record['type'] ?? '',
                $record['status'] ?? '',
                $record['brand_name'] ?? '',
                $record['category_name'] ?? '',
                $record['sku_base'] ?? '',
                $this->boolCell($record['is_public'] ?? false),
                $this->boolCell($record['is_ecommerce_enabled'] ?? false),
                $this->boolCell($record['is_pos_enabled'] ?? false),
                $this->boolCell($record['is_catalogue_enabled'] ?? true),
                $includePurchasePrices ? $this->moneyCell($record['base_purchase_price'] ?? null) : '',
                $this->moneyCell($record['base_sale_price'] ?? null),
                $record['sale_currency'] ?? $record['purchase_currency'] ?? 'CHF',
                $record['tax_class_id'] ?? '',
                $this->productOptionsCell((int) $record['id']),
                $record['variant_id'] ?? '',
                $record['variant_sku'] ?? '',
                $record['variant_barcode'] ?? '',
                $record['variant_name'] ?? '',
                !empty($record['variant_id']) ? $this->variantOptionsCell((int) $record['variant_id']) : '',
                $includePurchasePrices ? ($record['purchase_adjustment_type'] ?? 'none') : '',
                $includePurchasePrices ? $this->moneyCell($record['purchase_adjustment_value'] ?? null) : '',
                $record['sale_adjustment_type'] ?? 'none',
                $this->moneyCell($record['sale_adjustment_value'] ?? null),
                $includePurchasePrices ? ($summary['regular_purchase_price'] ?? '') : '',
                $summary['regular_sale_price'] ?? '',
                $summary['final_sale_price'] ?? '',
                $this->moneyCell($record['stock_quantity'] ?? null),
            ];
        }

        return $this->csv($rows);
    }

    /** @param array<string,mixed> $options */
    public function importProductsCsv(int $siteId, string $csv, array $options, ?int $actorId = null): array
    {
        if (strlen($csv) > self::MAX_IMPORT_BYTES) {
            throw new InvalidArgumentException('business.catalog.csv_file_too_large');
        }
        if (!$this->isUtf8($csv)) {
            throw new InvalidArgumentException('business.catalog.csv_utf8_required');
        }

        $dryRun = filter_var($options['dry_run'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $dryRun = $dryRun !== false;
        $createBrands = filter_var($options['create_brands'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $createCategories = filter_var($options['create_categories'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $createOptions = filter_var($options['create_options'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $overwriteExisting = filter_var($options['overwrite_existing'] ?? $options['confirm_overwrite'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $delimiter = $this->detectDelimiter($csv);
        [$headers, $rows] = $this->parseCsv($csv, $delimiter);
        $this->assertKnownHeaders($headers);

        $report = [
            'dry_run' => $dryRun,
            'delimiter' => $delimiter,
            'rows_total' => count($rows),
            'valid_rows' => 0,
            'created_products' => 0,
            'updated_products' => 0,
            'created_variants' => 0,
            'updated_variants' => 0,
            'skipped' => 0,
            'errors' => [],
            'rows' => [],
            'writes_performed' => false,
        ];

        $plans = [];
        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $data = $this->normalizeRow($headers, $row);
            $rowReport = ['line' => $line, 'status' => 'valid', 'action' => 'create', 'errors' => []];
            try {
                $plan = $this->planRow($siteId, $data, $createBrands, $createCategories, $createOptions, $overwriteExisting);
                $rowReport['action'] = $plan['product_action'] . '_' . $plan['variant_action'];
                $rowReport['product_slug'] = $plan['product']['slug'];
                $rowReport['variant_sku'] = $plan['variant']['sku'];
                $plans[] = $plan + ['line' => $line];
                $report['valid_rows']++;
            } catch (InvalidArgumentException $e) {
                $rowReport['status'] = 'error';
                $rowReport['errors'][] = $e->getMessage();
                $report['errors'][] = ['line' => $line, 'message' => $e->getMessage()];
                $report['skipped']++;
            }
            $report['rows'][] = $rowReport;
        }

        if ($report['errors'] !== [] || $dryRun) {
            return $report;
        }

        return $this->db->transaction(function () use ($siteId, $actorId, $plans, $report): array {
            $result = $report;
            foreach ($plans as $plan) {
                $write = $this->applyPlan($siteId, $plan, $actorId);
                $result[$write['product_action'] === 'created' ? 'created_products' : 'updated_products']++;
                $result[$write['variant_action'] === 'created' ? 'created_variants' : 'updated_variants']++;
                $result['writes_performed'] = true;
            }
            return $result;
        });
    }

    /** @param array<string,mixed> $filters @return array{0:string,1:list<mixed>} */
    private function exportWhere(int $siteId, array $filters): array
    {
        $clauses = ['p.site_id = ?', 'p.archived_at IS NULL'];
        $params = [$siteId];

        $channel = trim((string) ($filters['channel'] ?? ''));
        if ($channel !== '') {
            $field = match ($channel) {
                'public' => 'is_public',
                'ecommerce' => 'is_ecommerce_enabled',
                'pos' => 'is_pos_enabled',
                'catalogue' => 'is_catalogue_enabled',
                default => throw new InvalidArgumentException('business.catalog.export_channel_invalid'),
            };
            $clauses[] = 'p.' . $field . ' = 1';
        }

        $quality = trim((string) ($filters['quality'] ?? ''));
        if ($quality !== '') {
            if ($quality !== 'incomplete') {
                throw new InvalidArgumentException('business.catalog.export_quality_invalid');
            }
            $clauses[] = 'NOT EXISTS (
                SELECT 1 FROM business_product_completeness_scores cs
                WHERE cs.product_id = p.id AND cs.variant_id IS NULL AND cs.channel = "all" AND cs.score >= 100
            )';
        }

        return ['WHERE ' . implode(' AND ', $clauses), $params];
    }

    /** @param list<array<string,mixed>> $rows */
    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new InvalidArgumentException('business.catalog.csv_write_failed');
        }
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn(mixed $value): string => $this->exportCell($value), $row), self::EXPORT_DELIMITER, '"', '');
        }
        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);
        return $content === false ? '' : $content;
    }

    /** @return array{0:list<string>,1:list<list<string>>} */
    private function parseCsv(string $csv, string $delimiter): array
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new InvalidArgumentException('business.catalog.csv_read_failed');
        }
        fwrite($handle, $this->stripBom($csv));
        rewind($handle);
        $headers = fgetcsv($handle, 0, $delimiter, '"', '');
        if (!is_array($headers) || $headers === []) {
            fclose($handle);
            throw new InvalidArgumentException('business.catalog.csv_header_required');
        }
        $headers = array_map(fn(mixed $value): string => $this->headerKey((string) $value), $headers);
        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if (!is_array($row) || $this->emptyRow($row)) {
                continue;
            }
            $rows[] = array_map(static fn(mixed $value): string => trim((string) $value), $row);
        }
        fclose($handle);
        return [$headers, $rows];
    }

    /** @param list<string> $headers */
    private function assertKnownHeaders(array $headers): void
    {
        $known = array_fill_keys(self::HEADERS, true);
        foreach ($headers as $header) {
            if (!isset($known[$header])) {
                throw new InvalidArgumentException('business.catalog.csv_unknown_header_' . $header);
            }
        }
        foreach (['product_name', 'product_slug', 'variant_sku'] as $required) {
            if (!in_array($required, $headers, true)) {
                throw new InvalidArgumentException('business.catalog.csv_header_' . $required . '_required');
            }
        }
    }

    /** @param list<string> $headers @param list<string> $row @return array<string,string> */
    private function normalizeRow(array $headers, array $row): array
    {
        $data = [];
        foreach ($headers as $index => $header) {
            $data[$header] = trim((string) ($row[$index] ?? ''));
        }
        return $data;
    }

    /** @param array<string,string> $row @return array<string,mixed> */
    private function planRow(int $siteId, array $row, bool $createBrands, bool $createCategories, bool $createOptions, bool $overwriteExisting): array
    {
        $name = trim($row['product_name'] ?? '');
        $slug = $this->slug($row['product_slug'] ?? $name);
        $sku = trim($row['variant_sku'] ?? '');
        if ($name === '') {
            throw new InvalidArgumentException('business.catalog.product_name_required');
        }
        if ($sku === '') {
            throw new InvalidArgumentException('business.catalog.sku_invalid');
        }
        $productId = (int) ($row['product_id'] ?? 0);
        $variantId = (int) ($row['variant_id'] ?? 0);
        $existingProduct = $productId > 0 ? $this->products->find($siteId, $productId, true) : $this->findProductBySlug($siteId, $slug);
        if ($existingProduct !== null && !$overwriteExisting) {
            throw new InvalidArgumentException('business.catalog.import_product_exists');
        }
        $existingVariant = $variantId > 0 ? $this->variants->findById($variantId, true) : $this->findVariantBySku($sku);
        if ($existingVariant !== null && !$overwriteExisting) {
            throw new InvalidArgumentException('business.catalog.import_variant_exists');
        }
        if ($existingVariant !== null && (int) $existingVariant['site_id'] !== $siteId) {
            throw new InvalidArgumentException('business.catalog.import_variant_site_mismatch');
        }
        if ($existingVariant !== null) {
            $targetProductId = $existingProduct['id'] ?? null;
            if ($targetProductId === null || (int) $existingVariant['product_id'] !== (int) $targetProductId) {
                throw new InvalidArgumentException('business.catalog.import_variant_product_mismatch');
            }
        }

        $brand = $this->namedEntity($siteId, 'brand', $row['brand'] ?? '', $createBrands);
        $category = $this->namedEntity($siteId, 'category', $row['category'] ?? '', $createCategories);
        $variantOptions = $this->parseVariantOptions($row['variant_options'] ?? '');
        $optionPlans = $this->planOptions($siteId, $variantOptions, $createOptions);

        foreach (['base_purchase_price', 'base_sale_price', 'purchase_adjustment_value', 'sale_adjustment_value', 'stock_quantity'] as $field) {
            $this->assertNonNegativeNumber($row[$field] ?? '', $field);
        }

        return [
            'product_action' => $existingProduct === null ? 'create' : 'update',
            'variant_action' => $existingVariant === null ? 'create' : 'update',
            'existing_product_id' => $existingProduct['id'] ?? null,
            'existing_variant_id' => $existingVariant['id'] ?? null,
            'brand' => $brand,
            'category' => $category,
            'option_plans' => $optionPlans,
            'product' => [
                'name' => $name,
                'slug' => $slug,
                'type' => $this->choice($row['type'] ?? 'physical', ['physical', 'service', 'gift_card', 'bundle'], 'type'),
                'status' => $this->choice($row['status'] ?? 'draft', ['draft', 'active', 'archived'], 'status'),
                'sku_base' => $row['sku_base'] ?? '',
                'tax_class_id' => trim($row['tax_class'] ?? '') !== '' ? (int) $row['tax_class'] : null,
                'is_public' => $this->boolValue($row['is_public'] ?? '0'),
                'is_ecommerce_enabled' => $this->boolValue($row['is_ecommerce_enabled'] ?? '0'),
                'is_pos_enabled' => $this->boolValue($row['is_pos_enabled'] ?? '0'),
                'is_catalogue_enabled' => $this->boolValue($row['is_catalogue_enabled'] ?? '1'),
                'base_purchase_price' => $this->numberOrNull($row['base_purchase_price'] ?? ''),
                'base_sale_price' => $this->numberOrNull($row['base_sale_price'] ?? ''),
                'currency' => strtoupper(trim($row['currency'] ?? 'CHF') ?: 'CHF'),
            ],
            'variant' => [
                'sku' => $sku,
                'barcode' => $row['variant_barcode'] ?? '',
                'name' => trim($row['variant_name'] ?? '') ?: $sku,
                'status' => $this->choice($row['status'] ?? 'draft', ['draft', 'active', 'archived'], 'variant_status'),
                'stock_quantity' => $this->numberOrNull($row['stock_quantity'] ?? '') ?? 0,
                'purchase_adjustment_type' => $this->adjustmentType($row['purchase_adjustment_type'] ?? 'none'),
                'purchase_adjustment_value' => $this->numberOrNull($row['purchase_adjustment_value'] ?? ''),
                'sale_adjustment_type' => $this->adjustmentType($row['sale_adjustment_type'] ?? 'none'),
                'sale_adjustment_value' => $this->numberOrNull($row['sale_adjustment_value'] ?? ''),
                'option_values' => $variantOptions,
            ],
        ];
    }

    /** @param array<string,mixed> $plan @return array{product_action:string,variant_action:string} */
    private function applyPlan(int $siteId, array $plan, ?int $actorId): array
    {
        $brandId = $this->ensureNamedEntityId($siteId, 'brand', $plan['brand'], $actorId);
        $categoryId = $this->ensureNamedEntityId($siteId, 'category', $plan['category'], $actorId);
        $optionIds = $this->ensureOptions($siteId, $plan['option_plans'], $actorId);

        $productPayload = $plan['product'];
        $productPayload['brand_id'] = $brandId;
        $productPayload['category_id'] = $categoryId;
        $productPayload['channels'] = array_values(array_filter([
            !empty($productPayload['is_public']) ? 'public' : null,
            !empty($productPayload['is_ecommerce_enabled']) ? 'ecommerce' : null,
            !empty($productPayload['is_pos_enabled']) ? 'pos' : null,
            !empty($productPayload['is_catalogue_enabled']) ? 'catalogue' : null,
        ]));

        if ($plan['product_action'] === 'create') {
            $product = $this->products->create($siteId, $productPayload + ['status' => $productPayload['status']], $actorId);
            $productAction = 'created';
        } else {
            $productId = (int) $plan['existing_product_id'];
            $this->updateProductCore($siteId, $productId, $productPayload, $actorId);
            $product = $this->products->find($siteId, $productId, true) ?? [];
            $productAction = 'updated';
        }

        $productId = (int) $product['id'];
        $this->products->replaceOptionLinks($siteId, $productId, $optionIds);
        $this->setBasePrices($productId, $productPayload, $actorId);

        $variantPayload = $plan['variant'];
        if ($plan['variant_action'] === 'create') {
            $this->variants->create($siteId, $productId, $variantPayload, $actorId);
            $variantAction = 'created';
        } else {
            $variantId = (int) $plan['existing_variant_id'];
            $this->variants->update($siteId, $variantId, $variantPayload, $actorId);
            $this->variants->setAdjustment($variantId, 'purchase', (string) $variantPayload['purchase_adjustment_type'], $variantPayload['purchase_adjustment_value'], $actorId);
            $this->variants->setAdjustment($variantId, 'sale', (string) $variantPayload['sale_adjustment_type'], $variantPayload['sale_adjustment_value'], $actorId);
            $variantAction = 'updated';
        }

        return ['product_action' => $productAction, 'variant_action' => $variantAction];
    }

    /** @param array<string,mixed> $payload */
    private function updateProductCore(int $siteId, int $productId, array $payload, ?int $actorId): void
    {
        $this->db->run(
            'UPDATE business_products
             SET brand_id = :brand_id, category_id = :category_id, type = :type, status = :status, visibility = :visibility,
                 sku_base = :sku_base, name = :name, slug = :slug, tax_class_id = :tax_class_id,
                 is_public = :is_public, is_ecommerce_enabled = :is_ecommerce_enabled, is_pos_enabled = :is_pos_enabled, is_catalogue_enabled = :is_catalogue_enabled,
                 updated_by_iam_user_id = :actor, updated_at = CURRENT_TIMESTAMP
             WHERE site_id = :site_id AND id = :id',
            [
                'site_id' => $siteId,
                'id' => $productId,
                'brand_id' => $payload['brand_id'] ?? null,
                'category_id' => $payload['category_id'] ?? null,
                'type' => $payload['type'],
                'status' => $payload['status'],
                'visibility' => !empty($payload['is_public']) ? 'public' : 'internal',
                'sku_base' => trim((string) ($payload['sku_base'] ?? '')) ?: null,
                'name' => $payload['name'],
                'slug' => $payload['slug'],
                'tax_class_id' => trim((string) ($payload['tax_class_id'] ?? '')) ?: null,
                'is_public' => !empty($payload['is_public']) ? 1 : 0,
                'is_ecommerce_enabled' => !empty($payload['is_ecommerce_enabled']) ? 1 : 0,
                'is_pos_enabled' => !empty($payload['is_pos_enabled']) ? 1 : 0,
                'is_catalogue_enabled' => !empty($payload['is_catalogue_enabled']) ? 1 : 0,
                'actor' => $actorId,
            ]
        );
    }

    /** @param array<string,mixed> $payload */
    private function setBasePrices(int $productId, array $payload, ?int $actorId): void
    {
        $currency = (string) ($payload['currency'] ?? 'CHF');
        if ($payload['base_purchase_price'] !== null) {
            $this->products->setBasePrice($productId, 'purchase', $payload['base_purchase_price'], $currency, false, $actorId);
        }
        if ($payload['base_sale_price'] !== null) {
            $this->products->setBasePrice($productId, 'sale', $payload['base_sale_price'], $currency, true, $actorId);
        }
    }

    /** @return array{id:int|null,name:string|null,create:bool,type:string} */
    private function namedEntity(int $siteId, string $type, string $name, bool $create): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['id' => null, 'name' => null, 'create' => false, 'type' => $type];
        }
        $row = $type === 'brand' ? $this->findBrandByName($siteId, $name) : $this->findCategoryByName($siteId, $name);
        if ($row !== null) {
            return ['id' => (int) $row['id'], 'name' => $name, 'create' => false, 'type' => $type];
        }
        if (!$create) {
            throw new InvalidArgumentException('business.catalog.import_' . $type . '_not_found');
        }
        return ['id' => null, 'name' => $name, 'create' => true, 'type' => $type];
    }

    /** @param array{id:int|null,name:string|null,create:bool,type:string} $entity */
    private function ensureNamedEntityId(int $siteId, string $type, array $entity, ?int $actorId): ?int
    {
        if ($entity['name'] === null) {
            return null;
        }
        if ($entity['id'] !== null) {
            return $entity['id'];
        }
        $created = $type === 'brand'
            ? $this->brands->create($siteId, ['name' => $entity['name']], $actorId)
            : $this->categories->create($siteId, ['name' => $entity['name']], $actorId);
        return (int) $created['id'];
    }

    /** @param array<string,string> $variantOptions @return list<array{option_code:string,option_name:string,value_code:string,value_label:string,option_id:int|null,value_id:int|null,create_option:bool,create_value:bool}> */
    private function planOptions(int $siteId, array $variantOptions, bool $createOptions): array
    {
        $plans = [];
        foreach ($variantOptions as $optionCode => $valueCode) {
            $optionCode = $this->key($optionCode);
            $valueCode = $this->key($valueCode);
            $option = $this->findOptionByCode($siteId, $optionCode);
            $value = $option === null ? null : $this->findOptionValueByCode((int) $option['id'], $valueCode);
            if ($option === null && !$createOptions) {
                throw new InvalidArgumentException('business.catalog.import_option_not_found');
            }
            if ($value === null && !$createOptions) {
                throw new InvalidArgumentException('business.catalog.import_option_value_not_found');
            }
            $plans[] = [
                'option_code' => $optionCode,
                'option_name' => $optionCode,
                'value_code' => $valueCode,
                'value_label' => $valueCode,
                'option_id' => $option['id'] ?? null,
                'value_id' => $value['id'] ?? null,
                'create_option' => $option === null,
                'create_value' => $value === null,
            ];
        }
        return $plans;
    }

    /** @param list<array<string,mixed>> $plans @return list<int> */
    private function ensureOptions(int $siteId, array $plans, ?int $actorId): array
    {
        $ids = [];
        foreach ($plans as $plan) {
            $optionId = $plan['option_id'] !== null ? (int) $plan['option_id'] : (int) $this->options->create($siteId, ['code' => $plan['option_code'], 'name' => $plan['option_name']], $actorId)['id'];
            if ($plan['value_id'] === null) {
                $this->options->addValue($siteId, $optionId, ['code' => $plan['value_code'], 'label' => $plan['value_label'], 'value' => $plan['value_code']], $actorId);
            }
            $ids[] = $optionId;
        }
        return array_values(array_unique($ids));
    }

    /** @return array<string,string> */
    private function parseVariantOptions(string $value): array
    {
        $result = [];
        foreach (preg_split('/[|,;]/', $value) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $pieces = preg_split('/[:=]/', $part, 2) ?: [];
            if (count($pieces) !== 2 || trim($pieces[0]) === '' || trim($pieces[1]) === '') {
                throw new InvalidArgumentException('business.catalog.variant_options_invalid');
            }
            $result[$this->key($pieces[0])] = $this->key($pieces[1]);
        }
        return $result;
    }

    private function productOptionsCell(int $productId): string
    {
        $rows = $this->db->all(
            'SELECT o.code FROM business_product_option_links l INNER JOIN business_product_options o ON o.id = l.option_id WHERE l.product_id = ? ORDER BY l.sort_order ASC, o.sort_order ASC',
            [$productId]
        );
        return implode('|', array_map(static fn(array $row): string => (string) $row['code'], $rows));
    }

    private function variantOptionsCell(int $variantId): string
    {
        $rows = $this->variants->optionValues($variantId);
        return implode('|', array_map(static fn(array $row): string => (string) $row['option_code'] . ':' . (string) $row['value_code'], $rows));
    }

    private function findProductBySlug(int $siteId, string $slug): ?array
    {
        return $this->db->one('SELECT * FROM business_products WHERE site_id = ? AND slug = ? AND archived_at IS NULL LIMIT 1', [$siteId, $slug]);
    }

    private function findVariantBySku(string $sku): ?array
    {
        return $this->db->one(
            'SELECT v.*, p.site_id FROM business_product_variants v INNER JOIN business_products p ON p.id = v.product_id WHERE v.sku = ? AND v.archived_at IS NULL LIMIT 1',
            [$sku]
        );
    }

    private function findBrandByName(int $siteId, string $name): ?array
    {
        return $this->db->one('SELECT * FROM business_product_brands WHERE site_id = ? AND lower(name) = lower(?) AND archived_at IS NULL LIMIT 1', [$siteId, $name]);
    }

    private function findCategoryByName(int $siteId, string $name): ?array
    {
        return $this->db->one('SELECT * FROM business_product_categories WHERE site_id = ? AND lower(name) = lower(?) AND archived_at IS NULL LIMIT 1', [$siteId, $name]);
    }

    private function findOptionByCode(int $siteId, string $code): ?array
    {
        return $this->db->one('SELECT * FROM business_product_options WHERE site_id = ? AND code = ? AND archived_at IS NULL LIMIT 1', [$siteId, $code]);
    }

    private function findOptionValueByCode(int $optionId, string $code): ?array
    {
        return $this->db->one('SELECT * FROM business_product_option_values WHERE option_id = ? AND code = ? AND archived_at IS NULL LIMIT 1', [$optionId, $code]);
    }

    private function detectDelimiter(string $csv): string
    {
        $firstLine = strtok($csv, "\r\n") ?: '';
        return substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
    }

    /** @param list<string> $row */
    private function emptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }
        return true;
    }

    private function stripBom(string $csv): string
    {
        return str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv;
    }

    private function isUtf8(string $value): bool
    {
        return preg_match('//u', $value) === 1;
    }

    private function headerKey(string $value): string
    {
        return strtolower(trim($value));
    }

    private function exportCell(mixed $value): string
    {
        $cell = (string) $value;
        return preg_match('/^[=+\-@]/', $cell) === 1 ? "'" . $cell : $cell;
    }

    private function boolCell(mixed $value): string
    {
        return (bool) $value ? '1' : '0';
    }

    private function moneyCell(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    private function boolValue(string $value): bool
    {
        $value = strtolower(trim($value));
        return in_array($value, ['1', 'true', 'yes', 'oui', 'on'], true);
    }

    private function numberOrNull(string $value): ?float
    {
        $value = trim(str_replace(',', '.', $value));
        if ($value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('business.catalog.price_invalid');
        }
        return round((float) $value, 2);
    }

    private function assertNonNegativeNumber(string $value, string $field): void
    {
        $number = $this->numberOrNull($value);
        if ($number !== null && $number < 0) {
            throw new InvalidArgumentException('business.catalog.' . $field . '_negative');
        }
    }

    /** @param list<string> $allowed */
    private function choice(string $value, array $allowed, string $field): string
    {
        $value = trim($value);
        if ($value === '') {
            $value = $allowed[0];
        }
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException('business.catalog.' . $field . '_invalid');
        }
        return $value;
    }

    private function adjustmentType(string $value): string
    {
        return $this->choice(trim($value) === '' ? 'none' : $value, ['none', 'amount_delta', 'percent_delta', 'fixed_override'], 'price_adjustment');
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-_');
        if ($slug === '') {
            throw new InvalidArgumentException('business.catalog.slug_invalid');
        }
        return substr($slug, 0, 120);
    }

    private function key(string $value): string
    {
        $key = strtolower(trim($value));
        $key = preg_replace('/[^a-z0-9_-]+/', '_', $key) ?? '';
        $key = trim($key, '_-');
        if ($key === '') {
            throw new InvalidArgumentException('business.catalog.key_invalid');
        }
        return substr($key, 0, 80);
    }
}
