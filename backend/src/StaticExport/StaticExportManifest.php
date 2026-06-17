<?php

declare(strict_types=1);

namespace App\StaticExport;

/**
 * Manifest d'une release d'export statique.
 */
final class StaticExportManifest
{
    /**
     * @param list<string> $languages
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly string $releaseId,
        public readonly ?string $site,
        public readonly array $languages,
        public readonly string $mode,
        public readonly int $candidateRoutes,
        public readonly int $exportedRoutes,
        public readonly int $ignoredRoutes,
        public readonly int $assets,
        public readonly int $media,
        public readonly array $errors,
        public readonly array $warnings,
        public readonly string $generatedAt,
        public readonly string $outputPath,
        public readonly ?string $cmsVersion = null,
        public readonly ?float $durationSeconds = null,
        public readonly string $status = 'succeeded',
        public readonly ?string $zipPath = null,
        public readonly string $trigger = 'cli',
        public readonly ?string $routePath = null,
        public readonly array $artifacts = [],
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'release_id' => $this->releaseId,
            'site' => $this->site,
            'languages' => $this->languages,
            'mode' => $this->mode,
            'routes' => [
                'candidates' => $this->candidateRoutes,
                'exported' => $this->exportedRoutes,
                'ignored' => $this->ignoredRoutes,
            ],
            'assets' => $this->assets,
            'media' => $this->media,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'generated_at' => $this->generatedAt,
            'output_path' => $this->outputPath,
            'cms_version' => $this->cmsVersion,
            'duration_seconds' => $this->durationSeconds,
            'status' => $this->status,
            'zip_path' => $this->zipPath,
            'trigger' => $this->trigger,
            'route' => $this->routePath,
            'dry_run' => $this->trigger === 'dry_run',
            'artifacts' => $this->artifacts,
        ];
    }
}
