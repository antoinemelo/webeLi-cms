<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Core\Database;
use App\Core\Logger;
use App\Infrastructure\Persistence\Sql\SqlOutboxEventRepository;
use App\Repository\SystemJobRepository;
use App\Service\OutboxService;
use App\Worker\OutboxWorker;

$h = new TestHarness();
[$dir, $path, $db] = test_temp_cms_db(__DIR__ . '/../fixtures/outbox.sql');
$logPath = $dir . '/outbox.log';

try {
    $repo = new SqlOutboxEventRepository($db);
    $outbox = new OutboxService($repo);

    try {
        $db->transaction(function () use ($outbox): void {
            $outbox->push('content.cancelled', ['site_id' => 1, 'aggregate_type' => 'content_entry', 'aggregate_id' => 10]);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }
    $h->assertSame(0, (int) ($db->one('SELECT COUNT(*) c FROM outbox_events')['c'] ?? -1), 'rolled back business write leaves no orphan event');

    $event = $db->transaction(fn() => $outbox->push('content.published', ['site_id' => 1, 'entry_id' => 42, 'aggregate_type' => 'content_entry', 'aggregate_id' => 42], ['correlation_id' => 'corr-test']));
    $h->assertTrue((int) ($event['id'] ?? 0) > 0, 'event is inserted after commit');
    $h->assertSame('content.published', $event['event_type'] ?? null, 'event type is stored in common envelope');
    $h->assertSame('corr-test', $event['correlation_id'] ?? null, 'correlation id is stored in common envelope');

    $firstClaim = $outbox->claimBatch(10, 5);
    $secondClaim = $outbox->claimBatch(10, 5);
    $h->assertSame(1, count($firstClaim), 'first worker claims event');
    $h->assertSame(0, count($secondClaim), 'second worker cannot claim locked event');

    $outbox->markFailed((int) $firstClaim[0]['id'], 'temporary provider outage token=secret', (int) $firstClaim[0]['attempts'], 5, 'temporary');
    $failed = $outbox->find((int) $firstClaim[0]['id']);
    $h->assertSame('failed', $failed['status'] ?? null, 'temporary failure moves event to failed');
    $h->assertSame('temporary', $failed['error_type'] ?? null, 'failure type is stored');
    $h->assertTrue(!str_contains((string) ($failed['last_error'] ?? ''), 'secret'), 'sensitive error fragments are redacted');
    $h->assertTrue((string) ($failed['available_at'] ?? '') > (string) ($failed['created_at'] ?? ''), 'retry is deferred with backoff');

    $outbox->retry((int) $failed['id'], true);
    $effects = 0;
    $worker = new OutboxWorker(
        $outbox,
        new Logger($logPath),
        new SystemJobRepository($db),
        null,
        function (array $claimed) use (&$effects): array {
            $effects++;
            return ['claimed' => $claimed['event_id']];
        },
        'test.consumer'
    );
    $h->assertSame(1, $worker->run(10, 5), 'worker processes retried event');
    $h->assertSame(1, $effects, 'consumer side effect runs once');
    $processed = $outbox->find((int) $failed['id']);
    $h->assertSame('processed', $processed['status'] ?? null, 'successful retry marks event processed');

    $outbox->retry((int) $failed['id'], false);
    $h->assertSame(1, $worker->run(10, 5), 'manual replay of consumed event is accepted');
    $h->assertSame(1, $effects, 'manual replay is idempotent and has no second side effect');

    $dead = $outbox->push('content.dead', ['site_id' => 1], ['correlation_id' => 'corr-dead']);
    $claimedDead = $outbox->claimBatch(1, 1)[0];
    $outbox->markFailed((int) $claimedDead['id'], 'permanent failure', (int) $claimedDead['attempts'], 1, 'permanent');
    $deadRow = $outbox->find((int) $dead['id']);
    $h->assertSame('dead_letter', $deadRow['status'] ?? null, 'max attempts moves event to dead-letter');
    $h->assertTrue(($deadRow['dead_lettered_at'] ?? null) !== null, 'dead-letter timestamp is stored');

    $outbox->restoreDeadLetter((int) $deadRow['id']);
    $restored = $outbox->find((int) $deadRow['id']);
    $h->assertSame('pending', $restored['status'] ?? null, 'dead-letter can be restored');

    $outbox->moveToDeadLetter((int) $restored['id'], 'manual quarantine');
    $manualDead = $outbox->find((int) $restored['id']);
    $h->assertSame('dead_letter', $manualDead['status'] ?? null, 'event can be moved manually to dead-letter');

    $db->run("INSERT INTO outbox_events(event_id,event_type,schema_version,occurred_at,correlation_id,aggregate_type,topic,payload_json,metadata_json,status,available_at) VALUES('evt_invalid','broken',1,CURRENT_TIMESTAMP,'corr-invalid','test','broken','{}','{}','pending',CURRENT_TIMESTAMP)");
    $db->run("UPDATE outbox_events SET payload_json = 'null' WHERE event_id = 'evt_invalid'");
    $invalidWorker = new OutboxWorker($outbox, new Logger($logPath), null, null, null, 'invalid.consumer');
    $h->assertSame(0, $invalidWorker->run(10, 1), 'invalid payload is not processed silently');
    $invalid = $db->one("SELECT * FROM outbox_events WHERE event_id = 'evt_invalid'");
    $h->assertSame('dead_letter', $invalid['status'] ?? null, 'invalid payload reaches explicit dead-letter');
    $h->assertSame('invalid_payload', $invalid['error_type'] ?? null, 'invalid payload error type is explicit');

    $stats = $outbox->stats();
    $h->assertTrue(($stats['dead_letter'] ?? 0) >= 2, 'health stats expose dead-letter count');
    $h->assertTrue(array_key_exists('last_worker', $stats), 'health stats expose worker state');
    $log = is_file($logPath) ? (string) file_get_contents($logPath) : '';
    $h->assertTrue($log !== '' && !str_contains($log, 'entry_id') && !str_contains($log, 'site_id":1'), 'logs do not dump full payload bodies');
} finally {
    $db = null;
    gc_collect_cycles();
    test_remove_tree($dir);
}

exit($h->finish('UNIT transactional outbox'));
