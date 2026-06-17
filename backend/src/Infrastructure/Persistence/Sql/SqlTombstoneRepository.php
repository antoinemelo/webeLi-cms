<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sql;

use App\Application\Routing\TombstoneRepository;
use App\Core\Database;
use App\Domain\Routing\Tombstone;

final class SqlTombstoneRepository implements TombstoneRepository
{
    public function __construct(private readonly Database $db) {}

    public function deleteByResource(string $resourceType, int $resourceId): void
    {
        $this->db->run('DELETE FROM tombstones WHERE resource_type = :resource_type AND resource_id = :resource_id', [
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
        ]);
    }

    public function replace(Tombstone $tombstone): void
    {
        $this->db->run('DELETE FROM tombstones WHERE site_id = :site_id AND old_path = :old_path AND COALESCE(language_code, \'\') = COALESCE(:language_code, \'\')', [
            'site_id' => $tombstone->siteId,
            'old_path' => $tombstone->oldPath,
            'language_code' => $tombstone->languageCode,
        ]);
        $now = now_utc();
        $this->db->run('INSERT INTO tombstones(site_id, language_code, old_path, resource_type, resource_id, replacement_path, gone_reason, gone_at, updated_at, is_active) VALUES(:site_id, :language_code, :old_path, :resource_type, :resource_id, :replacement_path, :gone_reason, :gone_at, :updated_at, :is_active)', [
            'site_id' => $tombstone->siteId,
            'language_code' => $tombstone->languageCode,
            'old_path' => $tombstone->oldPath,
            'resource_type' => $tombstone->resourceType,
            'resource_id' => $tombstone->resourceId,
            'replacement_path' => $tombstone->replacementPath,
            'gone_reason' => $tombstone->reason,
            'gone_at' => $now,
            'updated_at' => $now,
            'is_active' => $tombstone->isActive ? 1 : 0,
        ]);
    }
}
