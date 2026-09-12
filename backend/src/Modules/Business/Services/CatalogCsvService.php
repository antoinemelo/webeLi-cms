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
    private const FORMAT_VERSION = 'pim.catalog.v1';
    private const EXPORT_DELIMITER = ';';
    private const MAX_IMPORT_BYTES = 1048576;
    private const HEADERS = [
        'format_version', 'site_id', 'external_id', 'product_id', 'product_name', 'product_slug', 'type', 'status', 'brand', 'category', 'sku_base',
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
             LEFT JOIN business_product_base_prices bp_purchase ON bp_purchase.product_id = p.id AND bp_purchase.price_kind = \'purchase\' AND bp_purchase.valid_from IS NULL
             LEFT JOIN business_product_base_prices bp_sale ON bp_sale.product_id = p.id AND bp_sale.price_kind = \'sale\' AND bp_sale.valid_from IS NULL
             LEFT JOIN business_product_variant_price_adjustments adj_purchase ON adj_purchase.variant_id = v.id AND adj_purchase.price_kind = \'purchase\' AND adj_purchase.valid_from IS NULL
             LEFT JOIN business_product_variant_price_adjustments adj_sale ON adj_sale.variant_id = v.id AND adj_sale.price_kind = \'sale\' AND adj_sale.valid_from IS NULL
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
                self::FORMAT_VERSION,
                $siteId,
                $record['external_id'] ?? '',
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
        $checksum = hash('sha256', $csv);
        $idempotencyKey = trim((string) ($options['idempotency_key'] ?? $checksum));
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 120 || preg_match('/^[A-Za-z0-9._:-]+$/', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('business.catalog.import_idempotency_key_invalid');
        }
        if (!$dryRun) {
            $previous = $this->db->one('SELECT * FROM business_catalog_import_runs WHERE site_id = ? AND idempotency_key = ? LIMIT 1', [$siteId, $idempotencyKey]);
            if ($previous !== null) {
                if (!hash_equals((string) $previous['checksum'], $checksum)) {
                    throw new InvalidArgumentException('business.catalog.import_idempotency_conflict');
                }
                $summary = json_decode((string) $previous['summary_json'], true);
                $summary = is_array($summary) ? $summary : [];
                $summary['replayed'] = true;
                $summary['writes_performed'] = false;
                $summary['unchanged_rows'] = (int) ($previous['rows_total'] ?? 0);
                $summary['journal'] = ['run_id' => (int) $previous['id'], 'idempotency_key' => $idempotencyKey, 'status' => (string) $previous['status']];
                return $summary;
            }
        }

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
            'unchanged_rows' => 0,
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
                $rowReport['diff'] = $plan['diff'];
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

        return $this->db->transaction(function () use ($siteId, $actorId, $plans, $report, $idempotencyKey, $checksum): array {
            $result = $report;
            foreach ($plans as $plan) {
                $write = $this->applyPlan($siteId, $plan, $actorId);
                if ($write['product_action'] !== 'unchanged') {
                    $result[$write['product_action'] === 'created' ? 'created_products' : 'updated_products']++;
                }
                if ($write['variant_action'] !== 'unchanged') {
                    $result[$write['variant_action'] === 'created' ? 'created_variants' : 'updated_variants']++;
                }
                if ($write['product_action'] === 'unchanged' && $write['variant_action'] === 'unchanged') {
                    $result['unchanged_rows']++;
                } else {
                    $result['writes_performed'] = true;
                }
            }
            $changedRows = count($plans) - (int) $result['unchanged_rows'];
            $summary = $result;
            unset($summary['rows']);
            $this->db->run(
                'INSERT INTO business_catalog_import_runs(site_id, idempotency_key, format_version, checksum, status, rows_total, changed_rows, summary_json, created_by_iam_user_id)
                 VALUES(?, ?, ?, ?, \'applied\', ?, ?, ?, ?)',
                [$siteId, $idempotencyKey, self::FORMAT_VERSION, $checksum, count($plans), $changedRows, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $actorId]
            );
            $result['journal'] = ['run_id' => (int) $this->db->lastInsertId(), 'idempotency_key' => $idempotencyKey, 'status' => 'applied'];
            $result['replayed'] = false;
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
            $clauses[] = 'NOT EXISTS (
                SELECT 1 FROM business_product_channel_visibility cv
                WHERE cv.product_id = p.id AND cv.site_id = p.site_id AND cv.channel = ?
                  AND (cv.status <> \'active\' OR (cv.starts_at IS NOT NULL AND cv.starts_at > CURRENT_TIMESTAMP) OR (cv.ends_at IS NOT NULL AND cv.ends_at <= CURRENT_TIMESTAMP))
            )';
            $params[] = $channel === 'public' ? 'ecommerce' : $channel;
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            if (!in_array($status, ['draft', 'active', 'archived'], true)) {
                throw new InvalidArgumentException('business.catalog.export_status_invalid');
            }
            $clauses[] = 'p.status = ?';
            $params[] = $status;
        }

        $quality = trim((string) ($filters['quality'] ?? ''));
        if ($quality !== '') {
            if ($quality !== 'incomplete') {
                throw new InvalidArgumentException('business.catalog.export_quality_invalid');
            }
            $clauses[] = 'NOT EXISTS (
                SELECT 1 FROM business_product_completeness_scores cs
                WHERE cs.product_id = p.id AND cs.variant_id IS NULL AND cs.channel = \'all\' AND cs.score >= 100
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
        $formatVersion = trim($row['format_version'] ?? '');
        if ($formatVersion !== '' && $formatVersion !== self::FORMAT_VERSION) {
            throw new InvalidArgumentException('business.catalog.import_format_version_unsupported');
        }
        $declaredSiteId = (int) ($row['site_id'] ?? 0);
        if ($declaredSiteId > 0 && $declaredSiteId !== $siteId) {
            throw new InvalidArgumentException('business.catalog.import_product_site_mismatch');
        }
        $externalId = trim($row['external_id'] ?? '');
        $productId = (int) ($row['product_id'] ?? 0);
        $variantId = (int) ($row['variant_id'] ?? 0);
        $externalProduct = $externalId === '' ? null : $this->findProductByExternalId($siteId, $externalId);
        if ($externalProduct !== null && (int) $externalProduct['site_id'] !== $siteId) {
            throw new InvalidArgumentException('business.catalog.import_product_site_mismatch');
        }
        $existingProduct = $externalProduct ?? ($productId > 0 ? $this->products->find($siteId, $productId, true) : $this->findProductBySlug($siteId, $slug));
        $existingVariant = $variantId > 0 ? $this->variants->findById($variantId, true) : $this->findVariantBySku($sku);
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

        $plan = [
            'product_action' => $existingProduct === null ? 'create' : 'update',
            'variant_action' => $existingVariant === null ? 'create' : 'update',
            'existing_product_id' => $existingProduct['id'] ?? null,
            'existing_variant_id' => $existingVariant['id'] ?? null,
            'brand' => $brand,
            'category' => $category,
            'option_plans' => $optionPlans,
            'product' => [
                'external_id' => $externalId === '' ? null : $externalId,
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
        $diff = $this->planDiff($plan);
        $plan['diff'] = $diff;
        if ($existingProduct !== null && $diff['product'] === []) {
            $plan['product_action'] = 'noop';
        }
        if ($existingVariant !== null && $diff['variant'] === []) {
            $plan['variant_action'] = 'noop';
        }
        if (!$overwriteExisting && ($plan['product_action'] === 'update' || $plan['variant_action'] === 'update')) {
            throw new InvalidArgumentException($plan['product_action'] === 'update' ? 'business.catalog.import_product_exists' : 'business.catalog.import_variant_exists');
        }
        return $plan;
    }

    /** @param array<string,mixed> $plan @return array{product_action:string,variant_action:string} */
    private function applyPlan(int $siteId, array $plan, ?int $actorId): array
    {
        if ($plan['product_action'] === 'noop' && $plan['variant_action'] === 'noop') {
            return ['product_action' => 'unchanged', 'variant_action' => 'unchanged'];
        }
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
            if (($productPayload['external_id'] ?? null) !== null) {
                $this->db->run('UPDATE business_products SET external_id = ? WHERE id = ?', [$productPayload['external_id'], (int) $product['id']]);
                $product = $this->products->find($siteId, (int) $product['id'], true) ?? $product;
            }
            $productAction = 'created';
        } elseif ($plan['product_action'] === 'update') {
            $productId = (int) $plan['existing_product_id'];
            $this->updateProductCore($siteId, $productId, $productPayload, $actorId);
            $product = $this->products->find($siteId, $productId, true) ?? [];
            $productAction = 'updated';
        } else {
            $product = $this->products->find($siteId, (int) $plan['existing_product_id'], true) ?? [];
            $productAction = 'unchanged';
        }

        $productId = (int) $product['id'];
        $this->products->replaceOptionLinks($siteId, $productId, $optionIds);
        $this->setBasePrices($productId, $productPayload, $actorId);

        $variantPayload = $plan['variant'];
        if ($plan['variant_action'] === 'create') {
            $this->variants->create($siteId, $productId, $variantPayload, $actorId);
            $variantAction = 'created';
        } elseif ($plan['variant_action'] === 'update') {
            $variantId = (int) $plan['existing_variant_id'];
            $this->variants->update($siteId, $variantId, $variantPayload, $actorId);
            $this->variants->setAdjustment($variantId, 'purchase', (string) $variantPayload['purchase_adjustment_type'], $variantPayload['purchase_adjustment_value'], $actorId);
            $this->variants->setAdjustment($variantId, 'sale', (string) $variantPayload['sale_adjustment_type'], $variantPayload['sale_adjustment_value'], $actorId);
            $variantAction = 'updated';
        } else {
            $variantAction = 'unchanged';
        }

        return ['product_action' => $productAction, 'variant_action' => $variantAction];
    }

    /** @param array<string,mixed> $payload */
    private function updateProductCore(int $siteId, int $productId, array $payload, ?int $actorId): void
    {
        $this->db->run(
            'UPDATE business_products
             SET brand_id = :brand_id, category_id = :category_id, type = :type, status = :status, visibility = :visibility, external_id = :external_id,
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
                'external_id' => $payload['external_id'] ?? null,
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

    /** @param array<string,mixed> $plan @return array{product:array<string,array{before:mixed,after:mixed}>,variant:array<string,array{before:mixed,after:mixed}>} */
    private function planDiff(array $plan): array
    {
        $productAfter = $plan['product'] + [
            'brand_id' => $plan['brand']['id'] ?? null,
            'category_id' => $plan['category']['id'] ?? null,
        ];
        $variantAfter = $plan['variant'];
        if ($plan['existing_product_id'] === null) {
            return [
                'product' => $this->changedFields([], $productAfter),
                'variant' => $this->changedFields([], $variantAfter),
            ];
        }
        $productBefore = $this->db->one(
            'SELECT p.external_id, p.name, p.slug, p.type, p.status, p.sku_base, p.tax_class_id, p.brand_id, p.category_id,
                    p.is_public, p.is_ecommerce_enabled, p.is_pos_enabled, p.is_catalogue_enabled,
                    sale.amount AS base_sale_price, COALESCE(sale.currency, purchase.currency, \'CHF\') AS currency,
                    purchase.amount AS base_purchase_price
             FROM business_products p
             LEFT JOIN business_product_base_prices sale ON sale.product_id=p.id AND sale.price_kind=\'sale\' AND sale.valid_from IS NULL
             LEFT JOIN business_product_base_prices purchase ON purchase.product_id=p.id AND purchase.price_kind=\'purchase\' AND purchase.valid_from IS NULL
             WHERE p.id=? LIMIT 1',
            [(int) $plan['existing_product_id']]
        ) ?? [];
        $productFields = ['external_id','name','slug','type','status','sku_base','tax_class_id','brand_id','category_id','is_public','is_ecommerce_enabled','is_pos_enabled','is_catalogue_enabled','base_purchase_price','base_sale_price','currency'];
        $productDiff = $this->changedFields(array_intersect_key($productBefore, array_flip($productFields)), array_intersect_key($productAfter, array_flip($productFields)));

        if ($plan['existing_variant_id'] === null) {
            return ['product' => $productDiff, 'variant' => $this->changedFields([], $variantAfter)];
        }
        // La quantité Business n'est qu'un amorçage à la création. Toute
        // variation ultérieure passe par le ledger Sale.
        unset($variantAfter['stock_quantity']);
        $variantBefore = $this->db->one(
            'SELECT v.sku, v.barcode, v.name, v.status, v.stock_quantity,
                    purchase.adjustment_type AS purchase_adjustment_type, purchase.adjustment_value AS purchase_adjustment_value,
                    sale.adjustment_type AS sale_adjustment_type, sale.adjustment_value AS sale_adjustment_value
             FROM business_product_variants v
             LEFT JOIN business_product_variant_price_adjustments purchase ON purchase.variant_id=v.id AND purchase.price_kind=\'purchase\' AND purchase.valid_from IS NULL
             LEFT JOIN business_product_variant_price_adjustments sale ON sale.variant_id=v.id AND sale.price_kind=\'sale\' AND sale.valid_from IS NULL
             WHERE v.id=? LIMIT 1',
            [(int) $plan['existing_variant_id']]
        ) ?? [];
        $variantBefore['purchase_adjustment_type'] ??= 'none';
        $variantBefore['sale_adjustment_type'] ??= 'none';
        unset($variantBefore['stock_quantity']);
        return ['product' => $productDiff, 'variant' => $this->changedFields($variantBefore, array_intersect_key($variantAfter, $variantBefore))];
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after @return array<string,array{before:mixed,after:mixed}> */
    private function changedFields(array $before, array $after): array
    {
        $diff = [];
        foreach ($after as $field => $value) {
            $old = $before[$field] ?? null;
            $normalizedOld = is_array($old) ? json_encode($old) : (is_bool($old) ? (string) (int) $old : (is_numeric($old) && $old !== '' ? (string) (float) $old : (string) ($old ?? '')));
            $normalizedNew = is_array($value) ? json_encode($value) : (is_bool($value) ? (string) (int) $value : (is_numeric($value) && $value !== '' ? (string) (float) $value : (string) ($value ?? '')));
            if ($normalizedOld !== $normalizedNew) {
                $diff[$field] = ['before' => $old, 'after' => $value];
            }
        }
        return $diff;
    }

    /** @return array<string,mixed>|null */
    private function findProductByExternalId(int $siteId, string $externalId): ?array
    {
        return $this->db->one(
            'SELECT * FROM business_products WHERE external_id = ? AND archived_at IS NULL ORDER BY CASE WHEN site_id = ? THEN 0 ELSE 1 END, id ASC LIMIT 1',
            [$externalId, $siteId]
        );
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
