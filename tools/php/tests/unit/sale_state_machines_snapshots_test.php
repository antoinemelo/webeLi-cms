<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Sale\Exceptions\SaleValidationException;
use App\Modules\Sale\Services\SaleStateMachineService;

$h = new TestHarness();
[$dir, $path, $db] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/sale.sql');

try {
    $states = new SaleStateMachineService($db);
    $channelId = (int) ($db->one("SELECT id FROM sale_channels WHERE code='admin-manual'")['id'] ?? 0);
    $h->assertSame(['abandoned', 'converted', 'expired', 'cancelled'], $states->allowedTransitions('cart', 'active'), 'cart transition matrix is explicit');
    $h->assertSame(['confirmed', 'cancelled'], $states->allowedTransitions('order', 'placed'), 'order transition matrix is explicit');
    $h->assertSame(['partially_captured', 'captured', 'cancelled', 'failed', 'expired'], $states->allowedTransitions('payment_intent', 'authorized'), 'payment transition matrix is explicit');
    $h->assertSame(['delivered', 'returned'], $states->allowedTransitions('fulfillment', 'shipped'), 'fulfillment transition matrix is explicit');
    $h->assertSame(['received', 'cancelled'], $states->allowedTransitions('return', 'approved'), 'return transition matrix is explicit');
    $h->assertSame(['succeeded', 'failed', 'cancelled'], $states->allowedTransitions('refund', 'pending'), 'refund transition matrix is explicit');

    $db->run("INSERT INTO sale_carts(site_id,channel_id,status,currency) VALUES(1,?,'active','CHF')", [$channelId]);
    $cartId = (int) $db->lastInsertId();
    $abandoned = $states->transition('cart', $cartId, 'abandoned', 1, 'customer left', 'corr-cart-0001');
    $h->assertSame('abandoned', $abandoned['status'], 'active cart can become abandoned');
    $states->transition('cart', $cartId, 'active', 1, 'customer returned', 'corr-cart-0001');
    $cancelledCart = $states->transition('cart', $cartId, 'cancelled', 1, null, 'corr-cart-0001');
    $h->assertSame('cancelled', $cancelledCart['status'], 'active cart can be cancelled');
    $h->expectException(fn() => $states->transition('cart', $cartId, 'active'), SaleValidationException::class, 'terminal cart transition is refused');
    $db->run("INSERT INTO sale_orders(site_id,channel_id,order_number,source,status,payment_status,currency,source_cart_id,customer_snapshot_json,billing_address_json,shipping_address_json,grand_total_minor) VALUES(1,?,'SOURCE-CART-1','admin','placed','unpaid','CHF',?,'{}','{}','{}',0)", [$channelId, $cartId]);
    $h->expectException(
        fn() => $db->run("INSERT INTO sale_orders(site_id,channel_id,order_number,source,status,payment_status,currency,source_cart_id,customer_snapshot_json,billing_address_json,shipping_address_json,grand_total_minor) VALUES(1,?,'SOURCE-CART-2','admin','placed','unpaid','CHF',?,'{}','{}','{}',0)", [$channelId, $cartId]),
        PDOException::class,
        'unique source cart prevents concurrent double order creation'
    );

    $db->run(
        "INSERT INTO sale_orders(site_id,channel_id,order_number,source,status,payment_status,fulfillment_status,currency,customer_snapshot_json,billing_address_json,shipping_address_json,shipping_method_snapshot_json,grand_total_minor,placed_at,correlation_id)
         VALUES(1,?,'STATE-ORDER-1','admin','placed','unpaid','not_required','CHF','{\"name\":\"Frozen customer\"}','{\"city\":\"Bern\"}','{\"city\":\"Bern\"}','{\"code\":\"pickup\"}',1000,CURRENT_TIMESTAMP,'corr-order-0001')",
        [$channelId]
    );
    $orderId = (int) $db->lastInsertId();
    $db->run(
        "INSERT INTO sale_order_lines(order_id,line_number,business_product_id,business_variant_id,sku,product_name,variant_name,product_type,quantity,unit_price_minor,regular_unit_price_minor,currency,line_subtotal_minor,line_total_minor,snapshot_json)
         VALUES(?,1,10,20,'FROZEN-SKU','Frozen product','Frozen variant','physical',2,500,500,'CHF',1000,1000,'{\"source\":\"catalog snapshot\"}')",
        [$orderId]
    );
    $lineId = (int) $db->lastInsertId();
    $confirmed = $states->transition('order', $orderId, 'confirmed', 1, null, 'corr-order-0001', 0);
    $h->assertSame('confirmed', $confirmed['status'], 'placed order can be confirmed');
    $h->assertSame(1, (int) $confirmed['version'], 'transition increments optimistic version');
    $h->expectException(fn() => $states->transition('order', $orderId, 'completed', 1, null, 'corr-order-0001'), SaleValidationException::class, 'unpaid order cannot complete');
    $h->expectException(fn() => $states->transition('order', $orderId, 'cancelled', 1, null, 'corr-order-0001', 0), SaleValidationException::class, 'stale expected version is refused');
    $db->run("UPDATE sale_orders SET payment_status='paid', paid_total_minor=1000 WHERE id=?", [$orderId]);
    $completed = $states->transition('order', $orderId, 'completed', 1, 'all done', 'corr-order-0001');
    $h->assertSame('completed', $completed['status'], 'paid non-shipping order can complete');
    $h->expectException(fn() => $states->transition('order', $orderId, 'cancelled'), SaleValidationException::class, 'completed order cannot be cancelled');

    $h->expectException(fn() => $db->run("UPDATE sale_orders SET customer_snapshot_json='{\"name\":\"Changed\"}' WHERE id=?", [$orderId]), PDOException::class, 'placed order customer snapshot is immutable');
    $h->expectException(fn() => $db->run("UPDATE sale_order_lines SET product_name='Changed by PIM' WHERE id=?", [$lineId]), PDOException::class, 'order line catalog snapshot is immutable');
    $h->expectException(fn() => $db->run('DELETE FROM sale_order_lines WHERE id=?', [$lineId]), PDOException::class, 'placed order line cannot be deleted');

    $db->run("INSERT INTO sale_payment_intents(site_id,channel_id,order_id,provider_key,status,amount_minor,currency) VALUES(1,?,?,'test','requires_payment',1000,'CHF')", [$channelId, $orderId]);
    $intentId = (int) $db->lastInsertId();
    $states->transition('payment_intent', $intentId, 'authorized', 1, null, 'corr-payment-0001');
    $captured = $states->transition('payment_intent', $intentId, 'captured', 1, null, 'corr-payment-0001');
    $h->assertSame('captured', $captured['status'], 'authorized payment can be captured');
    $h->expectException(fn() => $states->transition('payment_intent', $intentId, 'failed'), SaleValidationException::class, 'captured payment is terminal');

    $db->run("INSERT INTO sale_orders(site_id,channel_id,order_number,source,status,payment_status,fulfillment_status,currency,customer_snapshot_json,billing_address_json,shipping_address_json,shipping_method_snapshot_json,grand_total_minor,placed_at)
              VALUES(1,?,'STATE-ORDER-2','admin','placed','paid','unfulfilled','CHF','{}','{}','{\"city\":\"Lausanne\"}','{\"code\":\"post\"}',500,CURRENT_TIMESTAMP)", [$channelId]);
    $shippingOrderId = (int) $db->lastInsertId();
    $db->run("INSERT INTO sale_order_lines(order_id,line_number,business_product_id,business_variant_id,sku,product_name,product_type,quantity,unit_price_minor,regular_unit_price_minor,currency,line_subtotal_minor,line_total_minor,snapshot_json)
              VALUES(?,1,11,21,'SHIP-SKU','Ship product','physical',1,500,500,'CHF',500,500,'{}')", [$shippingOrderId]);
    $shippingLineId = (int) $db->lastInsertId();
    $fulfillment = $states->createFulfillment($shippingOrderId, [['order_line_id' => $shippingLineId, 'quantity' => 1]], 1, 'corr-fulfill-0001', 'TRACK-1');
    $states->transition('fulfillment', (int) $fulfillment['id'], 'preparing', 1, null, 'corr-fulfill-0001');
    $states->transition('fulfillment', (int) $fulfillment['id'], 'shipped', 1, null, 'corr-fulfill-0001');
    $delivered = $states->transition('fulfillment', (int) $fulfillment['id'], 'delivered', 1, null, 'corr-fulfill-0001');
    $h->assertSame('delivered', $delivered['status'], 'shipment follows pending-preparing-shipped-delivered');
    $h->expectException(fn() => $states->transition('fulfillment', (int) $fulfillment['id'], 'cancelled'), SaleValidationException::class, 'delivered fulfillment cannot be cancelled');
    $h->assertSame(1, (int) ($db->one('SELECT fulfilled_quantity FROM sale_order_lines WHERE id=?', [$shippingLineId])['fulfilled_quantity'] ?? 0), 'shipment updates fulfilled quantity once');
    $h->assertSame('fulfilled', $db->one('SELECT fulfillment_status FROM sale_orders WHERE id=?', [$shippingOrderId])['fulfillment_status'] ?? null, 'fulfillment state remains separate on order');

    $db->run("INSERT INTO sale_returns(order_id,return_number,status) VALUES(?,'RET-STATE-1','requested')", [$shippingOrderId]);
    $returnId = (int) $db->lastInsertId();
    foreach (['approved', 'received', 'completed'] as $status) {
        $return = $states->transition('return', $returnId, $status, 1, null, 'corr-return-0001');
    }
    $h->assertSame('completed', $return['status'], 'return follows requested-approved-received-completed');
    $h->expectException(fn() => $states->transition('return', $returnId, 'approved'), SaleValidationException::class, 'completed return is terminal');

    $db->run("INSERT INTO sale_refunds(order_id,refund_number,status,amount_minor,currency) VALUES(?,'REF-STATE-1','draft',100,'CHF')", [$shippingOrderId]);
    $refundId = (int) $db->lastInsertId();
    $states->transition('refund', $refundId, 'pending', 1, null, 'corr-refund-0001');
    $refund = $states->transition('refund', $refundId, 'succeeded', 1, null, 'corr-refund-0001');
    $h->assertSame('succeeded', $refund['status'], 'refund has an independent state machine');
    $h->expectException(fn() => $states->transition('refund', $refundId, 'pending'), SaleValidationException::class, 'succeeded refund is terminal');

    $orderHistory = $states->history('order', $orderId);
    $h->assertSame(['confirmed', 'completed'], array_column($orderHistory, 'to_status'), 'order transition history is complete and readable');
    $h->assertSame(['corr-order-0001', 'corr-order-0001'], array_column($orderHistory, 'correlation_id'), 'correlation id links order transitions');

} finally {
    $db = null;
    gc_collect_cycles();
    test_remove_tree($dir);
}

exit($h->finish('UNIT sale state machines and snapshots'));
