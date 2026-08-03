<?php

declare(strict_types=1);

namespace App\Repository;

use App\Application\Frontend\SiteReadRepository;
use App\Core\Database;
use App\Core\Request;

final class SiteRepository implements SiteReadRepository
{
    public function __construct(private readonly Database $db, private readonly array $config) {}

    public function withResolvedSiteContext(Request $request): Request
    {
        $host = (string) ($request->server['HTTP_HOST'] ?? '');
        $isHttps = $this->isHttps($request->server);
        $resolved = $this->resolveCurrentSite($host, $request->path, $isHttps);

        $path = $request->path;
        $basePath = (string) ($resolved['matched_request_base_path'] ?? $resolved['matched_base_path'] ?? '');
        if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
            $path = substr($path, strlen($basePath)) ?: '/';
        }

        $languages = $this->getLanguages((int) $resolved['id']);
        register_localized_path_prefixes($languages);

        $query = $request->query;
        if ($prefixMatch = $this->matchLanguageByUrlPrefix($path, $languages)) {
            $path = $prefixMatch['path'];
            $query['lang'] = (string) ($query['lang'] ?? $prefixMatch['language_code']);
        }

        $server = $request->server;
        $server['CMS_SITE_ID'] = (string) $resolved['id'];
        $server['CMS_SITE_KEY'] = (string) $resolved['site_key'];
        $server['CMS_SITE_BASE_PATH'] = $basePath;
        $server['CMS_SITE_DOMAIN_BASE_PATH'] = (string) ($resolved['matched_base_path'] ?? '');
        $server['CMS_SITE_CANONICAL_BASE_URL'] = (string) ($resolved['base_url'] ?? '');
        $server['CMS_SITE_CURRENT_BASE_URL'] = (string) ($resolved['current_base_url'] ?? $resolved['base_url'] ?? '');
        $server['CMS_SITE_IS_CANONICAL_DOMAIN'] = !empty($resolved['is_canonical_domain']) ? '1' : '0';
        $server['CMS_SITE_SHOULD_REDIRECT_HTTPS'] = !empty($resolved['should_redirect_https']) ? '1' : '0';

        return $request->withPathQueryServer($path, $query, $server);
    }

    public function resolveCurrentSite(string $host = '', string $path = '', ?bool $isHttps = null): array
    {
        $contextSiteId = (int) ($_SERVER['CMS_SITE_ID'] ?? 0);
        if ($contextSiteId > 0 && $path === '') {
            $site = $this->siteById($contextSiteId);
            if ($site) {
                return $site + [
                    'base_url' => (string) ($_SERVER['CMS_SITE_CANONICAL_BASE_URL'] ?? ''),
                    'current_base_url' => (string) ($_SERVER['CMS_SITE_CURRENT_BASE_URL'] ?? ''),
                    'matched_base_path' => (string) ($_SERVER['CMS_SITE_DOMAIN_BASE_PATH'] ?? $_SERVER['CMS_SITE_BASE_PATH'] ?? ''),
                    'matched_request_base_path' => (string) ($_SERVER['CMS_SITE_BASE_PATH'] ?? ''),
                    'is_canonical_domain' => (string) ($_SERVER['CMS_SITE_IS_CANONICAL_DOMAIN'] ?? '') === '1',
                    'should_redirect_https' => (string) ($_SERVER['CMS_SITE_SHOULD_REDIRECT_HTTPS'] ?? '') === '1',
                ];
            }
        }

        $requestedHost = trim($host !== '' ? $host : (string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = self::normalizeHost($requestedHost);
        $path = $path !== '' ? $path : (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        $isHttps = $isHttps ?? $this->isHttps($_SERVER);

        $sites = $this->db->all('SELECT * FROM sites WHERE is_active = 1 ORDER BY id');
        if (!$sites) {
            throw new \RuntimeException('Aucun site configuré. Lancez le seed.');
        }

        $domains = $this->db->all(
            'SELECT sd.*, s.site_key, s.name, s.default_language_code, s.is_active AS site_is_active
             FROM site_domains sd
             JOIN sites s ON s.id = sd.site_id
             WHERE sd.is_active = 1 AND s.is_active = 1
             ORDER BY length(sd.base_path) DESC, sd.is_primary DESC, sd.id ASC'
        );

        $matchedDomain = null;
        foreach ($domains as $domain) {
            if (strcasecmp($host, self::normalizeHost((string) $domain['host'])) !== 0) {
                continue;
            }
            $basePath = self::effectiveRequestBasePath((string) ($domain['base_path'] ?? ''));
            if ($basePath === '' || $path === $basePath || str_starts_with($path, $basePath . '/')) {
                $matchedDomain = $domain;
                break;
            }
        }

        if ($matchedDomain) {
            return $this->hydrateSiteContext($matchedDomain, $matchedDomain, $isHttps);
        }

        $wanted = $this->config['cms']['default_site_key'] ?? 'main';
        $fallback = null;
        foreach ($sites as $site) {
            if ($site['site_key'] === $wanted) {
                $fallback = $site;
                break;
            }
        }
        $fallback ??= $sites[0];

        if ($this->allowsLocalHost($host)) {
            $localDomain = null;
            foreach ($domains as $domain) {
                $basePath = self::effectiveRequestBasePath((string) ($domain['base_path'] ?? ''));
                if ($basePath === '' || $path === $basePath || str_starts_with($path, $basePath . '/')) {
                    $localDomain = $domain;
                    break;
                }
            }
            return $this->hydrateLocalSiteContext($localDomain ?? $fallback, $localDomain, $requestedHost, $isHttps);
        }

        $primaryDomain = $this->primaryDomainForSite((int) $fallback['id']);
        return $this->hydrateSiteContext($fallback, $primaryDomain, $isHttps);
    }

    public function findActiveSite(int $siteId): ?array
    {
        $site = $this->siteById($siteId);
        if (!$site) {
            return null;
        }
        return $this->hydrateSiteContext($site, $this->primaryDomainForSite($siteId), $this->isHttps($_SERVER));
    }

    public function getLocalization(int $siteId, string $languageCode): ?array
    {
        return $this->db->one('SELECT * FROM site_localizations WHERE site_id = :site_id AND language_code = :language_code', [
            'site_id' => $siteId,
            'language_code' => $languageCode,
        ]);
    }

    public function getLanguages(?int $siteId = null): array
    {
        if ($siteId !== null && $this->tableExists('site_languages')) {
            $rows = $this->db->all(
                'SELECT l.*, sl.is_default AS site_is_default, sl.is_active AS site_is_active,
                        sl.fallback_language_code, sl.url_prefix, sl.hreflang_code, sl.is_rtl, sl.sort_order AS site_sort_order
                 FROM site_languages sl
                 JOIN languages l ON l.code = sl.language_code
                 WHERE sl.site_id = :site_id AND sl.is_active = 1 AND l.is_active = 1
                 ORDER BY sl.sort_order, l.code',
                ['site_id' => $siteId]
            );
            if ($rows !== []) {
                return $rows;
            }
        }

        return $this->db->all('SELECT * FROM languages WHERE is_active = 1 ORDER BY sort_order, code');
    }

    public function isLanguageEnabled(int $siteId, string $languageCode): bool
    {
        foreach ($this->getLanguages($siteId) as $language) {
            $code = strtolower((string) ($language['language_code'] ?? $language['code'] ?? ''));
            if ($code === strtolower(trim($languageCode))) {
                return true;
            }
        }
        return false;
    }

    public function defaultLanguageCode(): string
    {
        $row = $this->db->one('SELECT code FROM languages WHERE is_default = 1 LIMIT 1');
        return $row['code'] ?? ($this->config['app']['default_locale'] ?? 'fr');
    }

    /** @return array<string,mixed> */
    public function publicUiSettings(int $siteId): array
    {
        if (!$this->tableExists('site_settings')) {
            return [];
        }
        $row = $this->db->one(
            "SELECT value_json FROM site_settings WHERE site_id = :site_id AND namespace = 'public_ui' AND setting_key = 'defaults' LIMIT 1",
            ['site_id' => $siteId]
        );
        if (!$row) {
            return [];
        }
        $settings = json_decode((string) ($row['value_json'] ?? ''), true);
        return is_array($settings) ? $settings : [];
    }

    public function menuItems(int $siteId, string $menuKey, string $languageCode): array
    {
        $defaultLanguage = (string) ($this->db->one('SELECT language_code FROM site_languages WHERE site_id = :site_id AND is_default = 1 LIMIT 1', ['site_id' => $siteId])['language_code'] ?? 'fr');
        $menu = $this->db->one(
            'SELECT * FROM menus
             WHERE site_id = :site_id
               AND is_active = 1
               AND (menu_key = :menu_key OR menu_location = :menu_key)
             ORDER BY CASE WHEN menu_key = :menu_key THEN 0 ELSE 1 END, id
             LIMIT 1',
            ['site_id' => $siteId, 'menu_key' => $menuKey]
        );
        if (!$menu) {
            return [];
        }
        $mode = (string) ($menu['language_mode'] ?? 'shared');
        $where = $mode === 'localized'
            ? 'm.id = :menu_id AND m.is_active = 1 AND mi.language_code = :scope_language'
            : 'm.id = :menu_id AND m.is_active = 1 AND mi.language_code IS NULL';
        $params = ['menu_id' => (int) $menu['id'], 'language' => $languageCode, 'default_language' => $defaultLanguage];
        if ($mode === 'localized') {
            $params['scope_language'] = $languageCode;
        }
        $rows = $this->db->all(
            "SELECT mi.*, COALESCE(current_loc.label, default_loc.label) AS label, COALESCE(current_loc.title_attr, default_loc.title_attr) AS title_attr,
                    COALESCE(entry_route.full_path, term_loc.full_path, term_fallback_loc.full_path) AS target_path
             FROM menus m
             JOIN menu_items mi ON mi.menu_id = m.id AND mi.is_active = 1
             LEFT JOIN menu_item_localizations current_loc ON current_loc.menu_item_id = mi.id AND current_loc.language_code = :language
             LEFT JOIN menu_item_localizations default_loc ON default_loc.menu_item_id = mi.id AND default_loc.language_code = :default_language
             LEFT JOIN content_entries menu_entry ON menu_entry.id = mi.resource_id
                AND mi.resource_type = 'content_entry'
                AND menu_entry.site_id = m.site_id
                AND menu_entry.is_active = 1
             LEFT JOIN content_entries localized_entry ON localized_entry.site_id = m.site_id
                AND localized_entry.entry_key = menu_entry.entry_key
                AND localized_entry.is_active = 1
                AND localized_entry.status = 'published'
             LEFT JOIN routes entry_route ON entry_route.site_id = m.site_id
                AND mi.resource_type = 'content_entry'
                AND entry_route.resource_type = 'content_entry'
                AND entry_route.resource_id = localized_entry.id
                AND entry_route.language_code = :language
                AND entry_route.is_primary = 1
                AND entry_route.is_canonical = 1
                AND entry_route.status = 'active'
             LEFT JOIN taxonomy_terms tt ON ((mi.term_id IS NOT NULL AND tt.id = mi.term_id) OR (mi.resource_type = 'taxonomy_term' AND tt.id = mi.resource_id))
             LEFT JOIN taxonomies tx ON tx.id = tt.taxonomy_id AND tx.site_id = m.site_id
             LEFT JOIN taxonomy_term_localizations term_loc ON term_loc.site_id = tx.site_id AND term_loc.taxonomy_id = tx.id AND term_loc.term_id = tt.id AND term_loc.language_code = :language
             LEFT JOIN taxonomy_term_localizations term_fallback_loc ON term_fallback_loc.site_id = tx.site_id AND term_fallback_loc.taxonomy_id = tx.id AND term_fallback_loc.term_id = tt.id AND term_fallback_loc.language_code = :default_language
             WHERE {$where}
             ORDER BY COALESCE(mi.parent_id, 0), mi.sort_order, mi.id",
            $params
        );
        if ($mode === 'localized' && $rows === [] && $languageCode !== $defaultLanguage) {
            return $this->db->all(
                "SELECT mi.*, COALESCE(current_loc.label, default_loc.label) AS label, COALESCE(current_loc.title_attr, default_loc.title_attr) AS title_attr,
                        COALESCE(entry_route.full_path, term_loc.full_path, term_fallback_loc.full_path) AS target_path
                 FROM menus m
                 JOIN menu_items mi ON mi.menu_id = m.id AND mi.is_active = 1 AND mi.language_code IS NULL
                 LEFT JOIN menu_item_localizations current_loc ON current_loc.menu_item_id = mi.id AND current_loc.language_code = :language
                 LEFT JOIN menu_item_localizations default_loc ON default_loc.menu_item_id = mi.id AND default_loc.language_code = :default_language
                 LEFT JOIN content_entries menu_entry ON menu_entry.id = mi.resource_id
                    AND mi.resource_type = 'content_entry'
                    AND menu_entry.site_id = m.site_id
                    AND menu_entry.is_active = 1
                 LEFT JOIN content_entries localized_entry ON localized_entry.site_id = m.site_id
                    AND localized_entry.entry_key = menu_entry.entry_key
                    AND localized_entry.is_active = 1
                    AND localized_entry.status = 'published'
                 LEFT JOIN routes entry_route ON entry_route.site_id = m.site_id
                    AND mi.resource_type = 'content_entry'
                    AND entry_route.resource_type = 'content_entry'
                    AND entry_route.resource_id = localized_entry.id
                    AND entry_route.language_code = :language
                    AND entry_route.is_primary = 1
                    AND entry_route.is_canonical = 1
                    AND entry_route.status = 'active'
                 LEFT JOIN taxonomy_terms tt ON ((mi.term_id IS NOT NULL AND tt.id = mi.term_id) OR (mi.resource_type = 'taxonomy_term' AND tt.id = mi.resource_id))
                 LEFT JOIN taxonomies tx ON tx.id = tt.taxonomy_id AND tx.site_id = m.site_id
                 LEFT JOIN taxonomy_term_localizations term_loc ON term_loc.site_id = tx.site_id AND term_loc.taxonomy_id = tx.id AND term_loc.term_id = tt.id AND term_loc.language_code = :language
                 LEFT JOIN taxonomy_term_localizations term_fallback_loc ON term_fallback_loc.site_id = tx.site_id AND term_fallback_loc.taxonomy_id = tx.id AND term_fallback_loc.term_id = tt.id AND term_fallback_loc.language_code = :default_language
                 WHERE m.id = :menu_id AND m.is_active = 1
                 ORDER BY COALESCE(mi.parent_id, 0), mi.sort_order, mi.id",
                ['menu_id' => (int) $menu['id'], 'language' => $languageCode, 'default_language' => $defaultLanguage]
            );
        }
        return $rows;
    }


    /**
     * @param list<array<string,mixed>> $languages
     * @return array{language_code:string,path:string}|null
     */
    private function matchLanguageByUrlPrefix(string $path, array $languages): ?array
    {
        $candidates = [];
        foreach ($languages as $language) {
            $prefix = trim((string) ($language['url_prefix'] ?? ''));
            if ($prefix === '') {
                continue;
            }
            $prefix = '/' . trim($prefix, '/');
            $candidates[] = [
                'language_code' => strtolower((string) ($language['language_code'] ?? $language['code'] ?? '')),
                'prefix' => $prefix,
            ];
        }

        usort($candidates, fn(array $a, array $b): int => strlen((string) $b['prefix']) <=> strlen((string) $a['prefix']));

        foreach ($candidates as $candidate) {
            $prefix = (string) $candidate['prefix'];
            if ($path !== $prefix && !str_starts_with($path, $prefix . '/')) {
                continue;
            }

            $remaining = $path === $prefix ? '/' : substr($path, strlen($prefix));
            return [
                'language_code' => (string) $candidate['language_code'],
                'path' => $remaining === '' ? '/' : $remaining,
            ];
        }

        return null;
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->db->one("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1", ['table' => $table]);
    }

    private function siteById(int $siteId): ?array
    {
        return $this->db->one('SELECT * FROM sites WHERE id = :id AND is_active = 1', ['id' => $siteId]);
    }

    private function primaryDomainForSite(int $siteId): ?array
    {
        return $this->db->one(
            'SELECT * FROM site_domains WHERE site_id = :site_id AND is_active = 1 ORDER BY is_primary DESC, length(base_path) ASC, id ASC LIMIT 1',
            ['site_id' => $siteId]
        );
    }

    private function hydrateSiteContext(array $siteOrDomain, ?array $domain, bool $isHttps): array
    {
        $siteId = (int) ($siteOrDomain['site_id'] ?? $siteOrDomain['id']);
        $site = isset($siteOrDomain['site_key']) && isset($siteOrDomain['site_id']) ? $this->siteById($siteId) : $siteOrDomain;
        if (!$site) {
            throw new \RuntimeException('Site introuvable.');
        }

        $primary = $this->primaryDomainForSite($siteId) ?? $domain;
        $canonicalBase = $primary ? self::domainBaseUrl($primary) : '';
        $currentBase = $domain ? self::domainBaseUrl($domain) : $canonicalBase;

        return $site + [
            'base_url' => $canonicalBase,
            'canonical_base_url' => $canonicalBase,
            'current_base_url' => $currentBase,
            'matched_domain_id' => $domain['id'] ?? null,
            'matched_host' => $domain['host'] ?? null,
            'matched_base_path' => $domain ? self::publicBasePath((string) ($domain['base_path'] ?? '')) : '',
            'matched_request_base_path' => $domain ? self::effectiveRequestBasePath((string) ($domain['base_path'] ?? '')) : '',
            'matched_scheme' => $domain['scheme'] ?? null,
            'is_canonical_domain' => $domain && $primary && (int) $domain['id'] === (int) $primary['id'],
            'should_redirect_https' => $domain && (int) ($domain['enforce_https'] ?? 0) === 1 && !$isHttps,
        ];
    }

    private function hydrateLocalSiteContext(array $siteOrDomain, ?array $domain, string $requestedHost, bool $isHttps): array
    {
        $siteId = (int) ($siteOrDomain['site_id'] ?? $siteOrDomain['id']);
        $site = isset($siteOrDomain['site_key']) && isset($siteOrDomain['site_id']) ? $this->siteById($siteId) : $siteOrDomain;
        if (!$site) {
            throw new \RuntimeException('Site local introuvable.');
        }

        $authority = self::localAuthority($requestedHost);
        $publicBasePath = self::publicBasePath((string) ($domain['base_path'] ?? ''));
        $requestBasePath = self::effectiveRequestBasePath((string) ($domain['base_path'] ?? ''));
        $baseUrl = rtrim(($isHttps ? 'https' : 'http') . '://' . $authority . $publicBasePath, '/');

        return $site + [
            'base_url' => $baseUrl,
            'canonical_base_url' => $baseUrl,
            'current_base_url' => $baseUrl,
            'matched_domain_id' => null,
            'matched_host' => $authority,
            'matched_base_path' => $publicBasePath,
            'matched_request_base_path' => $requestBasePath,
            'matched_scheme' => $isHttps ? 'https' : 'http',
            'is_canonical_domain' => true,
            'should_redirect_https' => false,
        ];
    }

    private function allowsLocalHost(string $host): bool
    {
        if (empty($this->config['app']['allow_local_hosts'])) {
            return false;
        }
        return $host === 'localhost' || $host === '127.0.0.1' || $host === '::1';
    }

    private static function localAuthority(string $host): string
    {
        $host = strtolower(trim($host));
        if (preg_match('/^(?:localhost|127[.]0[.]0[.]1)(?::[0-9]{1,5})?$/', $host)) {
            return $host;
        }
        if (preg_match('/^\[::1\](?::[0-9]{1,5})?$/', $host)) {
            return $host;
        }
        return self::normalizeHost($host);
    }

    private static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        if (str_contains($host, ':')) {
            $host = explode(':', $host, 2)[0];
        }
        return $host;
    }

    private static function normalizeBasePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $path === '/') {
            return '';
        }
        return '/' . trim($path, '/');
    }

    /**
     * Request::capture() already strips APP_BASE_PATH (for example /cms).
     * site_domains.base_path, however, is the canonical/public deployment path
     * and may legitimately contain that prefix (for example /cms/site-a).
     * Matching must therefore compare against the request-visible part only.
     */
    private static function effectiveRequestBasePath(string $domainBasePath): string
    {
        $basePath = self::normalizeBasePath($domainBasePath);
        $appBasePath = self::normalizeBasePath(function_exists('app_base_path') ? app_base_path() : '');
        if ($appBasePath !== '' && ($basePath === $appBasePath || str_starts_with($basePath, $appBasePath . '/'))) {
            $basePath = substr($basePath, strlen($appBasePath)) ?: '';
        }
        return self::normalizeBasePath($basePath);
    }

    /**
     * Public URLs must always include APP_BASE_PATH when the CMS is installed
     * below a subdirectory such as /cms. The database may contain either the
     * full public path (/cms/site-a) or the request-visible site path (/site-a);
     * this helper accepts both forms and never double-prefixes the app base.
     */
    private static function publicBasePath(string $domainBasePath): string
    {
        $basePath = self::normalizeBasePath($domainBasePath);
        $appBasePath = self::normalizeBasePath(function_exists('app_base_path') ? app_base_path() : '');
        if ($appBasePath === '') {
            return $basePath;
        }
        if ($basePath === '' || $basePath === '/') {
            return $appBasePath;
        }
        if ($basePath === $appBasePath || str_starts_with($basePath, $appBasePath . '/')) {
            return $basePath;
        }
        return self::normalizeBasePath($appBasePath . '/' . ltrim($basePath, '/'));
    }

    private static function domainBaseUrl(array $domain): string
    {
        $scheme = (string) ($domain['scheme'] ?? 'https');
        $host = self::normalizeHost((string) ($domain['host'] ?? 'localhost'));
        $basePath = self::publicBasePath((string) ($domain['base_path'] ?? ''));
        return rtrim($scheme . '://' . $host . $basePath, '/');
    }

    /** @param array<string,mixed> $server */
    private function isHttps(array $server): bool
    {
        if (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off') {
            return true;
        }
        return strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? $server['REQUEST_SCHEME'] ?? '')) === 'https';
    }
}
