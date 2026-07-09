<?php

declare(strict_types=1);

namespace App\Modules\Business\Services;

use App\Modules\Business\Catalog\CatalogPricingService;
use App\Modules\Business\Repositories\BusinessCatalogPricingRepository;
use App\Modules\Business\Repositories\PosCatalogRepository;
use InvalidArgumentException;

final class BusinessCatalogSellableReadService
{
    private readonly BusinessProductAssetService $assets;
    private readonly BusinessProductBundleService $bundles;

    public function __construct(
        private readonly BusinessCatalogPricingRepository $pricingRepository,
        private readonly CatalogPricingService $pricing,
        private readonly PosCatalogRepository $catalog,
        ?BusinessProductAssetService $assets = null,
        ?BusinessProductBundleService $bundles = null
    ) {
        $this->assets = $assets ?? new BusinessProductAssetService($pricingRepository->rawDatabase());
        $this->bundles = $bundles ?? new BusinessProductBundleService($pricingRepository->rawDatabase());
    }

    /** @param array<string,mixed>|int|null $context @return array<string,mixed> */
    public function getSellableVariantSnapshot(int $siteId, int $variantId, array|int|null $context = []): array
    {
        $context = is_int($context)
            ? ['channel' => $this->channelFromId($context), 'include_purchase_price' => true, 'include_internal_fields' => true]
            : $this->normalizeContext($context ?? []);

        return $this->buildSnapshot($this->requireSiteId($siteId), $this->requireVariantId($variantId), $context);
    }

    /** @param array<string,mixed> $filters @return array{items:list<array<string,mixed>>,limit:int,offset:int,total:int,has_more:bool} */
    public function listSellableVariants(int $siteId, array $filters = []): array
    {
        $siteId = $this->requireSiteId($siteId);
        $context = $this->normalizeContext($filters);
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $includeNotSellable = (bool) ($filters['include_not_sellable'] ?? false);

        [$where, $params] = $this->searchWhere($siteId, $filters, (string) $context['channel']);
        $sqlWhere = implode(' AND ', $where);
        $total = (int) ($this->pricingRepository->rawDatabase()->one(
            'SELECT COUNT(*) AS count
             FROM business_product_variants v
             INNER JOIN business_products p ON p.id = v.product_id
             WHERE ' . $sqlWhere,
            $params
        )['count'] ?? 0);

        $rows = $this->pricingRepository->rawDatabase()->all(
            'SELECT v.id
             FROM business_product_variants v
             INNER JOIN business_products p ON p.id = v.product_id
             WHERE ' . $sqlWhere . '
             ORDER BY p.name ASC, v.sort_order ASC, v.id ASC
             LIMIT :limit OFFSET :offset',
            $params + ['limit' => $limit, 'offset' => $offset]
        );

        $items = [];
        foreach ($rows as $row) {
            $snapshot = $this->buildSnapshot($siteId, (int) $row['id'], $context);
            if ($includeNotSellable || (bool) ($snapshot['is_sellable'] ?? false)) {
                $items[] = $snapshot;
            }
        }

        return [
            'items' => $items,
            'limit' => $limit,
            'offset' => $offset,
            'total' => $total,
            'has_more' => ($offset + $limit) < $total,
        ];
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public function explainSellability(int $siteId, int $variantId, array $context = []): array
    {
        $snapshot = $this->getSellableVariantSnapshot($siteId, $variantId, $context + ['include_internal_fields' => true]);
        return [
            'site_id' => (int) $snapshot['site_id'],
            'product_id' => (int) $snapshot['product_id'],
            'variant_id' => (int) $snapshot['variant_id'],
            'channel' => (string) ($snapshot['visibility']['channel'] ?? $this->normalizeContext($context)['channel']),
            'is_sellable' => (bool) $snapshot['is_sellable'],
            'missing_requirements' => $snapshot['missing_requirements'],
            'stock' => [
                'track_stock' => (bool) $snapshot['track_stock'],
                'available_quantity' => (float) ($snapshot['metadata']['available_quantity'] ?? 0),
                'allow_backorder' => (bool) ($snapshot['metadata']['allow_backorder'] ?? false),
            ],
            'visibility' => $snapshot['visibility'],
        ];
    }

    /** @param array<string,mixed> $filters @return array{items:list<array<string,mixed>>,limit:int,offset:int,total:int,has_more:bool} */
    public function searchSellableVariants(int $siteId, array $filters = []): array
    {
        return $this->listSellableVariants($siteId, $filters + ['include_purchase_price' => true, 'include_internal_fields' => true]);
    }

    public function assertVariantSellable(int $siteId, int $variantId, ?int $channelId = null): void
    {
        $snapshot = $this->getSellableVariantSnapshot($siteId, $variantId, $channelId);
        if (!((bool) ($snapshot['is_sellable'] ?? false))) {
            throw new InvalidArgumentException('business.catalog.variant_not_sellable');
        }
    }

    /** @return array<string,mixed> */
    public function variantSnapshot(int $siteId, int $businessVariantId, string $channel = 'admin', bool $includePurchasePrice = true): array
    {
        return $this->getSellableVariantSnapshot($siteId, $businessVariantId, [
            'channel' => $channel,
            'include_purchase_price' => $includePurchasePrice,
            'include_internal_fields' => true,
        ]);
    }

    public function moneyToMinor(float|int|string|null $amount): int
    {
        return $this->moneyMinor($amount);
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    public function publicPayload(array $snapshot): array
    {
        unset(
            $snapshot['purchase_price_minor'],
            $snapshot['unit_purchase_price_minor'],
            $snapshot['margin_minor'],
            $snapshot['margin_percent_basis_points'],
            $snapshot['snapshot_json']
        );
        return $snapshot;
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public function aiSnapshot(int $siteId, int $variantId, array $context = [], bool $canReadPurchasePrice = false): array
    {
        $snapshot = $this->getSellableVariantSnapshot($siteId, $variantId, $context + [
            'include_purchase_price' => $canReadPurchasePrice,
            'include_internal_fields' => false,
        ]);
        if (!$canReadPurchasePrice) {
            unset(
                $snapshot['purchase_price_minor'],
                $snapshot['unit_purchase_price_minor'],
                $snapshot['margin_minor'],
                $snapshot['margin_percent_basis_points']
            );
            $snapshot['purchase_price_visible'] = false;
        }
        unset($snapshot['snapshot_json']);
        $snapshot['ai'] = [
            'contract' => 'business.catalog.ai_snapshot.v1',
            'site_scoped' => true,
            'permission_scoped' => true,
            'purchase_price_visible' => $canReadPurchasePrice,
            'missing_requirements_available' => array_key_exists('missing_requirements', $snapshot),
        ];
        return $snapshot;
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private function buildSnapshot(int $siteId, int $variantId, array $context): array
    {
        $channel = (string) $context['channel'];
        $pricingChannel = $this->pricingChannel($channel);
        $pricingSnapshot = $this->pricingRepository->pricingSnapshot($variantId, $pricingChannel);
        $variant = $pricingSnapshot['variant'];
        if ((int) ($variant['site_id'] ?? 0) !== $siteId) {
            throw new InvalidArgumentException('business.catalog.variant_not_found');
        }

        $row = $this->variantRow($siteId, $variantId);
        $brand = $this->catalog->brand($siteId, isset($row['brand_id']) ? (int) $row['brand_id'] : null);
        $category = $this->catalog->category($siteId, isset($row['category_id']) ? (int) $row['category_id'] : null);
        $taxClass = $this->catalog->taxClass(isset($row['tax_class_id']) ? (int) $row['tax_class_id'] : null);
        $pricingSummary = $this->pricing->pricingSummary($variantId, $pricingChannel);

        $trackStock = $row['variant_track_stock'] === null ? (bool) $row['product_track_stock'] : (bool) $row['variant_track_stock'];
        $allowBackorder = $row['variant_allow_backorder'] === null ? (bool) $row['product_allow_backorder'] : (bool) $row['variant_allow_backorder'];
        $available = (float) $row['stock_quantity'] - (float) $row['stock_reserved'];
        $currency = strtoupper((string) ($context['currency'] ?: ($pricingSummary['currency'] ?? $row['sale_currency'] ?? 'CHF')));
        $regularSaleMinor = $this->moneyMinor($pricingSummary['regular_sale_price'] ?? $row['base_sale_price'] ?? null);
        $finalSaleMinor = $this->moneyMinor($pricingSummary['final_sale_price'] ?? $row['base_sale_price'] ?? null);
        $purchaseMinor = $this->moneyMinor($pricingSummary['regular_purchase_price'] ?? $row['base_purchase_price'] ?? null);
        $marginMinor = $finalSaleMinor - $purchaseMinor;
        $marginPercentBasisPoints = $finalSaleMinor <= 0 ? null : (int) round(($marginMinor / $finalSaleMinor) * 10000);
        $taxIncluded = (bool) ($row['sale_tax_included'] ?? true);
        $taxRateBasisPoints = $taxClass === null ? 0 : (int) round(((float) ($taxClass['rate'] ?? 0)) * 100);
        $mainAsset = $this->assets->mainAssetForProduct((int) $row['business_product_id'], $variantId, $channel);
        $completeness = $this->completeness($siteId, (int) $row['business_product_id'], $variantId, $channel);
        $missing = $this->missingRequirements($row, $channel, $trackStock, $allowBackorder, $available, $regularSaleMinor, $taxClass, $completeness);
        $isSellable = $missing === [];

        $snapshot = [
            'site_id' => $siteId,
            'product_id' => (int) $row['business_product_id'],
            'variant_id' => (int) $row['business_variant_id'],
            'business_product_id' => (int) $row['business_product_id'],
            'business_variant_id' => (int) $row['business_variant_id'],
            'sku' => (string) $row['sku'],
            'barcode' => $row['barcode'] ?? null,
            'product_name' => (string) $row['product_name'],
            'variant_name' => (string) $row['variant_name'],
            'brand_name' => $brand['name'] ?? null,
            'category_name' => $category['name'] ?? null,
            'product_type' => (string) $row['product_type'],
            'unit' => (string) ($row['unit'] ?? 'piece'),
            'currency' => $currency,
            'sale_price_minor' => $finalSaleMinor,
            'regular_sale_price_minor' => $regularSaleMinor,
            'unit_price_minor' => $finalSaleMinor,
            'regular_unit_price_minor' => $regularSaleMinor,
            'purchase_price_visible' => (bool) $context['include_purchase_price'],
            'tax_class_id' => $row['tax_class_id'] === null ? null : (int) $row['tax_class_id'],
            'tax_rate_basis_points' => $taxRateBasisPoints,
            'tax_included' => $taxIncluded,
            'discounts_applied' => $pricingSummary['active_discount'] === null ? [] : [$pricingSummary['active_discount']],
            'active_discount_snapshot' => $pricingSummary['active_discount'] ?? null,
            'main_asset' => $mainAsset,
            'main_asset_id' => $mainAsset['asset_id'] ?? null,
            'main_media_id' => $mainAsset['media_id'] ?? null,
            'main_media_url' => $mainAsset['url'] ?? null,
            'track_stock' => $trackStock,
            'is_public' => (bool) $row['is_public'],
            'is_ecommerce_enabled' => (bool) $row['is_ecommerce_enabled'],
            'is_pos_enabled' => (bool) $row['is_pos_enabled'],
            'is_sellable' => $isSellable,
            'missing_requirements' => $missing,
            'visibility' => [
                'channel' => $channel,
                'product_visibility' => (string) $row['visibility'],
                'is_public' => (bool) $row['is_public'],
                'is_ecommerce_enabled' => (bool) $row['is_ecommerce_enabled'],
                'is_pos_enabled' => (bool) $row['is_pos_enabled'],
            ],
            'metadata' => [
                'product_slug' => (string) $row['product_slug'],
                'brand_id' => $row['brand_id'] === null ? null : (int) $row['brand_id'],
                'category_id' => $row['category_id'] === null ? null : (int) $row['category_id'],
                'stock_quantity' => (float) $row['stock_quantity'],
                'stock_reserved' => (float) $row['stock_reserved'],
                'available_quantity' => $available,
                'allow_backorder' => $allowBackorder,
                'tax_class' => $taxClass,
                'completeness' => $completeness,
            ],
            'snapshot_json' => [],
        ];

        if ((bool) $context['include_purchase_price']) {
            $snapshot['purchase_price_minor'] = $purchaseMinor;
            $snapshot['unit_purchase_price_minor'] = $purchaseMinor;
            $snapshot['margin_minor'] = $marginMinor;
            $snapshot['margin_percent_basis_points'] = $marginPercentBasisPoints;
        }

        $bundle = $this->bundles->bundleSummaryForVariant($siteId, $variantId);
        $snapshot += $bundle;
        if (($bundle['bundle_missing_requirements'] ?? []) !== []) {
            $snapshot['missing_requirements'] = array_values(array_unique(array_merge($snapshot['missing_requirements'], $bundle['bundle_missing_requirements'])));
            $snapshot['is_sellable'] = false;
        }

        if (!(bool) $context['include_internal_fields']) {
            unset($snapshot['snapshot_json']);
        }

        return $snapshot;
    }

    /** @param array<string,mixed> $row @param array<string,mixed>|null $completeness @return list<string> */
    private function missingRequirements(array $row, string $channel, bool $trackStock, bool $allowBackorder, float $available, int $regularSaleMinor, ?array $taxClass, ?array $completeness): array
    {
        $missing = [];
        if ((string) $row['product_status'] !== 'active') {
            $missing[] = 'product_inactive';
        }
        if ((string) $row['variant_status'] !== 'active') {
            $missing[] = 'variant_inactive';
        }
        if (trim((string) ($row['sku'] ?? '')) === '') {
            $missing[] = 'sku_missing';
        }
        if ($regularSaleMinor <= 0) {
            $missing[] = 'sale_price_missing';
        }
        if ($taxClass === null) {
            $missing[] = 'tax_class_missing';
        }
        if ($channel === 'pos' && !((bool) $row['is_pos_enabled'])) {
            $missing[] = 'channel_pos_disabled';
        }
        if ($channel === 'ecommerce' && !((bool) $row['is_ecommerce_enabled'])) {
            $missing[] = 'channel_ecommerce_disabled';
        }
        if (in_array($channel, ['public', 'ecommerce'], true) && (!((bool) $row['is_public']) || (string) $row['visibility'] !== 'public')) {
            $missing[] = 'public_visibility_missing';
        }
        if ($trackStock && !$allowBackorder && $available <= 0.0) {
            $missing[] = 'stock_unavailable';
        }
        if ($completeness !== null && !((bool) ($completeness['is_sellable'] ?? true))) {
            foreach ((array) ($completeness['missing'] ?? []) as $item) {
                $code = is_array($item) ? (string) ($item['code'] ?? $item['field'] ?? 'completeness_missing') : (string) $item;
                if ($code !== '') {
                    $missing[] = $code;
                }
            }
        }
        return array_values(array_unique($missing));
    }

    /** @return array<string,mixed>|null */
    private function completeness(int $siteId, int $productId, int $variantId, string $channel): ?array
    {
        $db = $this->pricingRepository->rawDatabase();
        $row = $db->one(
            'SELECT * FROM business_product_completeness_scores
             WHERE product_id = :product_id AND variant_id = :variant_id AND channel IN (:channel, "all")
             ORDER BY CASE channel WHEN :channel THEN 0 ELSE 1 END
             LIMIT 1',
            ['product_id' => $productId, 'variant_id' => $variantId, 'channel' => $this->pimChannel($channel)]
        );
        $row ??= $db->one(
            'SELECT * FROM business_product_completeness_scores
             WHERE product_id = :product_id AND variant_id IS NULL AND channel IN (:channel, "all")
             ORDER BY CASE channel WHEN :channel THEN 0 ELSE 1 END
             LIMIT 1',
            ['product_id' => $productId, 'channel' => $this->pimChannel($channel)]
        );
        if ($row === null) {
            return null;
        }
        return [
            'score' => (int) $row['score'],
            'is_sellable' => (bool) $row['is_sellable'],
            'missing' => $this->jsonDecode((string) ($row['missing_json'] ?? '[]')),
            'calculated_at' => $row['calculated_at'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    private function variantRow(int $siteId, int $variantId): array
    {
        $row = $this->pricingRepository->rawDatabase()->one(
            'SELECT
                p.id AS business_product_id,
                v.id AS business_variant_id,
                p.site_id,
                p.brand_id,
                p.category_id,
                p.tax_class_id,
                p.type AS product_type,
                p.status AS product_status,
                p.visibility,
                p.slug AS product_slug,
                p.name AS product_name,
                p.unit,
                p.track_stock AS product_track_stock,
                p.allow_backorder AS product_allow_backorder,
                p.is_public,
                p.is_ecommerce_enabled,
                p.is_pos_enabled,
                v.status AS variant_status,
                v.sku,
                v.barcode,
                v.name AS variant_name,
                v.track_stock AS variant_track_stock,
                v.stock_quantity,
                v.stock_reserved,
                v.allow_backorder AS variant_allow_backorder,
                purchase.amount AS base_purchase_price,
                purchase.currency AS purchase_currency,
                sale.amount AS base_sale_price,
                sale.currency AS sale_currency,
                sale.tax_included AS sale_tax_included
             FROM business_product_variants v
             INNER JOIN business_products p ON p.id = v.product_id
             LEFT JOIN business_product_base_prices purchase
                ON purchase.product_id = p.id AND purchase.price_kind = "purchase"
                   AND purchase.valid_from IS NULL AND purchase.valid_until IS NULL
             LEFT JOIN business_product_base_prices sale
                ON sale.product_id = p.id AND sale.price_kind = "sale"
                   AND sale.valid_from IS NULL AND sale.valid_until IS NULL
             WHERE p.site_id = ? AND v.id = ? AND p.archived_at IS NULL AND v.archived_at IS NULL
             LIMIT 1',
            [$siteId, $variantId]
        );
        if ($row === null) {
            throw new InvalidArgumentException('business.catalog.variant_not_found');
        }
        return $row;
    }

    private function requireSiteId(int $siteId): int
    {
        if ($siteId < 1) {
            throw new InvalidArgumentException('business.site_id_invalid');
        }
        return $siteId;
    }

    private function requireVariantId(int $variantId): int
    {
        if ($variantId < 1) {
            throw new InvalidArgumentException('business.catalog.variant_id_invalid');
        }
        return $variantId;
    }

    /** @param array<string,mixed> $context @return array{channel:string,currency:string,include_purchase_price:bool,include_internal_fields:bool,language:string} */
    private function normalizeContext(array $context): array
    {
        return [
            'channel' => $this->channel((string) ($context['channel'] ?? 'admin')),
            'currency' => strtoupper(trim((string) ($context['currency'] ?? 'CHF')) ?: 'CHF'),
            'include_purchase_price' => (bool) ($context['include_purchase_price'] ?? false),
            'include_internal_fields' => (bool) ($context['include_internal_fields'] ?? false),
            'language' => strtolower(trim((string) ($context['language'] ?? 'fr')) ?: 'fr'),
        ];
    }

    private function channel(string $channel): string
    {
        $channel = trim($channel) === '' ? 'admin' : trim($channel);
        if (!in_array($channel, ['admin', 'pos', 'ecommerce', 'public', 'quote'], true)) {
            throw new InvalidArgumentException('business.catalog.channel_invalid');
        }
        return $channel;
    }

    private function pricingChannel(string $channel): string
    {
        return match ($channel) {
            'public' => 'ecommerce',
            'quote' => 'admin',
            default => $channel,
        };
    }

    private function pimChannel(string $channel): string
    {
        return match ($channel) {
            'quote' => 'admin',
            default => $this->pricingChannel($channel),
        };
    }

    private function channelFromId(int $channelId): string
    {
        return match ($channelId) {
            2 => 'pos',
            3 => 'ecommerce',
            default => 'admin',
        };
    }

    /** @param array<string,mixed> $filters @return array{0:list<string>,1:array<string,mixed>} */
    private function searchWhere(int $siteId, array $filters, string $channel): array
    {
        $where = ['p.site_id = :site_id', 'p.archived_at IS NULL', 'v.archived_at IS NULL'];
        $params = ['site_id' => $siteId];
        if (!(bool) ($filters['include_inactive'] ?? false)) {
            $where[] = 'p.status = "active"';
            $where[] = 'v.status = "active"';
        }
        if ($channel === 'pos') {
            $where[] = 'p.is_pos_enabled = 1';
        } elseif (in_array($channel, ['ecommerce', 'public'], true)) {
            $where[] = 'p.is_ecommerce_enabled = 1';
            $where[] = 'p.is_public = 1';
            $where[] = 'p.visibility = "public"';
        }
        if (trim((string) ($filters['product_type'] ?? '')) !== '') {
            $where[] = 'p.type = :product_type';
            $params['product_type'] = $this->productType((string) $filters['product_type']);
        }
        if (trim((string) ($filters['sku'] ?? '')) !== '') {
            $where[] = 'v.sku = :sku';
            $params['sku'] = trim((string) $filters['sku']);
        }
        if (trim((string) ($filters['barcode'] ?? '')) !== '') {
            $where[] = 'v.barcode = :barcode';
            $params['barcode'] = trim((string) $filters['barcode']);
        }
        if (trim((string) ($filters['q'] ?? '')) !== '') {
            $where[] = '(v.sku LIKE :q OR v.barcode LIKE :q OR v.name LIKE :q OR p.name LIKE :q)';
            $params['q'] = '%' . trim((string) $filters['q']) . '%';
        }
        return [$where, $params];
    }

    private function productType(string $type): string
    {
        $type = trim($type);
        if (!in_array($type, ['physical', 'service', 'gift_card', 'bundle'], true)) {
            throw new InvalidArgumentException('business.catalog.product_type_invalid');
        }
        return $type;
    }

    private function moneyMinor(mixed $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }
        return (int) round(((float) $amount) * 100);
    }

    /** @return array<mixed> */
    private function jsonDecode(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
