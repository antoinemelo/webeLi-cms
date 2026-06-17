<?php

declare(strict_types=1);

namespace App\Application\Media;

use App\Core\Database;
use App\Domain\Schema\ContentType;
use App\Domain\Schema\FieldDefinition;
use App\Domain\Schema\FieldType;
use App\Application\Content\BlockDocumentNormalizer;

final class SyncMediaUsagesForRevision
{
    public function __construct(private readonly Database $db) {}

    /** @param array<string,mixed> $document */
    public function execute(int $siteId, int $entryId, string $languageCode, int $revisionId, ContentType $contentType, array $document): void
    {
        $this->db->run(
            "DELETE FROM media_usages
             WHERE site_id = :site_id
               AND resource_type = 'content_entry'
               AND resource_id = :entry_id
               AND language_code = :language_code
               AND usage_context = 'content'",
            ['site_id' => $siteId, 'entry_id' => $entryId, 'language_code' => $languageCode]
        );

        $fields = is_array($document['fields'] ?? null) ? $document['fields'] : [];
        foreach ($contentType->fields as $field) {
            if (!$field instanceof FieldDefinition || $field->fieldType !== FieldType::MEDIA) {
                continue;
            }
            foreach ($this->mediaIds($fields[$field->fieldKey] ?? null) as $sortOrder => $mediaId) {
                $asset = $this->db->one(
                    'SELECT media_type FROM media_assets WHERE id = :id AND site_id = :site_id AND lifecycle_status = \'ready\' AND validation_status = \'valid\' LIMIT 1',
                    ['id' => $mediaId, 'site_id' => $siteId]
                );
                if (!$asset) {
                    continue;
                }
                $altPolicy = (string) $field->validationValue('alt_policy', 'required');
                if (!in_array($altPolicy, ['required', 'decorative_allowed', 'forbidden'], true)) {
                    $altPolicy = 'required';
                }
                $usageHash = hash('sha256', implode('|', [$siteId, $mediaId, 'content_entry', $entryId, $field->fieldKey, $languageCode, 'content', $revisionId, $sortOrder]));
                $this->db->run(
                    'INSERT INTO media_usages (
                        media_id, site_id, resource_type, resource_id, field_key, language_code, usage_context,
                        source_revision_id, alt_policy, alt_text_snapshot, usage_hash, created_at, updated_at
                     ) VALUES (
                        :media_id, :site_id, \'content_entry\', :entry_id, :field_key, :language_code, \'content\',
                        :revision_id, :alt_policy, :alt_text_snapshot, :usage_hash, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                     )',
                    [
                        'media_id' => $mediaId,
                        'site_id' => $siteId,
                        'entry_id' => $entryId,
                        'field_key' => $field->fieldKey,
                        'language_code' => $languageCode,
                        'revision_id' => $revisionId,
                        'alt_policy' => $altPolicy,
                        'alt_text_snapshot' => $this->localizedAlt($mediaId, $languageCode),
                        'usage_hash' => $usageHash,
                    ]
                );
            }
        }

        $blockNormalizer = new BlockDocumentNormalizer();
        foreach ($blockNormalizer->mediaIds($blockNormalizer->normalize($document['blocks'] ?? [])) as $sortOrder => $mediaId) {
            $asset = $this->db->one(
                'SELECT media_type FROM media_assets WHERE id = :id AND site_id = :site_id AND lifecycle_status = \'ready\' AND validation_status = \'valid\' LIMIT 1',
                ['id' => $mediaId, 'site_id' => $siteId]
            );
            if (!$asset) { continue; }
            $usageHash = hash('sha256', implode('|', [$siteId, $mediaId, 'content_entry', $entryId, 'blocks', $languageCode, 'content', $revisionId, $sortOrder]));
            $this->db->run(
                'INSERT INTO media_usages (
                    media_id, site_id, resource_type, resource_id, field_key, language_code, usage_context,
                    source_revision_id, alt_policy, alt_text_snapshot, usage_hash, created_at, updated_at
                 ) VALUES (
                    :media_id, :site_id, \'content_entry\', :entry_id, \'blocks\', :language_code, \'content\',
                    :revision_id, \'decorative_allowed\', :alt_text_snapshot, :usage_hash, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                 )',
                [
                    'media_id' => $mediaId,
                    'site_id' => $siteId,
                    'entry_id' => $entryId,
                    'language_code' => $languageCode,
                    'revision_id' => $revisionId,
                    'alt_text_snapshot' => $this->localizedAlt($mediaId, $languageCode),
                    'usage_hash' => $usageHash,
                ]
            );
        }

        $seo = is_array($document['seo'] ?? null) ? $document['seo'] : [];
        foreach (['og_image_media_id' => 'opengraph', 'twitter_image_media_id' => 'seo'] as $fieldKey => $context) {
            $mediaId = $this->mediaId($seo[$fieldKey] ?? null);
            if ($mediaId < 1) { continue; }
            $asset = $this->db->one(
                'SELECT media_type FROM media_assets WHERE id = :id AND site_id = :site_id AND lifecycle_status = \'ready\' AND validation_status = \'valid\' LIMIT 1',
                ['id' => $mediaId, 'site_id' => $siteId]
            );
            if (!$asset) { continue; }
            $usageHash = hash('sha256', implode('|', [$siteId, $mediaId, 'content_entry', $entryId, $fieldKey, $languageCode, $context, $revisionId]));
            $this->db->run(
                'INSERT INTO media_usages (media_id, site_id, resource_type, resource_id, field_key, language_code, usage_context, source_revision_id, alt_policy, alt_text_snapshot, usage_hash, created_at, updated_at)
                 VALUES (:media_id, :site_id, \'content_entry\', :entry_id, :field_key, :language_code, :usage_context, :revision_id, \'required\', :alt_text_snapshot, :usage_hash, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
                ['media_id' => $mediaId, 'site_id' => $siteId, 'entry_id' => $entryId, 'field_key' => $fieldKey, 'language_code' => $languageCode, 'usage_context' => $context, 'revision_id' => $revisionId, 'alt_text_snapshot' => $this->localizedAlt($mediaId, $languageCode), 'usage_hash' => $usageHash]
            );
        }
    }

    /** @return list<int> */
    private function mediaIds(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $items = is_array($value) ? $value : [$value];
        $ids = [];
        foreach ($items as $item) {
            if (is_array($item) && isset($item['id'])) {
                $item = $item['id'];
            }
            if (is_numeric($item) && (int) $item > 0) {
                $ids[] = (int) $item;
            }
        }
        return array_values(array_unique($ids));
    }

    private function mediaId(mixed $value): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : 0;
    }

    private function localizedAlt(int $mediaId, string $languageCode): ?string
    {
        $row = $this->db->one('SELECT alt_text FROM media_asset_localizations WHERE media_id = :media_id AND language_code = :language_code LIMIT 1', ['media_id' => $mediaId, 'language_code' => $languageCode]);
        $alt = trim((string) ($row['alt_text'] ?? ''));
        return $alt !== '' ? $alt : null;
    }
}
