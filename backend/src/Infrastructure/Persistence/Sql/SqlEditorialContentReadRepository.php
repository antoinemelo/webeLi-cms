<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sql;

use App\Application\Content\Read\EditorialContentReadRepository;

use App\Core\Database;

final class SqlEditorialContentReadRepository implements EditorialContentReadRepository
{
    public function __construct(private readonly Database $db, private readonly array $config, private readonly SqlPublicRouteReadRepository $routes) {}

    public function listEntries(int $siteId, ?string $languageCode = null): array
        {
            return $this->listEntriesPage([
                'site_id' => $siteId,
                'language_code' => $languageCode ?: ($this->config['app']['default_locale'] ?? 'fr'),
                'limit' => 500,
                'offset' => 0,
                'sort' => '-updated_at',
            ])['data'];
        }

    public function listEntriesPage(array $filters): array
        {
            $siteId = max(1, (int) ($filters['site_id'] ?? 0));
            $languageCode = (string) ($filters['language_code'] ?? ($this->config['app']['default_locale'] ?? 'fr'));
            $type = trim((string) ($filters['type'] ?? ''));
            $status = trim((string) ($filters['status'] ?? ''));
            $q = trim((string) ($filters['q'] ?? ''));
            $limit = max(1, min(100, (int) ($filters['limit'] ?? 50)));
            $offset = max(0, (int) ($filters['offset'] ?? 0));
            $sort = (string) ($filters['sort'] ?? '-updated_at');
    
            $params = [
                'site_id' => $siteId,
                'lang' => $languageCode,
            ];
            $where = ['ce.site_id = :site_id'];
    
            if ($type !== '') {
                $where[] = 'ct.type_key = :type_key';
                $params['type_key'] = $type;
            }
            if ($status !== '' && $status !== 'all') {
                $where[] = 'ce.status = :status';
                $params['status'] = $status;
            }
            if ($q !== '') {
                $where[] = "(ce.entry_key LIKE :q_like ESCAPE '\\' OR cel.title LIKE :q_like ESCAPE '\\' OR r.full_path LIKE :q_like ESCAPE '\\')";
                $params['q_like'] = '%' . $this->escapeLike($q) . '%';
            }
    
            $whereSql = implode(' AND ', $where);
            $orderSql = $this->entryListOrderBy($sort);
            $fromSql = "FROM content_entries ce
                 JOIN content_types ct ON ct.id = ce.content_type_id
                 LEFT JOIN content_entry_localizations cel ON cel.entry_id = ce.id AND cel.language_code = :lang
                 LEFT JOIN content_entry_publications cep ON cep.site_id = ce.site_id AND cep.entry_id = ce.id AND cep.language_code = :lang
                 LEFT JOIN content_entry_working_revisions cewr ON cewr.entry_id = ce.id AND cewr.language_code = :lang
                 LEFT JOIN revisions wr ON wr.id = cewr.working_revision_id
                 LEFT JOIN routes r ON r.site_id = ce.site_id
                    AND r.resource_type = 'content_entry'
                    AND r.resource_id = ce.id
                    AND r.language_code = :lang
                    AND r.is_primary = 1
                    AND r.is_canonical = 1";
    
            $totalRow = $this->db->one("SELECT COUNT(*) AS total $fromSql WHERE $whereSql", $params);
            $total = (int) ($totalRow['total'] ?? 0);
    
            $rows = $this->db->all(
                "SELECT ce.*, ct.type_key, ct.singular_label, cel.title, cep.workflow_status AS language_publication_status, wr.document_json AS working_document_json, r.full_path
                 $fromSql
                 WHERE $whereSql
                 ORDER BY $orderSql
                 LIMIT :limit OFFSET :offset",
                $params + ['limit' => $limit, 'offset' => $offset]
            );
    
            return [
                'data' => $rows,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $total,
            ];
        }

    private function entryListOrderBy(string $sort): string
        {
            $direction = str_starts_with($sort, '-') ? 'DESC' : 'ASC';
            $field = ltrim($sort, '-+');
            $columns = [
                'updated_at' => 'ce.updated_at',
                'created_at' => 'ce.created_at',
                'published_at' => 'ce.published_at',
                'entry_key' => 'ce.entry_key',
                'status' => 'ce.status',
                'type' => 'ct.type_key',
                'title' => 'cel.title',
            ];
            $column = $columns[$field] ?? 'ce.updated_at';
            return $column . ' ' . $direction . ', ce.id ' . $direction;
        }

    private function escapeLike(string $value): string
        {
            return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
        }

    public function getWorkingEntryAggregate(int $entryId, string $languageCode): ?array
    {
        return $this->getEntryAggregate($entryId, $languageCode, false);
    }

    public function getPublishedEntryAggregate(int $entryId, string $languageCode): ?array
    {
        return $this->getEntryAggregate($entryId, $languageCode, true);
    }

    private function getEntryAggregate(int $entryId, string $languageCode, bool $publishedOnly = false): ?array
        {
            $entry = $this->db->one(
                'SELECT ce.*, ct.type_key, ct.singular_label, ct.frontend_template
                 FROM content_entries ce
                 JOIN content_types ct ON ct.id = ce.content_type_id
                 WHERE ce.id = :id LIMIT 1',
                ['id' => $entryId]
            );
            if (!$entry) {
                return null;
            }
    
            if ($publishedOnly) {
                $snapshot = $this->db->one(
                    "SELECT pcs.*
                     FROM public_content_snapshots pcs
                     JOIN content_entry_publications cep ON cep.site_id = pcs.site_id
                        AND cep.entry_id = pcs.resource_id
                        AND cep.language_code = pcs.language_code
                        AND cep.published_revision_id = pcs.source_published_revision_id
                        AND cep.workflow_status = 'published'
                     WHERE pcs.resource_type = 'content_entry'
                       AND pcs.resource_id = :entry_id
                       AND pcs.language_code = :language
                     LIMIT 1",
                    ['entry_id' => $entryId, 'language' => $languageCode]
                );
                if (!$snapshot) {
                    return null;
                }
    
                $document = json_decode((string) $snapshot['document_json'], true) ?: [];
                $seoDocument = json_decode((string) $snapshot['seo_json'], true) ?: [];
                $loc = $this->localizationFromSnapshot($entryId, $snapshot, $document);
                if (($loc['language_code'] ?? '') !== $languageCode) {
                    return null;
                }
    
                $route = $this->db->one("SELECT * FROM routes WHERE site_id = :site_id AND resource_type = 'content_entry' AND resource_id = :resource_id AND language_code = :language AND full_path = :path AND is_primary = 1 AND is_canonical = 1 AND status = 'active' LIMIT 1", [
                    'site_id' => (int) $entry['site_id'],
                    'resource_id' => $entryId,
                    'language' => $languageCode,
                    'path' => (string) $snapshot['route_path'],
                ]);
                if (!$route) {
                    return null;
                }
    
                $seo = $this->db->one("SELECT * FROM seo_metadata WHERE site_id = :site_id AND resource_type = 'content_entry' AND resource_id = :resource_id AND language_code = :language AND source_published_revision_id = :revision_id LIMIT 1", [
                    'site_id' => (int) $entry['site_id'],
                    'resource_id' => $entryId,
                    'language' => $languageCode,
                    'revision_id' => (int) $snapshot['source_published_revision_id'],
                ]) ?: $this->seoFromSnapshot($seoDocument, (string) $snapshot['route_path']);
    
                return [
                    'entry' => $entry,
                    'localization' => $loc,
                    'route' => $route,
                    'seo' => $seo,
                    'working_revision' => null,
                    'published_revision' => null,
                    'publication' => [
                        'site_id' => (int) $snapshot['site_id'],
                        'entry_id' => $entryId,
                        'language_code' => $languageCode,
                        'published_revision_id' => (int) $snapshot['source_published_revision_id'],
                        'workflow_status' => 'published',
                        'published_at' => (string) ($snapshot['published_at'] ?? ''),
                    ],
                    'layout_blocks' => $this->layoutBlocksForEntry($entryId, $languageCode, true),
                    'revisions' => [],
                    'public_snapshot' => $snapshot,
                    'public_source' => 'public_content_snapshots',
                ];
            }
    
            $loc = $this->db->one('SELECT * FROM content_entry_localizations WHERE entry_id = :entry_id AND language_code = :language LIMIT 1', [
                'entry_id' => $entryId,
                'language' => $languageCode,
            ]) ?: $this->db->one('SELECT * FROM content_entry_localizations WHERE entry_id = :entry_id ORDER BY id LIMIT 1', ['entry_id' => $entryId]);
            $route = $this->db->one("SELECT * FROM routes WHERE site_id = :site_id AND resource_type = 'content_entry' AND resource_id = :resource_id AND language_code = :language AND is_primary = 1 LIMIT 1", [
                'site_id' => (int) $entry['site_id'],
                'resource_id' => $entryId,
                'language' => $languageCode,
            ]);
            $seo = $this->db->one("SELECT * FROM seo_metadata WHERE site_id = :site_id AND resource_type = 'content_entry' AND resource_id = :resource_id AND language_code = :language LIMIT 1", [
                'site_id' => (int) $entry['site_id'],
                'resource_id' => $entryId,
                'language' => $languageCode,
            ]);
            $workingPointer = $this->db->one('SELECT * FROM content_entry_working_revisions WHERE entry_id = :entry_id AND language_code = :language LIMIT 1', [
                'entry_id' => $entryId,
                'language' => $languageCode,
            ]);
            $publication = $this->db->one('SELECT * FROM content_entry_publications WHERE entry_id = :entry_id AND language_code = :language LIMIT 1', [
                'entry_id' => $entryId,
                'language' => $languageCode,
            ]);
            $workingRevisionId = $workingPointer ? (int) $workingPointer['working_revision_id'] : 0;
            $publishedRevisionId = $publication ? (int) $publication['published_revision_id'] : 0;
            $workingRevision = $workingRevisionId ? $this->db->one('SELECT * FROM revisions WHERE id = :id', ['id' => $workingRevisionId]) : null;
            $publishedRevision = $publishedRevisionId ? $this->db->one('SELECT * FROM revisions WHERE id = :id', ['id' => $publishedRevisionId]) : null;
            return [
                'entry' => $entry,
                'localization' => $loc,
                'route' => $route,
                'routes' => $this->routesForEntry($entry, $entryId, $languageCode),
                'seo' => $seo,
                'working_revision' => $workingRevision,
                'published_revision' => $publishedRevision,
                'working_pointer' => $workingPointer,
                'publication' => $publication,
                'fields' => $this->fieldsFromRevisionRow($workingRevision ?: $publishedRevision),
                'taxonomies' => $this->taxonomiesForEntry($entryId, $languageCode),
                'layout_blocks' => $this->layoutBlocksForEntry($entryId, $languageCode, false),
                'locks' => [],
                'revisions' => $this->db->all("SELECT * FROM revisions WHERE resource_type = 'content_entry' AND resource_id = :id AND language_code = :language ORDER BY revision_number DESC, id DESC", ['id' => $entryId, 'language' => $languageCode]),
            ];
        }

    private function fieldsFromRevisionRow(?array $revisionRow): array
        {
            if (!$revisionRow) {
                return [];
            }
            $document = json_decode((string) ($revisionRow['document_json'] ?? '{}'), true);
            return is_array($document) && is_array($document['fields'] ?? null) ? $document['fields'] : [];
        }

    private function routesForEntry(array $entry, int $entryId, string $languageCode): array
        {
            return $this->db->all("SELECT * FROM routes WHERE site_id = :site_id AND resource_type = 'content_entry' AND resource_id = :resource_id AND language_code = :language ORDER BY is_primary DESC, is_canonical DESC, id", [
                'site_id' => (int) $entry['site_id'],
                'resource_id' => $entryId,
                'language' => $languageCode,
            ]);
        }

    public function taxonomiesForEntry(int $entryId, string $languageCode): array
        {
            return $this->db->all("SELECT t.id AS taxonomy_id, t.taxonomy_key, tt.id AS term_id, tt.term_key,
                        COALESCE(ttl.name, ttl_fallback.name, tt.term_key) AS name,
                        COALESCE(ttl.slug, ttl_fallback.slug, tt.term_key) AS slug,
                        COALESCE(ttl.full_path, ttl_fallback.full_path, '/' || t.taxonomy_key || '/' || tt.term_key) AS full_path,
                        cet.sort_order
                 FROM content_entry_taxonomy_terms cet
                 JOIN taxonomy_terms tt ON tt.id = cet.term_id
                 JOIN taxonomies t ON t.id = tt.taxonomy_id
                 LEFT JOIN taxonomy_term_localizations ttl ON ttl.site_id = t.site_id AND ttl.taxonomy_id = t.id AND ttl.term_id = tt.id AND ttl.language_code = :language
                 LEFT JOIN taxonomy_term_localizations ttl_fallback ON ttl_fallback.site_id = t.site_id AND ttl_fallback.taxonomy_id = t.id AND ttl_fallback.term_id = tt.id AND ttl_fallback.language_code = 'fr'
                 WHERE cet.entry_id = :entry_id
                 ORDER BY t.taxonomy_key, cet.sort_order, tt.term_key", [
                'entry_id' => $entryId,
                'language' => $languageCode,
            ]);
        }

    public function previewAggregate(int $entryId, string $languageCode, ?int $revisionId = null, ?int $siteId = null): ?array
        {
            $aggregate = $this->getEntryAggregate($entryId, $languageCode, false);
            if (!$aggregate) {
                return null;
            }
            if ($siteId !== null && (int) ($aggregate['entry']['site_id'] ?? 0) !== $siteId) {
                return null;
            }
            $revision = $revisionId
                ? $this->db->one(
                    'SELECT * FROM revisions WHERE id = :id AND resource_type = :type AND resource_id = :entry_id AND language_code = :language LIMIT 1',
                    ['id' => $revisionId, 'type' => 'content_entry', 'entry_id' => $entryId, 'language' => $languageCode]
                )
                : ($aggregate['working_revision'] ?? null);
            if (!$revision) {
                return null;
            }
            $document = json_decode((string) $revision['document_json'], true) ?: [];
            $loc = $aggregate['localization'] ?? [];
            $content = is_array($document['content'] ?? null) ? $document['content'] : [];
            $loc['title'] = (string) ($content['title'] ?? ($loc['title'] ?? ''));
            $loc['draft_slug'] = (string) ($content['slug'] ?? ($loc['draft_slug'] ?? ''));
            $path = $this->routes->buildPath((string) $aggregate['entry']['type_key'], (string) ($loc['draft_slug'] ?? ''));
            $blocks = $this->enrichPreviewBlocksWithMediaUrls($this->previewBlocksFromDocument($document), (int) ($aggregate['entry']['site_id'] ?? 0));
    
            return [
                'entry' => $aggregate['entry'],
                'localization' => $loc,
                'route' => ['full_path' => $path],
                'seo' => [
                    'meta_title' => (string) ($document['seo']['meta_title'] ?? $loc['title'] ?? ''),
                    'meta_description' => (string) ($document['seo']['meta_description'] ?? ''),
                    'meta_robots' => (string) ($document['seo']['meta_robots'] ?? 'noindex,nofollow'),
                    'canonical_url' => '',
                ],
                'revision' => $revision,
                'working_revision' => $revision,
                'layout_blocks' => $blocks,
                'preview' => [
                    'entry_id' => $entryId,
                    'revision_id' => (int) $revision['id'],
                    'site_id' => (int) ($aggregate['entry']['site_id'] ?? 0),
                    'language_code' => $languageCode,
                    'path' => $path,
                ],
            ];
        }

    private function layoutBlocksForEntry(int $entryId, string $languageCode, bool $publishedOnly): array
        {
            $statusSql = $publishedOnly ? "AND lb.status = 'published' AND lbl.status = 'published'" : "AND lb.status NOT IN ('archived') AND lbl.status NOT IN ('archived')";
    
            return $this->db->all(
                "SELECT lb.block_type, lb.block_key, lb.component_key, lb.layout_width, lb.css_class, lb.sort_order,
                        lbl.title, lbl.content_text, lbl.embed_code, lbl.settings_json
                 FROM layout_containers lc
                 JOIN layout_blocks lb ON lb.container_id = lc.id
                 JOIN layout_block_localizations lbl ON lbl.block_id = lb.id AND lbl.language_code = :language
                 WHERE lc.entry_id = :entry_id
                   AND lb.is_active = 1
                   AND lbl.is_active = 1
                   $statusSql
                 ORDER BY lc.sort_order, lb.row_group, lb.column_position, lb.sort_order, lb.id",
                ['entry_id' => $entryId, 'language' => $languageCode]
            );
        }

    public function listContentTypes(): array
        {
            return $this->db->all('SELECT * FROM content_types WHERE admin_enabled = 1 ORDER BY is_system DESC, type_key');
        }

    public function outboxStats(): array
        {
            return [
                'pending' => (int) (($this->db->one("SELECT COUNT(*) AS c FROM outbox_events WHERE status = 'pending'")['c'] ?? 0)),
                'failed' => (int) (($this->db->one("SELECT COUNT(*) AS c FROM outbox_events WHERE status = 'failed'")['c'] ?? 0)),
                'processed' => (int) (($this->db->one("SELECT COUNT(*) AS c FROM outbox_events WHERE status = 'processed'")['c'] ?? 0)),
                'redirects' => (int) (($this->db->one("SELECT COUNT(*) AS c FROM redirects WHERE is_active = 1")['c'] ?? 0)),
                'tombstones' => (int) (($this->db->one("SELECT COUNT(*) AS c FROM tombstones WHERE is_active = 1")['c'] ?? 0)),
            ];
        }

    private function localizationFromSnapshot(int $entryId, array $snapshot, array $document): array
        {
            $content = is_array($document['content'] ?? null) ? $document['content'] : [];
            return [
                'id' => 0,
                'entry_id' => $entryId,
                'language_code' => (string) ($snapshot['language_code'] ?? ''),
                'title' => (string) ($snapshot['title'] ?? ($content['title'] ?? '')),
                'slug' => (string) ($snapshot['slug'] ?? ($content['slug'] ?? '')),
                'draft_slug' => (string) ($snapshot['slug'] ?? ($content['slug'] ?? '')),
                'status' => 'published',
                'draft_status' => 'published',
                'is_active' => 1,
            ];
        }

    private function seoFromSnapshot(array $seo, string $path): array
        {
            return [
                'meta_title' => (string) ($seo['meta_title'] ?? ''),
                'meta_description' => (string) ($seo['meta_description'] ?? ''),
                'meta_robots' => (string) ($seo['meta_robots'] ?? 'index,follow'),
                'canonical_url' => (string) ($seo['canonical_url'] ?? $path),
                'og_title' => (string) ($seo['og_title'] ?? $seo['meta_title'] ?? ''),
                'og_description' => (string) ($seo['og_description'] ?? $seo['meta_description'] ?? ''),
                'og_image_media_id' => isset($seo['og_image_media_id']) && $seo['og_image_media_id'] !== null ? (int) $seo['og_image_media_id'] : null,
                'twitter_title' => (string) ($seo['twitter_title'] ?? $seo['og_title'] ?? $seo['meta_title'] ?? ''),
                'twitter_description' => (string) ($seo['twitter_description'] ?? $seo['og_description'] ?? $seo['meta_description'] ?? ''),
                'twitter_image_media_id' => isset($seo['twitter_image_media_id']) && $seo['twitter_image_media_id'] !== null ? (int) $seo['twitter_image_media_id'] : null,
                'hreflang_code' => (string) ($seo['hreflang_code'] ?? ''),
                'json_ld' => $seo['json_ld'] ?? null,
                'seo_score' => isset($seo['seo_score']) ? (int) $seo['seo_score'] : null,
                'source' => is_array($seo['source'] ?? null) ? $seo['source'] : null,
            ];
        }

    private function localizationFromRevisionDocument(int $entryId, array $document): array
        {
            $content = is_array($document['content'] ?? null) ? $document['content'] : [];
            return [
                'id' => 0,
                'entry_id' => $entryId,
                'language_code' => (string) ($document['language_code'] ?? ''),
                'title' => (string) ($content['title'] ?? ''),
                'slug' => (string) ($content['slug'] ?? ''),
                'status' => 'published',
                'is_active' => 1,
            ];
        }

    private function seoFromRevisionDocument(array $document, array $loc, string $path): array
        {
            $seo = is_array($document['seo'] ?? null) ? $document['seo'] : [];
            return [
                'meta_title' => (string) ($seo['meta_title'] ?? $loc['title'] ?? ''),
                'meta_description' => (string) ($seo['meta_description'] ?? ''),
                'meta_robots' => (string) ($seo['meta_robots'] ?? 'index,follow'),
                'canonical_url' => $path,
            ];
        }

    private function previewBlocksFromDocument(array $document): array
        {
            foreach (['blocks', 'content_blocks'] as $key) {
                if (is_array($document[$key] ?? null)) {
                    return array_values(array_filter($document[$key], 'is_array'));
                }
            }
            if (is_array($document['content']['blocks'] ?? null)) {
                return array_values(array_filter($document['content']['blocks'], 'is_array'));
            }
            return [];
        }

    private function enrichPreviewBlocksWithMediaUrls(array $blocks, int $siteId): array
        {
            foreach ($blocks as $index => $block) {
                if (!is_array($block)) {
                    continue;
                }
                $data = is_array($block['data'] ?? null) ? $block['data'] : [];
                foreach ([
                    ['media_id', 'src'],
                    ['image_media_id', 'image_src'],
                    ['background_media_id', 'background_src'],
                    ['poster_media_id', 'poster'],
                ] as [$idKey, $urlKey]) {
                    $id = is_numeric($data[$idKey] ?? null) ? (int) $data[$idKey] : 0;
                    if ($id > 0 && trim((string) ($data[$urlKey] ?? '')) === '') {
                        $data[$urlKey] = $this->publicPreviewMediaPath($id, $siteId);
                    }
                }
                if (is_array($data['items'] ?? null)) {
                    foreach ($data['items'] as $itemIndex => $item) {
                        if (!is_array($item)) {
                            continue;
                        }
                        $id = is_numeric($item['media_id'] ?? null) ? (int) $item['media_id'] : 0;
                        if ($id > 0 && trim((string) ($item['src'] ?? '')) === '') {
                            $item['src'] = $this->publicPreviewMediaPath($id, $siteId);
                        }
                        $data['items'][$itemIndex] = $item;
                    }
                }
                if (is_array($data['columns'] ?? null)) {
                    foreach ($data['columns'] as $columnIndex => $column) {
                        if (!is_array($column)) {
                            continue;
                        }
                        $column['blocks'] = $this->enrichPreviewBlocksWithMediaUrls(is_array($column['blocks'] ?? null) ? $column['blocks'] : [], $siteId);
                        $data['columns'][$columnIndex] = $column;
                    }
                }
                $block['data'] = $data;
                $blocks[$index] = $block;
            }
            return array_values($blocks);
        }

    private function publicPreviewMediaPath(int $mediaId, int $siteId): string
        {
            $params = ['id' => $mediaId];
            $siteSql = '';
            if ($siteId > 0) {
                $siteSql = ' AND site_id = :site_id';
                $params['site_id'] = $siteId;
            }
            $row = $this->db->one('SELECT public_path, path FROM media_assets WHERE id = :id' . $siteSql . ' AND lifecycle_status = \'ready\' LIMIT 1', $params);
            $path = trim((string) ($row['public_path'] ?? $row['path'] ?? ''));
            return $path === '' ? '' : '/' . ltrim($path, '/');
        }
}
