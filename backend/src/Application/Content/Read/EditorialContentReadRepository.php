<?php

declare(strict_types=1);

namespace App\Application\Content\Read;

interface EditorialContentReadRepository
{
    public function listEntries(int $siteId, ?string $languageCode = null): array;
    public function listEntriesPage(array $filters): array;
    public function getWorkingEntryAggregate(int $entryId, string $languageCode): ?array;
    public function previewAggregate(int $entryId, string $languageCode, ?int $revisionId = null, ?int $siteId = null): ?array;
    public function listContentTypes(): array;
    public function outboxStats(): array;
}
