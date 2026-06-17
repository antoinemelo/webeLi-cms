<?php

declare(strict_types=1);

namespace App\Application\Routing;

use App\Domain\Routing\PublicRoute;

interface PublicRouteRepository
{
    /** @return list<array<string,mixed>> */
    public function listByResource(string $resourceType, int $resourceId): array;

    public function deleteByResource(string $resourceType, int $resourceId): void;

    public function save(PublicRoute $route): void;
}
