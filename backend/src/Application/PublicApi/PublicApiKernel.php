<?php

declare(strict_types=1);

namespace App\Application\PublicApi;

use App\Application\Frontend\ResolvePublicRoute;
use App\Application\Media\Storage\MediaUrlGenerator;
use App\Core\Database;
use App\Core\ErrorCode;
use App\Core\Request;
use App\Core\Response;
use App\Application\Content\Read\PublicContentReadRepository;
use App\Application\Routing\PublicRouteReadRepository;
use App\Repository\MenuRepository;
use App\Repository\SiteRepository;
use App\Repository\TaxonomyRepository;

final class PublicApiKernel
{
    private const MAX_LIMIT = 100;

    private MediaUrlGenerator $mediaUrls;
    private PublicApiResponder $responder;

    public function __construct(
        private readonly Request $request,
        private readonly Database $db,
        private readonly PublicContentReadRepository $content,
        private readonly PublicRouteReadRepository $routes,
        private readonly SiteRepository $sites,
        private readonly TaxonomyRepository $taxonomies,
        private readonly MenuRepository $menus,
        private readonly ResolvePublicRoute $resolver,
    ) {
        $this->mediaUrls = new MediaUrlGenerator($db);
        $this->responder = new PublicApiResponder();
    }

    public function health(): Response
    {
        [$site, $languageCode] = $this->context();
        return $this->json(['status' => 'ok'], 'public.health.v1', [
            'site_id' => (int) $site['id'],
            'site_key' => (string) ($site['site_key'] ?? ''),
            'language_code' => $languageCode,
        ], 200, 60);
    }

    public function byRoute(): Response
    {
        [$site, $languageCode] = $this->context();
        $path = $this->normalizePath((string) ($this->request->query['path'] ?? '/'));
        $resolution = $this->resolver->execute($site, $languageCode, $path, $this->request->query);
        $status = (int) ($resolution['status'] ?? 200);

        if (($resolution['type'] ?? '') === 'redirect') {
            return $this->json([
                'kind' => 'redirect',
                'to' => (string) ($resolution['to'] ?? ''),
                'status' => $status,
            ], 'public.route.redirect.v1', [
                'site_id' => (int) $site['id'],
                'language_code' => $languageCode,
                'path' => $path,
            ], $status, 300);
        }

        $payload = is_array($resolution['payload'] ?? null) ? $resolution['payload'] : [];
        if ($status === 404) {
            return Response::error(ErrorCode::ROUTE_NOT_FOUND, ErrorCode::message(ErrorCode::ROUTE_NOT_FOUND), 404, [
                'site_id' => (int) $site['id'],
                'language_code' => $languageCode,
                'path' => $path,
            ], $this->cacheHeaders(60));
        }
        if ($status === 410) {
            return Response::error(ErrorCode::PUBLIC_CONTENT_NOT_FOUND, 'Le contenu demandé a été retiré.', 410, [
                'site_id' => (int) $site['id'],
                'language_code' => $languageCode,
                'path' => $path,
            ], $this->cacheHeaders(300));
        }

        return $this->json($this->normalizePublicPayload($payload), 'public.route.show.v1', [
            'site_id' => (int) $site['id'],
            'language_code' => $languageCode,
            'path' => $path,
            'source' => 'ResolvePublicRoute + public_content_snapshots',
        ], 200, 300);
    }

    public function contentIndex(): Response
    {
        return $this->contentList($this->safeSlug((string) ($this->request->query['type'] ?? '')));
    }

    public function contentByType(string $type): Response
    {
        return $this->contentList($this->safeSlug($type));
    }

    private function contentList(string $type = ''): Response
    {
        [$site, $languageCode] = $this->context();
        if ($type !== '' && !$this->contentTypeExists($type)) {
            return Response::error(ErrorCode::CONTENT_TYPE_NOT_FOUND, ErrorCode::message(ErrorCode::CONTENT_TYPE_NOT_FOUND), 404, ['type' => $type], $this->cacheHeaders(60));
        }

        $page = $this->publishedContentPage($type);
        $items = array_map(fn(array $item): array => $this->normalizeListItem($item, $languageCode), $page['items']);

        return $this->json([
            'items' => $items,
            'pagination' => [
                'total' => $page['total'],
                'limit' => $page['limit'],
                'offset' => $page['offset'],
                'has_more' => $page['has_more'],
            ],
        ], 'public.content.index.v1', [
            'site_id' => (int) $site['id'],
            'language_code' => $languageCode,
            'type' => $type !== '' ? $type : null,
            'taxonomy' => $this->safeSlug((string) ($this->request->query['taxonomy'] ?? '')) ?: null,
            'term' => $this->safeSlug((string) ($this->request->query['term'] ?? '')) ?: null,
            'source' => 'public_content_snapshots',
        ], 200, 300);
    }

    public function contentShow(string $type, string $slug): Response
    {
        [$site, $languageCode] = $this->context();
        $type = $this->safeSlug($type);
        $slug = $this->safeSlug($slug);
        if ($type === '' || $slug === '') {
            return Response::validation([
                'type' => $type === '' ? ['Type invalide.'] : [],
                'slug' => $slug === '' ? ['Slug invalide.'] : [],
            ]);
        }
        $aggregate = $this->content->getPublishedByTypeAndSlug((int) $site['id'], $type, $slug, $languageCode);
        if (!$aggregate) {
            return Response::error(ErrorCode::PUBLIC_CONTENT_NOT_FOUND, ErrorCode::message(ErrorCode::PUBLIC_CONTENT_NOT_FOUND), 404, [
                'site_id' => (int) $site['id'],
                'language_code' => $languageCode,
                'type' => $type,
                'slug' => $slug,
            ], $this->cacheHeaders(60));
        }

        $payload = $this->resolver->entryPayload($site, $languageCode, $aggregate, false, $this->request->query);
        return $this->json($this->normalizePublicPayload($payload), 'public.content.show.v1', [
            'site_id' => (int) $site['id'],
            'language_code' => $languageCode,
            'type' => $type,
            'slug' => $slug,
            'source' => 'ResolvePublicRoute::entryPayload + public_content_snapshots',
        ], 200, 300);
    }

    public function routes(): Response
    {
        [$site, $languageCode] = $this->context();
        $type = $this->safeSlug((string) ($this->request->query['type'] ?? ''));
        $routes = $this->routes->listPublishedRoutes((int) $site['id'], $languageCode);
        if ($type !== '') {
            $routes = array_values(array_filter($routes, fn(array $row): bool => $this->routeMatchesType($row, $type)));
        }
        return $this->json([
            'items' => array_map(fn(array $row): array => [
                'path' => (string) ($row['full_path'] ?? ''),
                'resource_type' => (string) ($row['resource_type'] ?? ''),
                'resource_id' => (int) ($row['resource_id'] ?? 0),
                'title' => (string) ($row['title'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? $row['route_updated_at'] ?? ''),
                'meta_robots' => (string) ($row['meta_robots'] ?? 'index,follow'),
            ], $routes),
        ], 'public.routes.index.v1', [
            'site_id' => (int) $site['id'],
            'language_code' => $languageCode,
            'source' => 'routes + public_content_snapshots',
        ], 200, 300);
    }

    public function languages(): Response
    {
        [$site, $languageCode] = $this->context();
        $languages = array_map(static fn(array $row): array => [
            'code' => (string) ($row['language_code'] ?? $row['code'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'native_name' => (string) ($row['native_name'] ?? ''),
            'url_prefix' => (string) ($row['url_prefix'] ?? ''),
            'hreflang' => (string) ($row['hreflang_code'] ?? $row['language_code'] ?? $row['code'] ?? ''),
            'is_default' => !empty($row['site_is_default'] ?? $row['is_default'] ?? false),
            'is_active' => !empty($row['site_is_active'] ?? $row['is_active'] ?? true),
            'is_current' => strtolower((string) ($row['language_code'] ?? $row['code'] ?? '')) === strtolower($languageCode),
        ], $this->sites->getLanguages((int) $site['id']));

        return $this->json(['items' => $languages], 'public.languages.index.v1', [
            'site_id' => (int) $site['id'],
            'language_code' => $languageCode,
        ], 200, 600);
    }

    public function menu(string $key): Response
    {
        [$site, $languageCode] = $this->context();
        $key = $this->safeSlug($key);
        $menu = $this->menus->menuWithTree((int) $site['id'], $key, $languageCode);
        if (!$menu || empty($menu['menu']['is_active'])) {
            return Response::error(ErrorCode::MENU_NOT_FOUND, ErrorCode::message(ErrorCode::MENU_NOT_FOUND), 404, [
                'site_id' => (int) $site['id'],
                'language_code' => $languageCode,
                'menu_key' => $key,
            ], $this->cacheHeaders(60));
        }
        return $this->json([
            'key' => (string) ($menu['menu']['menu_key'] ?? $key),
            'location' => $menu['menu']['menu_location'] ?? null,
            'name' => (string) ($menu['menu']['name'] ?? ''),
            'items' => $this->normalizeMenuTree($menu['items'] ?? [], $languageCode),
        ], 'public.menus.show.v1', [
            'site_id' => (int) $site['id'],
            'language_code' => $languageCode,
            'menu_key' => $key,
        ], 200, 300);
    }

    public function menusIndex(): Response
    {
        [$site, $languageCode] = $this->context();
        $menus = array_values(array_filter(
            $this->menus->listMenus((int) $site['id']),
            static fn(array $row): bool => !empty($row['is_active'])
        ));
        return $this->json([
            'items' => array_map(static fn(array $row): array => [
                'key' => (string) ($row['menu_key'] ?? ''),
                'location' => $row['menu_location'] ?? null,
                'name' => (string) ($row['name'] ?? ''),
                'language_mode' => (string) ($row['language_mode'] ?? 'shared'),
                'item_count' => (int) ($row['item_count'] ?? 0),
            ], $menus),
        ], 'public.menus.index.v1', [
            'site_id' => (int) $site['id'],
            'language_code' => $languageCode,
        ], 200, 300);
    }

    public function taxonomiesIndex(): Response
    {
        [$site, $languageCode] = $this->context();
        $rows = $this->taxonomies->listTaxonomies((int) $site['id']);
        return $this->json([
            'items' => array_map(static fn(array $row): array => [
                'key' => (string) ($row['taxonomy_key'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'archive_enabled' => !empty($row['archive_enabled']),
                'is_active' => !empty($row['is_active']),
            ], array_values(array_filter($rows, static fn(array $row): bool => !empty($row['is_active'])))),
        ], 'public.taxonomies.index.v1', [
            'site_id' => (int) $site['id'],
            'language_code' => $languageCode,
        ], 200, 300);
    }

    public function taxonomy(string $taxonomy): Response
    {
        [$site, $languageCode] = $this->context();
        $taxonomy = $this->safeSlug($taxonomy);
        $taxonomyRow = null;
        foreach ($this->taxonomies->listTaxonomies((int) $site['id']) as $row) {
            if ((string) ($row['taxonomy_key'] ?? '') === $taxonomy && !empty($row['is_active'])) {
                $taxonomyRow = $row;
                break;
            }
        }
        if (!$taxonomyRow) {
            return Response::error(ErrorCode::TAXONOMY_NOT_FOUND, ErrorCode::message(ErrorCode::TAXONOMY_NOT_FOUND), 404, [
                'site_id' => (int) $site['id'],
                'language_code' => $languageCode,
                'taxonomy_key' => $taxonomy,
            ], $this->cacheHeaders(60));
        }
        $terms = array_values(array_filter(
            $this->taxonomies->listTermsForSite((int) $site['id'], $languageCode),
            static fn(array $term): bool => (string) ($term['taxonomy_key'] ?? '') === $taxonomy && !empty($term['is_active'])
        ));

        return $this->json([
            'taxonomy' => [
                'key' => (string) ($taxonomyRow['taxonomy_key'] ?? $taxonomy),
                'name' => (string) ($taxonomyRow['name'] ?? ''),
                'archive_enabled' => !empty($taxonomyRow['archive_enabled']),
            ],
            'terms' => array_map(static fn(array $term): array => [
                'id' => (int) ($term['id'] ?? 0),
                'key' => (string) ($term['term_key'] ?? ''),
                'slug' => (string) ($term['slug'] ?? $term['term_key'] ?? ''),
                'name' => (string) ($term['name'] ?? $term['term_key'] ?? ''),
                'description' => (string) ($term['description'] ?? ''),
                'path' => (string) ($term['full_path'] ?? ''),
            ], $terms),
        ], 'public.taxonomies.show.v1', [
            'site_id' => (int) $site['id'],
            'language_code' => $languageCode,
            'taxonomy_key' => $taxonomy,
        ], 200, 300);
    }

    public function search(): Response
    {
        [$site, $languageCode] = $this->context();
        $q = trim(mb_substr((string) ($this->request->query['q'] ?? ''), 0, 120));
        $page = $this->content->searchPublishedHeadless((int) $site['id'], $languageCode, $q, [
            'type' => $this->safeSlug((string) ($this->request->query['type'] ?? '')),
            'taxonomy' => $this->safeSlug((string) ($this->request->query['taxonomy'] ?? '')),
            'term' => $this->safeSlug((string) ($this->request->query['term'] ?? '')),
            'limit' => $this->limit(),
            'offset' => $this->offset(),
        ]);
        return $this->json([
            'query' => $q,
            'items' => $page['items'],
            'pagination' => [
                'total' => $page['total'],
                'limit' => $page['limit'],
                'offset' => $page['offset'],
                'has_more' => $page['has_more'],
            ],
        ], 'public.search.index.v1', [
            'site_id' => (int) $site['id'],
            'language_code' => $languageCode,
            'source' => 'search_documents + public_content_snapshots',
        ], 200, 120);
    }

    public function mediaIndex(): Response
    {
        [$site, $languageCode] = $this->context();
        $mediaType = $this->safeSlug((string) ($this->request->query['media_type'] ?? $this->request->query['type'] ?? ''));
        if ($mediaType !== '' && !in_array($mediaType, ['image', 'video', 'audio', 'document', 'binary'], true)) {
            return Response::validation(['media_type' => ['Type de média invalide.']]);
        }
        $page = $this->content->listPublicMedia((int) $site['id'], $languageCode, $this->limit(), $this->offset(), $mediaType);
        return $this->json([
            'items' => $page['items'],
            'pagination' => [
                'total' => $page['total'],
                'limit' => $page['limit'],
                'offset' => $page['offset'],
                'has_more' => $page['has_more'],
            ],
        ], 'public.media.index.v1', [
            'site_id' => (int) $site['id'],
            'language_code' => $languageCode,
            'media_type' => $mediaType !== '' ? $mediaType : null,
        ], 200, 600);
    }

    public function media(string|int $id): Response
    {
        [$site, $languageCode] = $this->context();
        $mediaId = (int) $id;
        if ($mediaId < 1) {
            return Response::validation(['id' => ['Identifiant média invalide.']]);
        }
        $row = $this->db->one(
            "SELECT ma.*, mal.alt_text, mal.caption, mal.title AS localized_title
             FROM media_assets ma
             LEFT JOIN media_asset_localizations mal ON mal.media_id = ma.id AND mal.language_code = :language
             WHERE ma.id = :id
               AND ma.site_id = :site_id
               AND ma.lifecycle_status = 'ready'
               AND ma.validation_status = 'valid'
             LIMIT 1",
            ['id' => $mediaId, 'site_id' => (int) $site['id'], 'language' => $languageCode]
        );
        if (!$row) {
            return Response::error(ErrorCode::MEDIA_NOT_FOUND, ErrorCode::message(ErrorCode::MEDIA_NOT_FOUND), 404, [
                'site_id' => (int) $site['id'],
                'language_code' => $languageCode,
                'media_id' => $mediaId,
            ], $this->cacheHeaders(60));
        }
        $variants = $this->db->all(
            "SELECT variant_key, path, width, height, format, mime_type, size_bytes
             FROM media_asset_variants
             WHERE media_id = :id AND generation_status = 'ready'
             ORDER BY width, variant_key",
            ['id' => $mediaId]
        );
        return $this->json($this->normalizeMedia($row, $variants), 'public.media.show.v1', [
            'site_id' => (int) $site['id'],
            'language_code' => $languageCode,
            'media_id' => $mediaId,
        ], 200, 600);
    }

    /** @return array{0:array<string,mixed>,1:string} */
    private function context(): array
    {
        $site = $this->resolveSite();
        $languageCode = strtolower(trim((string) ($this->request->query['lang'] ?? $site['default_language_code'] ?? 'fr')));
        if (!preg_match('/^[a-z]{2}(?:-[a-z0-9]{2,8})?$/i', $languageCode)) {
            throw new \InvalidArgumentException('Langue invalide.');
        }
        if (!$this->sites->isLanguageEnabled((int) $site['id'], $languageCode)) {
            return [
                $site,
                (string) ($site['default_language_code'] ?? 'fr'),
            ];
        }
        return [$site, $languageCode];
    }

    /** @return array<string,mixed> */
    private function resolveSite(): array
    {
        $siteId = (int) ($this->request->query['site_id'] ?? 0);
        $siteKey = $this->safeSlug((string) ($this->request->query['site'] ?? ''));
        if ($siteId > 0) {
            $site = $this->sites->findActiveSite($siteId);
            if (!$site) {
                throw new \InvalidArgumentException('Site invalide.');
            }
            return $site;
        }
        if ($siteKey !== '') {
            $site = $this->db->one('SELECT * FROM sites WHERE site_key = :key AND is_active = 1 LIMIT 1', ['key' => $siteKey]);
            if (!$site) {
                throw new \InvalidArgumentException('Site invalide.');
            }
            return $site + $this->sites->resolveCurrentSite((string) ($this->request->server['HTTP_HOST'] ?? ''), $this->request->path);
        }
        return $this->sites->resolveCurrentSite((string) ($this->request->server['HTTP_HOST'] ?? ''), $this->request->path);
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $meta */
    private function json(array $data, string $contract, array $meta = [], int $status = 200, int $ttl = 300): Response
    {
        return $this->responder->success($data, $contract, $meta, $status, $this->cacheHeaders($ttl));
    }

    /** @return array<string,string> */
    private function cacheHeaders(int $ttl): array
    {
        $ttl = max(0, $ttl);
        return [
            'Cache-Control' => $ttl > 0 ? 'public, max-age=' . $ttl . ', stale-while-revalidate=60' : 'no-store',
            'Vary' => 'Accept, Accept-Language',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function normalizePublicPayload(array $payload): array
    {
        $resource = is_array($payload['resource'] ?? null) ? $payload['resource'] : [];
        $entry = is_array($resource['entry'] ?? null) ? $resource['entry'] : [];
        $snapshot = is_array($resource['public_snapshot'] ?? null) ? $resource['public_snapshot'] : [];
        $document = json_decode((string) ($snapshot['document_json'] ?? '{}'), true);
        $document = is_array($document) ? $document : [];
        $fields = is_array($document['fields'] ?? null) ? $document['fields'] : [];

        return [
            'kind' => (string) ($payload['template'] ?? 'page'),
            'site' => $this->publicSite($payload['site'] ?? []),
            'language' => (string) ($payload['languageCode'] ?? ''),
            'route' => [
                'path' => (string) ($payload['current_path'] ?? ''),
                'canonical' => (string) ($payload['canonical'] ?? ''),
                'breadcrumbs' => $payload['breadcrumbs'] ?? [],
                'alternates' => $payload['languages'] ?? [],
                'x_default_url' => (string) ($payload['x_default_url'] ?? ''),
            ],
            'content' => [
                'id' => (int) ($entry['id'] ?? 0),
                'type' => (string) ($entry['type_key'] ?? $payload['template'] ?? ''),
                'key' => (string) ($payload['entry_key'] ?? ''),
                'title' => (string) ($payload['entry_title'] ?? $payload['title'] ?? ''),
                'summary' => (string) ($payload['entry_summary'] ?? ''),
                'published_at' => (string) ($payload['entry_published_at'] ?? $snapshot['published_at'] ?? ''),
                'published_at_label' => (string) ($payload['entry_published_at_label'] ?? ''),
                'updated_at' => (string) ($payload['entry_updated_at'] ?? ''),
                'updated_at_label' => (string) ($payload['entry_updated_at_label'] ?? ''),
                'author_name' => (string) ($payload['entry_author_name'] ?? ''),
                'display' => $payload['article_display'] ?? null,
                'fields' => $fields,
                'blocks' => $payload['entry_blocks'] ?? [],
            ],
            'listing' => $this->listingFromPayload($payload),
            'taxonomies' => $resource['taxonomies'] ?? [],
            'menus' => [
                'primary' => $payload['menu_items'] ?? [],
                'footer' => $payload['footer_menu_items'] ?? [],
                'footer_top' => $payload['footer_top_menu_items'] ?? [],
                'footer_bottom' => $payload['footer_bottom_menu_items'] ?? [],
            ],
            'seo' => [
                'title' => (string) ($payload['meta_title'] ?? $payload['title'] ?? ''),
                'description' => (string) ($payload['meta_description'] ?? ''),
                'robots' => (string) ($payload['meta_robots'] ?? 'index,follow'),
                'canonical' => (string) ($payload['canonical'] ?? ''),
                'json_ld' => $payload['json_ld'] ?? null,
                'open_graph' => [
                    'type' => (string) ($payload['og_type'] ?? 'website'),
                    'title' => (string) ($payload['og_title'] ?? $payload['meta_title'] ?? ''),
                    'description' => (string) ($payload['og_description'] ?? $payload['meta_description'] ?? ''),
                    'image' => (string) ($payload['og_image'] ?? ''),
                    'locale' => (string) ($payload['og_locale'] ?? ''),
                    'locale_alternates' => $payload['og_locale_alternates'] ?? [],
                ],
                'twitter' => [
                    'title' => (string) ($payload['twitter_title'] ?? $payload['meta_title'] ?? ''),
                    'description' => (string) ($payload['twitter_description'] ?? $payload['meta_description'] ?? ''),
                ],
            ],
            'media' => $this->mediaFromValue($payload),
            'ui' => $payload['ui'] ?? [],
        ];
    }

    /** @param mixed $site @return array<string,mixed> */
    private function publicSite(mixed $site): array
    {
        $site = is_array($site) ? $site : [];
        return [
            'id' => (int) ($site['id'] ?? 0),
            'key' => (string) ($site['site_key'] ?? ''),
            'name' => (string) ($site['name'] ?? ''),
            'base_url' => (string) ($site['base_url'] ?? $site['canonical_base_url'] ?? ''),
            'default_language_code' => (string) ($site['default_language_code'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed>|null */
    private function listingFromPayload(array $payload): ?array
    {
        if (isset($payload['article_items'])) {
            return [
                'type' => 'articles',
                'items' => $payload['article_items'],
                'facets' => $payload['article_facets'] ?? [],
                'pagination' => $payload['article_pagination'] ?? null,
                'filter' => $payload['article_filter'] ?? null,
            ];
        }
        if (isset($payload['archive_items'])) {
            return [
                'type' => 'taxonomy_archive',
                'items' => $payload['archive_items'],
                'taxonomy' => $payload['archive_taxonomy'] ?? null,
                'term' => $payload['archive_term'] ?? null,
            ];
        }
        if (isset($payload['search_results'])) {
            return [
                'type' => 'search',
                'items' => $payload['search_results'],
                'query' => $payload['search_query'] ?? '',
                'filters' => $payload['search_filters'] ?? [],
            ];
        }
        return null;
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function normalizeListItem(array $item, string $languageCode): array
    {
        $path = (string) ($item['full_path'] ?? $item['path'] ?? '');
        return [
            'id' => (int) ($item['id'] ?? 0),
            'key' => (string) ($item['entry_key'] ?? ''),
            'type' => (string) ($item['type_key'] ?? ''),
            'title' => (string) ($item['title'] ?? ''),
            'summary' => (string) ($item['summary'] ?? $item['meta_description'] ?? ''),
            'path' => $path,
            'url' => $path !== '' ? localized_path($path, $languageCode) : '',
            'published_at' => (string) ($item['published_at'] ?? ''),
            'updated_at' => (string) ($item['updated_at'] ?? ''),
            'seo' => [
                'title' => (string) ($item['meta_title'] ?? $item['title'] ?? ''),
                'description' => (string) ($item['meta_description'] ?? $item['summary'] ?? ''),
            ],
            'taxonomies' => $item['taxonomies'] ?? [],
        ];
    }

    /** @param list<array<string,mixed>> $items @return list<array<string,mixed>> */
    private function normalizeMenuTree(array $items, string $languageCode): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item) || empty($item['is_active'])) {
                continue;
            }
            $url = trim((string) ($item['manual_url'] ?? ''));
            if ($url === '') {
                $url = trim((string) ($item['target_path'] ?? ''));
                $url = $url !== '' ? localized_path($url, $languageCode) : '#';
            }
            $out[] = [
                'id' => (int) ($item['id'] ?? 0),
                'label' => (string) ($item['label'] ?? $item['target_label'] ?? ''),
                'title' => (string) ($item['title_attr'] ?? ''),
                'url' => $url,
                'target' => !empty($item['open_in_new_tab']) ? '_blank' : '_self',
                'css_class' => (string) ($item['css_class'] ?? ''),
                'resource_type' => $item['resource_type'] ?? null,
                'resource_id' => isset($item['resource_id']) ? (int) $item['resource_id'] : null,
                'children' => $this->normalizeMenuTree($item['children'] ?? [], $languageCode),
            ];
        }
        return $out;
    }

    /** @param array<string,mixed> $row @param list<array<string,mixed>> $variants @return array<string,mixed> */
    private function normalizeMedia(array $row, array $variants): array
    {
        $publicPath = trim((string) ($row['public_path'] ?? $row['path'] ?? ''));
        $siteId = (int) ($row['site_id'] ?? 0);
        $disk = (string) ($row['storage_disk'] ?? 'local');
        return [
            'id' => (int) ($row['id'] ?? 0),
            'uuid' => (string) ($row['uuid'] ?? ''),
            'url' => $this->mediaUrls->publicUrl($publicPath, $siteId, $disk),
            'filename' => (string) ($row['filename'] ?? ''),
            'mime_type' => (string) ($row['mime_type'] ?? ''),
            'media_type' => (string) ($row['media_type'] ?? ''),
            'size_bytes' => (int) ($row['size_bytes'] ?? 0),
            'width' => isset($row['width']) ? (int) $row['width'] : null,
            'height' => isset($row['height']) ? (int) $row['height'] : null,
            'alt_text' => (string) ($row['alt_text'] ?? ''),
            'caption' => (string) ($row['caption'] ?? ''),
            'title' => (string) ($row['localized_title'] ?? ''),
            'variants' => array_map(fn(array $variant): array => [
                'key' => (string) ($variant['variant_key'] ?? ''),
                'url' => $this->mediaUrls->publicUrl((string) ($variant['path'] ?? ''), $siteId, $disk),
                'width' => isset($variant['width']) ? (int) $variant['width'] : null,
                'height' => isset($variant['height']) ? (int) $variant['height'] : null,
                'format' => (string) ($variant['format'] ?? ''),
                'mime_type' => (string) ($variant['mime_type'] ?? ''),
                'size_bytes' => isset($variant['size_bytes']) ? (int) $variant['size_bytes'] : null,
            ], $variants),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function mediaFromValue(mixed $value): array
    {
        $media = [];
        $this->collectMedia($value, $media);
        return array_values($media);
    }

    /** @param array<string,array<string,mixed>> $media */
    private function collectMedia(mixed $value, array &$media): void
    {
        if (!is_array($value)) {
            return;
        }
        if (isset($value['url']) && is_string($value['url'])) {
            $key = (string) ($value['id'] ?? $value['url']);
            $media[$key] = array_intersect_key($value, array_flip(['id', 'url', 'srcset', 'sizes', 'width', 'height', 'mime_type', 'alt', 'caption']));
        }
        foreach ($value as $child) {
            $this->collectMedia($child, $media);
        }
    }

    /** @return array{items:list<array<string,mixed>>,total:int,limit:int,offset:int,has_more:bool} */
    private function publishedContentPage(string $type): array
    {
        [$site, $languageCode] = $this->context();
        return $this->content->listPublishedHeadless((int) $site['id'], $languageCode, [
            'type' => $type,
            'taxonomy' => $this->safeSlug((string) ($this->request->query['taxonomy'] ?? '')),
            'term' => $this->safeSlug((string) ($this->request->query['term'] ?? '')),
            'limit' => $this->limit(),
            'offset' => $this->offset(),
        ]);
    }

    private function limit(): int
    {
        return max(1, min(self::MAX_LIMIT, (int) ($this->request->query['limit'] ?? 24)));
    }

    private function offset(): int
    {
        if (isset($this->request->query['page'])) {
            return max(0, ((int) $this->request->query['page'] - 1) * $this->limit());
        }
        return max(0, (int) ($this->request->query['offset'] ?? 0));
    }

    private function normalizePath(string $path): string
    {
        $path = parse_url($path, PHP_URL_PATH) ?: '/';
        $path = rawurldecode($path);
        $path = '/' . ltrim($path, '/');
        $path = (string) preg_replace('#/+#', '/', $path);
        $path = $path !== '/' ? rtrim($path, '/') : '/';
        $path = mb_strtolower($path);
        return $path === '' ? '/' : $path;
    }

    private function safeSlug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_\-]+/', '-', $value) ?: '';
        return trim($value, '-_');
    }

    private function contentTypeExists(string $type): bool
    {
        return (bool) $this->db->one('SELECT 1 FROM content_types WHERE type_key = :type AND is_active = 1 LIMIT 1', ['type' => $type]);
    }

    /** @param array<string,mixed> $row */
    private function routeMatchesType(array $row, string $type): bool
    {
        if ((string) ($row['resource_type'] ?? '') !== 'content_entry') {
            return false;
        }
        $entryId = (int) ($row['resource_id'] ?? 0);
        if ($entryId < 1) {
            return false;
        }
        return (bool) $this->db->one(
            'SELECT 1 FROM content_entries ce JOIN content_types ct ON ct.id = ce.content_type_id WHERE ce.id = :id AND ct.type_key = :type LIMIT 1',
            ['id' => $entryId, 'type' => $type]
        );
    }
}
