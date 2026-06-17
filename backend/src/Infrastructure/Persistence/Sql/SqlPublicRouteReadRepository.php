<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sql;

use App\Application\Routing\PublicRouteReadRepository;

use App\Core\Database;

final class SqlPublicRouteReadRepository implements PublicRouteReadRepository
{
    public function __construct(private readonly Database $db) {}

    public function listPublishedRoutes(int $siteId, string $languageCode): array
        {
            return $this->db->all(
                "SELECT DISTINCT
                        r.full_path,
                        r.resource_type,
                        r.resource_id,
                        r.updated_at AS route_updated_at,
                        COALESCE(pcs.title, cel.title, r.slug, r.full_path) AS title,
                        CASE WHEN r.resource_type = 'content_entry' THEN ce.published_at ELSE NULL END AS published_at,
                        COALESCE(
                            CASE WHEN r.resource_type = 'content_entry' THEN ce.updated_at ELSE NULL END,
                            r.updated_at
                        ) AS updated_at,
                        sm.meta_robots,
                        sm.seo_score
                 FROM routes r
                 JOIN site_languages sl ON sl.site_id = r.site_id
                    AND sl.language_code = r.language_code
                    AND sl.is_active = 1
                 LEFT JOIN content_entries ce ON ce.id = r.resource_id
                    AND r.resource_type = 'content_entry'
                    AND ce.site_id = r.site_id
                    AND ce.is_active = 1
                    AND ce.status = 'published'
                 LEFT JOIN content_entry_localizations cel ON cel.entry_id = ce.id
                    AND cel.language_code = r.language_code
                    AND cel.is_active = 1
                 LEFT JOIN public_content_snapshots pcs ON pcs.site_id = r.site_id
                    AND pcs.resource_type = r.resource_type
                    AND pcs.resource_id = r.resource_id
                    AND pcs.language_code = r.language_code
                    AND pcs.route_path = r.full_path
                 LEFT JOIN taxonomy_terms tt ON tt.id = r.resource_id
                    AND r.resource_type = 'taxonomy_term'
                    AND tt.is_active = 1
                 LEFT JOIN taxonomies tx ON tx.id = tt.taxonomy_id
                    AND tx.site_id = r.site_id
                    AND tx.archive_enabled = 1
                 LEFT JOIN seo_metadata sm ON sm.site_id = r.site_id
                    AND sm.resource_type = r.resource_type
                    AND sm.resource_id = r.resource_id
                    AND sm.language_code = r.language_code
                 WHERE r.site_id = :site_id
                   AND r.language_code = :language
                   AND r.status = 'active'
                   AND r.is_primary = 1
                   AND r.is_canonical = 1
                   AND COALESCE(sm.meta_robots, 'index,follow') NOT LIKE '%noindex%'
                   AND (
                        (
                            r.resource_type = 'content_entry'
                            AND ce.id IS NOT NULL
                        )
                        OR (
                            r.resource_type = 'taxonomy_term'
                            AND tt.id IS NOT NULL
                            AND tx.id IS NOT NULL
                            AND EXISTS (
                                SELECT 1
                                FROM content_entry_taxonomy_terms cet
                                JOIN content_entries ce_tax ON ce_tax.id = cet.entry_id
                                LEFT JOIN seo_metadata sm_tax ON sm_tax.site_id = ce_tax.site_id
                                  AND sm_tax.resource_type = 'content_entry'
                                  AND sm_tax.resource_id = ce_tax.id
                                  AND sm_tax.language_code = r.language_code
                                WHERE cet.term_id = tt.id
                                  AND ce_tax.site_id = r.site_id
                                  AND ce_tax.is_active = 1
                                  AND ce_tax.status = 'published'
                                  AND COALESCE(sm_tax.meta_robots, 'index,follow') NOT LIKE '%noindex%'
                            )
                        )
                   )
                 ORDER BY r.full_path",
                ['site_id' => $siteId, 'language' => $languageCode]
            );
        }

    public function listPublishedRouteAlternates(int $siteId, string $resourceType, int $resourceId): array
        {
            return $this->db->all(
                "SELECT DISTINCT r.language_code, r.full_path, sl.hreflang_code, sl.is_default, l.native_name
                 FROM routes r
                 JOIN site_languages sl ON sl.site_id = r.site_id
                    AND sl.language_code = r.language_code
                    AND sl.is_active = 1
                 JOIN languages l ON l.code = r.language_code AND l.is_active = 1
                 LEFT JOIN content_entries ce ON ce.id = r.resource_id
                    AND r.resource_type = 'content_entry'
                    AND ce.site_id = r.site_id
                    AND ce.is_active = 1
                    AND ce.status = 'published'
                 LEFT JOIN content_entry_localizations cel ON cel.entry_id = ce.id
                    AND cel.language_code = r.language_code
                    AND cel.is_active = 1
                 LEFT JOIN public_content_snapshots pcs ON pcs.site_id = r.site_id
                    AND pcs.resource_type = r.resource_type
                    AND pcs.resource_id = r.resource_id
                    AND pcs.language_code = r.language_code
                    AND pcs.route_path = r.full_path
                 LEFT JOIN taxonomy_terms tt ON tt.id = r.resource_id
                    AND r.resource_type = 'taxonomy_term'
                    AND tt.is_active = 1
                 LEFT JOIN taxonomies tx ON tx.id = tt.taxonomy_id
                    AND tx.site_id = r.site_id
                    AND tx.archive_enabled = 1
                 LEFT JOIN seo_metadata sm ON sm.site_id = r.site_id
                    AND sm.resource_type = r.resource_type
                    AND sm.resource_id = r.resource_id
                    AND sm.language_code = r.language_code
                 WHERE r.site_id = :site_id
                   AND r.resource_type = :resource_type
                   AND r.resource_id = :resource_id
                   AND r.status = 'active'
                   AND r.is_primary = 1
                   AND r.is_canonical = 1
                   AND COALESCE(sm.meta_robots, 'index,follow') NOT LIKE '%noindex%'
                   AND (
                        (
                            r.resource_type = 'content_entry'
                            AND ce.id IS NOT NULL
                        )
                        OR (
                            r.resource_type = 'taxonomy_term'
                            AND tt.id IS NOT NULL
                            AND tx.id IS NOT NULL
                            AND EXISTS (
                                SELECT 1
                                FROM content_entry_taxonomy_terms cet
                                JOIN content_entries ce_tax ON ce_tax.id = cet.entry_id
                                LEFT JOIN seo_metadata sm_tax ON sm_tax.site_id = ce_tax.site_id
                                  AND sm_tax.resource_type = 'content_entry'
                                  AND sm_tax.resource_id = ce_tax.id
                                  AND sm_tax.language_code = r.language_code
                                WHERE cet.term_id = tt.id
                                  AND ce_tax.site_id = r.site_id
                                  AND ce_tax.is_active = 1
                                  AND ce_tax.status = 'published'
                                  AND COALESCE(sm_tax.meta_robots, 'index,follow') NOT LIKE '%noindex%'
                            )
                        )
                   )
                 ORDER BY sl.sort_order, r.language_code",
                [
                    'site_id' => $siteId,
                    'resource_type' => $resourceType,
                    'resource_id' => $resourceId,
                ]
            );
        }

    public function findRedirect(int $siteId, string $path, string $languageCode): ?array
        {
            return $this->db->one(
                "SELECT *
                 FROM redirects
                 WHERE site_id = :site_id
                   AND old_path = :old_path
                   AND is_active = 1
                   AND (language_code = :language OR language_code IS NULL)
                 ORDER BY
                   CASE WHEN language_code = :language THEN 0 ELSE 1 END,
                   id DESC
                 LIMIT 1",
                [
                    'site_id' => $siteId,
                    'language' => $languageCode,
                    'old_path' => $path,
                ]
            );
        }

    public function findTombstone(int $siteId, string $path, string $languageCode): ?array
        {
            return $this->db->one(
                "SELECT *
                 FROM tombstones
                 WHERE site_id = :site_id
                   AND old_path = :old_path
                   AND is_active = 1
                   AND (language_code = :language OR language_code IS NULL)
                 ORDER BY
                   CASE WHEN language_code = :language THEN 0 ELSE 1 END,
                   id DESC
                 LIMIT 1",
                [
                    'site_id' => $siteId,
                    'language' => $languageCode,
                    'old_path' => $path,
                ]
            );
        }

    public function buildPath(string $typeKey, string $slug): string
        {
            $typeKey = strtolower(trim($typeKey));
            $slug = strtolower(trim($slug));
            $slug = trim($slug, '/');
            $slug = preg_replace('~[^a-z0-9_-]+~', '-', $slug) ?? $slug;
            $slug = trim($slug, '-');
            $slug = $slug !== '' ? $slug : 'item';
    
            if ($typeKey === 'page' && in_array($slug, ['home', 'accueil'], true)) {
                return '/';
            }
            if ($typeKey === 'page') {
                return '/' . $slug;
            }
            return '/' . $typeKey . 's/' . $slug;
        }
}
