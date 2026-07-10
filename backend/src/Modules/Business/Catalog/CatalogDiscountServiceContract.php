<?php

declare(strict_types=1);

namespace App\Modules\Business\Catalog;

use DateTimeImmutable;

interface CatalogDiscountServiceContract
{
    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function create(int $siteId, array $payload, ?int $actorId = null): array;

    /** @return list<array<string,mixed>> */
    public function activeForVariant(int $siteId, int $productId, int $variantId, ?string $channel = null, ?DateTimeImmutable $at = null): array;

    public function archive(int $siteId, int $id, ?int $actorId = null): bool;
}
