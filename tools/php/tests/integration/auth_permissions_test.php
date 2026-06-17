<?php
declare(strict_types=1);
require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "[SKIP] pdo_sqlite unavailable\n";
    exit(0);
}
use App\Core\Database;
use App\Repository\AuthRepository;
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
    $pdo->exec("INSERT INTO iam_permissions(id,permission_key,name) VALUES(1,'content.publish','Publish'),(2,'security.webhooks.manage','Manage webhooks')");
    $pdo->exec("INSERT INTO iam_role_permissions(role_id,permission_id) VALUES(1,1),(1,2)");
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
