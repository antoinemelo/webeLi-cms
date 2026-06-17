<?php

declare(strict_types=1);

namespace App\Repository;

use App\Core\Database;

final class MenuRepository
{
    public function __construct(private readonly Database $db)
    {
        $this->ensureNativeMenuSchema();
    }

    public function listMenus(int $siteId): array
    {
        return $this->db->all(
            'SELECT m.*, COUNT(mi.id) AS item_count
             FROM menus m
             LEFT JOIN menu_items mi ON mi.menu_id = m.id
             WHERE m.site_id = :site_id
             GROUP BY m.id
             ORDER BY m.menu_location, m.menu_key',
            ['site_id' => $siteId]
        );
    }

    public function findMenu(int $siteId, string $menuKey): ?array
    {
        return $this->db->one('SELECT * FROM menus WHERE site_id = :site_id AND menu_key = :menu_key LIMIT 1', [
            'site_id' => $siteId,
            'menu_key' => $menuKey,
        ]);
    }

    public function menuWithTree(int $siteId, string $menuKey, string $languageCode): ?array
    {
        $menu = $this->findMenu($siteId, $menuKey);
        if (!$menu) {
            return null;
        }
        return [
            'menu' => $menu,
            'items' => $this->treeItems($siteId, $menu, $languageCode),
            'languages' => $this->siteLanguages($siteId),
        ];
    }

    public function createMenu(int $siteId, array $payload): array
    {
        $key = $this->slugKey((string) ($payload['menu_key'] ?? $payload['key'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        if ($key === '' || $name === '') {
            throw new \InvalidArgumentException('menu_key et name sont obligatoires.');
        }
        $this->db->run(
            'INSERT INTO menus(site_id, menu_key, name, menu_location, language_mode, is_active) VALUES(:site_id, :menu_key, :name, :menu_location, :language_mode, :is_active)',
            [
                'site_id' => $siteId,
                'menu_key' => $key,
                'name' => $name,
                'menu_location' => $this->nullableString($payload['menu_location'] ?? $payload['location'] ?? null),
                'language_mode' => in_array(($payload['language_mode'] ?? 'shared'), ['shared', 'localized'], true) ? (string) $payload['language_mode'] : 'shared',
                'is_active' => !empty($payload['is_active']) || !array_key_exists('is_active', $payload) ? 1 : 0,
            ]
        );
        return $this->findMenu($siteId, $key) ?? [];
    }

    public function updateMenu(int $siteId, string $menuKey, array $payload): ?array
    {
        $menu = $this->findMenu($siteId, $menuKey);
        if (!$menu) {
            return null;
        }
        $newKey = array_key_exists('menu_key', $payload) || array_key_exists('key', $payload)
            ? $this->slugKey((string) ($payload['menu_key'] ?? $payload['key'] ?? ''))
            : (string) $menu['menu_key'];
        $name = array_key_exists('name', $payload) ? trim((string) $payload['name']) : (string) $menu['name'];
        if ($newKey === '' || $name === '') {
            throw new \InvalidArgumentException('menu_key et name ne peuvent pas être vides.');
        }
        $languageMode = in_array(($payload['language_mode'] ?? $menu['language_mode']), ['shared', 'localized'], true)
            ? (string) ($payload['language_mode'] ?? $menu['language_mode'])
            : (string) $menu['language_mode'];
        $this->db->run(
            'UPDATE menus SET menu_key = :new_key, name = :name, menu_location = :menu_location, language_mode = :language_mode, is_active = :is_active WHERE id = :id',
            [
                'new_key' => $newKey,
                'name' => $name,
                'menu_location' => array_key_exists('menu_location', $payload) || array_key_exists('location', $payload) ? $this->nullableString($payload['menu_location'] ?? $payload['location'] ?? null) : $menu['menu_location'],
                'language_mode' => $languageMode,
                'is_active' => array_key_exists('is_active', $payload) ? (!empty($payload['is_active']) ? 1 : 0) : (int) $menu['is_active'],
                'id' => (int) $menu['id'],
            ]
        );
        return $this->findMenu($siteId, $newKey);
    }

    public function deleteMenu(int $siteId, string $menuKey): bool
    {
        $menu = $this->findMenu($siteId, $menuKey);
        if (!$menu) {
            return false;
        }
        $this->db->run('DELETE FROM menus WHERE id = :id', ['id' => (int) $menu['id']]);
        return true;
    }

    public function replaceItems(int $siteId, string $menuKey, string $languageCode, array $items): ?array
    {
        $menu = $this->findMenu($siteId, $menuKey);
        if (!$menu) {
            return null;
        }
        $this->assertLanguageEnabled($siteId, $languageCode);
        $menuId = (int) $menu['id'];
        $mode = (string) ($menu['language_mode'] ?? 'shared');
        $scopeLanguage = $mode === 'localized' ? $languageCode : null;

        $this->db->transaction(function () use ($menuId, $scopeLanguage, $languageCode, $items): void {
            if ($scopeLanguage === null) {
                $this->db->run('DELETE FROM menu_items WHERE menu_id = :menu_id AND language_code IS NULL', ['menu_id' => $menuId]);
            } else {
                $this->db->run('DELETE FROM menu_items WHERE menu_id = :menu_id AND language_code = :language_code', ['menu_id' => $menuId, 'language_code' => $scopeLanguage]);
            }
            $this->insertMenuItems($menuId, null, $scopeLanguage, $languageCode, $items);
        });
        return $this->menuWithTree($siteId, $menuKey, $languageCode);
    }

    private function insertMenuItems(int $menuId, ?int $parentId, ?string $scopeLanguage, string $currentLanguage, array $items): void
    {
        $sort = 10;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $target = $this->normalizeTarget($item);
            $this->db->run(
                'INSERT INTO menu_items(menu_id, language_code, parent_id, resource_type, resource_id, term_id, manual_url, open_in_new_tab, css_class, visibility_rules_json, sort_order, is_active)
                 VALUES(:menu_id, :language_code, :parent_id, :resource_type, :resource_id, :term_id, :manual_url, :open_in_new_tab, :css_class, :visibility_rules_json, :sort_order, :is_active)',
                [
                    'menu_id' => $menuId,
                    'language_code' => $scopeLanguage,
                    'parent_id' => $parentId,
                    'resource_type' => $target['resource_type'],
                    'resource_id' => $target['resource_id'],
                    'term_id' => $target['term_id'],
                    'manual_url' => $target['manual_url'],
                    'open_in_new_tab' => !empty($item['open_in_new_tab']) ? 1 : 0,
                    'css_class' => $this->nullableString($item['css_class'] ?? null),
                    'visibility_rules_json' => $this->jsonOrNull($item['visibility_rules'] ?? $item['visibility_rules_json'] ?? null),
                    'sort_order' => (int) ($item['sort_order'] ?? $sort),
                    'is_active' => !empty($item['is_active']) || !array_key_exists('is_active', $item) ? 1 : 0,
                ]
            );
            $id = $this->db->lastInsertId();
            $this->insertLocalizations($id, $currentLanguage, $item);
            $children = $item['children'] ?? [];
            if (is_array($children) && $children !== []) {
                $this->insertMenuItems($menuId, $id, $scopeLanguage, $currentLanguage, $children);
            }
            $sort += 10;
        }
    }

    private function insertLocalizations(int $menuItemId, string $currentLanguage, array $item): void
    {
        $localizations = [];
        if (isset($item['localizations']) && is_array($item['localizations'])) {
            foreach ($item['localizations'] as $code => $localization) {
                if (!is_array($localization)) {
                    continue;
                }
                $label = trim((string) ($localization['label'] ?? ''));
                $title = $this->nullableString($localization['title_attr'] ?? null);
                if ($label !== '') {
                    $localizations[strtolower((string) $code)] = ['label' => $label, 'title_attr' => $title];
                }
            }
        }
        $label = trim((string) ($item['label'] ?? $item['title'] ?? ''));
        if ($label !== '') {
            $localizations[strtolower($currentLanguage)] = ['label' => $label, 'title_attr' => $this->nullableString($item['title_attr'] ?? null)];
        }
        foreach ($localizations as $language => $data) {
            $this->db->run(
                'INSERT INTO menu_item_localizations(menu_item_id, language_code, label, title_attr) VALUES(:id, :language, :label, :title_attr)',
                ['id' => $menuItemId, 'language' => $language, 'label' => $data['label'], 'title_attr' => $data['title_attr']]
            );
        }
    }

    private function treeItems(int $siteId, array $menu, string $languageCode): array
    {
        $defaultLanguage = $this->defaultLanguage($siteId);
        $menuId = (int) $menu['id'];
        $mode = (string) ($menu['language_mode'] ?? 'shared');
        $where = $mode === 'localized'
            ? 'mi.menu_id = :menu_id AND mi.language_code = :scope_language'
            : 'mi.menu_id = :menu_id AND mi.language_code IS NULL';
        $params = ['menu_id' => $menuId];
        if ($mode === 'localized') {
            $params['scope_language'] = $languageCode;
        }
        $rows = $this->selectTreeRows($where, $params + ['language' => $languageCode, 'default_language' => $defaultLanguage]);

        // Compatibility/fallback: legacy databases created before native localized menu scopes
        // stored all items as shared rows (language_code NULL). A localized menu/language with
        // no scoped rows must still display those shared rows instead of crashing or looking empty.
        if ($mode === 'localized' && $rows === []) {
            $rows = $this->selectTreeRows(
                'mi.menu_id = :menu_id AND mi.language_code IS NULL',
                ['menu_id' => $menuId, 'language' => $languageCode, 'default_language' => $defaultLanguage]
            );
        }

        $localizations = $this->localizationsForMenu($menuId);
        $byParent = [];
        foreach ($rows as $row) {
            $row['label'] = (string) ($row['label'] ?? $row['fallback_label'] ?? '');
            $row['title_attr'] = $row['title_attr'] ?? $row['fallback_title_attr'] ?? null;
            $row['localizations'] = $localizations[(int) $row['id']] ?? [];
            $row['has_current_localization'] = isset($localizations[(int) $row['id']][strtolower($languageCode)]);
            $row = $this->decorateTargetMeta($row);
            $byParent[(int) ($row['parent_id'] ?? 0)][] = $row;
        }
        $build = function (?int $parentId) use (&$build, &$byParent): array {
            $items = [];
            foreach ($byParent[$parentId ?? 0] ?? [] as $row) {
                $row['children'] = $build((int) $row['id']);
                $items[] = $row;
            }
            return $items;
        };
        return $build(null);
    }

    private function selectTreeRows(string $where, array $params): array
    {
        return $this->db->all(
            "SELECT mi.*, current_loc.label AS label, current_loc.title_attr AS title_attr,
                    default_loc.label AS fallback_label, default_loc.title_attr AS fallback_title_attr,
                    ce.id AS target_entry_id, ce.status AS target_entry_status, ce.is_active AS target_entry_is_active,
                    ct.type_key AS target_entry_type, cel.title AS target_entry_title, r.full_path AS target_entry_path,
                    tt.id AS target_term_id, tt.is_active AS target_term_is_active, tx.taxonomy_key AS target_taxonomy_key,
                    COALESCE(ttl.name, ttlf.name) AS target_term_name, COALESCE(ttl.full_path, ttlf.full_path) AS target_term_path
             FROM menu_items mi
             LEFT JOIN menu_item_localizations current_loc ON current_loc.menu_item_id = mi.id AND current_loc.language_code = :language
             LEFT JOIN menu_item_localizations default_loc ON default_loc.menu_item_id = mi.id AND default_loc.language_code = :default_language
             LEFT JOIN menus m ON m.id = mi.menu_id
             LEFT JOIN content_entries ce ON mi.resource_type = 'content_entry' AND ce.id = mi.resource_id AND ce.site_id = m.site_id
             LEFT JOIN content_types ct ON ct.id = ce.content_type_id
             LEFT JOIN content_entry_localizations cel ON cel.entry_id = ce.id AND cel.language_code = :language
             LEFT JOIN routes r ON r.site_id = ce.site_id AND r.resource_type = 'content_entry' AND r.resource_id = ce.id AND r.language_code = :language AND r.is_primary = 1 AND r.is_canonical = 1
             LEFT JOIN taxonomy_terms tt ON ((mi.term_id IS NOT NULL AND tt.id = mi.term_id) OR (mi.resource_type = 'taxonomy_term' AND tt.id = mi.resource_id))
             LEFT JOIN taxonomies tx ON tx.id = tt.taxonomy_id AND tx.site_id = m.site_id
             LEFT JOIN taxonomy_term_localizations ttl ON ttl.site_id = tx.site_id AND ttl.taxonomy_id = tx.id AND ttl.term_id = tt.id AND ttl.language_code = :language
             LEFT JOIN taxonomy_term_localizations ttlf ON ttlf.site_id = tx.site_id AND ttlf.taxonomy_id = tx.id AND ttlf.term_id = tt.id AND ttlf.language_code = :default_language
             WHERE {$where}
             ORDER BY COALESCE(mi.parent_id, 0), mi.sort_order, mi.id",
            $params
        );
    }

    private function decorateTargetMeta(array $row): array
    {
        $manualUrl = trim((string) ($row['manual_url'] ?? ''));
        $resourceType = (string) ($row['resource_type'] ?? '');
        $resourceId = isset($row['resource_id']) ? (int) $row['resource_id'] : 0;
        $termId = isset($row['term_id']) ? (int) $row['term_id'] : 0;

        $row['target_label'] = null;
        $row['target_status'] = null;
        $row['target_path'] = null;
        $row['target_missing'] = false;

        if ($termId > 0 || $resourceType === 'taxonomy_term') {
            $row['target_label'] = $row['target_term_name'] ?? (($termId > 0 || $resourceId > 0) ? 'Terme #' . ($termId ?: $resourceId) : null);
            $row['target_status'] = !empty($row['target_term_is_active']) ? 'active' : 'inactive';
            $row['target_path'] = $row['target_term_path'] ?? null;
            $row['target_missing'] = empty($row['target_term_id']);
            return $row;
        }

        if ($resourceType !== '' && $resourceId > 0) {
            $label = trim((string) ($row['target_entry_title'] ?? ''));
            $row['target_label'] = $label !== '' ? $label : 'Contenu #' . $resourceId;
            $row['target_status'] = $row['target_entry_status'] ?? null;
            $row['target_path'] = $row['target_entry_path'] ?? null;
            $row['target_missing'] = empty($row['target_entry_id']);
            return $row;
        }

        if ($manualUrl !== '') {
            $row['target_label'] = $manualUrl === '#' ? 'Titre de groupe / séparateur' : $manualUrl;
            $row['target_path'] = $manualUrl;
        }
        return $row;
    }

    private function localizationsForMenu(int $menuId): array
    {
        $rows = $this->db->all(
            'SELECT mil.menu_item_id, mil.language_code, mil.label, mil.title_attr
             FROM menu_item_localizations mil
             JOIN menu_items mi ON mi.id = mil.menu_item_id
             WHERE mi.menu_id = :menu_id
             ORDER BY mil.language_code',
            ['menu_id' => $menuId]
        );
        $localizations = [];
        foreach ($rows as $row) {
            $localizations[(int) $row['menu_item_id']][strtolower((string) $row['language_code'])] = [
                'label' => (string) $row['label'],
                'title_attr' => $row['title_attr'],
            ];
        }
        return $localizations;
    }

    private function ensureNativeMenuSchema(): void
    {
        if (!$this->db->tableExists('menu_items')) {
            return;
        }

        $columns = array_map(
            static fn (array $row): string => strtolower((string) ($row['name'] ?? '')),
            $this->db->all('PRAGMA table_info(menu_items)')
        );

        if (!in_array('language_code', $columns, true)) {
            $this->db->run('ALTER TABLE menu_items ADD COLUMN language_code TEXT');
        }

        $this->db->run(
            'CREATE INDEX IF NOT EXISTS idx_menu_items_menu_language_parent_sort
             ON menu_items(menu_id, language_code, parent_id, sort_order, id)'
        );

        $this->db->run(
            'CREATE TABLE IF NOT EXISTS menu_item_localizations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                menu_item_id INTEGER NOT NULL,
                language_code TEXT NOT NULL,
                label TEXT NOT NULL,
                title_attr TEXT,
                UNIQUE(menu_item_id, language_code),
                FOREIGN KEY(menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE
            )'
        );
    }

    private function normalizeTarget(array $item): array
    {
        $manual = $this->nullableString($item['manual_url'] ?? $item['url'] ?? null);
        $termId = isset($item['term_id']) ? (int) $item['term_id'] : null;
        $resourceType = $this->nullableString($item['resource_type'] ?? null);
        $resourceId = isset($item['resource_id']) ? (int) $item['resource_id'] : null;
        if ($termId !== null && $termId <= 0) { $termId = null; }
        if ($resourceId !== null && $resourceId <= 0) { $resourceId = null; }
        $count = ($manual !== null ? 1 : 0) + ($termId !== null ? 1 : 0) + ($resourceType !== null && $resourceId !== null ? 1 : 0);
        if ($count !== 1) {
            throw new \InvalidArgumentException('Chaque item doit pointer vers exactement une cible: url, term_id ou resource_type + resource_id.');
        }
        return ['manual_url' => $manual, 'term_id' => $termId, 'resource_type' => $resourceType, 'resource_id' => $resourceId];
    }

    private function siteLanguages(int $siteId): array
    {
        return $this->db->all(
            'SELECT sl.language_code AS code, l.name, l.native_name, sl.is_default, sl.sort_order
             FROM site_languages sl
             JOIN languages l ON l.code = sl.language_code
             WHERE sl.site_id = :site_id AND sl.is_active = 1 AND l.is_active = 1
             ORDER BY sl.sort_order, sl.language_code',
            ['site_id' => $siteId]
        );
    }

    private function defaultLanguage(int $siteId): string
    {
        $row = $this->db->one('SELECT language_code FROM site_languages WHERE site_id = :site_id AND is_default = 1 LIMIT 1', ['site_id' => $siteId]);
        return strtolower((string) ($row['language_code'] ?? 'fr'));
    }

    private function assertLanguageEnabled(int $siteId, string $languageCode): void
    {
        $row = $this->db->one('SELECT 1 FROM site_languages WHERE site_id = :site_id AND language_code = :language AND is_active = 1 LIMIT 1', [
            'site_id' => $siteId,
            'language' => strtolower($languageCode),
        ]);
        if (!$row) {
            throw new \InvalidArgumentException('La langue demandée n’est pas activée pour ce site.');
        }
    }

    private function slugKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_\-]+/', '-', $value) ?: '';
        return trim($value, '-_');
    }

    private function nullableString(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : $text;
    }

    private function jsonOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            json_decode($value, true);
            return json_last_error() === JSON_ERROR_NONE ? $value : null;
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        return $json === false ? null : $json;
    }
}
