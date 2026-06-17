<?php

declare(strict_types=1);

namespace App\Application\Publication;

use App\Domain\Seo\SeoMetadata;

/**
 * Contrat applicatif unique de publication publique.
 *
 * Une PublishedProjection représente exactement une langue publiée d'une entrée.
 *
 * Le contrat est volontairement par langue : un rebuild complet produit 0..n
 * PublishedProjection, une pour chaque ligne publiée de content_entry_publications.
 * Chaque projection porte aussi la révision source afin que routes, SEO et search
 * documents soient auditables et jamais reconstruits depuis une draft ni depuis
 * l'espace de travail content_entry_localizations.
 */
final class PublishedProjection
{
    /** @param array<string,mixed> $document */
    public function __construct(
        public readonly int $siteId,
        public readonly string $languageCode,
        public readonly string $resourceType,
        public readonly int $resourceId,
        public readonly string $path,
        public readonly array $document,
        public readonly SeoMetadata $seo,
        public readonly int $sourcePublishedRevisionId,
        public readonly string $sourceRevisionChecksumSha256,
    ) {
        if ($this->siteId <= 0) {
            throw new \InvalidArgumentException('PublishedProjection: siteId doit être positif.');
        }
        if (trim($this->languageCode) === '') {
            throw new \InvalidArgumentException('PublishedProjection: languageCode est obligatoire.');
        }
        if (trim($this->resourceType) === '') {
            throw new \InvalidArgumentException('PublishedProjection: resourceType est obligatoire.');
        }
        if ($this->resourceId <= 0) {
            throw new \InvalidArgumentException('PublishedProjection: resourceId doit être positif.');
        }
        if ($this->path !== '/' && (!str_starts_with($this->path, '/') || str_contains($this->path, '//'))) {
            throw new \InvalidArgumentException(sprintf('PublishedProjection: path invalide "%s".', $this->path));
        }
        if ($this->sourcePublishedRevisionId <= 0) {
            throw new \InvalidArgumentException('PublishedProjection: sourcePublishedRevisionId doit être positif.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $this->sourceRevisionChecksumSha256)) {
            throw new \InvalidArgumentException('PublishedProjection: sourceRevisionChecksumSha256 doit être un SHA-256 hexadécimal.');
        }
    }

    public function title(): string
    {
        return trim((string) ($this->content()['title'] ?? ''));
    }

    public function slug(): string
    {
        return trim((string) ($this->content()['slug'] ?? ''));
    }


    /** @return array<string,mixed> */
    public function content(): array
    {
        return is_array($this->document['content'] ?? null) ? $this->document['content'] : [];
    }

    /** @return array<string,mixed> */
    public function localizationRow(): array
    {
        return [
            'id' => 0,
            'entry_id' => $this->resourceId,
            'language_code' => $this->languageCode,
            'title' => $this->title(),
            'slug' => $this->slug(),
            'status' => 'published',
            'is_active' => 1,
        ];
    }

    /** @return array<string,mixed> */
    public function routeRow(): array
    {
        return [
            'site_id' => $this->siteId,
            'language_code' => $this->languageCode,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
            'slug' => $this->slug(),
            'full_path' => $this->path,
            'is_primary' => 1,
            'is_canonical' => 1,
            'status' => 'active',
        ];
    }

    /** @return array<string,mixed> */
    public function seoRow(): array
    {
        return $this->seo->toArray();
    }
}
