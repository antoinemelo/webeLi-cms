<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sql;

use App\Application\Seo\SeoAuditIssueRepository;
use App\Core\Database;

final class SqlSeoAuditIssueRepository implements SeoAuditIssueRepository
{
    public function __construct(private readonly Database $db) {}

    public function deleteForResource(string $resourceType, int $resourceId): void
    {
        $this->db->run('DELETE FROM seo_audit_issues WHERE resource_type = :resource_type AND resource_id = :resource_id', [
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
        ]);
    }

    public function addIssue(int $siteId, string $resourceType, int $resourceId, string $languageCode, string $issueCode, string $severity, string $message): void
    {
        $this->db->run('INSERT INTO seo_audit_issues(site_id, resource_type, resource_id, language_code, issue_code, severity, message, is_resolved, detected_at) VALUES(:site_id, :resource_type, :resource_id, :language_code, :issue_code, :severity, :message, 0, :detected_at)', [
            'site_id' => $siteId,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'language_code' => $languageCode,
            'issue_code' => $issueCode,
            'severity' => $severity,
            'message' => $message,
            'detected_at' => now_utc(),
        ]);
    }

    public function listIssues(?int $siteId = null, ?string $languageCode = null, bool $unresolvedOnly = false): array
    {
        $where = [];
        $params = [];
        if ($siteId !== null && $siteId > 0) {
            $where[] = 'sai.site_id = :site_id';
            $params['site_id'] = $siteId;
        }
        if ($languageCode !== null && $languageCode !== '') {
            $where[] = 'sai.language_code = :language_code';
            $params['language_code'] = $languageCode;
        }
        if ($unresolvedOnly) {
            $where[] = 'sai.is_resolved = 0';
        }

        $sql = "SELECT
                sai.*,
                s.site_key,
                ce.entry_key,
                ct.type_key AS content_type_key,
                cel.title AS resource_title,
                r.full_path AS public_path
            FROM seo_audit_issues sai
            JOIN sites s ON s.id = sai.site_id
            LEFT JOIN content_entries ce ON ce.id = sai.resource_id AND sai.resource_type = 'content_entry'
            LEFT JOIN content_types ct ON ct.id = ce.content_type_id
            LEFT JOIN content_entry_localizations cel ON cel.entry_id = ce.id AND cel.language_code = sai.language_code
            LEFT JOIN routes r ON r.site_id = sai.site_id
                AND r.language_code = sai.language_code
                AND r.resource_type = sai.resource_type
                AND r.resource_id = sai.resource_id
                AND r.status = 'active'
                AND r.is_canonical = 1";
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY
            CASE sai.severity WHEN 'critical' THEN 4 WHEN 'high' THEN 3 WHEN 'medium' THEN 2 ELSE 1 END DESC,
            sai.detected_at DESC,
            sai.id DESC";

        return $this->db->all($sql, $params);
    }
}
