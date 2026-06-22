<?php

declare(strict_types=1);

namespace App\Application\Configuration;

use App\Core\Database;
use InvalidArgumentException;

final class MultisiteRepository
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public function __construct(private readonly Database $db) {}

    /** @return array<string,mixed> */
    public function overview(): array
    {
        $main = $this->mainSite();
        $sites = array_map(fn (array $row): array => $this->siteContract($row), $this->db->all(
            'SELECT s.*, sd.host, sd.base_path, sd.scheme, sd.is_primary, sd.enforce_https,
                    (SELECT COUNT(*) FROM content_entries ce WHERE ce.site_id = s.id AND ce.status != \'archived\') AS content_count,
                    (SELECT COUNT(*) FROM site_languages sl WHERE sl.site_id = s.id AND sl.is_active = 1) AS language_count
             FROM sites s
             LEFT JOIN site_domains sd ON sd.site_id = s.id AND sd.is_primary = 1
             WHERE s.id != :main_id
             ORDER BY s.name COLLATE NOCASE, s.id',
            ['main_id' => (int) $main['id']]
        ));

        return [
            'main_site' => $this->siteContract($main),
            'subsites' => $sites,
            'defaults' => [
                'default_language_code' => (string) ($main['default_language_code'] ?? 'fr'),
                'scheme' => 'https',
                'enforce_https' => true,
            ],
            'actions' => [
                'index' => ['method' => 'GET', 'path' => '/admin/api/multisite'],
                'store' => ['method' => 'POST', 'path' => '/admin/api/multisite/sites'],
                'destroy' => ['method' => 'DELETE', 'path' => '/admin/api/multisite/sites/{id}'],
            ],
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function create(array $payload, ?int $userId = null): array
    {
        $values = $this->validateCreatePayload($payload);

        return $this->db->transaction(function () use ($values, $userId): array {
            $this->db->run(
                'INSERT INTO sites(site_key, name, default_language_code, is_active, created_at, updated_at)
                 VALUES(:site_key, :name, :language, :is_active, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
                [
                    'site_key' => $values['site_key'],
                    'name' => $values['name'],
                    'language' => $values['default_language_code'],
                    'is_active' => $values['is_active'] ? 1 : 0,
                ]
            );
            $siteId = $this->db->lastInsertId();

            $this->db->run(
                'INSERT INTO site_languages(site_id, language_code, locale, url_prefix, hreflang_code, fallback_language_code, is_default, is_active, is_rtl, sort_order)
                 SELECT :site_id, code, locale, :url_prefix, locale, NULL, 1, 1, 0, 10
                 FROM languages WHERE code = :language LIMIT 1',
                ['site_id' => $siteId, 'language' => $values['default_language_code'], 'url_prefix' => '']
            );

            $this->db->run(
                'INSERT INTO site_domains(site_id, host, base_path, scheme, is_primary, is_active, enforce_https, canonical_host_strategy)
                 VALUES(:site_id, :host, :base_path, :scheme, 1, 1, :enforce_https, \'primary\')',
                [
                    'site_id' => $siteId,
                    'host' => $values['primary_domain_host'],
                    'base_path' => $values['base_path'],
                    'scheme' => $values['scheme'],
                    'enforce_https' => $values['enforce_https'] ? 1 : 0,
                ]
            );

            $this->db->run(
                'INSERT INTO site_localizations(site_id, language_code, site_title, baseline, footer_text, default_meta_title_suffix, default_meta_description)
                 VALUES(:site_id, :language, :title, \'\', \'\', :suffix, \'\')',
                [
                    'site_id' => $siteId,
                    'language' => $values['default_language_code'],
                    'title' => $values['name'],
                    'suffix' => ' · ' . $values['name'],
                ]
            );

            $this->seedSiteSettings($siteId, $userId);
            $this->seedMediaFolders($siteId);
            $this->seedMediaVariantPresets($siteId);
            $this->seedMenus($siteId);

            return $this->findSite($siteId) ?? ['id' => $siteId];
        });
    }

    public function delete(int $siteId): array
    {
        $site = $this->findSite($siteId);
        if (!$site) {
            throw new InvalidArgumentException('Site introuvable.');
        }
        if ((int) $site['id'] === (int) $this->mainSite()['id']) {
            throw new InvalidArgumentException('Le site principal ne peut pas être retiré.');
        }

        $this->db->transaction(function () use ($siteId): void {
            // Les FK ON DELETE CASCADE suppriment proprement langues, domaines, menus,
            // contenus, projections, SEO, recherche, médias et taxonomies du sous-site.
            $this->db->run('DELETE FROM sites WHERE id = :id', ['id' => $siteId]);
        });

        return ['deleted_site_id' => $siteId, 'status' => 'deleted'];
    }

    /** @return array<string,mixed> */
    private function mainSite(): array
    {
        $configured = $this->db->one("SELECT * FROM sites WHERE site_key = 'main' LIMIT 1");
        if ($configured) {
            return $configured;
        }
        $fallback = $this->db->one('SELECT * FROM sites ORDER BY id LIMIT 1');
        if (!$fallback) {
            throw new InvalidArgumentException('Aucun site principal configuré.');
        }
        return $fallback;
    }

    private function findSite(int $siteId): ?array
    {
        return $this->db->one(
            'SELECT s.*, sd.host, sd.base_path, sd.scheme, sd.is_primary, sd.enforce_https,
                    (SELECT COUNT(*) FROM content_entries ce WHERE ce.site_id = s.id AND ce.status != \'archived\') AS content_count,
                    (SELECT COUNT(*) FROM site_languages sl WHERE sl.site_id = s.id AND sl.is_active = 1) AS language_count
             FROM sites s
             LEFT JOIN site_domains sd ON sd.site_id = s.id AND sd.is_primary = 1
             WHERE s.id = :id LIMIT 1',
            ['id' => $siteId]
        );
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function siteContract(array $row): array
    {
        $storedBasePath = $this->normalizeBasePath((string) ($row['base_path'] ?? ''));
        $requestBasePath = $this->requestBasePath($storedBasePath);
        $publicBasePath = $this->publicBasePath($storedBasePath);
        $host = (string) ($row['host'] ?? '');
        $scheme = (string) ($row['scheme'] ?? 'https');
        $publicPath = function_exists('url_path') ? url_path($requestBasePath === '' ? '/' : $requestBasePath . '/') : ($publicBasePath === '' ? '/' : $publicBasePath . '/');
        $adminPath = function_exists('admin_url_path_for_site') ? admin_url_path_for_site($requestBasePath, '/admin/app') : ($publicBasePath . '/admin/app');

        return [
            'id' => (int) $row['id'],
            'site_key' => (string) $row['site_key'],
            'name' => (string) $row['name'],
            'default_language_code' => (string) $row['default_language_code'],
            'is_active' => (bool) ($row['is_active'] ?? true),
            'host' => $host,
            'base_path' => $publicBasePath,
            'request_base_path' => $requestBasePath,
            'scheme' => $scheme,
            'enforce_https' => (bool) ($row['enforce_https'] ?? true),
            'public_path' => $publicPath,
            'public_url' => $host !== '' ? rtrim($scheme . '://' . $host . $publicBasePath, '/') . '/' : $publicPath,
            'admin_path' => $adminPath,
            'content_count' => (int) ($row['content_count'] ?? 0),
            'language_count' => (int) ($row['language_count'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private function normalizeBasePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $path === '/') {
            return '';
        }
        return '/' . trim($path, '/');
    }

    private function requestBasePath(string $domainBasePath): string
    {
        $basePath = $this->normalizeBasePath($domainBasePath);
        $appBasePath = $this->normalizeBasePath(function_exists('app_base_path') ? app_base_path() : '');
        if ($appBasePath !== '' && ($basePath === $appBasePath || str_starts_with($basePath, $appBasePath . '/'))) {
            $basePath = substr($basePath, strlen($appBasePath)) ?: '';
        }
        return $this->normalizeBasePath($basePath);
    }

    private function publicBasePath(string $domainBasePath): string
    {
        $basePath = $this->normalizeBasePath($domainBasePath);
        $appBasePath = $this->normalizeBasePath(function_exists('app_base_path') ? app_base_path() : '');
        if ($appBasePath === '') {
            return $basePath;
        }
        if ($basePath === '' || $basePath === '/') {
            return $appBasePath;
        }
        if ($basePath === $appBasePath || str_starts_with($basePath, $appBasePath . '/')) {
            return $basePath;
        }
        return $this->normalizeBasePath($appBasePath . '/' . ltrim($basePath, '/'));
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function validateCreatePayload(array $payload): array
    {
        $siteKey = $this->cleanKey($payload['site_key'] ?? '');
        $name = $this->cleanString($payload['name'] ?? '', 120);
        $language = $this->cleanLanguage($payload['default_language_code'] ?? 'fr');
        $host = $this->cleanHost($payload['primary_domain_host'] ?? '');
        $basePath = $this->cleanBasePath($payload['base_path'] ?? '');
        $scheme = in_array(($payload['scheme'] ?? 'https'), ['http', 'https'], true) ? (string) $payload['scheme'] : 'https';

        $fields = [];
        if ($siteKey === '') { $fields['site_key'][] = 'Clé obligatoire.'; }
        if ($name === '') { $fields['name'][] = 'Nom obligatoire.'; }
        if ($host === '') { $fields['primary_domain_host'][] = 'Domaine obligatoire.'; }
        if ($basePath === '/admin' || str_starts_with($basePath . '/', '/admin/')) { $fields['base_path'][] = 'Chemin réservé au back-office.'; }
        if (!$this->db->one('SELECT 1 FROM languages WHERE code = :code AND is_active = 1', ['code' => $language])) { $fields['default_language_code'][] = 'Langue inactive ou inconnue.'; }
        if ($this->db->one('SELECT 1 FROM sites WHERE site_key = :key', ['key' => $siteKey])) { $fields['site_key'][] = 'Cette clé de site existe déjà.'; }
        if ($host !== '' && $this->db->one('SELECT 1 FROM site_domains WHERE host = :host AND base_path = :base_path', ['host' => $host, 'base_path' => $basePath])) { $fields['primary_domain_host'][] = 'Ce domaine et ce chemin sont déjà utilisés.'; }

        if ($fields !== []) {
            throw new InvalidArgumentException(json_encode(['fields' => $fields], self::JSON_FLAGS) ?: 'Configuration invalide.');
        }

        return [
            'site_key' => $siteKey,
            'name' => $name,
            'default_language_code' => $language,
            'primary_domain_host' => $host,
            'base_path' => $basePath,
            'scheme' => $scheme,
            'enforce_https' => filter_var($payload['enforce_https'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true,
            'is_active' => filter_var($payload['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true,
        ];
    }

    private function seedSiteSettings(int $siteId, ?int $userId): void
    {
        $settings = [
            ['seo', 'defaults', ['robots' => 'index,follow', 'sitemap_enabled' => true, 'canonical_enabled' => true, 'title_max_length' => 60, 'description_max_length' => 160], true],
            ['media', 'defaults', ['max_upload_mb' => 12, 'allowed_mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'video/mp4', 'video/webm', 'audio/mpeg', 'audio/mp4', 'audio/ogg', 'application/pdf'], 'auto_generate_variants' => true, 'variant_sets' => ['content' => [480, 768, 1024, 1280, 1600], 'hero' => [640, 960, 1280, 1920], 'open_graph' => ['1200x630']], 'require_alt_text' => true, 'default_folder_key' => 'general', 'logo_media_id' => null, 'favicon_media_id' => null, 'default_social_image_media_id' => null, 'social_image_policy' => ['required_width' => 1200, 'required_height' => 630, 'variant_key' => 'og_1200x630']], false],
            ['backoffice', 'defaults', ['admin_ui_language_code' => 'fr'], false],
            ['public_ui', 'defaults', ['show_login_shortcut' => false], true],
            ['articles', 'defaults', ['detail_show_published_date' => true, 'detail_show_author' => true, 'detail_show_updated_date' => false, 'detail_show_type' => true, 'detail_include_author_in_schema' => true, 'detail_date_format' => 'medium'], true],
            ['relations', 'defaults', ['allow_cross_type_relations' => true, 'max_related_items' => 24, 'enable_bidirectional_hints' => true, 'allowed_relation_types' => ['related', 'parent', 'child', 'featured_media'], 'media_storage' => ['driver' => 'local', 's3' => ['endpoint' => '', 'bucket' => '', 'region' => 'us-east-1', 'access_key' => '', 'secret_key' => '', 'public_base_url' => '', 'path_prefix' => '']]], false],
        ];

        foreach ($settings as [$namespace, $key, $value, $public]) {
            $this->db->run(
                'INSERT INTO site_settings(site_id, namespace, setting_key, value_json, is_public, updated_by_iam_user_id)
                 VALUES(:site_id, :namespace, :setting_key, :value_json, :is_public, :user_id)',
                [
                    'site_id' => $siteId,
                    'namespace' => $namespace,
                    'setting_key' => $key,
                    'value_json' => json_encode($value, self::JSON_FLAGS),
                    'is_public' => $public ? 1 : 0,
                    'user_id' => $userId,
                ]
            );
        }
    }

    private function seedMediaFolders(int $siteId): void
    {
        foreach ([['general', 'Général', 10], ['site-assets', 'Identité du site', 20]] as [$key, $name, $sort]) {
            $this->db->run('INSERT INTO media_folders(site_id, folder_key, name, sort_order) VALUES(:site_id, :key, :name, :sort)', [
                'site_id' => $siteId, 'key' => $key, 'name' => $name, 'sort' => $sort,
            ]);
        }
    }

    private function seedMediaVariantPresets(int $siteId): void
    {
        $presets = [
            ['content_480', 480, null, 'webp', 82, 'fit', 10],
            ['content_768', 768, null, 'webp', 82, 'fit', 20],
            ['content_1024', 1024, null, 'webp', 82, 'fit', 30],
            ['content_1280', 1280, null, 'webp', 82, 'fit', 40],
            ['content_1600', 1600, null, 'webp', 82, 'fit', 50],
            ['hero_640', 640, null, 'webp', 82, 'fit', 110],
            ['hero_960', 960, null, 'webp', 82, 'fit', 120],
            ['hero_1280', 1280, null, 'webp', 82, 'fit', 130],
            ['hero_1920', 1920, null, 'webp', 82, 'fit', 140],
            ['og_1200x630', 1200, 630, 'webp', 82, 'crop', 210],
        ];

        foreach ($presets as [$key, $width, $height, $format, $quality, $mode, $sort]) {
            $this->db->run(
                'INSERT INTO media_variant_presets(site_id, preset_key, width, height, format, quality, mode, sort_order, is_active)
                 VALUES(:site_id, :preset_key, :width, :height, :format, :quality, :mode, :sort_order, 1)
                 ON CONFLICT(site_id, preset_key) DO UPDATE SET
                    width = excluded.width,
                    height = excluded.height,
                    format = excluded.format,
                    quality = excluded.quality,
                    mode = excluded.mode,
                    sort_order = excluded.sort_order,
                    is_active = 1,
                    updated_at = CURRENT_TIMESTAMP',
                [
                    'site_id' => $siteId,
                    'preset_key' => $key,
                    'width' => $width,
                    'height' => $height,
                    'format' => $format,
                    'quality' => $quality,
                    'mode' => $mode,
                    'sort_order' => $sort,
                ]
            );
        }
    }

    private function seedMenus(int $siteId): void
    {
        foreach ([['main', 'Navigation principale', 'primary'], ['footer', 'Pied de page', 'footer']] as [$key, $name, $location]) {
            $this->db->run('INSERT INTO menus(site_id, menu_key, name, menu_location, language_mode, is_active) VALUES(:site_id, :key, :name, :location, \'shared\', 1)', [
                'site_id' => $siteId, 'key' => $key, 'name' => $name, 'location' => $location,
            ]);
        }
    }

    private function cleanString(mixed $value, int $max): string
    {
        $clean = trim(str_replace("\0", '', (string) $value));
        return function_exists('mb_substr') ? mb_substr($clean, 0, $max) : substr($clean, 0, $max);
    }

    private function cleanKey(mixed $value): string
    {
        $value = strtolower($this->cleanString($value, 60));
        if ($value !== '' && !preg_match('/^[a-z0-9][a-z0-9_-]{0,59}$/', $value)) {
            throw new InvalidArgumentException(json_encode(['fields' => ['site_key' => ['Clé invalide : lettres, chiffres, tirets et underscores uniquement.']]], self::JSON_FLAGS) ?: 'Clé invalide.');
        }
        return $value;
    }

    private function cleanLanguage(mixed $value): string
    {
        $value = $this->cleanString($value, 16);
        if (!preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $value)) {
            throw new InvalidArgumentException(json_encode(['fields' => ['default_language_code' => ['Code langue invalide.']]], self::JSON_FLAGS) ?: 'Langue invalide.');
        }
        return $value;
    }

    private function cleanHost(mixed $value): string
    {
        $host = strtolower($this->cleanString($value, 190));
        $host = preg_replace('#^https?://#', '', $host) ?? $host;
        $host = explode('/', $host, 2)[0];
        if ($host !== '' && !preg_match('/^[a-z0-9.-]+(?::[0-9]{2,5})?$/', $host)) {
            throw new InvalidArgumentException(json_encode(['fields' => ['primary_domain_host' => ['Domaine invalide.']]], self::JSON_FLAGS) ?: 'Domaine invalide.');
        }
        return $host;
    }

    private function cleanBasePath(mixed $value): string
    {
        $path = '/' . trim($this->cleanString($value, 120), '/');
        $path = $path === '/' ? '' : $path;
        if ($path !== '' && !preg_match('#^/[A-Za-z0-9._~/-]+$#', $path)) {
            throw new InvalidArgumentException(json_encode(['fields' => ['base_path' => ['Chemin invalide.']]], self::JSON_FLAGS) ?: 'Chemin invalide.');
        }
        return $path;
    }
}
