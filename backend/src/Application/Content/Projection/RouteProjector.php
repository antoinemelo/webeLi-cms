<?php

declare(strict_types=1);

namespace App\Application\Content\Projection;

use App\Application\Publication\PublishedProjection;
use App\Application\Routing\PublicRouteRepository;
use App\Application\Support\ContentPathBuilder;
use App\Domain\Routing\PublicRoute;

final class RouteProjector
{
    public const RESOURCE_TYPE = 'content_entry';

    public function __construct(
        private readonly PublicRouteRepository $routes,
        private readonly ContentPathBuilder $paths,
    ) {}

    /**
     * @param array<string,mixed> $entry
     * @param list<array<string,mixed>> $localizations
     * @return array<string,string> new public path indexed by language code
     */
    public function project(array $entry, array $localizations): array
    {
        $newPaths = [];

        foreach ($localizations as $loc) {
            $languageCode = (string) $loc['language_code'];
            $slug = trim((string) ($loc['draft_slug'] ?? ''));
            $path = $this->paths->build((string) $entry['type_key'], $slug);

            $newPaths[$languageCode] = $path;
            $this->routes->save(new PublicRoute(
                (int) $entry['site_id'],
                $languageCode,
                self::RESOURCE_TYPE,
                (int) $entry['id'],
                $path,
                $slug
            ));
        }

        return $newPaths;
    }

    public function projectPublished(PublishedProjection $projection): void
    {
        $this->routes->save(new PublicRoute(
            $projection->siteId,
            $projection->languageCode,
            $projection->resourceType,
            $projection->resourceId,
            $projection->path,
            $projection->slug(),
            PublicRoute::TYPE_CONTENT,
            true,
            true,
            PublicRoute::STATUS_ACTIVE,
            $projection->sourcePublishedRevisionId,
            $projection->sourceRevisionChecksumSha256
        ));
    }

    public function buildPath(string $typeKey, string $slug): string
    {
        return $this->paths->build($typeKey, $slug);
    }
}
