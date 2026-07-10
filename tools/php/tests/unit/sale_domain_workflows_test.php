<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Business\Catalog\CatalogPricingService;
use App\Modules\Business\Repositories\BusinessCatalogPricingRepository;
use App\Modules\Business\Repositories\PosCatalogRepository;
use App\Modules\Business\Services\BusinessCatalogSellableReadService;
use App\Modules\Sale\Adapters\BusinessSellableCatalogAdapter;
use App\Modules\Sale\Exceptions\SalePaymentException;
use App\Modules\Sale\Exceptions\SaleValidationException;
use App\Modules\Sale\Payments\PaymentProvider;
use App\Modules\Sale\Payments\PaymentProviderRegistry;
use App\Modules\Sale\Pricing\SalePricingService;
use App\Modules\Sale\Repositories\SaleCartRepository;
use App\Modules\Sale\Repositories\SaleChannelRepository;
use App\Modules\Sale\Repositories\SaleEventRepository;
use App\Modules\Sale\Repositories\SaleIdempotencyRepository;
use App\Modules\Sale\Repositories\SaleInventoryRepository;
use App\Modules\Sale\Repositories\SaleOrderRepository;
use App\Modules\Sale\Repositories\SalePaymentRepository;
use App\Modules\Sale\Services\SaleCartService;
use App\Modules\Sale\Services\SaleCatalogSnapshotService;
use App\Modules\Sale\Services\SaleCheckoutService;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Modules\Sale\Services\SaleEventService;
use App\Modules\Sale\Services\SaleIdempotencyService;
use App\Modules\Sale\Services\SaleInventoryService;
use App\Modules\Sale\Services\SalePaymentService;

$h = new TestHarness();
[$businessDir, $businessPath, $businessDb] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/business.sql');
[$saleDir, $salePath, $saleDb] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/sale.sql');

try {
    $pricingRepository = new BusinessCatalogPricingRepository($businessDb);
    $sellables = new BusinessCatalogSellableReadService(
        $pricingRepository,
        new CatalogPricingService($pricingRepository),
        new PosCatalogRepository($businessDb)
    );
    $saleConnection = new SaleDatabaseConnection($salePath);
    $channels = new SaleChannelRepository($saleConnection);
    $carts = new SaleCartRepository($saleConnection);
    $orders = new SaleOrderRepository($saleConnection);
    $payments = new SalePaymentRepository($saleConnection);
    $events = new SaleEventService(new SaleEventRepository($saleConnection));
    $inventory = new SaleInventoryService(new SaleInventoryRepository($saleConnection), null, null, $events);
    $idempotency = new SaleIdempotencyService(new SaleIdempotencyRepository($saleConnection));
    $cartService = new SaleCartService(
        $carts,
        $channels,
        new SaleCatalogSnapshotService($saleConnection, new BusinessSellableCatalogAdapter($sellables)),
        new SalePricingService(),
        $inventory,
        $events,
        $idempotency
    );
    $checkout = new SaleCheckoutService($saleConnection, $carts, $orders, $inventory, $events, $idempotency);
    $paymentService = new SalePaymentService($payments, $orders, $events);
    $failingPaymentService = new SalePaymentService(
        $payments,
        $orders,
        $events,
        null,
        new PaymentProviderRegistry([
            new class implements PaymentProvider {
                public function key(): string { return 'failing_test'; }
                public function supports(string $operation): bool { return $operation === 'record_payment'; }
                public function createIntent(array $payload): array { return []; }
                public function recordPayment(array $payload): array
                {
                    return [
                        'status' => 'failed',
                        'provider_reference' => 'failing-test',
                        'provider_transaction_id' => 'failing-test',
                        'error_code' => 'declined',
                        'error_message' => 'Declined by test provider.',
                        'payload' => ['provider' => 'failing_test', 'external_call' => false],
                    ];
                }
                public function capture(array $payload): array { return []; }
                public function refund(array $payload): array { return []; }
                public function void(array $payload): array { return []; }
            },
        ])
    );

    $channel = $saleDb->one("SELECT id FROM sale_channels WHERE site_id = 1 AND code = 'admin-manual' LIMIT 1");
    $variant = $businessDb->one("SELECT id FROM business_product_variants WHERE sku = 'DEMO-GOURDE-BLEU' LIMIT 1");
    $cart = $cartService->createCart(1, (int) $channel['id'], ['iam_user_id' => 1]);
    $h->assertSame('active', $cart['status'], 'cart workflow creates active cart');

    $added = $cartService->addLine((int) $cart['id'], (int) $variant['id'], 2, ['idempotency_key' => 'cart-line-demo']);
    $h->assertSame(2, (int) $added['line']['quantity'], 'cart line quantity is stored');
    $h->assertSame(5800, (int) $added['cart']['grand_total_minor'], 'cart total uses stable sellable snapshot');
    $h->assertSame('DEMO-GOURDE-BLEU', $added['line']['sku'], 'cart line keeps SKU snapshot');
    $cartLineSnapshot = json_decode((string) $added['line']['metadata_json'], true);
    $h->assertSame('DEMO-GOURDE-BLEU', $cartLineSnapshot['snapshot']['sku'] ?? null, 'cart line stores sellable snapshot');
    $h->assertSame(2900, (int) ($cartLineSnapshot['snapshot']['unit_price_minor'] ?? 0), 'cart line snapshot freezes unit price');

    $replayed = $cartService->addLine((int) $cart['id'], (int) $variant['id'], 2, ['idempotency_key' => 'cart-line-demo']);
    $linesAfterReplay = $saleDb->one('SELECT quantity FROM sale_cart_lines WHERE cart_id = ?', [(int) $cart['id']]);
    $h->assertSame(2, (int) $linesAfterReplay['quantity'], 'idempotent cart add does not duplicate quantity');
    $h->assertSame((int) $added['line']['id'], (int) $replayed['line']['id'], 'idempotent cart add replays response');

    $discountedCart = $carts->setManualCartAdjustment((int) $cart['id'], ['mode' => 'amount', 'value' => -5]);
    $h->assertSame(5300, (int) $discountedCart['grand_total_minor'], 'manual POS cart discount updates total');
    $h->assertSame(500, (int) $discountedCart['adjustments'][0]['amount_minor'], 'manual POS cart discount is stored');

    $reservation = $saleDb->one('SELECT * FROM sale_stock_reservations WHERE cart_id = ? AND status = "active"', [(int) $cart['id']]);
    $h->assertSame(2, (int) $reservation['quantity'], 'tracked variant reserves stock');
    $inventoryItem = $saleDb->one('SELECT * FROM sale_inventory_items WHERE id = ?', [(int) $reservation['inventory_item_id']]);
    $h->assertSame(23, (int) $inventoryItem['available_quantity'], 'reservation decreases available stock');

    $order = $checkout->placeOrder((int) $cart['id'], ['idempotency_key' => 'checkout-demo', 'source' => 'admin']);
    $h->assertSame('placed', $order['status'], 'checkout creates placed order');
    $h->assertSame(5300, (int) $order['grand_total_minor'], 'order copies adjusted cart total');
    $h->assertSame('converted', $carts->requireCart((int) $cart['id'])['status'], 'checkout converts cart');
    $h->assertSame(1, (int) ($saleDb->one('SELECT COUNT(*) AS count FROM sale_order_lines WHERE order_id = ?', [(int) $order['id']])['count'] ?? 0), 'checkout copies order lines');
    $h->assertSame(500, (int) ($saleDb->one('SELECT amount_minor FROM sale_order_adjustments WHERE order_id = ? LIMIT 1', [(int) $order['id']])['amount_minor'] ?? 0), 'checkout copies cart adjustment');
    $orderLine = $saleDb->one('SELECT * FROM sale_order_lines WHERE order_id = ? LIMIT 1', [(int) $order['id']]);
    $orderLineSnapshot = json_decode((string) ($orderLine['snapshot_json'] ?? '{}'), true);
    $h->assertSame('DEMO-GOURDE-BLEU', $orderLine['sku'] ?? null, 'order line keeps SKU snapshot');
    $h->assertSame('Gourde demo', $orderLine['product_name'] ?? null, 'order line freezes product name');
    $h->assertSame(2900, (int) ($orderLine['unit_price_minor'] ?? 0), 'order line freezes unit price');
    $h->assertSame(2900, (int) ($orderLineSnapshot['snapshot']['unit_price_minor'] ?? 0), 'order line snapshot JSON freezes unit price');
    $businessDb->run("UPDATE business_products SET name = 'Gourde modifiée après commande' WHERE id = ?", [(int) $orderLine['business_product_id']]);
    $businessDb->run('UPDATE business_product_base_prices SET amount = 99 WHERE product_id = ? AND price_kind = "sale"', [(int) $orderLine['business_product_id']]);
    $frozenOrderLine = $saleDb->one('SELECT * FROM sale_order_lines WHERE id = ? LIMIT 1', [(int) $orderLine['id']]);
    $h->assertSame('Gourde demo', $frozenOrderLine['product_name'] ?? null, 'product edit after checkout does not mutate order line name');
    $h->assertSame(2900, (int) ($frozenOrderLine['unit_price_minor'] ?? 0), 'product price edit after checkout does not mutate order line price');
    $h->assertSame('consumed', (string) ($saleDb->one('SELECT status FROM sale_stock_reservations WHERE id = ?', [(int) $reservation['id']])['status'] ?? ''), 'checkout consumes stock reservation');
    $inventoryItem = $saleDb->one('SELECT * FROM sale_inventory_items WHERE id = ?', [(int) $reservation['inventory_item_id']]);
    $h->assertSame(23, (int) $inventoryItem['on_hand_quantity'], 'checkout decreases on-hand stock through movement');
    $h->assertSame(0, (int) $inventoryItem['reserved_quantity'], 'checkout clears reserved stock');

    $h->expectException(
        fn() => $checkout->placeOrder((int) $cart['id']),
        SaleValidationException::class,
        'cart cannot be converted twice'
    );

    $blockedCart = $cartService->createCart(1, (int) $channel['id'], ['iam_user_id' => 1]);
    $businessDb->run('UPDATE business_product_variants SET status = "draft" WHERE id = ?', [(int) $variant['id']]);
    $h->expectException(
        fn() => $cartService->addLine((int) $blockedCart['id'], (int) $variant['id'], 1),
        InvalidArgumentException::class,
        'Sale refuses a non sellable catalog variant'
    );

    $h->expectException(
        fn() => $failingPaymentService->recordManualPayment((int) $order['id'], 1000, 1, ['provider_key' => 'failing_test']),
        SalePaymentException::class,
        'failed provider payment is rejected'
    );
    $h->assertSame(1, (int) ($saleDb->one('SELECT COUNT(*) AS count FROM sale_outbox WHERE topic = "sale.payment.failed"')['count'] ?? 0), 'failed payment event is queued in sale outbox');

    $payment = $paymentService->recordManualPayment((int) $order['id'], 2000, 1);
    $h->assertSame('partially_paid', $payment['order']['payment_status'], 'partial payment updates status');
    $payment = $paymentService->recordManualPayment((int) $order['id'], 3300, 1);
    $h->assertSame('paid', $payment['order']['payment_status'], 'full payment updates status');
    $h->expectException(
        fn() => $paymentService->recordManualPayment((int) $order['id'], 1, 1),
        SalePaymentException::class,
        'payment cannot exceed order total'
    );

    $eventTypes = array_map(
        static fn(array $row): string => (string) $row['event_type'],
        $saleDb->all('SELECT event_type FROM sale_events ORDER BY id ASC')
    );
    $h->assertTrue(in_array('sale.cart.created', $eventTypes, true), 'cart creation event emitted');
    $h->assertTrue(in_array('sale.cart.line_added', $eventTypes, true), 'cart line event emitted');
    $h->assertTrue(in_array('sale.order.placed', $eventTypes, true), 'order placed event emitted');
    $h->assertTrue(in_array('sale.payment.recorded', $eventTypes, true), 'payment event emitted');
    $outboxTopics = array_map(
        static fn(array $row): string => (string) $row['topic'],
        $saleDb->all('SELECT topic FROM sale_outbox ORDER BY id ASC')
    );
    $h->assertTrue(in_array('sale.order.placed', $outboxTopics, true), 'order placed event is queued in sale outbox');
    $h->assertTrue(in_array('sale.payment.recorded', $outboxTopics, true), 'payment event is queued in sale outbox');
    $orderOutbox = $saleDb->one('SELECT payload_json FROM sale_outbox WHERE topic = "sale.order.placed" LIMIT 1');
    $orderEnvelope = json_decode((string) ($orderOutbox['payload_json'] ?? '{}'), true);
    $h->assertSame(1, (int) ($orderEnvelope['schema_version'] ?? 0), 'sale outbox envelope exposes schema version');
    $h->assertSame('sale.order.placed', $orderEnvelope['event_type'] ?? null, 'sale outbox envelope exposes event type');
    $h->assertSame('order', $orderEnvelope['aggregate']['type'] ?? null, 'sale outbox envelope exposes aggregate type');
    $h->assertSame((string) $order['order_number'], $orderEnvelope['payload']['order_number'] ?? null, 'sale order outbox payload exposes order number');
    $paymentOutbox = $saleDb->one('SELECT payload_json FROM sale_outbox WHERE topic = "sale.payment.recorded" ORDER BY id DESC LIMIT 1');
    $paymentEnvelope = json_decode((string) ($paymentOutbox['payload_json'] ?? '{}'), true);
    $h->assertSame('paid', $paymentEnvelope['payload']['payment_status'] ?? null, 'sale payment outbox payload exposes payment status');
} finally {
    $businessDb = null;
    $saleDb = null;
    gc_collect_cycles();
    test_remove_tree($businessDir);
    test_remove_tree($saleDir);
}

exit($h->finish('UNIT sale domain workflows'));
