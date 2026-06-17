<?php

declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Health\HealthCheckService;

$h = new TestHarness();
$root = sys_get_temp_dir() . '/webeli-health-' . bin2hex(random_bytes(5));
mkdir($root, 0775, true);
$dbPaths = [];
foreach (['core', 'iam'] as $name) {
    $path = $root . '/' . $name . '.sqlite';
    $pdo = new PDO('sqlite:' . $path);
    $pdo->exec('CREATE TABLE health_probe (id INTEGER PRIMARY KEY)');
    $pdo = null;
    $dbPaths[$name] = ['path' => $path];
}

$config = [
    'timeout_ms' => 1600,
    'database_busy_timeout_ms' => 50,
    'check_databases' => true,
    'check_storage' => true,
    'required_storage_paths' => [$root],
];
$result = (new HealthCheckService($config, $dbPaths))->readiness();
$h->assertSame('ready', $result['status'], 'Readable local dependencies should be ready.');
$h->assertSame('ok', $result['checks']['databases']['status'], 'SQLite read probe should succeed.');
$h->assertSame('ok', $result['checks']['storage']['status'], 'Storage read probe should succeed.');

unlink($dbPaths['iam']['path']);
$result = (new HealthCheckService($config, $dbPaths))->readiness();
$h->assertSame('not_ready', $result['status'], 'Missing mandatory database should fail readiness.');
$h->assertSame('fail', $result['checks']['databases']['status'], 'Database failure must remain explicit.');

test_remove_tree($root);
exit($h->finish('health_check_test'));
