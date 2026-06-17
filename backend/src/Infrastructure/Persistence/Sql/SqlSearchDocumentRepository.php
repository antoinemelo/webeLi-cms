<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sql;

use App\Application\Content\SearchDocumentRepository;
use App\Core\Database;

final class SqlSearchDocumentRepository implements SearchDocumentRepository
{
    public function __construct(private readonly Database $db) {}

    public function deleteForResource(string $resourceType, int $resourceId): void
    {
        $this->db->run('DELETE FROM search_documents WHERE resource_type = :resource_type AND resource_id = :resource_id', [
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
        ]);
    }

    public function saveContentEntryDocument(int $siteId, int $entryId, string $languageCode, string $path, string $title, string $summary, string $searchText, ?int $sourcePublishedRevisionId = null, ?string $sourceRevisionChecksumSha256 = null): void
    {
        $this->db->run("INSERT INTO search_documents(site_id, resource_type, resource_id, language_code, path, title, summary, search_text, source_published_revision_id, source_revision_checksum_sha256, updated_at)
            VALUES(:site_id, 'content_entry', :resource_id, :language_code, :path, :title, :summary, :search_text, :source_published_revision_id, :source_revision_checksum_sha256, :updated_at)
            ON CONFLICT(site_id, resource_type, resource_id, language_code) DO UPDATE SET
                path = excluded.path,
                title = excluded.title,
                summary = excluded.summary,
                search_text = excluded.search_text,
                source_published_revision_id = excluded.source_published_revision_id,
                source_revision_checksum_sha256 = excluded.source_revision_checksum_sha256,
                updated_at = excluded.updated_at", [
            'site_id' => $siteId,
            'resource_id' => $entryId,
            'language_code' => $languageCode,
            'path' => $path,
            'title' => $title,
            'summary' => $summary,
            'search_text' => $searchText,
            'source_published_revision_id' => $sourcePublishedRevisionId,
            'source_revision_checksum_sha256' => $sourceRevisionChecksumSha256,
            'updated_at' => now_utc(),
        ]);
    }
}
