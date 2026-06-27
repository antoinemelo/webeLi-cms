<?php

declare(strict_types=1);

namespace App\Modules\Business\Catalog;

interface CatalogStockServiceContract
{
    /** @return array<string,mixed> */
    public function move(int $variantId, string $movementType, float $quantity, ?string $reason = null, ?int $actorId = null): array;
}
