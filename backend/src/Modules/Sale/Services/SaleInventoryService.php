<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Modules\Sale\Repositories\SaleInventoryRepository;

final class SaleInventoryService
{
    public function __construct(private readonly SaleInventoryRepository $inventory) {}

    /** @param array<string,mixed> $snapshot @return array<string,mixed>|null */
    public function reserveForCart(int $siteId, int $cartId, array $snapshot, int $quantity): ?array
    {
        return $this->inventory->reserveForCart($siteId, $cartId, $snapshot, $quantity);
    }

    public function consumeCartReservations(int $cartId, int $orderId): void
    {
        $this->inventory->consumeCartReservations($cartId, $orderId);
    }

    public function releaseCartReservations(int $cartId): void
    {
        $this->inventory->releaseCartReservations($cartId);
    }
}
