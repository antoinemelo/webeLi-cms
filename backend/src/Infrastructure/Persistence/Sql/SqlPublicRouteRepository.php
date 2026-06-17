<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sql;

use App\Application\Routing\PublicRouteRepository;
use App\Core\Database;
use App\Domain\Routing\PublicRoute;

final class SqlPublicRouteRepository implements PublicRouteRepository
{
    public function __construct(private readonly Database $db) {}

    public function listByResource(string $resourceType, int $resourceId): array
    {
        return $this->db->all('SELECT * FROM routes WHERE resource_type = :resource_type AND resource_id = :resource_id', [
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
        ]);
    }

    public function deleteByResource(string $resourceType, int $resourceId): void
    {
        $this->db->run('DELETE FROM routes WHERE resource_type = :resource_type AND resource_id = :resource_id', [
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
        ]);
    }

    public function save(PublicRoute $route): void
    {
        $now = now_utc();
        $this->db->run('INSERT INTO routes(site_id, language_code, resource_type, resource_id, route_type, slug, full_path, is_primary, is_canonical, status, source_published_revision_id, source_revision_checksum_sha256, created_at, updated_at) VALUES(:site_id, :language_code, :resource_type, :resource_id, :route_type, :slug, :full_path, :is_primary, :is_canonical, :status, :source_published_revision_id, :source_revision_checksum_sha256, :created_at, :updated_at)', [
            'site_id' => $route->siteId,
            'language_code' => $route->languageCode,
            'resource_type' => $route->resourceType,
            'resource_id' => $route->resourceId,
            'route_type' => $route->routeType,
            'slug' => $route->slug,
            'full_path' => $route->fullPath,
            'is_primary' => $route->isPrimary ? 1 : 0,
            'is_canonical' => $route->isCanonical ? 1 : 0,
            'status' => $route->status,
            'source_published_revision_id' => $route->sourcePublishedRevisionId,
            'source_revision_checksum_sha256' => $route->sourceRevisionChecksumSha256,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
