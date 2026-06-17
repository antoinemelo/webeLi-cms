<?php

declare(strict_types=1);

namespace App\EditorialPackage;

final class EditorialPackageManifest
{
    public const FORMAT = 'Editorial Package';
    public const VERSION = '1.0';
    public const HASH_ALGORITHM = 'sha256';

    /** @param list<string> $languages @param array{type:string,value:string|null} $scope @param array{entries:int,blueprints:int,media:int} $counts @param list<array{path:string,sha256:string,size:int}> $files */
    public function __construct(
        public readonly string $minimumCmsVersion,
        public readonly string $releaseId,
        public readonly string $generatedAt,
        public readonly string $sourceSiteKey,
        public readonly array $languages,
        public readonly array $scope,
        public readonly array $counts,
        public readonly array $files,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'format_version' => self::VERSION,
            'minimum_cms_version' => $this->minimumCmsVersion,
            'release_id' => $this->releaseId,
            'generated_at' => $this->generatedAt,
            'source_site_key' => $this->sourceSiteKey,
            'languages' => array_values($this->languages),
            'scope' => $this->scope,
            'counts' => $this->counts,
            'hash_algorithm' => self::HASH_ALGORITHM,
            'published_only' => true,
            'contains_drafts' => false,
            'contains_history' => false,
            'files' => array_values($this->files),
        ];
    }
}
