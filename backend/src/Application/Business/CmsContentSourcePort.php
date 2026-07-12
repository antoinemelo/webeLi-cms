<?php

declare(strict_types=1);

namespace App\Application\Business;

interface CmsContentSourcePort
{
    /** @return array<string,mixed>|null */
    public function contentEntry(int $siteId, int $contentEntryId): ?array;

    public function supportsLocale(int $siteId, string $locale): bool;

    /** @return list<array<string,mixed>> */
    public function searchContent(int $siteId, string $query, int $limit = 50): array;
}
