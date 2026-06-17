<?php

declare(strict_types=1);

namespace App\Application\Routing;

interface PublicRouteReadRepository
{
    public function listPublishedRoutes(int $siteId, string $languageCode): array;
    public function listPublishedRouteAlternates(int $siteId, string $resourceType, int $resourceId): array;
    public function findRedirect(int $siteId, string $path, string $languageCode): ?array;
    public function findTombstone(int $siteId, string $path, string $languageCode): ?array;
    public function buildPath(string $typeKey, string $slug): string;
}
