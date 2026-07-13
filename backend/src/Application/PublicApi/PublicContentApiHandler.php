<?php

declare(strict_types=1);

namespace App\Application\PublicApi;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Application\Content\Read\PublicContentReadRepository;
use App\Application\Routing\PublicRouteReadRepository;
use App\Repository\SiteRepository;
use App\Application\Business\ProductContentLinkService;

final class PublicContentApiHandler
{
    public function __construct(
        private readonly Request $request,
        private readonly PublicContentReadRepository $content,
        private readonly PublicRouteReadRepository $routes,
        private readonly SiteRepository $sites,
        private readonly Database $db,
        private readonly ProductContentLinkService $productContentLinks,
    ) {}

    public function index(string $type): Response
    {
        $site = $this->sites->resolveCurrentSite($this->request->server['HTTP_HOST'] ?? '');
        $languageCode = (string) ($this->request->query['lang'] ?? $site['default_language_code'] ?? 'fr');
        return Response::success(
            $this->content->listPublishedByType((int) $site['id'], $type, $languageCode),
            'public.content.index.v1',
            ['site_id' => (int) $site['id'], 'language_code' => $languageCode, 'source' => 'public_content_snapshots']
        );
    }

    public function show(string $type, string $slug): Response
    {
        $site = $this->sites->resolveCurrentSite($this->request->server['HTTP_HOST'] ?? '');
        $languageCode = (string) ($this->request->query['lang'] ?? $site['default_language_code'] ?? 'fr');
        $item = $this->content->getPublishedByTypeAndSlug((int) $site['id'], $type, trim($slug, '/'), $languageCode);
        if (!$item) {
            return Response::error('PUBLIC_CONTENT_NOT_FOUND', 'Le contenu demandé est introuvable.', 404, [
                'type' => $type,
                'slug' => trim($slug, '/'),
                'site_id' => (int) $site['id'],
                'language_code' => $languageCode,
            ]);
        }

        return Response::success($this->headlessContract($item), 'public.content.show.v1', ['site_id' => (int) $site['id'], 'language_code' => $languageCode, 'source' => 'public_content_snapshots']);
    }

    public function byPath(): Response
    {
        $site = $this->sites->resolveCurrentSite($this->request->server['HTTP_HOST'] ?? '');
        $languageCode = (string) ($this->request->query['lang'] ?? $site['default_language_code'] ?? 'fr');
        $path = $this->normalizeApiPath((string) ($this->request->query['path'] ?? '/'));
        $item = $this->content->getPublishedByPath((int) $site['id'], $path, $languageCode);
        if (!$item) {
            return Response::error('PUBLIC_CONTENT_NOT_FOUND', 'Le contenu demandé est introuvable.', 404, [
                'path' => $path,
                'site_id' => (int) $site['id'],
                'language_code' => $languageCode,
                'source' => 'public_content_snapshots',
            ]);
        }

        return Response::success($this->headlessContract($item), 'public.content.by_path.v1', [
            'site_id' => (int) $site['id'],
            'language_code' => $languageCode,
            'path' => $path,
            'source' => 'public_content_snapshots',
        ]);
    }

    /** @param array<string,mixed> $aggregate @return array<string,mixed> */
    private function headlessContract(array $aggregate): array
    {
        $entry = is_array($aggregate['entry'] ?? null) ? $aggregate['entry'] : [];
        $route = is_array($aggregate['route'] ?? null) ? $aggregate['route'] : [];
        $publication = is_array($aggregate['publication'] ?? null) ? $aggregate['publication'] : [];
        $snapshot = is_array($aggregate['public_snapshot'] ?? null) ? $aggregate['public_snapshot'] : [];
        $document = json_decode((string) ($snapshot['document_json'] ?? '{}'), true);
        $document = is_array($document) ? $document : [];
        $content = is_array($document['content'] ?? null) ? $document['content'] : [];
        $seo = is_array($aggregate['seo'] ?? null) ? $aggregate['seo'] : [];
        $fields = is_array($document['fields'] ?? null) ? $document['fields'] : [];
        $blocks = is_array($document['blocks'] ?? null) ? $document['blocks'] : [];
        $blocks = $this->productContentLinks->hydrateStorefrontBlocks($blocks, (int)($entry['site_id']??0), (string)($publication['language_code']??$snapshot['language_code']??''));

        $displayPublishedAt = $this->displayPublishedAt($fields, (string) ($publication['published_at'] ?? $snapshot['published_at'] ?? ''));
        $displaySettings = $this->articleDisplaySettings($fields, (int) ($entry['site_id'] ?? 0));
        $authorName = $this->displayAuthorName($fields);

        $products = $this->productContentLinks->publicProductsForContent(
            (int) ($entry['site_id'] ?? 0),
            (int) ($entry['id'] ?? 0),
            (string) ($publication['language_code'] ?? $snapshot['language_code'] ?? '')
        );

        return [
            'system' => [
                'id' => (int) ($entry['id'] ?? 0),
                'site_id' => (int) ($entry['site_id'] ?? 0),
                'content_type' => (string) ($entry['type_key'] ?? ''),
                'entry_key' => (string) ($entry['entry_key'] ?? ''),
                'language_code' => (string) ($publication['language_code'] ?? $snapshot['language_code'] ?? ''),
                'status' => 'published',
                'published_at' => (string) ($publication['published_at'] ?? $snapshot['published_at'] ?? ''),
                'source_revision_id' => (int) ($snapshot['source_published_revision_id'] ?? 0),
            ],
            'editorial' => [
                'title' => (string) ($content['title'] ?? $snapshot['title'] ?? ''),
                'slug' => (string) ($content['slug'] ?? $snapshot['slug'] ?? ''),
                'path' => (string) ($route['full_path'] ?? $snapshot['route_path'] ?? ''),
                'fields' => $fields,
                'published_at' => $displayPublishedAt,
                'published_at_label' => $this->formatPublicDate($displayPublishedAt, (string) $displaySettings['date_format'], (string) ($publication['language_code'] ?? $snapshot['language_code'] ?? 'fr')),
                'author_name' => $authorName,
                'display' => $displaySettings,
            ],
            'seo' => [
                'meta_title' => (string) ($seo['meta_title'] ?? ''),
                'meta_description' => (string) ($seo['meta_description'] ?? ''),
                'meta_robots' => (string) ($seo['meta_robots'] ?? 'index,follow'),
                'canonical_url' => (string) ($seo['canonical_url'] ?? ($route['full_path'] ?? $snapshot['route_path'] ?? '')),
                'json_ld' => $seo['json_ld'] ?? null,
                'hreflang_code' => (string) ($seo['hreflang_code'] ?? $publication['language_code'] ?? ''),
            ],
            'blocks' => $blocks,
            'media' => $this->mediaFromDocument($document),
            'commerce' => ['products' => $products],
            'links' => [
                'canonical' => (string) ($seo['canonical_url'] ?? ($route['full_path'] ?? $snapshot['route_path'] ?? '')),
                'hreflang' => $this->alternates($aggregate),
            ],
            'meta' => [
                'source' => 'public_content_snapshots',
                'projected_at' => (string) ($snapshot['projected_at'] ?? ''),
                'blueprint' => $document['blueprint'] ?? null,
            ],
        ];
    }

    /** @param array<string,mixed> $fields @return array<string,mixed> */
    private function articleDisplaySettings(array $fields, int $siteId): array
    {
        $settings = $this->defaultArticleDisplaySettings($siteId);
        if (!$this->truthy($fields['article_detail_use_custom_display_settings'] ?? false)) {
            return $settings;
        }
        if (array_key_exists('article_detail_show_published_date', $fields)) {
            $settings['show_published_date'] = $this->truthy($fields['article_detail_show_published_date']);
        }
        if (array_key_exists('article_detail_show_author', $fields)) {
            $settings['show_author'] = $this->truthy($fields['article_detail_show_author']);
        }
        if (array_key_exists('article_detail_show_updated_date', $fields)) {
            $settings['show_updated_date'] = $this->truthy($fields['article_detail_show_updated_date']);
        }
        if (array_key_exists('article_detail_show_type', $fields)) {
            $settings['show_type'] = $this->truthy($fields['article_detail_show_type']);
        }
        if (array_key_exists('article_detail_include_author_in_schema', $fields)) {
            $settings['include_author_in_schema'] = $this->truthy($fields['article_detail_include_author_in_schema']);
        }
        if (array_key_exists('article_detail_date_format', $fields)) {
            $settings['date_format'] = $this->safeDateFormat((string) $fields['article_detail_date_format']);
        }
        return $settings;
    }

    /** @return array{show_published_date:bool,show_author:bool,show_updated_date:bool,show_type:bool,include_author_in_schema:bool,date_format:string} */
    private function defaultArticleDisplaySettings(int $siteId): array
    {
        $settings = [
            'show_published_date' => true,
            'show_author' => true,
            'show_updated_date' => false,
            'show_type' => true,
            'include_author_in_schema' => true,
            'date_format' => 'medium',
        ];
        if ($siteId <= 0) {
            return $settings;
        }
        $row = $this->db->one("SELECT value_json FROM site_settings WHERE site_id = :site_id AND namespace = 'articles' AND setting_key = 'defaults' LIMIT 1", ['site_id' => $siteId]);
        $defaults = json_decode((string) ($row['value_json'] ?? '{}'), true);
        if (!is_array($defaults)) {
            return $settings;
        }
        return [
            'show_published_date' => $this->truthy($defaults['detail_show_published_date'] ?? $settings['show_published_date']),
            'show_author' => $this->truthy($defaults['detail_show_author'] ?? $settings['show_author']),
            'show_updated_date' => $this->truthy($defaults['detail_show_updated_date'] ?? $settings['show_updated_date']),
            'show_type' => $this->truthy($defaults['detail_show_type'] ?? $settings['show_type']),
            'include_author_in_schema' => $this->truthy($defaults['detail_include_author_in_schema'] ?? $settings['include_author_in_schema']),
            'date_format' => $this->safeDateFormat((string) ($defaults['detail_date_format'] ?? $settings['date_format'])),
        ];
    }

    /** @param array<string,mixed> $fields */
    private function displayPublishedAt(array $fields, string $fallback): string
    {
        foreach (['display_published_at', 'published_at', 'publication_date'] as $key) {
            $value = trim((string) ($fields[$key] ?? ''));
            if ($value === '') { continue; }
            $timestamp = strtotime($value);
            return $timestamp !== false ? gmdate('Y-m-d H:i:s', $timestamp) : $value;
        }
        return $fallback;
    }

    /** @param array<string,mixed> $fields */
    private function displayAuthorName(array $fields): string
    {
        foreach (['author_name', 'display_author_name', 'byline'] as $key) {
            $value = trim((string) ($fields[$key] ?? ''));
            if ($value !== '') { return $value; }
        }
        return '';
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) { return $value; }
        if (is_numeric($value)) { return (int) $value === 1; }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'oui', 'on'], true);
    }

    private function safeDateFormat(string $format): string
    {
        $format = strtolower(trim($format));
        return in_array($format, ['short', 'medium', 'long', 'iso'], true) ? $format : 'medium';
    }

    private function formatPublicDate(string $date, string $format, string $languageCode): string
    {
        $timestamp = strtotime(trim($date));
        if ($timestamp === false) { return trim($date); }
        $format = $this->safeDateFormat($format);
        if ($format === 'iso') { return date('Y-m-d', $timestamp); }
        if ($format === 'short') { return date('d.m.Y', $timestamp); }
        if (class_exists(\IntlDateFormatter::class)) {
            $locale = match (strtolower(substr($languageCode, 0, 2))) {
                'en' => 'en_GB',
                'de' => 'de_CH',
                default => 'fr_CH',
            };
            $formatter = new \IntlDateFormatter($locale, $format === 'long' ? \IntlDateFormatter::FULL : \IntlDateFormatter::LONG, \IntlDateFormatter::NONE);
            $formatted = $formatter->format($timestamp);
            if (is_string($formatted) && $formatted !== '') { return $formatted; }
        }
        return date('d.m.Y', $timestamp);
    }

    /** @param array<string,mixed> $aggregate @return list<array<string,mixed>> */
    private function alternates(array $aggregate): array
    {
        $entry = is_array($aggregate['entry'] ?? null) ? $aggregate['entry'] : [];
        $siteId = (int) ($entry['site_id'] ?? 0);
        $entryId = (int) ($entry['id'] ?? 0);
        if ($siteId < 1 || $entryId < 1 || !method_exists($this->content, 'listPublishedRouteAlternates')) {
            return [];
        }
        return array_map(static fn(array $row): array => [
            'language_code' => (string) ($row['language_code'] ?? ''),
            'hreflang' => (string) ($row['hreflang_code'] ?? $row['language_code'] ?? ''),
            'path' => (string) ($row['full_path'] ?? ''),
            'is_default' => (bool) ($row['is_default'] ?? false),
        ], $this->routes->listPublishedRouteAlternates($siteId, 'content_entry', $entryId));
    }

    /** @param array<string,mixed> $document @return array<string,mixed> */
    private function mediaFromDocument(array $document): array
    {
        $media = [];
        $this->collectMedia($document, $media);
        return array_values($media);
    }

    /** @param array<string,mixed> $media */
    private function collectMedia(mixed $value, array &$media): void
    {
        if (!is_array($value)) { return; }
        if (isset($value['url']) && is_string($value['url'])) {
            $key = (string) ($value['id'] ?? $value['url']);
            $media[$key] = array_intersect_key($value, array_flip(['id', 'url', 'srcset', 'sizes', 'width', 'height', 'mime_type']));
        }
        foreach ($value as $child) {
            $this->collectMedia($child, $media);
        }
    }

    private function normalizeApiPath(string $path): string
    {
        $path = parse_url($path, PHP_URL_PATH) ?: '/';
        $path = rawurldecode($path);
        $path = '/' . ltrim($path, '/');
        $path = (string) preg_replace('#/+#', '/', $path);
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }
        $path = mb_strtolower($path);
        return $path === '' ? '/' : $path;
    }
}
