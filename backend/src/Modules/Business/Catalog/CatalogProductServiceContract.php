<?php

declare(strict_types=1);

namespace App\Modules\Business\Catalog;

interface CatalogProductServiceContract
{
    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function create(int $siteId, array $payload, ?int $actorId = null): array;

    /** @param array<string,mixed> $payload @return array<string,mixed>|null */
    public function update(int $siteId, int $id, array $payload, ?int $actorId = null): ?array;

    public function archive(int $siteId, int $id, ?int $actorId = null): bool;

    public function restore(int $siteId, int $id, ?int $actorId = null): bool;

    public function deletePermanently(int $siteId, int $id): bool;
}
