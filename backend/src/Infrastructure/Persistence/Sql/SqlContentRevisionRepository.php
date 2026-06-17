<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sql;

use App\Application\Content\ContentRevisionRepository;
use App\Core\Database;
use App\Domain\Content\ContentRevision;

final class SqlContentRevisionRepository implements ContentRevisionRepository
{
    public function __construct(private readonly Database $db) {}

    public function findById(int $revisionId): ?ContentRevision
    {
        $row = $this->db->one('SELECT * FROM revisions WHERE id = :id LIMIT 1', ['id' => $revisionId]);
        return $row ? ContentRevision::fromArray($row) : null;
    }

    public function nextNumberForEntry(int $entryId): int
    {
        $row = $this->db->one("SELECT MAX(revision_number) AS max_rev FROM revisions WHERE resource_type = 'content_entry' AND resource_id = :entry_id", ['entry_id' => $entryId]);
        return (int) (($row['max_rev'] ?? 0)) + 1;
    }

    public function createDraftForEntry(int $entryId, string $languageCode, int $revisionNumber, array $document, string $label, string $summary, string $changeNotes, int $userId): int
    {
        $json = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new \RuntimeException('Impossible de sérialiser le document de révision.');
        }
        $now = now_utc();
        [$blueprintId, $blueprintVersionId] = $this->resolveBlueprintBinding($entryId);
        $this->db->run("INSERT INTO revisions(resource_type, resource_id, blueprint_id, blueprint_version_id, revision_number, language_code, workflow_status, document_json, checksum_sha256, revision_label, summary, change_notes, created_by_iam_user_id, updated_by_iam_user_id, created_at, updated_at) VALUES('content_entry', :resource_id, :blueprint_id, :blueprint_version_id, :revision_number, :language_code, 'draft', :document_json, :checksum, :revision_label, :summary, :change_notes, :created_by, :updated_by, :created_at, :updated_at)", [
            'resource_id' => $entryId,
            'blueprint_id' => $blueprintId,
            'blueprint_version_id' => $blueprintVersionId,
            'revision_number' => $revisionNumber,
            'language_code' => $languageCode,
            'document_json' => $json,
            'checksum' => hash('sha256', $json),
            'revision_label' => $label,
            'summary' => $summary,
            'change_notes' => $changeNotes,
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $this->db->lastInsertId();
    }

    public function markPublished(int $revisionId, int $userId, string $publishedAt): void
    {
        $this->db->run('UPDATE revisions SET workflow_status = :status, published_by_iam_user_id = :user_id, published_at = :published_at, updated_at = :updated_at WHERE id = :id', [
            'status' => ContentRevision::STATUS_PUBLISHED,
            'user_id' => $userId,
            'published_at' => $publishedAt,
            'updated_at' => now_utc(),
            'id' => $revisionId,
        ]);
    }

    public function markOtherPublishedRevisionsAsSuperseded(int $entryId, string $languageCode, int $revisionIdToKeep, string $supersededAt): void
    {
        $this->db->run("UPDATE revisions
            SET workflow_status = :status, updated_at = :updated_at
            WHERE resource_type = 'content_entry'
              AND resource_id = :entry_id
              AND language_code = :language
              AND id <> :revision_id
              AND workflow_status = :published_status", [
            'status' => ContentRevision::STATUS_SUPERSEDED,
            'updated_at' => $supersededAt,
            'entry_id' => $entryId,
            'language' => $languageCode,
            'revision_id' => $revisionIdToKeep,
            'published_status' => ContentRevision::STATUS_PUBLISHED,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function listForEntry(int $entryId, string $languageCode): array
    {
        return $this->db->all("SELECT * FROM revisions
            WHERE resource_type = 'content_entry'
              AND resource_id = :entry_id
              AND language_code = :language
            ORDER BY revision_number DESC, id DESC", [
            'entry_id' => $entryId,
            'language' => $languageCode,
        ]);
    }

    /** @return array<string,mixed>|null */
    public function findForEntry(int $entryId, string $languageCode, int $revisionId): ?array
    {
        return $this->db->one("SELECT * FROM revisions
            WHERE id = :id
              AND resource_type = 'content_entry'
              AND resource_id = :entry_id
              AND language_code = :language
            LIMIT 1", [
            'id' => $revisionId,
            'entry_id' => $entryId,
            'language' => $languageCode,
        ]);
    }

    /** @param array<string,mixed> $sourceRevision */
    public function createRestoredDraftForEntry(int $entryId, string $languageCode, array $sourceRevision, int $userId): int
    {
        $sourceId = (int) ($sourceRevision['id'] ?? 0);
        $documentJson = (string) ($sourceRevision['document_json'] ?? '{}');
        $document = json_decode($documentJson, true);
        if (!is_array($document)) {
            throw new \RuntimeException('Document source de révision invalide.');
        }
        $json = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new \RuntimeException('Impossible de sérialiser la révision restaurée.');
        }
        $now = now_utc();
        $sourceStatus = (string) ($sourceRevision['workflow_status'] ?? '');
        [$resolvedBlueprintId, $resolvedVersionId] = $this->resolveBlueprintBinding($entryId);
        $blueprintId = (int) ($sourceRevision['blueprint_id'] ?? $resolvedBlueprintId);
        $blueprintVersionId = (int) ($sourceRevision['blueprint_version_id'] ?? $resolvedVersionId);
        $this->db->run("INSERT INTO revisions(
                resource_type, resource_id, blueprint_id, blueprint_version_id, revision_number, language_code, workflow_status,
                document_json, checksum_sha256, base_revision_id, source_published_revision_id,
                created_from_event, revision_label, summary, change_notes,
                created_by_iam_user_id, updated_by_iam_user_id, created_at, updated_at
            ) VALUES(
                'content_entry', :resource_id, :blueprint_id, :blueprint_version_id, :revision_number, :language_code, 'draft',
                :document_json, :checksum, :base_revision_id, :source_published_revision_id,
                'revision_restore', :revision_label, :summary, :change_notes,
                :created_by, :updated_by, :created_at, :updated_at
            )", [
            'resource_id' => $entryId,
            'blueprint_id' => $blueprintId,
            'blueprint_version_id' => $blueprintVersionId,
            'revision_number' => $this->nextNumberForEntry($entryId),
            'language_code' => $languageCode,
            'document_json' => $json,
            'checksum' => hash('sha256', $json),
            'base_revision_id' => $sourceId > 0 ? $sourceId : null,
            'source_published_revision_id' => $sourceStatus === ContentRevision::STATUS_PUBLISHED && $sourceId > 0 ? $sourceId : null,
            'revision_label' => sprintf('Restored from revision #%d', (int) ($sourceRevision['revision_number'] ?? 0)),
            'summary' => (string) ($sourceRevision['summary'] ?? 'Révision restaurée'),
            'change_notes' => sprintf('Restaurée depuis la révision #%d.', (int) ($sourceRevision['revision_number'] ?? 0)),
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $this->db->lastInsertId();
    }

    /** @return array{deleted:int,kept:int,published_revision_id:int,published_revision_number:int,kept_revision_ids:list<int>,deleted_revision_ids:list<int>,revisions:list<array<string,mixed>>} */
    public function pruneBeforePublished(int $entryId, string $languageCode): array
    {
        $published = $this->db->one("SELECT r.*
            FROM content_entry_publications cep
            JOIN revisions r ON r.id = cep.published_revision_id
            WHERE cep.entry_id = :entry_id
              AND cep.language_code = :language
              AND cep.workflow_status = 'published'
              AND r.resource_type = 'content_entry'
              AND r.resource_id = cep.entry_id
              AND r.language_code = cep.language_code
            LIMIT 1", [
            'entry_id' => $entryId,
            'language' => $languageCode,
        ]);
        if (!$published) {
            return [
                'deleted' => 0,
                'kept' => 0,
                'published_revision_id' => 0,
                'published_revision_number' => 0,
                'kept_revision_ids' => [],
                'deleted_revision_ids' => [],
                'revisions' => [],
            ];
        }

        $publishedId = (int) $published['id'];
        $publishedNumber = (int) $published['revision_number'];
        $protectedIds = [$publishedId => true];

        $working = $this->db->one("SELECT working_revision_id
            FROM content_entry_working_revisions
            WHERE entry_id = :entry_id
              AND language_code = :language
            LIMIT 1", [
            'entry_id' => $entryId,
            'language' => $languageCode,
        ]);
        $workingId = (int) ($working['working_revision_id'] ?? 0);
        if ($workingId > 0) {
            $protectedIds[$workingId] = true;
        }

        foreach (['routes', 'seo_metadata', 'search_documents', 'public_content_snapshots'] as $table) {
            $rows = $this->db->all("SELECT source_published_revision_id AS id
                FROM {$table}
                WHERE resource_type = 'content_entry'
                  AND resource_id = :entry_id
                  AND language_code = :language
                  AND source_published_revision_id IS NOT NULL", [
                'entry_id' => $entryId,
                'language' => $languageCode,
            ]);
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id > 0) {
                    $protectedIds[$id] = true;
                }
            }
        }

        $rows = $this->db->all("SELECT id FROM revisions
            WHERE resource_type = 'content_entry'
              AND resource_id = :entry_id
              AND language_code = :language
              AND revision_number < :published_number
            ORDER BY revision_number ASC, id ASC", [
            'entry_id' => $entryId,
            'language' => $languageCode,
            'published_number' => $publishedNumber,
        ]);

        $candidates = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0 && !isset($protectedIds[$id])) {
                $candidates[] = $id;
            }
        }

        $deletedIds = [];
        $this->db->transaction(function () use ($candidates, &$deletedIds): void {
            foreach ($candidates as $id) {
                $this->db->run('UPDATE revisions SET base_revision_id = NULL WHERE base_revision_id = :id', ['id' => $id]);
                $this->db->run('UPDATE revisions SET source_published_revision_id = NULL WHERE source_published_revision_id = :id', ['id' => $id]);
                $this->db->run('DELETE FROM revisions WHERE id = :id', ['id' => $id]);
                $deletedIds[] = $id;
            }
        });

        $revisions = $this->listForEntry($entryId, $languageCode);
        $keptRevisionIds = array_values(array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $revisions));

        return [
            'deleted' => count($deletedIds),
            'kept' => count($revisions),
            'published_revision_id' => $publishedId,
            'published_revision_number' => $publishedNumber,
            'kept_revision_ids' => $keptRevisionIds,
            'deleted_revision_ids' => $deletedIds,
            'revisions' => $revisions,
        ];
    }


    /** @return array{0:int,1:int} */
    private function resolveBlueprintBinding(int $entryId): array
    {
        $row = $this->db->one(
            "SELECT b.id AS blueprint_id, b.active_version_id AS blueprint_version_id
             FROM content_entries ce
             JOIN content_types ct ON ct.id = ce.content_type_id
             JOIN blueprints b ON b.resource_type = 'content_type'
                AND (b.legacy_content_type_id = ct.id OR b.blueprint_key = ct.type_key)
                AND (b.site_id = ce.site_id OR b.site_id IS NULL)
             JOIN blueprint_versions bv ON bv.id = b.active_version_id AND bv.blueprint_id = b.id AND bv.is_active = 1
             WHERE ce.id = :entry_id
             ORDER BY b.site_id IS NOT NULL DESC
             LIMIT 1",
            ['entry_id' => $entryId],
        );
        $blueprintId = (int) ($row['blueprint_id'] ?? 0);
        $versionId = (int) ($row['blueprint_version_id'] ?? 0);
        if ($blueprintId < 1 || $versionId < 1) {
            throw new \RuntimeException(sprintf('Aucune version active de blueprint ne correspond au contenu %d.', $entryId));
        }
        return [$blueprintId, $versionId];
    }

}
