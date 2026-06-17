<?php

declare(strict_types=1);

namespace App\Application\Media;

use App\Core\Database;

final class ListUnusedMedia
{
    public function __construct(private readonly Database $db) {}

    /** @return list<array<string,mixed>> */
    public function execute(int $siteId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return $this->db->all(
            'SELECT ma.*
             FROM media_assets ma
             LEFT JOIN media_usages mu ON mu.media_id = ma.id
             WHERE ma.site_id = :site_id
               AND ma.lifecycle_status = \'ready\'
               AND mu.id IS NULL
             ORDER BY ma.created_at ASC, ma.id ASC
             LIMIT ' . $limit,
            ['site_id' => $siteId]
        );
    }
}
