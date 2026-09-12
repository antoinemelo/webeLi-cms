<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Sale\Exceptions\SalePaymentException;
use App\Modules\Sale\Payments\PaymentProviderRegistry;
use App\Modules\Sale\Payments\PaymentProvider;
use App\Modules\Sale\Payments\SandboxPaymentProvider;
use App\Modules\Sale\Repositories\SaleEventRepository;
use App\Modules\Sale\Repositories\SaleInventoryRepository;
use App\Modules\Sale\Repositories\SaleOrderRepository;
use App\Modules\Sale\Repositories\SalePaymentRepository;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Modules\Sale\Services\SaleEventService;
use App\Modules\Sale\Services\SaleInventoryService;
use App\Modules\Sale\Services\SaleOnlinePaymentService;
use App\Modules\Sale\Services\SalePaymentService;
use App\Modules\Sale\Services\SaleStateMachineService;

final class CrashOncePaymentProvider implements PaymentProvider
{
    public int $captureCalls = 0;
    public int $refundCalls = 0;
    public function key(): string { return 'crash_once'; }
    public function supports(string $operation): bool { return in_array($operation, ['capture', 'multiple_capture', 'refund'], true); }
    public function createIntent(array $payload): array { return ['status' => 'authorized']; }
    public function recordPayment(array $payload): array { return ['status' => 'succeeded']; }
    public function capture(array $payload): array
    {
        $this->captureCalls++;
        if ($this->captureCalls === 1) throw new RuntimeException('ambiguous provider timeout after capture');
        return ['status' => 'succeeded', 'provider_transaction_id' => 'crash-cap-' . $payload['idempotency_key'], 'payload' => ['provider' => $this->key()]];
    }
    public function refund(array $payload): array
    {
        $this->refundCalls++;
        if ($this->refundCalls === 1) throw new RuntimeException('ambiguous provider timeout after refund');
        return ['status' => 'succeeded', 'provider_transaction_id' => 'crash-ref-' . $payload['idempotency_key'], 'payload' => ['provider' => $this->key()]];
    }
    public function void(array $payload): array { return ['status' => 'cancelled']; }
}

$h = new TestHarness();
[$dir, $path, $db] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/sale.sql');
$secret = 'unit-sandbox-webhook-secret';

try {
    $connection = new SaleDatabaseConnection($path);
    $serviceDb = $connection->database() ?? throw new RuntimeException('sale database unavailable');
    $orders = new SaleOrderRepository($connection);
    $payments = new SalePaymentRepository($connection);
    $events = new SaleEventService(new SaleEventRepository($connection));
    $inventory = new SaleInventoryService(new SaleInventoryRepository($connection), null, null, $events);
    $states = new SaleStateMachineService($serviceDb);
    $registry = new PaymentProviderRegistry(null, $serviceDb, $secret, 'test');
    $sandbox = $registry->get('sandbox');
    if (!$sandbox instanceof SandboxPaymentProvider) {
        throw new RuntimeException('sandbox provider unavailable');
    }
    $online = new SaleOnlinePaymentService($connection, $payments, $orders, $inventory, $states, $registry);
    $paymentService = new SalePaymentService($payments, $orders, $events, null, $registry, $states);
    $channelId = (int) ($db->one("SELECT id FROM sale_channels WHERE code='web-main'")['id'] ?? 0);
    $locationId = (int) ($db->one("SELECT id FROM sale_stock_locations WHERE site_id=1 AND code='channel-default'")['id'] ?? 0);
    $sequence = 0;

    $pendingOrder = function (int $amount = 1000) use ($db, $channelId, $locationId, &$sequence): array {
        $sequence++;
        $db->run("INSERT INTO sale_carts(site_id,channel_id,cart_kind,status,currency,grand_total_minor,checkout_step,terms_accepted) VALUES(?,?,'web','active','CHF',?,'validated',1)", [1, $channelId, $amount]);
        $cartId = (int) $db->lastInsertId();
        $db->run(
            "INSERT INTO sale_orders(site_id,channel_id,order_number,source,status,payment_status,currency,source_cart_id,grand_total_minor) VALUES(?,? ,?,'ecommerce','pending_payment','pending','CHF',?,?)",
            [1, $channelId, 'PAY-UNIT-' . $sequence, $cartId, $amount]
        );
        $orderId = (int) $db->lastInsertId();
        $db->run("UPDATE sale_carts SET status='converted',converted_order_id=? WHERE id=?", [$orderId, $cartId]);
        $sellableId = 9000 + $sequence;
        $db->run(
            'INSERT INTO sale_inventory_items(site_id,business_variant_id,sellable_id,stock_location_id,sku,on_hand_quantity,reserved_quantity,available_quantity) VALUES(?,?,?,?,?,?,?,?)',
            [1, $sellableId, $sellableId, $locationId, 'PAY-' . $sequence, 10, 1, 9]
        );
        $itemId = (int) $db->lastInsertId();
        $db->run(
            "INSERT INTO sale_stock_reservations(inventory_item_id,cart_id,order_id,reservation_key,quantity,status,expires_at,confirmed_at) VALUES(?,?,?,?,1,'confirmed',datetime('now','+30 minutes'),CURRENT_TIMESTAMP)",
            [$itemId, $cartId, $orderId, 'cart:' . $cartId . ':payment']
        );
        return ['order_id' => $orderId, 'cart_id' => $cartId, 'item_id' => $itemId];
    };

    $first = $pendingOrder();
    $intent = $online->createIntentForOrder($first['order_id'], 'sandbox', ['idempotency_key' => 'online-payment-first']);
    $h->assertSame('sale.payment_provider.v1', $intent['contract'], 'online provider exposes versioned contract');
    $h->assertSame('requires_action', $intent['status'], 'sandbox intent requires customer action');
    $h->assertTrue(strlen((string) ($intent['sandbox_token'] ?? '')) >= 32, 'sandbox returns opaque action token');
    $h->assertSame('pending_payment', $orders->requireOrder($first['order_id'])['status'], 'order remains pending before reliable provider event');
    $h->assertSame('confirmed', $db->one('SELECT status FROM sale_stock_reservations WHERE cart_id=?', [$first['cart_id']])['status'] ?? null, 'stock reservation remains held while payment is pending');

    $generated = $sandbox->simulate((string) $intent['reference'], (string) $intent['sandbox_token'], 'success');
    $return = $online->browserReturn('sandbox', (string) $intent['reference']);
    $h->assertSame('captured', $return['provider_status'], 'browser return can observe remote sandbox state');
    $h->assertSame(true, $return['awaiting_webhook'], 'browser return before webhook keeps local payment pending');
    $h->assertSame(false, $return['payment_proof'], 'browser return explicitly is not payment proof');
    $h->assertSame('pending_payment', $orders->requireOrder($first['order_id'])['status'], 'browser return never confirms the order');

    $h->expectException(
        fn() => $online->processWebhook('sandbox', $generated['body'], ['x-sale-signature' => 't=' . time() . ',v1=' . str_repeat('0', 64)]),
        SalePaymentException::class,
        'invalid webhook signature is rejected'
    );
    $processed = $online->processWebhook('sandbox', $generated['body'], ['x-sale-signature' => $generated['signature']]);
    $h->assertSame(true, $processed['processed'], 'valid signed webhook is processed');
    $h->assertSame('confirmed', $orders->requireOrder($first['order_id'])['status'], 'captured webhook confirms order');
    $h->assertSame('paid', $orders->requireOrder($first['order_id'])['payment_status'], 'captured webhook marks order paid');
    $h->assertSame('consumed', $db->one('SELECT status FROM sale_stock_reservations WHERE cart_id=?', [$first['cart_id']])['status'] ?? null, 'successful payment consumes reservation');
    $h->assertSame(9, (int) ($db->one('SELECT on_hand_quantity FROM sale_inventory_items WHERE id=?', [$first['item_id']])['on_hand_quantity'] ?? 0), 'successful payment decrements on-hand once');

    $duplicate = $online->processWebhook('sandbox', $generated['body'], ['x-sale-signature' => $generated['signature']]);
    $h->assertSame(true, $duplicate['duplicate'], 'double webhook is idempotent');
    $h->assertSame(1, (int) ($db->one('SELECT COUNT(*) AS c FROM sale_payment_transactions WHERE order_id=? AND transaction_type=\'capture\'', [$first['order_id']])['c'] ?? 0), 'double webhook does not duplicate capture');

    $olderEvent = [
        'id' => 'sbx_evt_out_of_order', 'type' => 'payment.failed', 'provider_reference' => $intent['reference'],
        'provider_transaction_id' => 'sbx_tx_old', 'occurred_at' => '2000-01-01T00:00:00Z', 'amount_minor' => 0,
        'currency' => 'CHF', 'data' => ['status' => 'failed'],
    ];
    $olderBody = json_encode($olderEvent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    $timestamp = time();
    $olderSignature = 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $olderBody, $secret);
    $older = $online->processWebhook('sandbox', $olderBody, ['x-sale-signature' => $olderSignature]);
    $h->assertSame(true, $older['ignored_out_of_order'], 'out-of-order webhook is retained but ignored');
    $h->assertSame('confirmed', $orders->requireOrder($first['order_id'])['status'], 'out-of-order failure cannot roll back paid order');

    $partial = $pendingOrder();
    $partialIntent = $online->createIntentForOrder($partial['order_id'], 'sandbox', ['idempotency_key' => 'online-payment-partial']);
    $partialOne = $sandbox->simulate((string) $partialIntent['reference'], (string) $partialIntent['sandbox_token'], 'success', 400);
    $online->processWebhook('sandbox', $partialOne['body'], ['x-sale-signature' => $partialOne['signature']]);
    $h->assertSame('partially_paid', $orders->requireOrder($partial['order_id'])['payment_status'], 'partial capture is allocated locally');
    $h->assertSame('pending_payment', $orders->requireOrder($partial['order_id'])['status'], 'partial capture keeps order pending');
    $h->assertSame('confirmed', $db->one('SELECT status FROM sale_stock_reservations WHERE cart_id=?', [$partial['cart_id']])['status'] ?? null, 'partial capture keeps reservation');
    $partialTwo = $sandbox->simulate((string) $partialIntent['reference'], (string) $partialIntent['sandbox_token'], 'success', 1000);
    $online->processWebhook('sandbox', $partialTwo['body'], ['x-sale-signature' => $partialTwo['signature']]);
    $h->assertSame(1000, (int) $orders->requireOrder($partial['order_id'])['paid_total_minor'], 'successive captures converge to exact order total');
    $h->assertSame(2, (int) ($db->one('SELECT COUNT(*) AS c FROM sale_payment_transactions WHERE order_id=? AND transaction_type=\'capture\'', [$partial['order_id']])['c'] ?? 0), 'partial captures keep an immutable transaction trail');

    $delayed = $pendingOrder();
    $delayedIntent = $online->createIntentForOrder($delayed['order_id'], 'test', ['idempotency_key' => 'delayed-capture', 'scenario' => 'authorize_then_capture']);
    $captureOne = $paymentService->captureIntent((int) $delayedIntent['id'], 400, 1, ['idempotency_key' => 'capture-400', 'reason_code' => 'partial_fulfillment']);
    $captureReplay = $paymentService->captureIntent((int) $delayedIntent['id'], 400, 1, ['idempotency_key' => 'capture-400', 'reason_code' => 'partial_fulfillment']);
    $captureTwo = $paymentService->captureIntent((int) $delayedIntent['id'], 600, 1, ['idempotency_key' => 'capture-600', 'reason_code' => 'order_ready']);
    $h->assertSame('succeeded', $captureOne['transaction']['status'] ?? null, 'delayed partial capture succeeds through the provider contract');
    $h->assertSame(true, $captureReplay['replayed'] ?? false, 'capture idempotency key replays the durable local operation');
    $h->assertSame('captured', $captureTwo['intent']['status'] ?? null, 'multiple captures converge to the authorized total');
    $h->assertSame(2, (int) ($db->one("SELECT COUNT(*) AS c FROM sale_payment_transactions WHERE payment_intent_id=? AND transaction_type='capture'", [(int) $delayedIntent['id']])['c'] ?? 0), 'capture replay does not duplicate the immutable ledger');
    $h->expectException(
        fn() => $paymentService->captureIntent((int) $delayedIntent['id'], 1, 1, ['idempotency_key' => 'capture-over', 'reason_code' => 'order_ready']),
        SalePaymentException::class,
        'capture beyond the authorization is rejected'
    );

    $crashOrder = $pendingOrder(500);
    $db->run("INSERT INTO sale_payment_intents(site_id,channel_id,order_id,provider_key,intent_reference,status,amount_minor,currency,authorized_minor) VALUES(1,?,?,?,'crash-ref','authorized',500,'CHF',500)", [$channelId, $crashOrder['order_id'], 'crash_once']);
    $crashIntentId = (int) $db->lastInsertId();
    $crashProvider = new CrashOncePaymentProvider();
    $crashRegistry = new PaymentProviderRegistry([$crashProvider]);
    $crashService = new SalePaymentService($payments, $orders, $events, null, $crashRegistry, $states);
    $deferredCapture = $crashService->captureIntent($crashIntentId, 500, 1, ['idempotency_key' => 'crash-capture', 'reason_code' => 'order_ready']);
    $h->assertSame('pending', $deferredCapture['transaction']['status'] ?? null, 'ambiguous capture timeout preserves a durable pending operation');
    $db->run('UPDATE sale_payment_transactions SET available_at=CURRENT_TIMESTAMP WHERE id=?', [(int) $deferredCapture['transaction']['id']]);
    $retryResult = $crashService->processDueOperations(1);
    $h->assertSame(1, $retryResult['captures'] ?? 0, 'scheduled worker retries due capture operations');
    $h->assertSame(1, (int) ($db->one("SELECT COUNT(*) AS c FROM sale_payment_transactions WHERE payment_intent_id=? AND status='succeeded'", [$crashIntentId])['c'] ?? 0), 'crash replay finalizes exactly one capture ledger entry');

    foreach (['decline' => 'failed', 'abandon' => 'cancelled', 'timeout' => 'expired'] as $outcome => $providerStatus) {
        $failed = $pendingOrder();
        $failedIntent = $online->createIntentForOrder($failed['order_id'], 'sandbox', ['idempotency_key' => 'online-payment-' . $outcome]);
        $failureEvent = $sandbox->simulate((string) $failedIntent['reference'], (string) $failedIntent['sandbox_token'], $outcome);
        $online->processWebhook('sandbox', $failureEvent['body'], ['x-sale-signature' => $failureEvent['signature']]);
        $expectedOrderStatus = $outcome === 'decline' ? 'pending_payment' : 'cancelled';
        $expectedReservationStatus = $outcome === 'decline' ? 'confirmed' : 'released';
        $h->assertSame($expectedOrderStatus, $orders->requireOrder($failed['order_id'])['status'], $outcome . ' applies the retry policy to the pending order');
        $h->assertSame($expectedReservationStatus, $db->one('SELECT status FROM sale_stock_reservations WHERE cart_id=?', [$failed['cart_id']])['status'] ?? null, $outcome . ' applies the retry policy to the reservation');
        $h->assertSame($providerStatus, $db->one('SELECT status FROM sale_payment_intents WHERE id=?', [(int) $failedIntent['id']])['status'] ?? null, $outcome . ' persists normalized intent status');
    }

    $duplicateTest = $pendingOrder();
    $duplicateIntent = $online->createIntentForOrder($duplicateTest['order_id'], 'test', ['idempotency_key' => 'deterministic-duplicate', 'scenario' => 'duplicate_webhook']);
    $duplicateResult = $online->simulateDeterministicTest((string) $duplicateIntent['reference'], (string) $duplicateIntent['test_token'], 'duplicate_webhook', true);
    $h->assertSame(true, $duplicateResult['webhook']['duplicate_delivery']['duplicate'] ?? false, 'deterministic duplicate scenario delivers the same event twice');
    $h->assertSame('confirmed', $orders->requireOrder($duplicateTest['order_id'])['status'], 'first deterministic duplicate delivery confirms the order once');

    $outOfOrderTest = $pendingOrder();
    $outOfOrderIntent = $online->createIntentForOrder($outOfOrderTest['order_id'], 'test', ['idempotency_key' => 'deterministic-out-of-order', 'scenario' => 'out_of_order_webhook']);
    $outOfOrderResult = $online->simulateDeterministicTest((string) $outOfOrderIntent['reference'], (string) $outOfOrderIntent['test_token'], 'out_of_order_webhook', true);
    $h->assertSame(true, $outOfOrderResult['webhook']['ignored_out_of_order'] ?? false, 'deterministic out-of-order scenario first establishes a newer provider event');
    $h->assertSame('authorized', $db->one('SELECT status FROM sale_payment_intents WHERE id=?', [(int) $outOfOrderIntent['id']])['status'] ?? null, 'ignored deterministic capture cannot overwrite the newer authorization');

    $divergenceTest = $pendingOrder();
    $divergenceIntent = $online->createIntentForOrder($divergenceTest['order_id'], 'test', ['idempotency_key' => 'deterministic-divergence', 'scenario' => 'reconciliation_divergence']);
    $divergenceResult = $online->simulateDeterministicTest((string) $divergenceIntent['reference'], (string) $divergenceIntent['test_token'], 'reconciliation_divergence', true);
    $h->assertSame(false, $divergenceResult['webhook_delivered'] ?? true, 'deterministic divergence updates the provider without delivering a webhook');
    $divergenceRepair = $online->reconcile(1, (int) $divergenceIntent['id'], 1);
    $h->assertSame('repaired', $divergenceRepair['results'][0]['status'] ?? null, 'reconciliation repairs deterministic provider divergence');

    $reconcile = $pendingOrder();
    $reconcileIntent = $online->createIntentForOrder($reconcile['order_id'], 'sandbox', ['idempotency_key' => 'online-payment-reconcile']);
    $sandbox->simulate((string) $reconcileIntent['reference'], (string) $reconcileIntent['sandbox_token'], 'success');
    $repair = $online->reconcile(1, (int) $reconcileIntent['id'], 1);
    $h->assertSame('repaired', $repair['results'][0]['status'] ?? null, 'manual reconciliation repairs provider-only capture');
    $h->assertSame('confirmed', $orders->requireOrder($reconcile['order_id'])['status'], 'reconciliation converges order state');
    $secondRepair = $online->reconcile(1, (int) $reconcileIntent['id'], 1);
    $h->assertSame('consistent', $secondRepair['results'][0]['status'] ?? null, 'reconciliation retry is idempotent');

    $captureTx = $db->one("SELECT id FROM sale_payment_transactions WHERE order_id=? AND transaction_type='capture' ORDER BY id LIMIT 1", [$first['order_id']]);
    $refund = $paymentService->refundPayment((int) ($captureTx['id'] ?? 0), 300, 'unit refund', 1, 'online-refund-first');
    $h->assertSame('succeeded', $refund['refund']['status'] ?? null, 'sandbox refund succeeds through provider contract');
    $h->assertSame(300, (int) $orders->requireOrder($first['order_id'])['refunded_total_minor'], 'refund updates order financial state');
    $h->assertSame(300, (int) ($db->one('SELECT refunded_minor FROM sale_payment_intents WHERE id=?', [(int) $intent['id']])['refunded_minor'] ?? 0), 'refund updates provider intent totals');
    $refundReplay = $paymentService->refundPayment((int) ($captureTx['id'] ?? 0), 300, 'unit refund', 1, 'online-refund-first');
    $secondRefund = $paymentService->refundPayment((int) ($captureTx['id'] ?? 0), 200, 'commercial gesture', 1, 'online-refund-second', ['reason_code' => 'commercial_gesture']);
    $h->assertSame(true, $refundReplay['replayed'] ?? false, 'refund replay does not call or book the provider twice');
    $h->assertSame(500, (int) $secondRefund['order']['refunded_total_minor'], 'multiple partial refunds preserve the exact order total');
    $h->expectException(
        fn() => $paymentService->refundPayment((int) ($captureTx['id'] ?? 0), 501, 'too much', 1, 'online-refund-over'),
        SalePaymentException::class,
        'concurrent refund reservation prevents over-refunding'
    );

    $db->run('UPDATE sale_sandbox_payment_states SET amount_minor=amount_minor+100 WHERE provider_reference=?', [(string) $intent['reference']]);
    $mismatch = $online->reconcile(1, (int) $intent['id'], 1);
    $h->assertSame('attention_required', $mismatch['results'][0]['status'] ?? null, 'amount mismatch is never repaired silently');
    $exceptions = $online->exceptionCenter(1);
    $h->assertSame('attention', $exceptions['health'] ?? null, 'payment exception center exposes degraded health');
    $runId = (int) ($exceptions['items'][0]['id'] ?? 0);
    $preview = $online->previewExceptionResolution(1, [$runId]);
    $h->assertSame(false, $preview['safe_bulk_reconcile'] ?? true, 'human financial divergence cannot use unsafe bulk repair');
    $resolved = $online->resolveException(1, $runId, 'Verified against sandbox statement', 1);
    $h->assertSame('resolved', $resolved['resolution_status'] ?? null, 'manual resolution is explicit and audited');

    $observability = $online->observability(1);
    $h->assertTrue(count($observability['metrics']) > 0, 'payment metrics are queryable');
    $h->assertTrue(count($observability['alerts']) > 0, 'duplicate and out-of-order webhook alerts are queryable');
    $h->assertSame(0, (int) ($db->one("SELECT COUNT(*) AS c FROM sale_payment_webhook_events WHERE lower(payload_json) LIKE '%card_number%' OR lower(payload_json) LIKE '%cvc%' OR lower(payload_json) LIKE '%pan%'")['c'] ?? -1), 'webhook storage contains no raw card fields');
    $h->assertSame(1, (int) ($db->one('SELECT COUNT(*) AS c FROM sale_payment_attempts WHERE payment_intent_id=?', [(int) $intent['id']])['c'] ?? 0), 'payment attempt is persisted separately');
    $adminSessions = $online->adminSessions(1, 'fr', ['q' => 'PAY-UNIT-1']);
    $h->assertSame('Paiement reçu', $adminSessions[0]['state']['label'] ?? null, 'admin payment list exposes a friendly business status');
    $h->assertSame('none', $adminSessions[0]['state']['next_action'] ?? null, 'admin payment list exposes the next action');
    $adminDetail = $online->adminSession(1, (int) $intent['id'], 'fr');
    $h->assertTrue(count($adminDetail['captures'] ?? []) >= 1, 'admin payment detail exposes captures');
    $h->assertTrue(count($adminDetail['timeline'] ?? []) >= 3, 'admin payment detail exposes a consolidated timeline');
    $h->assertSame('sale.payment_provider.v1', $adminDetail['technical']['contract_version'] ?? null, 'technical provider details remain in the secondary panel payload');
} finally {
    $db = null;
    gc_collect_cycles();
    test_remove_tree($dir);
}

exit($h->finish('UNIT sale online payment workflow'));
