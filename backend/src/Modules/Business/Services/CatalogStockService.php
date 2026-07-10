<?php

declare(strict_types=1);

namespace App\Modules\Business\Services;

use App\Modules\Business\Catalog\CatalogStockServiceContract;
use App\Modules\Business\Repositories\CatalogStockRepository;

final class CatalogStockService implements CatalogStockServiceContract
{
    public function __construct(private readonly CatalogStockRepository $stock) {}

    /** @return array<string,mixed> */
    public function move(int $variantId, string $movementType, float $quantity, ?string $reason = null, ?int $actorId = null): array
    {
        return $this->stock->move($variantId, $movementType, $quantity, $reason, $actorId);
    }

    /** @return array{variant:array<string,mixed>,movement:array<string,mixed>} */
    public function createMovement(int $siteId, int $variantId, string $movementType, float $quantity, ?string $reason = null, ?string $referenceType = null, ?int $referenceId = null, ?int $actorId = null): array
    {
        return $this->stock->createMovement($siteId, $variantId, $movementType, $quantity, $reason, $referenceType, $referenceId, $actorId);
    }

    /** @return array<string,mixed> */
    public function stock(int $siteId, int $variantId): array
    {
        return $this->stock->stock($siteId, $variantId);
    }

    /** @param array<string,mixed> $filters @return array{items:list<array<string,mixed>>,limit:int,offset:int,total:int} */
    public function movements(int $siteId, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return $this->stock->movements($siteId, $filters, $limit, $offset);
    }
}
