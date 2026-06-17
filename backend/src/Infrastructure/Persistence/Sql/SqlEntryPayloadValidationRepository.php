<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sql;

use App\Application\Content\EntryPayloadValidationRepository;
use App\Core\Database;

final class SqlEntryPayloadValidationRepository implements EntryPayloadValidationRepository
{
    public function __construct(private readonly Database $db) {}

    public function entryKeyExists(int $siteId, string $entryKey, ?int $excludeEntryId = null): bool
    {
        $params = ['site_id' => $siteId, 'entry_key' => $entryKey];
        $sql = 'SELECT id FROM content_entries WHERE site_id = :site_id AND entry_key = :entry_key';
        if ($excludeEntryId !== null) { $sql .= ' AND id <> :exclude_entry_id'; $params['exclude_entry_id'] = $excludeEntryId; }
        return $this->db->one($sql . ' LIMIT 1', $params) !== null;
    }

    public function slugExists(int $siteId, string $languageCode, string $slug, ?int $excludeEntryId = null): bool
    {
        $params = ['site_id' => $siteId, 'language' => $languageCode, 'slug' => $slug];
        $sql = 'SELECT entry_id FROM content_slug_registry WHERE site_id = :site_id AND language_code = :language AND slug = :slug';
        if ($excludeEntryId !== null) { $sql .= ' AND entry_id <> :exclude_entry_id'; $params['exclude_entry_id'] = $excludeEntryId; }
        if ($this->db->one($sql . ' LIMIT 1', $params) !== null) { return true; }

        $params = ['site_id' => $siteId, 'language' => $languageCode, 'slug' => $slug];
        $sql = 'SELECT cel.entry_id FROM content_entry_localizations cel JOIN content_entries ce ON ce.id = cel.entry_id WHERE ce.site_id = :site_id AND cel.language_code = :language AND cel.draft_slug = :slug';
        if ($excludeEntryId !== null) { $sql .= ' AND cel.entry_id <> :exclude_entry_id'; $params['exclude_entry_id'] = $excludeEntryId; }
        return $this->db->one($sql . ' LIMIT 1', $params) !== null;
    }

    public function uniqueFieldValueExists(int $siteId, int $contentTypeId, string $fieldKey, string $languageCode, string $normalizedValue, ?int $excludeEntryId = null): bool
    {
        $params = ['site_id' => $siteId, 'content_type_id' => $contentTypeId, 'field_key' => $fieldKey, 'language_code' => $languageCode, 'normalized_value' => $normalizedValue];
        $sql = 'SELECT entry_id FROM content_field_unique_values WHERE site_id = :site_id AND content_type_id = :content_type_id AND field_key = :field_key AND language_code = :language_code AND normalized_value = :normalized_value';
        if ($excludeEntryId !== null) { $sql .= ' AND entry_id <> :exclude_entry_id'; $params['exclude_entry_id'] = $excludeEntryId; }
        return $this->db->one($sql . ' LIMIT 1', $params) !== null;
    }

    public function mediaExists(int $siteId, int $mediaId): bool
    { return $this->db->one("SELECT id FROM media_assets WHERE id = :id AND site_id = :site_id AND lifecycle_status = 'ready' AND validation_status = 'valid' LIMIT 1", ['id' => $mediaId, 'site_id' => $siteId]) !== null; }

    public function mediaMimeType(int $siteId, int $mediaId): ?string
    { $row = $this->db->one("SELECT mime_type FROM media_assets WHERE id = :id AND site_id = :site_id AND lifecycle_status = 'ready' AND validation_status = 'valid' LIMIT 1", ['id' => $mediaId, 'site_id' => $siteId]); return $row ? (string) ($row['mime_type'] ?? '') : null; }

    public function mediaType(int $siteId, int $mediaId): ?string
    { $row = $this->db->one("SELECT media_type FROM media_assets WHERE id = :id AND site_id = :site_id AND lifecycle_status = 'ready' AND validation_status = 'valid' LIMIT 1", ['id' => $mediaId, 'site_id' => $siteId]); return $row ? (string) ($row['media_type'] ?? '') : null; }

    public function mediaHasAlt(int $siteId, int $mediaId, string $languageCode): bool
    { return $this->db->one("SELECT mal.id FROM media_asset_localizations mal JOIN media_assets ma ON ma.id = mal.media_id WHERE ma.id = :id AND ma.site_id = :site_id AND mal.language_code = :language_code AND TRIM(COALESCE(mal.alt_text, '')) <> '' LIMIT 1", ['id' => $mediaId, 'site_id' => $siteId, 'language_code' => $languageCode]) !== null; }

    public function contentEntryExists(int $siteId, int $entryId): bool
    { return $this->db->one('SELECT id FROM content_entries WHERE id = :id AND site_id = :site_id LIMIT 1', ['id' => $entryId, 'site_id' => $siteId]) !== null; }

    public function taxonomyTermExists(int $siteId, int $termId, ?string $taxonomyKey = null): bool
    {
        $params = ['site_id' => $siteId, 'term_id' => $termId];
        $sql = 'SELECT tt.id FROM taxonomy_terms tt JOIN taxonomies t ON t.id = tt.taxonomy_id WHERE tt.id = :term_id AND t.site_id = :site_id AND tt.is_active = 1';
        if ($taxonomyKey !== null && $taxonomyKey !== '') { $sql .= ' AND t.taxonomy_key = :taxonomy_key'; $params['taxonomy_key'] = $taxonomyKey; }
        return $this->db->one($sql . ' LIMIT 1', $params) !== null;
    }

    public function contentTypeTaxonomyPolicies(int $siteId, int $contentTypeId): array
    {
        return $this->db->all(
            'SELECT t.id, t.taxonomy_key, t.name, ctt.is_required, ctt.max_terms
             FROM content_type_taxonomies ctt
             JOIN taxonomies t ON t.id = ctt.taxonomy_id
             WHERE ctt.content_type_id = :content_type_id
               AND t.site_id = :site_id
             ORDER BY ctt.is_required DESC, t.sort_order, t.taxonomy_key',
            ['site_id' => $siteId, 'content_type_id' => $contentTypeId],
        );
    }

    public function taxonomyTermsById(int $siteId, array $termIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $termIds), static fn(int $id): bool => $id > 0)));
        if ($ids === []) { return []; }

        $placeholders = [];
        $params = ['site_id' => $siteId];
        foreach ($ids as $index => $id) {
            $key = 'term_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        $rows = $this->db->all(
            'SELECT tt.id AS term_id, tt.taxonomy_id, t.taxonomy_key
             FROM taxonomy_terms tt
             JOIN taxonomies t ON t.id = tt.taxonomy_id
             WHERE t.site_id = :site_id
               AND tt.is_active = 1
               AND tt.id IN (' . implode(',', $placeholders) . ')',
            $params,
        );

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['term_id']] = [
                'term_id' => (int) $row['term_id'],
                'taxonomy_id' => (int) $row['taxonomy_id'],
                'taxonomy_key' => (string) $row['taxonomy_key'],
            ];
        }
        return $byId;
    }

    public function replaceUniqueFieldValues(int $siteId, int $contentTypeId, int $entryId, string $languageCode, array $uniqueValues): void
    {
        $this->db->run('DELETE FROM content_field_unique_values WHERE site_id = :site_id AND content_type_id = :content_type_id AND entry_id = :entry_id AND language_code = :language_code', ['site_id' => $siteId, 'content_type_id' => $contentTypeId, 'entry_id' => $entryId, 'language_code' => $languageCode]);
        foreach ($uniqueValues as $fieldKey => $normalizedValue) {
            if ($normalizedValue === '') { continue; }
            $this->db->run('INSERT INTO content_field_unique_values(site_id, content_type_id, field_key, language_code, normalized_value, entry_id, updated_at) VALUES(:site_id, :content_type_id, :field_key, :language_code, :normalized_value, :entry_id, :updated_at)', ['site_id' => $siteId, 'content_type_id' => $contentTypeId, 'field_key' => $fieldKey, 'language_code' => $languageCode, 'normalized_value' => $normalizedValue, 'entry_id' => $entryId, 'updated_at' => now_utc()]);
        }
    }

    public function reserveSlug(int $siteId, int $entryId, string $languageCode, string $slug): void
    {
        $this->db->run('DELETE FROM content_slug_registry WHERE site_id = :site_id AND entry_id = :entry_id AND language_code = :language_code', ['site_id' => $siteId, 'entry_id' => $entryId, 'language_code' => $languageCode]);
        if ($slug === '') { return; }
        $this->db->run('INSERT INTO content_slug_registry(site_id, language_code, slug, entry_id, updated_at) VALUES(:site_id, :language_code, :slug, :entry_id, :updated_at)', ['site_id' => $siteId, 'language_code' => $languageCode, 'slug' => $slug, 'entry_id' => $entryId, 'updated_at' => now_utc()]);
    }
}
