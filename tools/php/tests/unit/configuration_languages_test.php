<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Application\Configuration\ConfigurationRepository;
use App\Core\Database;

$h = new TestHarness();
$directory = sys_get_temp_dir() . '/amcms-configuration-languages-' . bin2hex(random_bytes(6));
mkdir($directory, 0775, true);
$db = new Database($directory . '/core.sqlite');
$db->pdo()->exec(<<<'SQL'
CREATE TABLE languages (
    code TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    native_name TEXT,
    locale TEXT NOT NULL,
    is_default INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE sites (
    id INTEGER PRIMARY KEY,
    site_key TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    default_language_code TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(default_language_code) REFERENCES languages(code) ON DELETE RESTRICT
);
CREATE TABLE site_languages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    locale TEXT,
    url_prefix TEXT NOT NULL DEFAULT '',
    hreflang_code TEXT,
    fallback_language_code TEXT,
    is_default INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    is_rtl INTEGER NOT NULL DEFAULT 0,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(site_id, language_code),
    UNIQUE(site_id, url_prefix),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY(language_code) REFERENCES languages(code) ON DELETE RESTRICT
);
CREATE UNIQUE INDEX idx_site_languages_one_default_per_site ON site_languages(site_id) WHERE is_default = 1;
CREATE TABLE site_localizations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    site_title TEXT NOT NULL,
    UNIQUE(site_id, language_code),
    FOREIGN KEY(site_id, language_code) REFERENCES site_languages(site_id, language_code) ON DELETE CASCADE
);
CREATE TABLE configuration_revisions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    group_key TEXT NOT NULL,
    value_json TEXT NOT NULL,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
SQL);
$db->run("INSERT INTO languages(code,name,native_name,locale) VALUES('fr','Français','Français','fr-CH'),('en','English','English','en-GB'),('de','Deutsch','Deutsch','de-CH')");
$db->run("INSERT INTO sites(id,site_key,name,default_language_code) VALUES(1,'main','Test','fr')");
$db->run("INSERT INTO site_languages(site_id,language_code,locale,url_prefix,hreflang_code,is_default,is_active,sort_order) VALUES(1,'fr','fr-CH','','fr-CH',1,1,10),(1,'en','en-GB','/en','en-GB',0,1,20),(1,'de','de-CH','/de','de-CH',0,1,30)");
$db->run("INSERT INTO site_localizations(site_id,language_code,site_title) VALUES(1,'en','English content')");

$repository = new ConfigurationRepository($db);
$payload = [
    'enabled_languages' => [
        ['code' => 'fr', 'locale' => 'fr-CH', 'url_prefix' => '', 'hreflang_code' => 'fr-CH', 'fallback_language_code' => null, 'is_default' => true, 'is_active' => true, 'is_rtl' => false, 'sort_order' => 10],
        ['code' => 'de', 'locale' => 'de-CH', 'url_prefix' => '/de', 'hreflang_code' => 'de-CH', 'fallback_language_code' => 'fr', 'is_default' => false, 'is_active' => true, 'is_rtl' => false, 'sort_order' => 20],
    ],
];
$validated = $repository->validate('languages', $payload, 1, 'fr');
$h->assertSame([], $validated['fields'], 'two-language configuration is valid');
$repository->save('languages', $validated['values'], 1, 7);

$statuses = $db->all('SELECT language_code,is_active,is_default FROM site_languages WHERE site_id=1 ORDER BY language_code');
$byCode = array_column($statuses, null, 'language_code');
$h->assertSame(0, (int) $byCode['en']['is_active'], 'omitted language is disabled');
$h->assertSame(1, (int) $byCode['fr']['is_default'], 'selected default language remains the sole default');
$h->assertSame(1, (int) ($db->one("SELECT COUNT(*) count FROM site_localizations WHERE site_id=1 AND language_code='en'")['count'] ?? 0), 'disabled language content is preserved');

$payload['enabled_languages'] = [
    ['code' => 'de', 'locale' => 'de-CH', 'url_prefix' => '', 'hreflang_code' => 'de-CH', 'fallback_language_code' => null, 'is_default' => true, 'is_active' => true, 'is_rtl' => false, 'sort_order' => 10],
    ['code' => 'it', 'locale' => 'it-CH', 'url_prefix' => '/it', 'hreflang_code' => 'it-CH', 'fallback_language_code' => 'de', 'is_default' => false, 'is_active' => true, 'is_rtl' => false, 'sort_order' => 20],
];
$validated = $repository->validate('languages', $payload, 1, 'fr');
$h->assertSame([], $validated['fields'], 'adding a new language is valid');
$repository->save('languages', $validated['values'], 1, 7);
$h->assertSame(1, (int) ($db->one("SELECT is_active FROM site_languages WHERE site_id=1 AND language_code='it'")['is_active'] ?? 0), 'new language is inserted and enabled');
$h->assertSame(0, (int) ($db->one("SELECT is_active FROM site_languages WHERE site_id=1 AND language_code='fr'")['is_active'] ?? 1), 'the former default can be disabled safely');
$h->assertSame('de', (string) ($db->one('SELECT default_language_code FROM sites WHERE id=1')['default_language_code'] ?? ''), 'the site follows the new default language');

test_remove_tree($directory);
exit($h->finish('Configuration content languages'));
