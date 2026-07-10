<?php

declare(strict_types=1);

namespace App\Modules\Business\Catalog;

interface CatalogVariantServiceContract
{
    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function create(int $siteId, int $productId, array $payload, ?int $actorId = null): array;

    public function archive(int $siteId, int $id, ?int $actorId = null): bool;
}
