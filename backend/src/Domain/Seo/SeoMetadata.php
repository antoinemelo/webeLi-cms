<?php

declare(strict_types=1);

namespace App\Domain\Seo;

final class SeoMetadata
{
    public function __construct(
        public readonly ?string $metaTitle,
        public readonly ?string $metaDescription = null,
        public readonly ?string $canonicalUrl = null,
        public readonly ?string $metaRobots = 'index,follow',
        public readonly ?string $jsonLd = null,
        public readonly ?string $resourceType = null,
        public readonly ?int $resourceId = null,
        public readonly ?string $languageCode = null,
        public readonly ?int $seoScore = null,
        public readonly ?string $ogTitle = null,
        public readonly ?string $ogDescription = null,
        public readonly ?int $ogImageMediaId = null,
        public readonly ?string $twitterTitle = null,
        public readonly ?string $twitterDescription = null,
        public readonly ?int $twitterImageMediaId = null,
        public readonly ?string $hreflangCode = null,
        public readonly ?array $source = null
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            isset($row['meta_title']) ? (string) $row['meta_title'] : null,
            isset($row['meta_description']) ? (string) $row['meta_description'] : null,
            isset($row['canonical_url']) ? (string) $row['canonical_url'] : null,
            isset($row['meta_robots']) ? (string) $row['meta_robots'] : 'index,follow',
            isset($row['json_ld']) ? (string) $row['json_ld'] : null,
            isset($row['resource_type']) ? (string) $row['resource_type'] : null,
            isset($row['resource_id']) ? (int) $row['resource_id'] : null,
            isset($row['language_code']) ? (string) $row['language_code'] : null,
            isset($row['seo_score']) && $row['seo_score'] !== null ? (int) $row['seo_score'] : null,
            isset($row['og_title']) ? (string) $row['og_title'] : null,
            isset($row['og_description']) ? (string) $row['og_description'] : null,
            isset($row['og_image_media_id']) && $row['og_image_media_id'] !== null ? (int) $row['og_image_media_id'] : null,
            isset($row['twitter_title']) ? (string) $row['twitter_title'] : null,
            isset($row['twitter_description']) ? (string) $row['twitter_description'] : null,
            isset($row['twitter_image_media_id']) && $row['twitter_image_media_id'] !== null ? (int) $row['twitter_image_media_id'] : null,
            isset($row['hreflang_code']) ? (string) $row['hreflang_code'] : null,
            isset($row['source']) && is_array($row['source']) ? $row['source'] : null,
        );
    }

    public function hasMetaTitle(): bool
    {
        return $this->metaTitle !== null && trim($this->metaTitle) !== '';
    }

    public function hasMetaDescription(): bool
    {
        return $this->metaDescription !== null && trim($this->metaDescription) !== '';
    }

    public function hasCanonicalUrl(): bool
    {
        return $this->canonicalUrl !== null && trim($this->canonicalUrl) !== '';
    }

    public function isIndexable(): bool
    {
        return !str_contains(strtolower((string) $this->metaRobots), 'noindex');
    }

    public function hasStructuredData(): bool
    {
        return $this->jsonLd !== null && trim($this->jsonLd) !== '';
    }

    /** @return list<string> */
    public function basicAudit(): array
    {
        $issues = [];
        if (!$this->hasMetaTitle()) {
            $issues[] = 'missing_meta_title';
        }
        if (!$this->hasMetaDescription()) {
            $issues[] = 'missing_meta_description';
        }
        if ($this->metaTitle !== null && mb_strlen($this->metaTitle) > 60) {
            $issues[] = 'meta_title_too_long';
        }
        if ($this->metaDescription !== null && mb_strlen($this->metaDescription) > 160) {
            $issues[] = 'meta_description_too_long';
        }
        if ($this->jsonLd !== null && trim($this->jsonLd) !== '') {
            json_decode($this->jsonLd, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $issues[] = 'invalid_json_ld';
            }
        }
        return $issues;
    }

    public function computedScore(): int
    {
        if ($this->seoScore !== null) {
            return max(0, min(100, $this->seoScore));
        }
        $score = 100;
        foreach ($this->basicAudit() as $issue) {
            $score -= match ($issue) {
                'missing_meta_title', 'missing_meta_description' => 25,
                'meta_title_too_long', 'meta_description_too_long' => 10,
                'invalid_json_ld' => 15,
                default => 5,
            };
        }
        return max(0, $score);
    }

    public function toArray(): array
    {
        return [
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
            'language_code' => $this->languageCode,
            'meta_title' => $this->metaTitle,
            'meta_description' => $this->metaDescription,
            'canonical_url' => $this->canonicalUrl,
            'meta_robots' => $this->metaRobots,
            'json_ld' => $this->jsonLd,
            'seo_score' => $this->computedScore(),
            'og_title' => $this->ogTitle ?? $this->metaTitle,
            'og_description' => $this->ogDescription ?? $this->metaDescription,
            'og_image_media_id' => $this->ogImageMediaId,
            'twitter_title' => $this->twitterTitle ?? $this->ogTitle ?? $this->metaTitle,
            'twitter_description' => $this->twitterDescription ?? $this->ogDescription ?? $this->metaDescription,
            'twitter_image_media_id' => $this->twitterImageMediaId ?? $this->ogImageMediaId,
            'hreflang_code' => $this->hreflangCode,
            'source' => $this->source,
            'is_indexable' => $this->isIndexable(),
            'issues' => $this->basicAudit(),
        ];
    }
}
