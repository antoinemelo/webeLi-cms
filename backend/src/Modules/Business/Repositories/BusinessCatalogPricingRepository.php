<?php

declare(strict_types=1);

namespace App\Modules\Business\Repositories;

use App\Core\Database;
use DateTimeImmutable;
use InvalidArgumentException;

class BusinessCatalogPricingRepository extends BusinessRepositoryBase
{
    public function rawDatabase(): Database
    {
        return $this->database();
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function createProduct(int $siteId, array $payload): array
    {
        $db = $this->database();
        $db->run(
            'INSERT INTO business_products
                (site_id, brand_id, category_id, name, slug, type, status, visibility, sku_base, unit, track_stock, allow_backorder, backorder_delivery_days, is_public, is_ecommerce_enabled, is_pos_enabled, is_catalogue_enabled)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $this->requireSiteId($siteId),
                $payload['brand_id'] ?? null,
                $payload['category_id'] ?? null,
                $payload['name'],
                $payload['slug'],
                $payload['type'] ?? 'physical',
                $payload['status'] ?? 'draft',
                in_array('public', $payload['channels'] ?? [], true) ? 'public' : 'internal',
                $payload['sku_base'] ?? null,
                $payload['unit'] ?? 'unit',
                (int) ($payload['stock_enabled'] ?? $payload['track_stock'] ?? false),
                (int) ($payload['allow_backorder'] ?? true),
                max(0, (int) ($payload['backorder_delivery_days'] ?? $payload['delivery_lead_time_days'] ?? 7)),
                (int) in_array('public', $payload['channels'] ?? [], true),
                (int) in_array('ecommerce', $payload['channels'] ?? [], true),
                (int) in_array('pos', $payload['channels'] ?? [], true),
                (int) (in_array('catalogue', $payload['channels'] ?? [], true) || !in_array('internal', $payload['channels'] ?? [], true)),
            ]
        );
        $product = $this->product((int) $db->lastInsertId());
        $this->setBasePrice((int) $product['id'], 'purchase', $payload['base_purchase_price'] ?? null, $payload['currency'] ?? 'CHF', false);
        $this->setBasePrice((int) $product['id'], 'sale', $payload['base_sale_price'] ?? null, $payload['currency'] ?? 'CHF', true);
        return $this->product((int) $product['id']);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function createVariant(int $productId, array $payload): array
    {
        $db = $this->database();
        $product = $this->product($productId);
        $db->run(
            'INSERT INTO business_product_variants
                (product_id, sku, barcode, name, status, stock_quantity, stock_reserved, track_stock, allow_backorder, backorder_delivery_days)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $product['id'],
                $payload['sku'],
                $payload['barcode'] ?? null,
                $payload['name'] ?? $payload['sku'],
                $payload['status'] ?? 'draft',
                max(0, (int) ($payload['stock_quantity'] ?? 0)),
                max(0, (int) ($payload['stock_reserved'] ?? 0)),
                $payload['track_stock'] ?? null,
                $payload['allow_backorder'] ?? null,
                array_key_exists('backorder_delivery_days', $payload) || array_key_exists('delivery_lead_time_days', $payload) ? max(0, (int) ($payload['backorder_delivery_days'] ?? $payload['delivery_lead_time_days'] ?? 0)) : null,
            ]
        );
        $variantId = (int) $db->lastInsertId();
        $this->setAdjustment($variantId, 'purchase', $payload['purchase_adjustment_type'] ?? 'none', $payload['purchase_adjustment_value'] ?? null);
        $this->setAdjustment($variantId, 'sale', $payload['sale_adjustment_type'] ?? 'none', $payload['sale_adjustment_value'] ?? null);
        return $this->variant($variantId);
    }

    /** @return array<string,mixed> */
    public function setAdjustment(int $variantId, string $priceKind, string $adjustmentType, float|int|string|null $adjustmentValue): array
    {
        if (!in_array($priceKind, ['purchase', 'sale'], true)) {
            throw new InvalidArgumentException('business.catalog.price_kind_invalid');
        }
        if (!in_array($adjustmentType, ['none', 'amount_delta', 'percent_delta', 'fixed_override'], true)) {
            throw new InvalidArgumentException('business.catalog.price_adjustment_type_invalid');
        }
        $db = $this->database();
        if ($adjustmentType === 'none') {
            $db->run('DELETE FROM business_product_variant_price_adjustments WHERE variant_id = ? AND price_kind = ?', [$variantId, $priceKind]);
            return ['variant_id' => $variantId, 'price_kind' => $priceKind, 'adjustment_type' => 'none', 'adjustment_value' => null, 'currency' => null];
        }
        $value = round((float) $adjustmentValue, 2);
        if ($value < 0) {
            throw new InvalidArgumentException('business.catalog.price_adjustment_positive_required');
        }
        $currency = $adjustmentType === 'fixed_override' ? 'CHF' : null;
        $db->run('DELETE FROM business_product_variant_price_adjustments WHERE variant_id = ? AND price_kind = ? AND valid_from IS NULL', [$variantId, $priceKind]);
        $db->run(
            'INSERT INTO business_product_variant_price_adjustments
                (variant_id, price_kind, adjustment_type, adjustment_value, currency)
             VALUES (?, ?, ?, ?, ?)',
            [$variantId, $priceKind, $adjustmentType, $value, $currency]
        );
        return $this->adjustment($variantId, $priceKind);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function createOffer(int $siteId, array $payload): array
    {
        $db = $this->database();
        $db->run(
            'INSERT INTO business_catalog_discounts
                (site_id, name, discount_type, discount_value, currency, scope_type, scope_id, channel, customer_segment, starts_at, ends_at, status, priority)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $this->requireSiteId($siteId),
                $payload['name'],
                $payload['type'] ?? $payload['offer_type'] ?? 'percent',
                $payload['value'] ?? $payload['offer_value'],
                ($payload['type'] ?? $payload['offer_type'] ?? 'percent') === 'amount' ? ($payload['currency'] ?? 'CHF') : null,
                $payload['scope'] ?? $payload['scope_type'] ?? 'product',
                $payload['scope_id'] ?? $payload['variant_id'] ?? $payload['product_id'] ?? $payload['category_id'] ?? $payload['brand_id'] ?? null,
                $payload['channel'] ?? 'all',
                isset($payload['customer_segment']) && trim((string) $payload['customer_segment']) !== '' ? strtolower(trim((string) $payload['customer_segment'])) : null,
                $payload['starts_at'] ?? null,
                $payload['ends_at'] ?? null,
                $payload['status'] ?? 'active',
                (int) ($payload['priority'] ?? 100),
            ]
        );
        return $this->offer((int) $db->lastInsertId());
    }

    /** @return array<string,mixed> */
    /** @param array<string,mixed> $context @return array<string,mixed> */
    public function pricingSnapshot(int $variantId, ?string $channel = null, ?DateTimeImmutable $at = null, array $context = []): array
    {
        $db = $this->database();
        $at ??= new DateTimeImmutable();
        $now = $at->format('Y-m-d H:i:s');
        $currency = strtoupper(trim((string) ($context['currency'] ?? 'CHF')));
        $segment = strtolower(trim((string) ($context['customer_segment'] ?? '')));
        $segment = $segment === '' ? null : $segment;
        $row = $db->one(
            'SELECT
                v.id AS variant_id,
                v.sku,
                v.status AS variant_status,
                v.stock_quantity,
                p.id AS product_id,
                p.site_id,
                p.brand_id,
                p.category_id,
                p.name AS product_name,
                p.slug AS product_slug,
                p.type AS product_type,
                p.status AS product_status,
                purchase.currency AS purchase_currency,
                purchase.amount AS base_purchase_price,
                sale.currency AS sale_currency,
                sale.amount AS base_sale_price
             FROM business_product_variants v
             INNER JOIN business_products p ON p.id = v.product_id
             LEFT JOIN business_product_base_prices purchase ON purchase.id = (
                SELECT bp.id FROM business_product_base_prices bp
                WHERE bp.product_id=p.id AND bp.price_kind=\'purchase\' AND bp.currency=?
                  AND (bp.valid_from IS NULL OR bp.valid_from<=?) AND (bp.valid_until IS NULL OR bp.valid_until>=?)
                ORDER BY CASE WHEN bp.valid_from IS NULL THEN 1 ELSE 0 END, bp.valid_from DESC, bp.id DESC LIMIT 1
             )
             LEFT JOIN business_product_base_prices sale ON sale.id = (
                SELECT bp.id FROM business_product_base_prices bp
                WHERE bp.product_id=p.id AND bp.price_kind=\'sale\' AND bp.currency=?
                  AND (bp.valid_from IS NULL OR bp.valid_from<=?) AND (bp.valid_until IS NULL OR bp.valid_until>=?)
                ORDER BY CASE WHEN bp.valid_from IS NULL THEN 1 ELSE 0 END, bp.valid_from DESC, bp.id DESC LIMIT 1
             )
             WHERE v.id = ? AND v.archived_at IS NULL AND p.archived_at IS NULL',
            [$currency, $now, $now, $currency, $now, $now, $variantId]
        );
        if ($row === null) {
            throw new InvalidArgumentException('business.catalog.variant_not_found');
        }
        $adjustments = [
            'purchase' => ['adjustment_type' => 'none', 'adjustment_value' => null],
            'sale' => ['adjustment_type' => 'none', 'adjustment_value' => null],
        ];
        foreach ($db->all('SELECT price_kind, adjustment_type, adjustment_value, currency FROM business_product_variant_price_adjustments WHERE variant_id = ?', [$variantId]) as $adjustment) {
            $adjustments[(string) $adjustment['price_kind']] = [
                'adjustment_type' => (string) $adjustment['adjustment_type'],
                'adjustment_value' => $adjustment['adjustment_value'] === null ? null : (float) $adjustment['adjustment_value'],
                'currency' => $adjustment['currency'] ?? null,
            ];
        }

        return [
            'variant' => $this->castPricingRow($row),
            'adjustments' => $adjustments,
            'context' => ['currency' => $currency, 'channel' => $channel ?? 'all', 'customer_segment' => $segment, 'at' => $now],
            'price_rules' => $this->activePriceRules((int) $row['site_id'], (int) $row['product_id'], $variantId, $currency, $channel, $segment, $at),
            'offers' => $this->activeOffers((int) $row['site_id'], (int) $row['product_id'], $variantId, isset($row['brand_id']) ? (int) $row['brand_id'] : null, isset($row['category_id']) ? (int) $row['category_id'] : null, $channel, $currency, $segment, $at),
        ];
    }

    /** @return array<string,mixed> */
    private function product(int $productId): array
    {
        $row = $this->database()->one('SELECT * FROM business_products WHERE id = ?', [$productId]);
        if ($row === null) {
            throw new InvalidArgumentException('business.catalog.product_not_found');
        }
        return $this->castPricingRow($row);
    }

    /** @return array<string,mixed> */
    private function variant(int $variantId): array
    {
        $row = $this->database()->one('SELECT * FROM business_product_variants WHERE id = ?', [$variantId]);
        if ($row === null) {
            throw new InvalidArgumentException('business.catalog.variant_not_found');
        }
        return $this->castPricingRow($row);
    }

    /** @return array<string,mixed> */
    private function adjustment(int $variantId, string $priceKind): array
    {
        $row = $this->database()->one('SELECT * FROM business_product_variant_price_adjustments WHERE variant_id = ? AND price_kind = ?', [$variantId, $priceKind]);
        if ($row === null) {
            throw new InvalidArgumentException('business.catalog.adjustment_not_found');
        }
        return $this->castPricingRow($row);
    }

    /** @return array<string,mixed> */
    private function offer(int $offerId): array
    {
        $row = $this->database()->one('SELECT * FROM business_catalog_discounts WHERE id = ?', [$offerId]);
        if ($row === null) {
            throw new InvalidArgumentException('business.catalog.offer_not_found');
        }
        return $this->castPricingRow($row);
    }

    /** @return list<array<string,mixed>> */
    private function activeOffers(int $siteId, int $productId, int $variantId, ?int $brandId, ?int $categoryId, ?string $channel, string $currency, ?string $segment, DateTimeImmutable $at): array
    {
        $now = $at->format('Y-m-d H:i:s');
        $rows = $this->database()->all(
            'SELECT *
             FROM business_catalog_discounts
             WHERE site_id = ?
               AND status = \'active\'
               AND archived_at IS NULL
               AND (channel = \'all\' OR channel = ?)
               AND (currency IS NULL OR currency = ?)
               AND (customer_segment IS NULL OR customer_segment = ?)
               AND (starts_at IS NULL OR starts_at <= ?)
               AND (ends_at IS NULL OR ends_at >= ?)
               AND (
                    (scope_type = \'product\' AND scope_id = ?)
                 OR (scope_type = \'variant\' AND scope_id = ?)
                 OR (scope_type = \'category\' AND scope_id = ?)
                 OR (scope_type = \'brand\' AND scope_id = ?)
               )
             ORDER BY CASE scope_type WHEN \'variant\' THEN 0 WHEN \'product\' THEN 1 WHEN \'category\' THEN 2 ELSE 3 END ASC,
                      CASE WHEN customer_segment IS NULL THEN 1 ELSE 0 END,
                      CASE WHEN channel = \'all\' THEN 1 ELSE 0 END,
                      priority ASC, id ASC',
            [$siteId, $channel ?? 'all', $currency, $segment, $now, $now, $productId, $variantId, $categoryId ?? 0, $brandId ?? 0]
        );
        return array_map(fn(array $row): array => $this->castPricingRow($row), $rows);
    }

    /** @return list<array<string,mixed>> */
    private function activePriceRules(int $siteId, int $productId, int $variantId, string $currency, ?string $channel, ?string $segment, DateTimeImmutable $at): array
    {
        $now = $at->format('Y-m-d H:i:s');
        return array_map(fn(array $row): array => $this->castPricingRow($row), $this->database()->all(
            'SELECT pli.*, pl.name AS price_list_name, pl.currency, pl.channel, pl.customer_segment,
                    pl.priority AS list_priority,
                    CASE WHEN pli.variant_id IS NOT NULL THEN 0 ELSE 1 END AS target_rank,
                    CASE WHEN pl.customer_segment IS NOT NULL THEN 0 ELSE 1 END AS segment_rank,
                    CASE WHEN pl.channel <> \'all\' THEN 0 ELSE 1 END AS channel_rank
             FROM business_price_list_items pli
             INNER JOIN business_price_lists pl ON pl.id=pli.price_list_id
             WHERE pl.site_id=? AND pl.currency=? AND pl.status=\'active\' AND pl.archived_at IS NULL
               AND pli.archived_at IS NULL AND pli.product_id=? AND (pli.variant_id IS NULL OR pli.variant_id=?)
               AND (pl.channel=\'all\' OR pl.channel=?)
               AND (pl.customer_segment IS NULL OR pl.customer_segment=?)
               AND (pl.starts_at IS NULL OR pl.starts_at<=?) AND (pl.ends_at IS NULL OR pl.ends_at>=?)
               AND (pli.starts_at IS NULL OR pli.starts_at<=?) AND (pli.ends_at IS NULL OR pli.ends_at>=?)
             ORDER BY target_rank, segment_rank, channel_rank, pl.priority, pli.priority, pl.id, pli.id',
            [$siteId, $currency, $productId, $variantId, $channel ?? 'all', $segment, $now, $now, $now, $now]
        ));
    }

    private function setBasePrice(int $productId, string $priceKind, mixed $amount, string $currency, bool $taxIncluded): void
    {
        if ($amount === null || $amount === '') {
            return;
        }
        $this->database()->run(
            'DELETE FROM business_product_base_prices WHERE product_id = ? AND price_kind = ? AND currency = ? AND valid_from IS NULL',
            [$productId, $priceKind, strtoupper($currency)]
        );
        $this->database()->run(
            'INSERT INTO business_product_base_prices(product_id, price_kind, currency, amount, tax_included)
             VALUES (?, ?, ?, ?, ?)',
            [$productId, $priceKind, strtoupper($currency), round((float) $amount, 2), (int) $taxIncluded]
        );
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function castPricingRow(array $row): array
    {
        foreach (['id', 'site_id', 'product_id', 'variant_id', 'stock_quantity', 'price_list_id', 'priority', 'list_priority', 'target_rank', 'segment_rank', 'channel_rank'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (int) $row[$key];
            }
        }
        foreach (['base_purchase_price', 'base_sale_price', 'adjustment_value', 'offer_value', 'discount_value', 'compare_at_amount'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (float) $row[$key];
            }
        }
        return $row;
    }
}
