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
[$dir, $path] = test_temp_db(__DIR__ . '/../fixtures/public_api_media.sql');
$db = null;
try {
    $db = new Database($path, 1000);
    $routes = new SqlPublicRouteReadRepository($db);
    $entries = new SqlEditorialContentReadRepository($db, ['app' => ['default_locale' => 'fr']], $routes);
    $repo = new SqlPublicContentReadRepository($db, $entries, $routes);

    $page = $repo->listPublicMedia(1, 'fr', 10, 0, '');
    $h->assertSame(3, $page['total'], 'media listing without type counts public ready valid assets only');
    $h->assertSame(10, $page['limit'], 'media listing keeps requested limit');
    $h->assertSame(0, $page['offset'], 'media listing keeps requested offset');
    $h->assertSame(false, $page['has_more'], 'media listing without type reports no next page when all rows fit');
    $h->assertSame(3, count($page['items']), 'media listing without type returns all public rows');
    $h->assertSame(3, $page['items'][0]['id'], 'media listing ordering remains deterministic');
    $h->assertSame('Titre document FR', $page['items'][0]['title'], 'media listing keeps localized metadata for requested language');

    $firstPage = $repo->listPublicMedia(1, 'fr', 2, 0, '');
    $h->assertSame(3, $firstPage['total'], 'paginated media listing keeps total independent of limit');
    $h->assertSame(2, count($firstPage['items']), 'paginated media listing applies limit');
    $h->assertSame(true, $firstPage['has_more'], 'paginated media listing detects following rows');

    $secondPage = $repo->listPublicMedia(1, 'fr', 2, 2, '');
    $h->assertSame(3, $secondPage['total'], 'paginated media listing keeps total independent of offset');
    $h->assertSame(1, count($secondPage['items']), 'paginated media listing applies offset');
    $h->assertSame(false, $secondPage['has_more'], 'paginated media listing detects the last page');

    $typedPage = $repo->listPublicMedia(1, 'fr', 10, 0, 'image');
    $h->assertSame(2, $typedPage['total'], 'media listing with type=image counts only images');
    $h->assertSame(2, count($typedPage['items']), 'media listing with type=image returns only images');
    $h->assertTrue(
        array_reduce($typedPage['items'], static fn(bool $ok, array $item): bool => $ok && $item['media_type'] === 'image', true),
        'media listing with type=image keeps the type filter on returned rows'
    );

    $typedSecondPage = $repo->listPublicMedia(1, 'fr', 1, 1, 'image');
    $h->assertSame(2, $typedSecondPage['total'], 'typed media pagination keeps total independent of limit and offset');
    $h->assertSame(1, count($typedSecondPage['items']), 'typed media pagination applies offset');
    $h->assertSame(false, $typedSecondPage['has_more'], 'typed media pagination detects the last page');

    $emptyTypePage = $repo->listPublicMedia(1, 'fr', 10, 0, 'video');
    $h->assertSame(0, $emptyTypePage['total'], 'media listing returns an empty page for an absent type');
    $h->assertSame([], $emptyTypePage['items'], 'media listing exposes an empty items array for an absent type');
} finally {
    unset($repo, $entries, $routes);
    $db = null;
    gc_collect_cycles();
    test_remove_tree($dir);
}
exit($h->finish('INTEGRATION public media API repository queries'));
