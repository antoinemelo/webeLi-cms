<?php

declare(strict_types=1);

namespace App\Application\Seo;

interface SeoMetadataRepository
{
    public function deleteForResource(string $resourceType, int $resourceId): void;

    public function saveContentEntryMetadata(
        int $siteId,
        int $entryId,
        string $languageCode,
        string $title,
        string $description,
        string $robots,
        string $canonicalUrl,
        int $score,
        ?int $sourcePublishedRevisionId = null,
        ?string $sourceRevisionChecksumSha256 = null,
        ?int $ogImageMediaId = null,
        ?int $twitterImageMediaId = null,
        ?string $ogTitle = null,
        ?string $ogDescription = null,
        ?string $twitterTitle = null,
        ?string $twitterDescription = null,
        ?string $hreflangCode = null,
        ?string $jsonLd = null
    ): void;
}
