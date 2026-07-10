<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sql;

use App\Application\Content\Read\PublicContentReadRepository;

use App\Core\Database;

final class SqlPublicContentReadRepository implements PublicContentReadRepository
{
    public function __construct(private readonly Database $db, private readonly SqlEditorialContentReadRepository $entries, private readonly SqlPublicRouteReadRepository $routes) {}

    public function listPublishedByType(int $siteId, string $typeKey, string $languageCode): array
        {
            $entries = $this->db->all(
                "SELECT ce.id
                 FROM content_entries ce
                 JOIN content_types ct ON ct.id = ce.content_type_id
                 WHERE ce.site_id = :site_id
                   AND ct.type_key = :type_key
                   AND EXISTS (
                       SELECT 1 FROM public_content_snapshots pcs
                       WHERE pcs.site_id = ce.site_id
                         AND pcs.resource_type = 'content_entry'
                         AND pcs.resource_id = ce.id
                         AND pcs.language_code = :language
                   )
                 ORDER BY COALESCE(ce.published_at, ce.updated_at) DESC, ce.id DESC",
                ['site_id' => $siteId, 'type_key' => $typeKey, 'language' => $languageCode]
            );
    
            $items = [];
            foreach ($entries as $entry) {
                $aggregate = $this->entries->getPublishedEntryAggregate((int) $entry['id'], $languageCode);
                if (!$aggregate) {
                    continue;
                }
                $items[] = [
                    'id' => (int) $aggregate['entry']['id'],
                    'entry_key' => (string) $aggregate['entry']['entry_key'],
                    'status' => (string) $aggregate['entry']['status'],
                    'published_at' => (string) ($aggregate['entry']['published_at'] ?? ''),
                    'title' => (string) ($aggregate['localization']['title'] ?? ''),
                    'summary' => (string) ($aggregate['seo']['meta_description'] ?? ''),
                    'full_path' => (string) ($aggregate['route']['full_path'] ?? ''),
                    'meta_title' => (string) ($aggregate['seo']['meta_title'] ?? ''),
                    'meta_description' => (string) ($aggregate['seo']['meta_description'] ?? ''),
                ];
            }
            return $items;
        }

    public function listPublishedArticlesIndex(int $siteId, string $languageCode, int $limit = 6, int $offset = 0, ?string $categorySlug = null, ?string $tagSlug = null): array
        {
            $limit = max(1, min(48, $limit));
            $offset = max(0, $offset);
            $categorySlug = trim((string) $categorySlug) ?: null;
            $tagSlug = trim((string) $tagSlug) ?: null;
    
            $params = [
                'site_id' => $siteId,
                'language' => $languageCode,
            ];
            $where = [
                'ce.site_id = :site_id',
                "ct.type_key = 'article'",
                "r.status = 'active'",
                'r.is_primary = 1',
                'r.is_canonical = 1',
                "COALESCE(sm.meta_robots, 'index,follow') NOT LIKE '%noindex%'",
            ];
    
            if ($categorySlug !== null) {
                $where[] = "EXISTS (
                    SELECT 1
                    FROM content_entry_taxonomy_terms cet_filter
                    JOIN taxonomy_terms tt_filter ON tt_filter.id = cet_filter.term_id
                    JOIN taxonomies t_filter ON t_filter.id = tt_filter.taxonomy_id
                    LEFT JOIN taxonomy_term_localizations ttl_filter ON ttl_filter.term_id = tt_filter.id
                       AND ttl_filter.taxonomy_id = t_filter.id
                       AND ttl_filter.site_id = t_filter.site_id
                       AND ttl_filter.language_code = :language
                    LEFT JOIN taxonomy_term_localizations ttl_filter_fallback ON ttl_filter_fallback.term_id = tt_filter.id
                       AND ttl_filter_fallback.taxonomy_id = t_filter.id
                       AND ttl_filter_fallback.site_id = t_filter.site_id
                       AND ttl_filter_fallback.language_code = 'fr'
                    WHERE cet_filter.entry_id = ce.id
                      AND t_filter.taxonomy_key = 'categories'
                      AND COALESCE(ttl_filter.slug, ttl_filter_fallback.slug, tt_filter.term_key) = :category_slug
                )";
                $params['category_slug'] = $categorySlug;
            }
    
            if ($tagSlug !== null) {
                $where[] = "EXISTS (
                    SELECT 1
                    FROM content_entry_taxonomy_terms cet_filter
                    JOIN taxonomy_terms tt_filter ON tt_filter.id = cet_filter.term_id
                    JOIN taxonomies t_filter ON t_filter.id = tt_filter.taxonomy_id
                    LEFT JOIN taxonomy_term_localizations ttl_filter ON ttl_filter.term_id = tt_filter.id
                       AND ttl_filter.taxonomy_id = t_filter.id
                       AND ttl_filter.site_id = t_filter.site_id
                       AND ttl_filter.language_code = :language
                    LEFT JOIN taxonomy_term_localizations ttl_filter_fallback ON ttl_filter_fallback.term_id = tt_filter.id
                       AND ttl_filter_fallback.taxonomy_id = t_filter.id
                       AND ttl_filter_fallback.site_id = t_filter.site_id
                       AND ttl_filter_fallback.language_code = 'fr'
                    WHERE cet_filter.entry_id = ce.id
                      AND t_filter.taxonomy_key = 'tags'
                      AND COALESCE(ttl_filter.slug, ttl_filter_fallback.slug, tt_filter.term_key) = :tag_slug
                )";
                $params['tag_slug'] = $tagSlug;
            }
    
            $whereSql = implode(' AND ', $where);
            $fromSql = "FROM content_entries ce
                JOIN content_types ct ON ct.id = ce.content_type_id
                JOIN routes r ON r.site_id = ce.site_id
                   AND r.resource_type = 'content_entry'
                   AND r.resource_id = ce.id
                   AND r.language_code = :language
                LEFT JOIN seo_metadata sm ON sm.site_id = ce.site_id
                   AND sm.resource_type = 'content_entry'
                   AND sm.resource_id = ce.id
                   AND sm.language_code = :language
                JOIN public_content_snapshots pcs ON pcs.site_id = ce.site_id
                   AND pcs.resource_type = 'content_entry'
                   AND pcs.resource_id = ce.id
                   AND pcs.language_code = :language
                   AND pcs.route_path = r.full_path
                JOIN content_entry_publications cep ON cep.site_id = pcs.site_id
                   AND cep.entry_id = pcs.resource_id
                   AND cep.language_code = pcs.language_code
                   AND cep.published_revision_id = pcs.source_published_revision_id
                   AND cep.workflow_status = 'published'";
    
            $totalRow = $this->db->one("SELECT COUNT(DISTINCT ce.id) AS total $fromSql WHERE $whereSql", $params);
            $total = (int) ($totalRow['total'] ?? 0);
            $rows = $this->db->all(
                "SELECT DISTINCT ce.id
                 $fromSql
                 WHERE $whereSql
                 ORDER BY COALESCE(
                        NULLIF(json_extract(pcs.document_json, '$.fields.display_published_at'), ''),
                        NULLIF(json_extract(pcs.document_json, '$.fields.publication_date'), ''),
                        ce.published_at,
                        ce.updated_at
                    ) DESC, ce.id DESC
                 LIMIT :limit OFFSET :offset",
                $params + ['limit' => $limit, 'offset' => $offset]
            );
    
            $items = [];
            foreach ($rows as $row) {
                $aggregate = $this->entries->getPublishedEntryAggregate((int) $row['id'], $languageCode);
                if (!$aggregate) {
                    continue;
                }
                $terms = $this->entries->taxonomiesForEntry((int) $row['id'], $languageCode);
                $publicFields = $this->publicDocumentFields($aggregate);
                $displayPublishedAt = $this->displayPublishedAt($publicFields, (string) ($aggregate['entry']['published_at'] ?? $aggregate['publication']['published_at'] ?? ''));
                $items[] = [
                    'id' => (int) $aggregate['entry']['id'],
                    'entry_key' => (string) $aggregate['entry']['entry_key'],
                    'type_key' => (string) $aggregate['entry']['type_key'],
                    'title' => (string) ($aggregate['localization']['title'] ?? ''),
                    'summary' => (string) ($aggregate['seo']['meta_description'] ?? ''),
                    'full_path' => (string) ($aggregate['route']['full_path'] ?? ''),
                    'published_at' => $displayPublishedAt,
                    'updated_at' => (string) ($aggregate['entry']['updated_at'] ?? ''),
                    'author_name' => $this->displayAuthorName($publicFields),
                    'taxonomies' => $terms,
                    'categories' => array_values(array_filter($terms, fn(array $term): bool => ($term['taxonomy_key'] ?? '') === 'categories')),
                    'tags' => array_values(array_filter($terms, fn(array $term): bool => ($term['taxonomy_key'] ?? '') === 'tags')),
                ];
            }
    
            return [
                'items' => $items,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $total,
            ];
        }

    public function listPublishedHeadless(int $siteId, string $languageCode, array $filters = []): array
        {
            $type = trim((string) ($filters['type'] ?? ''));
            $taxonomy = trim((string) ($filters['taxonomy'] ?? ''));
            $term = trim((string) ($filters['term'] ?? ''));
            $limit = max(1, min(100, (int) ($filters['limit'] ?? 24)));
            $offset = max(0, (int) ($filters['offset'] ?? 0));
    
            $params = ['site_id' => $siteId, 'language' => $languageCode];
            $where = [
                'ce.site_id = :site_id',
                'ce.is_active = 1',
                "ce.status = 'published'",
                "r.status = 'active'",
                'r.is_primary = 1',
                'r.is_canonical = 1',
                "COALESCE(sm.meta_robots, 'index,follow') NOT LIKE '%noindex%'",
            ];
            if ($type !== '') {
                $where[] = 'ct.type_key = :type_key';
                $params['type_key'] = $type;
            }
            if ($taxonomy !== '') {
                $where[] = "EXISTS (
                    SELECT 1
                    FROM content_entry_taxonomy_terms cet_filter
                    JOIN taxonomy_terms tt_filter ON tt_filter.id = cet_filter.term_id AND tt_filter.is_active = 1
                    JOIN taxonomies t_filter ON t_filter.id = tt_filter.taxonomy_id AND t_filter.is_active = 1
                    LEFT JOIN taxonomy_term_localizations ttl_filter ON ttl_filter.term_id = tt_filter.id
                       AND ttl_filter.taxonomy_id = t_filter.id
                       AND ttl_filter.site_id = t_filter.site_id
                       AND ttl_filter.language_code = :language
                    LEFT JOIN taxonomy_term_localizations ttl_filter_fallback ON ttl_filter_fallback.term_id = tt_filter.id
                       AND ttl_filter_fallback.taxonomy_id = t_filter.id
                       AND ttl_filter_fallback.site_id = t_filter.site_id
                       AND ttl_filter_fallback.language_code = 'fr'
                    WHERE cet_filter.entry_id = ce.id
                      AND t_filter.taxonomy_key = :taxonomy_key
                      AND (:term_slug = '' OR COALESCE(ttl_filter.slug, ttl_filter_fallback.slug, tt_filter.term_key) = :term_slug OR tt_filter.term_key = :term_slug)
                )";
                $params['taxonomy_key'] = $taxonomy;
                $params['term_slug'] = $term;
            }
    
            $whereSql = implode(' AND ', $where);
            $fromSql = "FROM content_entries ce
                JOIN content_types ct ON ct.id = ce.content_type_id AND ct.api_enabled = 1
                JOIN public_content_snapshots pcs ON pcs.site_id = ce.site_id
                   AND pcs.resource_type = 'content_entry'
                   AND pcs.resource_id = ce.id
                   AND pcs.language_code = :language
                JOIN content_entry_publications cep ON cep.site_id = pcs.site_id
                   AND cep.entry_id = pcs.resource_id
                   AND cep.language_code = pcs.language_code
                   AND cep.published_revision_id = pcs.source_published_revision_id
                   AND cep.workflow_status = 'published'
                JOIN routes r ON r.site_id = ce.site_id
                   AND r.resource_type = 'content_entry'
                   AND r.resource_id = ce.id
                   AND r.language_code = :language
                   AND r.full_path = pcs.route_path
                LEFT JOIN seo_metadata sm ON sm.site_id = ce.site_id
                   AND sm.resource_type = 'content_entry'
                   AND sm.resource_id = ce.id
                   AND sm.language_code = :language";
            $totalRow = $this->db->one("SELECT COUNT(DISTINCT ce.id) AS total $fromSql WHERE $whereSql", $params);
            $total = (int) ($totalRow['total'] ?? 0);
            $rows = $this->db->all(
                "SELECT DISTINCT ce.id
                 $fromSql
                 WHERE $whereSql
                 ORDER BY COALESCE(pcs.published_at, ce.published_at, ce.updated_at) DESC, ce.id DESC
                 LIMIT :limit OFFSET :offset",
                $params + ['limit' => $limit, 'offset' => $offset]
            );
    
            $items = [];
            foreach ($rows as $row) {
                $aggregate = $this->entries->getPublishedEntryAggregate((int) $row['id'], $languageCode);
                if (!$aggregate) {
                    continue;
                }
                $terms = $this->entries->taxonomiesForEntry((int) $row['id'], $languageCode);
                $snapshot = $aggregate['public_snapshot'] ?? [];
                $items[] = [
                    'id' => (int) $aggregate['entry']['id'],
                    'entry_key' => (string) $aggregate['entry']['entry_key'],
                    'type_key' => (string) $aggregate['entry']['type_key'],
                    'title' => (string) ($snapshot['title'] ?? $aggregate['localization']['title'] ?? ''),
                    'summary' => (string) ($aggregate['seo']['meta_description'] ?? ''),
                    'full_path' => (string) ($aggregate['route']['full_path'] ?? $snapshot['route_path'] ?? ''),
                    'published_at' => (string) ($snapshot['published_at'] ?? $aggregate['entry']['published_at'] ?? ''),
                    'updated_at' => (string) ($aggregate['entry']['updated_at'] ?? ''),
                    'meta_title' => (string) ($aggregate['seo']['meta_title'] ?? $snapshot['title'] ?? ''),
                    'meta_description' => (string) ($aggregate['seo']['meta_description'] ?? ''),
                    'meta_robots' => (string) ($aggregate['seo']['meta_robots'] ?? 'index,follow'),
                    'taxonomies' => $terms,
                ];
            }
    
            return [
                'items' => $items,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $total,
            ];
        }

    public function searchPublishedHeadless(int $siteId, string $languageCode, string $query, array $filters = []): array
        {
            $query = trim(mb_substr($query, 0, 120));
            $limit = max(1, min(100, (int) ($filters['limit'] ?? 20)));
            $offset = max(0, (int) ($filters['offset'] ?? 0));
            if ($query === '') {
                return ['items' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset, 'has_more' => false];
            }
    
            $type = trim((string) ($filters['type'] ?? ''));
            $taxonomy = trim((string) ($filters['taxonomy'] ?? ''));
            $term = trim((string) ($filters['term'] ?? ''));
            $params = [
                'site_id' => $siteId,
                'language' => $languageCode,
                'q_like' => '%' . $this->escapeLike($query) . '%',
            ];
            $where = [
                'sd.site_id = :site_id',
                'sd.language_code = :language',
                "sd.resource_type = 'content_entry'",
                '(sd.title LIKE :q_like ESCAPE \'\\\' OR sd.summary LIKE :q_like ESCAPE \'\\\' OR sd.search_text LIKE :q_like ESCAPE \'\\\')',
            ];
            if ($type !== '') {
                $where[] = 'ct.type_key = :type_key';
                $params['type_key'] = $type;
            }
            if ($taxonomy !== '') {
                $where[] = "EXISTS (
                    SELECT 1
                    FROM content_entry_taxonomy_terms cet_filter
                    JOIN taxonomy_terms tt_filter ON tt_filter.id = cet_filter.term_id AND tt_filter.is_active = 1
                    JOIN taxonomies t_filter ON t_filter.id = tt_filter.taxonomy_id AND t_filter.is_active = 1
                    LEFT JOIN taxonomy_term_localizations ttl_filter ON ttl_filter.term_id = tt_filter.id
                       AND ttl_filter.taxonomy_id = t_filter.id
                       AND ttl_filter.site_id = t_filter.site_id
                       AND ttl_filter.language_code = :language
                    LEFT JOIN taxonomy_term_localizations ttl_filter_fallback ON ttl_filter_fallback.term_id = tt_filter.id
                       AND ttl_filter_fallback.taxonomy_id = t_filter.id
                       AND ttl_filter_fallback.site_id = t_filter.site_id
                       AND ttl_filter_fallback.language_code = 'fr'
                    WHERE cet_filter.entry_id = ce.id
                      AND t_filter.taxonomy_key = :taxonomy_key
                      AND (:term_slug = '' OR COALESCE(ttl_filter.slug, ttl_filter_fallback.slug, tt_filter.term_key) = :term_slug OR tt_filter.term_key = :term_slug)
                )";
                $params['taxonomy_key'] = $taxonomy;
                $params['term_slug'] = $term;
            }
            $whereSql = implode(' AND ', $where);
            $fromSql = "FROM search_documents sd
                JOIN public_content_snapshots pcs ON pcs.site_id = sd.site_id
                   AND pcs.resource_type = sd.resource_type
                   AND pcs.resource_id = sd.resource_id
                   AND pcs.language_code = sd.language_code
                   AND pcs.source_published_revision_id = sd.source_published_revision_id
                JOIN content_entry_publications cep ON cep.site_id = pcs.site_id
                   AND cep.entry_id = pcs.resource_id
                   AND cep.language_code = pcs.language_code
                   AND cep.published_revision_id = pcs.source_published_revision_id
                   AND cep.workflow_status = 'published'
                JOIN content_entries ce ON ce.id = sd.resource_id AND ce.site_id = sd.site_id AND ce.is_active = 1 AND ce.status = 'published'
                JOIN content_types ct ON ct.id = ce.content_type_id AND ct.api_enabled = 1
                JOIN routes r ON r.site_id = sd.site_id
                   AND r.resource_type = sd.resource_type
                   AND r.resource_id = sd.resource_id
                   AND r.language_code = sd.language_code
                   AND r.full_path = sd.path
                   AND r.status = 'active'
                   AND r.is_primary = 1
                   AND r.is_canonical = 1
                LEFT JOIN seo_metadata sm ON sm.site_id = sd.site_id
                   AND sm.resource_type = sd.resource_type
                   AND sm.resource_id = sd.resource_id
                   AND sm.language_code = sd.language_code";
            $totalRow = $this->db->one("SELECT COUNT(DISTINCT sd.id) AS total $fromSql WHERE $whereSql", $params);
            $total = (int) ($totalRow['total'] ?? 0);
            $rows = $this->db->all(
                "SELECT DISTINCT sd.resource_id, sd.path, sd.title, sd.summary, sd.updated_at, ct.type_key, sm.meta_title, sm.meta_description, sm.meta_robots
                 $fromSql
                 WHERE $whereSql
                 ORDER BY sd.updated_at DESC, sd.resource_id DESC
                 LIMIT :limit OFFSET :offset",
                $params + ['limit' => $limit, 'offset' => $offset]
            );
            $items = array_map(static fn(array $row): array => [
                'id' => (int) ($row['resource_id'] ?? 0),
                'type' => (string) ($row['type_key'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
                'summary' => (string) ($row['summary'] ?? ''),
                'path' => (string) ($row['path'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
                'seo' => [
                    'title' => (string) ($row['meta_title'] ?? $row['title'] ?? ''),
                    'description' => (string) ($row['meta_description'] ?? $row['summary'] ?? ''),
                    'robots' => (string) ($row['meta_robots'] ?? 'index,follow'),
                ],
            ], $rows);
            return ['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset, 'has_more' => ($offset + $limit) < $total];
        }

    public function listPublicMedia(int $siteId, string $languageCode, int $limit = 24, int $offset = 0, string $type = ''): array
        {
            $limit = max(1, min(100, $limit));
            $offset = max(0, $offset);

            $filterParams = ['site_id' => $siteId];
            $where = ["ma.site_id = :site_id", "ma.lifecycle_status = 'ready'", "ma.validation_status = 'valid'", 'ma.public_path IS NOT NULL'];
            if ($type !== '') {
                $where[] = 'ma.media_type = :media_type';
                $filterParams['media_type'] = $type;
            }

            $whereSql = implode(' AND ', $where);
            $totalRow = $this->db->one("SELECT COUNT(*) AS total FROM media_assets ma WHERE $whereSql", $filterParams);
            $total = (int) ($totalRow['total'] ?? 0);

            $selectParams = $filterParams + [
                'language' => $languageCode,
                'limit' => $limit,
                'offset' => $offset,
            ];
            $rows = $this->db->all(
                "SELECT ma.*, mal.alt_text, mal.caption, mal.title AS localized_title
                 FROM media_assets ma
                 LEFT JOIN media_asset_localizations mal ON mal.media_id = ma.id AND mal.language_code = :language
                 WHERE $whereSql
                 ORDER BY ma.updated_at DESC, ma.id DESC
                 LIMIT :limit OFFSET :offset",
                $selectParams
            );
            $items = array_map(static function (array $row): array {
                $publicPath = trim((string) ($row['public_path'] ?? $row['path'] ?? ''));
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'uuid' => (string) ($row['uuid'] ?? ''),
                    'url' => $publicPath !== '' ? '/' . ltrim($publicPath, '/') : '',
                    'filename' => (string) ($row['filename'] ?? ''),
                    'mime_type' => (string) ($row['mime_type'] ?? ''),
                    'media_type' => (string) ($row['media_type'] ?? ''),
                    'size_bytes' => (int) ($row['size_bytes'] ?? 0),
                    'width' => isset($row['width']) ? (int) $row['width'] : null,
                    'height' => isset($row['height']) ? (int) $row['height'] : null,
                    'alt_text' => (string) ($row['alt_text'] ?? ''),
                    'caption' => (string) ($row['caption'] ?? ''),
                    'title' => (string) ($row['localized_title'] ?? ''),
                    'updated_at' => (string) ($row['updated_at'] ?? ''),
                ];
            }, $rows);
            return ['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset, 'has_more' => ($offset + $limit) < $total];
        }

    public function getPublishedById(int $entryId, string $languageCode): ?array
    {
        return $this->entries->getPublishedEntryAggregate($entryId, $languageCode);
    }

    public function getPublishedByPath(int $siteId, string $path, string $languageCode): ?array
        {
            $route = $this->db->one("SELECT * FROM routes WHERE site_id = :site_id AND language_code = :language AND full_path = :full_path AND status = 'active' AND is_canonical = 1 LIMIT 1", [
                'site_id' => $siteId,
                'language' => $languageCode,
                'full_path' => $path,
            ]);
            if (!$route || $route['resource_type'] !== 'content_entry') {
                return null;
            }
            return $this->entries->getPublishedEntryAggregate((int) $route['resource_id'], $languageCode);
        }

    public function getPublishedByTypeAndSlug(int $siteId, string $typeKey, string $slug, string $languageCode): ?array
        {
            return $this->getPublishedByPath($siteId, $this->routes->buildPath($typeKey, $slug), $languageCode);
        }

    private function publicDocumentFields(array $aggregate): array
        {
            $document = json_decode((string) ($aggregate['public_snapshot']['document_json'] ?? '{}'), true);
            return is_array($document) && is_array($document['fields'] ?? null) ? $document['fields'] : [];
        }

    private function displayPublishedAt(array $fields, string $fallback): string
        {
            foreach (['display_published_at', 'published_at', 'publication_date'] as $key) {
                $value = trim((string) ($fields[$key] ?? ''));
                if ($value === '') {
                    continue;
                }
                $timestamp = strtotime($value);
                if ($timestamp !== false) {
                    return gmdate('Y-m-d H:i:s', $timestamp);
                }
                return $value;
            }
            return $fallback;
        }

    private function displayAuthorName(array $fields): string
        {
            foreach (['author_name', 'display_author_name', 'byline'] as $key) {
                $value = trim((string) ($fields[$key] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
            return '';
        }

    private function escapeLike(string $value): string
        {
            return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
        }
}
