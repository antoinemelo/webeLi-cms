<?php

declare(strict_types=1);

namespace App\Application\Frontend;

interface TaxonomyReadRepository
{
    /** @return list<array<string,mixed>> */
    public function listTaxonomies(int $siteId): array;

    /** @return list<array<string,mixed>> */
    public function listTermsForSite(int $siteId, string $languageCode): array;

    /** @return list<array<string,mixed>> */
    public function listTermsForEntry(int $entryId, string $languageCode): array;

    /** @return array{categories:list<array<string,mixed>>,tags:list<array<string,mixed>>} */
    public function articleFacets(int $siteId, string $languageCode, int $tagLimit = 12): array;

    /** @return array<string,mixed>|null */
    public function findArchiveByPath(int $siteId, string $path, string $languageCode): ?array;
}
