<?php

declare(strict_types=1);

namespace App\Application\Content;

interface SearchDocumentRepository
{
    public function deleteForResource(string $resourceType, int $resourceId): void;

    public function saveContentEntryDocument(int $siteId, int $entryId, string $languageCode, string $path, string $title, string $summary, string $searchText, ?int $sourcePublishedRevisionId = null, ?string $sourceRevisionChecksumSha256 = null): void;
}
