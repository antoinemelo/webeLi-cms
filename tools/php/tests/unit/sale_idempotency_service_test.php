<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Sale\Exceptions\SaleValidationException;
use App\Modules\Sale\Repositories\SaleIdempotencyRepository;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Modules\Sale\Services\SaleIdempotencyService;

$h = new TestHarness();
[$saleDir, $salePath, $saleDb] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/sale.sql');

try {
    $repository = new SaleIdempotencyRepository(new SaleDatabaseConnection($salePath));
    $service = new SaleIdempotencyService($repository);

    $calls = 0;
    $first = $service->run(1, 'payment.capture', 'idem-payment', ['order_id' => 10, 'amount_minor' => 500], function () use (&$calls): array {
        $calls++;
        return ['ok' => true, 'calls' => $calls];
    });
    $replay = $service->run(1, 'payment.capture', 'idem-payment', ['order_id' => 10, 'amount_minor' => 500], function () use (&$calls): array {
        $calls++;
        return ['ok' => false, 'calls' => $calls];
    });
    $h->assertSame(['ok' => true, 'calls' => 1], $first, 'first idempotent call returns callback result');
    $h->assertSame($first, $replay, 'completed idempotent call replays stored response');
    $h->assertSame(1, $calls, 'completed idempotent replay does not run callback twice');

    $h->expectException(
        fn() => $service->run(1, 'payment.capture', 'idem-payment', ['order_id' => 10, 'amount_minor' => 501], fn(): array => ['ok' => false]),
        SaleValidationException::class,
        'same idempotency key with different payload is refused'
    );

    $requestHash = $repository->requestHash(['order_id' => 11, 'amount_minor' => 700]);
    $repository->begin(1, 'payment.capture', 'idem-locked', $requestHash, 120);
    $h->expectException(
        fn() => $service->run(1, 'payment.capture', 'idem-locked', ['order_id' => 11, 'amount_minor' => 700], fn(): array => ['ok' => false]),
        SaleValidationException::class,
        'processing idempotency key is locked'
    );

    $h->expectException(
        fn() => $service->run(1, 'refund.create', 'idem-failed', ['transaction_id' => 3], function (): array {
            throw new RuntimeException('provider unavailable');
        }),
        RuntimeException::class,
        'failed idempotent call rethrows original error'
    );
    $failed = $saleDb->one('SELECT status, response_json FROM sale_idempotency_keys WHERE scope = "refund.create" LIMIT 1');
    $h->assertSame('failed', $failed['status'] ?? null, 'failed idempotent call stores failed status');
    $failedPayload = json_decode((string) ($failed['response_json'] ?? '{}'), true);
    $h->assertSame('provider unavailable', $failedPayload['error'] ?? null, 'failed idempotent call stores error message');
} finally {
    $saleDb = null;
    gc_collect_cycles();
    test_remove_tree($saleDir);
}

exit($h->finish('UNIT sale idempotency service'));
