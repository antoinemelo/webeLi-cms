<?php

declare(strict_types=1);

namespace App\Repository;

use App\Application\Frontend\TaxonomyReadRepository;
use App\Application\Content\Read\PublicContentReadRepository;
use App\Core\Database;

final class TaxonomyRepository implements TaxonomyReadRepository
{
    public function __construct(private readonly Database $db, private readonly PublicContentReadRepository $content) {}

    public function listTaxonomies(int $siteId): array
    {
        return $this->db->all('SELECT * FROM taxonomies WHERE site_id = :site_id ORDER BY sort_order, taxonomy_key', ['site_id' => $siteId]);
    }


    public function findTaxonomy(int $siteId, string $taxonomyKey): ?array
    {
        return $this->db->one('SELECT * FROM taxonomies WHERE site_id = :site_id AND taxonomy_key = :taxonomy_key LIMIT 1', [
            'site_id' => $siteId,
            'taxonomy_key' => $taxonomyKey,
        ]);
    }

    public function createTaxonomy(int $siteId, array $payload): array
    {
        $key = $this->slugKey((string) ($payload['taxonomy_key'] ?? $payload['key'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        if ($key === '' || $name === '') {
            throw new \InvalidArgumentException('taxonomy_key et name sont obligatoires.');
        }
        $this->db->transaction(function () use ($siteId, $key, $name, $payload): void {
            $this->db->run(
                'INSERT INTO taxonomies(site_id, taxonomy_key, name, description, is_hierarchical, is_localized, seo_enabled, archive_enabled, sort_order)
                 VALUES(:site_id, :taxonomy_key, :name, :description, :is_hierarchical, :is_localized, :seo_enabled, :archive_enabled, :sort_order)',
                [
                    'site_id' => $siteId,
                    'taxonomy_key' => $key,
                    'name' => $name,
                    'description' => $this->nullableString($payload['description'] ?? null),
                    'is_hierarchical' => !empty($payload['is_hierarchical']) ? 1 : 0,
                    'is_localized' => !empty($payload['is_localized']) || !array_key_exists('is_localized', $payload) ? 1 : 0,
                    'seo_enabled' => !empty($payload['seo_enabled']) || !array_key_exists('seo_enabled', $payload) ? 1 : 0,
                    'archive_enabled' => !empty($payload['archive_enabled']) || !array_key_exists('archive_enabled', $payload) ? 1 : 0,
                    'sort_order' => (int) ($payload['sort_order'] ?? 0),
                ]
            );
            $this->attachTaxonomyToTaxonomyEnabledContentTypes($this->db->lastInsertId());
        });
        return $this->findTaxonomy($siteId, $key) ?? [];
    }


    private function attachTaxonomyToTaxonomyEnabledContentTypes(int $taxonomyId): void
    {
        if ($taxonomyId < 1) { return; }
        $contentTypes = $this->db->all('SELECT id FROM content_types WHERE has_taxonomies = 1 AND admin_enabled = 1 ORDER BY id');
        foreach ($contentTypes as $contentType) {
            $contentTypeId = (int) ($contentType['id'] ?? 0);
            if ($contentTypeId < 1) { continue; }
            $this->db->run(
                'INSERT INTO content_type_taxonomies(content_type_id, taxonomy_id, is_required, max_terms)
                 SELECT :content_type_id, :taxonomy_id, 0, NULL
                 WHERE NOT EXISTS (
                    SELECT 1 FROM content_type_taxonomies
                    WHERE content_type_id = :content_type_id AND taxonomy_id = :taxonomy_id
                 )',
                ['content_type_id' => $contentTypeId, 'taxonomy_id' => $taxonomyId]
            );
        }
    }

    public function updateTaxonomy(int $siteId, string $taxonomyKey, array $payload): ?array
    {
        $taxonomy = $this->findTaxonomy($siteId, $taxonomyKey);
        if (!$taxonomy) {
            return null;
        }
        $newKey = array_key_exists('taxonomy_key', $payload) || array_key_exists('key', $payload)
            ? $this->slugKey((string) ($payload['taxonomy_key'] ?? $payload['key'] ?? ''))
            : (string) $taxonomy['taxonomy_key'];
        $name = array_key_exists('name', $payload) ? trim((string) $payload['name']) : (string) $taxonomy['name'];
        if ($newKey === '' || $name === '') {
            throw new \InvalidArgumentException('taxonomy_key et name ne peuvent pas être vides.');
        }
        $this->db->run(
            'UPDATE taxonomies SET taxonomy_key = :taxonomy_key, name = :name, description = :description, is_hierarchical = :is_hierarchical,
             is_localized = :is_localized, seo_enabled = :seo_enabled, archive_enabled = :archive_enabled, sort_order = :sort_order WHERE id = :id',
            [
                'taxonomy_key' => $newKey,
                'name' => $name,
                'description' => array_key_exists('description', $payload) ? $this->nullableString($payload['description']) : $taxonomy['description'],
                'is_hierarchical' => array_key_exists('is_hierarchical', $payload) ? (!empty($payload['is_hierarchical']) ? 1 : 0) : (int) $taxonomy['is_hierarchical'],
                'is_localized' => array_key_exists('is_localized', $payload) ? (!empty($payload['is_localized']) ? 1 : 0) : (int) $taxonomy['is_localized'],
                'seo_enabled' => array_key_exists('seo_enabled', $payload) ? (!empty($payload['seo_enabled']) ? 1 : 0) : (int) $taxonomy['seo_enabled'],
                'archive_enabled' => array_key_exists('archive_enabled', $payload) ? (!empty($payload['archive_enabled']) ? 1 : 0) : (int) $taxonomy['archive_enabled'],
                'sort_order' => array_key_exists('sort_order', $payload) ? (int) $payload['sort_order'] : (int) $taxonomy['sort_order'],
                'id' => (int) $taxonomy['id'],
            ]
        );
        return $this->findTaxonomy($siteId, $newKey);
    }

    public function deleteTaxonomy(int $siteId, string $taxonomyKey): bool
    {
        $taxonomy = $this->findTaxonomy($siteId, $taxonomyKey);
        if (!$taxonomy) {
            return false;
        }
        $this->db->run('DELETE FROM taxonomies WHERE id = :id', ['id' => (int) $taxonomy['id']]);
        return true;
    }

    public function createTerm(int $siteId, string $taxonomyKey, string $languageCode, array $payload): ?array
    {
        $taxonomy = $this->findTaxonomy($siteId, $taxonomyKey);
        if (!$taxonomy) {
            return null;
        }
        $termKey = $this->slugKey((string) ($payload['term_key'] ?? $payload['key'] ?? $payload['slug'] ?? ''));
        $name = trim((string) ($payload['name'] ?? $payload['label'] ?? ''));
        $slug = $this->slugKey((string) ($payload['slug'] ?? $termKey));
        if ($termKey === '' || $name === '' || $slug === '') {
            throw new \InvalidArgumentException('term_key, name et slug sont obligatoires.');
        }
        $this->db->transaction(function () use ($taxonomy, $payload, $termKey, $siteId, $languageCode, $name, $slug): void {
            $this->db->run('INSERT INTO taxonomy_terms(taxonomy_id, parent_id, term_key, is_active, sort_order) VALUES(:taxonomy_id, :parent_id, :term_key, :is_active, :sort_order)', [
                'taxonomy_id' => (int) $taxonomy['id'],
                'parent_id' => isset($payload['parent_id']) && (int) $payload['parent_id'] > 0 ? (int) $payload['parent_id'] : null,
                'term_key' => $termKey,
                'is_active' => !empty($payload['is_active']) || !array_key_exists('is_active', $payload) ? 1 : 0,
                'sort_order' => (int) ($payload['sort_order'] ?? 0),
            ]);
            $termId = $this->db->lastInsertId();
            $this->upsertTermLocalization($siteId, (int) $taxonomy['id'], $termId, $languageCode, $name, $slug, $payload);
            $this->upsertTermLocalizationsFromPayload($siteId, (int) $taxonomy['id'], $termId, $payload);
        });
        $term = $this->db->one('SELECT id FROM taxonomy_terms WHERE taxonomy_id = :taxonomy_id AND term_key = :term_key', ['taxonomy_id' => (int) $taxonomy['id'], 'term_key' => $termKey]);
        return $term ? $this->findTerm((int) $term['id'], $languageCode) : null;
    }

    public function updateTerm(int $siteId, string $taxonomyKey, int $termId, string $languageCode, array $payload): ?array
    {
        $taxonomy = $this->findTaxonomy($siteId, $taxonomyKey);
        if (!$taxonomy) {
            return null;
        }
        $term = $this->db->one('SELECT * FROM taxonomy_terms WHERE id = :id AND taxonomy_id = :taxonomy_id', ['id' => $termId, 'taxonomy_id' => (int) $taxonomy['id']]);
        if (!$term) {
            return null;
        }
        $termKey = array_key_exists('term_key', $payload) || array_key_exists('key', $payload) ? $this->slugKey((string) ($payload['term_key'] ?? $payload['key'])) : (string) $term['term_key'];
        if ($termKey === '') {
            throw new \InvalidArgumentException('term_key ne peut pas être vide.');
        }
        $this->db->transaction(function () use ($taxonomy, $term, $termId, $siteId, $languageCode, $payload, $termKey): void {
            $this->db->run('UPDATE taxonomy_terms SET term_key = :term_key, parent_id = :parent_id, is_active = :is_active, sort_order = :sort_order WHERE id = :id', [
                'term_key' => $termKey,
                'parent_id' => array_key_exists('parent_id', $payload) ? ((int) $payload['parent_id'] > 0 ? (int) $payload['parent_id'] : null) : $term['parent_id'],
                'is_active' => array_key_exists('is_active', $payload) ? (!empty($payload['is_active']) ? 1 : 0) : (int) $term['is_active'],
                'sort_order' => array_key_exists('sort_order', $payload) ? (int) $payload['sort_order'] : (int) $term['sort_order'],
                'id' => $termId,
            ]);
            if (array_key_exists('name', $payload) || array_key_exists('label', $payload) || array_key_exists('slug', $payload) || array_key_exists('description', $payload) || array_key_exists('meta_title', $payload) || array_key_exists('meta_description', $payload)) {
                $current = $this->findTerm($termId, $languageCode) ?? [];
                $name = trim((string) ($payload['name'] ?? $payload['label'] ?? $current['name'] ?? ''));
                $slug = $this->slugKey((string) ($payload['slug'] ?? $current['slug'] ?? $termKey));
                if ($name === '' || $slug === '') {
                    throw new \InvalidArgumentException('name et slug ne peuvent pas être vides.');
                }
                $this->upsertTermLocalization($siteId, (int) $taxonomy['id'], $termId, $languageCode, $name, $slug, $payload);
            }
            $this->upsertTermLocalizationsFromPayload($siteId, (int) $taxonomy['id'], $termId, $payload);
        });
        return $this->findTerm($termId, $languageCode);
    }

    public function deleteTerm(int $siteId, string $taxonomyKey, int $termId): bool
    {
        $taxonomy = $this->findTaxonomy($siteId, $taxonomyKey);
        if (!$taxonomy) {
            return false;
        }
        $term = $this->db->one('SELECT id FROM taxonomy_terms WHERE id = :id AND taxonomy_id = :taxonomy_id', ['id' => $termId, 'taxonomy_id' => (int) $taxonomy['id']]);
        if (!$term) {
            return false;
        }
        $this->db->run('DELETE FROM taxonomy_terms WHERE id = :id', ['id' => $termId]);
        return true;
    }

    public function findTerm(int $termId, string $languageCode): ?array
    {
        $row = $this->db->one(
            'SELECT t.id AS taxonomy_id, t.site_id, t.taxonomy_key, tt.id, tt.parent_id, tt.term_key, tt.is_active, tt.sort_order,
                    COALESCE(ttl.name, fallback.name) AS name,
                    COALESCE(ttl.slug, fallback.slug) AS slug,
                    COALESCE(ttl.full_path, fallback.full_path) AS full_path,
                    COALESCE(ttl.description, fallback.description) AS description,
                    COALESCE(ttl.meta_title, fallback.meta_title) AS meta_title,
                    COALESCE(ttl.meta_description, fallback.meta_description) AS meta_description,
                    ttl.language_code IS NOT NULL AS has_current_localization
             FROM taxonomy_terms tt
             JOIN taxonomies t ON t.id = tt.taxonomy_id
             LEFT JOIN taxonomy_term_localizations ttl ON ttl.site_id = t.site_id AND ttl.taxonomy_id = t.id AND ttl.term_id = tt.id AND ttl.language_code = :language
             LEFT JOIN site_languages sl_default ON sl_default.site_id = t.site_id AND sl_default.is_default = 1
             LEFT JOIN taxonomy_term_localizations fallback ON fallback.site_id = t.site_id AND fallback.taxonomy_id = t.id AND fallback.term_id = tt.id AND fallback.language_code = sl_default.language_code
             WHERE tt.id = :term_id LIMIT 1',
            ['term_id' => $termId, 'language' => $languageCode]
        );
        if (!$row) {
            return null;
        }
        $row['localizations'] = $this->termLocalizations($termId);
        return $row;
    }

    private function upsertTermLocalization(int $siteId, int $taxonomyId, int $termId, string $languageCode, string $name, string $slug, array $payload): void
    {
        $fullPath = '/' . trim((string) ($payload['full_path'] ?? $slug), '/');
        $fullPath = strtolower($fullPath);
        $this->db->run(
            'INSERT INTO taxonomy_term_localizations(site_id, taxonomy_id, term_id, language_code, name, slug, full_path, description, meta_title, meta_description, canonical_url, json_ld)
             VALUES(:site_id, :taxonomy_id, :term_id, :language_code, :name, :slug, :full_path, :description, :meta_title, :meta_description, :canonical_url, :json_ld)
             ON CONFLICT(term_id, language_code) DO UPDATE SET name = excluded.name, slug = excluded.slug, full_path = excluded.full_path,
             description = excluded.description, meta_title = excluded.meta_title, meta_description = excluded.meta_description, canonical_url = excluded.canonical_url, json_ld = excluded.json_ld',
            [
                'site_id' => $siteId,
                'taxonomy_id' => $taxonomyId,
                'term_id' => $termId,
                'language_code' => $languageCode,
                'name' => $name,
                'slug' => $slug,
                'full_path' => $fullPath,
                'description' => $this->nullableString($payload['description'] ?? null),
                'meta_title' => $this->nullableString($payload['meta_title'] ?? null),
                'meta_description' => $this->nullableString($payload['meta_description'] ?? null),
                'canonical_url' => $this->nullableString($payload['canonical_url'] ?? null),
                'json_ld' => $this->nullableString($payload['json_ld'] ?? null),
            ]
        );
    }

    private function upsertTermLocalizationsFromPayload(int $siteId, int $taxonomyId, int $termId, array $payload): void
    {
        if (!isset($payload['localizations']) || !is_array($payload['localizations'])) {
            return;
        }
        foreach ($payload['localizations'] as $languageCode => $localization) {
            if (!is_array($localization)) {
                continue;
            }
            $name = trim((string) ($localization['name'] ?? ''));
            $slug = $this->slugKey((string) ($localization['slug'] ?? ''));
            if ($name === '' || $slug === '') {
                continue;
            }
            $this->upsertTermLocalization($siteId, $taxonomyId, $termId, strtolower((string) $languageCode), $name, $slug, $localization);
        }
    }

    private function termLocalizations(int $termId): array
    {
        $rows = $this->db->all(
            'SELECT language_code, name, slug, full_path, description, meta_title, meta_description, canonical_url, json_ld
             FROM taxonomy_term_localizations
             WHERE term_id = :term_id
             ORDER BY language_code',
            ['term_id' => $termId]
        );
        $localizations = [];
        foreach ($rows as $row) {
            $localizations[strtolower((string) $row['language_code'])] = [
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
                'full_path' => (string) ($row['full_path'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'meta_title' => (string) ($row['meta_title'] ?? ''),
                'meta_description' => (string) ($row['meta_description'] ?? ''),
                'canonical_url' => (string) ($row['canonical_url'] ?? ''),
                'json_ld' => (string) ($row['json_ld'] ?? ''),
            ];
        }
        return $localizations;
    }

    private function termLocalizationsForSite(int $siteId): array
    {
        $rows = $this->db->all(
            'SELECT ttl.term_id, ttl.language_code, ttl.name, ttl.slug, ttl.full_path, ttl.description, ttl.meta_title, ttl.meta_description, ttl.canonical_url, ttl.json_ld
             FROM taxonomy_term_localizations ttl
             WHERE ttl.site_id = :site_id
             ORDER BY ttl.term_id, ttl.language_code',
            ['site_id' => $siteId]
        );
        $byTerm = [];
        foreach ($rows as $row) {
            $byTerm[(int) $row['term_id']][strtolower((string) $row['language_code'])] = [
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
                'full_path' => (string) ($row['full_path'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'meta_title' => (string) ($row['meta_title'] ?? ''),
                'meta_description' => (string) ($row['meta_description'] ?? ''),
                'canonical_url' => (string) ($row['canonical_url'] ?? ''),
                'json_ld' => (string) ($row['json_ld'] ?? ''),
            ];
        }
        return $byTerm;
    }

    private function slugKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_\-]+/', '-', $value) ?: '';
        return trim($value, '-_');
    }

    private function nullableString(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : $text;
    }

    public function listTermsForSite(int $siteId, string $languageCode): array
    {
        $rows = $this->db->all(
            'SELECT t.id AS taxonomy_id, t.taxonomy_key, tt.id, tt.parent_id, tt.term_key, tt.is_active, tt.sort_order,
                    COALESCE(ttl.name, fallback.name) AS name,
                    COALESCE(ttl.slug, fallback.slug) AS slug,
                    COALESCE(ttl.full_path, fallback.full_path) AS full_path,
                    COALESCE(ttl.description, fallback.description) AS description,
                    ttl.meta_title, ttl.meta_description,
                    ttl.language_code IS NOT NULL AS has_current_localization
             FROM taxonomies t
             JOIN taxonomy_terms tt ON tt.taxonomy_id = t.id
             LEFT JOIN taxonomy_term_localizations ttl ON ttl.site_id = t.site_id AND ttl.taxonomy_id = t.id AND ttl.term_id = tt.id AND ttl.language_code = :language
             LEFT JOIN site_languages sl_default ON sl_default.site_id = t.site_id AND sl_default.is_default = 1
             LEFT JOIN taxonomy_term_localizations fallback ON fallback.site_id = t.site_id AND fallback.taxonomy_id = t.id AND fallback.term_id = tt.id AND fallback.language_code = sl_default.language_code
             WHERE t.site_id = :site_id
             ORDER BY t.sort_order, tt.parent_id, tt.sort_order, tt.term_key',
             ['site_id' => $siteId, 'language' => $languageCode]
        );
        $localizations = $this->termLocalizationsForSite($siteId);
        foreach ($rows as &$row) {
            $row['localizations'] = $localizations[(int) $row['id']] ?? [];
        }
        unset($row);
        return $rows;
    }

    public function listTermsForEntry(int $entryId, string $languageCode): array
    {
        return $this->db->all(
            'SELECT cet.entry_id, t.taxonomy_key, tt.id AS term_id, tt.term_key, ttl.name
             FROM content_entry_taxonomy_terms cet
             JOIN taxonomy_terms tt ON tt.id = cet.term_id
             JOIN taxonomies t ON t.id = tt.taxonomy_id
             LEFT JOIN taxonomy_term_localizations ttl ON ttl.site_id = t.site_id AND ttl.taxonomy_id = t.id AND ttl.term_id = tt.id AND ttl.language_code = :language
             WHERE cet.entry_id = :entry_id
             ORDER BY t.taxonomy_key, tt.sort_order, tt.term_key',
            ['entry_id' => $entryId, 'language' => $languageCode]
        );
    }

    public function setTermsForEntry(int $entryId, array $termIds): void
    {
        $this->db->run('DELETE FROM content_entry_taxonomy_terms WHERE entry_id = :entry_id', ['entry_id' => $entryId]);
        $sort = 1;
        foreach (array_unique(array_filter(array_map('intval', $termIds))) as $termId) {
            $this->db->run('INSERT INTO content_entry_taxonomy_terms(entry_id, term_id, sort_order) VALUES(:entry_id, :term_id, :sort_order)', [
                'entry_id' => $entryId, 'term_id' => $termId, 'sort_order' => $sort++,
            ]);
        }
    }


    /** @return array{categories:list<array<string,mixed>>,tags:list<array<string,mixed>>} */
    public function articleFacets(int $siteId, string $languageCode, int $tagLimit = 12): array
    {
        $tagLimit = max(1, min(50, $tagLimit));
        $baseSql = "FROM content_entry_taxonomy_terms cet
            JOIN content_entries ce ON ce.id = cet.entry_id
            JOIN content_types ct ON ct.id = ce.content_type_id
            JOIN taxonomy_terms tt ON tt.id = cet.term_id
            JOIN taxonomies t ON t.id = tt.taxonomy_id
            LEFT JOIN taxonomy_term_localizations ttl ON ttl.site_id = t.site_id
               AND ttl.taxonomy_id = t.id
               AND ttl.term_id = tt.id
               AND ttl.language_code = :language
            LEFT JOIN taxonomy_term_localizations ttl_fallback ON ttl_fallback.site_id = t.site_id
               AND ttl_fallback.taxonomy_id = t.id
               AND ttl_fallback.term_id = tt.id
               AND ttl_fallback.language_code = 'fr'
            JOIN routes r ON r.site_id = ce.site_id
               AND r.resource_type = 'content_entry'
               AND r.resource_id = ce.id
               AND r.language_code = :language
               AND r.status = 'active'
               AND r.is_primary = 1
               AND r.is_canonical = 1
            LEFT JOIN seo_metadata sm ON sm.site_id = ce.site_id
               AND sm.resource_type = 'content_entry'
               AND sm.resource_id = ce.id
               AND sm.language_code = :language
            WHERE ce.site_id = :site_id
              AND ct.type_key = 'article'
              AND t.site_id = :site_id
              AND COALESCE(sm.meta_robots, 'index,follow') NOT LIKE '%noindex%'
              AND EXISTS (
                  SELECT 1 FROM public_content_snapshots pcs
                  JOIN content_entry_publications cep ON cep.site_id = pcs.site_id
                     AND cep.entry_id = pcs.resource_id
                     AND cep.language_code = pcs.language_code
                     AND cep.published_revision_id = pcs.source_published_revision_id
                     AND cep.workflow_status = 'published'
                  WHERE pcs.site_id = ce.site_id
                    AND pcs.resource_type = 'content_entry'
                    AND pcs.resource_id = ce.id
                    AND pcs.language_code = :language
                    AND pcs.route_path = r.full_path
              )";

        $select = "SELECT t.taxonomy_key, tt.id AS term_id, tt.term_key,
                COALESCE(ttl.name, ttl_fallback.name, tt.term_key) AS name,
                COALESCE(ttl.slug, ttl_fallback.slug, tt.term_key) AS slug,
                COALESCE(ttl.full_path, ttl_fallback.full_path, '/' || t.taxonomy_key || '/' || tt.term_key) AS full_path,
                COALESCE(ttl.description, ttl_fallback.description, '') AS description,
                COUNT(DISTINCT ce.id) AS article_count";
        $group = "GROUP BY t.taxonomy_key, tt.id, tt.term_key,
                COALESCE(ttl.name, ttl_fallback.name, tt.term_key),
                COALESCE(ttl.slug, ttl_fallback.slug, tt.term_key),
                COALESCE(ttl.full_path, ttl_fallback.full_path, '/' || t.taxonomy_key || '/' || tt.term_key),
                COALESCE(ttl.description, ttl_fallback.description, '')";
        $params = ['site_id' => $siteId, 'language' => $languageCode];

        $categories = $this->db->all(
            "$select $baseSql AND t.taxonomy_key = 'categories' $group ORDER BY name COLLATE NOCASE ASC",
            $params
        );
        $tags = $this->db->all(
            "$select $baseSql AND t.taxonomy_key = 'tags' $group ORDER BY article_count DESC, name COLLATE NOCASE ASC LIMIT :limit",
            $params + ['limit' => $tagLimit]
        );

        return ['categories' => $categories, 'tags' => $tags];
    }

    public function findArchiveByPath(int $siteId, string $path, string $languageCode): ?array
    {
        $term = $this->db->one(
            'SELECT t.taxonomy_key, tt.id AS term_id, tt.term_key, ttl.name, ttl.slug, ttl.full_path, ttl.description, ttl.meta_title, ttl.meta_description
             FROM taxonomies t
             JOIN taxonomy_terms tt ON tt.taxonomy_id = t.id
             JOIN taxonomy_term_localizations ttl ON ttl.site_id = t.site_id AND ttl.taxonomy_id = t.id AND ttl.term_id = tt.id AND ttl.language_code = :language
             WHERE t.site_id = :site_id AND ttl.full_path = :full_path LIMIT 1',
            ['site_id' => $siteId, 'language' => $languageCode, 'full_path' => $path]
        );
        if (!$term) {
            return null;
        }

        $entryRows = $this->db->all(
            "SELECT ce.id
             FROM content_entry_taxonomy_terms cet
             JOIN content_entries ce ON ce.id = cet.entry_id
             WHERE cet.term_id = :term_id
               AND EXISTS (
                   SELECT 1 FROM content_entry_publications cep
                   WHERE cep.site_id = ce.site_id AND cep.entry_id = ce.id AND cep.language_code = :language
               )
             ORDER BY COALESCE(ce.published_at, ce.updated_at) DESC",
            ['term_id' => $term['term_id'], 'language' => $languageCode]
        );

        $items = [];
        foreach ($entryRows as $row) {
            $aggregate = $this->content->getPublishedById((int) $row['id'], $languageCode);
            if (!$aggregate) {
                continue;
            }
            $items[] = [
                'id' => (int) $aggregate['entry']['id'],
                'entry_key' => (string) $aggregate['entry']['entry_key'],
                'type_key' => (string) $aggregate['entry']['type_key'],
                'title' => (string) ($aggregate['localization']['title'] ?? ''),
                'summary' => (string) ($aggregate['localization']['summary'] ?? ''),
                'full_path' => (string) ($aggregate['route']['full_path'] ?? ''),
            ];
        }

        return ['term' => $term, 'items' => $items];
    }
}
