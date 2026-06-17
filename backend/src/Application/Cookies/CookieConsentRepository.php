<?php

declare(strict_types=1);

namespace App\Application\Cookies;

use App\Core\Database;
use InvalidArgumentException;

final class CookieConsentRepository
{
    private const DEFAULT_CATEGORIES = [
        'necessary' => ['required' => 1, 'enabled' => 1, 'preselected' => 1, 'order' => 10],
        'preferences' => ['required' => 0, 'enabled' => 0, 'preselected' => 0, 'order' => 20],
        'statistics' => ['required' => 0, 'enabled' => 0, 'preselected' => 0, 'order' => 30],
        'marketing' => ['required' => 0, 'enabled' => 0, 'preselected' => 0, 'order' => 40],
        'external_media' => ['required' => 0, 'enabled' => 0, 'preselected' => 0, 'order' => 50],
    ];

    public function __construct(private readonly Database $db) {}

    public function ensureSiteDefaults(int $siteId, array $languages, string $defaultLanguage = 'fr'): void
    {
        if ($siteId <= 0) { return; }
        $languages = $this->normalizeLanguages($languages, $defaultLanguage);
        $setting = $this->setting($siteId);
        if (!$setting) {
            $this->db->run(
                'INSERT INTO cookie_banner_settings(site_id, consent_version, cookie_name, consent_lifetime_days, log_retention_days, is_enabled, show_floating_button) VALUES(?,?,?,?,?,?,?)',
                [$siteId, gmdate('Y-m-d'), 'amcms_cookie_consent_' . $siteId, 180, 180, 1, 1]
            );
            $setting = $this->setting($siteId);
        }
        $settingId = (int)($setting['id'] ?? 0);
        foreach ($languages as $lang) {
            $existing = $this->db->one('SELECT id FROM cookie_banner_translations WHERE setting_id=? AND language_code=? LIMIT 1', [$settingId, strtolower($lang)]);
            if (!$existing) {
                $this->upsertBannerTranslation($settingId, $lang, $this->defaultBannerTexts($lang));
            }
        }
        foreach (self::DEFAULT_CATEGORIES as $key => $meta) {
            $row = $this->db->one('SELECT id FROM cookie_categories WHERE site_id = ? AND category_key = ? LIMIT 1', [$siteId, $key]);
            if (!$row) {
                $this->db->run(
                    'INSERT INTO cookie_categories(site_id, category_key, is_required, is_enabled, is_preselected, sort_order) VALUES(?,?,?,?,?,?)',
                    [$siteId, $key, $meta['required'], $meta['enabled'], $meta['preselected'], $meta['order']]
                );
                $categoryId = $this->db->lastInsertId();
            } else {
                $categoryId = (int)$row['id'];
            }
            foreach ($languages as $lang) {
                $existingTranslation = $this->db->one('SELECT id FROM cookie_category_translations WHERE category_id=? AND language_code=? LIMIT 1', [$categoryId, strtolower($lang)]);
                if (!$existingTranslation) {
                    $this->upsertCategoryTranslation($categoryId, $lang, $this->defaultCategoryTexts($key, $lang));
                }
            }
        }
    }

    public function publicConfig(int $siteId, string $languageCode, string $defaultLanguage = 'fr'): array
    {
        $setting = $this->setting($siteId);
        if (!$setting || (int)($setting['is_enabled'] ?? 0) !== 1) {
            return ['enabled' => false, 'setting' => null, 'texts' => [], 'categories' => [], 'services' => [], 'script_bindings' => []];
        }
        $settingId = (int)$setting['id'];
        $texts = $this->bannerTexts($settingId, $languageCode, $defaultLanguage);
        $categories = $this->categories($siteId, $languageCode, $defaultLanguage, true);
        $services = $this->services($siteId, $languageCode, $defaultLanguage, true);
        $bindings = $this->scriptBindings($siteId, true);
        $hasOptionalChoices = $this->hasOptionalChoices($categories);
        return [
            'enabled' => true,
            'setting' => $this->sanitizeSetting($setting),
            'texts' => $texts,
            'categories' => $categories,
            'services' => $services,
            'script_bindings' => $bindings,
            'has_optional_choices' => $hasOptionalChoices,
        ];
    }

    public function adminState(int $siteId, string $languageCode, string $defaultLanguage = 'fr'): array
    {
        $setting = $this->setting($siteId) ?? [];
        return [
            'setting' => $this->sanitizeSetting($setting),
            'texts' => $setting ? $this->bannerTexts((int)$setting['id'], $languageCode, $defaultLanguage) : [],
            'categories' => $this->categories($siteId, $languageCode, $defaultLanguage, false),
            'services' => $this->services($siteId, $languageCode, $defaultLanguage, false),
            'script_bindings' => $this->scriptBindings($siteId, false),
            'logs' => $this->recentLogs($siteId, 50),
        ];
    }

    public function setting(int $siteId): ?array
    {
        return $this->db->one('SELECT * FROM cookie_banner_settings WHERE site_id = ? LIMIT 1', [$siteId]);
    }

    public function updateSetting(int $siteId, array $payload, string $languageCode): array
    {
        $this->ensureSiteDefaults($siteId, [$languageCode], $languageCode);
        $current = $this->setting($siteId);
        if (!$current) { throw new InvalidArgumentException('Réglage cookies introuvable.'); }
        $this->db->run(
            'UPDATE cookie_banner_settings SET is_enabled=?, consent_version=?, cookie_name=?, consent_lifetime_days=?, log_retention_days=?, banner_position=?, theme=?, accent_color=?, show_floating_button=?, reject_equal_prominence=?, respect_dnt=?, updated_at=CURRENT_TIMESTAMP WHERE site_id=?',
            [
                $this->bool($payload['is_enabled'] ?? $current['is_enabled']),
                $this->text($payload['consent_version'] ?? $current['consent_version'], 80) ?: gmdate('Y-m-d'),
                $this->key($payload['cookie_name'] ?? $current['cookie_name'], true),
                $this->intRange($payload['consent_lifetime_days'] ?? $current['consent_lifetime_days'], 1, 730),
                $this->intRange($payload['log_retention_days'] ?? $current['log_retention_days'], 1, 1095),
                $this->enum($payload['banner_position'] ?? $current['banner_position'], ['bottom','top','modal'], 'bottom'),
                $this->enum($payload['theme'] ?? $current['theme'], ['auto','light','dark'], 'auto'),
                $this->text($payload['accent_color'] ?? $current['accent_color'], 40),
                $this->bool($payload['show_floating_button'] ?? $current['show_floating_button']),
                $this->bool($payload['reject_equal_prominence'] ?? $current['reject_equal_prominence']),
                $this->bool($payload['respect_dnt'] ?? $current['respect_dnt']),
                $siteId,
            ]
        );
        $setting = $this->setting($siteId) ?? [];
        if (isset($payload['texts']) && is_array($payload['texts'])) {
            $this->upsertBannerTranslation((int)$setting['id'], $languageCode, $payload['texts']);
        }
        return $this->adminState($siteId, $languageCode, $languageCode);
    }

    public function saveCategory(int $siteId, ?int $id, array $payload, string $languageCode): array
    {
        $key = $this->key($payload['category_key'] ?? '');
        if ($id === null && $key === '') { throw new InvalidArgumentException('La clé de catégorie est obligatoire.'); }
        if ($id === null) {
            $this->db->run('INSERT INTO cookie_categories(site_id, category_key, is_required, is_enabled, is_preselected, sort_order) VALUES(?,?,?,?,?,?)', [
                $siteId, $key, $this->bool($payload['is_required'] ?? 0), $this->bool($payload['is_enabled'] ?? 1), $this->bool($payload['is_preselected'] ?? 0), (int)($payload['sort_order'] ?? 0),
            ]);
            $id = $this->db->lastInsertId();
        } else {
            $current = $this->db->one('SELECT * FROM cookie_categories WHERE site_id=? AND id=?', [$siteId, $id]);
            if (!$current) { throw new InvalidArgumentException('Catégorie introuvable.'); }
            $required = $this->bool($payload['is_required'] ?? $current['is_required']);
            $this->db->run('UPDATE cookie_categories SET is_required=?, is_enabled=?, is_preselected=?, sort_order=?, updated_at=CURRENT_TIMESTAMP WHERE site_id=? AND id=?', [
                $required, $required ? 1 : $this->bool($payload['is_enabled'] ?? $current['is_enabled']), $required ? 1 : $this->bool($payload['is_preselected'] ?? $current['is_preselected']), (int)($payload['sort_order'] ?? $current['sort_order']), $siteId, $id,
            ]);
        }
        $this->upsertCategoryTranslation($id, $languageCode, $payload);
        return $this->adminState($siteId, $languageCode, $languageCode);
    }

    public function deleteCategory(int $siteId, int $id): void
    {
        $required = (int)($this->db->one('SELECT is_required FROM cookie_categories WHERE site_id=? AND id=?', [$siteId, $id])['is_required'] ?? 0);
        if ($required === 1) { throw new InvalidArgumentException('Une catégorie nécessaire ne peut pas être supprimée.'); }
        $this->db->run('DELETE FROM cookie_categories WHERE site_id=? AND id=?', [$siteId, $id]);
    }

    public function saveService(int $siteId, ?int $id, array $payload, string $languageCode): array
    {
        $categoryId = (int)($payload['category_id'] ?? 0);
        if ($categoryId <= 0 || !$this->db->one('SELECT id FROM cookie_categories WHERE site_id=? AND id=?', [$siteId, $categoryId])) {
            throw new InvalidArgumentException('Catégorie invalide.');
        }
        $key = $this->key($payload['service_key'] ?? '');
        if ($id === null && $key === '') { throw new InvalidArgumentException('La clé de service est obligatoire.'); }
        $common = [
            $categoryId,
            $this->text($payload['provider_name'] ?? '', 160),
            $this->enum($payload['service_type'] ?? 'script', ['script','iframe','pixel','local_storage','server','other'], 'script'),
            $this->enum($payload['cookie_type'] ?? 'http', ['http','html5','pixel','server','mixed','none'], 'http'),
            $this->text($payload['domain'] ?? '', 160),
            $this->text($payload['duration'] ?? '', 160),
            $this->url($payload['privacy_url'] ?? ''),
            $this->bool($payload['is_enabled'] ?? 1),
            (int)($payload['sort_order'] ?? 0),
        ];
        if ($id === null) {
            $this->db->run('INSERT INTO cookie_services(site_id, category_id, service_key, provider_name, service_type, cookie_type, domain, duration, privacy_url, is_enabled, sort_order) VALUES(?,?,?,?,?,?,?,?,?,?,?)', [$siteId, ...[$categoryId, $key], ...array_slice($common, 1)]);
            $id = $this->db->lastInsertId();
        } else {
            if (!$this->db->one('SELECT id FROM cookie_services WHERE site_id=? AND id=?', [$siteId, $id])) { throw new InvalidArgumentException('Service introuvable.'); }
            $this->db->run('UPDATE cookie_services SET category_id=?, provider_name=?, service_type=?, cookie_type=?, domain=?, duration=?, privacy_url=?, is_enabled=?, sort_order=?, updated_at=CURRENT_TIMESTAMP WHERE site_id=? AND id=?', [...$common, $siteId, $id]);
        }
        $this->upsertServiceTranslation($id, $languageCode, $payload);
        $this->replaceServiceCookies($id, is_array($payload['cookies'] ?? null) ? $payload['cookies'] : []);
        return $this->adminState($siteId, $languageCode, $languageCode);
    }

    public function deleteService(int $siteId, int $id): void
    {
        $this->db->run('DELETE FROM cookie_services WHERE site_id=? AND id=?', [$siteId, $id]);
    }

    public function saveBinding(int $siteId, ?int $id, array $payload): array
    {
        $serviceId = (int)($payload['service_id'] ?? 0);
        if ($serviceId <= 0 || !$this->db->one('SELECT id FROM cookie_services WHERE site_id=? AND id=?', [$siteId, $serviceId])) {
            throw new InvalidArgumentException('Service invalide.');
        }
        $attrs = $payload['attributes'] ?? $payload['attributes_json'] ?? [];
        if (is_string($attrs)) { $attrs = json_decode($attrs, true) ?: []; }
        $attrsJson = json_encode(is_array($attrs) ? $attrs : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $key = $this->key($payload['binding_key'] ?? '');
        if ($id === null && $key === '') { throw new InvalidArgumentException('La clé de script est obligatoire.'); }
        $values = [
            $serviceId,
            $this->enum($payload['location'] ?? 'footer', ['head','footer','manual'], 'footer'),
            $this->enum($payload['trigger_mode'] ?? 'after_consent', ['after_consent','manual'], 'after_consent'),
            $this->enum($payload['script_kind'] ?? 'external', ['external','inline','html'], 'external'),
            $this->url($payload['src_url'] ?? ''),
            $this->text($payload['inline_code'] ?? '', 20000),
            $attrsJson,
            $this->bool($payload['is_enabled'] ?? 1),
            (int)($payload['sort_order'] ?? 0),
        ];
        if ($id === null) {
            $this->db->run('INSERT INTO cookie_script_bindings(site_id, service_id, binding_key, location, trigger_mode, script_kind, src_url, inline_code, attributes_json, is_enabled, sort_order) VALUES(?,?,?,?,?,?,?,?,?,?,?)', [$siteId, $serviceId, $key, ...array_slice($values, 1)]);
        } else {
            $this->db->run('UPDATE cookie_script_bindings SET service_id=?, location=?, trigger_mode=?, script_kind=?, src_url=?, inline_code=?, attributes_json=?, is_enabled=?, sort_order=?, updated_at=CURRENT_TIMESTAMP WHERE site_id=? AND id=?', [...$values, $siteId, $id]);
        }
        return $this->adminState($siteId, 'fr', 'fr');
    }

    public function deleteBinding(int $siteId, int $id): void
    {
        $this->db->run('DELETE FROM cookie_script_bindings WHERE site_id=? AND id=?', [$siteId, $id]);
    }

    public function logConsent(int $siteId, string $languageCode, array $payload, string $ip, string $userAgent): void
    {
        $setting = $this->setting($siteId);
        if (!$setting) { return; }
        $uid = $this->text($payload['consent_uid'] ?? '', 128);
        if ($uid === '') { $uid = bin2hex(random_bytes(16)); }
        $action = $this->enum($payload['action'] ?? 'save_choices', ['accept_all','reject_all','save_choices','revoke','update'], 'save_choices');
        $choices = is_array($payload['choices'] ?? null) ? $payload['choices'] : [];
        $choicesJson = json_encode($choices, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $salt = (string)($setting['consent_version'] ?? 'amcms');
        $this->db->run('INSERT INTO cookie_consent_logs(site_id, consent_uid, consent_version, language_code, action, choices_json, ip_hash, user_agent_hash) VALUES(?,?,?,?,?,?,?,?)', [
            $siteId, $uid, (string)$setting['consent_version'], strtolower($languageCode), $action, $choicesJson,
            $ip !== '' ? hash('sha256', $salt . '|' . $ip) : '',
            $userAgent !== '' ? hash('sha256', $salt . '|' . $userAgent) : '',
        ]);
    }

    public function pruneLogs(int $siteId): void
    {
        $setting = $this->setting($siteId);
        $days = max(1, (int)($setting['log_retention_days'] ?? 180));
        $this->db->run("DELETE FROM cookie_consent_logs WHERE site_id=? AND created_at < datetime('now', ?)", [$siteId, '-' . $days . ' days']);
    }

    public function clearLogs(int $siteId): void
    {
        $this->db->run('DELETE FROM cookie_consent_logs WHERE site_id=?', [$siteId]);
    }

    private function categories(int $siteId, string $languageCode, string $defaultLanguage, bool $publicOnly): array
    {
        $sql = "SELECT c.*, COALESCE(tr.name, td.name, c.category_key) AS name, COALESCE(tr.description, td.description, '') AS description
                FROM cookie_categories c
                LEFT JOIN cookie_category_translations tr ON tr.category_id=c.id AND tr.language_code=?
                LEFT JOIN cookie_category_translations td ON td.category_id=c.id AND td.language_code=?
                WHERE c.site_id=?" . ($publicOnly ? ' AND c.is_enabled=1' : '') . ' ORDER BY c.sort_order ASC, c.id ASC';
        return $this->db->all($sql, [strtolower($languageCode), strtolower($defaultLanguage), $siteId]);
    }

    private function services(int $siteId, string $languageCode, string $defaultLanguage, bool $publicOnly): array
    {
        $sql = "SELECT s.*, c.category_key, COALESCE(tr.name, td.name, s.service_key) AS name, COALESCE(tr.purpose, td.purpose, '') AS purpose, COALESCE(tr.description, td.description, '') AS description, COALESCE(tr.fallback_message, td.fallback_message, '') AS fallback_message
                FROM cookie_services s
                INNER JOIN cookie_categories c ON c.id=s.category_id
                LEFT JOIN cookie_service_translations tr ON tr.service_id=s.id AND tr.language_code=?
                LEFT JOIN cookie_service_translations td ON td.service_id=s.id AND td.language_code=?
                WHERE s.site_id=?" . ($publicOnly ? ' AND s.is_enabled=1 AND c.is_enabled=1' : '') . ' ORDER BY c.sort_order ASC, s.sort_order ASC, s.id ASC';
        $services = $this->db->all($sql, [strtolower($languageCode), strtolower($defaultLanguage), $siteId]);
        foreach ($services as &$service) {
            $service['cookies'] = $this->db->all('SELECT cookie_name, purpose, duration, domain FROM cookie_service_cookies WHERE service_id=? ORDER BY id ASC', [(int)$service['id']]);
        }
        unset($service);
        return $services;
    }

    private function scriptBindings(int $siteId, bool $publicOnly): array
    {
        $sql = "SELECT b.*, s.service_key, c.category_key
                FROM cookie_script_bindings b
                INNER JOIN cookie_services s ON s.id=b.service_id
                INNER JOIN cookie_categories c ON c.id=s.category_id
                WHERE b.site_id=?" . ($publicOnly ? ' AND b.is_enabled=1 AND s.is_enabled=1 AND c.is_enabled=1' : '') . ' ORDER BY b.location ASC, b.sort_order ASC, b.id ASC';
        $rows = $this->db->all($sql, [$siteId]);
        foreach ($rows as &$row) {
            $row['attributes'] = json_decode((string)($row['attributes_json'] ?? '{}'), true) ?: [];
            if ($publicOnly) { unset($row['attributes_json']); }
        }
        unset($row);
        return $rows;
    }

    private function hasOptionalChoices(array $categories): bool
    {
        foreach ($categories as $category) {
            if ((int)($category['is_required'] ?? 0) !== 1) {
                return true;
            }
        }
        return false;
    }

    private function recentLogs(int $siteId, int $limit): array
    {
        return $this->db->all('SELECT id, consent_uid, consent_version, language_code, action, choices_json, created_at FROM cookie_consent_logs WHERE site_id=? ORDER BY created_at DESC, id DESC LIMIT ?', [$siteId, $limit]);
    }

    private function bannerTexts(int $settingId, string $languageCode, string $defaultLanguage): array
    {
        $row = $this->db->one('SELECT COALESCE(tr.banner_title, td.banner_title, ?) AS banner_title, COALESCE(tr.banner_summary, td.banner_summary, ?) AS banner_summary, COALESCE(tr.preferences_title, td.preferences_title, ?) AS preferences_title, COALESCE(tr.preferences_summary, td.preferences_summary, ?) AS preferences_summary, COALESCE(tr.accept_all_label, td.accept_all_label, ?) AS accept_all_label, COALESCE(tr.reject_all_label, td.reject_all_label, ?) AS reject_all_label, COALESCE(tr.customize_label, td.customize_label, ?) AS customize_label, COALESCE(tr.save_choices_label, td.save_choices_label, ?) AS save_choices_label, COALESCE(tr.manage_link_label, td.manage_link_label, ?) AS manage_link_label, COALESCE(tr.legal_notice_html, td.legal_notice_html, ?) AS legal_notice_html FROM cookie_banner_settings s LEFT JOIN cookie_banner_translations tr ON tr.setting_id=s.id AND tr.language_code=? LEFT JOIN cookie_banner_translations td ON td.setting_id=s.id AND td.language_code=? WHERE s.id=?', [
            'Gestion des cookies', 'Nous utilisons des cookies nécessaires au fonctionnement du site. Les autres cookies ne sont activés qu’avec votre accord.', 'Préférences de confidentialité', 'Choisissez les catégories de cookies que vous acceptez.', 'Tout accepter', 'Tout refuser', 'Personnaliser mes choix', 'Enregistrer mes choix', 'Gérer mes cookies', '', strtolower($languageCode), strtolower($defaultLanguage), $settingId
        ]);
        return $row ?: [];
    }

    private function upsertBannerTranslation(int $settingId, string $languageCode, array $texts): void
    {
        $languageCode = strtolower(trim($languageCode));
        $this->db->run('INSERT INTO cookie_banner_translations(setting_id, language_code, banner_title, banner_summary, preferences_title, preferences_summary, accept_all_label, reject_all_label, customize_label, save_choices_label, manage_link_label, legal_notice_html) VALUES(?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(setting_id, language_code) DO UPDATE SET banner_title=excluded.banner_title, banner_summary=excluded.banner_summary, preferences_title=excluded.preferences_title, preferences_summary=excluded.preferences_summary, accept_all_label=excluded.accept_all_label, reject_all_label=excluded.reject_all_label, customize_label=excluded.customize_label, save_choices_label=excluded.save_choices_label, manage_link_label=excluded.manage_link_label, legal_notice_html=excluded.legal_notice_html, updated_at=CURRENT_TIMESTAMP', [
            $settingId, $languageCode,
            $this->text($texts['banner_title'] ?? '', 180), $this->text($texts['banner_summary'] ?? '', 1200), $this->text($texts['preferences_title'] ?? '', 180), $this->text($texts['preferences_summary'] ?? '', 1200), $this->text($texts['accept_all_label'] ?? '', 80), $this->text($texts['reject_all_label'] ?? '', 80), $this->text($texts['customize_label'] ?? '', 80), $this->text($texts['save_choices_label'] ?? '', 80), $this->text($texts['manage_link_label'] ?? '', 80), $this->safeHtml($texts['legal_notice_html'] ?? ''),
        ]);
    }

    private function upsertCategoryTranslation(int $categoryId, string $languageCode, array $payload): void
    {
        $this->db->run('INSERT INTO cookie_category_translations(category_id, language_code, name, description) VALUES(?,?,?,?) ON CONFLICT(category_id, language_code) DO UPDATE SET name=excluded.name, description=excluded.description, updated_at=CURRENT_TIMESTAMP', [$categoryId, strtolower($languageCode), $this->text($payload['name'] ?? $payload['label'] ?? '', 120), $this->text($payload['description'] ?? '', 1200)]);
    }

    private function upsertServiceTranslation(int $serviceId, string $languageCode, array $payload): void
    {
        $this->db->run('INSERT INTO cookie_service_translations(service_id, language_code, name, purpose, description, fallback_message) VALUES(?,?,?,?,?,?) ON CONFLICT(service_id, language_code) DO UPDATE SET name=excluded.name, purpose=excluded.purpose, description=excluded.description, fallback_message=excluded.fallback_message, updated_at=CURRENT_TIMESTAMP', [$serviceId, strtolower($languageCode), $this->text($payload['name'] ?? '', 160), $this->text($payload['purpose'] ?? '', 800), $this->text($payload['description'] ?? '', 1200), $this->text($payload['fallback_message'] ?? '', 800)]);
    }

    private function replaceServiceCookies(int $serviceId, array $cookies): void
    {
        $this->db->run('DELETE FROM cookie_service_cookies WHERE service_id=?', [$serviceId]);
        foreach ($cookies as $cookie) {
            if (!is_array($cookie)) { continue; }
            $name = $this->text($cookie['cookie_name'] ?? $cookie['name'] ?? '', 120);
            if ($name === '') { continue; }
            $this->db->run('INSERT INTO cookie_service_cookies(service_id, cookie_name, purpose, duration, domain) VALUES(?,?,?,?,?)', [$serviceId, $name, $this->text($cookie['purpose'] ?? '', 400), $this->text($cookie['duration'] ?? '', 120), $this->text($cookie['domain'] ?? '', 160)]);
        }
    }

    private function sanitizeSetting(array $setting): array
    {
        if ($setting === []) { return []; }
        foreach (['is_enabled','show_floating_button','reject_equal_prominence','respect_dnt'] as $key) { $setting[$key] = (int)($setting[$key] ?? 0); }
        return $setting;
    }

    private function normalizeLanguages(array $languages, string $default): array
    {
        $out = [];
        foreach ($languages as $language) {
            $code = is_array($language) ? ($language['language_code'] ?? $language['code'] ?? '') : $language;
            $code = strtolower(trim((string)$code));
            if ($code !== '' && preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $code)) { $out[$code] = true; }
        }
        $default = strtolower(trim($default ?: 'fr'));
        $out[$default] = true;
        return array_keys($out);
    }

    private function defaultBannerTexts(string $lang): array
    {
        if (str_starts_with($lang, 'en')) {
            return ['banner_title'=>'Cookie settings','banner_summary'=>'We use cookies required for site operation. Optional cookies are activated only with your consent.','preferences_title'=>'Privacy preferences','preferences_summary'=>'Choose which cookie categories you allow.','accept_all_label'=>'Accept all','reject_all_label'=>'Reject all','customize_label'=>'Customize','save_choices_label'=>'Save choices','manage_link_label'=>'Manage cookies','legal_notice_html'=>''];
        }
        return ['banner_title'=>'Gestion des cookies','banner_summary'=>'Nous utilisons des cookies nécessaires au fonctionnement du site. Les autres cookies ne sont activés qu’avec votre accord.','preferences_title'=>'Préférences de confidentialité','preferences_summary'=>'Choisissez les catégories de cookies que vous acceptez.','accept_all_label'=>'Tout accepter','reject_all_label'=>'Tout refuser','customize_label'=>'Personnaliser mes choix','save_choices_label'=>'Enregistrer mes choix','manage_link_label'=>'Gérer mes cookies','legal_notice_html'=>''];
    }

    private function defaultCategoryTexts(string $key, string $lang): array
    {
        $fr = [
            'necessary'=>['name'=>'Nécessaires','description'=>'Indispensables à la sécurité, à la navigation, à l’accessibilité et à la mémorisation de vos choix.'],
            'preferences'=>['name'=>'Préférences','description'=>'Permettent de mémoriser des réglages non essentiels comme certaines préférences d’affichage.'],
            'statistics'=>['name'=>'Statistiques','description'=>'Aident à comprendre l’usage du site afin d’améliorer les contenus et les parcours.'],
            'marketing'=>['name'=>'Marketing','description'=>'Peuvent être utilisés pour mesurer ou personnaliser des campagnes et contenus promotionnels.'],
            'external_media'=>['name'=>'Médias externes','description'=>'Autorisent le chargement de contenus tiers comme vidéos, cartes ou widgets.'],
        ];
        $en = [
            'necessary'=>['name'=>'Necessary','description'=>'Required for security, navigation, accessibility and remembering your choices.'],
            'preferences'=>['name'=>'Preferences','description'=>'Store non-essential settings such as display preferences.'],
            'statistics'=>['name'=>'Statistics','description'=>'Help understand site usage and improve content and journeys.'],
            'marketing'=>['name'=>'Marketing','description'=>'May be used to measure or personalize promotional campaigns and content.'],
            'external_media'=>['name'=>'External media','description'=>'Allow third-party content such as videos, maps or widgets to load.'],
        ];
        return (str_starts_with($lang, 'en') ? $en : $fr)[$key] ?? ['name'=>$key, 'description'=>''];
    }

    private function key(mixed $value, bool $allowUnderscorePrefix = false): string
    {
        $value = strtolower(trim((string)$value));
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?? '';
        $value = trim($value, '_');
        if ($allowUnderscorePrefix && $value !== '') { return substr($value, 0, 80); }
        return substr($value, 0, 80);
    }
    private function text(mixed $value, int $max): string { return mb_substr(trim((string)$value), 0, $max); }
    private function bool(mixed $value): int { return in_array($value, [1, '1', true, 'true', 'on', 'yes'], true) ? 1 : 0; }
    private function intRange(mixed $value, int $min, int $max): int { return min($max, max($min, (int)$value)); }
    private function enum(mixed $value, array $allowed, string $default): string { $value = (string)$value; return in_array($value, $allowed, true) ? $value : $default; }
    private function url(mixed $value): string { $value = trim((string)$value); return $value === '' || preg_match('#^https?://#i', $value) ? mb_substr($value, 0, 2048) : ''; }
    private function safeHtml(mixed $value): string { return strip_tags((string)$value, '<p><br><strong><em><a><ul><ol><li>'); }
}
