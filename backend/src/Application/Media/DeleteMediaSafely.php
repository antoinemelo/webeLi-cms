<?php

declare(strict_types=1);

namespace App\Application\Media;

use App\Core\Database;
use App\Application\Media\Storage\StorageDriverFactory;

final class DeleteMediaSafely
{
    private readonly StorageDriverFactory $storageFactory;

    public function __construct(private readonly Database $db)
    {
        $this->storageFactory = new StorageDriverFactory($db);
    }

    /** @return array<string,mixed> */
    public function execute(int $siteId, int $mediaId, bool $force = false): array
    {
        $asset = $this->db->one(
            'SELECT * FROM media_assets WHERE id = :id AND site_id = :site_id AND lifecycle_status != \'deleted\' LIMIT 1',
            ['id' => $mediaId, 'site_id' => $siteId]
        );
        if (!$asset) {
            throw new \InvalidArgumentException('MEDIA_NOT_FOUND');
        }

        $usageCount = (int) ($this->db->one('SELECT COUNT(*) AS c FROM media_usages WHERE media_id = :id AND site_id = :site_id', ['id' => $mediaId, 'site_id' => $siteId])['c'] ?? 0);
        if ($usageCount > 0) {
            $this->db->run(
                "UPDATE media_assets SET lifecycle_status = 'delete_pending', delete_requested_at = COALESCE(delete_requested_at, CURRENT_TIMESTAMP), updated_at = CURRENT_TIMESTAMP WHERE id = :id AND site_id = :site_id",
                ['id' => $mediaId, 'site_id' => $siteId]
            );
            $this->recordEvent($mediaId, $siteId, 'delete_pending', ['usage_count' => $usageCount, 'blocked' => true, 'force_requested' => $force]);
            return ['status' => 'delete_pending', 'media_id' => $mediaId, 'usage_count' => $usageCount, 'blocked' => true];
        }

        $paths = [];
        foreach (['path', 'quarantine_path', 'public_path'] as $column) {
            if (!empty($asset[$column])) {
                $paths[] = (string) $asset[$column];
            }
        }
        foreach ($this->db->all('SELECT path FROM media_asset_variants WHERE media_id = :id', ['id' => $mediaId]) as $variant) {
            if (!empty($variant['path'])) {
                $paths[] = (string) $variant['path'];
            }
        }

        $this->db->transaction(function () use ($mediaId, $siteId, $usageCount): void {
            $this->recordEvent($mediaId, $siteId, 'deleted', ['usage_count' => $usageCount]);
            $this->db->run('DELETE FROM media_asset_variants WHERE media_id = :id', ['id' => $mediaId]);
            $this->db->run('DELETE FROM media_usages WHERE media_id = :id AND site_id = :site_id', ['id' => $mediaId, 'site_id' => $siteId]);
            $this->db->run(
                'UPDATE media_assets SET lifecycle_status = \'deleted\', deleted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
                ['id' => $mediaId]
            );
        });

        $storage = $this->storageFactory->forDisk((string) ($asset['storage_disk'] ?? 'local'), $siteId);
        foreach (array_unique($paths) as $relative) {
            try { $storage->delete($relative); } catch (\Throwable) {}
            $absolute = MediaPath::absolute($relative);
            if (is_file($absolute)) { @unlink($absolute); }
        }

        return ['status' => 'deleted', 'media_id' => $mediaId, 'usage_count' => $usageCount];
    }

    /** @param array<string,mixed> $payload */
    private function recordEvent(int $mediaId, int $siteId, string $eventType, array $payload = []): void
    {
        $this->db->run(
            'INSERT INTO media_asset_events (media_id, site_id, event_type, payload_json, created_at)
             VALUES (:media_id, :site_id, :event_type, :payload_json, CURRENT_TIMESTAMP)',
            [
                'media_id' => $mediaId,
                'site_id' => $siteId,
                'event_type' => $eventType,
                'payload_json' => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ]
        );
    }
}

