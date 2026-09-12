<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Sale\Exceptions\SaleValidationException;
use App\Modules\Sale\Payments\PaymentProviderRegistry;
use App\Modules\Sale\Repositories\SaleEventRepository;
use App\Modules\Sale\Repositories\SaleInventoryRepository;
use App\Modules\Sale\Repositories\SaleOrderRepository;
use App\Modules\Sale\Repositories\SalePaymentRepository;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Modules\Sale\Services\SaleDeferredPaymentService;
use App\Modules\Sale\Services\SaleEventService;
use App\Modules\Sale\Services\SaleInventoryService;
use App\Modules\Sale\Services\SaleOnlinePaymentService;
use App\Modules\Sale\Services\SaleOrderDocumentService;
use App\Modules\Sale\Services\SaleOrderDossierService;
use App\Modules\Sale\Services\SaleOrderTimelineService;
use App\Modules\Sale\Services\SaleStateMachineService;

$h = new TestHarness();
[$dir, $path, $db] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/sale.sql');

try {
    $connection = new SaleDatabaseConnection($path);
    $serviceDb = $connection->database() ?? throw new RuntimeException('sale database unavailable');
    $orders = new SaleOrderRepository($connection);
    $payments = new SalePaymentRepository($connection);
    $events = new SaleEventService(new SaleEventRepository($connection));
    $inventory = new SaleInventoryService(new SaleInventoryRepository($connection), null, null, $events);
    $states = new SaleStateMachineService($serviceDb, $events);
    $registry = new PaymentProviderRegistry(null, $serviceDb, 'dossier-test-secret', 'test');
    $online = new SaleOnlinePaymentService($connection, $payments, $orders, $inventory, $states, $registry);
    $deferred = new SaleDeferredPaymentService($connection, $online);
    $documents = new SaleOrderDocumentService($connection);
    $dossiers = new SaleOrderDossierService($connection, new SaleOrderTimelineService($connection));

    $channelId = (int) ($db->one("SELECT id FROM sale_channels WHERE code='web-main'")['id'] ?? 0);
    $locationId = (int) ($db->one("SELECT id FROM sale_stock_locations WHERE site_id=1 AND code='channel-default'")['id'] ?? 0);
    $db->run("INSERT INTO sale_carts(site_id,channel_id,cart_kind,status,currency,grand_total_minor,checkout_step,terms_accepted) VALUES(1,?,'web','active','CHF',2500,'validated',1)", [$channelId]);
    $cartId = (int) $db->lastInsertId();
    $db->run(
        "INSERT INTO sale_orders(site_id,channel_id,order_number,source,status,payment_status,fulfillment_status,currency,source_cart_id,customer_snapshot_json,billing_address_json,shipping_address_json,shipping_method_snapshot_json,grand_total_minor,correlation_id) VALUES(1,?,'DOSSIER-1','ecommerce','pending_payment','pending','unfulfilled','CHF',?,'{\"name\":\"Ada Client\",\"email\":\"ada@example.test\"}','{}','{\"city\":\"Lausanne\"}','{\"type\":\"shipping\"}',2500,'corr-dossier-0001')",
        [$channelId, $cartId]
    );
    $orderId = (int) $db->lastInsertId();
    $db->run("UPDATE sale_carts SET status='converted',converted_order_id=? WHERE id=?", [$orderId, $cartId]);
    $db->run("INSERT INTO sale_order_lines(order_id,line_number,business_product_id,business_variant_id,sellable_id,sku,product_name,product_type,quantity,unit_price_minor,regular_unit_price_minor,currency,line_subtotal_minor,line_total_minor,snapshot_json) VALUES(?,1,7001,8001,8001,'ON-ORDER','Produit sur commande','physical',1,2500,2500,'CHF',2500,2500,'{}')", [$orderId]);
    $lineId = (int) $db->lastInsertId();
    $db->run("INSERT INTO sale_inventory_items(site_id,business_variant_id,sellable_id,stock_location_id,sku,on_hand_quantity,reserved_quantity,available_quantity) VALUES(1,8001,8001,?,'ON-ORDER',1,1,0)", [$locationId]);
    $itemId = (int) $db->lastInsertId();
    $db->run("INSERT INTO sale_stock_reservations(inventory_item_id,cart_id,order_id,reservation_key,quantity,status,reservation_trigger,expires_at,confirmed_at) VALUES(?,?,?,'dossier:payment',1,'confirmed','payment_capture',datetime('now','+7 days'),CURRENT_TIMESTAMP)", [$itemId,$cartId,$orderId]);
    $states->recordInitial(1, 'order', $orderId, 'pending_payment', 'corr-dossier-0001', 1, 'checkout');

    $initial = $dossiers->dossier($orderId, 'fr');
    $h->assertSame('payment_due', $initial['state']['key'], 'pending order is translated into a user-facing payment task');
    $h->assertSame(false, $initial['payments']['summary']['payment_proof'], 'pending redirect state is never payment proof');
    $h->expectException(fn() => $documents->issue($orderId, 'invoice', 'fr', 1), SaleValidationException::class, 'final invoice is refused before demonstrated payment');

    $plan = $deferred->configure($orderId, [
        'mode' => 'deferred_availability', 'provider_key' => 'sandbox', 'price_policy' => 'frozen',
        'expected_availability_at' => '2026-08-01 09:00:00', 'payment_window_seconds' => 604800,
        'idempotency_key' => 'dossier-plan-0001',
    ], 1);
    $h->assertSame('waiting_availability', $plan['status'], 'on-order flow waits without creating initial payment');
    $h->assertSame(0, (int) ($db->one('SELECT COUNT(*) AS count FROM sale_payment_intents WHERE order_id=?', [$orderId])['count'] ?? -1), 'no payment intent exists before availability');
    $h->assertSame(true, $deferred->configure($orderId, ['idempotency_key'=>'dossier-plan-0001'], 1)['replayed'], 'plan configuration is idempotent');

    $confirmation = $documents->issue($orderId, 'order_confirmation', 'fr', 1);
    $h->assertSame('order_confirmation', $confirmation['document_type'], 'order confirmation is distinct from invoice');
    $h->assertSame(true, $documents->issue($orderId, 'order_confirmation', 'fr', 1)['replayed'], 'same document snapshot is replayed');
    $h->expectException(fn() => $db->run('DELETE FROM sale_order_documents WHERE id=?', [(int) $confirmation['id']]), PDOException::class, 'issued document cannot be deleted');

    $available = $deferred->markAvailable($orderId, ['language'=>'fr','ttl_seconds'=>604800,'idempotency_key'=>'dossier-available-0001']);
    $h->assertSame('payment_due', $available['plan']['status'], 'availability creates the payment task');
    $h->assertTrue(trim((string) ($available['payment']['checkout_url'] ?? '')) !== '', 'availability returns a usable payment link');
    $intentCount = (int) ($db->one('SELECT COUNT(*) AS count FROM sale_payment_intents WHERE order_id=?', [$orderId])['count'] ?? 0);
    $replayed = $deferred->markAvailable($orderId, ['idempotency_key'=>'dossier-available-0001']);
    $h->assertSame($intentCount, (int) ($db->one('SELECT COUNT(*) AS count FROM sale_payment_intents WHERE order_id=?', [$orderId])['count'] ?? 0), 'availability replay does not duplicate payment intent');
    $h->assertSame(true, $replayed['plan']['replayed'], 'availability replay is explicit');

    $online->simulateSandbox((string) $available['payment']['reference'], (string) $available['payment']['sandbox_token'], 'success', 2500, true);
    $paidOrder = $db->one('SELECT * FROM sale_orders WHERE id=?', [$orderId]) ?? [];
    $h->assertSame('paid', $paidOrder['payment_status'] ?? null, 'provider webhook proves payment and updates order');
    $invoice = $documents->issue($orderId, 'invoice', 'fr', 1);
    $h->assertSame('invoice', $invoice['document_type'], 'invoice can be issued after demonstrated payment');
    $h->assertSame(2500, (int) $invoice['snapshot']['paid_total_minor'], 'invoice freezes the paid amount in its snapshot');

    $paidDossier = $dossiers->dossier($orderId, 'fr');
    $h->assertSame('ready_to_prepare', $paidDossier['state']['key'], 'paid delivery order converges on preparation');
    $h->assertSame($locationId, (int) $paidDossier['fulfillment']['summary']['suggested_stock_location_id'], 'dossier exposes the reserved stock location');
    $dashboard = $dossiers->actionableDashboard(1, 'fr');
    $h->assertTrue(in_array($orderId, array_column($dashboard['tasks'], 'order_id'), true), 'actionable dashboard contains the order instead of a vanity metric');
    $h->assertSame($lineId, (int) ($paidDossier['order']['lines'][0]['id'] ?? 0), 'dossier preserves immutable order lines');
    $documentRows = array_column($paidDossier['documents'], null, 'document_type');
    $h->assertTrue(str_contains((string) ($documentRows['order_confirmation']['printable_text'] ?? ''), 'Confirmation de commande'), 'dossier exposes the concrete confirmation content');
    $h->assertTrue(str_contains((string) ($documentRows['invoice']['printable_text'] ?? ''), 'Facture'), 'dossier exposes the concrete invoice content');

    $db->run("UPDATE sale_orders SET status='confirmed', fulfillment_status='fulfilled' WHERE id=?", [$orderId]);
    $readyToClose = $dossiers->dossier($orderId, 'fr');
    $h->assertSame('ready_to_close', $readyToClose['state']['key'], 'a paid and fulfilled order is explicitly ready to close');
    $closeAction = array_column($readyToClose['state']['actions'], null, 'key')['close_order'] ?? [];
    $h->assertSame(true, $closeAction['allowed'] ?? false, 'the dossier exposes an actionable close order command');
    $closed = $states->transition('order', $orderId, 'completed', 1, 'Clôture opérateur');
    $h->assertSame('completed', $closed['status'] ?? null, 'the exposed close command reaches the completed state machine transition');
} finally {
    $db = null;
    gc_collect_cycles();
    test_remove_tree($dir);
}

exit($h->finish('UNIT sale order dossier workflow'));
