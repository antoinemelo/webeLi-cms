<?php

declare(strict_types=1);

namespace App\Application\Configuration;

use App\Core\Database;

final class ConfigurationRepository
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /** @var array<string,mixed> */
    private const PUBLIC_UI_DEFAULTS = [
        'show_login_shortcut' => false,
        'show_site_title_in_header' => true,
        'active_theme_key' => 'default',
        'body_font_family' => 'system',
        'heading_font_family' => 'system',
        'main_heading_font_family' => 'heading',
        'main_heading_letter_spacing' => 'normal',
        'color_background' => '#fbfcfe',
        'color_surface' => '#ffffff',
        'color_text' => '#102033',
        'color_muted' => '#627086',
        'color_primary' => '#1b5fc1',
        'color_accent' => '#ffb21e',
    ];

    /** @var array<string,mixed> */
    private const ARTICLE_DEFAULTS = [
        'detail_show_published_date' => true,
        'detail_show_author' => true,
        'detail_show_updated_date' => false,
        'detail_show_type' => true,
        'detail_include_author_in_schema' => true,
        'detail_date_format' => 'medium',
    ];

    /** @var array<string,array<string,mixed>> */
    private const THEME_APPEARANCE_DEFAULTS = [
        'default' => [
            'body_font_family' => 'system',
            'heading_font_family' => 'system',
            'main_heading_font_family' => 'heading',
            'main_heading_letter_spacing' => 'normal',
            'show_site_title_in_header' => true,
            'color_background' => '#fbfcfe',
            'color_surface' => '#ffffff',
            'color_text' => '#102033',
            'color_muted' => '#627086',
            'color_primary' => '#1b5fc1',
            'color_accent' => '#ffb21e',
        ],
        'aurora' => [
            'body_font_family' => 'system',
            'heading_font_family' => 'serif',
            'main_heading_font_family' => 'arial',
            'main_heading_letter_spacing' => 'normal',
            'show_site_title_in_header' => true,
            'color_background' => '#fbf7ef',
            'color_surface' => '#fffaf2',
            'color_text' => '#162026',
            'color_muted' => '#66747d',
            'color_primary' => '#13242b',
            'color_accent' => '#ef8f4e',
        ],
        'pulse' => [
            'body_font_family' => 'system',
            'heading_font_family' => 'system',
            'main_heading_font_family' => 'arial',
            'main_heading_letter_spacing' => 'normal',
            'show_site_title_in_header' => true,
            'color_background' => '#f6fbff',
            'color_surface' => '#ffffff',
            'color_text' => '#102033',
            'color_muted' => '#5d6f86',
            'color_primary' => '#2667ff',
            'color_accent' => '#ff6b4a',
        ],
    ];

    public function __construct(private readonly Database $db) {}

    /** @return array<string,mixed> */
    public function load(int $siteId, string $languageCode, string $uiLanguageCode = 'fr'): array
    {
        $site = $this->site($siteId);
        $domain = $this->primaryDomain($siteId);
        $languages = $this->languages($siteId);
        $localization = $this->localization($siteId, $languageCode);
        $settings = $this->settings($siteId);

        $values = [
            'site' => [
                'site_key' => (string) ($site['site_key'] ?? ''),
                'name' => (string) ($site['name'] ?? ''),
                'default_language_code' => (string) ($site['default_language_code'] ?? ''),
                'is_active' => (bool) ($site['is_active'] ?? true),
                'primary_domain_host' => (string) ($domain['host'] ?? ''),
                'base_path' => (string) ($domain['base_path'] ?? ''),
                'scheme' => (string) ($domain['scheme'] ?? 'https'),
                'enforce_https' => (bool) ($domain['enforce_https'] ?? true),
            ],
            'languages' => [
                'enabled_languages' => $languages,
            ],
            'localization' => [
                'language_code' => $languageCode,
                'site_title' => (string) ($localization['site_title'] ?? $site['name'] ?? ''),
                'baseline' => (string) ($localization['baseline'] ?? ''),
                'footer_text' => (string) ($localization['footer_text'] ?? ''),
                'default_meta_title_suffix' => (string) ($localization['default_meta_title_suffix'] ?? ''),
                'default_meta_description' => (string) ($localization['default_meta_description'] ?? ''),
                'og_default_image_media_id' => isset($localization['og_default_image_media_id']) ? (int) $localization['og_default_image_media_id'] : null,
            ],
            'seo' => $settings['seo']['defaults'] ?? [
                'robots' => 'index,follow',
                'sitemap_enabled' => true,
                'canonical_enabled' => true,
                'title_max_length' => 60,
                'description_max_length' => 160,
            ],
            'media' => $settings['media']['defaults'] ?? [
                'max_upload_mb' => 12,
                'allowed_mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
                'auto_generate_variants' => true,
                'require_alt_text' => true,
                'default_folder_key' => 'general',
                'logo_media_id' => null,
                'favicon_media_id' => null,
            ],
            'backoffice' => $settings['backoffice']['defaults'] ?? [
                'admin_ui_language_code' => 'fr',
            ],
            'public_ui' => array_replace(self::PUBLIC_UI_DEFAULTS, $settings['public_ui']['defaults'] ?? []),
            'articles' => array_replace(self::ARTICLE_DEFAULTS, $settings['articles']['defaults'] ?? []),
            'relations' => array_replace_recursive([
                'allow_cross_type_relations' => true,
                'max_related_items' => 24,
                'enable_bidirectional_hints' => true,
                'allowed_relation_types' => ['related', 'parent', 'child', 'featured_media'],
                'media_storage' => [
                    'driver' => 'local',
                    's3' => [
                        'endpoint' => '',
                        'bucket' => '',
                        'region' => 'us-east-1',
                        'access_key' => '',
                        'secret_key' => '',
                        'public_base_url' => '',
                        'path_prefix' => '',
                    ],
                ],
            ], $settings['relations']['defaults'] ?? []),
        ];

        $corsSetting = $settings['api']['cors_allowed_origins'] ?? [];
        $corsOrigins = is_array($corsSetting) && array_is_list($corsSetting) ? $corsSetting : ($corsSetting['origins'] ?? []);
        $values['relations']['cors_allowed_origins'] = implode("\n", is_array($corsOrigins) ? array_map('strval', $corsOrigins) : []);

        return [
            'schema_version' => '2026-05-configuration-v3',
            'groups' => $this->schemaGroups($uiLanguageCode),
            'values' => $values,
            'actions' => [
                'read' => ['method' => 'GET', 'path' => '/admin/api/configuration'],
                'update' => ['method' => 'PATCH', 'path' => '/admin/api/configuration'],
            ],
        ];
    }

    /** @param array<string,mixed> $payload @return array{values:array<string,mixed>,fields:array<string,list<string>>} */
    public function validate(string $groupKey, array $payload, int $siteId, string $languageCode): array
    {
        $fields = [];
        $values = [];
        $add = static function (string $key, string $message) use (&$fields): void {
            $fields[$key][] = $message;
        };

        if (!in_array($groupKey, ['site', 'languages', 'backoffice', 'public_ui', 'localization', 'seo', 'media', 'articles', 'relations'], true)) {
            $add('group_key', 'Groupe de configuration inconnu.');
            return ['values' => [], 'fields' => $fields];
        }

        if ($groupKey === 'site') {
            $values['site_key'] = $this->key($payload['site_key'] ?? '', 'site.site_key', $add, 60);
            $values['name'] = $this->str($payload['name'] ?? '', 'site.name', $add, 120, false);
            $values['default_language_code'] = $this->language($payload['default_language_code'] ?? '', 'site.default_language_code', $add);
            $values['is_active'] = $this->bool($payload['is_active'] ?? true);
            $values['primary_domain_host'] = $this->host($payload['primary_domain_host'] ?? '', 'site.primary_domain_host', $add);
            $values['base_path'] = $this->basePath($payload['base_path'] ?? '', 'site.base_path', $add);
            $values['scheme'] = in_array(($payload['scheme'] ?? 'https'), ['http', 'https'], true) ? (string) $payload['scheme'] : 'https';
            $values['enforce_https'] = $this->bool($payload['enforce_https'] ?? true);
            if (!$this->languageExistsForSite($siteId, $values['default_language_code'])) {
                $add('site.default_language_code', 'La langue par défaut doit être activée sur le site.');
            }
        }

        if ($groupKey === 'languages') {
            $items = $payload['enabled_languages'] ?? null;
            if (!is_array($items) || $items === []) {
                $add('languages.enabled_languages', 'Au moins une langue active est requise.');
                $items = [];
            }
            $seenCodes = [];
            $seenPrefixes = [];
            $defaultCount = 0;
            foreach (array_values($items) as $i => $item) {
                if (!is_array($item)) {
                    $add("languages.enabled_languages.$i", 'Chaque langue doit être un objet.');
                    continue;
                }
                $code = $this->language($item['code'] ?? $item['language_code'] ?? '', "languages.enabled_languages.$i.code", $add);
                $prefix = $this->basePath($item['url_prefix'] ?? '', "languages.enabled_languages.$i.url_prefix", $add);
                $isDefault = $this->bool($item['is_default'] ?? false);
                $isActive = $this->bool($item['is_active'] ?? true);
                $fallback = ($item['fallback_language_code'] ?? null) ? $this->language($item['fallback_language_code'], "languages.enabled_languages.$i.fallback_language_code", $add) : null;
                if (isset($seenCodes[$code])) {
                    $add("languages.enabled_languages.$i.code", 'Code langue dupliqué.');
                }
                if (isset($seenPrefixes[$prefix])) {
                    $add("languages.enabled_languages.$i.url_prefix", 'Préfixe URL dupliqué.');
                }
                if ($isDefault && !$isActive) {
                    $add("languages.enabled_languages.$i.is_active", 'La langue par défaut doit être active.');
                }
                if ($fallback !== null && $fallback === $code) {
                    $add("languages.enabled_languages.$i.fallback_language_code", 'La langue de fallback doit être différente de la langue courante.');
                }
                $seenCodes[$code] = true;
                $seenPrefixes[$prefix] = true;
                $defaultCount += $isDefault ? 1 : 0;
                $values['enabled_languages'][] = [
                    'code' => $code,
                    'locale' => $this->str($item['locale'] ?? $code, "languages.enabled_languages.$i.locale", $add, 16, false),
                    'url_prefix' => $prefix,
                    'hreflang_code' => $this->str($item['hreflang_code'] ?? $code, "languages.enabled_languages.$i.hreflang_code", $add, 16, true),
                    'fallback_language_code' => $fallback,
                    'is_default' => $isDefault,
                    'is_active' => $isActive,
                    'is_rtl' => $this->bool($item['is_rtl'] ?? false),
                    'sort_order' => max(0, (int) ($item['sort_order'] ?? ($i + 1) * 10)),
                ];
            }
            if ($defaultCount !== 1) {
                $add('languages.enabled_languages', 'Une seule langue doit être définie comme langue par défaut.');
            }
            foreach (($values['enabled_languages'] ?? []) as $i => $item) {
                if (($item['fallback_language_code'] ?? null) !== null && !isset($seenCodes[(string) $item['fallback_language_code']])) {
                    $add("languages.enabled_languages.$i.fallback_language_code", 'La langue de fallback doit faire partie des langues exposées.');
                }
            }
        }

        if ($groupKey === 'backoffice') {
            $uiLanguage = (string) ($payload['admin_ui_language_code'] ?? 'fr');
            if (!in_array($uiLanguage, ['fr', 'en'], true)) {
                $add('backoffice.admin_ui_language_code', 'La langue d’interface doit être fr ou en.');
                $uiLanguage = 'fr';
            }
            $values = [
                'admin_ui_language_code' => $uiLanguage,
            ];
        }

        if ($groupKey === 'localization') {
            $values['language_code'] = $this->language($payload['language_code'] ?? $languageCode, 'localization.language_code', $add);
            $values['site_title'] = $this->str($payload['site_title'] ?? '', 'localization.site_title', $add, 120, false);
            $values['baseline'] = $this->str($payload['baseline'] ?? '', 'localization.baseline', $add, 180, true);
            $values['footer_text'] = $this->str($payload['footer_text'] ?? '', 'localization.footer_text', $add, 500, true);
            $values['default_meta_title_suffix'] = $this->str($payload['default_meta_title_suffix'] ?? '', 'localization.default_meta_title_suffix', $add, 80, true);
            $values['default_meta_description'] = $this->str($payload['default_meta_description'] ?? '', 'localization.default_meta_description', $add, 180, true);
            $media = $payload['og_default_image_media_id'] ?? null;
            $values['og_default_image_media_id'] = ($media === null || $media === '') ? null : max(1, (int) $media);
            if ($values['og_default_image_media_id'] !== null && !$this->mediaExistsForSite($siteId, $values['og_default_image_media_id'])) {
                $add('localization.og_default_image_media_id', 'Le média OpenGraph doit exister et appartenir au site courant.');
            }
            if (!$this->languageExistsForSite($siteId, $values['language_code'])) {
                $add('localization.language_code', 'La langue doit être activée sur le site.');
            }
        }

        if ($groupKey === 'seo') {
            $robots = (string) ($payload['robots'] ?? 'index,follow');
            if (!in_array($robots, ['index,follow', 'noindex,follow', 'index,nofollow', 'noindex,nofollow'], true)) {
                $add('seo.robots', 'Valeur robots non autorisée.');
            }
            $values = [
                'robots' => $robots,
                'sitemap_enabled' => $this->bool($payload['sitemap_enabled'] ?? true),
                'canonical_enabled' => $this->bool($payload['canonical_enabled'] ?? true),
                'title_max_length' => $this->intRange($payload['title_max_length'] ?? 60, 30, 90, 'seo.title_max_length', $add),
                'description_max_length' => $this->intRange($payload['description_max_length'] ?? 160, 80, 240, 'seo.description_max_length', $add),
            ];
        }

        if ($groupKey === 'public_ui') {
            $themeKey = $this->themeKey($payload['active_theme_key'] ?? 'default', 'public_ui.active_theme_key', $add);
            $values = [
                'show_login_shortcut' => $this->bool($payload['show_login_shortcut'] ?? false),
                'show_site_title_in_header' => $this->bool($payload['show_site_title_in_header'] ?? true),
                'active_theme_key' => $themeKey,
                'body_font_family' => $this->fontFamily($payload['body_font_family'] ?? 'system', 'public_ui.body_font_family', $add),
                'heading_font_family' => $this->fontFamily($payload['heading_font_family'] ?? 'system', 'public_ui.heading_font_family', $add),
                'main_heading_font_family' => $this->mainHeadingFontFamily($payload['main_heading_font_family'] ?? 'heading', 'public_ui.main_heading_font_family', $add),
                'main_heading_letter_spacing' => $this->mainHeadingLetterSpacing($payload['main_heading_letter_spacing'] ?? 'normal', 'public_ui.main_heading_letter_spacing', $add),
                'color_background' => $this->hexColor($payload['color_background'] ?? '#fbfcfe', 'public_ui.color_background', $add),
                'color_surface' => $this->hexColor($payload['color_surface'] ?? '#ffffff', 'public_ui.color_surface', $add),
                'color_text' => $this->hexColor($payload['color_text'] ?? '#102033', 'public_ui.color_text', $add),
                'color_muted' => $this->hexColor($payload['color_muted'] ?? '#627086', 'public_ui.color_muted', $add),
                'color_primary' => $this->hexColor($payload['color_primary'] ?? '#1b5fc1', 'public_ui.color_primary', $add),
                'color_accent' => $this->hexColor($payload['color_accent'] ?? '#ffb21e', 'public_ui.color_accent', $add),
            ];
        }

        if ($groupKey === 'media') {
            $mimes = $payload['allowed_mime_types'] ?? [];
            if (!is_array($mimes) || $mimes === []) {
                $add('media.allowed_mime_types', 'Au moins un type MIME est requis.');
                $mimes = [];
            }
            $values = [
                'max_upload_mb' => $this->intRange($payload['max_upload_mb'] ?? 12, 1, 200, 'media.max_upload_mb', $add),
                'allowed_mime_types' => array_values(array_unique(array_map(static fn($v): string => trim((string) $v), $mimes))),
                'auto_generate_variants' => $this->bool($payload['auto_generate_variants'] ?? true),
                'require_alt_text' => $this->bool($payload['require_alt_text'] ?? true),
                'default_folder_key' => $this->key($payload['default_folder_key'] ?? 'general', 'media.default_folder_key', $add, 80),
                'logo_media_id' => $this->nullableMediaId($payload['logo_media_id'] ?? null),
                'favicon_media_id' => $this->nullableMediaId($payload['favicon_media_id'] ?? null),
            ];
            foreach ($values['allowed_mime_types'] as $mime) {
                if (!preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $mime)) {
                    $add('media.allowed_mime_types', 'Type MIME invalide: ' . $mime);
                }
            }
            if ($values['logo_media_id'] !== null && !$this->mediaExistsForSite($siteId, $values['logo_media_id'])) {
                $add('media.logo_media_id', 'Le logo doit exister et appartenir au site courant.');
            }
            if ($values['favicon_media_id'] !== null && !$this->mediaExistsForSite($siteId, $values['favicon_media_id'])) {
                $add('media.favicon_media_id', 'Le favicon doit exister et appartenir au site courant.');
            }
        }

        if ($groupKey === 'articles') {
            $format = strtolower(trim((string) ($payload['detail_date_format'] ?? 'medium')));
            if (!in_array($format, ['short', 'medium', 'long', 'iso'], true)) {
                $add('articles.detail_date_format', 'Format de date non autorisé.');
                $format = 'medium';
            }
            $values = [
                'detail_show_published_date' => $this->bool($payload['detail_show_published_date'] ?? true),
                'detail_show_author' => $this->bool($payload['detail_show_author'] ?? true),
                'detail_show_updated_date' => $this->bool($payload['detail_show_updated_date'] ?? false),
                'detail_show_type' => $this->bool($payload['detail_show_type'] ?? true),
                'detail_include_author_in_schema' => $this->bool($payload['detail_include_author_in_schema'] ?? true),
                'detail_date_format' => $format,
            ];
        }

        if ($groupKey === 'relations') {
            $types = $payload['allowed_relation_types'] ?? [];
            if (!is_array($types) || $types === []) {
                $add('relations.allowed_relation_types', 'Au moins un type de relation est requis.');
                $types = [];
            }
            $storage = is_array($payload['media_storage'] ?? null) ? $payload['media_storage'] : [];
            $driver = strtolower(trim((string) ($storage['driver'] ?? 'local')));
            if (!in_array($driver, ['local', 's3'], true)) {
                $add('relations.media_storage.driver', 'Driver média non autorisé.');
                $driver = 'local';
            }
            $s3 = is_array($storage['s3'] ?? null) ? $storage['s3'] : [];
            $endpoint = $this->urlString($s3['endpoint'] ?? '', 'relations.media_storage.s3.endpoint', $add, true);
            $bucket = $this->s3Bucket((string) ($s3['bucket'] ?? ''), 'relations.media_storage.s3.bucket', $add, $driver === 's3');
            $region = $this->str($s3['region'] ?? 'us-east-1', 'relations.media_storage.s3.region', $add, 80, $driver !== 's3');
            $accessKey = $this->str($s3['access_key'] ?? '', 'relations.media_storage.s3.access_key', $add, 180, $driver !== 's3');
            $secretKey = $this->str($s3['secret_key'] ?? '', 'relations.media_storage.s3.secret_key', $add, 240, $driver !== 's3');
            $publicBaseUrl = $this->urlString($s3['public_base_url'] ?? '', 'relations.media_storage.s3.public_base_url', $add, true);
            $prefix = $this->storagePrefix((string) ($s3['path_prefix'] ?? ''), 'relations.media_storage.s3.path_prefix', $add);
            if ($driver === 's3') {
                foreach ([
                    'endpoint' => $endpoint,
                    'bucket' => $bucket,
                    'region' => $region,
                    'access_key' => $accessKey,
                    'secret_key' => $secretKey,
                ] as $key => $value) {
                    if ($value === '') {
                        $add('relations.media_storage.s3.' . $key, 'Champ requis pour le stockage S3.');
                    }
                }
            }
            $values = [
                'allow_cross_type_relations' => $this->bool($payload['allow_cross_type_relations'] ?? true),
                'max_related_items' => $this->intRange($payload['max_related_items'] ?? 24, 1, 200, 'relations.max_related_items', $add),
                'enable_bidirectional_hints' => $this->bool($payload['enable_bidirectional_hints'] ?? true),
                'allowed_relation_types' => array_values(array_unique(array_map(fn($v): string => $this->key((string) $v, 'relations.allowed_relation_types', $add, 60), $types))),
                'cors_allowed_origins' => $this->corsOrigins($payload['cors_allowed_origins'] ?? '', 'relations.cors_allowed_origins', $add),
                'media_storage' => [
                    'driver' => $driver,
                    's3' => [
                        'endpoint' => $endpoint,
                        'bucket' => $bucket,
                        'region' => $region !== '' ? $region : 'us-east-1',
                        'access_key' => $accessKey,
                        'secret_key' => $secretKey,
                        'public_base_url' => $publicBaseUrl,
                        'path_prefix' => $prefix,
                    ],
                ],
            ];
        }

        return ['values' => $values, 'fields' => $fields];
    }

    /** @param array<string,mixed> $values */
    public function save(string $groupKey, array $values, int $siteId, ?int $userId = null): void
    {
        $this->db->transaction(function (Database $db) use ($groupKey, $values, $siteId, $userId): void {
            if ($groupKey === 'site') {
                $db->run('UPDATE sites SET site_key = :site_key, name = :name, default_language_code = :default_language_code, is_active = :is_active, updated_at = CURRENT_TIMESTAMP WHERE id = :id', [
                    'site_key' => $values['site_key'], 'name' => $values['name'], 'default_language_code' => $values['default_language_code'], 'is_active' => $values['is_active'] ? 1 : 0, 'id' => $siteId,
                ]);
                $primary = $this->primaryDomain($siteId);
                if ($primary) {
                    $db->run('UPDATE site_domains SET host = :host, base_path = :base_path, scheme = :scheme, enforce_https = :enforce_https, updated_at = CURRENT_TIMESTAMP WHERE id = :id', [
                        'host' => $values['primary_domain_host'], 'base_path' => $values['base_path'], 'scheme' => $values['scheme'], 'enforce_https' => $values['enforce_https'] ? 1 : 0, 'id' => (int) $primary['id'],
                    ]);
                } else {
                    $db->run('INSERT INTO site_domains(site_id, host, base_path, scheme, is_primary, is_active, enforce_https) VALUES(:site_id, :host, :base_path, :scheme, 1, 1, :enforce_https)', [
                        'site_id' => $siteId, 'host' => $values['primary_domain_host'], 'base_path' => $values['base_path'], 'scheme' => $values['scheme'], 'enforce_https' => $values['enforce_https'] ? 1 : 0,
                    ]);
                }
            } elseif ($groupKey === 'languages') {
                $default = array_values(array_filter($values['enabled_languages'], static fn($v): bool => (bool) $v['is_default']))[0] ?? null;
                if (!$default) {
                    throw new \RuntimeException('Une langue par défaut est requise.');
                }

                foreach ($values['enabled_languages'] as $item) {
                    $db->run('INSERT OR IGNORE INTO languages(code, name, native_name, locale, is_default, is_active, sort_order) VALUES(:code, :name, :native_name, :locale, 0, 1, :sort_order)', [
                        'code' => $item['code'], 'name' => strtoupper((string) $item['code']), 'native_name' => strtoupper((string) $item['code']), 'locale' => $item['locale'], 'sort_order' => $item['sort_order'],
                    ]);
                }

                // L'index partiel idx_site_languages_one_default_per_site interdit deux défauts.
                // On neutralise donc explicitement l'ancien défaut avant tout upsert.
                $db->run('UPDATE site_languages SET is_default = 0, updated_at = CURRENT_TIMESTAMP WHERE site_id = :site_id', ['site_id' => $siteId]);

                foreach ($values['enabled_languages'] as $item) {
                    $db->run('INSERT INTO site_languages(site_id, language_code, locale, url_prefix, hreflang_code, fallback_language_code, is_default, is_active, is_rtl, sort_order, updated_at) VALUES(:site_id, :code, :locale, :prefix, :hreflang, :fallback, 0, :is_active, :is_rtl, :sort_order, CURRENT_TIMESTAMP) ON CONFLICT(site_id, language_code) DO UPDATE SET locale = excluded.locale, url_prefix = excluded.url_prefix, hreflang_code = excluded.hreflang_code, fallback_language_code = excluded.fallback_language_code, is_active = excluded.is_active, is_rtl = excluded.is_rtl, sort_order = excluded.sort_order, updated_at = CURRENT_TIMESTAMP', [
                        'site_id' => $siteId, 'code' => $item['code'], 'locale' => $item['locale'], 'prefix' => $item['url_prefix'], 'hreflang' => $item['hreflang_code'], 'fallback' => $item['fallback_language_code'], 'is_active' => $item['is_active'] ? 1 : 0, 'is_rtl' => $item['is_rtl'] ? 1 : 0, 'sort_order' => $item['sort_order'],
                    ]);
                }

                $db->run('UPDATE site_languages SET is_default = 1, is_active = 1, updated_at = CURRENT_TIMESTAMP WHERE site_id = :site_id AND language_code = :code', ['site_id' => $siteId, 'code' => $default['code']]);
                $db->run('UPDATE sites SET default_language_code = :code, updated_at = CURRENT_TIMESTAMP WHERE id = :site_id', ['code' => $default['code'], 'site_id' => $siteId]);
            } elseif ($groupKey === 'localization') {
                $db->run('INSERT INTO site_localizations(site_id, language_code, site_title, baseline, footer_text, default_meta_title_suffix, default_meta_description, og_default_image_media_id) VALUES(:site_id, :language_code, :site_title, :baseline, :footer_text, :suffix, :description, :media_id) ON CONFLICT(site_id, language_code) DO UPDATE SET site_title = excluded.site_title, baseline = excluded.baseline, footer_text = excluded.footer_text, default_meta_title_suffix = excluded.default_meta_title_suffix, default_meta_description = excluded.default_meta_description, og_default_image_media_id = excluded.og_default_image_media_id', [
                    'site_id' => $siteId, 'language_code' => $values['language_code'], 'site_title' => $values['site_title'], 'baseline' => $values['baseline'], 'footer_text' => $values['footer_text'], 'suffix' => $values['default_meta_title_suffix'], 'description' => $values['default_meta_description'], 'media_id' => $values['og_default_image_media_id'],
                ]);
                $this->syncLocalizationOpenGraphMediaUsage($db, $siteId, (string) $values['language_code'], $values['og_default_image_media_id'] ?? null);
            } else {
                $isPublic = in_array($groupKey, ['seo', 'public_ui', 'articles'], true);
                if ($groupKey === 'relations') {
                    $corsOrigins = is_array($values['cors_allowed_origins'] ?? null) ? $values['cors_allowed_origins'] : [];
                    $this->upsertSetting($siteId, 'api', 'cors_allowed_origins', $corsOrigins, false, $userId);
                    unset($values['cors_allowed_origins']);
                }
                $this->upsertSetting($siteId, $groupKey, 'defaults', $values, $isPublic, $userId);
            }
            $this->audit($siteId, $groupKey, $values, $userId);
        });
    }

    /** @return list<array<string,mixed>> */
    private function schemaGroups(string $uiLanguageCode): array
    {
        $isEnglish = $uiLanguageCode === 'en';
        return [
            ['key' => 'site', 'label' => $isEnglish ? 'Site' : 'Site', 'description' => $isEnglish ? 'Identity, primary domain and publication status of the site.' : 'Identité, domaine primaire et état de publication du site.', 'permission' => 'settings.manage', 'fields' => [
                $this->field('site_key', $isEnglish ? 'Site key' : 'Clé site', 'text', true, ['pattern' => '^[a-z0-9][a-z0-9_-]{0,59}$']),
                $this->field('name', $isEnglish ? 'Name' : 'Nom', 'text', true, ['max' => 120]),
                $this->field('default_language_code', $isEnglish ? 'Default content language' : 'Langue de contenu par défaut', 'language', true),
                $this->field('is_active', $isEnglish ? 'Active site' : 'Site actif', 'boolean', true),
                $this->field('primary_domain_host', $isEnglish ? 'Primary domain' : 'Domaine primaire', 'text', true),
                $this->field('base_path', $isEnglish ? 'Base path' : 'Chemin de base', 'text', false),
                $this->field('scheme', $isEnglish ? 'Scheme' : 'Schéma', 'select', true, ['options' => [['value' => 'https', 'label' => 'HTTPS'], ['value' => 'http', 'label' => 'HTTP']]]),
                $this->field('enforce_https', $isEnglish ? 'Enforce HTTPS' : 'Forcer HTTPS', 'boolean', true),
            ]],
            ['key' => 'languages', 'label' => $isEnglish ? 'Content languages' : 'Langues de contenu', 'description' => $isEnglish ? 'Enabled content languages, URL prefixes, hreflang and fallback.' : 'Langues de contenu activées, préfixes URL, hreflang et fallback.', 'permission' => 'settings.manage', 'fields' => [$this->field('enabled_languages', $isEnglish ? 'Enabled content languages' : 'Langues de contenu activées', 'array', true)]],
            ['key' => 'backoffice', 'label' => $isEnglish ? 'Back office' : 'Backoffice', 'description' => $isEnglish ? 'Administration interface preferences. This does not change the content language.' : 'Préférences de l’interface d’administration. Cela ne change pas la langue du contenu.', 'permission' => 'settings.manage', 'fields' => [
                $this->field('admin_ui_language_code', $isEnglish ? 'Interface language' : 'Langue de l’interface', 'select', true, ['options' => [['value' => 'fr', 'label' => 'Français'], ['value' => 'en', 'label' => 'English']]]),
            ]],
            ['key' => 'localization', 'label' => $isEnglish ? 'Localization' : 'Localisation', 'description' => $isEnglish ? 'Localized texts and editorial metadata by content language.' : 'Textes localisés et métadonnées éditoriales par langue de contenu.', 'permission' => 'settings.manage', 'fields' => [
                $this->field('site_title', $isEnglish ? 'Site title' : 'Titre du site', 'text', true),
                $this->field('baseline', $isEnglish ? 'Baseline' : 'Baseline', 'text', false),
                $this->field('footer_text', $isEnglish ? 'Footer text' : 'Texte de pied de page', 'textarea', false),
                $this->field('default_meta_title_suffix', $isEnglish ? 'SEO title suffix' : 'Suffixe de titre SEO', 'text', false),
                $this->field('default_meta_description', $isEnglish ? 'Default SEO description' : 'Description SEO par défaut', 'textarea', false),
                $this->field('og_default_image_media_id', $isEnglish ? 'Default OpenGraph image' : 'Image OpenGraph par défaut', 'media', false),
            ]],
            ['key' => 'seo', 'label' => 'SEO', 'description' => $isEnglish ? 'Native defaults for robots, sitemap, canonical and metadata lengths.' : 'Règles natives par défaut pour robots, sitemap, canonical et longueurs.', 'permission' => 'seo.manage', 'fields' => [
                $this->field('robots', $isEnglish ? 'Default robots' : 'Robots par défaut', 'select', true, ['options' => [['value' => 'index,follow', 'label' => 'index,follow'], ['value' => 'noindex,follow', 'label' => 'noindex,follow'], ['value' => 'index,nofollow', 'label' => 'index,nofollow'], ['value' => 'noindex,nofollow', 'label' => 'noindex,nofollow']]]),
                $this->field('sitemap_enabled', $isEnglish ? 'Sitemap enabled' : 'Sitemap actif', 'boolean', true), $this->field('canonical_enabled', $isEnglish ? 'Canonical enabled' : 'Canonical actif', 'boolean', true), $this->field('title_max_length', $isEnglish ? 'Recommended title length' : 'Longueur titre recommandée', 'number', true, ['min' => 30, 'max' => 90]), $this->field('description_max_length', $isEnglish ? 'Recommended description length' : 'Longueur description recommandée', 'number', true, ['min' => 80, 'max' => 240]),
            ]],
            ['key' => 'public_ui', 'label' => $isEnglish ? 'Appearance' : 'Apparence', 'description' => $isEnglish ? 'Public theme, fonts, main heading readability, base colors and navigation options.' : 'Thème public, polices, lisibilité des titres, couleurs de base et options de navigation.', 'permission' => 'settings.manage', 'fields' => [
                $this->field('active_theme_key', $isEnglish ? 'Active public template' : 'Template public actif', 'select', true, ['options' => $this->themeOptions(), 'preview_query_parameter' => '_theme', 'appearance_defaults' => $this->themeAppearanceDefaults()]),
                $this->field('body_font_family', $isEnglish ? 'Body font' : 'Police du texte', 'select', true, ['options' => $this->fontOptions($isEnglish)]),
                $this->field('heading_font_family', $isEnglish ? 'Heading font' : 'Police des titres', 'select', true, ['options' => $this->fontOptions($isEnglish)]),
                $this->field('main_heading_font_family', $isEnglish ? 'Main title font' : 'Police du titre principal H1', 'select', true, ['options' => $this->mainHeadingFontOptions($isEnglish)]),
                $this->field('main_heading_letter_spacing', $isEnglish ? 'Main title letter spacing' : 'Espacement du titre principal H1', 'select', true, ['options' => $this->mainHeadingLetterSpacingOptions($isEnglish)]),
                $this->field('color_background', $isEnglish ? 'Background color' : 'Couleur de fond', 'color', true),
                $this->field('color_surface', $isEnglish ? 'Surface color' : 'Couleur des surfaces', 'color', true),
                $this->field('color_text', $isEnglish ? 'Text color' : 'Couleur du texte', 'color', true),
                $this->field('color_muted', $isEnglish ? 'Muted text color' : 'Couleur du texte secondaire', 'color', true),
                $this->field('color_primary', $isEnglish ? 'Primary color' : 'Couleur principale', 'color', true),
                $this->field('color_accent', $isEnglish ? 'Accent color' : 'Couleur d’accent', 'color', true),
                $this->field('show_login_shortcut', $isEnglish ? 'Show login icon next to languages' : 'Afficher l’icône de connexion à droite des langues', 'boolean', false),
                $this->field('show_site_title_in_header', $isEnglish ? 'Show site title in header' : 'Afficher le titre du site dans la barre de menu', 'boolean', false),
            ]],
            ['key' => 'media', 'label' => $isEnglish ? 'Media' : 'Médias', 'description' => $isEnglish ? 'Server constraints for uploads, MIME types, variants and accessibility.' : 'Contraintes serveur pour upload, MIME, variantes et accessibilité.', 'permission' => 'media.update', 'fields' => [
                $this->field('logo_media_id', $isEnglish ? 'Site logo' : 'Logo du site', 'site_asset', false, ['asset_kind' => 'logo', 'accept' => 'image/png,image/jpeg,image/webp,image/gif']), $this->field('favicon_media_id', $isEnglish ? 'Favicon' : 'Favicon', 'site_asset', false, ['asset_kind' => 'favicon', 'accept' => 'image/png,image/jpeg,image/webp,image/gif']), $this->field('max_upload_mb', $isEnglish ? 'Maximum upload size' : 'Taille maximale upload', 'number', true, ['min' => 1, 'max' => 200]), $this->field('allowed_mime_types', $isEnglish ? 'Allowed MIME types' : 'Types MIME autorisés', 'array', true), $this->field('auto_generate_variants', $isEnglish ? 'Generate variants' : 'Générer les variantes', 'boolean', true), $this->field('require_alt_text', $isEnglish ? 'Alt text required' : 'Alt text requis', 'boolean', true), $this->field('default_folder_key', $isEnglish ? 'Default folder' : 'Dossier par défaut', 'text', true),
            ]],
            ['key' => 'articles', 'label' => $isEnglish ? 'Articles' : 'Articles', 'description' => $isEnglish ? 'Visible article metadata, date format and JSON-LD author policy used by article detail pages and article listings. Individual articles only override these rules when custom display settings are enabled.' : 'Métadonnées visibles des articles, format de date et politique auteur JSON-LD utilisés par les pages détail et les listes d’articles. Un article ne surcharge ces règles que si ses réglages d’affichage personnalisés sont activés.', 'permission' => 'settings.manage', 'fields' => [
                $this->field('detail_show_published_date', $isEnglish ? 'Show publication date' : 'Afficher la date de publication', 'boolean', false),
                $this->field('detail_show_author', $isEnglish ? 'Show author' : 'Afficher l’auteur', 'boolean', false),
                $this->field('detail_show_updated_date', $isEnglish ? 'Show update date' : 'Afficher la date de mise à jour', 'boolean', false),
                $this->field('detail_show_type', $isEnglish ? 'Show content type' : 'Afficher le type de contenu', 'boolean', false),
                $this->field('detail_include_author_in_schema', $isEnglish ? 'Keep author in JSON-LD schema' : 'Conserver l’auteur dans le JSON-LD', 'boolean', false),
                $this->field('detail_date_format', $isEnglish ? 'Date format' : 'Format de date', 'select', true, ['options' => [
                    ['value' => 'short', 'label' => $isEnglish ? 'Short — 27.05.2026' : 'Court — 27.05.2026'],
                    ['value' => 'medium', 'label' => $isEnglish ? 'Medium — 27 May 2026' : 'Moyen — 27 mai 2026'],
                    ['value' => 'long', 'label' => $isEnglish ? 'Long — Wednesday 27 May 2026' : 'Long — mercredi 27 mai 2026'],
                    ['value' => 'iso', 'label' => 'ISO — 2026-05-27'],
                ]]),
            ]],
            ['key' => 'relations', 'label' => 'Relations', 'description' => $isEnglish ? 'Default contracts for content relations and media object storage.' : 'Contrats par défaut des relations entre contenus et stockage objet des médias.', 'permission' => 'fields.manage', 'fields' => [
                $this->field('allow_cross_type_relations', $isEnglish ? 'Cross-type relations' : 'Relations entre types', 'boolean', true),
                $this->field('max_related_items', $isEnglish ? 'Maximum related items' : 'Nombre maximum de relations', 'number', true, ['min' => 1, 'max' => 200]),
                $this->field('enable_bidirectional_hints', $isEnglish ? 'Bidirectional hints' : 'Hints bidirectionnels', 'boolean', true),
                $this->field('allowed_relation_types', $isEnglish ? 'Allowed relation types' : 'Types de relation autorisés', 'array', true),
                $this->field('media_storage', $isEnglish ? 'Media storage' : 'Stockage médias', 'object', false, ['interface' => 'media_storage', 'driver_options' => [['value' => 'local', 'label' => 'Local'], ['value' => 's3', 'label' => 'S3 compatible']], 's3_fields' => ['endpoint', 'bucket', 'region', 'access_key', 'secret_key', 'public_base_url', 'path_prefix']]),
                $this->field('cors_allowed_origins', $isEnglish ? 'CORS by site' : 'CORS par site', 'textarea', false, ['placeholder' => 'https://front.example.ch', 'description' => $isEnglish ? 'Origins authorized for the public headless API, integrated into the Relations tab.' : 'Origines autorisées pour l’API headless publique, intégrées à l’onglet Relations.', 'system_blueprint_handle' => 'security_cors']),
            ]],
        ];
    }


    /** @return list<array{value:string,label:string}> */
    private function fontOptions(bool $isEnglish): array
    {
        return [
            ['value' => 'system', 'label' => $isEnglish ? 'System sans-serif' : 'Sans-serif système'],
            ['value' => 'serif', 'label' => $isEnglish ? 'Serif' : 'Serif'],
            ['value' => 'slab', 'label' => $isEnglish ? 'Slab serif' : 'Slab serif'],
            ['value' => 'mono', 'label' => $isEnglish ? 'Monospace' : 'Monospace'],
            ['value' => 'arial', 'label' => 'Arial'],
        ];
    }

    private function fontFamily(mixed $value, string $field, callable $add): string
    {
        $key = (string) ($value ?: 'system');
        if (!in_array($key, ['system', 'serif', 'slab', 'mono', 'arial'], true)) {
            $add($field, 'Police non autorisée.');
            return 'system';
        }
        return $key;
    }


    /** @return list<array{value:string,label:string}> */
    private function mainHeadingFontOptions(bool $isEnglish): array
    {
        return array_merge(
            [['value' => 'heading', 'label' => $isEnglish ? 'Same as heading font' : 'Identique à la police des titres']],
            $this->fontOptions($isEnglish)
        );
    }

    private function mainHeadingFontFamily(mixed $value, string $field, callable $add): string
    {
        $key = (string) ($value ?: 'heading');
        if ($key === 'heading') {
            return 'heading';
        }
        return $this->fontFamily($key, $field, $add);
    }

    /** @return list<array{value:string,label:string}> */
    private function mainHeadingLetterSpacingOptions(bool $isEnglish): array
    {
        return [
            ['value' => 'normal', 'label' => $isEnglish ? 'Normal — readable default' : 'Normal — défaut lisible'],
            ['value' => 'relaxed', 'label' => $isEnglish ? 'Slightly relaxed' : 'Légèrement aéré'],
            ['value' => 'airy', 'label' => $isEnglish ? 'Airy' : 'Aéré'],
        ];
    }

    private function mainHeadingLetterSpacing(mixed $value, string $field, callable $add): string
    {
        $key = (string) ($value ?: 'normal');
        if (!in_array($key, ['normal', 'relaxed', 'airy'], true)) {
            $add($field, 'Espacement non autorisé.');
            return 'normal';
        }
        return $key;
    }

    private function mainHeadingLetterSpacingCss(string $key): string
    {
        return match ($key) {
            'relaxed' => '.012em',
            'airy' => '.025em',
            default => '0em',
        };
    }

    private function mainHeadingFontStack(string $fontKey): string
    {
        return $fontKey === 'heading' ? 'var(--font-heading)' : $this->cssFontStack($fontKey);
    }

    private function hexColor(mixed $value, string $field, callable $add): string
    {
        $color = trim((string) ($value ?: ''));
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $add($field, 'Couleur hexadécimale invalide.');
            return (string) (self::PUBLIC_UI_DEFAULTS[substr($field, strlen('public_ui.'))] ?? '#000000');
        }
        return strtolower($color);
    }

    private function cssFontStack(string $fontKey): string
    {
        return match ($fontKey) {
            'serif' => 'Georgia, Cambria, "Times New Roman", Times, serif',
            'slab' => 'Roboto Slab, Rockwell, "Courier Bold", serif',
            'mono' => 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace',
            'arial' => 'Arial, Helvetica, sans-serif',
            default => 'Inter, ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif',
        };
    }

    /** @param array<string,mixed> $values */
    public function appearanceCss(array $values): string
    {
        $merged = array_replace(self::PUBLIC_UI_DEFAULTS, $values);
        $cssVars = [
            '--bg' => $this->hexColor($merged['color_background'] ?? '#fbfcfe', 'public_ui.color_background', static function (): void {}),
            '--surface' => $this->hexColor($merged['color_surface'] ?? '#ffffff', 'public_ui.color_surface', static function (): void {}),
            '--text' => $this->hexColor($merged['color_text'] ?? '#102033', 'public_ui.color_text', static function (): void {}),
            '--muted' => $this->hexColor($merged['color_muted'] ?? '#627086', 'public_ui.color_muted', static function (): void {}),
            '--brand' => $this->hexColor($merged['color_primary'] ?? '#1b5fc1', 'public_ui.color_primary', static function (): void {}),
            '--brand-dark' => $this->hexColor($merged['color_primary'] ?? '#1b5fc1', 'public_ui.color_primary', static function (): void {}),
            '--accent' => $this->hexColor($merged['color_accent'] ?? '#ffb21e', 'public_ui.color_accent', static function (): void {}),
            '--font-body' => $this->cssFontStack($this->fontFamily($merged['body_font_family'] ?? 'system', 'public_ui.body_font_family', static function (): void {})),
            '--font-heading' => $this->cssFontStack($this->fontFamily($merged['heading_font_family'] ?? 'system', 'public_ui.heading_font_family', static function (): void {})),
            '--font-main-heading' => $this->mainHeadingFontStack($this->mainHeadingFontFamily($merged['main_heading_font_family'] ?? 'heading', 'public_ui.main_heading_font_family', static function (): void {})),
            '--main-heading-letter-spacing' => $this->mainHeadingLetterSpacingCss($this->mainHeadingLetterSpacing($merged['main_heading_letter_spacing'] ?? 'normal', 'public_ui.main_heading_letter_spacing', static function (): void {})),
        ];
        $declarations = [];
        foreach ($cssVars as $name => $value) {
            $declarations[] = $name . ':' . $value;
        }
        return ':root{' . implode(';', $declarations) . '}body{font-family:var(--font-body)}h1,h2,h3,h4,h5,h6,.brand{font-family:var(--font-heading)}body:not(#amcms-appearance-override) h1{font-family:var(--font-main-heading)!important;letter-spacing:var(--main-heading-letter-spacing)!important}';
    }


    /** @return array<string,array<string,mixed>> */
    private function themeAppearanceDefaults(): array
    {
        $defaults = self::THEME_APPEARANCE_DEFAULTS;
        $rows = $this->db->all("SELECT theme_key, config_json FROM themes WHERE is_active = 1 ORDER BY is_default DESC, name ASC, theme_key ASC");
        foreach ($rows as $row) {
            $key = trim((string) ($row['theme_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $config = json_decode((string) ($row['config_json'] ?? '{}'), true);
            $appearance = is_array($config) && isset($config['appearance_defaults']) && is_array($config['appearance_defaults'])
                ? $config['appearance_defaults']
                : [];
            $clean = [];
            foreach (['body_font_family', 'heading_font_family', 'main_heading_font_family', 'main_heading_letter_spacing', 'show_site_title_in_header', 'color_background', 'color_surface', 'color_text', 'color_muted', 'color_primary', 'color_accent'] as $field) {
                if (array_key_exists($field, $appearance)) {
                    $clean[$field] = $field === 'show_site_title_in_header' ? filter_var($appearance[$field], FILTER_VALIDATE_BOOLEAN) : (string) $appearance[$field];
                }
            }
            if ($clean !== []) {
                $defaults[$key] = array_replace($defaults[$key] ?? [], $clean);
            }
        }
        return $defaults;
    }

    /** @return list<array{value:string,label:string}> */
    private function themeOptions(): array
    {
        $rows = $this->db->all("SELECT theme_key, name FROM themes WHERE is_active = 1 ORDER BY is_default DESC, name ASC, theme_key ASC");
        $options = [];
        foreach ($rows as $row) {
            $key = trim((string) ($row['theme_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $options[] = ['value' => $key, 'label' => (string) ($row['name'] ?? $key)];
        }
        return $options !== [] ? $options : [['value' => 'default', 'label' => 'Default Theme']];
    }

    private function themeKey(mixed $value, string $field, callable $add): string
    {
        $key = $this->key($value ?: 'default', $field, $add, 80);
        $exists = (bool) $this->db->one('SELECT 1 FROM themes WHERE theme_key = :key AND is_active = 1 LIMIT 1', ['key' => $key]);
        if (!$exists) {
            $add($field, 'Template public inconnu ou inactif.');
            return 'default';
        }
        return $key;
    }

    private function field(string $key, string $label, string $type, bool $required, array $validation = []): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type, 'required' => $required, 'validation' => $validation];
    }

    private function site(int $siteId): array { return $this->db->one('SELECT * FROM sites WHERE id = :id', ['id' => $siteId]) ?? []; }
    private function primaryDomain(int $siteId): ?array { return $this->db->one('SELECT * FROM site_domains WHERE site_id = :site_id AND is_primary = 1 ORDER BY id LIMIT 1', ['site_id' => $siteId]); }
    private function localization(int $siteId, string $languageCode): ?array { return $this->db->one('SELECT * FROM site_localizations WHERE site_id = :site_id AND language_code = :language_code', ['site_id' => $siteId, 'language_code' => $languageCode]); }

    private function languageExistsForSite(int $siteId, string $code): bool
    {
        return (bool) $this->db->one('SELECT 1 FROM site_languages WHERE site_id = :site_id AND language_code = :code AND is_active = 1', ['site_id' => $siteId, 'code' => $code]);
    }

    private function mediaExistsForSite(int $siteId, int $mediaId): bool
    {
        return (bool) $this->db->one("SELECT 1 FROM media_assets WHERE id = :id AND site_id = :site_id AND lifecycle_status != 'deleted'", ['id' => $mediaId, 'site_id' => $siteId]);
    }

    private function syncLocalizationOpenGraphMediaUsage(Database $db, int $siteId, string $languageCode, mixed $mediaId): void
    {
        $fieldKey = 'localization.og_default_image_media_id';
        $db->run(
            "DELETE FROM media_usages
             WHERE site_id = :site_id
               AND resource_type = 'system'
               AND resource_id = 0
               AND field_key = :field_key
               AND language_code = :language_code
               AND usage_context = 'opengraph'",
            ['site_id' => $siteId, 'field_key' => $fieldKey, 'language_code' => $languageCode]
        );

        $mediaId = $this->nullableMediaId($mediaId);
        if ($mediaId === null) {
            return;
        }

        $asset = $db->one(
            "SELECT id FROM media_assets WHERE id = :id AND site_id = :site_id AND lifecycle_status != 'deleted' LIMIT 1",
            ['id' => $mediaId, 'site_id' => $siteId]
        );
        if (!$asset) {
            return;
        }

        $usageHash = hash('sha256', implode('|', [$siteId, $mediaId, 'system', 0, $fieldKey, $languageCode, 'opengraph']));
        $alt = $this->localizedAlt($db, $mediaId, $languageCode);
        $db->run(
            "INSERT INTO media_usages (
                media_id, site_id, resource_type, resource_id, field_key, language_code, usage_context,
                source_revision_id, alt_policy, alt_text_snapshot, usage_hash, created_at, updated_at
             ) VALUES (
                :media_id, :site_id, 'system', 0, :field_key, :language_code, 'opengraph',
                NULL, 'required', :alt_text_snapshot, :usage_hash, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             )
             ON CONFLICT(usage_hash) DO UPDATE SET
                media_id = excluded.media_id,
                site_id = excluded.site_id,
                field_key = excluded.field_key,
                language_code = excluded.language_code,
                usage_context = excluded.usage_context,
                alt_policy = excluded.alt_policy,
                alt_text_snapshot = excluded.alt_text_snapshot,
                updated_at = CURRENT_TIMESTAMP",
            [
                'media_id' => $mediaId,
                'site_id' => $siteId,
                'field_key' => $fieldKey,
                'language_code' => $languageCode,
                'alt_text_snapshot' => $alt,
                'usage_hash' => $usageHash,
            ]
        );
    }

    private function localizedAlt(Database $db, int $mediaId, string $languageCode): ?string
    {
        $row = $db->one(
            'SELECT alt_text FROM media_asset_localizations WHERE media_id = :media_id AND language_code = :language_code LIMIT 1',
            ['media_id' => $mediaId, 'language_code' => $languageCode]
        );
        $alt = trim((string) ($row['alt_text'] ?? ''));
        return $alt === '' ? null : $alt;
    }

    private function nullableMediaId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        return max(1, (int) $value);
    }

    private function languages(int $siteId): array
    {
        return array_map(static fn(array $row): array => [
            'code' => (string) $row['language_code'], 'name' => (string) ($row['name'] ?? $row['language_code']), 'locale' => (string) ($row['locale'] ?? ''), 'url_prefix' => (string) ($row['url_prefix'] ?? ''), 'hreflang_code' => (string) ($row['hreflang_code'] ?? ''), 'fallback_language_code' => $row['fallback_language_code'] ?? null, 'is_default' => (bool) $row['is_default'], 'is_active' => (bool) $row['is_active'], 'is_rtl' => (bool) $row['is_rtl'], 'sort_order' => (int) $row['sort_order'],
        ], $this->db->all('SELECT sl.*, l.name FROM site_languages sl LEFT JOIN languages l ON l.code = sl.language_code WHERE sl.site_id = :site_id ORDER BY sl.sort_order, sl.language_code', ['site_id' => $siteId]));
    }

    private function settings(int $siteId): array
    {
        $out = [];
        foreach ($this->db->all('SELECT namespace, setting_key, value_json FROM site_settings WHERE site_id = :site_id', ['site_id' => $siteId]) as $row) {
            $out[(string) $row['namespace']][(string) $row['setting_key']] = json_decode((string) $row['value_json'], true) ?: [];
        }
        return $out;
    }

    private function upsertSetting(int $siteId, string $namespace, string $key, array $value, bool $public, ?int $userId): void
    {
        $json = json_encode($value, self::JSON_FLAGS);
        $this->db->run('INSERT INTO site_settings(site_id, namespace, setting_key, value_json, is_public, updated_by_iam_user_id, updated_at) VALUES(:site_id, :namespace, :setting_key, :value_json, :is_public, :user_id, CURRENT_TIMESTAMP) ON CONFLICT(site_id, namespace, setting_key) DO UPDATE SET value_json = excluded.value_json, is_public = excluded.is_public, updated_by_iam_user_id = excluded.updated_by_iam_user_id, updated_at = CURRENT_TIMESTAMP', [
            'site_id' => $siteId, 'namespace' => $namespace, 'setting_key' => $key, 'value_json' => $json, 'is_public' => $public ? 1 : 0, 'user_id' => $userId,
        ]);
    }

    private function audit(int $siteId, string $groupKey, array $values, ?int $userId): void
    {
        $this->db->run('INSERT INTO configuration_revisions(site_id, group_key, value_json, updated_by_iam_user_id) VALUES(:site_id, :group_key, :value_json, :user_id)', [
            'site_id' => $siteId, 'group_key' => $groupKey, 'value_json' => json_encode($values, self::JSON_FLAGS), 'user_id' => $userId,
        ]);
    }

    private function str(mixed $value, string $field, callable $add, int $max, bool $allowEmpty): string
    {
        $v = trim(str_replace("\0", '', (string) $value));
        if (!$allowEmpty && $v === '') { $add($field, 'Champ obligatoire.'); }
        if ($this->strlen($v) > $max) { $add($field, 'Longueur maximale dépassée.'); }
        return $this->substr($v, 0, $max);
    }
    private function key(mixed $value, string $field, callable $add, int $max): string { $v = $this->str($value, $field, $add, $max, false); if ($v !== '' && !preg_match('/^[a-z0-9][a-z0-9_-]{0,' . ($max - 1) . '}$/i', $v)) { $add($field, 'Clé invalide.'); } return $v; }
    private function language(mixed $value, string $field, callable $add): string { $v = trim((string) $value); if (!preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $v)) { $add($field, 'Code langue invalide.'); } return $v; }
    private function bool(mixed $value): bool { return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false; }
    private function intRange(mixed $value, int $min, int $max, string $field, callable $add): int { $i = (int) $value; if ($i < $min || $i > $max) { $add($field, "La valeur doit être entre $min et $max."); } return max($min, min($max, $i)); }
    private function host(mixed $value, string $field, callable $add): string { $v = strtolower($this->str($value, $field, $add, 255, false)); if ($v !== '' && !preg_match('/^[a-z0-9.-]+(?::[0-9]{2,5})?$/', $v)) { $add($field, 'Domaine invalide.'); } return $v; }
    private function basePath(mixed $value, string $field, callable $add): string { $v = trim((string) $value); if ($v === '/') { return ''; } if ($v !== '' && (!str_starts_with($v, '/') || str_ends_with($v, '/') || str_contains($v, '//') || str_contains($v, ' '))) { $add($field, 'Le chemin doit être vide ou commencer par /, sans slash final.'); } return $v; }

    private function urlString(mixed $value, string $field, callable $add, bool $allowEmpty): string
    {
        $v = $this->str($value, $field, $add, 500, $allowEmpty);
        if ($v !== '' && !preg_match('#^https?://[^\s]+$#i', $v)) {
            $add($field, 'URL HTTP(S) invalide.');
        }
        return rtrim($v, '/');
    }

    private function s3Bucket(string $value, string $field, callable $add, bool $required): string
    {
        $v = strtolower(trim($value));
        if ($required && $v === '') { $add($field, 'Champ obligatoire.'); }
        if ($v !== '' && !preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $v)) {
            $add($field, 'Bucket S3 invalide.');
        }
        return $v;
    }

    private function storagePrefix(string $value, string $field, callable $add): string
    {
        $v = trim(str_replace('\\', '/', $value), '/');
        if ($v !== '' && (str_contains($v, '..') || preg_match('/[^a-zA-Z0-9._\/-]/', $v))) {
            $add($field, 'Préfixe de chemin invalide.');
            return '';
        }
        return preg_replace('#/+#', '/', $v) ?: '';
    }

    /** @return list<string> */
    private function corsOrigins(mixed $value, string $field, callable $add): array
    {
        $items = is_array($value) ? $value : preg_split('/\R+/', (string) $value);
        $origins = [];
        foreach ($items ?: [] as $item) {
            $origin = rtrim(trim((string) $item), '/');
            if ($origin === '') { continue; }
            if (!preg_match('#^https?://[^/\s]+(?::[0-9]{2,5})?$#i', $origin)) {
                $add($field, 'Origine CORS invalide: ' . $origin);
                continue;
            }
            $origins[] = $origin;
        }
        return array_values(array_unique($origins));
    }
    private function strlen(string $value): int { return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value); }
    private function substr(string $value, int $start, int $length): string { return function_exists('mb_substr') ? mb_substr($value, $start, $length) : substr($value, $start, $length); }
}
