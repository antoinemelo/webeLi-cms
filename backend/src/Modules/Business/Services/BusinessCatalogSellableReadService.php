<?php

declare(strict_types=1);

namespace App\Modules\Business\Services;

use App\Modules\Business\Catalog\CatalogPricingService;
use App\Modules\Business\Repositories\BusinessCatalogPricingRepository;
use App\Modules\Business\Repositories\PosCatalogRepository;
use InvalidArgumentException;

final class BusinessCatalogSellableReadService
{
    public function __construct(
        private readonly BusinessCatalogPricingRepository $pricingRepository,
        private readonly CatalogPricingService $pricing,
        private readonly PosCatalogRepository $catalog
    ) {}

    /** @return array<string,mixed> */
    public function getSellableVariantSnapshot(int $siteId, int $variantId, ?int $channelId = null): array
    {
        $channel = $channelId === null ? 'admin' : $this->channelFromId($channelId);
        return $this->variantSnapshot($siteId, $variantId, $channel, true);
    }

    /** @param array<string,mixed> $filters @return array{items:list<array<string,mixed>>,limit:int,offset:int,total:int,has_more:bool} */
    public function searchSellableVariants(int $siteId, array $filters = []): array
    {
        $siteId = $this->requireSiteId($siteId);
        $channel = $this->channel((string) ($filters['channel'] ?? 'admin'));
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $includeNotSellable = (bool) ($filters['include_not_sellable'] ?? false);

        [$where, $params] = $this->searchWhere($siteId, $filters, $channel);
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
            $snapshot = $this->variantSnapshot($siteId, (int) $row['id'], $channel, true);
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
        $siteId = $this->requireSiteId($siteId);
        if ($businessVariantId < 1) {
            throw new InvalidArgumentException('business.catalog.variant_id_invalid');
        }
        $channel = $this->channel($channel);

        $pricingSnapshot = $this->pricingRepository->pricingSnapshot($businessVariantId, $channel);
        $variant = $pricingSnapshot['variant'];
        if ((int) ($variant['site_id'] ?? 0) !== $siteId) {
            throw new InvalidArgumentException('business.catalog.variant_not_found');
        }

        $row = $this->variantRow($siteId, $businessVariantId);
        $brand = $this->catalog->brand($siteId, isset($row['brand_id']) ? (int) $row['brand_id'] : null);
        $category = $this->catalog->category($siteId, isset($row['category_id']) ? (int) $row['category_id'] : null);
        $taxClass = $this->catalog->taxClass(isset($row['tax_class_id']) ? (int) $row['tax_class_id'] : null);
        $pricingSummary = $this->pricing->pricingSummary($businessVariantId, $channel);

        $trackStock = $row['variant_track_stock'] === null ? (bool) $row['product_track_stock'] : (bool) $row['variant_track_stock'];
        $allowBackorder = $row['variant_allow_backorder'] === null ? (bool) $row['product_allow_backorder'] : (bool) $row['variant_allow_backorder'];
        $available = (float) $row['stock_quantity'] - (float) $row['stock_reserved'];
        $channelEnabled = match ($channel) {
            'pos' => (bool) $row['is_pos_enabled'],
            'ecommerce' => (bool) $row['is_ecommerce_enabled'],
            default => true,
        };
        $isSellable = $channelEnabled
            && (string) $row['product_status'] === 'active'
            && (string) $row['variant_status'] === 'active'
            && (!$trackStock || $allowBackorder || $available > 0.0);

        $currency = (string) ($pricingSummary['variant']['sale_currency'] ?? $row['sale_currency'] ?? 'CHF');
        $regularSaleMinor = $this->moneyMinor($pricingSummary['regular_sale_price'] ?? $row['base_sale_price'] ?? 0);
        $finalSaleMinor = $this->moneyMinor($pricingSummary['final_sale_price'] ?? $row['base_sale_price'] ?? 0);
        $purchaseMinor = $includePurchasePrice ? $this->moneyMinor($pricingSummary['regular_purchase_price'] ?? $row['base_purchase_price'] ?? 0) : null;
        $taxIncluded = (bool) ($row['sale_tax_included'] ?? true);
        $taxRateBasisPoints = $taxClass === null ? 0 : (int) round(((float) ($taxClass['rate'] ?? 0)) * 100);

        $snapshot = [
            'site_id' => $siteId,
            'business_product_id' => (int) $row['business_product_id'],
            'business_variant_id' => (int) $row['business_variant_id'],
            'sku' => (string) $row['sku'],
            'barcode' => $row['barcode'] ?? null,
            'product_name' => (string) $row['product_name'],
            'variant_name' => (string) $row['variant_name'],
            'brand_name' => $brand['name'] ?? null,
            'category_name' => $category['name'] ?? null,
            'product_type' => (string) $row['product_type'],
            'track_stock' => $trackStock,
            'is_sellable' => $isSellable,
            'currency' => strtoupper($currency),
            'regular_unit_price_minor' => $regularSaleMinor,
            'unit_price_minor' => $finalSaleMinor,
            'unit_purchase_price_minor' => $purchaseMinor,
            'tax_class_id' => $row['tax_class_id'] === null ? null : (int) $row['tax_class_id'],
            'tax_rate_basis_points' => $taxRateBasisPoints,
            'tax_included' => $taxIncluded,
            'active_discount_snapshot' => $pricingSummary['active_discount'] ?? null,
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
            ],
        ];

        if (!$includePurchasePrice) {
            unset($snapshot['unit_purchase_price_minor']);
        }

        return $snapshot;
    }

    public function moneyToMinor(float|int|string|null $amount): int
    {
        return $this->moneyMinor($amount);
    }

    /** @return array<string,mixed> */
    public function publicPayload(array $snapshot): array
    {
        unset($snapshot['unit_purchase_price_minor']);
        return $snapshot;
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

    private function channel(string $channel): string
    {
        $channel = trim($channel) === '' ? 'admin' : trim($channel);
        if (!in_array($channel, ['admin', 'pos', 'ecommerce'], true)) {
            throw new InvalidArgumentException('business.catalog.channel_invalid');
        }
        return $channel;
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
        } elseif ($channel === 'ecommerce') {
            $where[] = 'p.is_ecommerce_enabled = 1';
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
        if (!in_array($type, ['physical', 'service', 'gift_card'], true)) {
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
}
