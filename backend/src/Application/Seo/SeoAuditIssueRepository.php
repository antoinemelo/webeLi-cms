<?php

declare(strict_types=1);

namespace App\Application\Seo;

interface SeoAuditIssueRepository
{
    public function deleteForResource(string $resourceType, int $resourceId): void;

    public function addIssue(int $siteId, string $resourceType, int $resourceId, string $languageCode, string $issueCode, string $severity, string $message): void;

    /** @return list<array<string,mixed>> */
    public function listIssues(?int $siteId = null, ?string $languageCode = null, bool $unresolvedOnly = false): array;
}
