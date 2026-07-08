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
                (site_id, brand_id, category_id, name, slug, type, status, visibility, sku_base, unit, track_stock, allow_backorder, is_public, is_ecommerce_enabled, is_pos_enabled)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
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
                (int) ($payload['allow_backorder'] ?? false),
                (int) in_array('public', $payload['channels'] ?? [], true),
                (int) in_array('ecommerce', $payload['channels'] ?? [], true),
                (int) in_array('pos', $payload['channels'] ?? [], true),
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
                (product_id, sku, barcode, name, status, stock_quantity, stock_reserved, track_stock, allow_backorder)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
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
                (site_id, name, discount_type, discount_value, currency, scope_type, scope_id, channel, starts_at, ends_at, status, priority)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $this->requireSiteId($siteId),
                $payload['name'],
                $payload['type'] ?? $payload['offer_type'] ?? 'percent',
                $payload['value'] ?? $payload['offer_value'],
                ($payload['type'] ?? $payload['offer_type'] ?? 'percent') === 'amount' ? ($payload['currency'] ?? 'CHF') : null,
                $payload['scope'] ?? $payload['scope_type'] ?? 'product',
                $payload['scope_id'] ?? $payload['variant_id'] ?? $payload['product_id'] ?? $payload['category_id'] ?? $payload['brand_id'] ?? null,
                $payload['channel'] ?? 'all',
                $payload['starts_at'] ?? null,
                $payload['ends_at'] ?? null,
                $payload['status'] ?? 'active',
                (int) ($payload['priority'] ?? 100),
            ]
        );
        return $this->offer((int) $db->lastInsertId());
    }

    /** @return array<string,mixed> */
    public function pricingSnapshot(int $variantId, ?string $channel = null, ?DateTimeImmutable $at = null): array
    {
        $db = $this->database();
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
             LEFT JOIN business_product_base_prices purchase
                ON purchase.product_id = p.id AND purchase.price_kind = "purchase"
                   AND purchase.valid_from IS NULL
                   AND purchase.valid_until IS NULL
             LEFT JOIN business_product_base_prices sale
                ON sale.product_id = p.id AND sale.price_kind = "sale"
                   AND sale.valid_from IS NULL
                   AND sale.valid_until IS NULL
             WHERE v.id = ? AND v.archived_at IS NULL AND p.archived_at IS NULL',
            [$variantId]
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
            'offers' => $this->activeOffers((int) $row['site_id'], (int) $row['product_id'], $variantId, isset($row['brand_id']) ? (int) $row['brand_id'] : null, isset($row['category_id']) ? (int) $row['category_id'] : null, $channel, $at ?? new DateTimeImmutable()),
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
    private function activeOffers(int $siteId, int $productId, int $variantId, ?int $brandId, ?int $categoryId, ?string $channel, DateTimeImmutable $at): array
    {
        $now = $at->format('Y-m-d H:i:s');
        $rows = $this->database()->all(
            'SELECT *
             FROM business_catalog_discounts
             WHERE site_id = ?
               AND status = "active"
               AND archived_at IS NULL
               AND (channel = "all" OR channel = ?)
               AND (starts_at IS NULL OR starts_at <= ?)
               AND (ends_at IS NULL OR ends_at >= ?)
               AND (
                    (scope_type = "product" AND scope_id = ?)
                 OR (scope_type = "variant" AND scope_id = ?)
                 OR (scope_type = "category" AND scope_id = ?)
                 OR (scope_type = "brand" AND scope_id = ?)
               )
             ORDER BY CASE scope_type WHEN "variant" THEN 0 WHEN "product" THEN 1 WHEN "category" THEN 2 ELSE 3 END ASC, priority ASC, id ASC',
            [$siteId, $channel ?? 'all', $now, $now, $productId, $variantId, $categoryId ?? 0, $brandId ?? 0]
        );
        return array_map(fn(array $row): array => $this->castPricingRow($row), $rows);
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
        foreach (['id', 'site_id', 'product_id', 'variant_id', 'stock_quantity'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (int) $row[$key];
            }
        }
        foreach (['base_purchase_price', 'base_sale_price', 'adjustment_value', 'offer_value', 'discount_value'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (float) $row[$key];
            }
        }
        return $row;
    }
}
