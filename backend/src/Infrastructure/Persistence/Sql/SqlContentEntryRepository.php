<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sql;

use App\Application\Content\ContentEntryRepository;
use App\Application\Content\EditorialStatusResolver;
use App\Core\Database;
use App\Domain\Content\ContentEntry;

final class SqlContentEntryRepository implements ContentEntryRepository
{
    public function __construct(private readonly Database $db) {}

    public function findById(int $entryId): ?ContentEntry
    {
        $row = $this->findProjectionRowById($entryId);
        return $row ? ContentEntry::fromArray($row) : null;
    }

    public function findRowById(int $entryId): ?array
    {
        return $this->db->one('SELECT * FROM content_entries WHERE id = :id LIMIT 1', ['id' => $entryId]);
    }

    public function findProjectionRowById(int $entryId): ?array
    {
        return $this->db->one(
            'SELECT ce.*, ct.type_key, ct.singular_label, ct.frontend_template
             FROM content_entries ce
             JOIN content_types ct ON ct.id = ce.content_type_id
             WHERE ce.id = :id LIMIT 1',
            ['id' => $entryId]
        );
    }

    public function findIdBySiteAndEntryKey(int $siteId, string $entryKey): ?int
    {
        $row = $this->db->one(
            'SELECT id FROM content_entries WHERE site_id = :site_id AND entry_key = :entry_key LIMIT 1',
            ['site_id' => $siteId, 'entry_key' => $entryKey]
        );
        return $row ? (int) $row['id'] : null;
    }

    private function siteIdForEntry(int $entryId): int
    {
        $row = $this->db->one('SELECT site_id FROM content_entries WHERE id = :id LIMIT 1', ['id' => $entryId]);
        if (!$row) {
            throw new \RuntimeException('Cannot resolve site_id for content entry ' . $entryId);
        }
        return (int) $row['site_id'];
    }

    public function createDraft(int $siteId, int $contentTypeId, string $entryKey, int $userId): int
    {
        $now = now_utc();
        $this->db->run("INSERT INTO content_entries(site_id, content_type_id, entry_key, author_iam_user_id, owner_iam_user_id, created_by_iam_user_id, updated_by_iam_user_id, status, workflow_state, created_at, updated_at) VALUES(:site_id, :content_type_id, :entry_key, :author_iam_user_id, :owner_iam_user_id, :created_by_iam_user_id, :updated_by_iam_user_id, 'draft', 'draft', :created_at, :updated_at)", [
            'site_id' => $siteId,
            'content_type_id' => $contentTypeId,
            'entry_key' => $entryKey,
            'author_iam_user_id' => $userId,
            'owner_iam_user_id' => $userId,
            'created_by_iam_user_id' => $userId,
            'updated_by_iam_user_id' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $this->db->lastInsertId();
    }

    public function setWorkingRevision(int $entryId, string $languageCode, int $revisionId, int $userId): void
    {
        $now = now_utc();
        $siteId = $this->siteIdForEntry($entryId);
        $existing = $this->db->one('SELECT id FROM content_entry_working_revisions WHERE entry_id = :entry_id AND language_code = :language LIMIT 1', [
            'entry_id' => $entryId,
            'language' => $languageCode,
        ]);
        if ($existing) {
            $this->db->run("UPDATE content_entry_working_revisions
                SET site_id = :site_id, working_revision_id = :revision_id, workflow_status = 'draft', updated_by_iam_user_id = :user_id, updated_at = :updated_at
                WHERE id = :id", [
                'site_id' => $siteId,
                'revision_id' => $revisionId,
                'user_id' => $userId,
                'updated_at' => $now,
                'id' => (int) $existing['id'],
            ]);
        } else {
            $this->db->run("INSERT INTO content_entry_working_revisions(site_id, entry_id, language_code, working_revision_id, workflow_status, updated_by_iam_user_id, created_at, updated_at)
                VALUES(:site_id, :entry_id, :language, :revision_id, 'draft', :user_id, :created_at, :updated_at)", [
                'site_id' => $siteId,
                'entry_id' => $entryId,
                'language' => $languageCode,
                'revision_id' => $revisionId,
                'user_id' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $statusPair = EditorialStatusResolver::preservePublicAggregateOnDraftSave($this->findRowById($entryId));
        $this->db->run('UPDATE content_entries SET status = :status, workflow_state = :workflow_state, updated_by_iam_user_id = :updated_by_iam_user_id, updated_at = :updated_at WHERE id = :id', [
            'status' => $statusPair['status'],
            'workflow_state' => $statusPair['workflow_state'],
            'updated_by_iam_user_id' => $userId,
            'updated_at' => $now,
            'id' => $entryId,
        ]);
    }

    public function workingRevisionId(int $entryId, string $languageCode): ?int
    {
        $row = $this->db->one('SELECT working_revision_id FROM content_entry_working_revisions WHERE entry_id = :entry_id AND language_code = :language LIMIT 1', [
            'entry_id' => $entryId,
            'language' => $languageCode,
        ]);
        return $row ? (int) $row['working_revision_id'] : null;
    }

    public function publicationRow(int $entryId, string $languageCode): ?array
    {
        return $this->db->one('SELECT * FROM content_entry_publications WHERE entry_id = :entry_id AND language_code = :language LIMIT 1', [
            'entry_id' => $entryId,
            'language' => $languageCode,
        ]);
    }

    public function listPublicationRows(int $entryId): array
    {
        return $this->db->all("SELECT * FROM content_entry_publications WHERE entry_id = :entry_id AND workflow_status = 'published' AND published_revision_id IS NOT NULL ORDER BY language_code", ['entry_id' => $entryId]);
    }

    public function publishRevisionForLanguage(int $entryId, string $languageCode, int $revisionId, int $userId, string $publishedAt): void
    {
        $siteId = $this->siteIdForEntry($entryId);
        $existing = $this->publicationRow($entryId, $languageCode);
        if ($existing) {
            $this->db->run("UPDATE content_entry_publications
                SET site_id = :site_id, published_revision_id = :revision_id, workflow_status = 'published', published_by_iam_user_id = :user_id, published_at = :published_at, updated_at = :updated_at
                WHERE id = :id", [
                'site_id' => $siteId,
                'revision_id' => $revisionId,
                'user_id' => $userId,
                'published_at' => $publishedAt,
                'updated_at' => $publishedAt,
                'id' => (int) $existing['id'],
            ]);
        } else {
            $this->db->run("INSERT INTO content_entry_publications(site_id, entry_id, language_code, published_revision_id, workflow_status, published_by_iam_user_id, published_at, updated_at)
                VALUES(:site_id, :entry_id, :language, :revision_id, 'published', :user_id, :published_at, :updated_at)", [
                'site_id' => $siteId,
                'entry_id' => $entryId,
                'language' => $languageCode,
                'revision_id' => $revisionId,
                'user_id' => $userId,
                'published_at' => $publishedAt,
                'updated_at' => $publishedAt,
            ]);
        }

        $this->db->run('UPDATE content_entry_working_revisions SET workflow_status = :status, updated_at = :updated_at WHERE entry_id = :entry_id AND language_code = :language', [
            'status' => ContentEntry::STATUS_PUBLISHED,
            'updated_at' => $publishedAt,
            'entry_id' => $entryId,
            'language' => $languageCode,
        ]);

        $this->db->run('UPDATE content_entries SET published_by_iam_user_id = :user_id, updated_by_iam_user_id = :user_id, updated_at = :updated_at WHERE id = :id', [
            'user_id' => $userId,
            'updated_at' => $publishedAt,
            'id' => $entryId,
        ]);

        $this->refreshAggregateStatus($entryId);
    }

    public function unpublishLanguage(int $entryId, string $languageCode, int $userId, string $unpublishedAt): void
    {
        $this->db->run("UPDATE content_entry_publications
            SET workflow_status = 'unpublished', unpublished_by_iam_user_id = :user_id, unpublished_at = :unpublished_at, updated_at = :updated_at
            WHERE entry_id = :entry_id AND language_code = :language", [
            'user_id' => $userId,
            'unpublished_at' => $unpublishedAt,
            'updated_at' => $unpublishedAt,
            'entry_id' => $entryId,
            'language' => $languageCode,
        ]);
        $this->db->run("UPDATE content_entry_working_revisions
            SET workflow_status = 'draft', updated_at = :updated_at
            WHERE entry_id = :entry_id AND language_code = :language", [
            'updated_at' => $unpublishedAt,
            'entry_id' => $entryId,
            'language' => $languageCode,
        ]);
        $this->db->run('UPDATE content_entries SET updated_by_iam_user_id = :user_id, updated_at = :updated_at WHERE id = :id', [
            'user_id' => $userId,
            'updated_at' => $unpublishedAt,
            'id' => $entryId,
        ]);
        $this->refreshAggregateStatus($entryId);
    }

    public function refreshAggregateStatus(int $entryId): void
    {
        $activePublication = $this->db->one(
            "SELECT published_by_iam_user_id, published_at
             FROM content_entry_publications
             WHERE entry_id = :entry_id
               AND workflow_status = 'published'
               AND published_revision_id IS NOT NULL
             ORDER BY published_at DESC, id DESC
             LIMIT 1",
            ['entry_id' => $entryId]
        );
        $hasActivePublication = $activePublication !== null;
        $now = now_utc();

        $statusPair = EditorialStatusResolver::aggregateFromPublication($hasActivePublication);
        $this->db->run('UPDATE content_entries
            SET status = :status,
                workflow_state = :workflow_state,
                published_by_iam_user_id = :published_by_iam_user_id,
                published_at = :published_at,
                updated_at = :updated_at
            WHERE id = :id', [
            'status' => $statusPair['status'],
            'workflow_state' => $statusPair['workflow_state'],
            'published_by_iam_user_id' => $hasActivePublication ? ($activePublication['published_by_iam_user_id'] ?? null) : null,
            'published_at' => $hasActivePublication ? ($activePublication['published_at'] ?? null) : null,
            'updated_at' => $now,
            'id' => $entryId,
        ]);
    }
}
