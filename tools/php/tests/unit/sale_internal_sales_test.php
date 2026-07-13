<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Sale\Exceptions\SalePaymentException;
use App\Modules\Sale\Exceptions\SaleValidationException;
use App\Modules\Sale\Repositories\SaleEventRepository;
use App\Modules\Sale\Repositories\SaleIdempotencyRepository;
use App\Modules\Sale\Repositories\SaleInventoryRepository;
use App\Modules\Sale\Repositories\SaleOrderRepository;
use App\Modules\Sale\Repositories\SalePaymentRepository;
use App\Modules\Sale\Repositories\SaleReceiptRepository;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Modules\Sale\Services\SaleEventService;
use App\Modules\Sale\Services\SaleIdempotencyService;
use App\Modules\Sale\Services\SaleInventoryService;
use App\Modules\Sale\Services\SaleOrderService;
use App\Modules\Sale\Services\SaleOrderTimelineService;
use App\Modules\Sale\Services\SalePaymentService;
use App\Modules\Sale\Services\SaleReceiptService;
use App\Modules\Sale\Services\SaleReturnService;
use App\Modules\Sale\Services\SaleStateMachineService;

$h = new TestHarness();
[$saleDir, $salePath, $db] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/sale.sql');

try {
    $db = null;
    $connection = new SaleDatabaseConnection($salePath);
    $db = $connection->database() ?? throw new RuntimeException('Sale test database unavailable.');
    $orders = new SaleOrderRepository($connection);
    $payments = new SalePaymentRepository($connection);
    $events = new SaleEventService(new SaleEventRepository($connection));
    $states = new SaleStateMachineService($db);
    $inventory = new SaleInventoryService(new SaleInventoryRepository($connection), null, null, $events);
    $paymentService = new SalePaymentService(
        $payments,
        $orders,
        $events,
        new SaleIdempotencyService(new SaleIdempotencyRepository($connection)),
        null,
        $states
    );
    $orderService = new SaleOrderService($orders, $events, $inventory, $states);
    $returnService = new SaleReturnService($connection, $states, $inventory);
    $receiptService = new SaleReceiptService($orders, $payments, new SaleReceiptRepository($connection));
    $timelineService = new SaleOrderTimelineService($connection);

    $channelId = (int) ($db->one("SELECT id FROM sale_channels WHERE code='admin-manual'")['id'] ?? 0);
    $db->run(
        "INSERT INTO sale_orders(site_id,channel_id,order_number,source,status,payment_status,currency,customer_snapshot_json,billing_address_json,shipping_address_json,subtotal_minor,tax_total_minor,grand_total_minor,placed_at,created_by_iam_user_id)
         VALUES(1,?,'INT-11-001','admin','placed','unpaid','CHF','{\"guest\":true}','{}','{}',930,70,1000,CURRENT_TIMESTAMP,7)",
        [$channelId]
    );
    $orderId = (int) $db->lastInsertId();
    $states->recordInitial(1, 'order', $orderId, 'placed', 'point11-order', 7, 'guest sale');
    $db->run(
        "INSERT INTO sale_order_lines(order_id,line_number,business_product_id,business_variant_id,sku,product_name,quantity,unit_price_minor,regular_unit_price_minor,currency,tax_rate_basis_points,line_subtotal_minor,line_tax_minor,line_total_minor,snapshot_json)
         VALUES(?,1,11001,11002,'P11-SKU','Produit point 11',2,500,500,'CHF',700,930,70,1000,'{\"historical\":true}')",
        [$orderId]
    );
    $orderLineId = (int) $db->lastInsertId();
    $db->run("INSERT INTO sale_order_tax_lines(order_id,tax_class_code,tax_rate_basis_points,taxable_amount_minor,tax_amount_minor,currency) VALUES(?,'standard',700,930,70,'CHF')", [$orderId]);
    $db->run("INSERT INTO sale_stock_locations(site_id,code,name,location_type,status) VALUES(1,'main','Point 11','main','active')");
    $locationId = (int) $db->lastInsertId();
    $db->run("INSERT INTO sale_inventory_items(site_id,business_variant_id,sellable_id,stock_location_id,sku,on_hand_quantity,reserved_quantity,available_quantity) VALUES(1,11002,11002,?,'P11-SKU',8,0,8)", [$locationId]);

    $first = $paymentService->recordManualPayment($orderId, 400, 7, ['provider_key' => 'cash', 'idempotency_key' => 'p11-cash-400']);
    $firstReplay = $paymentService->recordManualPayment($orderId, 400, 7, ['provider_key' => 'cash', 'idempotency_key' => 'p11-cash-400']);
    $h->assertSame('partially_paid', $first['order']['payment_status'], 'first local payment leaves the order partially paid');
    $h->assertSame((int) $first['transaction']['id'], (int) $firstReplay['transaction']['id'], 'payment replay is idempotent');
    $second = $paymentService->recordManualPayment($orderId, 600, 8, ['provider_key' => 'external_terminal', 'idempotency_key' => 'p11-terminal-600']);
    $h->assertSame('paid', $second['order']['payment_status'], 'multiple payments total the full order amount');
    $h->assertSame(1000, $payments->allocatedTotal($orderId), 'payment allocations are totalled exactly');

    $correction = $paymentService->recordCorrection($orderId, -100, 'Correction opérateur', 'p11-correction-minus', 7, (int) $first['transaction']['id']);
    $correctionReplay = $paymentService->recordCorrection($orderId, -100, 'Correction opérateur', 'p11-correction-minus', 7, (int) $first['transaction']['id']);
    $h->assertSame('partially_paid', $correction['order']['payment_status'], 'explicit correction updates the paid state');
    $h->assertSame(true, $correctionReplay['replayed'], 'correction replay is idempotent');
    $h->expectException(
        fn() => $paymentService->recordCorrection($orderId, -50, 'Autre correction', 'p11-correction-minus', 7, (int) $first['transaction']['id']),
        SalePaymentException::class,
        'correction idempotency key rejects a different request'
    );
    $paymentService->recordCorrection($orderId, 100, 'Contre-correction opérateur', 'p11-correction-plus', 7, (int) $first['transaction']['id']);

    $refund = $paymentService->refundPayment((int) $first['transaction']['id'], 200, 'Retour partiel', 7, 'p11-refund-200');
    $refundReplay = $paymentService->refundPayment((int) $first['transaction']['id'], 200, 'Retour partiel', 7, 'p11-refund-200');
    $h->assertSame('partially_refunded', $refund['order']['payment_status'], 'partial refund updates the order state');
    $h->assertSame((int) $refund['refund']['id'], (int) $refundReplay['refund']['id'], 'refund replay is idempotent');
    $h->expectException(
        fn() => $paymentService->refundPayment((int) $first['transaction']['id'], 201, 'Trop élevé', 7, 'p11-refund-excess'),
        SalePaymentException::class,
        'refund cannot exceed the remaining refundable transaction amount'
    );

    $pendingIntent = $payments->createIntent(1, $channelId, $orderId, 'bank_transfer', 100, 'CHF', 'p11-void-intent');
    $voided = $paymentService->voidIntent((int) $pendingIntent['id'], 7);
    $voidReplay = $paymentService->voidIntent((int) $pendingIntent['id'], 7);
    $h->assertSame('cancelled', $voided['intent']['status'], 'intent can be voided before capture');
    $h->assertSame(true, $voidReplay['replayed'], 'void replay is idempotent');
    $h->expectException(
        fn() => $paymentService->voidIntent((int) $first['intent']['id'], 7),
        SalePaymentException::class,
        'captured intent cannot be voided'
    );

    $snapshotBefore = (string) $orders->requireOrder($orderId)['customer_snapshot_json'];
    $reconciled = $orderService->reconcileCustomer($orderId, null, 4242, 7, 'Client identifié après la vente');
    $h->assertSame(4242, (int) $reconciled['customer_contact_id'], 'guest order can be reconciled to CRM later');
    $h->assertSame($snapshotBefore, (string) $reconciled['customer_snapshot_json'], 'CRM reconciliation preserves the historical customer snapshot byte for byte');

    $return = $returnService->request($orderId, [['order_line_id' => $orderLineId, 'quantity' => 1]], 'Retour comptoir', 7, 'p11-return-one');
    $returnReplay = $returnService->request($orderId, [['order_line_id' => $orderLineId, 'quantity' => 1]], 'Retour comptoir', 7, 'p11-return-one');
    $h->assertSame(true, $returnReplay['replayed'], 'return request replay is idempotent');
    $h->expectException(
        fn() => $returnService->request($orderId, [['order_line_id' => $orderLineId, 'quantity' => 2]], 'Retour comptoir', 7, 'p11-return-one'),
        SaleValidationException::class,
        'return idempotency key rejects a different request'
    );
    $returnService->transition((int) $return['id'], 'approved', 7);
    $returnService->transition((int) $return['id'], 'received', 7);
    $completedReturn = $returnService->transition((int) $return['id'], 'completed', 7);
    $h->assertSame('completed', $completedReturn['status'], 'approved and received return can be completed');
    $h->assertSame(1, (int) ($db->one('SELECT returned_quantity FROM sale_order_lines WHERE id=?', [$orderLineId])['returned_quantity'] ?? 0), 'completed return updates the historical order line quantity');
    $h->assertSame(9, (int) ($db->one("SELECT on_hand_quantity FROM sale_inventory_items WHERE sku='P11-SKU'")['on_hand_quantity'] ?? 0), 'completed return restocks inventory');

    $fr = $receiptService->issue($orderId, 'fr', 7);
    $frReplay = $receiptService->issue($orderId, 'fr', 99);
    $en = $receiptService->issue($orderId, 'en', 7);
    $h->assertSame((string) $fr['receipt_number'], (string) $frReplay['receipt_number'], 'receipt number is stable for the same financial state and language');
    $h->assertTrue(str_contains((string) $fr['printable_text'], 'Ticket de caisse'), 'French receipt is localized');
    $h->assertTrue(str_contains((string) $en['printable_text'], 'Receipt'), 'English receipt is localized');
    $h->assertSame(7, (int) ($fr['snapshot']['operator_iam_user_id'] ?? 0), 'receipt freezes its operator');
    $h->assertSame(2, count($fr['snapshot']['payments'] ?? []), 'receipt lists successful payments without void/refund noise');
    $h->assertSame(1, count($fr['snapshot']['refunds'] ?? []), 'receipt lists refunds');
    $h->assertTrue(!str_contains(json_encode($fr['snapshot']) ?: '', 'provider_payload'), 'receipt excludes provider payloads');

    $timeline = $timelineService->timeline($orderId, 'fr');
    $kinds = array_values(array_unique(array_column($timeline, 'kind')));
    foreach (['transition', 'payment', 'correction', 'return', 'refund', 'stock', 'integration_event', 'customer_reconciliation'] as $kind) {
        $h->assertTrue(in_array($kind, $kinds, true), 'order timeline includes ' . $kind);
    }
    $h->assertTrue(!str_contains(json_encode($timeline) ?: '', 'provider_payload_json'), 'timeline does not expose sensitive provider payloads');

    $h->expectException(
        fn() => $db->run('DELETE FROM sale_payment_transactions WHERE id=?', [(int) $first['transaction']['id']]),
        PDOException::class,
        'financial transactions cannot be deleted'
    );
    $h->expectException(
        fn() => $db->run('DELETE FROM sale_refunds WHERE id=?', [(int) $refund['refund']['id']]),
        PDOException::class,
        'refund records cannot be deleted'
    );
} finally {
    $db = null;
    gc_collect_cycles();
    test_remove_tree($saleDir);
}

exit($h->finish('UNIT sale internal payments returns receipts timeline'));
