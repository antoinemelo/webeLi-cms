<?php

declare(strict_types=1);

namespace App\Domain\Blueprint;

final class Blueprint
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $blueprintKey,
        public readonly string $resourceType,
        public readonly ?int $siteId,
        public readonly ?int $legacyContentTypeId,
        public readonly string $label,
        public readonly ?string $description,
        public readonly bool $isActive,
        public readonly ?int $activeVersionId,
    ) {}

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            isset($row['id']) ? (int) $row['id'] : null,
            (string) $row['blueprint_key'],
            (string) $row['resource_type'],
            $row['site_id'] === null ? null : (int) $row['site_id'],
            $row['legacy_content_type_id'] === null ? null : (int) $row['legacy_content_type_id'],
            (string) $row['label'],
            $row['description'] === null ? null : (string) $row['description'],
            (bool) ((int) ($row['is_active'] ?? 1)),
            $row['active_version_id'] === null ? null : (int) $row['active_version_id'],
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'blueprint_key' => $this->blueprintKey,
            'resource_type' => $this->resourceType,
            'site_id' => $this->siteId,
            'legacy_content_type_id' => $this->legacyContentTypeId,
            'label' => $this->label,
            'description' => $this->description,
            'is_active' => $this->isActive,
            'active_version_id' => $this->activeVersionId,
        ];
    }
}
