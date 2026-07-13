<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Sale\Exceptions\SalePaymentException;
use App\Modules\Sale\Payments\PaymentProviderRegistry;
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
    $sandbox = new SandboxPaymentProvider($serviceDb, $secret);
    $registry = new PaymentProviderRegistry([$sandbox]);
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

    foreach (['decline' => 'failed', 'abandon' => 'cancelled', 'timeout' => 'expired'] as $outcome => $providerStatus) {
        $failed = $pendingOrder();
        $failedIntent = $online->createIntentForOrder($failed['order_id'], 'sandbox', ['idempotency_key' => 'online-payment-' . $outcome]);
        $failureEvent = $sandbox->simulate((string) $failedIntent['reference'], (string) $failedIntent['sandbox_token'], $outcome);
        $online->processWebhook('sandbox', $failureEvent['body'], ['x-sale-signature' => $failureEvent['signature']]);
        $h->assertSame('cancelled', $orders->requireOrder($failed['order_id'])['status'], $outcome . ' cancels pending order');
        $h->assertSame('released', $db->one('SELECT status FROM sale_stock_reservations WHERE cart_id=?', [$failed['cart_id']])['status'] ?? null, $outcome . ' releases reservation');
        $h->assertSame($providerStatus, $db->one('SELECT status FROM sale_payment_intents WHERE id=?', [(int) $failedIntent['id']])['status'] ?? null, $outcome . ' persists normalized intent status');
    }

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
