<?php

declare(strict_types=1);

namespace App\Domain\Taxonomy;

final class Taxonomy
{
    public function __construct(
        public readonly string $taxonomyKey,
        public readonly string $name,
        public readonly bool $isHierarchical = false,
        public readonly bool $isLocalized = true,
        public readonly bool $seoEnabled = true,
        public readonly bool $archiveEnabled = true
    ) {
        if (trim($this->taxonomyKey) === '') {
            throw new \InvalidArgumentException('La clé de taxonomie est obligatoire.');
        }
        if (trim($this->name) === '') {
            throw new \InvalidArgumentException('Le nom de taxonomie est obligatoire.');
        }
    }

    public static function fromArray(array $row): self
    {
        return new self(
            (string) ($row['taxonomy_key'] ?? ''),
            (string) ($row['name'] ?? ''),
            (bool) (int) ($row['is_hierarchical'] ?? 0),
            (bool) (int) ($row['is_localized'] ?? 1),
            (bool) (int) ($row['seo_enabled'] ?? 1),
            (bool) (int) ($row['archive_enabled'] ?? 1),
        );
    }

    public function acceptsChildren(): bool
    {
        return $this->isHierarchical;
    }

    public function acceptsLocalization(): bool
    {
        return $this->isLocalized;
    }

    public function hasPublicArchive(): bool
    {
        return $this->archiveEnabled;
    }

    public function allowsSeo(): bool
    {
        return $this->seoEnabled;
    }

    public function toArray(): array
    {
        return [
            'taxonomy_key' => $this->taxonomyKey,
            'name' => $this->name,
            'is_hierarchical' => $this->isHierarchical,
            'is_localized' => $this->isLocalized,
            'seo_enabled' => $this->seoEnabled,
            'archive_enabled' => $this->archiveEnabled,
        ];
    }
}
