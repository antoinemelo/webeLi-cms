<?php

declare(strict_types=1);

namespace App\Application\Media;

use App\Core\Database;

final class UpdateMediaMetadata
{
    private readonly MediaFolderRepository $folders;

    public function __construct(private readonly Database $db)
    {
        $this->folders = new MediaFolderRepository($db);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function execute(int $siteId, int $mediaId, array $payload, ?int $actorId = null): array
    {
        $asset = $this->db->one(
            'SELECT * FROM media_assets WHERE id = :id AND site_id = :site_id AND lifecycle_status != \'deleted\' LIMIT 1',
            ['id' => $mediaId, 'site_id' => $siteId]
        );
        if (!$asset) {
            throw new \InvalidArgumentException('MEDIA_NOT_FOUND');
        }

        if (isset($payload['folder_id']) && (int) $payload['folder_id'] > 0) {
            $this->folders->assertFolderBelongsToSite($siteId, (int) $payload['folder_id']);
            $payload['folder_id'] = (int) $payload['folder_id'];
        } elseif (array_key_exists('folder_key', $payload)) {
            $folder = $this->folders->ensureFolder($siteId, (string) $payload['folder_key'], $payload['folder_name'] ?? null, isset($payload['parent_folder_id']) ? (int) $payload['parent_folder_id'] : null);
            $payload['folder_id'] = (int) $folder['id'];
        }

        $allowed = ['dominant_color', 'copyright_text', 'license_type', 'source_url', 'folder_id'];
        $sets = ['updated_at = CURRENT_TIMESTAMP'];
        $params = ['id' => $mediaId];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $payload)) {
                $sets[] = $key . ' = :' . $key;
                $params[$key] = $payload[$key] !== '' ? $payload[$key] : null;
            }
        }

        if (array_key_exists('metadata', $payload)) {
            $sets[] = 'metadata_json = :metadata_json';
            $params['metadata_json'] = json_encode($payload['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $this->db->transaction(function () use ($sets, $params, $payload, $mediaId, $siteId, $actorId): void {
            if (count($sets) > 1 || isset($params['metadata_json'])) {
                $this->db->run('UPDATE media_assets SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
            }

            foreach ((array) ($payload['localizations'] ?? []) as $languageCode => $loc) {
                if (is_array($loc)) {
                    $this->upsertLocalization($mediaId, (string) $languageCode, $loc);
                }
            }

            $this->refreshMetadataStatus($mediaId);
            $this->refreshUsageAltSnapshots($mediaId);
            $this->recordEvent($mediaId, $siteId, 'metadata_updated', $actorId, [
                'fields' => array_values(array_intersect(array_keys($payload), ['dominant_color', 'copyright_text', 'license_type', 'source_url', 'folder_id', 'folder_key', 'metadata', 'localizations'])),
            ]);
        });

        return [
            'media_id' => $mediaId,
            'metadata_status' => (string) ($this->db->one('SELECT metadata_status FROM media_assets WHERE id = :id', ['id' => $mediaId])['metadata_status'] ?? 'incomplete'),
        ];
    }

    /** @param array<string,mixed> $payload */
    private function recordEvent(int $mediaId, int $siteId, string $eventType, ?int $actorId = null, array $payload = []): void
    {
        $this->db->run(
            'INSERT INTO media_asset_events (media_id, site_id, event_type, actor_iam_user_id, payload_json, created_at)
             VALUES (:media_id, :site_id, :event_type, :actor_iam_user_id, :payload_json, CURRENT_TIMESTAMP)',
            [
                'media_id' => $mediaId,
                'site_id' => $siteId,
                'event_type' => $eventType,
                'actor_iam_user_id' => $actorId,
                'payload_json' => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ]
        );
    }

    /** @param array<string,mixed> $loc */
    private function upsertLocalization(int $mediaId, string $languageCode, array $loc): void
    {
        $this->db->run(
            'INSERT INTO media_asset_localizations (media_id, language_code, alt_text, caption, title, is_alt_verified, updated_at)
             VALUES (:media_id, :language_code, :alt_text, :caption, :title, :is_alt_verified, CURRENT_TIMESTAMP)
             ON CONFLICT(media_id, language_code) DO UPDATE SET
                alt_text = excluded.alt_text,
                caption = excluded.caption,
                title = excluded.title,
                is_alt_verified = excluded.is_alt_verified,
                updated_at = CURRENT_TIMESTAMP',
            [
                'media_id' => $mediaId,
                'language_code' => $languageCode,
                'alt_text' => $loc['alt_text'] ?? null,
                'caption' => $loc['caption'] ?? null,
                'title' => $loc['title'] ?? null,
                'is_alt_verified' => trim((string) ($loc['alt_text'] ?? '')) !== '' ? 1 : 0,
            ]
        );
    }

    private function refreshMetadataStatus(int $mediaId): void
    {
        $asset = $this->db->one('SELECT media_type FROM media_assets WHERE id = :id', ['id' => $mediaId]);
        $complete = true;
        if (($asset['media_type'] ?? '') === 'image') {
            $missingRequiredAlt = $this->db->one(
                'SELECT mu.id
                 FROM media_usages mu
                 LEFT JOIN media_asset_localizations mal ON mal.media_id = mu.media_id AND mal.language_code = mu.language_code
                 WHERE mu.media_id = :id
                   AND mu.alt_policy = \'required\'
                   AND COALESCE(TRIM(mal.alt_text), \'\') = \'\'
                 LIMIT 1',
                ['id' => $mediaId]
            );
            $complete = $missingRequiredAlt === null;
        }
        $this->db->run(
            'UPDATE media_assets SET metadata_status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['id' => $mediaId, 'status' => $complete ? 'complete' : 'incomplete']
        );
    }

    private function refreshUsageAltSnapshots(int $mediaId): void
    {
        $this->db->run(
            'UPDATE media_usages
             SET alt_text_snapshot = (
                SELECT mal.alt_text FROM media_asset_localizations mal
                WHERE mal.media_id = media_usages.media_id AND mal.language_code = media_usages.language_code
                LIMIT 1
             ),
             updated_at = CURRENT_TIMESTAMP
             WHERE media_id = :id',
            ['id' => $mediaId]
        );
    }
}
