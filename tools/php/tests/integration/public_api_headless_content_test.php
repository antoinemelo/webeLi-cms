<?php
declare(strict_types=1);
require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "[SKIP] pdo_sqlite unavailable\n";
    exit(0);
}

use App\Core\Database;
use App\Infrastructure\Persistence\Sql\SqlEditorialContentReadRepository;
use App\Infrastructure\Persistence\Sql\SqlPublicContentReadRepository;
use App\Infrastructure\Persistence\Sql\SqlPublicRouteReadRepository;

$h = new TestHarness();
[$dir, $path] = test_temp_db(__DIR__ . '/../fixtures/public_api_headless.sql');
$db = null;
try {
    $db = new Database($path, 1000);
    $pdo = $db->pdo();
    $pdo->exec("INSERT INTO content_types(id,type_key,name,api_enabled) VALUES(1,'page','Page',1)");
    $routes = new SqlPublicRouteReadRepository($db);
    $entries = new SqlEditorialContentReadRepository($db, ['app' => ['default_locale' => 'fr']], $routes);
    $repo = new SqlPublicContentReadRepository($db, $entries, $routes);

    $page = $repo->listPublishedHeadless(1, 'fr', ['limit' => 10, 'offset' => 0]);
    $h->assertSame(0, $page['total'], 'public content listing query runs without missing content_types columns');

    $typedPage = $repo->listPublishedHeadless(1, 'fr', ['type' => 'page', 'limit' => 10, 'offset' => 0]);
    $h->assertSame(0, $typedPage['total'], 'typed public content listing query runs without missing content_types columns');

    $searchPage = $repo->searchPublishedHeadless(1, 'fr', 'alpha', ['limit' => 10, 'offset' => 0]);
    $h->assertSame(0, $searchPage['total'], 'public search query runs without missing content_types columns');
} finally {
    unset($repo, $entries, $routes, $pdo);
    $db = null;
    gc_collect_cycles();
    test_remove_tree($dir);
}
exit($h->finish('INTEGRATION public headless content API queries'));
