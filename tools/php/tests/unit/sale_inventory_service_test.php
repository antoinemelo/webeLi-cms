<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Sale\Exceptions\SaleInventoryException;
use App\Modules\Sale\Repositories\SaleEventRepository;
use App\Modules\Sale\Repositories\SaleInventoryRepository;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Modules\Sale\Services\SaleEventService;
use App\Modules\Sale\Services\SaleInventoryService;
use App\Modules\Sale\Services\SaleStockMovementService;
use App\Modules\Sale\Services\SaleStockReservationService;

$h = new TestHarness();
[$saleDir, $salePath, $saleDb] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/sale.sql');

try {
    $connection = new SaleDatabaseConnection($salePath);
    $repository = new SaleInventoryRepository($connection);
    $events = new SaleEventService(new SaleEventRepository($connection));
    $inventory = new SaleInventoryService(
        $repository,
        new SaleStockReservationService($repository),
        new SaleStockMovementService($repository),
        $events
    );
    $channel = $saleDb->one("SELECT id FROM sale_channels WHERE site_id = 1 AND code = 'admin-manual' LIMIT 1");

    $cartId = createSaleInventoryTestCart($saleDb, (int) $channel['id']);
    $untracked = [
        'business_variant_id' => 9001,
        'sku' => 'NO-STOCK',
        'track_stock' => false,
        'metadata' => ['available_quantity' => 0],
    ];
    $h->assertSame(null, $inventory->reserveForCart(1, $cartId, $untracked, 2), 'untracked product does not reserve stock');
    $h->assertSame(0, (int) ($saleDb->one('SELECT COUNT(*) AS count FROM sale_stock_reservations WHERE cart_id = ?', [$cartId])['count'] ?? 0), 'untracked product creates no reservation row');

    $tracked = [
        'business_variant_id' => 9002,
        'sku' => 'TRACKED',
        'track_stock' => true,
        'metadata' => ['available_quantity' => 5],
    ];
    $reservation = $inventory->reserveForCart(1, $cartId, $tracked, 3);
    $h->assertSame('active', $reservation['status'] ?? null, 'tracked product creates active reservation');
    $h->assertTrue(($reservation['expires_at'] ?? null) !== null, 'cart reservation has an expiry timestamp');
    $item = $saleDb->one('SELECT * FROM sale_inventory_items WHERE business_variant_id = 9002 LIMIT 1');
    $h->assertSame(5, (int) ($item['on_hand_quantity'] ?? 0), 'sale stock starts from sellable snapshot quantity');
    $h->assertSame(3, (int) ($item['reserved_quantity'] ?? 0), 'reservation increases reserved stock');
    $h->assertSame(2, (int) ($item['available_quantity'] ?? 0), 'reservation decreases available stock');
    $h->assertSame(1, (int) ($saleDb->one('SELECT COUNT(*) AS count FROM sale_stock_movements WHERE movement_type = "reservation" AND quantity = 3')['count'] ?? 0), 'reservation is historized as movement');
    $h->assertSame(1, (int) ($saleDb->one('SELECT COUNT(*) AS count FROM sale_outbox WHERE topic = "sale.stock.reserved"')['count'] ?? 0), 'stock reservation is queued in sale outbox');

    $retryReservation = $inventory->reserveForCart(1, $cartId, $tracked, 3);
    $h->assertSame((int) $reservation['id'], (int) $retryReservation['id'], 'reservation retry replays the deterministic reservation');
    $h->assertSame(3, (int) ($saleDb->one('SELECT reserved_quantity FROM sale_inventory_items WHERE business_variant_id=9002')['reserved_quantity'] ?? 0), 'reservation retry does not reserve twice');
    $concurrentCartId = createSaleInventoryTestCart($saleDb, (int) $channel['id']);
    $h->expectException(
        fn() => $inventory->reserveForCart(1, $concurrentCartId, $tracked, 3),
        SaleInventoryException::class,
        'concurrent cart cannot reserve stock already held by the first cart'
    );

    $backorderCartId = createSaleInventoryTestCart($saleDb, (int) $channel['id']);
    $backorder = [
        'business_variant_id' => 9003,
        'sku' => 'BACKORDER',
        'track_stock' => true,
        'allow_backorder' => true,
        'backorder_delivery_days' => 10,
        'metadata' => ['available_quantity' => 0, 'allow_backorder' => true, 'backorder_delivery_days' => 10],
    ];
    $backorderReservation = $inventory->reserveForCart(1, $backorderCartId, $backorder, 2);
    $h->assertSame('backorder', $backorderReservation['_reservation_kind'] ?? null, 'zero stock creates an explicit backorder rather than an implicit pass-through');
    $h->assertSame(2, (int) ($backorderReservation['_backorder_quantity'] ?? 0), 'explicit backorder preserves the requested quantity');
    $h->assertSame(1, (int) ($saleDb->one('SELECT COUNT(*) AS count FROM sale_stock_backorders WHERE cart_id=? AND quantity=2 AND status="active"', [$backorderCartId])['count'] ?? 0), 'backorder is stored with its lifecycle');
    $backorderItem = $saleDb->one('SELECT * FROM sale_inventory_items WHERE business_variant_id = 9003 LIMIT 1');
    $h->assertSame(0, (int) ($backorderItem['on_hand_quantity'] ?? -1), 'backorder item keeps zero on-hand stock');
    $h->assertSame(0, (int) ($backorderItem['reserved_quantity'] ?? -1), 'backorder item does not reserve unavailable stock');

    $inventory->syncCartLineReservation(1, $cartId, 9002, 1);
    $item = $saleDb->one('SELECT * FROM sale_inventory_items WHERE business_variant_id = 9002 LIMIT 1');
    $h->assertSame(1, (int) ($item['reserved_quantity'] ?? 0), 'cart line quantity decrease releases excess reservation');
    $h->assertSame(4, (int) ($item['available_quantity'] ?? 0), 'quantity decrease restores availability');
    $h->assertSame(1, (int) ($saleDb->one('SELECT COUNT(*) AS count FROM sale_outbox WHERE topic = "sale.stock.released"')['count'] ?? 0), 'stock release is queued in sale outbox');

    $repository->confirmCartReservations($cartId);
    $saleDb->run('UPDATE sale_stock_reservations SET expires_at = datetime("now", "-1 minute") WHERE cart_id = ? AND status = "active"', [$cartId]);
    $saleDb->run('UPDATE sale_stock_reservations SET expires_at = datetime("now", "-1 minute") WHERE cart_id = ? AND status = "confirmed"', [$cartId]);
    $h->assertSame(1, $inventory->expireDueReservations(1), 'expired cart reservation is released by expiry worker');
    $expired = $saleDb->one('SELECT * FROM sale_stock_reservations WHERE cart_id = ? ORDER BY id DESC LIMIT 1', [$cartId]);
    $h->assertSame('expired', $expired['status'] ?? null, 'expired reservation status is stored');
    $item = $saleDb->one('SELECT * FROM sale_inventory_items WHERE business_variant_id = 9002 LIMIT 1');
    $h->assertSame(0, (int) ($item['reserved_quantity'] ?? 0), 'expired reservation clears reserved stock');

    $checkoutCartId = createSaleInventoryTestCart($saleDb, (int) $channel['id']);
    $orderId = createSaleInventoryTestOrder($saleDb, (int) $channel['id'], $checkoutCartId);
    $inventory->reserveForCart(1, $checkoutCartId, $tracked, 2);
    $repository->confirmCartReservations($checkoutCartId);
    $h->assertSame('confirmed', $saleDb->one('SELECT status FROM sale_stock_reservations WHERE cart_id=?', [$checkoutCartId])['status'] ?? null, 'checkout confirmation is explicit');
    $inventory->consumeCartReservations($checkoutCartId, $orderId);
    $consumed = $saleDb->one('SELECT * FROM sale_stock_reservations WHERE cart_id = ? LIMIT 1', [$checkoutCartId]);
    $h->assertSame('consumed', $consumed['status'] ?? null, 'checkout consumes active reservation');
    $item = $saleDb->one('SELECT * FROM sale_inventory_items WHERE business_variant_id = 9002 LIMIT 1');
    $h->assertSame(3, (int) ($item['on_hand_quantity'] ?? 0), 'checkout sale movement decreases on-hand stock');
    $h->assertSame(0, (int) ($item['reserved_quantity'] ?? 0), 'checkout clears consumed reserved stock');
    $h->assertSame(1, (int) ($saleDb->one('SELECT COUNT(*) AS count FROM sale_stock_movements WHERE movement_type = "sale" AND quantity = -2 AND reference_id = ?', [$orderId])['count'] ?? 0), 'checkout sale is historized as immutable movement');
    $inventory->consumeCartReservations($checkoutCartId, $orderId);
    $h->assertSame(3, (int) ($saleDb->one('SELECT on_hand_quantity FROM sale_inventory_items WHERE business_variant_id=9002')['on_hand_quantity'] ?? 0), 'checkout retry cannot consume stock twice');
    $stockConsumed = $saleDb->one('SELECT payload_json FROM sale_outbox WHERE topic = "sale.stock.consumed" ORDER BY id DESC LIMIT 1');
    $stockConsumedEnvelope = json_decode((string) ($stockConsumed['payload_json'] ?? '{}'), true);
    $h->assertSame('sale.stock.consumed', $stockConsumedEnvelope['event_type'] ?? null, 'stock consumed outbox envelope exposes topic');
    $h->assertSame($orderId, (int) ($stockConsumedEnvelope['payload']['order_id'] ?? 0), 'stock consumed payload exposes order id');

    $restocked = $inventory->restockReturn(1, 9002, 2, 'TRACKED', 12, 'customer return', 1);
    $h->assertSame(5, (int) ($restocked['on_hand_quantity'] ?? 0), 'return restock increases on-hand stock');
    $h->assertSame(1, (int) ($saleDb->one('SELECT COUNT(*) AS count FROM sale_stock_movements WHERE movement_type = "return" AND quantity = 2 AND reference_id = 12')['count'] ?? 0), 'return restock is historized as return movement');
    $saleDb->run("INSERT INTO sale_stock_locations(site_id,code,name,location_type,status) VALUES(1,'secondary','Secondary','external','active')");
    $secondaryLocationId = (int) $saleDb->lastInsertId();
    $transfer = $inventory->transfer(1, 9002, 2, (int) $restocked['stock_location_id'], $secondaryLocationId, 'test-transfer-1', 1);
    $h->assertSame(3, (int) $transfer['from']['on_hand_quantity'], 'transfer decrements its source location');
    $h->assertSame(2, (int) $transfer['to']['on_hand_quantity'], 'transfer increments its destination location');
    $retryTransfer = $inventory->transfer(1, 9002, 2, (int) $restocked['stock_location_id'], $secondaryLocationId, 'test-transfer-1', 1);
    $h->assertSame(3, (int) $retryTransfer['from']['on_hand_quantity'], 'transfer retry does not move stock twice');
    $h->assertSame(2, (int) ($saleDb->one("SELECT COUNT(*) AS count FROM sale_stock_movements WHERE transfer_key='test-transfer-1'")['count'] ?? 0), 'transfer writes one immutable movement per location');

    $bundleCartId = createSaleInventoryTestCart($saleDb, (int) $channel['id']);
    $bundleCart = $saleDb->one('SELECT * FROM sale_carts WHERE id=?', [$bundleCartId]);
    $bundleSnapshot = [
        'is_bundle' => true,
        'bundle_stock_strategy' => 'COMPONENT_DERIVED',
        'bundle_inventory_plan' => [
            ['business_variant_id' => 9101, 'sellable_id' => 9101, 'sku' => 'BUNDLE-COMP-A', 'quantity_per_bundle' => 2, 'track_stock' => true, 'available_quantity' => 4],
            ['business_variant_id' => 9102, 'sellable_id' => 9102, 'sku' => 'BUNDLE-COMP-B', 'quantity_per_bundle' => 1, 'track_stock' => true, 'available_quantity' => 3],
        ],
    ];
    $bundleLine = [
        'business_variant_id' => 9199,
        'sellable_id' => 9199,
        'product_type' => 'bundle',
        'quantity' => 2,
        'metadata_json' => json_encode(['snapshot' => $bundleSnapshot], JSON_UNESCAPED_SLASHES),
    ];
    $bundleReservations = $inventory->prepareCartForCheckout($bundleCart, [$bundleLine], false, 1800, 'payment_capture');
    $h->assertSame(2, count($bundleReservations), 'derived bundle reserves each flattened tracked component');
    $h->assertSame(2, (int) ($saleDb->one("SELECT COUNT(*) AS count FROM sale_stock_reservations WHERE cart_id=? AND demand_kind='bundle_component' AND bundle_parent_sellable_id=9199", [$bundleCartId])['count'] ?? 0), 'component reservations retain their parent bundle');
    $h->assertSame(4, (int) ($saleDb->one('SELECT quantity FROM sale_stock_reservations r INNER JOIN sale_inventory_items i ON i.id=r.inventory_item_id WHERE r.cart_id=? AND i.sellable_id=9101', [$bundleCartId])['quantity'] ?? 0), 'bundle component demand multiplies line quantity by its ratio');
    $operatorReservations = $inventory->listReservations(1, ['q' => (string) $bundleCartId], 200, 0);
    $h->assertSame('bundle_component', $operatorReservations['items'][0]['demand_kind'] ?? null, 'operator reservation list explains component demand');
    $h->assertSame(9199, (int) ($operatorReservations['items'][0]['bundle_parent_sellable_id'] ?? 0), 'operator reservation list links demand to its parent bundle');

    $competingCartId = createSaleInventoryTestCart($saleDb, (int) $channel['id']);
    $competingCart = $saleDb->one('SELECT * FROM sale_carts WHERE id=?', [$competingCartId]);
    $h->expectException(
        fn() => $inventory->prepareCartForCheckout($competingCart, [array_replace($bundleLine, ['quantity' => 1])], false, 1800, 'payment_capture'),
        SaleInventoryException::class,
        'concurrent bundle cannot claim a limiting component already reserved by another cart'
    );

    $bundleOrderId = createSaleInventoryTestOrder($saleDb, (int) $channel['id'], $bundleCartId);
    $inventory->consumeCartReservations($bundleCartId, $bundleOrderId);
    $h->assertSame(2, (int) ($saleDb->one("SELECT COUNT(*) AS count FROM sale_stock_movements WHERE reference_id=? AND movement_type='bundle_consumption'", [$bundleOrderId])['count'] ?? 0), 'checkout consumes components with an explicit bundle movement type');
    $inventory->consumeCartReservations($bundleCartId, $bundleOrderId);
    $h->assertSame(2, (int) ($saleDb->one("SELECT COUNT(*) AS count FROM sale_stock_movements WHERE reference_id=? AND movement_type='bundle_consumption'", [$bundleOrderId])['count'] ?? 0), 'bundle component consumption is idempotent');
    $h->assertSame(0, (int) ($saleDb->one('SELECT on_hand_quantity FROM sale_inventory_items WHERE sellable_id=9101')['on_hand_quantity'] ?? -1), 'bundle consumption decrements the limiting component stock');

    $ownCartId = createSaleInventoryTestCart($saleDb, (int) $channel['id']);
    $ownCart = $saleDb->one('SELECT * FROM sale_carts WHERE id=?', [$ownCartId]);
    $ownLine = ['business_variant_id' => 9201, 'sellable_id' => 9201, 'product_type' => 'bundle', 'quantity' => 1, 'metadata_json' => json_encode(['snapshot' => ['is_bundle' => true, 'bundle_stock_strategy' => 'OWN_STOCK', 'track_stock' => false, 'sku' => 'OWN-BUNDLE', 'metadata' => ['available_quantity' => 2]]])];
    $h->assertSame(1, count($inventory->prepareCartForCheckout($ownCart, [$ownLine], false, 1800, 'payment_capture')), 'own-stock bundle reserves its own sellable');
    $h->assertSame('sellable', $saleDb->one('SELECT demand_kind FROM sale_stock_reservations WHERE cart_id=?', [$ownCartId])['demand_kind'] ?? null, 'own-stock demand is not reported as a component demand');

    $noneCartId = createSaleInventoryTestCart($saleDb, (int) $channel['id']);
    $noneCart = $saleDb->one('SELECT * FROM sale_carts WHERE id=?', [$noneCartId]);
    $noneLine = ['business_variant_id' => 9202, 'sellable_id' => 9202, 'product_type' => 'bundle', 'quantity' => 3, 'metadata_json' => json_encode(['snapshot' => ['is_bundle' => true, 'bundle_stock_strategy' => 'NON_STOCKED']])];
    $h->assertSame([], $inventory->prepareCartForCheckout($noneCart, [$noneLine], false, 1800, 'payment_capture'), 'non-stocked bundle creates no reservation or physical movement');

    $saleDb->run('UPDATE sale_inventory_channel_configs SET stock_location_id=? WHERE channel_id=? AND site_id=1', [$secondaryLocationId, (int) $channel['id']]);
    $secondaryCartId = createSaleInventoryTestCart($saleDb, (int) $channel['id']);
    $secondaryCart = $saleDb->one('SELECT * FROM sale_carts WHERE id=?', [$secondaryCartId]);
    $secondaryLine = ['business_variant_id' => 9300, 'sellable_id' => 9300, 'product_type' => 'bundle', 'quantity' => 1, 'metadata_json' => json_encode(['snapshot' => ['is_bundle' => true, 'bundle_stock_strategy' => 'COMPONENT_DERIVED', 'bundle_inventory_plan' => [['business_variant_id' => 9301, 'sellable_id' => 9301, 'sku' => 'SECONDARY-COMP', 'quantity_per_bundle' => 1, 'track_stock' => true, 'available_quantity' => 2]]]])];
    $inventory->prepareCartForCheckout($secondaryCart, [$secondaryLine], false, 1800, 'payment_capture');
    $h->assertSame($secondaryLocationId, (int) ($saleDb->one('SELECT stock_location_id FROM sale_inventory_items WHERE sellable_id=9301')['stock_location_id'] ?? 0), 'bundle components reserve in the location configured for the sales channel');
} finally {
    $saleDb = null;
    gc_collect_cycles();
    test_remove_tree($saleDir);
}

exit($h->finish('UNIT sale inventory service'));

function createSaleInventoryTestCart(App\Core\Database $db, int $channelId): int
{
    $db->run(
        'INSERT INTO sale_carts(site_id, channel_id, status, currency, customer_snapshot_json, billing_address_json, shipping_address_json)
         VALUES(1, ?, "active", "CHF", "{}", "{}", "{}")',
        [$channelId]
    );
    return (int) $db->lastInsertId();
}

function createSaleInventoryTestOrder(App\Core\Database $db, int $channelId, int $cartId): int
{
    $db->run(
        'INSERT INTO sale_orders(site_id, channel_id, order_number, source, status, payment_status, currency, customer_snapshot_json, billing_address_json, shipping_address_json, subtotal_minor, discount_total_minor, tax_total_minor, grand_total_minor, placed_at, metadata_json)
         VALUES(1, ?, ?, "admin", "placed", "unpaid", "CHF", "{}", "{}", "{}", 0, 0, 0, 0, CURRENT_TIMESTAMP, ?)',
        [$channelId, 'SALE-STOCK-' . bin2hex(random_bytes(3)), json_encode(['source_cart_id' => $cartId], JSON_UNESCAPED_SLASHES)]
    );
    return (int) $db->lastInsertId();
}
