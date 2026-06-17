<?php

declare(strict_types=1);

namespace App\StaticExport;

/**
 * Route publique candidate pour l'export statique.
 *
 * Le DTO reste volontairement centré sur les projections publiques. Il ne porte
 * aucune notion de brouillon, preview ou révision non publiée.
 */
final class StaticExportRoute
{
    /**
     * @param array<string,mixed> $metadata Données non critiques utiles au rapport.
     */
    public function __construct(
        public readonly int $siteId,
        public readonly string $siteKey,
        public readonly string $languageCode,
        public readonly string $path,
        public readonly string $outputPath,
        public readonly string $routeType,
        public readonly ?string $resourceType = null,
        public readonly ?int $resourceId = null,
        public readonly bool $isCanonical = true,
        public readonly bool $isIndexable = true,
        public readonly array $metadata = [],
        public readonly int $statusCode = 200,
        public readonly ?string $redirectTo = null,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'site_id' => $this->siteId,
            'site_key' => $this->siteKey,
            'language_code' => $this->languageCode,
            'path' => $this->path,
            'output_path' => $this->outputPath,
            'route_type' => $this->routeType,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
            'is_canonical' => $this->isCanonical,
            'is_indexable' => $this->isIndexable,
            'status_code' => $this->statusCode,
            'redirect_to' => $this->redirectTo,
            'metadata' => $this->metadata,
        ];
    }
}
