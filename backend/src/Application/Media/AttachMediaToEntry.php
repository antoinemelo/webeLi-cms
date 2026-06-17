<?php

declare(strict_types=1);

namespace App\Application\Media;

use App\Core\Database;

final class AttachMediaToEntry
{
    public function __construct(private readonly Database $db) {}

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function execute(int $siteId, int $mediaId, array $payload): array
    {
        $asset = $this->db->one(
            'SELECT * FROM media_assets WHERE id = :id AND site_id = :site_id AND lifecycle_status = \'ready\' LIMIT 1',
            ['id' => $mediaId, 'site_id' => $siteId]
        );
        if (!$asset) {
            throw new \InvalidArgumentException('MEDIA_NOT_READY');
        }

        $resourceType = trim((string) ($payload['resource_type'] ?? 'content_entry'));
        $resourceId = (int) ($payload['resource_id'] ?? 0);
        if ($resourceType === '' || $resourceId <= 0) {
            throw new \InvalidArgumentException('MEDIA_USAGE_RESOURCE_REQUIRED');
        }

        $fieldKey = (string) ($payload['field_key'] ?? '');
        $languageCode = (string) ($payload['language_code'] ?? '');
        $usageContext = (string) ($payload['usage_context'] ?? 'content');
        $sourceRevisionId = isset($payload['source_revision_id']) ? (int) $payload['source_revision_id'] : null;
        $altPolicy = (string) ($payload['alt_policy'] ?? ($usageContext === 'seo' || $usageContext === 'content' ? 'required' : 'decorative_allowed'));
        if (!in_array($altPolicy, ['required', 'decorative_allowed', 'forbidden'], true)) {
            throw new \InvalidArgumentException('MEDIA_ALT_POLICY_INVALID');
        }

        $altText = null;
        if ($languageCode !== '') {
            $loc = $this->db->one(
                'SELECT alt_text FROM media_asset_localizations WHERE media_id = :media_id AND language_code = :language_code',
                ['media_id' => $mediaId, 'language_code' => $languageCode]
            );
            $altText = $loc['alt_text'] ?? null;
        }

        if ($altPolicy === 'required' && str_starts_with((string) $asset['mime_type'], 'image/') && trim((string) $altText) === '') {
            throw new \InvalidArgumentException('MEDIA_ALT_REQUIRED');
        }

        $usageHash = hash('sha256', implode('|', [
            $siteId,
            $mediaId,
            $resourceType,
            $resourceId,
            $fieldKey,
            $languageCode,
            $usageContext,
            (string) $sourceRevisionId,
        ]));

        $this->db->run(
            'INSERT INTO media_usages (
                media_id, site_id, resource_type, resource_id, field_key, language_code, usage_context,
                source_revision_id, alt_policy, alt_text_snapshot, usage_hash, created_at, updated_at
             ) VALUES (
                :media_id, :site_id, :resource_type, :resource_id, :field_key, :language_code, :usage_context,
                :source_revision_id, :alt_policy, :alt_text_snapshot, :usage_hash, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             )
             ON CONFLICT(usage_hash) DO UPDATE SET
                alt_policy = excluded.alt_policy,
                alt_text_snapshot = excluded.alt_text_snapshot,
                updated_at = CURRENT_TIMESTAMP',
            [
                'media_id' => $mediaId,
                'site_id' => $siteId,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'field_key' => $fieldKey !== '' ? $fieldKey : null,
                'language_code' => $languageCode !== '' ? $languageCode : null,
                'usage_context' => $usageContext,
                'source_revision_id' => $sourceRevisionId,
                'alt_policy' => $altPolicy,
                'alt_text_snapshot' => $altText,
                'usage_hash' => $usageHash,
            ]
        );

        $this->recordEvent($mediaId, $siteId, 'attached', [
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'field_key' => $fieldKey !== '' ? $fieldKey : null,
            'language_code' => $languageCode !== '' ? $languageCode : null,
            'usage_context' => $usageContext,
            'alt_policy' => $altPolicy,
            'usage_hash' => $usageHash,
        ]);

        return ['media_id' => $mediaId, 'usage_hash' => $usageHash, 'alt_policy' => $altPolicy];
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
