<?php

declare(strict_types=1);

namespace App\Modules\Business\Repositories;

final class CatalogStockRepository extends BusinessRepositoryBase
{
    /** @return array<string,mixed> */
    public function variant(int $variantId): array
    {
        $row = $this->variantRow($variantId);
        if ($row === null) {
            throw new \InvalidArgumentException('business.catalog.variant_not_found');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    public function stock(int $siteId, int $variantId): array
    {
        $variant = $this->variantForSite($siteId, $variantId);
        $projection = $this->hasInventoryProjectionTable() ? $this->database()->one('SELECT * FROM business_inventory_availability_projections WHERE site_id=? AND sellable_id=?', [$siteId, $variantId]) : null;
        $trackStock = $this->trackStock($variant);
        $quantity = $projection === null ? (float) ($variant['stock_quantity'] ?? 0) : (float) $projection['on_hand_quantity'];
        $reserved = $projection === null ? (float) ($variant['stock_reserved'] ?? 0) : (float) $projection['reserved_quantity'];
        return [
            'variant_id' => (int) $variant['id'],
            'product_id' => (int) $variant['product_id'],
            'sku' => (string) $variant['sku'],
            'track_stock' => $projection === null ? $trackStock : (bool) $projection['tracked'],
            'allow_backorder' => $this->allowBackorder($variant),
            'stock_quantity' => $quantity,
            'stock_reserved' => $reserved,
            'available_quantity' => $trackStock ? max(0, $quantity - $reserved) : null,
            'updated_at' => (string) ($variant['updated_at'] ?? ''),
            'source' => $projection === null ? 'catalog_bootstrap' : 'sale_projection',
        ];
    }

    /** @return array<string,mixed> */
    public function move(int $variantId, string $movementType, float $quantity, ?string $reason = null, ?int $actorId = null): array
    {
        $variant = $this->variant($variantId);
        return $this->createMovement((int) $variant['site_id'], $variantId, $movementType, $quantity, $reason, null, null, $actorId)['variant'];
    }

    /** @return array{variant:array<string,mixed>,movement:array<string,mixed>} */
    public function createMovement(int $siteId, int $variantId, string $movementType, float $quantity, ?string $reason = null, ?string $referenceType = null, ?int $referenceId = null, ?int $actorId = null): array
    {
        if ($this->hasInventoryProjectionTable() && $this->database()->one('SELECT sellable_id FROM business_inventory_availability_projections WHERE site_id=? AND sellable_id=?', [$siteId, $variantId]) !== null) {
            throw new \InvalidArgumentException('business.catalog.stock_transactional_source_sale');
        }
        if ($quantity <= 0 && $movementType !== 'adjustment') {
            throw new \InvalidArgumentException('business.catalog.stock_quantity_invalid');
        }
        if ($quantity == 0.0) {
            throw new \InvalidArgumentException('business.catalog.stock_quantity_invalid');
        }
        $variant = $this->variantForSite($siteId, $variantId);
        $stock = (float) $variant['stock_quantity'];
        $reserved = (float) $variant['stock_reserved'];
        $allowBackorder = $this->allowBackorder($variant);
        $trackStock = $this->trackStock($variant);

        if (!in_array($movementType, ['initial', 'purchase', 'sale', 'adjustment', 'return', 'reservation', 'release'], true)) {
            throw new \InvalidArgumentException('business.catalog.stock_movement_type_invalid');
        }

        if ($trackStock) {
            match ($movementType) {
                'initial', 'purchase', 'return' => $stock += $quantity,
                'sale' => $stock -= $quantity,
                'adjustment' => $stock += $quantity,
                'reservation' => $reserved += $quantity,
                'release' => $reserved -= $quantity,
                default => null,
            };
            if ($reserved < 0) {
                throw new \InvalidArgumentException('business.catalog.stock_reserved_negative');
            }
            if (!$allowBackorder && ($stock < 0 || $reserved > $stock)) {
                throw new \InvalidArgumentException('business.catalog.stock_negative');
            }
        }

        $this->database()->run(
            'INSERT INTO business_stock_movements(variant_id, movement_type, quantity, reason, reference_type, reference_id, created_by_iam_user_id) VALUES(?, ?, ?, ?, ?, ?, ?)',
            [$variantId, $movementType, round($quantity, 2), $this->nullableText($reason, 'reason', 500), $this->nullableText($referenceType, 'reference_type', 80), $referenceId, $actorId]
        );
        $movementId = $this->database()->lastInsertId();
        if ($trackStock) {
            $this->database()->run(
                'UPDATE business_product_variants SET stock_quantity = ?, stock_reserved = ?, updated_by_iam_user_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
                [round($stock, 2), round($reserved, 2), $actorId, $variantId]
            );
        }
        return ['variant' => $this->variant($variantId), 'movement' => $this->movement($movementId)];
    }

    /** @param array<string,mixed> $filters @return array{items:list<array<string,mixed>>,limit:int,offset:int,total:int} */
    public function movements(int $siteId, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = ['p.site_id = :site_id'];
        $params = ['site_id' => $this->requireSiteId($siteId)];
        foreach (['variant_id', 'reference_id'] as $key) {
            if (isset($filters[$key]) && (int) $filters[$key] > 0) {
                $where[] = 'm.' . $key . ' = :' . $key;
                $params[$key] = (int) $filters[$key];
            }
        }
        foreach (['movement_type', 'reference_type'] as $key) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value !== '') {
                $where[] = 'm.' . $key . ' = :' . $key;
                $params[$key] = $value;
            }
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int) (($this->database()->one(
            'SELECT COUNT(*) AS c FROM business_stock_movements m
             INNER JOIN business_product_variants v ON v.id = m.variant_id
             INNER JOIN business_products p ON p.id = v.product_id
             WHERE ' . $sqlWhere,
            $params
        )['c'] ?? 0));
        $limit = $this->limit($limit, 200);
        $offset = $this->offset($offset);
        $rows = $this->database()->all(
            'SELECT m.*, v.sku, v.product_id, p.site_id, p.name AS product_name
             FROM business_stock_movements m
             INNER JOIN business_product_variants v ON v.id = m.variant_id
             INNER JOIN business_products p ON p.id = v.product_id
             WHERE ' . $sqlWhere . '
             ORDER BY m.created_at DESC, m.id DESC
             LIMIT :limit OFFSET :offset',
            $params + ['limit' => $limit, 'offset' => $offset]
        );
        return ['items' => array_map(fn(array $row): array => $this->castRow($row), $rows), 'limit' => $limit, 'offset' => $offset, 'total' => $total];
    }

    /** @return array<string,mixed> */
    private function movement(int $id): array
    {
        $row = $this->database()->one('SELECT * FROM business_stock_movements WHERE id = ? LIMIT 1', [$id]);
        if ($row === null) {
            throw new \InvalidArgumentException('business.catalog.stock_movement_not_found');
        }
        return $this->castRow($row);
    }

    /** @return array<string,mixed> */
    private function variantForSite(int $siteId, int $variantId): array
    {
        $variant = $this->variant($variantId);
        if ((int) $variant['site_id'] !== $this->requireSiteId($siteId)) {
            throw new \InvalidArgumentException('business.catalog.variant_not_found');
        }
        return $variant;
    }

    /** @return array<string,mixed>|null */
    private function variantRow(int $variantId): ?array
    {
        $row = $this->database()->one(
            'SELECT v.*, p.site_id, p.track_stock AS product_track_stock, p.allow_backorder AS product_allow_backorder
             FROM business_product_variants v
             INNER JOIN business_products p ON p.id = v.product_id
             WHERE v.id = ? AND v.archived_at IS NULL AND p.archived_at IS NULL
             LIMIT 1',
            [$variantId]
        );
        return $row ? $this->castRow($row) : null;
    }

    /** @param array<string,mixed> $variant */
    private function trackStock(array $variant): bool
    {
        return array_key_exists('track_stock', $variant) && $variant['track_stock'] !== null
            ? (bool) $variant['track_stock']
            : (bool) ($variant['product_track_stock'] ?? false);
    }

    /** @param array<string,mixed> $variant */
    private function allowBackorder(array $variant): bool
    {
        return array_key_exists('allow_backorder', $variant) && $variant['allow_backorder'] !== null
            ? (bool) $variant['allow_backorder']
            : (bool) ($variant['product_allow_backorder'] ?? false);
    }

    private function hasInventoryProjectionTable(): bool
    {
        return $this->database()->one("SELECT 1 FROM sqlite_master WHERE type='table' AND name='business_inventory_availability_projections'") !== null;
    }
}
