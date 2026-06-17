<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Domain\Schema\ContentType;

final class ContentEntry
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    public function __construct(
        public readonly string $entryKey,
        public readonly string $typeKey,
        public readonly string $status,
        public readonly string $workflowState,
        public readonly bool $isActive = true,
        public readonly ?int $id = null,
        public readonly ?int $siteId = null,
        public readonly ?string $publishedAt = null
    ) {
        if (trim($this->entryKey) === '') {
            throw new \InvalidArgumentException('La clé de l’entrée est obligatoire.');
        }
        if (trim($this->typeKey) === '') {
            throw new \InvalidArgumentException('Le type de contenu de l’entrée est obligatoire.');
        }
    }

    public static function fromArray(array $row, ?string $typeKey = null): self
    {
        return new self(
            (string) ($row['entry_key'] ?? ''),
            (string) ($typeKey ?? $row['type_key'] ?? ''),
            (string) ($row['status'] ?? self::STATUS_DRAFT),
            (string) ($row['workflow_state'] ?? self::STATUS_DRAFT),
            (bool) (int) ($row['is_active'] ?? 1),
            isset($row['id']) ? (int) $row['id'] : null,
            isset($row['site_id']) ? (int) $row['site_id'] : null,
            isset($row['published_at']) ? (string) $row['published_at'] : null,
        );
    }

    public function belongsToType(ContentType|string $type): bool
    {
        return $this->typeKey === ($type instanceof ContentType ? $type->typeKey : $type);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT || $this->workflowState === self::STATUS_DRAFT;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    public function canBePublished(ContentType $contentType): bool
    {
        return $this->isActive && !$this->isArchived() && $this->belongsToType($contentType);
    }

    public function withPublishedRevision(int $revisionId, string $publishedAt): self
    {
        return new self(
            $this->entryKey,
            $this->typeKey,
            self::STATUS_PUBLISHED,
            'published',
            $this->isActive,
            $this->id,
            $this->siteId,
            $publishedAt,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'site_id' => $this->siteId,
            'entry_key' => $this->entryKey,
            'type_key' => $this->typeKey,
            'status' => $this->status,
            'workflow_state' => $this->workflowState,
            'is_active' => $this->isActive,
            'published_at' => $this->publishedAt,
        ];
    }
}
