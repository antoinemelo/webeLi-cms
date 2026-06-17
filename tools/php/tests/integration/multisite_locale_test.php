<?php
declare(strict_types=1);
require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "[SKIP] pdo_sqlite unavailable\n";
    exit(0);
}
use App\Core\Database;

$h = new TestHarness();
[$dir, $path] = test_temp_db(__DIR__ . '/../fixtures/multisite_locale.sql');
$db = null;
try {
    $db = new Database($path, 1000);
    $pdo = $db->pdo();
    $pdo->exec("INSERT INTO languages(code,name,locale,is_active) VALUES('fr','Français','fr-CH',1),('en','English','en-GB',1)");
    $pdo->exec("INSERT INTO sites(id,site_key,name,default_language_code,is_active) VALUES(1,'a','A','fr',1),(2,'b','B','en',1)");
    $pdo->exec("INSERT INTO site_languages(site_id,language_code,locale,url_prefix,hreflang_code,is_default,is_active) VALUES(1,'fr','fr-CH','fr','fr-CH',1,1),(2,'en','en-GB','en','en-GB',1,1)");
    $pdo->exec("INSERT INTO search_documents(site_id,resource_type,resource_id,language_code,path,title,summary,search_text) VALUES(1,'system',1,'fr','/fr/a','Bonjour','alpha','bonjour alpha'),(2,'system',2,'en','/en/b','Hello','alpha','hello alpha')");

    $a = $db->all("SELECT title FROM search_documents WHERE site_id=1 AND language_code='fr'");
    $b = $db->all("SELECT title FROM search_documents WHERE site_id=2 AND language_code='en'");
    $h->assertSame([['title' => 'Bonjour']], $a, 'site A French isolation');
    $h->assertSame([['title' => 'Hello']], $b, 'site B English isolation');
    $h->assertSame(0, (int) $db->one("SELECT COUNT(*) c FROM search_documents WHERE site_id=1 AND language_code='en'")['c'], 'wrong locale excluded');
    $h->expectException(
        fn() => $pdo->exec("INSERT INTO search_documents(site_id,resource_type,resource_id,language_code,path,title,summary,search_text) VALUES(1,'system',3,'en','/en/leak','Leak','','leak')"),
        PDOException::class,
        'cross-site locale insertion rejected by foreign key'
    );
} finally {
    unset($pdo);
    $db = null;
    gc_collect_cycles();
    test_remove_tree($dir);
}
exit($h->finish('INTEGRATION multisite and locale'));
