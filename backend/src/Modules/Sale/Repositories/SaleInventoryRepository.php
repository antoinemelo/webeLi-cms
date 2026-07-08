<?php

declare(strict_types=1);

namespace App\Modules\Sale\Repositories;

use App\Modules\Sale\Exceptions\SaleInventoryException;

final class SaleInventoryRepository extends SaleRepositoryBase
{
    /** @return array{items:list<array<string,mixed>>,limit:int,offset:int,total:int,has_more:bool} */
    public function listItems(int $siteId, int $limit = 50, int $offset = 0): array
    {
        $total = (int) ($this->rawDatabase()->one('SELECT COUNT(*) AS count FROM sale_inventory_items WHERE site_id = ?', [$siteId])['count'] ?? 0);
        $items = $this->rawDatabase()->all(
            'SELECT * FROM sale_inventory_items WHERE site_id = ? ORDER BY updated_at DESC, id DESC LIMIT ? OFFSET ?',
            [$siteId, max(1, min(100, $limit)), max(0, $offset)]
        );
        return ['items' => $items, 'limit' => $limit, 'offset' => $offset, 'total' => $total, 'has_more' => ($offset + $limit) < $total];
    }

    /** @return array{items:list<array<string,mixed>>,limit:int,offset:int,total:int,has_more:bool} */
    public function movements(int $siteId, int $limit = 50, int $offset = 0): array
    {
        $params = ['site_id' => $siteId];
        $total = (int) ($this->rawDatabase()->one(
            'SELECT COUNT(*) AS count
             FROM sale_stock_movements m
             INNER JOIN sale_inventory_items i ON i.id = m.inventory_item_id
             WHERE i.site_id = :site_id',
            $params
        )['count'] ?? 0);
        $items = $this->rawDatabase()->all(
            'SELECT m.*, i.business_variant_id, i.sku
             FROM sale_stock_movements m
             INNER JOIN sale_inventory_items i ON i.id = m.inventory_item_id
             WHERE i.site_id = :site_id
             ORDER BY m.created_at DESC, m.id DESC
             LIMIT :limit OFFSET :offset',
            $params + ['limit' => max(1, min(100, $limit)), 'offset' => max(0, $offset)]
        );
        return ['items' => $items, 'limit' => $limit, 'offset' => $offset, 'total' => $total, 'has_more' => ($offset + $limit) < $total];
    }

    public function adjust(int $siteId, int $businessVariantId, int $quantityDelta, ?string $sku = null, ?string $reason = null, ?int $actorId = null): array
    {
        if ($quantityDelta === 0) {
            throw new SaleInventoryException('sale.stock_adjustment_zero');
        }
        $locationId = $this->defaultLocationId($siteId);
        $row = $this->rawDatabase()->one(
            'SELECT * FROM sale_inventory_items WHERE site_id = ? AND business_variant_id = ? AND stock_location_id = ? LIMIT 1',
            [$siteId, $businessVariantId, $locationId]
        );
        if ($row === null) {
            $this->rawDatabase()->run(
                'INSERT INTO sale_inventory_items(site_id, business_variant_id, stock_location_id, sku, tracked, on_hand_quantity, reserved_quantity, available_quantity)
                 VALUES(?, ?, ?, ?, 1, 0, 0, 0)',
                [$siteId, $businessVariantId, $locationId, $sku]
            );
            $row = $this->rawDatabase()->one('SELECT * FROM sale_inventory_items WHERE id = ?', [(int) $this->rawDatabase()->lastInsertId()]);
        }
        $itemId = (int) $row['id'];
        $this->rawDatabase()->run(
            'UPDATE sale_inventory_items
             SET on_hand_quantity = on_hand_quantity + ?,
                 available_quantity = (on_hand_quantity + ?) - reserved_quantity,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?',
            [$quantityDelta, $quantityDelta, $itemId]
        );
        $this->rawDatabase()->run(
            'INSERT INTO sale_stock_movements(inventory_item_id, movement_type, quantity, reference_type, reason, created_by_iam_user_id)
             VALUES(?, "adjustment", ?, "manual_adjustment", ?, ?)',
            [$itemId, $quantityDelta, $reason, $actorId]
        );
        return $this->rawDatabase()->one('SELECT * FROM sale_inventory_items WHERE id = ?', [$itemId]) ?? [];
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    public function ensureItem(int $siteId, array $snapshot): array
    {
        $locationId = $this->defaultLocationId($siteId);
        $variantId = (int) $snapshot['business_variant_id'];
        $row = $this->rawDatabase()->one(
            'SELECT * FROM sale_inventory_items WHERE site_id = ? AND business_variant_id = ? AND stock_location_id = ? LIMIT 1',
            [$siteId, $variantId, $locationId]
        );
        if ($row !== null) {
            return $row;
        }
        $onHand = max(0, (int) round((float) ($snapshot['metadata']['available_quantity'] ?? 0)));
        $this->rawDatabase()->run(
            'INSERT INTO sale_inventory_items(
                site_id, business_variant_id, stock_location_id, sku, tracked,
                on_hand_quantity, reserved_quantity, available_quantity
             ) VALUES(?, ?, ?, ?, ?, ?, 0, ?)',
            [
                $siteId,
                $variantId,
                $locationId,
                $snapshot['sku'] ?? null,
                (int) (bool) ($snapshot['track_stock'] ?? false),
                $onHand,
                $onHand,
            ]
        );
        return $this->rawDatabase()->one('SELECT * FROM sale_inventory_items WHERE id = ?', [(int) $this->rawDatabase()->lastInsertId()]) ?? [];
    }

    public function reserveForCart(int $siteId, int $cartId, array $snapshot, int $quantity): ?array
    {
        if (!((bool) ($snapshot['track_stock'] ?? false))) {
            return null;
        }
        $item = $this->ensureItem($siteId, $snapshot);
        if ((int) $item['available_quantity'] < $quantity) {
            throw new SaleInventoryException('sale.stock_insufficient');
        }
        $reservationKey = 'cart:' . $cartId . ':variant:' . (int) $snapshot['business_variant_id'] . ':' . bin2hex(random_bytes(3));
        $this->rawDatabase()->run(
            'UPDATE sale_inventory_items
             SET reserved_quantity = reserved_quantity + ?,
                 available_quantity = on_hand_quantity - (reserved_quantity + ?),
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?',
            [$quantity, $quantity, (int) $item['id']]
        );
        $this->rawDatabase()->run(
            'INSERT INTO sale_stock_reservations(inventory_item_id, cart_id, reservation_key, quantity)
             VALUES(?, ?, ?, ?)',
            [(int) $item['id'], $cartId, $reservationKey, $quantity]
        );
        $reservationId = (int) $this->rawDatabase()->lastInsertId();
        $this->movement((int) $item['id'], 'reservation', $quantity, 'cart', $cartId, 'cart line reservation');
        return $this->rawDatabase()->one('SELECT * FROM sale_stock_reservations WHERE id = ?', [$reservationId]) ?? null;
    }

    public function consumeCartReservations(int $cartId, int $orderId): void
    {
        foreach ($this->rawDatabase()->all('SELECT * FROM sale_stock_reservations WHERE cart_id = ? AND status = "active"', [$cartId]) as $reservation) {
            $quantity = (int) $reservation['quantity'];
            $itemId = (int) $reservation['inventory_item_id'];
            $this->rawDatabase()->run(
                'UPDATE sale_inventory_items
                 SET on_hand_quantity = on_hand_quantity - ?,
                     reserved_quantity = reserved_quantity - ?,
                     available_quantity = (on_hand_quantity - ?) - (reserved_quantity - ?),
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?',
                [$quantity, $quantity, $quantity, $quantity, $itemId]
            );
            $this->rawDatabase()->run(
                'UPDATE sale_stock_reservations
                 SET status = "consumed", order_id = ?, consumed_at = CURRENT_TIMESTAMP
                 WHERE id = ?',
                [$orderId, (int) $reservation['id']]
            );
            $this->movement($itemId, 'sale', -$quantity, 'order', $orderId, 'checkout consumption');
        }
    }

    public function releaseCartReservations(int $cartId): void
    {
        foreach ($this->rawDatabase()->all('SELECT * FROM sale_stock_reservations WHERE cart_id = ? AND status = "active"', [$cartId]) as $reservation) {
            $quantity = (int) $reservation['quantity'];
            $itemId = (int) $reservation['inventory_item_id'];
            $this->rawDatabase()->run(
                'UPDATE sale_inventory_items
                 SET reserved_quantity = reserved_quantity - ?,
                     available_quantity = on_hand_quantity - (reserved_quantity - ?),
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?',
                [$quantity, $quantity, $itemId]
            );
            $this->rawDatabase()->run(
                'UPDATE sale_stock_reservations SET status = "released", released_at = CURRENT_TIMESTAMP WHERE id = ?',
                [(int) $reservation['id']]
            );
            $this->movement($itemId, 'release', -$quantity, 'cart', $cartId, 'reservation release');
        }
    }

    private function defaultLocationId(int $siteId): int
    {
        $row = $this->rawDatabase()->one(
            'SELECT id FROM sale_stock_locations WHERE site_id = ? AND code = "main" LIMIT 1',
            [$siteId]
        );
        if ($row !== null) {
            return (int) $row['id'];
        }
        $this->rawDatabase()->run(
            'INSERT INTO sale_stock_locations(site_id, code, name, location_type, status)
             VALUES(?, "main", "Stock principal", "main", "active")',
            [$siteId]
        );
        return (int) $this->rawDatabase()->lastInsertId();
    }

    private function movement(int $itemId, string $type, int $quantity, string $referenceType, int $referenceId, string $reason): void
    {
        $this->rawDatabase()->run(
            'INSERT INTO sale_stock_movements(inventory_item_id, movement_type, quantity, reference_type, reference_id, reason)
             VALUES(?, ?, ?, ?, ?, ?)',
            [$itemId, $type, $quantity, $referenceType, $referenceId, $reason]
        );
    }
}
