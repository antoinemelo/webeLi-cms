<?php

declare(strict_types=1);

namespace App\Domain\Routing;

final class PublicRoute
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ARCHIVED = 'archived';

    public const TYPE_CONTENT = 'content';
    public const TYPE_TAXONOMY = 'taxonomy';
    public const TYPE_SYSTEM = 'system';

    public function __construct(
        public readonly int $siteId,
        public readonly string $languageCode,
        public readonly string $resourceType,
        public readonly int $resourceId,
        public readonly string $fullPath,
        public readonly ?string $slug = null,
        public readonly string $routeType = self::TYPE_CONTENT,
        public readonly bool $isPrimary = true,
        public readonly bool $isCanonical = true,
        public readonly string $status = self::STATUS_ACTIVE,
        public readonly ?int $sourcePublishedRevisionId = null,
        public readonly ?string $sourceRevisionChecksumSha256 = null,
    ) {
        if ($this->siteId < 1) {
            throw new \InvalidArgumentException('Une route publique doit appartenir à un site valide.');
        }
        if (trim($this->languageCode) === '') {
            throw new \InvalidArgumentException('La langue de la route est obligatoire.');
        }
        if (trim($this->resourceType) === '' || $this->resourceId < 1) {
            throw new \InvalidArgumentException('Une route publique doit pointer vers une ressource valide.');
        }
        if (!$this->isValidRouteType($this->routeType)) {
            throw new \InvalidArgumentException(sprintf('Type de route publique invalide : %s.', $this->routeType));
        }
        if (!$this->isValidStatus($this->status)) {
            throw new \InvalidArgumentException(sprintf('Statut de route publique invalide : %s.', $this->status));
        }
        if (!$this->isValidPath($this->fullPath)) {
            throw new \InvalidArgumentException(sprintf('Chemin public invalide : %s.', $this->fullPath));
        }
    }

    public static function fromArray(array $row): self
    {
        return new self(
            (int) ($row['site_id'] ?? 0),
            (string) ($row['language_code'] ?? ''),
            (string) ($row['resource_type'] ?? ''),
            (int) ($row['resource_id'] ?? 0),
            (string) ($row['full_path'] ?? ''),
            isset($row['slug']) ? (string) $row['slug'] : null,
            (string) ($row['route_type'] ?? self::TYPE_CONTENT),
            (bool) (int) ($row['is_primary'] ?? 1),
            (bool) (int) ($row['is_canonical'] ?? 1),
            (string) ($row['status'] ?? self::STATUS_ACTIVE),
            isset($row['source_published_revision_id']) ? (int) $row['source_published_revision_id'] : null,
            isset($row['source_revision_checksum_sha256']) ? (string) $row['source_revision_checksum_sha256'] : null,
        );
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function pointsTo(string $resourceType, int $resourceId): bool
    {
        return $this->resourceType === $resourceType && $this->resourceId === $resourceId;
    }

    public function canonicalUrl(?string $baseUrl = null): string
    {
        $path = $this->fullPath === '/' ? '/' : '/' . trim($this->fullPath, '/');
        return $baseUrl ? rtrim($baseUrl, '/') . $path : $path;
    }

    public function toArray(): array
    {
        return [
            'site_id' => $this->siteId,
            'language_code' => $this->languageCode,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
            'route_type' => $this->routeType,
            'slug' => $this->slug,
            'full_path' => $this->fullPath,
            'is_primary' => $this->isPrimary,
            'is_canonical' => $this->isCanonical,
            'status' => $this->status,
            'source_published_revision_id' => $this->sourcePublishedRevisionId,
            'source_revision_checksum_sha256' => $this->sourceRevisionChecksumSha256,
        ];
    }

    private function isValidRouteType(string $routeType): bool
    {
        return in_array($routeType, [self::TYPE_CONTENT, self::TYPE_TAXONOMY, self::TYPE_SYSTEM], true);
    }

    private function isValidStatus(string $status): bool
    {
        return in_array($status, [self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_ARCHIVED], true);
    }

    private function isValidPath(string $path): bool
    {
        return $path === '/' || (
            str_starts_with($path, '/')
            && !str_contains($path, '//')
            && !str_contains($path, ' ')
            && rtrim($path, '/') === $path
            && $path === strtolower($path)
        );
    }
}
