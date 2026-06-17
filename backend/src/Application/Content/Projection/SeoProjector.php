<?php

declare(strict_types=1);

namespace App\Application\Content\Projection;

use App\Application\Publication\PublishedProjection;
use App\Application\Seo\RunSeoAudit;
use App\Application\Seo\SeoMetadataRepository;

final class SeoProjector
{
    public function __construct(
        private readonly array $config,
        private readonly SeoMetadataRepository $seoMetadata,
        private readonly RunSeoAudit $seoAudit,
    ) {}

    /**
     * @param array<string,mixed> $entry
     * @param array<string,mixed> $document
     * @param list<array<string,mixed>> $localizations
     * @param array<string,string> $pathsByLanguage
     */
    public function project(array $entry, array $document, array $localizations, array $pathsByLanguage): void
    {
        foreach ($localizations as $loc) {
            $languageCode = (string) $loc['language_code'];
            $path = $pathsByLanguage[$languageCode] ?? null;
            if ($path === null) {
                continue;
            }

            $slug = trim((string) ($loc['draft_slug'] ?? ''));
            $seoTitle = trim((string) ($document['seo']['meta_title'] ?? $loc['title'] ?? ''));
            $seoDescription = trim((string) ($document['seo']['meta_description'] ?? ''));
            $seoRobots = (string) ($document['seo']['meta_robots'] ?? ($this->config['seo']['default_meta_robots'] ?? 'index,follow'));

            $this->seoMetadata->saveContentEntryMetadata(
                (int) $entry['site_id'],
                (int) $entry['id'],
                $languageCode,
                $seoTitle,
                $seoDescription,
                $seoRobots,
                $path,
                $this->seoAudit->score($seoTitle, $seoDescription, $slug),
                null,
                null,
                is_numeric($document['seo']['og_image_media_id'] ?? null) ? (int) $document['seo']['og_image_media_id'] : null,
                is_numeric($document['seo']['twitter_image_media_id'] ?? null) ? (int) $document['seo']['twitter_image_media_id'] : null
            );
            $this->seoAudit->recordContentIssues((int) $entry['site_id'], (int) $entry['id'], $languageCode, $loc, $seoTitle, $seoDescription, $slug);
        }
    }

    public function projectPublished(PublishedProjection $projection): void
    {
        $this->seoMetadata->saveContentEntryMetadata(
            $projection->siteId,
            $projection->resourceId,
            $projection->languageCode,
            (string) $projection->seo->metaTitle,
            (string) $projection->seo->metaDescription,
            (string) $projection->seo->metaRobots,
            $projection->path,
            $projection->seo->computedScore(),
            $projection->sourcePublishedRevisionId,
            $projection->sourceRevisionChecksumSha256,
            $projection->seo->ogImageMediaId,
            $projection->seo->twitterImageMediaId,
            $projection->seo->ogTitle,
            $projection->seo->ogDescription,
            $projection->seo->twitterTitle,
            $projection->seo->twitterDescription,
            $projection->seo->hreflangCode,
            $projection->seo->jsonLd
        );
        $this->seoAudit->recordContentIssues(
            $projection->siteId,
            $projection->resourceId,
            $projection->languageCode,
            $projection->localizationRow(),
            (string) $projection->seo->metaTitle,
            (string) $projection->seo->metaDescription,
            $projection->slug()
        );
    }
}
