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

    $h->expectException(
        fn() => $inventory->reserveForCart(1, $cartId, $tracked, 3),
        SaleInventoryException::class,
        'tracked product with insufficient available stock is refused'
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
    $h->assertSame(null, $inventory->reserveForCart(1, $backorderCartId, $backorder, 2), 'zero stock backorder creates no reservation but does not block cart');
    $backorderItem = $saleDb->one('SELECT * FROM sale_inventory_items WHERE business_variant_id = 9003 LIMIT 1');
    $h->assertSame(0, (int) ($backorderItem['on_hand_quantity'] ?? -1), 'backorder item keeps zero on-hand stock');
    $h->assertSame(0, (int) ($backorderItem['reserved_quantity'] ?? -1), 'backorder item does not reserve unavailable stock');

    $inventory->syncCartLineReservation(1, $cartId, 9002, 1);
    $item = $saleDb->one('SELECT * FROM sale_inventory_items WHERE business_variant_id = 9002 LIMIT 1');
    $h->assertSame(1, (int) ($item['reserved_quantity'] ?? 0), 'cart line quantity decrease releases excess reservation');
    $h->assertSame(4, (int) ($item['available_quantity'] ?? 0), 'quantity decrease restores availability');
    $h->assertSame(1, (int) ($saleDb->one('SELECT COUNT(*) AS count FROM sale_outbox WHERE topic = "sale.stock.released"')['count'] ?? 0), 'stock release is queued in sale outbox');

    $saleDb->run('UPDATE sale_stock_reservations SET expires_at = datetime("now", "-1 minute") WHERE cart_id = ? AND status = "active"', [$cartId]);
    $h->assertSame(1, $inventory->expireDueReservations(1), 'expired cart reservation is released by expiry worker');
    $expired = $saleDb->one('SELECT * FROM sale_stock_reservations WHERE cart_id = ? ORDER BY id DESC LIMIT 1', [$cartId]);
    $h->assertSame('expired', $expired['status'] ?? null, 'expired reservation status is stored');
    $item = $saleDb->one('SELECT * FROM sale_inventory_items WHERE business_variant_id = 9002 LIMIT 1');
    $h->assertSame(0, (int) ($item['reserved_quantity'] ?? 0), 'expired reservation clears reserved stock');

    $checkoutCartId = createSaleInventoryTestCart($saleDb, (int) $channel['id']);
    $orderId = createSaleInventoryTestOrder($saleDb, (int) $channel['id'], $checkoutCartId);
    $inventory->reserveForCart(1, $checkoutCartId, $tracked, 2);
    $inventory->consumeCartReservations($checkoutCartId, $orderId);
    $consumed = $saleDb->one('SELECT * FROM sale_stock_reservations WHERE cart_id = ? LIMIT 1', [$checkoutCartId]);
    $h->assertSame('consumed', $consumed['status'] ?? null, 'checkout consumes active reservation');
    $item = $saleDb->one('SELECT * FROM sale_inventory_items WHERE business_variant_id = 9002 LIMIT 1');
    $h->assertSame(3, (int) ($item['on_hand_quantity'] ?? 0), 'checkout sale movement decreases on-hand stock');
    $h->assertSame(0, (int) ($item['reserved_quantity'] ?? 0), 'checkout clears consumed reserved stock');
    $h->assertSame(1, (int) ($saleDb->one('SELECT COUNT(*) AS count FROM sale_stock_movements WHERE movement_type = "sale" AND quantity = -2 AND reference_id = ?', [$orderId])['count'] ?? 0), 'checkout consumption is historized as sale movement');
    $stockConsumed = $saleDb->one('SELECT payload_json FROM sale_outbox WHERE topic = "sale.stock.consumed" ORDER BY id DESC LIMIT 1');
    $stockConsumedEnvelope = json_decode((string) ($stockConsumed['payload_json'] ?? '{}'), true);
    $h->assertSame('sale.stock.consumed', $stockConsumedEnvelope['event_type'] ?? null, 'stock consumed outbox envelope exposes topic');
    $h->assertSame($orderId, (int) ($stockConsumedEnvelope['payload']['order_id'] ?? 0), 'stock consumed payload exposes order id');

    $restocked = $inventory->restockReturn(1, 9002, 2, 'TRACKED', 12, 'customer return', 1);
    $h->assertSame(5, (int) ($restocked['on_hand_quantity'] ?? 0), 'return restock increases on-hand stock');
    $h->assertSame(1, (int) ($saleDb->one('SELECT COUNT(*) AS count FROM sale_stock_movements WHERE movement_type = "return" AND quantity = 2 AND reference_id = 12')['count'] ?? 0), 'return restock is historized as return movement');
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
