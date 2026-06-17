<?php

declare(strict_types=1);

namespace App\Domain\Content;

final class ContentRevision
{
    public const RESOURCE_TYPE = 'content_entry';
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_SUPERSEDED = 'superseded';

    public function __construct(
        public readonly string $resourceType,
        public readonly int $resourceId,
        public readonly int $revisionNumber,
        public readonly ?string $languageCode,
        public readonly string $workflowStatus,
        public readonly string $documentJson,
        public readonly ?int $id = null,
        public readonly ?int $blueprintId = null,
        public readonly ?int $blueprintVersionId = null,
        public readonly ?string $revisionLabel = null,
        public readonly ?string $summary = null,
        public readonly ?string $checksumSha256 = null,
        public readonly ?string $publishedAt = null
    ) {
        if ($this->resourceType === '') {
            throw new \InvalidArgumentException('Le type de ressource de la révision est obligatoire.');
        }
        if ($this->resourceId < 1) {
            throw new \InvalidArgumentException('Une révision doit viser une ressource valide.');
        }
        if ($this->revisionNumber < 1) {
            throw new \InvalidArgumentException('Le numéro de révision doit être positif.');
        }
        json_decode($this->documentJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Le document JSON de la révision est invalide.');
        }
    }

    public static function fromArray(array $row): self
    {
        return new self(
            (string) ($row['resource_type'] ?? self::RESOURCE_TYPE),
            (int) ($row['resource_id'] ?? 0),
            (int) ($row['revision_number'] ?? 0),
            isset($row['language_code']) ? (string) $row['language_code'] : null,
            (string) ($row['workflow_status'] ?? self::STATUS_DRAFT),
            (string) ($row['document_json'] ?? '{}'),
            isset($row['id']) ? (int) $row['id'] : null,
            isset($row['blueprint_id']) ? (int) $row['blueprint_id'] : null,
            isset($row['blueprint_version_id']) ? (int) $row['blueprint_version_id'] : null,
            isset($row['revision_label']) ? (string) $row['revision_label'] : null,
            isset($row['summary']) ? (string) $row['summary'] : null,
            isset($row['checksum_sha256']) ? (string) $row['checksum_sha256'] : null,
            isset($row['published_at']) ? (string) $row['published_at'] : null,
        );
    }

    public function isForEntry(int $entryId): bool
    {
        return $this->resourceType === self::RESOURCE_TYPE && $this->resourceId === $entryId;
    }

    public function isDraft(): bool
    {
        return $this->workflowStatus === self::STATUS_DRAFT;
    }

    public function isPublished(): bool
    {
        return $this->workflowStatus === self::STATUS_PUBLISHED;
    }

    public function isSuperseded(): bool
    {
        return $this->workflowStatus === self::STATUS_SUPERSEDED;
    }

    public function document(): array
    {
        $decoded = json_decode($this->documentJson, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function checksum(): string
    {
        return $this->checksumSha256 ?: hash('sha256', $this->documentJson);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
            'revision_number' => $this->revisionNumber,
            'language_code' => $this->languageCode,
            'workflow_status' => $this->workflowStatus,
            'document_json' => $this->documentJson,
            'blueprint_id' => $this->blueprintId,
            'blueprint_version_id' => $this->blueprintVersionId,
            'revision_label' => $this->revisionLabel,
            'summary' => $this->summary,
            'checksum_sha256' => $this->checksum(),
            'published_at' => $this->publishedAt,
        ];
    }
}
