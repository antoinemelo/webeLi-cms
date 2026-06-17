<?php

declare(strict_types=1);

namespace App\Application\Content;

use App\Domain\Content\ContentRevision;

interface ContentRevisionRepository
{
    public function findById(int $revisionId): ?ContentRevision;

    public function nextNumberForEntry(int $entryId): int;

    /** @param array<string,mixed> $document */
    public function createDraftForEntry(int $entryId, string $languageCode, int $revisionNumber, array $document, string $label, string $summary, string $changeNotes, int $userId): int;

    public function markPublished(int $revisionId, int $userId, string $publishedAt): void;

    public function markOtherPublishedRevisionsAsSuperseded(int $entryId, string $languageCode, int $revisionIdToKeep, string $supersededAt): void;

    /** @return list<array<string,mixed>> */
    public function listForEntry(int $entryId, string $languageCode): array;

    /** @return array<string,mixed>|null */
    public function findForEntry(int $entryId, string $languageCode, int $revisionId): ?array;

    /** @param array<string,mixed> $sourceRevision */
    public function createRestoredDraftForEntry(int $entryId, string $languageCode, array $sourceRevision, int $userId): int;

    /** @return array{deleted:int,kept:int,published_revision_id:int,published_revision_number:int,kept_revision_ids:list<int>,deleted_revision_ids:list<int>,revisions:list<array<string,mixed>>} */
    public function pruneBeforePublished(int $entryId, string $languageCode): array;
}

