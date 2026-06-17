<?php

declare(strict_types=1);

namespace App\Application\Search;

interface PublicSearchReadRepository
{
    public function searchDocuments(string $query, string $languageCode, int $siteId, array $filters = []): array;
}
