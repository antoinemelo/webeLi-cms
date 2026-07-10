<?php

declare(strict_types=1);

namespace App\Modules\Business\Repositories;

final class CatalogVariantRepository extends BusinessRepositoryBase
{
    private ?bool $hasSalesNoteColumn = null;

    /** @return list<array<string,mixed>> */
    public function listForProduct(int $siteId, int $productId, bool $includeArchived = false): array
    {
        $this->productForSite($siteId, $productId);
        $rows = $this->database()->all(
            'SELECT v.*, p.site_id FROM business_product_variants v INNER JOIN business_products p ON p.id = v.product_id
             WHERE v.product_id = ?' . ($includeArchived ? '' : ' AND v.archived_at IS NULL') . ' ORDER BY v.sort_order ASC, v.id ASC',
            [$productId]
        );
        return array_map(fn(array $row): array => $this->castRow($row), $rows);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function create(int $siteId, int $productId, array $payload, ?int $actorId = null): array
    {
        $product = $this->productForSite($siteId, $productId);
        $sku = $this->sku($payload['sku'] ?? null);
        if ($this->skuExists($sku)) {
            throw new \InvalidArgumentException('business.catalog.sku_exists');
        }
        $hasSalesNoteColumn = $this->hasSalesNoteColumn();
        $params = [
                'product_id' => $product['id'],
                'status' => $this->choice($payload['status'] ?? 'draft', ['draft', 'active', 'archived'], 'variant_status'),
                'sku' => $sku,
                'barcode' => $this->nullableText($payload['barcode'] ?? null, 'barcode', 80),
                'name' => $this->text($payload['name'] ?? $sku, 'name', 180),
                'track_stock' => array_key_exists('track_stock', $payload) ? $this->boolInt($payload['track_stock']) : null,
                'stock_quantity' => max(0, (float) ($payload['stock_quantity'] ?? 0)),
                'stock_reserved' => max(0, (float) ($payload['stock_reserved'] ?? 0)),
                'allow_backorder' => array_key_exists('allow_backorder', $payload) ? $this->boolInt($payload['allow_backorder']) : null,
                'backorder_delivery_days' => array_key_exists('backorder_delivery_days', $payload) || array_key_exists('delivery_lead_time_days', $payload) ? max(0, (int) ($payload['backorder_delivery_days'] ?? $payload['delivery_lead_time_days'] ?? 0)) : null,
                'weight_grams' => $payload['weight_grams'] ?? null,
                'sort_order' => max(0, (int) ($payload['sort_order'] ?? 0)),
                'actor' => $actorId,
        ];
        if ($hasSalesNoteColumn) {
            $params['sales_note'] = $this->nullableText($payload['sales_note'] ?? null, 'sales_note', 2000);
        }
        $this->database()->run(
            'INSERT INTO business_product_variants(product_id, status, sku, barcode, name, ' . ($hasSalesNoteColumn ? 'sales_note, ' : '') . 'track_stock, stock_quantity, stock_reserved, allow_backorder, backorder_delivery_days, weight_grams, sort_order, created_by_iam_user_id, updated_by_iam_user_id)
             VALUES(:product_id, :status, :sku, :barcode, :name, ' . ($hasSalesNoteColumn ? ':sales_note, ' : '') . ':track_stock, :stock_quantity, :stock_reserved, :allow_backorder, :backorder_delivery_days, :weight_grams, :sort_order, :actor, :actor)',
            $params
        );
        $variantId = $this->database()->lastInsertId();
        $this->syncOptionValues($productId, $variantId, $payload['option_values'] ?? []);
        $this->setAdjustment($variantId, 'purchase', $payload['purchase_adjustment_type'] ?? 'none', $payload['purchase_adjustment_value'] ?? null, $actorId);
        $this->setAdjustment($variantId, 'sale', $payload['sale_adjustment_type'] ?? 'none', $payload['sale_adjustment_value'] ?? null, $actorId);
        return $this->findById($variantId) ?? [];
    }

    public function findById(int $id, bool $includeArchived = false): ?array
    {
        $row = $this->database()->one(
            'SELECT v.*, p.site_id FROM business_product_variants v INNER JOIN business_products p ON p.id = v.product_id WHERE v.id = ?' . ($includeArchived ? '' : ' AND v.archived_at IS NULL') . ' LIMIT 1',
            [$id]
        );
        return $row ? $this->castRow($row) : null;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed>|null */
    public function update(int $siteId, int $id, array $payload, ?int $actorId = null): ?array
    {
        $current = $this->findById($id, true);
        if ($current === null || (int) $current['site_id'] !== $this->requireSiteId($siteId)) {
            return null;
        }
        $sku = array_key_exists('sku', $payload) ? $this->sku($payload['sku']) : (string) $current['sku'];
        if ($sku !== (string) $current['sku'] && $this->skuExists($sku)) {
            throw new \InvalidArgumentException('business.catalog.sku_exists');
        }
        $hasSalesNoteColumn = $this->hasSalesNoteColumn();
        $params = [
                'id' => $id,
                'status' => $this->choice($payload['status'] ?? $current['status'], ['draft', 'active', 'archived'], 'variant_status'),
                'sku' => $sku,
                'barcode' => $this->nullableText($payload['barcode'] ?? $current['barcode'] ?? null, 'barcode', 80),
                'name' => $this->text($payload['name'] ?? $current['name'], 'name', 180),
                'track_stock' => array_key_exists('track_stock', $payload) ? $this->boolInt($payload['track_stock']) : ($current['track_stock'] ?? null),
                'stock_quantity' => max(0, (float) ($payload['stock_quantity'] ?? $current['stock_quantity'] ?? 0)),
                'stock_reserved' => max(0, (float) ($payload['stock_reserved'] ?? $current['stock_reserved'] ?? 0)),
                'allow_backorder' => array_key_exists('allow_backorder', $payload) ? $this->boolInt($payload['allow_backorder']) : ($current['allow_backorder'] ?? null),
                'backorder_delivery_days' => array_key_exists('backorder_delivery_days', $payload) || array_key_exists('delivery_lead_time_days', $payload) ? max(0, (int) ($payload['backorder_delivery_days'] ?? $payload['delivery_lead_time_days'] ?? 0)) : ($current['backorder_delivery_days'] ?? null),
                'weight_grams' => $payload['weight_grams'] ?? $current['weight_grams'] ?? null,
                'sort_order' => max(0, (int) ($payload['sort_order'] ?? $current['sort_order'] ?? 0)),
                'actor' => $actorId,
        ];
        if ($hasSalesNoteColumn) {
            $params['sales_note'] = $this->nullableText($payload['sales_note'] ?? $current['sales_note'] ?? null, 'sales_note', 2000);
        }
        $this->database()->run(
            'UPDATE business_product_variants
             SET status = :status, sku = :sku, barcode = :barcode, name = :name, ' . ($hasSalesNoteColumn ? 'sales_note = :sales_note, ' : '') . 'track_stock = :track_stock, stock_quantity = :stock_quantity,
                 stock_reserved = :stock_reserved, allow_backorder = :allow_backorder, backorder_delivery_days = :backorder_delivery_days, weight_grams = :weight_grams, sort_order = :sort_order,
                 updated_by_iam_user_id = :actor, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            $params
        );
        if (array_key_exists('option_values', $payload)) {
            $this->database()->run('DELETE FROM business_product_variant_option_values WHERE variant_id = ?', [$id]);
            $this->syncOptionValues((int) $current['product_id'], $id, $payload['option_values']);
        }
        return $this->findById($id, true);
    }

    public function archive(int $siteId, int $id, ?int $actorId = null): bool
    {
        $variant = $this->findById($id);
        if ($variant === null || (int) $variant['site_id'] !== $this->requireSiteId($siteId)) {
            return false;
        }
        $this->database()->run(
            'UPDATE business_product_variants SET status = \'archived\', archived_at = COALESCE(archived_at, CURRENT_TIMESTAMP), updated_by_iam_user_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
            [$actorId, $id]
        );
        return true;
    }

    public function setAdjustment(int $variantId, string $priceKind, string $type, mixed $value, ?int $actorId = null): void
    {
        if (!in_array($priceKind, ['purchase', 'sale'], true)) {
            throw new \InvalidArgumentException('business.catalog.price_kind_invalid');
        }
        if ($type === 'none') {
            $this->database()->run('DELETE FROM business_product_variant_price_adjustments WHERE variant_id = ? AND price_kind = ?', [$variantId, $priceKind]);
            return;
        }
        if (!in_array($type, ['amount_delta', 'percent_delta', 'fixed_override'], true) || !is_numeric($value)) {
            throw new \InvalidArgumentException('business.catalog.price_adjustment_invalid');
        }
        $amount = round((float) $value, 2);
        if ($amount < 0) {
            throw new \InvalidArgumentException('business.catalog.price_adjustment_positive_required');
        }
        if ($type === 'percent_delta' && ($amount < -100 || $amount > 1000)) {
            throw new \InvalidArgumentException('business.catalog.price_adjustment_percent_invalid');
        }
        $currency = $type === 'fixed_override' ? 'CHF' : null;
        $this->database()->run('DELETE FROM business_product_variant_price_adjustments WHERE variant_id = ? AND price_kind = ? AND valid_from IS NULL', [$variantId, $priceKind]);
        $this->database()->run(
            'INSERT INTO business_product_variant_price_adjustments(variant_id, price_kind, adjustment_type, adjustment_value, currency, created_by_iam_user_id, updated_by_iam_user_id)
             VALUES(?, ?, ?, ?, ?, ?, ?)',
            [$variantId, $priceKind, $type, $amount, $currency, $actorId, $actorId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function adjustments(int $variantId): array
    {
        return array_map(fn(array $row): array => $this->castRow($row), $this->database()->all(
            'SELECT * FROM business_product_variant_price_adjustments WHERE variant_id = ? ORDER BY price_kind ASC, id ASC',
            [$variantId]
        ));
    }

    /** @return list<array<string,mixed>> */
    public function optionValues(int $variantId): array
    {
        return array_map(fn(array $row): array => $this->castRow($row), $this->database()->all(
            'SELECT o.code AS option_code, o.name AS option_name, ov.code AS value_code, ov.label, ov.value, ov.color_hex
             FROM business_product_variant_option_values vv
             INNER JOIN business_product_options o ON o.id = vv.option_id
             INNER JOIN business_product_option_values ov ON ov.id = vv.option_value_id
             WHERE vv.variant_id = ?
             ORDER BY o.sort_order ASC, ov.sort_order ASC',
            [$variantId]
        ));
    }

    /** @param array<string,string> $optionValues */
    private function syncOptionValues(int $productId, int $variantId, mixed $optionValues): void
    {
        if (!is_array($optionValues)) {
            return;
        }
        foreach ($optionValues as $optionCode => $valueCode) {
            $row = $this->database()->one(
                'SELECT o.id AS option_id, ov.id AS option_value_id
                 FROM business_product_option_links l
                 INNER JOIN business_product_options o ON o.id = l.option_id
                 INNER JOIN business_product_option_values ov ON ov.option_id = o.id
                 WHERE l.product_id = ? AND o.code = ? AND ov.code = ? AND o.archived_at IS NULL AND ov.archived_at IS NULL
                 LIMIT 1',
                [$productId, (string) $optionCode, (string) $valueCode]
            );
            if ($row === null) {
                throw new \InvalidArgumentException('business.catalog.option_not_linked');
            }
            $this->database()->run(
                'INSERT OR REPLACE INTO business_product_variant_option_values(variant_id, option_id, option_value_id) VALUES(?, ?, ?)',
                [$variantId, (int) $row['option_id'], (int) $row['option_value_id']]
            );
        }
    }

    /** @return array<string,mixed> */
    private function productForSite(int $siteId, int $productId): array
    {
        $row = $this->database()->one('SELECT * FROM business_products WHERE site_id = ? AND id = ? AND archived_at IS NULL LIMIT 1', [$this->requireSiteId($siteId), $productId]);
        if ($row === null) {
            throw new \InvalidArgumentException('business.catalog.product_not_found');
        }
        return $this->castRow($row);
    }

    private function skuExists(string $sku): bool
    {
        return $this->database()->one('SELECT 1 FROM business_product_variants WHERE sku = ? AND archived_at IS NULL LIMIT 1', [$sku]) !== null;
    }

    private function hasSalesNoteColumn(): bool
    {
        if ($this->hasSalesNoteColumn !== null) {
            return $this->hasSalesNoteColumn;
        }
        foreach ($this->database()->all('PRAGMA table_info(business_product_variants)') as $column) {
            if (($column['name'] ?? '') === 'sales_note') {
                return $this->hasSalesNoteColumn = true;
            }
        }
        return $this->hasSalesNoteColumn = false;
    }

    private function sku(mixed $value): string
    {
        $sku = trim((string) $value);
        if ($sku === '' || strlen($sku) > 80) {
            throw new \InvalidArgumentException('business.catalog.sku_invalid');
        }
        return $sku;
    }

    /** @param list<string> $allowed */
    private function choice(mixed $value, array $allowed, string $field): string
    {
        $choice = trim((string) $value);
        if (!in_array($choice, $allowed, true)) {
            throw new \InvalidArgumentException('business.catalog.' . $field . '_invalid');
        }
        return $choice;
    }
}
