<?php

declare(strict_types=1);

namespace App\Application\Frontend;

use App\Core\Renderer;
use App\Core\Response;
use App\Application\Content\Read\EditorialContentReadRepository;
use App\Application\Routing\PublicRouteReadRepository;
use App\Repository\SiteRepository;
use App\Security\PreviewSigner;
use App\Security\EditorialBlockSecurityPolicy;
use App\Application\Commerce\StorefrontMerchandisingService;

abstract class FrontendPageController
{
    public function __construct(
        protected readonly array $config,
        protected readonly \App\Core\Request $request,
        private readonly EditorialContentReadRepository $content,
        private readonly PublicRouteReadRepository $routes,
        private readonly SiteRepository $sites,
        private readonly ResolvePublicRoute $resolvePublicRoute,
        private readonly ?StorefrontMerchandisingService $merchandising = null,
    ) {}

    protected function handlePublicRequest(): Response
    {
        $site = $this->sites->resolveCurrentSite($this->request->server['HTTP_HOST'] ?? '');
        $languageCode = (string) ($this->request->query['lang'] ?? $site['default_language_code'] ?? $this->config['app']['default_locale']);
        $path = $this->request->path ?: '/';

        if ($canonicalRedirect = $this->canonicalRequestRedirect($site, $languageCode, $path)) {
            return $canonicalRedirect;
        }
        $path = strip_localized_path_prefix($this->normalizePublicPath($path));

        if ($path === '/sitemap.xml') {
            return $this->sitemap($site, $languageCode);
        }
        if ($path === '/sitemap.xsl') {
            return $this->sitemapStylesheet();
        }
        if ($path === '/llms.txt') {
            return $this->llmsTxt($site, $languageCode);
        }
        if ($path === '/robots.txt') {
            return $this->robots($site);
        }
        if ($path === '/search') {
            return $this->respondForMode($site, $languageCode, $this->resolvePublicRoute->searchPayload($site, $languageCode, trim((string) $this->request->input('q', '')), $this->request->query));
        }
        if (preg_match('#^/preview/(\d+)$#', $path, $m)) {
            return $this->preview($site, $languageCode, (int) $m[1]);
        }

        $result = $this->resolvePublicRoute->execute($site, $languageCode, $path, $this->request->query);
        if ($result['type'] === 'payload' && (int) $result['status'] === 200 && !$this->wantsJson()) {
            $this->recordStorefrontActivity($site, $languageCode, $result['payload']);
        }
        return match ($result['type']) {
            'redirect' => redirect((string) $result['to'], (int) $result['status']),
            'payload' => $this->respondForMode($site, $languageCode, $result['payload'], (int) $result['status']),
            default => Response::html('<h1>404</h1><p>Page introuvable.</p>', 404),
        };
    }

    /** @param array<string,mixed> $site @param array<string,mixed> $payload */
    private function recordStorefrontActivity(array $site, string $languageCode, array $payload): void
    {
        if ($this->merchandising === null) return;
        $template=(string)($payload['template']??'');
        if ($template==='storefront-product') {
            $product=(array)($payload['storefront_product']??[]);
            $this->merchandising->recordProductView((int)$site['id'],$languageCode,(int)($product['product_id']??0),$this->request->server,$this->request->query);
            return;
        }
        if ($template==='storefront-shop') {
            $term=trim((string)($this->request->query['q']??''));
            $catalog=(array)($payload['storefront_catalog']??[]);
            $this->merchandising->recordSearch((int)$site['id'],$languageCode,$term,(int)($catalog['pagination']['total']??0),$this->request->server,$this->request->query);
        }
    }

    protected function preview(array $site, string $languageCode, int $entryId): Response
    {
        $revId = (int) ($this->request->query['rev'] ?? 0);
        $tokenClaims = $this->previewClaims($entryId, $revId, (int) $site['id'], $languageCode);
        if ($tokenClaims === null) {
            return $this->previewDenied();
        }

        $revisionId = (int) ($tokenClaims['revision_id'] ?: $revId);
        $aggregate = $this->content->previewAggregate($entryId, $languageCode, $revisionId ?: null, (int) $site['id']);
        if (!$aggregate) {
            return Response::html('<h1>404</h1><p>Révision de prévisualisation introuvable.</p>', 404, $this->previewSecurityHeaders());
        }

        $payload = $this->resolvePublicRoute->entryPayload($site, $languageCode, $aggregate, true);
        $payload['preview'] = $aggregate['preview'] ?? [];
        if ((string) ($this->request->query['visual'] ?? '') === '1') {
            $payload['visual_editing'] = true;
        }
        $payload['canonical'] = localized_absolute_url((string) ($aggregate['route']['full_path'] ?? '/'), $languageCode, (string) ($site['base_url'] ?? ''));
        $response = $this->respondForMode($site, $languageCode, $payload);
        return $response->withHeaders($this->previewSecurityHeaders());
    }

    /** @return array{entry_id:int,revision_id:int,site_id:int,language_code:string,expires_at:int,nonce:string}|null */
    protected function previewClaims(int $entryId, int $revisionId, int $siteId, string $languageCode): ?array
    {
        $token = (string) ($this->request->query['token'] ?? '');
        if ($token === '') {
            return null;
        }
        $signer = new PreviewSigner((string) $this->config['app']['preview_signing_key'], (int) ($this->config['cms']['preview_ttl_minutes'] ?? 60));
        $claims = $signer->validateClaims($token);
        if ($claims === null) {
            return null;
        }
        if ((int) $claims['entry_id'] !== $entryId) {
            return null;
        }
        if ($revisionId > 0 && (int) $claims['revision_id'] !== $revisionId) {
            return null;
        }
        if ((int) $claims['site_id'] > 0 && (int) $claims['site_id'] !== $siteId) {
            return null;
        }
        if ((string) $claims['language_code'] !== '' && (string) $claims['language_code'] !== strtolower($languageCode)) {
            return null;
        }
        return $claims;
    }

    protected function previewDenied(): Response
    {
        return Response::html('<h1>403</h1><p>Prévisualisation non autorisée ou expirée.</p>', 403, $this->previewSecurityHeaders());
    }

    /** @return array<string,string> */
    protected function previewSecurityHeaders(): array
    {
        return [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Referrer-Policy' => 'same-origin',
            'Content-Security-Policy' => $this->cspHeader(),
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    protected function respondForMode(array $site, string $languageCode, array $payload, int $status = 200): Response
    {
        if ($this->wantsJson()) {
            return Response::json([
                'site' => ['id' => $site['id'], 'site_key' => $site['site_key'], 'language' => $languageCode],
                'data' => $payload['resource'] ?? [],
                'meta' => [
                    'title' => $payload['meta_title'] ?? '',
                    'description' => $payload['meta_description'] ?? '',
                    'robots' => $payload['meta_robots'] ?? '',
                    'canonical' => $payload['canonical'] ?? '',
                    'alternates' => $payload['languages'] ?? [],
                    'x_default_url' => $payload['x_default_url'] ?? '',
                    'open_graph' => [
                        'title' => $payload['og_title'] ?? $payload['meta_title'] ?? '',
                        'description' => $payload['og_description'] ?? $payload['meta_description'] ?? '',
                        'type' => $payload['og_type'] ?? 'website',
                        'image' => $payload['og_image'] ?? '',
                    ],
                    'twitter' => [
                        'card' => $payload['twitter_card'] ?? 'summary_large_image',
                        'title' => $payload['twitter_title'] ?? $payload['og_title'] ?? $payload['meta_title'] ?? '',
                        'description' => $payload['twitter_description'] ?? $payload['og_description'] ?? $payload['meta_description'] ?? '',
                        'image' => $payload['twitter_image'] ?? '',
                    ],
                    'json_ld' => $payload['json_ld'] ?? '',
                    'seo_score' => $payload['resource']['seo']['seo_score'] ?? null,
                    'source' => 'public_content_snapshots',
                    'preview' => (bool) ($payload['is_preview'] ?? false),
                ],
            ], $status);
        }
        return $this->pageLayout((string) $payload['template'], $payload, $status);
    }

    protected function wantsJson(): bool
    {
        $format = strtolower((string) ($this->request->query['_format'] ?? ''));
        if ($format === 'json') {
            return true;
        }
        $accept = strtolower((string) ($this->request->server['HTTP_ACCEPT'] ?? ''));
        return str_contains($accept, 'application/json');
    }

    protected function pageLayout(string $template, array $data, int $status = 200): Response
    {
        [$themeKey, $theme, $isThemePreview] = $this->activeTheme((int) ($data['site']['id'] ?? 0), (string) ($data['theme_override_key'] ?? ''));
        $themeBase = (string) ($theme['templates_path'] ?? $theme['path'] ?? $this->config['themes']['default']['templates_path'] ?? base_path('frontend/theme-default/templates'));
        $renderer = new Renderer($themeBase, ['app_name' => $this->config['app']['name']], [
            'cache' => ($this->config['app']['env'] ?? 'production') === 'production' && !$isThemePreview ? (string) ($this->config['app']['twig_cache'] ?? '') : false,
            'cache_path' => (string) ($this->config['app']['twig_cache'] ?? ''),
            'debug' => (bool) ($this->config['app']['debug'] ?? false),
        ]);
        $themeData = $data + [
            'active_theme' => [
                'key' => $themeKey,
                'name' => (string) ($theme['name'] ?? $themeKey),
                'assets_url' => (string) ($theme['assets_url'] ?? ''),
            ],
            'theme_preview' => $isThemePreview,
        ];
        $content = $renderer->render($template, $themeData);
        $html = $renderer->render('layout', $themeData + ['content' => $content]);
        return Response::html($this->cleanRenderedHtml($html), $status, $this->publicCacheHeaders($themeData));
    }

    /** @return array{0:string,1:array<string,mixed>,2:bool} */
    private function activeTheme(int $siteId, string $overrideKey = ''): array
    {
        $themes = (array) ($this->config['themes'] ?? []);
        $fallbackKey = isset($themes['default']) ? 'default' : (array_key_first($themes) ?: 'default');
        $configuredKey = $fallbackKey;

        if ($siteId > 0 && method_exists($this->sites, 'publicUiSettings')) {
            $settings = $this->sites->publicUiSettings($siteId);
            $candidate = trim((string) ($settings['active_theme_key'] ?? ''));
            if ($candidate !== '' && isset($themes[$candidate])) {
                $configuredKey = $candidate;
            }
        }

        $overrideKey = trim($overrideKey);
        if ($overrideKey !== '' && isset($themes[$overrideKey])) {
            $configuredKey = $overrideKey;
        }

        $previewKey = trim((string) ($this->request->query['_theme'] ?? ''));
        $isThemePreview = false;
        if ($previewKey !== '' && isset($themes[$previewKey])) {
            $configuredKey = $previewKey;
            $isThemePreview = true;
        }

        return [$configuredKey, (array) ($themes[$configuredKey] ?? $themes[$fallbackKey] ?? []), $isThemePreview];
    }

    /** @param array<string,mixed> $data @return array<string,string> */
    private function publicCacheHeaders(array $data): array
    {
        if (!empty($data['is_preview'])) {
            return $this->previewSecurityHeaders();
        }
        if (!empty($data['theme_preview'])) {
            return $this->previewSecurityHeaders();
        }

        $headers = [
            'Cache-Control' => 'no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Source' => 'public_content_snapshots',
            'Content-Security-Policy' => $this->cspHeader(),
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Content-Type-Options' => 'nosniff',
        ];

        $snapshot = is_array($data['resource']['public_snapshot'] ?? null) ? $data['resource']['public_snapshot'] : [];
        $checksum = trim((string) ($snapshot['source_revision_checksum_sha256'] ?? ''));
        if ($checksum !== '') {
            $headers['ETag'] = '"' . substr($checksum, 0, 32) . '"';
        }

        $lastModified = trim((string) ($snapshot['projected_at'] ?? $snapshot['published_at'] ?? ''));
        if ($lastModified !== '') {
            $timestamp = strtotime($lastModified);
            if ($timestamp !== false) {
                $headers['Last-Modified'] = gmdate('D, d M Y H:i:s', $timestamp) . ' GMT';
            }
        }

        return $headers;
    }

    private function cspHeader(): string
    {
        return (new EditorialBlockSecurityPolicy((array) ($this->config['cms']['editorial_security'] ?? [])))->contentSecurityPolicyHeader();
    }

    private function cleanRenderedHtml(string $html): string
    {
        $html = str_replace(["\r\n", "\r"], "\n", $html);
        $html = preg_replace('/[ \t]+$/m', '', $html) ?? $html;
        $html = preg_replace('/\n{3,}/', "\n\n", $html) ?? $html;
        return trim($html) . "\n";
    }

    private function canonicalRequestRedirect(array $site, string $languageCode, string $path): ?Response
    {
        if ($this->request->method !== 'GET' && $this->request->method !== 'HEAD') {
            return null;
        }

        $normalizedPath = $this->normalizePublicPath($path);
        $canonicalBase = rtrim((string) ($site['base_url'] ?? ''), '/');
        $currentBase = rtrim((string) ($site['current_base_url'] ?? $canonicalBase), '/');
        $shouldRedirectDomain = $canonicalBase !== '' && $currentBase !== '' && strcasecmp($canonicalBase, $currentBase) !== 0;
        $shouldRedirectHttps = !empty($site['should_redirect_https']);

        if ($normalizedPath === $path && !$shouldRedirectDomain && !$shouldRedirectHttps) {
            return null;
        }

        $target = localized_absolute_url($normalizedPath, $languageCode, $canonicalBase !== '' ? $canonicalBase : null);
        $query = $this->publicQueryString();
        return redirect($target . ($query !== '' ? '?' . $query : ''), 301);
    }

    private function normalizePublicPath(string $path): string
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

    private function publicQueryString(): string
    {
        $query = $this->request->query;
        unset($query['lang']);
        return http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function llmsTxt(array $site, string $languageCode): Response
    {
        $siteId = (int) $site['id'];
        $baseUrl = (string) ($site['base_url'] ?? '');
        $siteName = trim((string) ($site['name'] ?? $this->config['app']['name'] ?? 'DEC CMS'));
        $languageCode = strtolower(trim($languageCode)) ?: (string) ($site['default_language_code'] ?? 'fr');
        $items = $this->routes->listPublishedRoutes($siteId, $languageCode);

        $lines = [
            '# ' . ($siteName !== '' ? $siteName : 'Site'),
            '',
            '> Public content index for LLMs and AI search engines.',
            '',
            'This file lists the canonical, indexable public pages generated by the CMS. It is derived from the same published routes as sitemap.xml and excludes private, draft, preview, admin and API URLs.',
            '',
            '## Site',
            '',
            '- Canonical base: ' . (rtrim($baseUrl, '/') ?: localized_absolute_url('/', $languageCode, $baseUrl)),
            '- Language: ' . $languageCode,
            '- Sitemap: ' . localized_absolute_url('/sitemap.xml', $languageCode, $baseUrl),
            '',
            '## Canonical public pages',
            '',
        ];

        $seen = [];
        foreach ($items as $item) {
            $path = $this->sitemapPath((string) ($item['full_path'] ?? ''));
            if ($path === null) {
                continue;
            }
            $url = localized_absolute_url($path, $languageCode, $baseUrl);
            $key = strtolower($url);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $title = trim((string) ($item['title'] ?? '')) ?: ($path === '/' ? 'Home' : ucwords(str_replace(['-', '_'], ' ', basename($path))));
            $lastmod = $this->sitemapLastModified($item);
            $line = '- [' . $this->llmsText($title) . '](' . $url . ')';
            if ($lastmod !== null) {
                $line .= ' — updated ' . $lastmod;
            }
            $lines[] = $line;
        }

        if (count($seen) === 0) {
            $lines[] = '- No indexable public page is currently published.';
        }

        $lines[] = '';
        $lines[] = '## Usage notes';
        $lines[] = '';
        $lines[] = '- Prefer canonical URLs listed above.';
        $lines[] = '- For multilingual variants, use the hreflang links exposed in each page head and in sitemap.xml.';
        $lines[] = '- Respect robots.txt and page-level robots directives.';

        return new Response(200, implode("\n", $lines) . "\n", [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'public, max-age=300, stale-while-revalidate=3600',
            'X-Content-Source' => 'public_routes',
        ]);
    }

    private function llmsText(string $value): string
    {
        $value = preg_replace('/[\r\n]+/', ' ', $value) ?? $value;
        return trim(str_replace([']', '['], ['\\]', '\\['], $value));
    }

    private function robots(array $site): Response
    {
        $baseUrl = (string) ($site['base_url'] ?? '');
        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin',
            'Disallow: /admin/',
            'Disallow: /api/',
            'Disallow: /preview/',
        ];
        foreach ($this->sites->getLanguages((int) $site['id']) as $language) {
            $code = (string) ($language['language_code'] ?? $language['code'] ?? '');
            if ($code !== '') {
                $lines[] = 'Sitemap: ' . localized_absolute_url('/sitemap.xml', $code, $baseUrl);
            }
        }
        return new Response(200, implode("\n", array_values(array_unique($lines))) . "\n", ['Content-Type' => 'text/plain; charset=utf-8']);
    }


    protected function htmlSitemap(array $site, string $languageCode): Response
    {
        $siteId = (int) $site['id'];
        $baseUrl = (string) ($site['base_url'] ?? '');
        $items = $this->routes->listPublishedRoutes($siteId, $languageCode);
        $seen = [];
        $groups = [];

        foreach ($items as $item) {
            $path = $this->sitemapPath((string) ($item['full_path'] ?? ''));
            if ($path === null) {
                continue;
            }
            $loc = localized_absolute_url($path, $languageCode, $baseUrl);
            $key = strtolower($loc);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $parts = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $part): bool => $part !== ''));
            $group = $parts[0] ?? 'accueil';
            $label = $path === '/' ? 'Accueil' : ucwords(str_replace(['-', '_'], ' ', (string) end($parts)));
            $groups[$group][] = [
                'label' => $label,
                'path' => $path,
                'url' => $loc,
                'lastmod' => $this->sitemapLastModified($item),
            ];
        }

        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
        $count = count($seen);
        $cards = '';

        foreach ($groups as $group => $links) {
            $title = $group === 'accueil' ? 'Pages principales' : ucwords(str_replace(['-', '_'], ' ', (string) $group));
            $cards .= '<section class="sitemap-card"><div class="sitemap-card__header"><h2>' . $this->html($title) . '</h2><span>' . count($links) . ' URL</span></div><ul class="sitemap-links">';
            foreach ($links as $link) {
                $cards .= '<li><a href="' . $this->html($link['url']) . '"><strong>' . $this->html($link['label']) . '</strong><small>' . $this->html($link['path']) . '</small></a>';
                if ($link['lastmod'] !== null) {
                    $cards .= '<time datetime="' . $this->html($link['lastmod']) . '">Maj. ' . $this->html($link['lastmod']) . '</time>';
                }
                $cards .= '</li>';
            }
            $cards .= '</ul></section>';
        }

        if ($cards === '') {
            $cards = '<section class="sitemap-card"><h2>Aucune URL indexable</h2><p>Le sitemap public ne contient actuellement aucune page publiée et indexable.</p></section>';
        }

        $xmlUrl = localized_absolute_url('/sitemap.xml', $languageCode, $baseUrl);
        $homeUrl = localized_absolute_url('/', $languageCode, $baseUrl);
        $html = '<!doctype html><html lang="' . $this->html($languageCode) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Plan du site</title><meta name="robots" content="noindex,follow">'
            . '<style>:root{color-scheme:light dark;--bg:#f6f7fb;--panel:#fff;--text:#17202a;--muted:#637083;--line:#dce3ec;--accent:#2457d6;--soft:#edf3ff}@media(prefers-color-scheme:dark){:root{--bg:#10151d;--panel:#161e28;--text:#edf3f8;--muted:#9fb0c1;--line:#2d3845;--accent:#91b2ff;--soft:#1e2a42}}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:16px/1.55 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}main{max-width:1120px;margin:auto;padding:34px 18px 56px}.sitemap-hero{display:grid;gap:14px;margin-bottom:24px}.sitemap-eyebrow{display:inline-flex;width:max-content;padding:6px 10px;border-radius:999px;background:var(--soft);font-weight:700;color:var(--accent)}h1{margin:0;font-size:clamp(2rem,4vw,3.4rem);line-height:1.05}p{margin:0;color:var(--muted)}.sitemap-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:8px}.sitemap-actions a{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border:1px solid var(--line);border-radius:999px;background:var(--panel);color:var(--text);text-decoration:none;font-weight:700}.sitemap-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}.sitemap-card{background:var(--panel);border:1px solid var(--line);border-radius:22px;box-shadow:0 12px 30px rgba(0,0,0,.06);overflow:hidden}.sitemap-card__header{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:18px 18px 10px}.sitemap-card h2{margin:0;font-size:1.05rem}.sitemap-card__header span{color:var(--muted);font-size:.9rem}.sitemap-links{list-style:none;margin:0;padding:0}.sitemap-links li{display:grid;gap:5px;padding:14px 18px;border-top:1px solid var(--line)}.sitemap-links a{color:var(--accent);text-decoration:none}.sitemap-links a:hover{text-decoration:underline;text-underline-offset:.18em}.sitemap-links strong,.sitemap-links small{display:block}.sitemap-links small,time{color:var(--muted);font-size:.87rem;overflow-wrap:anywhere}@media(max-width:640px){main{padding:24px 12px 40px}.sitemap-card{border-radius:16px}}</style>'
            . '</head><body><main><header class="sitemap-hero"><span class="sitemap-eyebrow">Plan du site</span><h1>Explorer les contenus publiés</h1><p>Cette page est pensée pour les utilisateurs. Le sitemap XML reste disponible séparément pour les moteurs de recherche.</p><div class="sitemap-actions"><a href="' . $this->html($homeUrl) . '">Retour à l’accueil</a><a href="' . $this->html($xmlUrl) . '">' . $count . ' URL dans le sitemap XML</a></div></header><div class="sitemap-grid">'
            . $cards
            . '</div></main></body></html>';

        return Response::html($html, 200, [
            'Cache-Control' => 'public, max-age=300, stale-while-revalidate=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    protected function sitemap(array $site, string $languageCode): Response
    {
        $siteId = (int) $site['id'];
        $baseUrl = (string) ($site['base_url'] ?? '');
        $defaultLanguageCode = strtolower((string) ($site['default_language_code'] ?? $languageCode));
        $items = $this->routes->listPublishedRoutes($siteId, $languageCode);

        $seenLocations = [];
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<?xml-stylesheet type=\"text/xsl\" href=\"sitemap.xsl\"?>\n";
        $xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\" xmlns:xhtml=\"http://www.w3.org/1999/xhtml\">\n";

        foreach ($items as $item) {
            $path = $this->sitemapPath((string) ($item['full_path'] ?? ''));
            if ($path === null) {
                continue;
            }

            $loc = localized_absolute_url($path, $languageCode, $baseUrl);
            $locKey = strtolower($loc);
            if (isset($seenLocations[$locKey])) {
                continue;
            }
            $seenLocations[$locKey] = true;

            $lastmod = $this->sitemapLastModified($item);
            $alternates = $this->sitemapAlternates(
                $siteId,
                (string) ($item['resource_type'] ?? ''),
                (int) ($item['resource_id'] ?? 0),
                $baseUrl,
                $defaultLanguageCode
            );

            $xml .= "  <url>\n";
            $xml .= '    <loc>' . $this->xml($loc) . "</loc>\n";
            if ($lastmod !== null) {
                $xml .= '    <lastmod>' . $this->xml($lastmod) . "</lastmod>\n";
            }
            foreach ($alternates as $alternate) {
                $xml .= '    <xhtml:link rel="alternate" hreflang="' . $this->xml($alternate['hreflang']) . '" href="' . $this->xml($alternate['href']) . "\" />\n";
            }
            $xml .= "  </url>\n";
        }

        $xml .= "</urlset>\n";
        return new Response(200, $xml, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Cache-Control' => 'no-cache, must-revalidate, max-age=0',
            'X-Content-Source' => 'public_routes',
        ]);
    }


    private function sitemapStylesheet(): Response
    {
        $xsl = <<<'XSL'
<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns:sitemap="http://www.sitemaps.org/schemas/sitemap/0.9"
    xmlns:xhtml="http://www.w3.org/1999/xhtml">
    <xsl:output method="html" encoding="UTF-8" indent="yes" />
    <xsl:template match="/">
        <html lang="fr">
            <head>
                <meta charset="UTF-8" />
                <meta name="viewport" content="width=device-width, initial-scale=1" />
                <title>Sitemap XML</title>
                <style>
                    :root { color-scheme: light dark; --bg: #f6f7f9; --panel: #ffffff; --text: #17202a; --muted: #5f6f7f; --line: #dce3ea; --accent: #2357d9; --soft: #eef3ff; }
                    @media (prefers-color-scheme: dark) { :root { --bg: #0f141b; --panel: #151c24; --text: #eef3f8; --muted: #9fb0c1; --line: #2b3642; --accent: #8fb0ff; --soft: #1d2a42; } }
                    * { box-sizing: border-box; }
                    body { margin: 0; background: var(--bg); color: var(--text); font: 16px/1.5 system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
                    main { max-width: 1180px; margin: 0 auto; padding: 32px 18px 48px; }
                    header { margin-bottom: 22px; }
                    h1 { margin: 0 0 8px; font-size: clamp(1.8rem, 3vw, 2.6rem); line-height: 1.1; }
                    p { margin: 0; color: var(--muted); }
                    .card { background: var(--panel); border: 1px solid var(--line); border-radius: 18px; box-shadow: 0 12px 34px rgba(0, 0, 0, .07); overflow: hidden; }
                    .summary { display: flex; flex-wrap: wrap; gap: 10px; margin: 20px 0; }
                    .pill { display: inline-flex; align-items: center; gap: 8px; padding: 8px 12px; border-radius: 999px; background: var(--soft); color: var(--text); font-weight: 650; }
                    .table-wrap { overflow-x: auto; }
                    table { width: 100%; min-width: 760px; border-collapse: collapse; }
                    th, td { padding: 14px 16px; text-align: left; vertical-align: top; border-bottom: 1px solid var(--line); }
                    th { font-size: .78rem; letter-spacing: .04em; text-transform: uppercase; color: var(--muted); background: color-mix(in srgb, var(--panel) 84%, var(--bg)); }
                    tr:last-child td { border-bottom: 0; }
                    a { color: var(--accent); text-decoration-thickness: .08em; text-underline-offset: .18em; overflow-wrap: anywhere; }
                    .loc { font-weight: 650; }
                    .muted { color: var(--muted); }
                    .alts { display: flex; flex-wrap: wrap; gap: 6px; }
                    .alt { display: inline-flex; align-items: center; padding: 3px 8px; border: 1px solid var(--line); border-radius: 999px; font-size: .86rem; color: var(--muted); }
                    @media (max-width: 720px) { main { padding: 24px 12px 36px; } .card { border-radius: 14px; } th, td { padding: 12px; } }
                </style>
            </head>
            <body>
                <main>
                    <header>
                        <h1>Sitemap XML</h1>
                        <p>Ce fichier reste un sitemap XML standard pour les moteurs de recherche. Cette présentation est uniquement ajoutée pour faciliter la lecture humaine dans le navigateur.</p>
                    </header>
                    <div class="summary" aria-label="Résumé du sitemap">
                        <span class="pill"><xsl:value-of select="count(sitemap:urlset/sitemap:url)" /> URL indexables</span>
                        <span class="pill">XML + XSL lisible</span>
                    </div>
                    <section class="card" aria-label="URLs du sitemap">
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>URL</th>
                                        <th>Dernière modification</th>
                                        <th>Langues alternatives</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <xsl:for-each select="sitemap:urlset/sitemap:url">
                                        <tr>
                                            <td class="loc"><a href="{sitemap:loc}"><xsl:value-of select="sitemap:loc" /></a></td>
                                            <td>
                                                <xsl:choose>
                                                    <xsl:when test="sitemap:lastmod"><xsl:value-of select="sitemap:lastmod" /></xsl:when>
                                                    <xsl:otherwise><span class="muted">Non renseigné</span></xsl:otherwise>
                                                </xsl:choose>
                                            </td>
                                            <td>
                                                <xsl:choose>
                                                    <xsl:when test="xhtml:link[@rel='alternate']">
                                                        <span class="alts">
                                                            <xsl:for-each select="xhtml:link[@rel='alternate']">
                                                                <a class="alt" href="{@href}"><xsl:value-of select="@hreflang" /></a>
                                                            </xsl:for-each>
                                                        </span>
                                                    </xsl:when>
                                                    <xsl:otherwise><span class="muted">Aucune</span></xsl:otherwise>
                                                </xsl:choose>
                                            </td>
                                        </tr>
                                    </xsl:for-each>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </main>
            </body>
        </html>
    </xsl:template>
</xsl:stylesheet>
XSL;

        return new Response(200, $xsl, [
            'Content-Type' => 'application/xslt+xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=86400, stale-while-revalidate=604800',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function sitemapPath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }
        $path = '/' . ltrim($path, '/');
        $path = (string) preg_replace('#/+#', '/', $path);
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }
        if (preg_match('#^/(admin|api|preview|search)(/|$)#', $path)) {
            return null;
        }
        return $path;
    }

    /** @param array<string,mixed> $item */
    private function sitemapLastModified(array $item): ?string
    {
        foreach (['published_at', 'updated_at', 'route_updated_at'] as $field) {
            $value = trim((string) ($item[$field] ?? ''));
            if ($value === '') {
                continue;
            }
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return gmdate('Y-m-d', $timestamp);
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return $value;
            }
        }
        return null;
    }

    /**
     * @return list<array{hreflang:string,href:string}>
     */
    private function sitemapAlternates(int $siteId, string $resourceType, int $resourceId, string $baseUrl, string $defaultLanguageCode): array
    {
        if ($resourceType === '' || $resourceId <= 0) {
            return [];
        }

        $alternates = [];
        $seen = [];
        $defaultHref = null;

        foreach ($this->routes->listPublishedRouteAlternates($siteId, $resourceType, $resourceId) as $alternate) {
            $languageCode = strtolower((string) ($alternate['language_code'] ?? ''));
            $path = $this->sitemapPath((string) ($alternate['full_path'] ?? ''));
            if ($languageCode === '' || $path === null) {
                continue;
            }
            $hreflang = strtolower(trim((string) (($alternate['hreflang_code'] ?? '') ?: $languageCode)));
            $href = localized_absolute_url($path, $languageCode, $baseUrl);
            $key = $hreflang . '|' . strtolower($href);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $alternates[] = ['hreflang' => $hreflang, 'href' => $href];

            if (!empty($alternate['is_default']) || $languageCode === $defaultLanguageCode) {
                $defaultHref = $href;
            }
        }

        if ($defaultHref !== null) {
            $alternates[] = ['hreflang' => 'x-default', 'href' => $defaultHref];
        }

        usort($alternates, static function (array $a, array $b): int {
            if ($a['hreflang'] === 'x-default') {
                return 1;
            }
            if ($b['hreflang'] === 'x-default') {
                return -1;
            }
            return $a['hreflang'] <=> $b['hreflang'];
        });

        return $alternates;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
