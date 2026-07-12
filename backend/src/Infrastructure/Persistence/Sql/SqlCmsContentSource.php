<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sql;

use App\Application\Business\CmsContentSourcePort;
use App\Core\Database;

final class SqlCmsContentSource implements CmsContentSourcePort
{
    public function __construct(private readonly Database $db) {}

    public function contentEntry(int $siteId, int $contentEntryId): ?array
    {
        return $this->db->one(
            'SELECT ce.id, ce.site_id, ce.entry_key, ce.status, ce.is_active, ct.type_key
             FROM content_entries ce
             INNER JOIN content_types ct ON ct.id = ce.content_type_id
             WHERE ce.id = ? AND ce.site_id = ? LIMIT 1',
            [$contentEntryId, $siteId]
        );
    }

    public function supportsLocale(int $siteId, string $locale): bool
    {
        return $this->db->one(
            'SELECT 1 FROM site_languages WHERE site_id = ? AND language_code = ? AND is_active = 1 LIMIT 1',
            [$siteId, $locale]
        ) !== null;
    }

    public function searchContent(int $siteId, string $query, int $limit = 50): array
    {
        $query = trim($query);
        $params = ['site_id' => $siteId, 'limit' => max(1, min(100, $limit))];
        $where = '';
        if ($query !== '') {
            $where = ' AND (ce.entry_key LIKE :query OR pcs.title LIKE :query OR ct.type_key LIKE :query)';
            $params['query'] = '%' . $query . '%';
        }
        return $this->db->all(
            'SELECT ce.id, ce.site_id, ce.entry_key, ce.status, ce.is_active, ct.type_key,
                    MAX(pcs.title) AS title
             FROM content_entries ce
             INNER JOIN content_types ct ON ct.id = ce.content_type_id
             LEFT JOIN public_content_snapshots pcs ON pcs.site_id = ce.site_id AND pcs.resource_type = \'content_entry\' AND pcs.resource_id = ce.id
             WHERE ce.site_id = :site_id' . $where . '
             GROUP BY ce.id, ce.site_id, ce.entry_key, ce.status, ce.is_active, ct.type_key
             ORDER BY COALESCE(MAX(pcs.title), ce.entry_key) ASC
             LIMIT :limit',
            $params
        );
    }
}
