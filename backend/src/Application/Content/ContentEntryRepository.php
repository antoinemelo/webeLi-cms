<?php

declare(strict_types=1);

namespace App\Application\Content;

use App\Domain\Content\ContentEntry;

interface ContentEntryRepository
{
    public function findById(int $entryId): ?ContentEntry;

    /** @return array<string,mixed>|null */
    public function findRowById(int $entryId): ?array;

    /** @return array<string,mixed>|null Row enriched with content type data. */
    public function findProjectionRowById(int $entryId): ?array;

    public function findIdBySiteAndEntryKey(int $siteId, string $entryKey): ?int;

    public function createDraft(int $siteId, int $contentTypeId, string $entryKey, int $userId): int;

    public function setWorkingRevision(int $entryId, string $languageCode, int $revisionId, int $userId): void;

    public function workingRevisionId(int $entryId, string $languageCode): ?int;

    /** @return array<string,mixed>|null */
    public function publicationRow(int $entryId, string $languageCode): ?array;

    /** @return list<array<string,mixed>> */
    public function listPublicationRows(int $entryId): array;

    public function publishRevisionForLanguage(int $entryId, string $languageCode, int $revisionId, int $userId, string $publishedAt): void;

    public function unpublishLanguage(int $entryId, string $languageCode, int $userId, string $unpublishedAt): void;

    public function refreshAggregateStatus(int $entryId): void;
}
