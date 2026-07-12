<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Modules\Sale\Repositories\SaleInventoryRepository;

final class SaleStockMovementService
{
    public function __construct(private readonly SaleInventoryRepository $inventory) {}

    /** @return array{items:list<array<string,mixed>>,limit:int,offset:int,total:int,has_more:bool} */
    public function list(int $siteId, int $limit = 50, int $offset = 0): array
    {
        return $this->inventory->movements($siteId, $limit, $offset);
    }

    /** @return array<string,mixed> */
    public function adjust(int $siteId, int $businessVariantId, int $quantityDelta, ?string $sku = null, ?string $reason = null, ?int $actorId = null, ?int $locationId = null, string $movementType = 'adjustment', ?string $idempotencyKey = null): array
    {
        return $this->inventory->adjust($siteId, $businessVariantId, $quantityDelta, $sku, $reason, $actorId, $locationId, $movementType, $idempotencyKey);
    }

    /** @return array<string,mixed> */
    public function restockReturn(int $siteId, int $businessVariantId, int $quantity, ?string $sku = null, ?int $returnId = null, ?string $reason = null, ?int $actorId = null): array
    {
        return $this->inventory->restockReturn($siteId, $businessVariantId, $quantity, $sku, $returnId, $reason, $actorId);
    }
}
