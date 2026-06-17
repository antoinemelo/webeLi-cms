<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sql;

use App\Application\Search\PublicSearchReadRepository;

use App\Core\Database;

final class SqlPublicSearchReadRepository implements PublicSearchReadRepository
{
    public function __construct(private readonly Database $db) {}

    public function searchDocuments(string $query, string $languageCode, int $siteId, array $filters = []): array
        {
            $ftsQuery = $this->buildFtsQuery($query);
            if ($ftsQuery === '') {
                return [];
            }
    
            $typeKey = trim((string) ($filters['type'] ?? ''));
            $taxonomyKey = trim((string) ($filters['taxonomy'] ?? ''));
            $term = trim((string) ($filters['term'] ?? ''));
            $limit = max(1, min(50, (int) ($filters['limit'] ?? 20)));
    
            $where = [
                'sd.site_id = :site_id',
                'ce.site_id = :site_id',
                'sd.language_code = :language',
                "sd.resource_type = 'content_entry'",
                "ce.status = 'published'",
                'ce.is_active = 1',
                "COALESCE(sm.meta_robots, 'index,follow') NOT LIKE '%noindex%'",
                'search_documents_fts MATCH :q',
            ];
            $params = [
                'site_id' => $siteId,
                'language' => $languageCode,
                'q' => $ftsQuery,
                'query_norm' => mb_strtolower(trim($query)),
                'title_like' => '%' . $this->escapeLike(trim($query)) . '%',
                'path_like' => '%' . $this->escapeLike(str_replace(' ', '-', mb_strtolower(trim($query)))) . '%',
                'limit' => $limit,
            ];
    
            if ($typeKey !== '') {
                $where[] = 'ct.type_key = :type_key';
                $params['type_key'] = $typeKey;
            }
            if ($taxonomyKey !== '' || $term !== '') {
                $taxonomyPredicates = [];
                if ($taxonomyKey !== '') {
                    $taxonomyPredicates[] = 'tx.taxonomy_key = :taxonomy_key';
                    $params['taxonomy_key'] = $taxonomyKey;
                }
                if ($term !== '') {
                    $taxonomyPredicates[] = '(tt.term_key = :term OR ttl.slug = :term OR ttl.name LIKE :term_like ESCAPE \'\\\')';
                    $params['term'] = $term;
                    $params['term_like'] = '%' . $this->escapeLike($term) . '%';
                }
                $where[] = 'EXISTS (
                    SELECT 1
                    FROM content_entry_taxonomy_terms cett
                    JOIN taxonomy_terms tt ON tt.id = cett.term_id AND tt.is_active = 1
                    JOIN taxonomies tx ON tx.id = tt.taxonomy_id AND tx.site_id = sd.site_id
                    LEFT JOIN taxonomy_term_localizations ttl ON ttl.term_id = tt.id AND ttl.language_code = sd.language_code
                    WHERE cett.entry_id = ce.id
                      AND ' . implode(' AND ', $taxonomyPredicates) . '
                )';
            }
    
            $whereSql = implode("\n                  AND ", $where);
    
            return $this->db->all(
                "SELECT
                    sd.*,
                    ce.entry_key,
                    ct.type_key,
                    ct.name AS type_label,
                    (
                        bm25(search_documents_fts, 8.0, 3.0, 1.0)
                        - CASE WHEN lower(COALESCE(sd.title, '')) = :query_norm THEN 6.0 ELSE 0 END
                        - CASE WHEN sd.title LIKE :title_like ESCAPE '\\' THEN 2.0 ELSE 0 END
                        - CASE WHEN sd.path LIKE :path_like ESCAPE '\\' THEN 0.75 ELSE 0 END
                    ) AS search_rank,
                    (
                        SELECT group_concat(DISTINCT COALESCE(ttl2.name, tt2.term_key))
                        FROM content_entry_taxonomy_terms cett2
                        JOIN taxonomy_terms tt2 ON tt2.id = cett2.term_id AND tt2.is_active = 1
                        JOIN taxonomies tx2 ON tx2.id = tt2.taxonomy_id AND tx2.site_id = sd.site_id
                        LEFT JOIN taxonomy_term_localizations ttl2 ON ttl2.term_id = tt2.id AND ttl2.language_code = sd.language_code
                        WHERE cett2.entry_id = ce.id
                    ) AS taxonomy_labels
                 FROM search_documents_fts
                 JOIN search_documents sd ON sd.id = search_documents_fts.rowid
                 JOIN content_entries ce ON ce.id = sd.resource_id
                 JOIN content_types ct ON ct.id = ce.content_type_id
                 JOIN public_content_snapshots pcs ON pcs.site_id = sd.site_id
                    AND pcs.resource_type = sd.resource_type
                    AND pcs.resource_id = sd.resource_id
                    AND pcs.language_code = sd.language_code
                    AND pcs.source_published_revision_id = sd.source_published_revision_id
                    AND pcs.source_revision_checksum_sha256 = sd.source_revision_checksum_sha256
                 LEFT JOIN seo_metadata sm ON sm.site_id = sd.site_id
                    AND sm.resource_type = sd.resource_type
                    AND sm.resource_id = sd.resource_id
                    AND sm.language_code = sd.language_code
                 WHERE {$whereSql}
                 ORDER BY search_rank ASC, sd.updated_at DESC, sd.id DESC
                 LIMIT :limit",
                $params
            );
        }

    private function buildFtsQuery(string $query): string
        {
            if (!preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($query), $matches)) {
                return '';
            }
    
            $terms = [];
            foreach ($matches[0] as $term) {
                $term = trim($term);
                if (mb_strlen($term) < 2) {
                    continue;
                }
                $terms[$term] = $term . '*';
            }
    
            return implode(' ', array_values($terms));
        }

    private function escapeLike(string $value): string
        {
            return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
        }
}
