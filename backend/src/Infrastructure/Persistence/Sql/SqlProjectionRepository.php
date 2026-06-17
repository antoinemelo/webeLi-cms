<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sql;

use App\Application\Projection\ProjectionRepository;
use App\Core\Database;

final class SqlProjectionRepository implements ProjectionRepository
{
    public function __construct(private readonly Database $db) {}

    public function listContentEntryIds(): array
    {
        return array_map(
            static fn(array $row): int => (int) $row['id'],
            $this->db->all('SELECT id FROM content_entries ORDER BY id')
        );
    }

    public function clearGlobalProjections(): void
    {
        // Reconstruction publique déterministe : on vide toutes les projections
        // runtime atomiques pour éviter qu'un ancien redirect/tombstone ou snapshot
        // masque les routes fraîchement reconstruites. Les tables éditoriales
        // brouillon/révision/publication restent intactes.
        foreach ([
            'public_content_snapshots',
            'routes',
            'redirects',
            'tombstones',
            'search_documents',
            'seo_metadata',
            'seo_audit_issues',
        ] as $table) {
            $this->db->run("DELETE FROM {$table}");
        }
    }

    public function replaceTaxonomyRoutes(): void
    {
        $this->db->transaction(function (): void {
            $this->db->run("DELETE FROM routes WHERE resource_type = 'taxonomy_term'");
            $this->db->run("DELETE FROM seo_metadata WHERE resource_type = 'taxonomy_term'");
            $this->db->run("DELETE FROM search_documents WHERE resource_type = 'taxonomy_term'");

            $terms = $this->db->all(
                'SELECT
                    t.site_id,
                    t.taxonomy_key,
                    tt.id AS term_id,
                    ttl.language_code,
                    ttl.name,
                    ttl.slug,
                    ttl.full_path,
                    ttl.description,
                    ttl.meta_title,
                    ttl.meta_description,
                    sl.hreflang_code
                 FROM taxonomies t
                 JOIN taxonomy_terms tt ON tt.taxonomy_id = t.id
                 JOIN taxonomy_term_localizations ttl
                   ON ttl.site_id = t.site_id
                  AND ttl.taxonomy_id = t.id
                  AND ttl.term_id = tt.id
                 JOIN site_languages sl
                   ON sl.site_id = t.site_id
                  AND sl.language_code = ttl.language_code
                  AND sl.is_active = 1
                 WHERE tt.is_active = 1
                 ORDER BY t.site_id, ttl.language_code, t.taxonomy_key, tt.sort_order, tt.id'
            );

            foreach ($terms as $term) {
                $path = (string) ($term['full_path'] ?: '/' . $term['taxonomy_key'] . '/' . $term['slug']);
                $name = trim((string) ($term['name'] ?? ''));
                $description = trim((string) ($term['description'] ?? ''));
                $metaTitle = trim((string) ($term['meta_title'] ?? '')) ?: $name;
                $metaDescription = trim((string) ($term['meta_description'] ?? '')) ?: ($description ?: $name);
                $timestamp = now_utc();

                $this->db->run("INSERT INTO routes(site_id, language_code, resource_type, resource_id, route_type, slug, full_path, is_primary, is_canonical, status, created_at, updated_at) VALUES(:site_id, :language_code, 'taxonomy_term', :resource_id, 'taxonomy', :slug, :full_path, 1, 1, 'active', :created_at, :updated_at)", [
                    'site_id' => $term['site_id'],
                    'language_code' => $term['language_code'],
                    'resource_id' => $term['term_id'],
                    'slug' => $term['slug'],
                    'full_path' => $path,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);

                $this->db->run("INSERT INTO seo_metadata(site_id, resource_type, resource_id, language_code, meta_title, meta_description, meta_robots, canonical_url, og_title, og_description, twitter_title, twitter_description, hreflang_code, seo_score, updated_at) VALUES(:site_id, 'taxonomy_term', :resource_id, :language_code, :meta_title, :meta_description, 'index,follow', NULL, :og_title, :og_description, :twitter_title, :twitter_description, :hreflang_code, :seo_score, :updated_at)", [
                    'site_id' => $term['site_id'],
                    'resource_id' => $term['term_id'],
                    'language_code' => $term['language_code'],
                    'meta_title' => $metaTitle,
                    'meta_description' => $metaDescription,
                    'og_title' => $metaTitle,
                    'og_description' => $metaDescription,
                    'twitter_title' => $metaTitle,
                    'twitter_description' => $metaDescription,
                    'hreflang_code' => $term['hreflang_code'],
                    'seo_score' => $metaTitle !== '' && $metaDescription !== '' ? 100 : 0,
                    'updated_at' => $timestamp,
                ]);

                $this->db->run("INSERT INTO search_documents(site_id, resource_type, resource_id, language_code, path, title, summary, search_text, updated_at) VALUES(:site_id, 'taxonomy_term', :resource_id, :language_code, :path, :title, :summary, :search_text, :updated_at)", [
                    'site_id' => $term['site_id'],
                    'resource_id' => $term['term_id'],
                    'language_code' => $term['language_code'],
                    'path' => $path,
                    'title' => $name,
                    'summary' => $description,
                    'search_text' => trim($name . "\n" . $description),
                    'updated_at' => $timestamp,
                ]);
            }
        });
    }
}
