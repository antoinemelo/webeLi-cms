<?php
declare(strict_types=1);
require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "[SKIP] pdo_sqlite unavailable\n";
    exit(0);
}

use App\Core\Database;
use App\Core\Request;
use App\Repository\SiteRepository;

$h = new TestHarness();
[$dir, $path] = test_temp_db(__DIR__ . '/../fixtures/multisite_base_path.sql');
$db = null;
$previousEnv = getenv('APP_BASE_PATH');
$previousServerEnv = $_SERVER['APP_BASE_PATH'] ?? null;
$previousSuperEnv = $_ENV['APP_BASE_PATH'] ?? null;

try {
    putenv('APP_BASE_PATH=/mod');
    $_SERVER['APP_BASE_PATH'] = '/mod';
    $_ENV['APP_BASE_PATH'] = '/mod';

    $db = new Database($path, 1000);
    $pdo = $db->pdo();
    $pdo->exec("INSERT INTO languages(code,name,locale,is_active) VALUES('fr','Français','fr-CH',1)");
    $pdo->exec("INSERT INTO sites(id,site_key,name,default_language_code,is_active) VALUES(1,'main','Main','fr',1),(10,'site_a','Site A','fr',1),(11,'site_b','Site B','fr',1)");
    $pdo->exec("INSERT INTO site_languages(site_id,language_code,locale,url_prefix,hreflang_code,is_default,is_active) VALUES(1,'fr','fr-CH','','fr-CH',1,1),(10,'fr','fr-CH','','fr-CH',1,1),(11,'fr','fr-CH','','fr-CH',1,1)");
    $pdo->exec("INSERT INTO site_domains(id,site_id,host,base_path,scheme,is_primary,is_active,enforce_https) VALUES(1,1,'webe.li','','https',1,1,1),(10,10,'webe.li','/site-a','https',1,1,1),(11,11,'webe.li','/mod/site-b','https',1,1,1)");

    $repo = new SiteRepository($db, ['cms' => ['default_site_key' => 'main']]);

    $siteA = $repo->resolveCurrentSite('webe.li', '/site-a/', true);
    $h->assertSame(10, (int) $siteA['id'], 'site A resolved from request-visible subsite path');
    $h->assertSame('/site-a', $siteA['matched_request_base_path'], 'site A request base strips APP_BASE_PATH');
    $h->assertSame('/mod/site-a', $siteA['matched_base_path'], 'site A public base includes APP_BASE_PATH');
    $h->assertSame('https://webe.li/mod/site-a', $siteA['current_base_url'], 'site A public URL includes APP_BASE_PATH');

    $siteB = $repo->resolveCurrentSite('webe.li', '/site-b/', true);
    $h->assertSame(11, (int) $siteB['id'], 'site B resolved when DB already stores the full public path');
    $h->assertSame('/site-b', $siteB['matched_request_base_path'], 'site B request base strips stored APP_BASE_PATH');
    $h->assertSame('/mod/site-b', $siteB['matched_base_path'], 'site B public base is not double-prefixed');
    $h->assertSame('https://webe.li/mod/site-b', $siteB['current_base_url'], 'site B URL is not double-prefixed');

    $main = $repo->resolveCurrentSite('webe.li', '/', true);
    $h->assertSame(1, (int) $main['id'], 'main site resolved at app root');
    $h->assertSame('', $main['matched_request_base_path'], 'main request base is empty below APP_BASE_PATH');
    $h->assertSame('/mod', $main['matched_base_path'], 'main public base includes APP_BASE_PATH');
    $h->assertSame('https://webe.li/mod', $main['current_base_url'], 'main public URL includes APP_BASE_PATH');

    $request = new Request('GET', '/site-a/admin/api/context', [], [], ['HTTP_HOST' => 'webe.li', 'HTTPS' => 'on'], [], []);
    $scoped = $repo->withResolvedSiteContext($request);
    $h->assertSame('/admin/api/context', $scoped->path, 'resolved request strips request-visible site path only');
    $h->assertSame('/site-a', $scoped->server['CMS_SITE_BASE_PATH'], 'server keeps request-visible site base');
    $h->assertSame('/mod/site-a', $scoped->server['CMS_SITE_DOMAIN_BASE_PATH'], 'server exposes public domain base separately');
} finally {
    if ($previousEnv === false) { putenv('APP_BASE_PATH'); } else { putenv('APP_BASE_PATH=' . $previousEnv); }
    if ($previousServerEnv === null) { unset($_SERVER['APP_BASE_PATH']); } else { $_SERVER['APP_BASE_PATH'] = $previousServerEnv; }
    if ($previousSuperEnv === null) { unset($_ENV['APP_BASE_PATH']); } else { $_ENV['APP_BASE_PATH'] = $previousSuperEnv; }
    unset($pdo);
    $db = null;
    gc_collect_cycles();
    test_remove_tree($dir);
}
exit($h->finish('INTEGRATION multisite APP_BASE_PATH URLs'));
