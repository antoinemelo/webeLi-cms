<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Sale\Exceptions\SaleInventoryException;
use App\Modules\Sale\Repositories\SaleInventoryRepository;
use App\Modules\Business\Services\BusinessDatabaseConnection;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Modules\Sale\Services\SaleInventoryService;

$h = new TestHarness();
[$dir, $path, $db] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/sale.sql');

try {
    $repository = new SaleInventoryRepository(new SaleDatabaseConnection($path));
    $secondRepository = new SaleInventoryRepository(new SaleDatabaseConnection($path));
    $webChannel = $db->one("SELECT * FROM sale_channels WHERE code='web-main'");
    $posChannel = $db->one("SELECT * FROM sale_channels WHERE code='pos-main'");
    $webCartId = reservation_test_cart($db, (int) $webChannel['id'], 'web');
    $webCart = $db->one('SELECT * FROM sale_carts WHERE id=?', [$webCartId]) ?? [];
    $posCartId = reservation_test_cart($db, (int) $posChannel['id'], 'pos');
    $posCart = $db->one('SELECT * FROM sale_carts WHERE id=?', [$posCartId]) ?? [];

    $webPolicy = $repository->reservationPolicyForCart($webCart);
    $posPolicy = $repository->reservationPolicyForCart($posCart);
    $h->assertSame('checkout_start', $webPolicy['reservation_policy'] ?? null, 'web reserves at checkout start by default');
    $h->assertSame('order_placement', $posPolicy['reservation_policy'] ?? null, 'POS reserves atomically at order placement by default');
    $h->assertSame('disabled', $posPolicy['backorder_policy'] ?? null, 'POS never backorders implicitly');
    $h->assertSame('pos', $posPolicy['fulfillment_mode'] ?? null, 'POS uses its dedicated fulfillment context');
    $pickupPolicy = $repository->reservationPolicyForCart(array_replace($webCart, ['shipping_method_snapshot_json' => '{"type":"pickup"}']));
    $h->assertSame('pickup', $pickupPolicy['fulfillment_mode'] ?? null, 'pickup uses its explicit fulfillment context');
    $h->assertSame('disabled', $pickupPolicy['backorder_policy'] ?? null, 'pickup never promises unavailable delivery stock');

    $updatedPolicy = $repository->updateReservationPolicy(1, (int) $webChannel['id'], [
        'reservation_policy' => 'payment_authorization', 'reservation_ttl_seconds' => 600,
        'reservation_renewal_window_seconds' => 120, 'reservation_max_lifetime_seconds' => 3600,
        'backorder_policy' => 'sellable', 'show_exact_quantity' => false,
    ]);
    $h->assertSame('payment_authorization', $updatedPolicy['reservation_policy'] ?? null, 'all documented reservation triggers are configurable');
    $repository->updateReservationPolicy(1, (int) $webChannel['id'], ['reservation_policy' => 'checkout_start']);

    $lastSnapshot = ['business_variant_id' => 9101, 'sellable_id' => 9101, 'sku' => 'LAST-ONE', 'track_stock' => true, 'allow_backorder' => false, 'metadata' => ['available_quantity' => 1]];
    $first = $repository->reserveForCart(1, $webCartId, $lastSnapshot, 1, 1800, $webPolicy);
    $otherCartId = reservation_test_cart($db, (int) $webChannel['id'], 'web');
    try {
        $secondRepository->reserveForCart(1, $otherCartId, $lastSnapshot, 1, 1800, $webPolicy);
        $h->assertTrue(false, 'second client must not reserve the last article');
    } catch (SaleInventoryException $error) {
        $h->assertSame('sale.stock_insufficient', $error->getMessage(), 'atomic claim rejects the second client');
        $h->assertSame(9101, (int) ($error->context()['sellable_id'] ?? 0), 'availability error identifies the affected variant');
        $h->assertTrue(in_array('reduce_quantity', $error->context()['recovery_options'] ?? [], true), 'availability error provides a cart-preserving recovery');
    }
    $retry = $repository->reserveForCart(1, $webCartId, $lastSnapshot, 1, 1800, $webPolicy);
    $h->assertSame((int) $first['id'], (int) $retry['id'], 'retry after a simulated crash replays the same reservation');
    $h->assertSame(1, (int) ($db->one('SELECT COUNT(*) AS count FROM sale_stock_reservations WHERE inventory_item_id=?', [(int) $first['inventory_item_id']])['count'] ?? 0), 'retry never duplicates the hold');

    $renewSnapshot = ['business_variant_id' => 9102, 'sellable_id' => 9102, 'sku' => 'RENEW', 'track_stock' => true, 'metadata' => ['available_quantity' => 2]];
    $renewCartId = reservation_test_cart($db, (int) $webChannel['id'], 'web');
    $renewedReservation = $repository->reserveForCart(1, $renewCartId, $renewSnapshot, 1, 60, $webPolicy + ['reservation_ttl_seconds' => 60, 'reservation_renewal_window_seconds' => 120, 'reservation_max_lifetime_seconds' => 600]);
    $db->run("UPDATE sale_stock_reservations SET expires_at=datetime('now','+20 seconds') WHERE id=?", [(int) $renewedReservation['id']]);
    $renewed = $repository->renewReservation(1, (int) $renewedReservation['id'], 'physical', 180);
    $h->assertSame(true, $renewed['_renewed'] ?? null, 'renewal extends a reservation inside its controlled window');
    $h->assertSame(1, (int) ($renewed['renewal_count'] ?? 0), 'renewal is auditable');
    $notDue = $repository->renewReservation(1, (int) $renewedReservation['id'], 'physical', 180);
    $h->assertSame(false, $notDue['_renewed'] ?? null, 'early retry does not extend the TTL repeatedly');

    $expireSnapshot = ['business_variant_id' => 9103, 'sellable_id' => 9103, 'sku' => 'EXPIRE', 'track_stock' => true, 'metadata' => ['available_quantity' => 2]];
    $expireCartId = reservation_test_cart($db, (int) $webChannel['id'], 'web');
    $expiring = $repository->reserveForCart(1, $expireCartId, $expireSnapshot, 2, 60, $webPolicy);
    $db->run("UPDATE sale_stock_reservations SET expires_at=datetime('now','-1 second') WHERE id=?", [(int) $expiring['id']]);
    $h->assertSame(1, $repository->expireDueReservations(1), 'expiry worker releases the due reservation');
    $h->assertSame(0, $repository->expireDueReservations(1), 'worker retry never releases twice');
    $h->assertSame('expired', $db->one('SELECT status FROM sale_stock_reservations WHERE id=?', [(int) $expiring['id']])['status'] ?? null, 'expired lifecycle is explicit');

    $backorderSnapshot = ['business_variant_id' => 9104, 'sellable_id' => 9104, 'sku' => 'BACKORDER', 'track_stock' => true, 'allow_backorder' => true, 'backorder_delivery_days' => 8, 'metadata' => ['available_quantity' => 0, 'allow_backorder' => true]];
    $backorderCartId = reservation_test_cart($db, (int) $webChannel['id'], 'web');
    $backorder = $repository->reserveForCart(1, $backorderCartId, $backorderSnapshot, 3, 1800, $webPolicy);
    $h->assertSame('backorder', $backorder['_reservation_kind'] ?? null, 'backorder is explicit when both channel and sellable allow it');
    $h->assertSame(8, (int) ($backorder['delivery_lead_time_days'] ?? 0), 'backorder exposes its lead time before checkout');
    $blockedCartId = reservation_test_cart($db, (int) $posChannel['id'], 'pos');
    $h->expectException(fn() => $repository->reserveForCart(1, $blockedCartId, $backorderSnapshot, 1, 300, $posPolicy), SaleInventoryException::class, 'POS backorder policy blocks unavailable stock');

    $orderId = reservation_test_order($db, (int) $webChannel['id'], $webCartId);
    $repository->confirmCartReservations($webCartId);
    $repository->consumeCartReservations($webCartId, $orderId);
    $consumed = $repository->releaseReservationById(1, (int) $first['id'], 'physical', false, 'retry release', 7);
    $h->assertSame('consumed', $consumed['status'] ?? null, 'a consumed reservation can never be released');
    $h->assertSame(0, (int) ($db->one('SELECT reserved_quantity FROM sale_inventory_items WHERE id=?', [(int) $first['inventory_item_id']])['reserved_quantity'] ?? -1), 'consumed reservation leaves no negative reserved quantity');

    $cancelSnapshot = ['business_variant_id' => 9105, 'sellable_id' => 9105, 'sku' => 'CANCEL', 'track_stock' => true, 'metadata' => ['available_quantity' => 2]];
    $cancelCartId = reservation_test_cart($db, (int) $webChannel['id'], 'web');
    $cancellable = $repository->reserveForCart(1, $cancelCartId, $cancelSnapshot, 1, 1800, $webPolicy);
    $cancelled = $repository->releaseReservationById(1, (int) $cancellable['id'], 'physical', true, 'customer cancellation', 7);
    $h->assertSame('cancelled', $cancelled['status'] ?? null, 'manual cancellation has a distinct terminal state');
    $h->assertTrue(($cancelled['cancelled_at'] ?? null) !== null, 'manual cancellation is timestamped');
    $h->assertSame(7, (int) ($db->one("SELECT created_by_iam_user_id FROM sale_stock_movements WHERE idempotency_key=?", ['release:reservation:' . (int) $cancellable['id']])['created_by_iam_user_id'] ?? 0), 'manual release records the operator in the immutable ledger');

    $list = $repository->listReservations(1, ['q' => 'BACKORDER'], 100, 0);
    $h->assertTrue(count($list['items']) >= 1, 'back-office search includes explicit backorders');
    $h->assertTrue((int) ($list['summary']['backorders'] ?? 0) >= 1, 'back-office summary exposes active backorders');
    $releasedBackorder = $repository->releaseReservationById(1, (int) $backorder['id'], 'backorder', false, 'supplier unavailable', 7);
    $h->assertSame('supplier unavailable', $releasedBackorder['release_reason'] ?? null, 'manual backorder release keeps its reason');
    $h->assertSame(7, (int) ($releasedBackorder['released_by_iam_user_id'] ?? 0), 'manual backorder release keeps its operator');

    $businessPath = $dir . '/business.sqlite';
    $businessDb = new App\Core\Database($businessPath);
    $businessDb->pdo()->exec('CREATE TABLE business_sellables(sellable_id INTEGER PRIMARY KEY,site_id INTEGER NOT NULL,product_id INTEGER); CREATE TABLE business_inventory_availability_projections(sellable_id INTEGER PRIMARY KEY,site_id INTEGER,tracked INTEGER,on_hand_quantity INTEGER,reserved_quantity INTEGER,available_quantity INTEGER,availability_status TEXT,source_version INTEGER,projected_at TEXT); CREATE TABLE business_storefront_projection_invalidations(id INTEGER PRIMARY KEY AUTOINCREMENT,site_id INTEGER,product_id INTEGER,reason TEXT); INSERT INTO business_sellables VALUES(9199,1,919);');
    $service = new SaleInventoryService($repository, null, null, null, new BusinessDatabaseConnection($businessPath));
    $projectionCartId = reservation_test_cart($db, (int) $webChannel['id'], 'web');
    $projected = $service->reserveForCart(1, $projectionCartId, ['business_variant_id' => 9199, 'sellable_id' => 9199, 'sku' => 'PROJECTED', 'track_stock' => true, 'metadata' => ['available_quantity' => 2]], 1, 1800, $webPolicy);
    $h->assertSame(1, (int) ($businessDb->one('SELECT available_quantity FROM business_inventory_availability_projections WHERE sellable_id=9199')['available_quantity'] ?? -1), 'reservation immediately refreshes the rebuildable Business projection');
    $service->releaseReservationById(1, (int) $projected['id'], 'physical', false, 'projection test', 7);
    $h->assertSame(2, (int) ($businessDb->one('SELECT available_quantity FROM business_inventory_availability_projections WHERE sellable_id=9199')['available_quantity'] ?? -1), 'release immediately restores the public projected availability');
} finally {
    $db = null;
    gc_collect_cycles();
    test_remove_tree($dir);
}

exit($h->finish('UNIT reservation policy lifecycle and concurrency'));

function reservation_test_cart(App\Core\Database $db, int $channelId, string $kind): int
{
    $db->run('INSERT INTO sale_carts(site_id,channel_id,status,currency,cart_kind,customer_snapshot_json,billing_address_json,shipping_address_json) VALUES(1,?,"active","CHF",?,"{}","{}","{}")', [$channelId, $kind]);
    return (int) $db->lastInsertId();
}

function reservation_test_order(App\Core\Database $db, int $channelId, int $cartId): int
{
    $db->run('INSERT INTO sale_orders(site_id,channel_id,order_number,source,status,payment_status,currency,customer_snapshot_json,billing_address_json,shipping_address_json,subtotal_minor,discount_total_minor,tax_total_minor,grand_total_minor,placed_at,metadata_json) VALUES(1,?,? ,"ecommerce","placed","unpaid","CHF","{}","{}","{}",0,0,0,0,CURRENT_TIMESTAMP,?)', [$channelId, 'RES-' . bin2hex(random_bytes(3)), json_encode(['source_cart_id' => $cartId])]);
    return (int) $db->lastInsertId();
}
