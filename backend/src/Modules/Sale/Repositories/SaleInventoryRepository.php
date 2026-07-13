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

    public function adjust(int $siteId, int $businessVariantId, int $quantityDelta, ?string $sku = null, ?string $reason = null, ?int $actorId = null, ?int $locationId = null, string $movementType = 'adjustment', ?string $idempotencyKey = null): array
    {
        if ($quantityDelta === 0) {
            throw new SaleInventoryException('sale.stock_adjustment_zero');
        }
        if (!in_array($movementType, ['receipt', 'issue', 'adjustment', 'correction'], true)) {
            throw new SaleInventoryException('sale.stock_movement_type_invalid');
        }
        $locationId ??= $this->defaultLocationId($siteId);
        return $this->rawDatabase()->transaction(function () use ($siteId, $businessVariantId, $locationId, $sku, $quantityDelta, $movementType, $reason, $actorId, $idempotencyKey): array {
            if ($idempotencyKey !== null) {
                $existing = $this->rawDatabase()->one('SELECT inventory_item_id FROM sale_stock_movements WHERE idempotency_key=?', [$idempotencyKey]);
                if ($existing !== null) {
                    return $this->rawDatabase()->one('SELECT * FROM sale_inventory_items WHERE id=?', [(int) $existing['inventory_item_id']]) ?? [];
                }
            }
            $row = $this->rawDatabase()->one('SELECT * FROM sale_inventory_items WHERE site_id=? AND business_variant_id=? AND stock_location_id=?', [$siteId, $businessVariantId, $locationId]);
            if ($row === null) {
                $this->rawDatabase()->run('INSERT INTO sale_inventory_items(site_id,business_variant_id,sellable_id,stock_location_id,sku,tracked,on_hand_quantity,reserved_quantity,available_quantity) VALUES(?,?,?,?,?,1,0,0,0)', [$siteId, $businessVariantId, $businessVariantId, $locationId, $sku]);
                $row = $this->rawDatabase()->one('SELECT * FROM sale_inventory_items WHERE id=?', [$this->rawDatabase()->lastInsertId()]);
            }
            $itemId = (int) $row['id'];
            $stmt = $this->rawDatabase()->pdo()->prepare('UPDATE sale_inventory_items SET on_hand_quantity=on_hand_quantity+?,available_quantity=available_quantity+?,version=version+1,updated_at=CURRENT_TIMESTAMP WHERE id=? AND (allow_negative=1 OR available_quantity+?>=0)');
            $stmt->execute([$quantityDelta, $quantityDelta, $itemId, $quantityDelta]);
            if ($stmt->rowCount() !== 1) {
                throw new SaleInventoryException('sale.stock_negative_forbidden');
            }
            $this->movement($itemId, $movementType, $quantityDelta, 'manual_' . $movementType, null, $reason ?? $movementType, $actorId, $idempotencyKey);
            return $this->rawDatabase()->one('SELECT * FROM sale_inventory_items WHERE id=?', [$itemId]) ?? [];
        });
    }

    public function locationIdForCart(array $cart): int
    {
        if (($cart['cart_kind'] ?? null) === 'pos' && (int) ($cart['register_session_id'] ?? 0) > 0) {
            $row = $this->rawDatabase()->one(
                'SELECT r.stock_location_id FROM sale_cash_sessions s INNER JOIN sale_pos_registers r ON r.id=s.register_id WHERE s.id=? AND s.status IN (\'open\',\'closing\')',
                [(int) ($cart['register_session_id'] ?? 0)]
            );
            if ($row === null || (int) ($row['stock_location_id'] ?? 0) < 1) {
                throw new SaleInventoryException('sale.pos_stock_location_required');
            }
            return (int) $row['stock_location_id'];
        }
        $row = $this->rawDatabase()->one(
            "SELECT stock_location_id FROM sale_inventory_channel_configs WHERE channel_id=? AND site_id=? AND status='active'",
            [(int) $cart['channel_id'], (int) $cart['site_id']]
        );
        return $row === null ? $this->defaultLocationId((int) $cart['site_id']) : (int) $row['stock_location_id'];
    }

    /** @return array<string,mixed> */
    public function availability(int $siteId, int $sellableId, int $locationId): array
    {
        $item = $this->rawDatabase()->one(
            'SELECT * FROM sale_inventory_items WHERE site_id=? AND sellable_id=? AND stock_location_id=?',
            [$siteId, $sellableId, $locationId]
        );
        if ($item === null || (int) $item['tracked'] === 0) {
            return ['tracked' => false, 'on_hand_quantity' => null, 'reserved_quantity' => null, 'available_quantity' => null, 'status' => 'not_tracked'];
        }
        return $item + ['status' => (int) $item['available_quantity'] > 0 ? 'available' : 'unavailable'];
    }

    /** @return array{from:array<string,mixed>,to:array<string,mixed>} */
    public function transfer(int $siteId, int $businessVariantId, int $quantity, int $fromLocationId, int $toLocationId, string $transferKey, ?int $actorId = null): array
    {
        if ($quantity < 1 || $fromLocationId === $toLocationId || trim($transferKey) === '') {
            throw new SaleInventoryException('sale.stock_transfer_invalid');
        }
        return $this->rawDatabase()->transaction(function () use ($siteId, $businessVariantId, $quantity, $fromLocationId, $toLocationId, $transferKey, $actorId): array {
            $normalizedKey = strtolower(trim($transferKey));
            $already = $this->rawDatabase()->one("SELECT 1 FROM sale_stock_movements WHERE idempotency_key=?", ['transfer:out:' . $normalizedKey]);
            $from = $this->rawDatabase()->one('SELECT * FROM sale_inventory_items WHERE site_id=? AND business_variant_id=? AND stock_location_id=?', [$siteId, $businessVariantId, $fromLocationId]);
            if ($from === null) {
                throw new SaleInventoryException('sale.stock_item_not_found');
            }
            $to = $this->ensureItem($siteId, ['business_variant_id' => $businessVariantId, 'sellable_id' => $from['sellable_id'], 'sku' => $from['sku'], 'track_stock' => true, 'stock_location_id' => $toLocationId, 'metadata' => ['available_quantity' => 0]]);
            if ($already !== null) {
                return ['from' => $from, 'to' => $to];
            }
            $out = $this->rawDatabase()->pdo()->prepare('UPDATE sale_inventory_items SET on_hand_quantity=on_hand_quantity-?,available_quantity=available_quantity-?,version=version+1,updated_at=CURRENT_TIMESTAMP WHERE id=? AND available_quantity>=?');
            $out->execute([$quantity, $quantity, (int) $from['id'], $quantity]);
            if ($out->rowCount() !== 1) {
                throw new SaleInventoryException('sale.stock_insufficient');
            }
            $this->rawDatabase()->run('UPDATE sale_inventory_items SET on_hand_quantity=on_hand_quantity+?,available_quantity=available_quantity+?,version=version+1,updated_at=CURRENT_TIMESTAMP WHERE id=?', [$quantity, $quantity, (int) $to['id']]);
            $this->movement((int) $from['id'], 'transfer_out', -$quantity, 'transfer', null, 'stock transfer', $actorId, 'transfer:out:' . $normalizedKey, $normalizedKey);
            $this->movement((int) $to['id'], 'transfer_in', $quantity, 'transfer', null, 'stock transfer', $actorId, 'transfer:in:' . $normalizedKey, $normalizedKey);
            return ['from' => $this->rawDatabase()->one('SELECT * FROM sale_inventory_items WHERE id=?', [(int) $from['id']]) ?? [], 'to' => $this->rawDatabase()->one('SELECT * FROM sale_inventory_items WHERE id=?', [(int) $to['id']]) ?? []];
        });
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    public function ensureItem(int $siteId, array $snapshot): array
    {
        $locationId = isset($snapshot['stock_location_id']) ? (int) $snapshot['stock_location_id'] : $this->defaultLocationId($siteId);
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
                site_id, business_variant_id, sellable_id, stock_location_id, sku, tracked,
                on_hand_quantity, reserved_quantity, available_quantity
             ) VALUES(?, ?, ?, ?, ?, ?, ?, 0, ?)',
            [
                $siteId,
                $variantId,
                (int) ($snapshot['sellable_id'] ?? $variantId),
                $locationId,
                $snapshot['sku'] ?? null,
                (int) (bool) ($snapshot['track_stock'] ?? false),
                $onHand,
                $onHand,
            ]
        );
        $itemId = (int) $this->rawDatabase()->lastInsertId();
        if ($onHand !== 0) {
            $this->movement($itemId, 'initial', $onHand, 'catalog_bootstrap', $variantId, 'initial inventory bootstrap', null, 'initial:item:' . $itemId);
        }
        return $this->rawDatabase()->one('SELECT * FROM sale_inventory_items WHERE id = ?', [$itemId]) ?? [];
    }

    public function reserveForCart(int $siteId, int $cartId, array $snapshot, int $quantity, int $ttlSeconds = 1800): ?array
    {
        if (!((bool) ($snapshot['track_stock'] ?? false))) {
            return null;
        }
        $item = $this->ensureItem($siteId, $snapshot);
        $itemId = (int) $item['id'];
        $available = max(0, (int) $item['available_quantity']);
        $allowBackorder = (bool) ($snapshot['allow_backorder'] ?? $snapshot['metadata']['allow_backorder'] ?? false);
        if ($available < $quantity && $allowBackorder) {
            $quantity = $available;
        }
        if ($quantity < 1) {
            return null;
        }
        $sellableId = (int) ($snapshot['sellable_id'] ?? $snapshot['business_variant_id']);
        $reservationKey = 'cart:' . $cartId . ':sellable:' . $sellableId . ':location:' . (int) $item['stock_location_id'];
        $expiresAt = gmdate('Y-m-d H:i:s', time() + max(60, $ttlSeconds));
        return $this->rawDatabase()->transaction(function () use ($itemId, $cartId, $quantity, $reservationKey, $expiresAt): ?array {
            $existing = $this->rawDatabase()->one(
                "SELECT * FROM sale_stock_reservations WHERE inventory_item_id=? AND reservation_key=? AND status IN ('active','confirmed') LIMIT 1",
                [$itemId, $reservationKey]
            );
            if ($existing !== null) {
                if ((int) $existing['quantity'] !== $quantity) {
                    throw new SaleInventoryException('sale.stock_reservation_quantity_conflict');
                }
                $existing['_replayed'] = true;
                return $existing;
            }
            $stmt = $this->rawDatabase()->pdo()->prepare(
                'UPDATE sale_inventory_items
                 SET reserved_quantity=reserved_quantity+?,available_quantity=available_quantity-?,version=version+1,updated_at=CURRENT_TIMESTAMP
                 WHERE id=? AND (tracked=0 OR allow_negative=1 OR available_quantity>=?)'
            );
            $stmt->execute([$quantity, $quantity, $itemId, $quantity]);
            if ($stmt->rowCount() !== 1) {
                throw new SaleInventoryException('sale.stock_insufficient');
            }
            $this->rawDatabase()->run(
                'INSERT INTO sale_stock_reservations(inventory_item_id,cart_id,reservation_key,quantity,expires_at) VALUES(?,?,?,?,?)',
                [$itemId, $cartId, $reservationKey, $quantity, $expiresAt]
            );
            $reservationId = (int) $this->rawDatabase()->lastInsertId();
            $this->movement($itemId, 'reservation', $quantity, 'cart', $cartId, 'checkout reservation', null, 'reserve:' . $reservationKey);
            $created = $this->rawDatabase()->one('SELECT * FROM sale_stock_reservations WHERE id=?', [$reservationId]);
            if ($created !== null) $created['_replayed'] = false;
            return $created;
        });
    }

    public function syncCartVariantReservation(int $siteId, int $cartId, int $businessVariantId, int $targetQuantity, bool $allowBackorder = false): void
    {
        $item = $this->rawDatabase()->one(
            'SELECT * FROM sale_inventory_items WHERE site_id = ? AND business_variant_id = ? LIMIT 1',
            [$siteId, $businessVariantId]
        );
        if ($item === null || (int) $item['tracked'] !== 1) {
            return;
        }

        $current = (int) ($this->rawDatabase()->one(
            'SELECT COALESCE(SUM(r.quantity), 0) AS total
             FROM sale_stock_reservations r
             WHERE r.cart_id = ? AND r.inventory_item_id = ? AND r.status = \'active\'',
            [$cartId, (int) $item['id']]
        )['total'] ?? 0);
        $targetQuantity = max(0, $targetQuantity);
        if ($targetQuantity === $current) {
            return;
        }
        if ($targetQuantity > $current) {
            $delta = $targetQuantity - $current;
            $fresh = $this->rawDatabase()->one('SELECT * FROM sale_inventory_items WHERE id = ?', [(int) $item['id']]) ?? $item;
            $available = max(0, (int) $fresh['available_quantity']);
            if ($available < $delta && !$allowBackorder) {
                throw new SaleInventoryException('sale.stock_insufficient');
            }
            $delta = min($delta, $available);
            if ($delta > 0) {
                $this->addReservationToItem((int) $fresh['id'], $cartId, $businessVariantId, $delta, 'cart line quantity increase');
            }
            return;
        }

        $this->releaseCartVariantQuantity($cartId, (int) $item['id'], $current - $targetQuantity, 'cart line quantity decrease');
    }

    public function consumeCartReservations(int $cartId, int $orderId): void
    {
        $this->rawDatabase()->transaction(function () use ($cartId, $orderId): void {
            $rows = $this->rawDatabase()->all("SELECT * FROM sale_stock_reservations WHERE cart_id=? AND status IN ('active','confirmed')", [$cartId]);
            foreach ($rows as $reservation) {
                $quantity = (int) $reservation['quantity'];
                $itemId = (int) $reservation['inventory_item_id'];
                $claim = $this->rawDatabase()->pdo()->prepare(
                    "UPDATE sale_stock_reservations SET status='consumed',order_id=?,consumed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ('active','confirmed')"
                );
                $claim->execute([$orderId, (int) $reservation['id']]);
                if ($claim->rowCount() !== 1) {
                    continue;
                }
                $stock = $this->rawDatabase()->pdo()->prepare(
                    'UPDATE sale_inventory_items
                     SET on_hand_quantity=on_hand_quantity-?,reserved_quantity=reserved_quantity-?,version=version+1,updated_at=CURRENT_TIMESTAMP
                     WHERE id=? AND reserved_quantity>=? AND (allow_negative=1 OR on_hand_quantity>=?)'
                );
                $stock->execute([$quantity, $quantity, $itemId, $quantity, $quantity]);
                if ($stock->rowCount() !== 1) {
                    throw new SaleInventoryException('sale.stock_consumption_conflict');
                }
                $this->movement($itemId, 'consumption', -$quantity, 'order', $orderId, 'checkout consumption', null, 'consume:reservation:' . (int) $reservation['id']);
            }
        });
    }

    public function confirmCartReservations(int $cartId): int
    {
        $stmt = $this->rawDatabase()->pdo()->prepare(
            "UPDATE sale_stock_reservations SET status='confirmed',confirmed_at=COALESCE(confirmed_at,CURRENT_TIMESTAMP),updated_at=CURRENT_TIMESTAMP WHERE cart_id=? AND status='active'"
        );
        $stmt->execute([$cartId]);
        return $stmt->rowCount();
    }

    public function holdCartReservationsForOrder(int $cartId, int $orderId, string $expiresAt): int
    {
        $stmt = $this->rawDatabase()->pdo()->prepare(
            "UPDATE sale_stock_reservations
             SET status='confirmed',order_id=?,expires_at=?,confirmed_at=COALESCE(confirmed_at,CURRENT_TIMESTAMP),updated_at=CURRENT_TIMESTAMP
             WHERE cart_id=? AND status IN ('active','confirmed')"
        );
        $stmt->execute([$orderId, $expiresAt, $cartId]);
        return $stmt->rowCount();
    }

    public function releaseCartReservations(int $cartId, ?string $reason = null): void
    {
        foreach ($this->rawDatabase()->all("SELECT * FROM sale_stock_reservations WHERE cart_id=? AND status IN ('active','confirmed')", [$cartId]) as $reservation) {
            $this->releaseReservation($reservation, 'released', $reason ?? 'reservation release');
        }
    }

    public function releaseCartVariantReservations(int $cartId, int $businessVariantId, ?string $reason = null): void
    {
        $rows = $this->rawDatabase()->all(
            "SELECT r.*
             FROM sale_stock_reservations r
             INNER JOIN sale_inventory_items i ON i.id = r.inventory_item_id
             WHERE r.cart_id = ? AND i.business_variant_id = ? AND r.status IN ('active','confirmed')",
            [$cartId, $businessVariantId]
        );
        foreach ($rows as $reservation) {
            $this->releaseReservation($reservation, 'released', $reason ?? 'cart line deleted');
        }
    }

    public function expireDueReservations(?int $siteId = null): int
    {
        $params = [gmdate('Y-m-d H:i:s')];
        $siteSql = '';
        if ($siteId !== null) {
            $siteSql = ' AND i.site_id = ?';
            $params[] = $siteId;
        }
        $rows = $this->rawDatabase()->all(
            "SELECT r.*
             FROM sale_stock_reservations r
             INNER JOIN sale_inventory_items i ON i.id = r.inventory_item_id
             WHERE r.status IN ('active','confirmed') AND r.expires_at IS NOT NULL AND r.expires_at <= ?" . $siteSql,
            $params
        );
        foreach ($rows as $reservation) {
            $this->releaseReservation($reservation, 'expired', 'reservation expired');
        }
        return count($rows);
    }

    public function restockReturn(int $siteId, int $businessVariantId, int $quantity, ?string $sku = null, ?int $returnId = null, ?string $reason = null, ?int $actorId = null): array
    {
        if ($quantity < 1) {
            throw new SaleInventoryException('sale.return_quantity_invalid');
        }
        $item = $this->ensureTrackedVariantItem($siteId, $businessVariantId, $sku);
        $idempotencyKey = $returnId === null ? null : 'return:' . $returnId . ':sellable:' . $businessVariantId;
        return $this->rawDatabase()->transaction(function () use ($item, $quantity, $returnId, $reason, $actorId, $idempotencyKey): array {
            if ($idempotencyKey !== null && $this->rawDatabase()->one('SELECT id FROM sale_stock_movements WHERE idempotency_key=?', [$idempotencyKey]) !== null) {
                return $this->rawDatabase()->one('SELECT * FROM sale_inventory_items WHERE id=?', [(int) $item['id']]) ?? $item;
            }
            $this->rawDatabase()->run(
                'UPDATE sale_inventory_items SET on_hand_quantity=on_hand_quantity+?,available_quantity=available_quantity+?,version=version+1,updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [$quantity, $quantity, (int) $item['id']]
            );
            $this->movement((int) $item['id'], 'return', $quantity, 'return', $returnId, $reason ?? 'return restock', $actorId, $idempotencyKey);
            return $this->rawDatabase()->one('SELECT * FROM sale_inventory_items WHERE id=?', [(int) $item['id']]) ?? [];
        });
    }

    private function defaultLocationId(int $siteId): int
    {
        $row = $this->rawDatabase()->one(
            "SELECT id FROM sale_stock_locations WHERE site_id=? AND status='active' ORDER BY code='channel-default' DESC,location_type='main' DESC,id ASC LIMIT 1",
            [$siteId]
        );
        if ($row !== null) {
            return (int) $row['id'];
        }
        $this->rawDatabase()->run(
            'INSERT INTO sale_stock_locations(site_id, code, name, location_type, status)
             VALUES(?, \'main\', \'Stock principal\', \'main\', \'active\')',
            [$siteId]
        );
        return (int) $this->rawDatabase()->lastInsertId();
    }

    private function addReservationToItem(int $itemId, int $cartId, int $businessVariantId, int $quantity, string $reason): void
    {
        $reservationKey = 'cart:' . $cartId . ':variant:' . $businessVariantId . ':' . bin2hex(random_bytes(3));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + 1800);
        $this->rawDatabase()->run(
            'UPDATE sale_inventory_items
             SET reserved_quantity = reserved_quantity + ?,
                 available_quantity = on_hand_quantity - (reserved_quantity + ?),
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?',
            [$quantity, $quantity, $itemId]
        );
        $this->rawDatabase()->run(
            'INSERT INTO sale_stock_reservations(inventory_item_id, cart_id, reservation_key, quantity, expires_at)
             VALUES(?, ?, ?, ?, ?)',
            [$itemId, $cartId, $reservationKey, $quantity, $expiresAt]
        );
        $this->movement($itemId, 'reservation', $quantity, 'cart', $cartId, $reason);
    }

    private function releaseCartVariantQuantity(int $cartId, int $itemId, int $quantityToRelease, string $reason): void
    {
        foreach ($this->rawDatabase()->all('SELECT * FROM sale_stock_reservations WHERE cart_id = ? AND inventory_item_id = ? AND status = \'active\' ORDER BY id DESC', [$cartId, $itemId]) as $reservation) {
            if ($quantityToRelease <= 0) {
                return;
            }
            $quantity = (int) $reservation['quantity'];
            if ($quantity <= $quantityToRelease) {
                $this->releaseReservation($reservation, 'released', $reason);
                $quantityToRelease -= $quantity;
                continue;
            }
            $remaining = $quantity - $quantityToRelease;
            $this->rawDatabase()->run(
                'UPDATE sale_stock_reservations SET quantity = ? WHERE id = ?',
                [$remaining, (int) $reservation['id']]
            );
            $this->rawDatabase()->run(
                'UPDATE sale_inventory_items
                 SET reserved_quantity = reserved_quantity - ?,
                     available_quantity = on_hand_quantity - (reserved_quantity - ?),
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?',
                [$quantityToRelease, $quantityToRelease, $itemId]
            );
            $this->movement($itemId, 'release', -$quantityToRelease, 'cart', $cartId, $reason);
            return;
        }
    }

    /** @param array<string,mixed> $reservation */
    private function releaseReservation(array $reservation, string $status, string $reason): void
    {
        $this->rawDatabase()->transaction(function () use ($reservation, $status, $reason): void {
            $quantity = (int) $reservation['quantity'];
            $itemId = (int) $reservation['inventory_item_id'];
            $claim = $this->rawDatabase()->pdo()->prepare(
                "UPDATE sale_stock_reservations SET status=?,released_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ('active','confirmed')"
            );
            $claim->execute([$status, (int) $reservation['id']]);
            if ($claim->rowCount() !== 1) {
                return;
            }
            $stock = $this->rawDatabase()->pdo()->prepare(
                'UPDATE sale_inventory_items SET reserved_quantity=reserved_quantity-?,available_quantity=available_quantity+?,version=version+1,updated_at=CURRENT_TIMESTAMP WHERE id=? AND reserved_quantity>=?'
            );
            $stock->execute([$quantity, $quantity, $itemId, $quantity]);
            if ($stock->rowCount() !== 1) {
                throw new SaleInventoryException('sale.stock_release_conflict');
            }
            $referenceId = isset($reservation['cart_id']) && $reservation['cart_id'] !== null ? (int) $reservation['cart_id'] : null;
            $this->movement($itemId, 'release', -$quantity, $referenceId === null ? null : 'cart', $referenceId, $reason, null, 'release:reservation:' . (int) $reservation['id']);
        });
    }

    /** @return array<string,mixed> */
    private function ensureTrackedVariantItem(int $siteId, int $businessVariantId, ?string $sku): array
    {
        $existing = $this->rawDatabase()->one(
            'SELECT * FROM sale_inventory_items WHERE site_id=? AND business_variant_id=? ORDER BY id ASC LIMIT 1',
            [$siteId, $businessVariantId]
        );
        if ($existing !== null) {
            return $existing;
        }
        $locationId = $this->defaultLocationId($siteId);
        $row = $this->rawDatabase()->one(
            'SELECT * FROM sale_inventory_items WHERE site_id = ? AND business_variant_id = ? AND stock_location_id = ? LIMIT 1',
            [$siteId, $businessVariantId, $locationId]
        );
        if ($row !== null) {
            return $row;
        }
        $this->rawDatabase()->run(
            'INSERT INTO sale_inventory_items(site_id, business_variant_id, sellable_id, stock_location_id, sku, tracked, on_hand_quantity, reserved_quantity, available_quantity)
             VALUES(?, ?, ?, ?, ?, 1, 0, 0, 0)',
            [$siteId, $businessVariantId, $businessVariantId, $locationId, $sku]
        );
        return $this->rawDatabase()->one('SELECT * FROM sale_inventory_items WHERE id = ?', [(int) $this->rawDatabase()->lastInsertId()]) ?? [];
    }

    private function movement(int $itemId, string $type, int $quantity, ?string $referenceType, ?int $referenceId, string $reason, ?int $actorId = null, ?string $idempotencyKey = null, ?string $transferKey = null): void
    {
        $this->rawDatabase()->run(
            'INSERT OR IGNORE INTO sale_stock_movements(inventory_item_id, movement_type, quantity, idempotency_key, transfer_key, reference_type, reference_id, reason, created_by_iam_user_id)
             VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$itemId, $type, $quantity, $idempotencyKey, $transferKey, $referenceType, $referenceId, $reason, $actorId]
        );
    }
}
