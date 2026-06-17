<?php

declare(strict_types=1);

namespace App\StaticExport;

use App\Application\Frontend\ResolvePublicRoute;
use App\Core\Renderer;
use App\Core\Response;
use App\Repository\SiteRepository;

final class StaticExportRenderer
{
    public function __construct(
        private readonly array $config,
        private readonly ResolvePublicRoute $resolver,
        private readonly SiteRepository $sites,
    ) {}

    /** @param array<string,mixed> $site */
    public function renderRoute(StaticExportRoute $route, array $site): Response
    {
        $this->registerRuntimeContext($route, $site);
        $result = $this->resolver->execute($site, $route->languageCode, $route->path);
        if (($result['type'] ?? '') === 'redirect') {
            return Response::html('', (int) ($result['status'] ?? 301), ['Location' => (string) ($result['to'] ?? '/')]);
        }
        $payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];
        $status = (int) ($result['status'] ?? 200);
        if ($payload === []) {
            return Response::html('<h1>404</h1><p>Page introuvable.</p>', 404);
        }
        return $this->renderPayload((string) ($payload['template'] ?? 'page'), $payload, $status, (int) $site['id']);
    }

    /** @param array<string,mixed> $site */
    public function renderSitemapXml(array $routes, array $site, ?string $languageCode = null): string
    {
        $baseUrl = rtrim((string) ($site['base_url'] ?? ''), '/');
        $items = [];
        foreach ($routes as $route) {
            if (!$route instanceof StaticExportRoute || !$route->isIndexable || $route->statusCode !== 200) {
                continue;
            }
            if ($languageCode !== null && $route->languageCode !== $languageCode) {
                continue;
            }
            $publicPath = SqlStaticExportSource::localizedPublicPath($route->path, (string) ($route->metadata['url_prefix'] ?? ''));
            $loc = $baseUrl !== '' ? $baseUrl . $publicPath : $publicPath;
            $items[] = '  <url><loc>' . htmlspecialchars($loc, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</loc></url>';
        }
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n" . implode("\n", $items) . "\n</urlset>\n";
    }

    public function renderRobotsTxt(): string
    {
        return "User-agent: *\nAllow: /\nSitemap: /sitemap.xml\n";
    }

    /** @param array<string,mixed> $site */
    private function registerRuntimeContext(StaticExportRoute $route, array $site): void
    {
        $_SERVER['CMS_SITE_ID'] = (string) $route->siteId;
        $_SERVER['CMS_SITE_BASE_PATH'] = (string) ($site['matched_base_path'] ?? '');
        $_SERVER['CMS_SITE_CANONICAL_BASE_URL'] = (string) ($site['base_url'] ?? '');
        $_SERVER['HTTP_HOST'] = parse_url((string) ($site['base_url'] ?? ''), PHP_URL_HOST) ?: 'localhost';
        if (function_exists('register_localized_path_prefixes')) {
            register_localized_path_prefixes($this->sites->getLanguages($route->siteId));
        }
    }

    /** @param array<string,mixed> $payload */
    private function renderPayload(string $template, array $payload, int $status, int $siteId): Response
    {
        [$themeKey, $theme] = $this->activeTheme($siteId);
        $themeBase = (string) ($theme['templates_path'] ?? $theme['path'] ?? $this->config['themes']['default']['templates_path'] ?? base_path('frontend/theme-default/templates'));
        $renderer = new Renderer($themeBase, ['app_name' => $this->config['app']['name'] ?? 'CMS'], [
            'cache' => false,
            'cache_path' => (string) ($this->config['app']['twig_cache'] ?? ''),
            'debug' => (bool) ($this->config['app']['debug'] ?? false),
        ]);
        $themeData = $payload + [
            'active_theme' => [
                'key' => $themeKey,
                'name' => (string) ($theme['name'] ?? $themeKey),
                'assets_url' => (string) ($theme['assets_url'] ?? ''),
            ],
            'theme_preview' => false,
        ];
        $content = $renderer->render(str_replace(['.php', '.twig'], '', $template), $themeData);
        $html = $renderer->render('layout', $themeData + ['content' => $content]);
        return Response::html($this->cleanHtml($html), $status);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function activeTheme(int $siteId): array
    {
        $themes = (array) ($this->config['themes'] ?? []);
        $fallbackKey = isset($themes['default']) ? 'default' : (array_key_first($themes) ?: 'default');
        $configuredKey = $fallbackKey;
        $settings = method_exists($this->sites, 'publicUiSettings') ? $this->sites->publicUiSettings($siteId) : [];
        $candidate = trim((string) ($settings['active_theme_key'] ?? ''));
        if ($candidate !== '' && isset($themes[$candidate])) {
            $configuredKey = $candidate;
        }
        return [$configuredKey, (array) ($themes[$configuredKey] ?? $themes[$fallbackKey] ?? [])];
    }

    private function cleanHtml(string $html): string
    {
        return preg_replace('/\n{3,}/', "\n\n", trim($html)) . "\n";
    }

    private function localizedSitePath(string $path, string $languageCode): string
    {
        if (function_exists('localized_site_path')) {
            return localized_site_path($path, $languageCode);
        }
        return $path;
    }
}
