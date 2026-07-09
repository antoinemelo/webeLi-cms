<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Modules\Sale\Repositories\SaleInventoryRepository;

final class SaleInventoryService
{
    private readonly SaleStockReservationService $reservations;
    private readonly SaleStockMovementService $movements;

    public function __construct(
        private readonly SaleInventoryRepository $inventory,
        ?SaleStockReservationService $reservations = null,
        ?SaleStockMovementService $movements = null,
        private readonly ?SaleEventService $events = null
    ) {
        $this->reservations = $reservations ?? new SaleStockReservationService($inventory);
        $this->movements = $movements ?? new SaleStockMovementService($inventory);
    }

    /** @return array{items:list<array<string,mixed>>,limit:int,offset:int,total:int,has_more:bool} */
    public function listItems(int $siteId, int $limit = 50, int $offset = 0): array
    {
        return $this->inventory->listItems($siteId, $limit, $offset);
    }

    /** @return array{items:list<array<string,mixed>>,limit:int,offset:int,total:int,has_more:bool} */
    public function movements(int $siteId, int $limit = 50, int $offset = 0): array
    {
        return $this->movements->list($siteId, $limit, $offset);
    }

    /** @return array<string,mixed> */
    public function adjust(int $siteId, int $businessVariantId, int $quantityDelta, ?string $sku = null, ?string $reason = null, ?int $actorId = null): array
    {
        return $this->movements->adjust($siteId, $businessVariantId, $quantityDelta, $sku, $reason, $actorId);
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed>|null */
    public function reserveForCart(int $siteId, int $cartId, array $snapshot, int $quantity): ?array
    {
        $reservation = $this->reservations->reserveForCart($siteId, $cartId, $snapshot, $quantity);
        if ($reservation !== null) {
            $this->emitStockReserved($siteId, $cartId, $reservation, $snapshot, 'cart line reservation');
        }
        return $reservation;
    }

    public function consumeCartReservations(int $cartId, int $orderId): void
    {
        $reservations = $this->cartReservations($cartId);
        $this->reservations->consumeCart($cartId, $orderId);
        foreach ($reservations as $reservation) {
            $this->emitStockConsumed($reservation, $orderId);
        }
    }

    public function releaseCartReservations(int $cartId, ?string $reason = null): void
    {
        $reservations = $this->cartReservations($cartId);
        $this->reservations->releaseCart($cartId, $reason);
        foreach ($reservations as $reservation) {
            $this->emitStockReleased($reservation, 'released', $reason ?? 'reservation release');
        }
    }

    public function syncCartLineReservation(int $siteId, int $cartId, int $businessVariantId, int $targetQuantity, bool $allowBackorder = false): void
    {
        $before = $this->cartVariantReservedQuantity($cartId, $businessVariantId);
        $this->reservations->syncCartLine($siteId, $cartId, $businessVariantId, $targetQuantity, $allowBackorder);
        $after = $this->cartVariantReservedQuantity($cartId, $businessVariantId);
        $delta = $after - $before;
        if ($delta > 0) {
            $this->events?->emit($siteId, 'sale.stock.reserved', 'cart', $cartId, [
                'site_id' => $siteId,
                'cart_id' => $cartId,
                'business_variant_id' => $businessVariantId,
                'quantity' => $delta,
                'source' => 'cart_line_quantity_increase',
            ]);
        } elseif ($delta < 0) {
            $this->events?->emit($siteId, 'sale.stock.released', 'cart', $cartId, [
                'site_id' => $siteId,
                'cart_id' => $cartId,
                'business_variant_id' => $businessVariantId,
                'quantity' => abs($delta),
                'status' => 'released',
                'reason' => 'cart line quantity decrease',
            ]);
        }
    }

    public function releaseCartVariantReservations(int $cartId, int $businessVariantId, ?string $reason = null): void
    {
        $reservations = $this->cartReservations($cartId, $businessVariantId);
        $this->reservations->releaseCartVariant($cartId, $businessVariantId, $reason);
        foreach ($reservations as $reservation) {
            $this->emitStockReleased($reservation, 'released', $reason ?? 'cart line deleted');
        }
    }

    public function expireDueReservations(?int $siteId = null): int
    {
        $reservations = $this->dueReservations($siteId);
        $count = $this->reservations->expireDue($siteId);
        foreach ($reservations as $reservation) {
            $this->emitStockReleased($reservation, 'expired', 'reservation expired');
        }
        return $count;
    }

    /** @return array<string,mixed> */
    public function restockReturn(int $siteId, int $businessVariantId, int $quantity, ?string $sku = null, ?int $returnId = null, ?string $reason = null, ?int $actorId = null): array
    {
        return $this->movements->restockReturn($siteId, $businessVariantId, $quantity, $sku, $returnId, $reason, $actorId);
    }

    /** @param array<string,mixed> $reservation @param array<string,mixed> $snapshot */
    private function emitStockReserved(int $siteId, int $cartId, array $reservation, array $snapshot, string $reason): void
    {
        $this->events?->emit($siteId, 'sale.stock.reserved', 'stock_reservation', (int) $reservation['id'], [
            'site_id' => $siteId,
            'cart_id' => $cartId,
            'reservation_id' => (int) $reservation['id'],
            'inventory_item_id' => (int) $reservation['inventory_item_id'],
            'business_variant_id' => (int) $snapshot['business_variant_id'],
            'quantity' => (int) $reservation['quantity'],
            'sku' => $snapshot['sku'] ?? null,
            'source' => 'cart',
            'reason' => $reason,
        ]);
    }

    /** @param array<string,mixed> $reservation */
    private function emitStockConsumed(array $reservation, int $orderId): void
    {
        $this->events?->emit((int) $reservation['site_id'], 'sale.stock.consumed', 'stock_reservation', (int) $reservation['id'], [
            'site_id' => (int) $reservation['site_id'],
            'order_id' => $orderId,
            'cart_id' => (int) $reservation['cart_id'],
            'reservation_id' => (int) $reservation['id'],
            'inventory_item_id' => (int) $reservation['inventory_item_id'],
            'business_variant_id' => (int) $reservation['business_variant_id'],
            'quantity' => (int) $reservation['quantity'],
            'sku' => $reservation['sku'] ?? null,
            'reason' => 'checkout consumption',
        ]);
    }

    /** @param array<string,mixed> $reservation */
    private function emitStockReleased(array $reservation, string $status, string $reason): void
    {
        $this->events?->emit((int) $reservation['site_id'], 'sale.stock.released', 'stock_reservation', (int) $reservation['id'], [
            'site_id' => (int) $reservation['site_id'],
            'cart_id' => (int) $reservation['cart_id'],
            'reservation_id' => (int) $reservation['id'],
            'inventory_item_id' => (int) $reservation['inventory_item_id'],
            'business_variant_id' => (int) $reservation['business_variant_id'],
            'quantity' => (int) $reservation['quantity'],
            'sku' => $reservation['sku'] ?? null,
            'status' => $status,
            'reason' => $reason,
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function cartReservations(int $cartId, ?int $businessVariantId = null): array
    {
        $params = [$cartId];
        $variantSql = '';
        if ($businessVariantId !== null) {
            $variantSql = ' AND i.business_variant_id = ?';
            $params[] = $businessVariantId;
        }
        return $this->inventory->rawDatabase()->all(
            'SELECT r.*, i.site_id, i.business_variant_id, i.sku
             FROM sale_stock_reservations r
             INNER JOIN sale_inventory_items i ON i.id = r.inventory_item_id
             WHERE r.cart_id = ? AND r.status = "active"' . $variantSql,
            $params
        );
    }

    private function cartVariantReservedQuantity(int $cartId, int $businessVariantId): int
    {
        $row = $this->inventory->rawDatabase()->one(
            'SELECT COALESCE(SUM(r.quantity), 0) AS total
             FROM sale_stock_reservations r
             INNER JOIN sale_inventory_items i ON i.id = r.inventory_item_id
             WHERE r.cart_id = ? AND i.business_variant_id = ? AND r.status = "active"',
            [$cartId, $businessVariantId]
        );
        return (int) ($row['total'] ?? 0);
    }

    /** @return list<array<string,mixed>> */
    private function dueReservations(?int $siteId): array
    {
        $params = [gmdate('Y-m-d H:i:s')];
        $siteSql = '';
        if ($siteId !== null) {
            $siteSql = ' AND i.site_id = ?';
            $params[] = $siteId;
        }
        return $this->inventory->rawDatabase()->all(
            'SELECT r.*, i.site_id, i.business_variant_id, i.sku
             FROM sale_stock_reservations r
             INNER JOIN sale_inventory_items i ON i.id = r.inventory_item_id
             WHERE r.status = "active" AND r.expires_at IS NOT NULL AND r.expires_at <= ?' . $siteSql,
            $params
        );
    }
}
