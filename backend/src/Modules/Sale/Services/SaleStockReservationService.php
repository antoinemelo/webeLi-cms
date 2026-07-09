<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Modules\Sale\Repositories\SaleInventoryRepository;

final class SaleStockReservationService
{
    public function __construct(private readonly SaleInventoryRepository $inventory) {}

    /** @param array<string,mixed> $snapshot @return array<string,mixed>|null */
    public function reserveForCart(int $siteId, int $cartId, array $snapshot, int $quantity, int $ttlSeconds = 1800): ?array
    {
        return $this->inventory->reserveForCart($siteId, $cartId, $snapshot, $quantity, $ttlSeconds);
    }

    public function consumeCart(int $cartId, int $orderId): void
    {
        $this->inventory->consumeCartReservations($cartId, $orderId);
    }

    public function releaseCart(int $cartId, ?string $reason = null): void
    {
        $this->inventory->releaseCartReservations($cartId, $reason);
    }

    public function syncCartLine(int $siteId, int $cartId, int $businessVariantId, int $targetQuantity, bool $allowBackorder = false): void
    {
        $this->inventory->syncCartVariantReservation($siteId, $cartId, $businessVariantId, $targetQuantity, $allowBackorder);
    }

    public function releaseCartVariant(int $cartId, int $businessVariantId, ?string $reason = null): void
    {
        $this->inventory->releaseCartVariantReservations($cartId, $businessVariantId, $reason);
    }

    public function expireDue(?int $siteId = null): int
    {
        return $this->inventory->expireDueReservations($siteId);
    }
}
