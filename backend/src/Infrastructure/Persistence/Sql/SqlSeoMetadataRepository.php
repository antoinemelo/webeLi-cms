<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sql;

use App\Application\Seo\SeoMetadataRepository;
use App\Core\Database;

final class SqlSeoMetadataRepository implements SeoMetadataRepository
{
    public function __construct(private readonly Database $db) {}

    public function deleteForResource(string $resourceType, int $resourceId): void
    {
        $this->db->run('DELETE FROM seo_metadata WHERE resource_type = :resource_type AND resource_id = :resource_id', [
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
        ]);
    }

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
    ): void {
        $this->db->run("INSERT INTO seo_metadata(
            site_id, resource_type, resource_id, language_code, meta_title, meta_description, meta_robots, canonical_url,
            og_title, og_description, og_image_media_id, twitter_title, twitter_description, twitter_image_media_id,
            hreflang_code, json_ld, seo_score, source_published_revision_id, source_revision_checksum_sha256, updated_at
        ) VALUES(
            :site_id, 'content_entry', :resource_id, :language_code, :meta_title, :meta_description, :meta_robots, :canonical_url,
            :og_title, :og_description, :og_image_media_id, :twitter_title, :twitter_description, :twitter_image_media_id,
            :hreflang_code, :json_ld, :seo_score, :source_published_revision_id, :source_revision_checksum_sha256, :updated_at
        ) ON CONFLICT(site_id, resource_type, resource_id, language_code) DO UPDATE SET
            meta_title = excluded.meta_title,
            meta_description = excluded.meta_description,
            meta_robots = excluded.meta_robots,
            canonical_url = excluded.canonical_url,
            og_title = excluded.og_title,
            og_description = excluded.og_description,
            og_image_media_id = excluded.og_image_media_id,
            twitter_title = excluded.twitter_title,
            twitter_description = excluded.twitter_description,
            twitter_image_media_id = excluded.twitter_image_media_id,
            hreflang_code = excluded.hreflang_code,
            json_ld = excluded.json_ld,
            seo_score = excluded.seo_score,
            source_published_revision_id = excluded.source_published_revision_id,
            source_revision_checksum_sha256 = excluded.source_revision_checksum_sha256,
            updated_at = excluded.updated_at", [
            'site_id' => $siteId,
            'resource_id' => $entryId,
            'language_code' => $languageCode,
            'meta_title' => $title,
            'meta_description' => $description,
            'meta_robots' => $robots,
            'canonical_url' => $canonicalUrl,
            'og_title' => $ogTitle ?? $title,
            'og_description' => $ogDescription ?? $description,
            'og_image_media_id' => $ogImageMediaId && $ogImageMediaId > 0 ? $ogImageMediaId : null,
            'twitter_title' => $twitterTitle ?? $ogTitle ?? $title,
            'twitter_description' => $twitterDescription ?? $ogDescription ?? $description,
            'twitter_image_media_id' => $twitterImageMediaId && $twitterImageMediaId > 0 ? $twitterImageMediaId : null,
            'hreflang_code' => $hreflangCode ?? $languageCode,
            'json_ld' => $jsonLd,
            'seo_score' => $score,
            'source_published_revision_id' => $sourcePublishedRevisionId,
            'source_revision_checksum_sha256' => $sourceRevisionChecksumSha256,
            'updated_at' => now_utc(),
        ]);
    }
}
