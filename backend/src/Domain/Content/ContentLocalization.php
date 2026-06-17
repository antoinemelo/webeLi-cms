<?php

declare(strict_types=1);

namespace App\Domain\Content;

/**
 * Représentation de l'espace de travail de localisation côté admin.
 *
 * Important : draft_slug, draft_full_path, draft_status et
 * admin_cache_published_at sont des informations de brouillon ou de cache
 * éditorial. La vérité publique reste portée par routes,
 * content_entry_publications et revisions.
 */
class ContentLocalization
{
    public function __construct(
        public readonly string $languageCode,
        public readonly ?string $title,
        public readonly ?string $draftSlug,
        public readonly ?string $draftFullPath,
        public readonly ?string $summary,
        public readonly ?string $excerpt = null,
        public readonly ?string $body = null,
        public readonly string $draftStatus = 'draft',
        public readonly bool $isActive = true,
        public readonly ?string $adminCachePublishedAt = null,
    ) {
        if (trim($this->languageCode) === '') {
            throw new \InvalidArgumentException('La langue de localisation est obligatoire.');
        }
    }

    public static function fromArray(array $row): self
    {
        return new self(
            (string) ($row['language_code'] ?? ''),
            isset($row['title']) ? (string) $row['title'] : null,
            isset($row['draft_slug']) ? (string) $row['draft_slug'] : null,
            isset($row['draft_full_path']) ? (string) $row['draft_full_path'] : null,
            isset($row['summary']) ? (string) $row['summary'] : null,
            isset($row['excerpt']) ? (string) $row['excerpt'] : null,
            isset($row['body']) ? (string) $row['body'] : null,
            (string) ($row['draft_status'] ?? 'draft'),
            (bool) (int) ($row['is_active'] ?? 1),
            isset($row['admin_cache_published_at']) ? (string) $row['admin_cache_published_at'] : null,
        );
    }

    public function hasPublicPath(): bool
    {
        return $this->draftFullPath !== null && trim($this->draftFullPath) !== '';
    }

    public function hasSlug(): bool
    {
        return $this->draftSlug !== null && trim($this->draftSlug) !== '';
    }

    public function isPublishable(): bool
    {
        return $this->isActive && $this->title !== null && trim($this->title) !== '' && $this->hasSlug();
    }

    public function displayTitle(string $fallback = 'Sans titre'): string
    {
        $title = trim((string) $this->title);
        return $title !== '' ? $title : $fallback;
    }

    public function toArray(): array
    {
        return [
            'language_code' => $this->languageCode,
            'title' => $this->title,
            'draft_slug' => $this->draftSlug,
            'draft_full_path' => $this->draftFullPath,
            'summary' => $this->summary,
            'excerpt' => $this->excerpt,
            'body' => $this->body,
            'draft_status' => $this->draftStatus,
            'is_active' => $this->isActive,
            'admin_cache_published_at' => $this->adminCachePublishedAt,
        ];
    }
}
