<?php

declare(strict_types=1);

namespace App\Modules\Sale\Repositories;

use App\Modules\Sale\Exceptions\SaleInventoryException;

final class SaleInventoryRepository extends SaleRepositoryBase
{
    /** @return array{items:list<array<string,mixed>>,limit:int,offset:int,total:int,has_more:bool} */
    public function listItems(int $siteId, int $limit = 50, int $offset = 0, array $filters = []): array
    {
        $where = ['i.site_id=:site_id'];
        $params = ['site_id' => $siteId];
        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $where[] = '(i.sku LIKE :query OR r.barcode LIKE :query OR r.product_name LIKE :query OR r.variant_name LIKE :query OR l.code LIKE :query OR l.name LIKE :query)';
            $params['query'] = '%' . $query . '%';
        }
        if ((int) ($filters['location_id'] ?? 0) > 0) {
            $where[] = 'i.stock_location_id=:location_id';
            $params['location_id'] = (int) $filters['location_id'];
        }
        $alert = trim((string) ($filters['alert'] ?? ''));
        if ($alert === 'out_of_stock') $where[] = 'i.tracked=1 AND i.available_quantity<=0';
        if ($alert === 'low_stock') $where[] = 'i.tracked=1 AND i.available_quantity>0 AND i.available_quantity<=i.low_stock_threshold';
        if ($alert === 'inconsistent') {
            $where[] = '(i.on_hand_quantity<>(SELECT COALESCE(SUM(m.quantity),0) FROM sale_stock_movements m WHERE m.inventory_item_id=i.id AND m.movement_type NOT IN (\'reservation\',\'release\')) OR i.reserved_quantity<>(SELECT COALESCE(SUM(sr.quantity),0) FROM sale_stock_reservations sr WHERE sr.inventory_item_id=i.id AND sr.status IN (\'active\',\'confirmed\')))';
        }
        $sqlWhere = implode(' AND ', $where);
        $joins = ' FROM sale_inventory_items i INNER JOIN sale_stock_locations l ON l.id=i.stock_location_id LEFT JOIN sale_catalog_variant_refs r ON r.site_id=i.site_id AND r.sellable_id=i.sellable_id ';
        $total = (int) ($this->rawDatabase()->one('SELECT COUNT(*) AS count' . $joins . 'WHERE ' . $sqlWhere, $params)['count'] ?? 0);
        $items = $this->rawDatabase()->all(
            'SELECT i.*,l.code AS location_code,l.name AS location_name,l.location_type,r.product_name,r.variant_name,r.barcode,
                    (SELECT COALESCE(SUM(m.quantity),0) FROM sale_stock_movements m WHERE m.inventory_item_id=i.id AND m.movement_type NOT IN (\'reservation\',\'release\')) AS ledger_on_hand_quantity,
                    (SELECT COALESCE(SUM(sr.quantity),0) FROM sale_stock_reservations sr WHERE sr.inventory_item_id=i.id AND sr.status IN (\'active\',\'confirmed\')) AS ledger_reserved_quantity'
            . $joins . 'WHERE ' . $sqlWhere . '
             ORDER BY CASE WHEN i.tracked=1 AND i.available_quantity<=0 THEN 0 WHEN i.tracked=1 AND i.available_quantity<=i.low_stock_threshold THEN 1 ELSE 2 END,i.updated_at DESC,i.id DESC
             LIMIT :limit OFFSET :offset',
            $params + ['limit' => max(1, min(100, $limit)), 'offset' => max(0, $offset)]
        );
        foreach ($items as &$item) {
            $item['inconsistent'] = (int) $item['on_hand_quantity'] !== (int) $item['ledger_on_hand_quantity'] || (int) $item['reserved_quantity'] !== (int) $item['ledger_reserved_quantity'];
            $item['low_stock'] = (int) $item['tracked'] === 1 && (int) $item['available_quantity'] > 0 && (int) $item['available_quantity'] <= (int) $item['low_stock_threshold'];
            $item['last_available'] = (int) $item['tracked'] === 1 && (int) $item['available_quantity'] === 1;
            $item['availability_status'] = $this->publicAvailabilityStatus($item);
        }
        unset($item);
        return ['items' => $items, 'limit' => $limit, 'offset' => $offset, 'total' => $total, 'has_more' => ($offset + $limit) < $total];
    }

    /** @return array{items:list<array<string,mixed>>,limit:int,offset:int,total:int,has_more:bool} */
    public function movements(int $siteId, int $limit = 50, int $offset = 0, array $filters = []): array
    {
        $params = ['site_id' => $siteId];
        $where = ['i.site_id=:site_id'];
        foreach (['inventory_item_id', 'stock_location_id', 'reference_id'] as $key) {
            if ((int) ($filters[$key] ?? 0) > 0) {
                $where[] = 'm.' . $key . '=:' . $key;
                $params[$key] = (int) $filters[$key];
            }
        }
        foreach (['movement_type', 'reference_type', 'correlation_id'] as $key) {
            if (trim((string) ($filters[$key] ?? '')) !== '') {
                $where[] = 'm.' . $key . '=:' . $key;
                $params[$key] = trim((string) $filters[$key]);
            }
        }
        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $where[] = '(i.sku LIKE :query OR r.barcode LIKE :query OR r.product_name LIKE :query OR l.code LIKE :query OR m.reason LIKE :query OR m.correlation_id LIKE :query)';
            $params['query'] = '%' . $query . '%';
        }
        $sqlWhere = implode(' AND ', $where);
        $joins = ' FROM sale_stock_movements m INNER JOIN sale_inventory_items i ON i.id=m.inventory_item_id INNER JOIN sale_stock_locations l ON l.id=m.stock_location_id LEFT JOIN sale_catalog_variant_refs r ON r.site_id=i.site_id AND r.sellable_id=i.sellable_id ';
        $total = (int) ($this->rawDatabase()->one(
            'SELECT COUNT(*) AS count' . $joins . 'WHERE ' . $sqlWhere,
            $params
        )['count'] ?? 0);
        $items = $this->rawDatabase()->all(
            'SELECT m.*,i.business_variant_id,i.sellable_id,i.sku,l.code AS location_code,l.name AS location_name,r.product_name,r.variant_name,r.barcode'
            . $joins . 'WHERE ' . $sqlWhere . '
             ORDER BY m.created_at DESC, m.id DESC
             LIMIT :limit OFFSET :offset',
            $params + ['limit' => max(1, min(100, $limit)), 'offset' => max(0, $offset)]
        );
        return ['items' => $items, 'limit' => $limit, 'offset' => $offset, 'total' => $total, 'has_more' => ($offset + $limit) < $total];
    }

    public function adjust(int $siteId, int $businessVariantId, int $quantityDelta, ?string $sku = null, ?string $reason = null, ?int $actorId = null, ?int $locationId = null, string $movementType = 'adjustment', ?string $idempotencyKey = null): array
    {
        if ($businessVariantId < 1) {
            throw new SaleInventoryException('sale.stock_item_required');
        }
        if ($quantityDelta === 0) {
            throw new SaleInventoryException('sale.stock_adjustment_zero');
        }
        if (trim((string) $reason) === '') {
            throw new SaleInventoryException('sale.stock_reason_required');
        }
        if (!in_array($movementType, ['receipt', 'issue', 'adjustment', 'correction'], true)) {
            throw new SaleInventoryException('sale.stock_movement_type_invalid');
        }
        $locationId ??= $this->defaultLocationId($siteId);
        if ($this->rawDatabase()->one("SELECT id FROM sale_stock_locations WHERE id=? AND site_id=? AND status='active'", [$locationId, $siteId]) === null) {
            throw new SaleInventoryException('sale.stock_location_invalid');
        }
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
            return ['tracked' => false, 'on_hand_quantity' => null, 'reserved_quantity' => null, 'available_quantity' => null, 'status' => 'deliverable', 'contract' => 'sale.inventory.availability.v1', 'last_available' => false];
        }
        return $item + ['status' => $this->publicAvailabilityStatus($item), 'contract' => 'sale.inventory.availability.v1', 'last_available' => (int) $item['available_quantity'] === 1];
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
                allow_backorder, backorder_delivery_days, low_stock_threshold,
                on_hand_quantity, reserved_quantity, available_quantity
             ) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)',
            [
                $siteId,
                $variantId,
                (int) ($snapshot['sellable_id'] ?? $variantId),
                $locationId,
                $snapshot['sku'] ?? null,
                (int) (bool) ($snapshot['track_stock'] ?? false),
                (int) (bool) ($snapshot['allow_backorder'] ?? $snapshot['metadata']['allow_backorder'] ?? false),
                max(1, (int) ($snapshot['backorder_delivery_days'] ?? $snapshot['metadata']['backorder_delivery_days'] ?? 7)),
                max(0, (int) ($snapshot['low_stock_threshold'] ?? 2)),
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

    /** @param array<string,mixed> $cart @return array<string,mixed> */
    public function reservationPolicyForCart(array $cart): array
    {
        $kind = (string) ($cart['cart_kind'] ?? 'admin');
        $defaults = [
            'reservation_policy' => $kind === 'web' ? 'checkout_start' : 'order_placement',
            'reservation_ttl_seconds' => $kind === 'pos' ? 300 : 1800,
            'reservation_renewal_window_seconds' => 300,
            'reservation_max_lifetime_seconds' => 7200,
            'backorder_policy' => $kind === 'pos' ? 'disabled' : 'sellable',
            'show_exact_quantity' => 0,
        ];
        $configured = $this->rawDatabase()->one(
            "SELECT * FROM sale_inventory_channel_configs WHERE channel_id=? AND site_id=? AND status='active'",
            [(int) ($cart['channel_id'] ?? 0), (int) ($cart['site_id'] ?? 0)]
        ) ?? [];
        $shipping = json_decode((string) ($cart['shipping_method_snapshot_json'] ?? '{}'), true);
        $fulfillmentMode = $kind === 'pos' ? 'pos' : ($kind === 'admin' ? 'admin' : 'delivery');
        if (is_array($shipping) && in_array((string) ($shipping['type'] ?? $shipping['code'] ?? ''), ['pickup', 'click_collect', 'collect'], true)) {
            $fulfillmentMode = 'pickup';
        }
        $policy = $configured + $defaults;
        $policy['fulfillment_mode'] = $fulfillmentMode;
        if (in_array($fulfillmentMode, ['pos', 'pickup'], true)) {
            $policy['backorder_policy'] = 'disabled';
        }
        return $policy;
    }

    /** @param array<string,mixed> $snapshot @param array<string,mixed> $policy @return array<string,mixed>|null */
    public function reserveForCart(int $siteId, int $cartId, array $snapshot, int $quantity, int $ttlSeconds = 1800, array $policy = []): ?array
    {
        if (!((bool) ($snapshot['track_stock'] ?? false))) {
            return null;
        }
        if ($quantity < 1) {
            throw new SaleInventoryException('sale.stock_reservation_quantity_invalid');
        }
        $item = $this->ensureItem($siteId, $snapshot);
        $itemId = (int) $item['id'];
        $backorderPolicy = (string) ($policy['backorder_policy'] ?? 'sellable');
        $sellableBackorder = (bool) ($snapshot['allow_backorder'] ?? $snapshot['metadata']['allow_backorder'] ?? false);
        $allowBackorder = $backorderPolicy === 'enabled' || ($backorderPolicy === 'sellable' && $sellableBackorder);
        $sellableId = (int) ($snapshot['sellable_id'] ?? $snapshot['business_variant_id']);
        $reservationKey = 'cart:' . $cartId . ':sellable:' . $sellableId . ':location:' . (int) $item['stock_location_id'];
        $trigger = (string) ($policy['reservation_policy'] ?? 'checkout_start');
        $fulfillmentMode = (string) ($policy['fulfillment_mode'] ?? 'delivery');
        $ttlSeconds = max(60, min(86400, (int) ($policy['reservation_ttl_seconds'] ?? $ttlSeconds)));
        $maxLifetime = max($ttlSeconds, min(604800, (int) ($policy['reservation_max_lifetime_seconds'] ?? 7200)));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);
        $maxExpiresAt = gmdate('Y-m-d H:i:s', time() + $maxLifetime);
        $deliveryDays = max(1, (int) ($snapshot['backorder_delivery_days'] ?? $snapshot['metadata']['backorder_delivery_days'] ?? 7));
        return $this->rawDatabase()->transaction(function () use ($itemId, $cartId, $quantity, $reservationKey, $expiresAt, $maxExpiresAt, $trigger, $fulfillmentMode, $allowBackorder, $deliveryDays, $sellableId): ?array {
            $existing = $this->rawDatabase()->one(
                "SELECT * FROM sale_stock_reservations WHERE inventory_item_id=? AND reservation_key=? AND status IN ('active','confirmed') LIMIT 1",
                [$itemId, $reservationKey]
            );
            $existingBackorder = $this->rawDatabase()->one(
                "SELECT * FROM sale_stock_backorders WHERE inventory_item_id=? AND backorder_key=? AND status IN ('active','confirmed') LIMIT 1",
                [$itemId, $reservationKey]
            );
            if ($existing !== null || $existingBackorder !== null) {
                $held = (int) ($existing['quantity'] ?? 0) + (int) ($existingBackorder['quantity'] ?? 0);
                if ($held !== $quantity) {
                    throw new SaleInventoryException('sale.stock_reservation_quantity_conflict');
                }
                $result = $existing ?? $existingBackorder ?? [];
                $result['_replayed'] = true;
                $result['_backorder_quantity'] = (int) ($existingBackorder['quantity'] ?? 0);
                $result['_reservation_kind'] = $existing === null ? 'backorder' : 'physical';
                return $result;
            }
            $fresh = $this->rawDatabase()->one('SELECT * FROM sale_inventory_items WHERE id=?', [$itemId]);
            $available = max(0, (int) ($fresh['available_quantity'] ?? 0));
            if ($available < $quantity && !$allowBackorder) {
                throw new SaleInventoryException('sale.stock_insufficient', [
                    'sellable_id' => $sellableId,
                    'requested_quantity' => $quantity,
                    'available_quantity' => $available,
                    'recovery_options' => ['reduce_quantity', 'choose_variant', 'remove_line'],
                ]);
            }
            $physicalQuantity = min($quantity, $available);
            $backorderQuantity = $quantity - $physicalQuantity;
            $created = null;
            if ($physicalQuantity > 0) {
                $stmt = $this->rawDatabase()->pdo()->prepare(
                    'UPDATE sale_inventory_items
                     SET reserved_quantity=reserved_quantity+?,available_quantity=available_quantity-?,version=version+1,updated_at=CURRENT_TIMESTAMP
                     WHERE id=? AND (tracked=0 OR allow_negative=1 OR available_quantity>=?)'
                );
                $stmt->execute([$physicalQuantity, $physicalQuantity, $itemId, $physicalQuantity]);
                if ($stmt->rowCount() !== 1) {
                    throw new SaleInventoryException('sale.stock_insufficient', ['sellable_id' => $sellableId, 'requested_quantity' => $quantity, 'recovery_options' => ['reduce_quantity', 'choose_variant', 'remove_line']]);
                }
                $this->rawDatabase()->run(
                    'INSERT INTO sale_stock_reservations(inventory_item_id,cart_id,reservation_key,quantity,expires_at,max_expires_at,reservation_trigger,fulfillment_mode) VALUES(?,?,?,?,?,?,?,?)',
                    [$itemId, $cartId, $reservationKey, $physicalQuantity, $expiresAt, $maxExpiresAt, $trigger, $fulfillmentMode]
                );
                $reservationId = (int) $this->rawDatabase()->lastInsertId();
                $this->movement($itemId, 'reservation', $physicalQuantity, 'cart', $cartId, 'checkout reservation', null, 'reserve:' . $reservationKey);
                $created = $this->rawDatabase()->one('SELECT * FROM sale_stock_reservations WHERE id=?', [$reservationId]);
            }
            if ($backorderQuantity > 0) {
                $this->rawDatabase()->run(
                    'INSERT INTO sale_stock_backorders(inventory_item_id,cart_id,backorder_key,quantity,delivery_lead_time_days,reservation_trigger,fulfillment_mode,expires_at,max_expires_at) VALUES(?,?,?,?,?,?,?,?,?)',
                    [$itemId, $cartId, $reservationKey, $backorderQuantity, $deliveryDays, $trigger, $fulfillmentMode, $expiresAt, $maxExpiresAt]
                );
                $backorder = $this->rawDatabase()->one('SELECT * FROM sale_stock_backorders WHERE id=?', [(int) $this->rawDatabase()->lastInsertId()]) ?? [];
                if ($created === null) $created = $backorder;
            }
            if ($created !== null) {
                $created['_replayed'] = false;
                $created['_backorder_quantity'] = $backorderQuantity;
                $created['_reservation_kind'] = $physicalQuantity > 0 ? 'physical' : 'backorder';
            }
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
                $this->movement($itemId, 'sale', -$quantity, 'order', $orderId, 'checkout sale', null, 'consume:reservation:' . (int) $reservation['id']);
            }
            $this->rawDatabase()->run(
                "UPDATE sale_stock_backorders
                 SET status='confirmed',order_id=?,confirmed_at=COALESCE(confirmed_at,CURRENT_TIMESTAMP),updated_at=CURRENT_TIMESTAMP
                 WHERE cart_id=? AND status='active'",
                [$orderId, $cartId]
            );
        });
    }

    public function confirmCartReservations(int $cartId): int
    {
        $stmt = $this->rawDatabase()->pdo()->prepare(
            "UPDATE sale_stock_reservations SET status='confirmed',confirmed_at=COALESCE(confirmed_at,CURRENT_TIMESTAMP),updated_at=CURRENT_TIMESTAMP WHERE cart_id=? AND status='active'"
        );
        $stmt->execute([$cartId]);
        $count = $stmt->rowCount();
        $backorders = $this->rawDatabase()->pdo()->prepare(
            "UPDATE sale_stock_backorders SET status='confirmed',confirmed_at=COALESCE(confirmed_at,CURRENT_TIMESTAMP),updated_at=CURRENT_TIMESTAMP WHERE cart_id=? AND status='active'"
        );
        $backorders->execute([$cartId]);
        return $count + $backorders->rowCount();
    }

    public function holdCartReservationsForOrder(int $cartId, int $orderId, string $expiresAt): int
    {
        $stmt = $this->rawDatabase()->pdo()->prepare(
            "UPDATE sale_stock_reservations
             SET status='confirmed',order_id=?,expires_at=MIN(?,COALESCE(max_expires_at,?)),confirmed_at=COALESCE(confirmed_at,CURRENT_TIMESTAMP),updated_at=CURRENT_TIMESTAMP
             WHERE cart_id=? AND status IN ('active','confirmed')"
        );
        $stmt->execute([$orderId, $expiresAt, $expiresAt, $cartId]);
        $count = $stmt->rowCount();
        $backorders = $this->rawDatabase()->pdo()->prepare(
            "UPDATE sale_stock_backorders
             SET status='confirmed',order_id=?,expires_at=MIN(?,COALESCE(max_expires_at,?)),confirmed_at=COALESCE(confirmed_at,CURRENT_TIMESTAMP),updated_at=CURRENT_TIMESTAMP
             WHERE cart_id=? AND status IN ('active','confirmed')"
        );
        $backorders->execute([$orderId, $expiresAt, $expiresAt, $cartId]);
        return $count + $backorders->rowCount();
    }

    public function releaseCartReservations(int $cartId, ?string $reason = null): void
    {
        foreach ($this->rawDatabase()->all("SELECT * FROM sale_stock_reservations WHERE cart_id=? AND status IN ('active','confirmed')", [$cartId]) as $reservation) {
            $this->releaseReservation($reservation, 'released', $reason ?? 'reservation release');
        }
        $this->rawDatabase()->run(
            "UPDATE sale_stock_backorders SET status='released',released_at=CURRENT_TIMESTAMP,release_reason=?,updated_at=CURRENT_TIMESTAMP WHERE cart_id=? AND status IN ('active','confirmed')",
            [$reason ?? 'reservation release', $cartId]
        );
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
        $this->rawDatabase()->run(
            "UPDATE sale_stock_backorders SET status='released',released_at=CURRENT_TIMESTAMP,release_reason=?,updated_at=CURRENT_TIMESTAMP
             WHERE cart_id=? AND inventory_item_id IN (SELECT id FROM sale_inventory_items WHERE business_variant_id=?) AND status IN ('active','confirmed')",
            [$reason ?? 'cart line deleted', $cartId, $businessVariantId]
        );
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
        $backorderParams = [gmdate('Y-m-d H:i:s')];
        $backorderSiteSql = '';
        if ($siteId !== null) {
            $backorderSiteSql = ' AND i.site_id = ?';
            $backorderParams[] = $siteId;
        }
        $backorders = $this->rawDatabase()->all(
            "SELECT b.id FROM sale_stock_backorders b INNER JOIN sale_inventory_items i ON i.id=b.inventory_item_id
             WHERE b.status IN ('active','confirmed') AND b.expires_at IS NOT NULL AND b.expires_at<=?" . $backorderSiteSql,
            $backorderParams
        );
        foreach ($backorders as $backorder) {
            $this->rawDatabase()->run(
                "UPDATE sale_stock_backorders SET status='expired',released_at=CURRENT_TIMESTAMP,release_reason='reservation expired',updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ('active','confirmed')",
                [(int) $backorder['id']]
            );
        }
        return count($rows) + count($backorders);
    }

    public function renewReservation(int $siteId, int $reservationId, string $kind = 'physical', ?int $ttlSeconds = null): array
    {
        $table = $kind === 'backorder' ? 'sale_stock_backorders' : 'sale_stock_reservations';
        $alias = $kind === 'backorder' ? 'b' : 'r';
        return $this->rawDatabase()->transaction(function () use ($siteId, $reservationId, $table, $alias, $kind, $ttlSeconds): array {
            $row = $this->rawDatabase()->one(
                "SELECT {$alias}.*,i.site_id FROM {$table} {$alias} INNER JOIN sale_inventory_items i ON i.id={$alias}.inventory_item_id WHERE {$alias}.id=? AND i.site_id=?",
                [$reservationId, $siteId]
            );
            if ($row === null) throw new SaleInventoryException('sale.stock_reservation_not_found');
            if (!in_array((string) $row['status'], ['active','confirmed'], true)) throw new SaleInventoryException('sale.stock_reservation_not_renewable');
            if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) <= time()) throw new SaleInventoryException('sale.stock_reservation_expired', ['reservation_id' => $reservationId, 'recovery_options' => ['reduce_quantity','choose_variant','remove_line']]);
            $cart = $this->rawDatabase()->one('SELECT * FROM sale_carts WHERE id=?', [(int) $row['cart_id']]) ?? [];
            $policy = $this->reservationPolicyForCart($cart);
            $window = max(30, (int) ($policy['reservation_renewal_window_seconds'] ?? 300));
            if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) > time() + $window) {
                $row['_renewed'] = false;
                return $row;
            }
            $ttl = max(60, min(86400, $ttlSeconds ?? (int) ($policy['reservation_ttl_seconds'] ?? 1800)));
            $target = time() + $ttl;
            if ($row['max_expires_at'] !== null) $target = min($target, strtotime((string) $row['max_expires_at']));
            if ($target <= time()) throw new SaleInventoryException('sale.stock_reservation_max_lifetime');
            $this->rawDatabase()->run(
                "UPDATE {$table} SET expires_at=?,renewed_at=CURRENT_TIMESTAMP,renewal_count=renewal_count+1,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ('active','confirmed')",
                [gmdate('Y-m-d H:i:s', $target), $reservationId]
            );
            $renewed = $this->rawDatabase()->one("SELECT * FROM {$table} WHERE id=?", [$reservationId]) ?? [];
            $renewed['_renewed'] = true;
            $renewed['_reservation_kind'] = $kind;
            return $renewed;
        });
    }

    public function releaseReservationById(int $siteId, int $reservationId, string $kind = 'physical', bool $cancel = false, string $reason = 'manual release', ?int $actorId = null): array
    {
        if ($kind === 'backorder') {
            $row = $this->rawDatabase()->one('SELECT b.*,i.site_id FROM sale_stock_backorders b INNER JOIN sale_inventory_items i ON i.id=b.inventory_item_id WHERE b.id=? AND i.site_id=?', [$reservationId, $siteId]);
            if ($row === null) throw new SaleInventoryException('sale.stock_reservation_not_found');
            $status = $cancel ? 'cancelled' : 'released';
            $this->rawDatabase()->run("UPDATE sale_stock_backorders SET status=?,released_at=CURRENT_TIMESTAMP,release_reason=?,released_by_iam_user_id=?,cancelled_at=CASE WHEN ?='cancelled' THEN CURRENT_TIMESTAMP ELSE cancelled_at END,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ('active','confirmed')", [$status, $reason, $actorId, $status, $reservationId]);
            return $this->rawDatabase()->one('SELECT * FROM sale_stock_backorders WHERE id=?', [$reservationId]) ?? $row;
        }
        $row = $this->rawDatabase()->one('SELECT r.*,i.site_id FROM sale_stock_reservations r INNER JOIN sale_inventory_items i ON i.id=r.inventory_item_id WHERE r.id=? AND i.site_id=?', [$reservationId, $siteId]);
        if ($row === null) throw new SaleInventoryException('sale.stock_reservation_not_found');
        $this->releaseReservation($row, $cancel ? 'cancelled' : 'released', $reason, $actorId);
        return $this->rawDatabase()->one('SELECT * FROM sale_stock_reservations WHERE id=?', [$reservationId]) ?? $row;
    }

    /** @param array<string,mixed> $filters @return array{items:list<array<string,mixed>>,summary:array<string,int>,limit:int,offset:int,total:int,has_more:bool} */
    public function listReservations(int $siteId, array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $where = ['site_id=:site_id'];
        $params = ['site_id' => $siteId];
        foreach (['status', 'reservation_kind', 'fulfillment_mode'] as $key) {
            if (trim((string) ($filters[$key] ?? '')) !== '') {
                $where[] = $key . '=:' . $key;
                $params[$key] = trim((string) $filters[$key]);
            }
        }
        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $where[] = '(product_name LIKE :query OR variant_name LIKE :query OR sku LIKE :query OR order_number LIKE :query OR CAST(cart_id AS TEXT) LIKE :query)';
            $params['query'] = '%' . $query . '%';
        }
        $alert = trim((string) ($filters['alert'] ?? ''));
        if ($alert === 'expiring_soon') $where[] = "status IN ('active','confirmed') AND expires_at IS NOT NULL AND expires_at<=datetime('now','+10 minutes')";
        if ($alert === 'blocked') $where[] = "status IN ('active','confirmed') AND expires_at IS NOT NULL AND expires_at<=CURRENT_TIMESTAMP";
        if ($alert === 'order') $where[] = 'order_id IS NOT NULL';
        $cte = "WITH entries AS (
            SELECT 'physical' AS reservation_kind,r.id,i.site_id,r.inventory_item_id,r.cart_id,r.order_id,r.quantity,r.status,r.expires_at,r.max_expires_at,r.reservation_trigger,r.fulfillment_mode,r.renewal_count,r.created_at,r.updated_at,
                   i.sellable_id,i.sku,ref.product_name,ref.variant_name,l.name AS location_name,o.order_number,0 AS backorder_delivery_days
            FROM sale_stock_reservations r
            INNER JOIN sale_inventory_items i ON i.id=r.inventory_item_id
            INNER JOIN sale_stock_locations l ON l.id=i.stock_location_id
            LEFT JOIN sale_catalog_variant_refs ref ON ref.site_id=i.site_id AND ref.sellable_id=i.sellable_id
            LEFT JOIN sale_orders o ON o.id=r.order_id
            UNION ALL
            SELECT 'backorder',b.id,i.site_id,b.inventory_item_id,b.cart_id,b.order_id,b.quantity,b.status,b.expires_at,b.max_expires_at,b.reservation_trigger,b.fulfillment_mode,b.renewal_count,b.created_at,b.updated_at,
                   i.sellable_id,i.sku,ref.product_name,ref.variant_name,l.name,o.order_number,b.delivery_lead_time_days
            FROM sale_stock_backorders b
            INNER JOIN sale_inventory_items i ON i.id=b.inventory_item_id
            INNER JOIN sale_stock_locations l ON l.id=i.stock_location_id
            LEFT JOIN sale_catalog_variant_refs ref ON ref.site_id=i.site_id AND ref.sellable_id=i.sellable_id
            LEFT JOIN sale_orders o ON o.id=b.order_id
        ) ";
        $sqlWhere = implode(' AND ', $where);
        $total = (int) ($this->rawDatabase()->one($cte . 'SELECT COUNT(*) AS count FROM entries WHERE ' . $sqlWhere, $params)['count'] ?? 0);
        $safeLimit = max(1, min(200, $limit));
        $safeOffset = max(0, $offset);
        $items = $this->rawDatabase()->all(
            $cte . "SELECT *,
                    CASE WHEN expires_at IS NULL THEN NULL ELSE CAST(strftime('%s',expires_at)-strftime('%s','now') AS INTEGER) END AS ttl_seconds,
                    CASE WHEN status IN ('active','confirmed') AND expires_at IS NOT NULL AND expires_at<=CURRENT_TIMESTAMP THEN 1 ELSE 0 END AS blocked,
                    CASE WHEN status IN ('active','confirmed') AND expires_at IS NOT NULL AND expires_at<=datetime('now','+10 minutes') THEN 1 ELSE 0 END AS expiring_soon
             FROM entries WHERE " . $sqlWhere . '
             ORDER BY blocked DESC,expiring_soon DESC,created_at DESC,id DESC LIMIT :limit OFFSET :offset',
            $params + ['limit' => $safeLimit, 'offset' => $safeOffset]
        );
        $all = $this->rawDatabase()->all($cte . 'SELECT status,reservation_kind,expires_at FROM entries WHERE site_id=?', [$siteId]);
        $summary = ['active' => 0, 'expiring_soon' => 0, 'blocked' => 0, 'order_linked' => 0, 'backorders' => 0];
        $now = time();
        foreach ($items as &$item) {
            $item['releasable'] = in_array((string) $item['status'], ['active','confirmed'], true);
        }
        unset($item);
        foreach ($all as $row) {
            if (in_array((string) $row['status'], ['active','confirmed'], true)) $summary['active']++;
            if ((string) $row['reservation_kind'] === 'backorder' && in_array((string) $row['status'], ['active','confirmed'], true)) $summary['backorders']++;
            if ($row['expires_at'] !== null && in_array((string) $row['status'], ['active','confirmed'], true)) {
                $expires = strtotime((string) $row['expires_at']);
                if ($expires <= $now + 600) $summary['expiring_soon']++;
                if ($expires <= $now) $summary['blocked']++;
            }
        }
        $summary['order_linked'] = (int) ($this->rawDatabase()->one($cte . 'SELECT COUNT(*) AS count FROM entries WHERE site_id=? AND order_id IS NOT NULL', [$siteId])['count'] ?? 0);
        return ['items' => $items, 'summary' => $summary, 'limit' => $safeLimit, 'offset' => $safeOffset, 'total' => $total, 'has_more' => $safeOffset + $safeLimit < $total];
    }

    /** @return list<array<string,mixed>> */
    public function reservationPolicies(int $siteId): array
    {
        return $this->rawDatabase()->all(
            'SELECT c.id AS channel_id,c.code AS channel_code,c.name AS channel_name,c.channel_kind,c.channel_type,
                    cfg.stock_location_id,cfg.availability_policy,cfg.reservation_policy,cfg.reservation_ttl_seconds,
                    cfg.reservation_renewal_window_seconds,cfg.reservation_max_lifetime_seconds,cfg.backorder_policy,cfg.show_exact_quantity,cfg.status
             FROM sale_channels c INNER JOIN sale_inventory_channel_configs cfg ON cfg.channel_id=c.id
             WHERE c.site_id=? ORDER BY c.channel_kind,c.name',
            [$siteId]
        );
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function updateReservationPolicy(int $siteId, int $channelId, array $payload): array
    {
        $current = $this->rawDatabase()->one('SELECT * FROM sale_inventory_channel_configs WHERE site_id=? AND channel_id=?', [$siteId, $channelId]);
        if ($current === null) throw new SaleInventoryException('sale.stock_reservation_policy_not_found');
        $policy = (string) ($payload['reservation_policy'] ?? $current['reservation_policy']);
        $backorder = (string) ($payload['backorder_policy'] ?? $current['backorder_policy']);
        if (!in_array($policy, ['checkout_start','order_placement','payment_authorization','payment_capture'], true)) throw new SaleInventoryException('sale.stock_reservation_policy_invalid');
        if (!in_array($backorder, ['disabled','sellable','enabled'], true)) throw new SaleInventoryException('sale.stock_backorder_policy_invalid');
        $ttl = max(60, min(86400, (int) ($payload['reservation_ttl_seconds'] ?? $current['reservation_ttl_seconds'])));
        $window = max(30, min(3600, (int) ($payload['reservation_renewal_window_seconds'] ?? $current['reservation_renewal_window_seconds'])));
        $maxLifetime = max(300, min(604800, (int) ($payload['reservation_max_lifetime_seconds'] ?? $current['reservation_max_lifetime_seconds'])));
        if ($maxLifetime < $ttl) throw new SaleInventoryException('sale.stock_reservation_lifetime_invalid');
        $this->rawDatabase()->run(
            'UPDATE sale_inventory_channel_configs SET reservation_policy=?,reservation_ttl_seconds=?,reservation_renewal_window_seconds=?,reservation_max_lifetime_seconds=?,backorder_policy=?,show_exact_quantity=?,updated_at=CURRENT_TIMESTAMP WHERE site_id=? AND channel_id=?',
            [$policy,$ttl,$window,$maxLifetime,$backorder,(int) (bool) ($payload['show_exact_quantity'] ?? $current['show_exact_quantity']),$siteId,$channelId]
        );
        return $this->rawDatabase()->one('SELECT * FROM sale_inventory_channel_configs WHERE site_id=? AND channel_id=?', [$siteId, $channelId]) ?? [];
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
    private function releaseReservation(array $reservation, string $status, string $reason, ?int $actorId = null): void
    {
        $this->rawDatabase()->transaction(function () use ($reservation, $status, $reason, $actorId): void {
            $quantity = (int) $reservation['quantity'];
            $itemId = (int) $reservation['inventory_item_id'];
            $claim = $this->rawDatabase()->pdo()->prepare(
                "UPDATE sale_stock_reservations
                 SET status=?,released_at=CURRENT_TIMESTAMP,cancelled_at=CASE WHEN ?='cancelled' THEN CURRENT_TIMESTAMP ELSE cancelled_at END,updated_at=CURRENT_TIMESTAMP
                 WHERE id=? AND status IN ('active','confirmed')"
            );
            $claim->execute([$status, $status, (int) $reservation['id']]);
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
            $this->movement($itemId, 'release', -$quantity, $referenceId === null ? null : 'cart', $referenceId, $reason, $actorId, 'release:reservation:' . (int) $reservation['id']);
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
        $item = $this->rawDatabase()->one('SELECT stock_location_id,on_hand_quantity FROM sale_inventory_items WHERE id=?', [$itemId]);
        if ($item === null) {
            throw new SaleInventoryException('sale.stock_item_not_found');
        }
        $correlationId = $idempotencyKey !== null && trim($idempotencyKey) !== ''
            ? strtolower(trim($idempotencyKey))
            : 'stock:' . bin2hex(random_bytes(12));
        $this->rawDatabase()->run(
            'INSERT OR IGNORE INTO sale_stock_movements(inventory_item_id,stock_location_id,movement_type,quantity,balance_after_quantity,idempotency_key,transfer_key,reference_type,reference_id,correlation_id,reason,created_by_iam_user_id)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',
            [$itemId,(int)$item['stock_location_id'],$type,$quantity,(int)$item['on_hand_quantity'],$idempotencyKey,$transferKey,$referenceType,$referenceId,$correlationId,$reason,$actorId]
        );
    }

    /** @param array<string,mixed> $item */
    private function publicAvailabilityStatus(array $item): string
    {
        if ((int) ($item['tracked'] ?? 0) === 0) return 'deliverable';
        if ((int) ($item['available_quantity'] ?? 0) > 0) return 'in_stock';
        if ((int) ($item['allow_backorder'] ?? 0) === 1) return 'backorder';
        return 'unavailable';
    }
}
