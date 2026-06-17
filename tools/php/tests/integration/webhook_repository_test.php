<?php
declare(strict_types=1);
require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "[SKIP] pdo_sqlite unavailable\n";
    exit(0);
}
use App\Core\Database;
use App\Infrastructure\Persistence\Sql\SqlWebhookRepository;

$h = new TestHarness();
[$dir, $path] = test_temp_db(__DIR__ . '/../fixtures/webhooks.sql');
$db = null;
try {
    $db = new Database($path, 1000);
    $pdo = $db->pdo();
    $pdo->exec("INSERT INTO languages(code,name,locale,is_active) VALUES('fr','Français','fr-CH',1)");
    $pdo->exec("INSERT INTO sites(id,site_key,name,default_language_code,is_active) VALUES(1,'main','Main','fr',1),(2,'other','Other','fr',1)");
    $pdo->exec("INSERT INTO webhook_endpoints(id,site_id,name,url,events_json,secret,is_active,max_attempts) VALUES(1,1,'Main hook','https://example.test/hook','[\"content.published\"]','0123456789abcdef',1,2),(2,2,'Other hook','https://example.test/other','[\"*\"]','fedcba9876543210',1,2)");
    $pdo->exec("INSERT INTO outbox_events(id,topic,payload_json,status,available_at) VALUES(1,'content.published','{}','pending',CURRENT_TIMESTAMP)");

    $repo = new SqlWebhookRepository($db);
    $matches = $repo->matchingEndpoints('content.published', 1);
    $h->assertSame(1, count($matches), 'site-scoped endpoint match');
    $h->assertSame(1, (int) $matches[0]['id'], 'other site excluded');

    $delivery = $repo->createDelivery(1, 1, 'content.published', ['entry_id' => 42], 'delivery-test-1');
    $h->assertTrue($delivery > 0, 'delivery created');
    $claimed = $repo->claimDueDeliveries(10);
    $h->assertSame(1, count($claimed), 'delivery claimed');
    $repo->markSucceeded($delivery, 204, 'response-without-secret');

    $row = $db->one('SELECT * FROM webhook_deliveries WHERE id=:id', ['id' => $delivery]);
    $h->assertSame('succeeded', $row['status'], 'result persisted');
    $h->assertSame(204, (int) $row['http_status'], 'http status persisted');
    $h->assertSame(1, $repo->stats()['succeeded'], 'repository stats updated');

    $duplicate = $repo->createDelivery(1, 1, 'content.published', [], 'delivery-test-2');
    $h->assertSame($delivery, $duplicate, 'duplicate outbox delivery remains idempotent');

    $h->expectException(
        fn() => $pdo->exec("INSERT INTO webhook_endpoints(site_id,name,url,events_json,secret) VALUES(1,'bad','http://evil.test','[]','0123456789abcdef')"),
        PDOException::class,
        'unsafe URL rejected'
    );

    $secretLeak = $db->one("SELECT COUNT(*) c FROM webhook_deliveries WHERE payload_json LIKE '%0123456789abcdef%' OR response_body LIKE '%0123456789abcdef%'");
    $h->assertSame(0, (int) $secretLeak['c'], 'secret not exposed in delivery storage');

    // Close the CMS connection before an independent read proves persistence.
    unset($claimed, $matches, $repo, $pdo);
    $db = null;
    gc_collect_cycles();

    $read = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $persisted = $read->query("SELECT status, http_status FROM webhook_deliveries WHERE id = " . (int) $delivery)->fetch();
    $h->assertSame('succeeded', $persisted['status'], 'result survives reload');
    $h->assertSame(204, (int) $persisted['http_status'], 'HTTP status survives reload');
    $read = null;
} finally {
    unset($read, $repo, $pdo);
    $db = null;
    gc_collect_cycles();
    test_remove_tree($dir);
}
exit($h->finish('INTEGRATION webhooks'));
