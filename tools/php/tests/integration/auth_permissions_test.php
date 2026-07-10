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
use App\Application\Api\Admin\BlueprintApiController;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;
use App\Core\ApiException;

$h = new TestHarness();
[$dir, $path] = test_temp_db(base_path('database/iam.sql'));
$db = null;
try {
    $db = new Database($path, 1000);
    $pdo = $db->pdo();
    $pdo->exec("INSERT INTO iam_users(id,email,email_normalized,password_hash,is_active) VALUES(1,'editor@example.test','editor@example.test','x',1),(2,'viewer@example.test','viewer@example.test','x',1)");
    $pdo->exec("INSERT INTO iam_roles(id,role_key,name) VALUES(1,'publisher','Publisher'),(2,'viewer','Viewer')");
    $pdo->exec("INSERT INTO iam_permissions(id,permission_key,name) VALUES(1,'content.publish','Publish'),(2,'security.webhooks.manage','Manage webhooks'),(3,'blueprints.read','Read blueprints'),(4,'blueprints.manage','Manage blueprints')");
    $pdo->exec("INSERT INTO iam_role_permissions(role_id,permission_id) VALUES(1,1),(1,2),(1,3),(1,4)");
    $pdo->exec("INSERT INTO iam_user_site_roles(user_id,site_id,role_id) VALUES(1,10,1),(2,20,2)");

    $_SESSION['admin_user'] = ['id' => 1, 'email' => 'editor@example.test'];
    $auth = new AuthRepository($db);
    $authorization = new Authorization($auth);
    $h->assertTrue($auth->hasPermission('content.publish', 10), 'authorized permission');
    $h->assertTrue(!$auth->hasPermission('content.publish', 20), 'permission isolated by site');
    $h->assertTrue($auth->canAccessSite(10), 'authorized site access');
    $h->assertTrue(!$auth->canAccessSite(20), 'cross-site access denied');
    $h->expectException(
        fn() => $authorization->require('content.publish', 20),
        ApiException::class,
        'direct-call bypass denied'
    );

    $coreDir = sys_get_temp_dir() . '/amcms-blueprint-scope-' . bin2hex(random_bytes(6));
    if (!mkdir($coreDir, 0775, true) && !is_dir($coreDir)) {
        throw new RuntimeException('Unable to create blueprint scope test directory.');
    }
    $coreDb = null;
    try {
        $coreDb = new Database($coreDir . '/core.sqlite', 1000);
        $corePdo = $coreDb->pdo();
        $corePdo->exec("CREATE TABLE sites(id INTEGER PRIMARY KEY, site_key TEXT NOT NULL, name TEXT NOT NULL, default_language_code TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 1)");
        $corePdo->exec("CREATE TABLE site_domains(id INTEGER PRIMARY KEY, site_id INTEGER NOT NULL, host TEXT NOT NULL, base_path TEXT NOT NULL DEFAULT '', scheme TEXT NOT NULL DEFAULT 'https', is_primary INTEGER NOT NULL DEFAULT 0, is_active INTEGER NOT NULL DEFAULT 1, enforce_https INTEGER NOT NULL DEFAULT 1)");
        $corePdo->exec("INSERT INTO sites(id,site_key,name,default_language_code,is_active) VALUES(10,'site-a','Site A','fr',1),(20,'site-b','Site B','fr',1)");

        $sites = new SiteRepository($coreDb, []);
        $requireForSite = new ReflectionMethod(BlueprintApiController::class, 'requireForSite');
        $controllerFor = static function (int $requestedSiteId) use ($auth, $sites, $authorization): BlueprintApiController {
            $controller = (new ReflectionClass(BlueprintApiController::class))->newInstanceWithoutConstructor();
            $request = new Request('GET', '/admin/api/blueprints', ['site_id' => $requestedSiteId], [], ['HTTP_HOST' => 'site-a.test'], [], []);
            foreach (['request' => $request, 'auth' => $auth, 'sites' => $sites, 'authorization' => $authorization] as $property => $value) {
                (new ReflectionProperty(BlueprintApiController::class, $property))->setValue($controller, $value);
            }
            return $controller;
        };

        $h->expectException(
            fn() => $requireForSite->invoke($controllerFor(20), 'blueprints.read'),
            ApiException::class,
            'blueprint controller rejects a requested site outside the user scope'
        );
        $h->assertSame(
            10,
            $requireForSite->invoke($controllerFor(10), 'blueprints.manage'),
            'blueprint controller authorizes and returns the scoped site'
        );
    } finally {
        unset($corePdo, $sites, $requireForSite, $controllerFor);
        $coreDb = null;
        gc_collect_cycles();
        test_remove_tree($coreDir);
    }

    $_SESSION['admin_user'] = ['id' => 2, 'email' => 'viewer@example.test'];
    $viewer = new AuthRepository($db);
    $h->assertTrue(!$viewer->hasPermission('security.webhooks.manage', 20), 'forbidden permission');
} finally {
    unset($pdo, $viewer, $authorization, $auth);
    $db = null;
    gc_collect_cycles();
    test_remove_tree($dir);
}
exit($h->finish('INTEGRATION authentication and permissions'));
