<?php

declare(strict_types=1);

namespace App\StaticExport;

use App\Core\Database;

final class SqlStaticExportSource implements StaticExportSourceContract
{
    public function __construct(private readonly Database $db) {}

    /** @return list<StaticExportRoute> */
    public function listExportableRoutes(?string $siteKey = null, ?string $languageCode = null, ?string $routePath = null): array
    {
        if (!$this->hasTable('routes') || !$this->hasTable('sites') || !$this->hasTable('site_languages')) {
            return [];
        }

        $where = ["r.status = 'active'", 's.is_active = 1', 'sl.is_active = 1'];
        $params = [];
        if ($siteKey !== null && $siteKey !== '') {
            $where[] = 's.site_key = :site_key';
            $params['site_key'] = $siteKey;
        }
        if ($languageCode !== null && $languageCode !== '') {
            $where[] = 'r.language_code = :language_code';
            $params['language_code'] = strtolower($languageCode);
        }
        if ($routePath !== null && $routePath !== '') {
            $where[] = 'r.full_path = :route_path';
            $params['route_path'] = $this->stripKnownLanguagePrefix($this->normalizePath($routePath), $siteKey);
        }

        $sql = "SELECT r.*, s.site_key, sl.url_prefix, sl.hreflang_code, sl.is_default
                FROM routes r
                JOIN sites s ON s.id = r.site_id
                JOIN site_languages sl ON sl.site_id = r.site_id AND sl.language_code = r.language_code
                WHERE " . implode(' AND ', $where) . "
                ORDER BY s.site_key, sl.sort_order, CASE WHEN r.full_path = '/' THEN 0 ELSE 1 END, r.full_path";
        $routes = [];
        foreach ($this->db->all($sql, $params) as $row) {
            $path = $this->normalizePath((string) $row['full_path']);
            if ($this->isInternalPath($path)) {
                continue;
            }
            $routes[] = new StaticExportRoute(
                (int) $row['site_id'],
                (string) $row['site_key'],
                (string) $row['language_code'],
                $path,
                self::htmlOutputPath($path, (string) ($row['url_prefix'] ?? '')),
                (string) $row['route_type'],
                (string) $row['resource_type'],
                (int) $row['resource_id'],
                (int) $row['is_canonical'] === 1,
                true,
                [
                    'route_id' => (int) $row['id'],
                    'slug' => $row['slug'] ?? null,
                    'url_prefix' => $row['url_prefix'] ?? '',
                    'hreflang_code' => $row['hreflang_code'] ?? null,
                    'is_default_language' => (int) ($row['is_default'] ?? 0) === 1,
                    'source_published_revision_id' => $row['source_published_revision_id'] ?? null,
                ],
            );
        }
        return $routes;
    }


    /**
     * Liste légère destinée au back-office pour proposer des pages déjà publiées.
     *
     * Elle reste volontairement basée sur les projections publiques (`routes` +
     * `public_content_snapshots`) et ne lit jamais les brouillons. Le filtre par
     * site_id rend l'interface plus robuste que le seul site_key lorsque le
     * contexte admin est résolu depuis un host local ou un sous-chemin.
     *
     * @return list<array<string,mixed>>
     */
    public function listPublishedRouteSuggestions(?int $siteId = null, ?string $siteKey = null, ?string $languageCode = null, int $limit = 80): array
    {
        if (!$this->hasTable('routes') || !$this->hasTable('sites')) {
            return $this->listPublishedContentFallbackSuggestions($siteId, $siteKey, $languageCode, $limit);
        }

        $languageCode = $this->normalizeLanguageCode($languageCode);
        $where = ["r.status = 'active'", 's.is_active = 1', 'r.is_canonical = 1'];
        $params = [];
        if ($siteId !== null && $siteId > 0) {
            $where[] = 'r.site_id = :site_id';
            $params['site_id'] = $siteId;
        } elseif ($siteKey !== null && trim($siteKey) !== '') {
            $where[] = 's.site_key = :site_key';
            $params['site_key'] = trim($siteKey);
        }
        if ($languageCode !== null && $languageCode !== '') {
            $where[] = 'r.language_code = :language_code';
            $params['language_code'] = $languageCode;
        }

        $joins = "JOIN sites s ON s.id = r.site_id";
        $orderLanguage = 'r.language_code';
        if ($this->hasTable('site_languages')) {
            $joins .= "\n                LEFT JOIN site_languages sl ON sl.site_id = r.site_id AND sl.language_code = r.language_code";
            $where[] = '(sl.id IS NULL OR sl.is_active = 1)';
            $orderLanguage = 'COALESCE(sl.sort_order, 999), r.language_code';
        }

        $titleSelect = "r.full_path AS title";
        if ($this->hasTable('public_content_snapshots')) {
            $joins .= "\n                LEFT JOIN public_content_snapshots pcs
                    ON pcs.site_id = r.site_id
                   AND pcs.language_code = r.language_code
                   AND (
                        (pcs.resource_type = r.resource_type AND pcs.resource_id = r.resource_id)
                        OR pcs.route_path = r.full_path
                   )";
            $titleSelect = "COALESCE(NULLIF(pcs.title, ''), r.full_path) AS title";
        }

        $sql = "SELECT r.id, r.site_id, s.site_key, r.language_code, r.full_path, r.resource_type, r.resource_id, r.route_type, r.is_canonical, COALESCE(sl.url_prefix, '') AS url_prefix, {$titleSelect}
                FROM routes r
                {$joins}
                WHERE " . implode(' AND ', $where) . "
                GROUP BY r.id
                ORDER BY {$orderLanguage}, CASE WHEN r.full_path = '/' THEN 0 ELSE 1 END, r.full_path
                LIMIT " . max(1, min(250, $limit));

        try {
            $items = $this->mapSuggestionRows($this->db->all($sql, $params));
        } catch (\Throwable) {
            $items = [];
        }
        if ($items !== []) {
            return $items;
        }
        if ($siteId !== null && $siteId > 0 && $siteKey !== null && trim($siteKey) !== '') {
            $items = $this->listPublishedRouteSuggestions(null, $siteKey, $languageCode, $limit);
            if ($items !== []) {
                return $items;
            }
        }
        if ($languageCode !== null && $languageCode !== '') {
            $items = $this->listPublishedRouteSuggestions($siteId, $siteKey, null, $limit);
            if ($items !== []) {
                return $items;
            }
        }
        return $this->listPublishedContentFallbackSuggestions($siteId, $siteKey, $languageCode, $limit);
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function mapSuggestionRows(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            $path = $this->normalizePath((string) ($row['full_path'] ?? $row['path'] ?? '/'));
            if ($this->isInternalPath($path) || (isset($row['is_canonical']) && (int) $row['is_canonical'] !== 1)) {
                continue;
            }
            $items[] = [
                'id' => (int) ($row['id'] ?? 0),
                'site_id' => (int) ($row['site_id'] ?? 0),
                'site' => (string) ($row['site_key'] ?? ''),
                'language_code' => (string) ($row['language_code'] ?? ''),
                'path' => self::localizedPublicPath($path, (string) ($row['url_prefix'] ?? '')),
                'route_path' => $path,
                'label' => $path === '/' ? 'Accueil' : (string) (($row['title'] ?? '') ?: $path),
                'type' => (string) ($row['resource_type'] ?? 'content_entry'),
                'route_type' => (string) ($row['route_type'] ?? 'content'),
                'canonical' => true,
            ];
        }
        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function listPublishedContentFallbackSuggestions(?int $siteId, ?string $siteKey, ?string $languageCode, int $limit): array
    {
        if (!$this->hasTable('content_entries') || !$this->hasTable('content_entry_localizations') || !$this->hasTable('sites')) {
            return [];
        }
        $languageCode = $this->normalizeLanguageCode($languageCode);
        $where = ["ce.status = 'published'", 'ce.is_active = 1', 'cel.is_active = 1', 's.is_active = 1'];
        $params = [];
        if ($siteId !== null && $siteId > 0) {
            $where[] = 'ce.site_id = :site_id';
            $params['site_id'] = $siteId;
        } elseif ($siteKey !== null && trim($siteKey) !== '') {
            $where[] = 's.site_key = :site_key';
            $params['site_key'] = trim($siteKey);
        }
        if ($languageCode !== null && $languageCode !== '') {
            $where[] = 'cel.language_code = :language_code';
            $params['language_code'] = $languageCode;
        }

        $typeJoin = '';
        $typeSelect = "'content_entry' AS resource_type";
        if ($this->hasTable('content_types')) {
            $typeJoin = 'LEFT JOIN content_types ct ON ct.id = ce.content_type_id';
            $typeSelect = "COALESCE(ct.type_key, 'content_entry') AS resource_type";
        }

        $sql = "SELECT ce.id, ce.site_id, s.site_key, cel.language_code,
                       COALESCE(NULLIF(cel.draft_full_path, ''), CASE WHEN ce.entry_key = 'home' THEN '/' ELSE '/' || COALESCE(NULLIF(cel.draft_slug, ''), ce.entry_key) END) AS full_path,
                       {$typeSelect}, ce.id AS resource_id, 'content' AS route_type, 1 AS is_canonical,
                       COALESCE(NULLIF(cel.title, ''), ce.entry_key) AS title
                FROM content_entries ce
                JOIN sites s ON s.id = ce.site_id
                JOIN content_entry_localizations cel ON cel.entry_id = ce.id
                {$typeJoin}
                WHERE " . implode(' AND ', $where) . "
                ORDER BY cel.language_code, CASE WHEN ce.entry_key = 'home' THEN 0 ELSE 1 END, COALESCE(cel.draft_full_path, cel.draft_slug, ce.entry_key)
                LIMIT " . max(1, min(250, $limit));

        return $this->mapSuggestionRows($this->db->all($sql, $params));
    }

    private function normalizeLanguageCode(?string $languageCode): ?string
    {
        if ($languageCode === null || trim($languageCode) === '') {
            return null;
        }
        $normalized = strtolower(trim($languageCode));
        if (str_contains($normalized, '-')) {
            $normalized = explode('-', $normalized, 2)[0];
        }
        return $normalized;
    }

    /** @return array<string,mixed>|null */
    public function getPublicSnapshot(StaticExportRoute $route): ?array
    {
        if (!$this->hasTable('public_content_snapshots')) {
            return null;
        }
        return $this->db->one(
            'SELECT * FROM public_content_snapshots
             WHERE site_id = :site_id AND language_code = :language_code AND resource_type = :resource_type AND resource_id = :resource_id
             LIMIT 1',
            [
                'site_id' => $route->siteId,
                'language_code' => $route->languageCode,
                'resource_type' => $route->resourceType ?? 'content_entry',
                'resource_id' => $route->resourceId ?? 0,
            ]
        );
    }

    /** @return array<string,mixed> */
    public function getSeoMetadata(StaticExportRoute $route): array
    {
        if (!$this->hasTable('seo_metadata')) {
            return [];
        }
        return $this->db->one(
            'SELECT * FROM seo_metadata
             WHERE site_id = :site_id AND language_code = :language_code AND resource_type = :resource_type AND resource_id = :resource_id
             LIMIT 1',
            [
                'site_id' => $route->siteId,
                'language_code' => $route->languageCode,
                'resource_type' => $route->resourceType ?? 'content_entry',
                'resource_id' => $route->resourceId ?? 0,
            ]
        ) ?? [];
    }

    /** @return list<array<string,mixed>> */
    public function listRedirects(?string $siteKey = null): array
    {
        return $this->listBySite('redirects', 'r', $siteKey, "r.is_active = 1", 'r.old_path');
    }

    /** @return list<array<string,mixed>> */
    public function listTombstones(?string $siteKey = null): array
    {
        return $this->listBySite('tombstones', 't', $siteKey, "t.is_active = 1", 't.old_path');
    }

    /** @return list<array<string,mixed>> */
    public function listSearchDocuments(?string $siteKey = null, ?string $languageCode = null): array
    {
        if (!$this->hasTable('search_documents')) {
            return [];
        }
        $where = ['s.is_active = 1'];
        $params = [];
        if ($siteKey !== null && $siteKey !== '') {
            $where[] = 's.site_key = :site_key';
            $params['site_key'] = $siteKey;
        }
        if ($languageCode !== null && $languageCode !== '') {
            $where[] = 'sd.language_code = :language_code';
            $params['language_code'] = strtolower($languageCode);
        }
        return $this->db->all(
            'SELECT sd.*, s.site_key FROM search_documents sd JOIN sites s ON s.id = sd.site_id WHERE ' . implode(' AND ', $where) . ' ORDER BY s.site_key, sd.language_code, sd.path',
            $params
        );
    }

    /** @return list<array<string,mixed>> */
    public function listPublicMediaAssets(?string $siteKey = null): array
    {
        if (!$this->hasTable('media_assets')) {
            return [];
        }
        $where = ["m.lifecycle_status = 'ready'", "m.validation_status = 'valid'", "m.storage_disk = 'local'", 's.is_active = 1'];
        $params = [];
        if ($siteKey !== null && $siteKey !== '') {
            $where[] = 's.site_key = :site_key';
            $params['site_key'] = $siteKey;
        }
        return $this->db->all(
            'SELECT m.*, s.site_key FROM media_assets m JOIN sites s ON s.id = m.site_id WHERE ' . implode(' AND ', $where) . ' ORDER BY s.site_key, m.path',
            $params
        );
    }

    /** @return list<array<string,mixed>> */
    public function listMenus(?string $siteKey = null, ?string $languageCode = null): array
    {
        if (!$this->hasTable('menus')) {
            return [];
        }
        $where = ['m.is_active = 1', 's.is_active = 1'];
        $params = [];
        if ($siteKey !== null && $siteKey !== '') {
            $where[] = 's.site_key = :site_key';
            $params['site_key'] = $siteKey;
        }
        $items = $this->db->all(
            'SELECT m.*, s.site_key FROM menus m JOIN sites s ON s.id = m.site_id WHERE ' . implode(' AND ', $where) . ' ORDER BY s.site_key, m.menu_key',
            $params
        );
        return $items;
    }

    /** @return list<array<string,mixed>> */
    public function listSiteLanguageSettings(?string $siteKey = null): array
    {
        if (!$this->hasTable('sites') || !$this->hasTable('site_languages')) {
            return [];
        }
        $where = ['s.is_active = 1', 'sl.is_active = 1'];
        $params = [];
        if ($siteKey !== null && $siteKey !== '') {
            $where[] = 's.site_key = :site_key';
            $params['site_key'] = $siteKey;
        }
        return $this->db->all(
            'SELECT s.id AS site_id, s.site_key, s.name, s.default_language_code, sl.language_code, sl.url_prefix, sl.hreflang_code, sl.is_default, sl.sort_order
             FROM sites s JOIN site_languages sl ON sl.site_id = s.id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY s.site_key, sl.sort_order, sl.language_code',
            $params
        );
    }

    /** @return array<string,mixed>|null */
    public function siteByKey(string $siteKey): ?array
    {
        if (!$this->hasTable('sites')) {
            return null;
        }
        
        $site = $this->db->one('SELECT * FROM sites WHERE site_key = :site_key AND is_active = 1 LIMIT 1', ['site_key' => $siteKey]);
        return $site ? $this->withPrimaryDomain($site) : null;
    }

    /** @return array<string,mixed>|null */
    public function siteById(int $siteId): ?array
    {
        if (!$this->hasTable('sites')) {
            return null;
        }
        
        $site = $this->db->one('SELECT * FROM sites WHERE id = :id AND is_active = 1 LIMIT 1', ['id' => $siteId]);
        return $site ? $this->withPrimaryDomain($site) : null;
    }


    /** @param array<string,mixed> $site @return array<string,mixed> */
    private function withPrimaryDomain(array $site): array
    {
        if (!$this->hasTable('site_domains')) {
            return $site + ['base_url' => ''];
        }
        $domain = $this->db->one(
            'SELECT * FROM site_domains WHERE site_id = :site_id AND is_active = 1 ORDER BY is_primary DESC, id ASC LIMIT 1',
            ['site_id' => (int) ($site['id'] ?? 0)]
        );
        if (!$domain) {
            return $site + ['base_url' => ''];
        }
        $scheme = (string) ($domain['scheme'] ?? 'https');
        $host = (string) ($domain['host'] ?? '');
        $basePath = trim((string) ($domain['base_path'] ?? ''));
        $basePath = $basePath === '' || $basePath === '/' ? '' : '/' . trim($basePath, '/');
        return $site + [
            'base_url' => $host !== '' ? rtrim($scheme . '://' . $host . $basePath, '/') : '',
            'current_base_url' => $host !== '' ? rtrim($scheme . '://' . $host . $basePath, '/') : '',
            'matched_base_path' => $basePath,
            'is_canonical_domain' => true,
            'should_redirect_https' => false,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function listBySite(string $table, string $alias, ?string $siteKey, string $extraWhere, string $order): array
    {
        if (!$this->hasTable($table)) {
            return [];
        }
        $where = ['s.is_active = 1', $extraWhere];
        $params = [];
        if ($siteKey !== null && $siteKey !== '') {
            $where[] = 's.site_key = :site_key';
            $params['site_key'] = $siteKey;
        }
        return $this->db->all(
            "SELECT {$alias}.*, s.site_key FROM {$table} {$alias} JOIN sites s ON s.id = {$alias}.site_id WHERE " . implode(' AND ', $where) . " ORDER BY s.site_key, {$order}",
            $params
        );
    }

    private function hasTable(string $table): bool
    {
        return $this->db->tableExists($table);
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path === '//' ? '/' : $path;
    }

    public static function htmlOutputPath(string $path, string $urlPrefix = ''): string
    {
        $path = '/' . trim($path, '/');
        $path = $path === '//' ? '/' : $path;
        $prefix = trim($urlPrefix, '/');
        $segments = [];
        if ($prefix !== '') {
            $segments[] = $prefix;
        }
        if ($path !== '/') {
            $segments[] = trim($path, '/');
        }
        if ($segments === []) {
            return 'index.html';
        }
        return implode('/', $segments) . '/index.html';
    }

    public static function localizedPublicPath(string $path, string $urlPrefix = ''): string
    {
        $path = '/' . trim($path, '/');
        $path = $path === '//' ? '/' : $path;
        $prefix = trim($urlPrefix, '/');
        if ($prefix === '') {
            return $path;
        }
        return '/' . $prefix . ($path === '/' ? '' : $path);
    }


    private function stripKnownLanguagePrefix(string $path, ?string $siteKey): string
    {
        $path = $this->normalizePath($path);
        if ($path === '/') {
            return $path;
        }
        foreach ($this->listSiteLanguageSettings($siteKey) as $language) {
            $prefix = trim((string) ($language['url_prefix'] ?? ''), '/');
            if ($prefix === '') {
                continue;
            }
            $prefixPath = '/' . $prefix;
            if ($path === $prefixPath) {
                return '/';
            }
            if (str_starts_with($path, $prefixPath . '/')) {
                return substr($path, strlen($prefixPath)) ?: '/';
            }
        }
        return $path;
    }

    private function isInternalPath(string $path): bool
    {
        return (bool) preg_match('#^/(admin|api|preview|assets|_debug|_internal|backend|tools|database|ops)(/|$)#i', $path)
            || preg_match('#\.(sqlite|log|bak|zip|tmp|env)$#i', $path) === 1;
    }
}
