<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Core\Router;
use App\Application\Capability\CapabilityDefinition;
use App\Modules\Sale\SaleModuleProvider;

$h = new TestHarness();
$provider = new SaleModuleProvider();

$expectedTables = [
    'sale_channels',
    'sale_channel_catalog_scopes',
    'sale_settings',
    'sale_customer_refs',
    'sale_catalog_variant_refs',
    'sale_carts',
    'sale_cart_lines',
    'sale_cart_adjustments',
    'sale_orders',
    'sale_order_lines',
    'sale_order_adjustments',
    'sale_order_tax_lines',
    'sale_order_status_history',
    'sale_payment_methods',
    'sale_payment_intents',
    'sale_payment_transactions',
    'sale_payment_allocations',
    'sale_pos_registers',
    'sale_pos_devices',
    'sale_cash_sessions',
    'sale_cash_movements',
    'sale_stock_locations',
    'sale_inventory_items',
    'sale_stock_reservations',
    'sale_stock_movements',
    'sale_receipts',
    'sale_returns',
    'sale_return_lines',
    'sale_refunds',
    'sale_financial_corrections',
    'sale_order_customer_reconciliations',
    'sale_fulfillments',
    'sale_fulfillment_lines',
    'sale_state_transitions',
    'sale_promotions',
    'sale_coupons',
    'sale_idempotency_keys',
    'sale_events',
    'sale_outbox',
];

$expectedPermissions = [
    'sale.read',
    'sale.manage',
    'sale.orders.read',
    'sale.orders.manage',
    'sale.payments.read',
    'sale.payments.manage',
    'sale.refunds.manage',
    'sale.returns.manage',
    'sale.pos.use',
    'sale.pos.manage',
    'sale.cash.manage',
    'sale.stock.read',
    'sale.stock.manage',
    'sale.reports.read',
    'sale.settings.manage',
    'sale.customer_accounts.manage',
];

$h->assertSame('sale', $provider->key(), 'sale provider key is stable');
$h->assertSame('Vente', $provider->name(), 'sale provider name is stable');
$h->assertSame([], $provider->dependencies(), 'sale provider has no hard module dependency');
$h->assertSame('sale', $provider->databases()[0]['key'] ?? null, 'sale provider declares sale database');
$h->assertSame('database/modules/sale.sql', $provider->databases()[0]['schema'] ?? null, 'sale provider points to sale SQL schema');

$settings = $provider->settingsSchema();
$h->assertSame('optional_port', $settings['integrations']['sellable_catalog'] ?? null, 'sellable catalog stays an optional port');
$h->assertSame('optional_port', $settings['integrations']['customer_snapshot'] ?? null, 'customer snapshot stays an optional port');
$h->assertSame('planned_optional_port', $settings['integrations']['crm_activity_sink'] ?? null, 'CRM integration stays planned');
$h->assertSame('active_iam_crm_sale_port', $settings['integrations']['cms_account_bridge'] ?? null, 'CMS account integration is active');

$capabilities = [];
foreach ($provider->capabilities() as $capability) {
    $definition = CapabilityDefinition::fromArray($capability);
    $capabilities[$definition->key] = $definition;
    $h->assertSame('sale', $definition->module, 'sale capability is owned by sale: ' . $definition->key);
    $h->assertSame([], $definition->config['foreign_tables'] ?? null, 'sale capability has no direct foreign table access: ' . $definition->key);
}
foreach (['catalog.product.read', 'pricing.calculate', 'cart.validate', 'checkout.validate', 'payment.provider', 'order.after_place'] as $key) {
    $h->assertTrue(isset($capabilities[$key]), 'sale capability is declared: ' . $key);
}
$h->assertSame('validator', $capabilities['cart.validate']->type ?? null, 'sale cart validation is a validator capability');
$h->assertSame(false, $capabilities['cart.validate']->config['mutates_order'] ?? null, 'sale cart validator cannot mutate orders');
$h->assertSame('outbox', $capabilities['order.after_place']->config['transport'] ?? null, 'sale after-order capability uses outbox');

$permissionKeys = array_column($provider->permissions(), 'key');
foreach ($expectedPermissions as $permission) {
    $h->assertTrue(in_array($permission, $permissionKeys, true), 'sale permission is declared: ' . $permission);
}

$blueprints = [];
foreach ($provider->blueprints() as $blueprint) {
    $blueprints[(string) ($blueprint['blueprint_key'] ?? '')] = $blueprint;
}
foreach ([
    'sale_channel',
    'sale_cart',
    'sale_order',
    'sale_payment',
    'sale_pos_register',
    'sale_stock_location',
    'sale_stock_movement',
] as $key) {
    $h->assertTrue(isset($blueprints[$key]), 'sale blueprint is declared: ' . $key);
    $h->assertSame('sale', $blueprints[$key]['storage']['database'] ?? null, $key . ' uses sale database');
    $h->assertSame(false, $blueprints[$key]['headless']['public'] ?? null, $key . ' is not public headless');
    $h->assertSame(false, $blueprints[$key]['capabilities']['public'] ?? null, $key . ' disables public capability');
    foreach (($blueprints[$key]['permissions'] ?? []) as $action => $permission) {
        $h->assertTrue(in_array($permission, $permissionKeys, true), $key . ' blueprint permission exists for ' . $action);
    }
}
$paymentFields = array_column($blueprints['sale_payment']['fields'] ?? [], null, 'key');
$h->assertSame(true, $paymentFields['provider_payload_json']['sensitive'] ?? null, 'payment provider payload is sensitive');

$router = new Router();
$adminRoutes = $provider->adminRoutes();
$publicRoutes = $provider->publicHeadlessRoutes();
$contracts = $provider->apiContracts();
$adminRouteKeys = routeKeys($adminRoutes);
$publicRouteKeys = routeKeys($publicRoutes);
$contractKeys = [];
$routeContracts = [];

foreach ($contracts as $contract) {
    $key = (string) ($contract['key'] ?? '');
    $h->assertTrue($key !== '', 'sale API contract has a key');
    $h->assertTrue(!isset($contractKeys[$key]), 'sale API contract key is unique: ' . $key);
    $contractKeys[$key] = true;

    $scope = (string) ($contract['scope'] ?? 'admin');
    if (!isset($contract['method'], $contract['path'])) {
        continue;
    }

    $routeKey = routeKey((string) $contract['method'], (string) $contract['path']);
    $routeContracts[$routeKey] = $contract;
    if ($scope === 'headless') {
        $h->assertTrue(isset($publicRouteKeys[$routeKey]), 'headless sale contract has public route: ' . $routeKey);
        $h->assertTrue(trim((string) ($contract['permission'] ?? '')) !== '', 'headless sale contract declares its authentication mode: ' . $key);
        continue;
    }

    $permission = (string) ($contract['permission'] ?? '');
    $h->assertTrue(isset($adminRouteKeys[$routeKey]), 'admin sale contract has admin route: ' . $routeKey);
    $h->assertTrue(in_array($permission, $permissionKeys, true), 'admin sale contract permission exists: ' . $key);
}

foreach ($adminRouteKeys as $routeKey => $_) {
    $h->assertTrue(isset($routeContracts[$routeKey]), 'admin sale route has API contract: ' . $routeKey);
}
foreach ($publicRouteKeys as $routeKey => $_) {
    $h->assertTrue(isset($routeContracts[$routeKey]), 'public sale route has API contract: ' . $routeKey);
}

foreach ([
    ['GET', '/admin/api/sale/export/order-lines.csv', 'admin.sale.export.order_lines.v1', 'sale.reports.read'],
    ['GET', '/admin/api/sale/export/stock-movements.csv', 'admin.sale.export.stock_movements.v1', 'sale.stock.read'],
    ['POST', '/admin/api/sale/import/stock/apply', 'admin.sale.import.stock.apply.v1', 'sale.stock.manage'],
    ['POST', '/admin/api/sale/pos/checkout', 'admin.sale.pos.checkout.v1', 'sale.pos.use'],
    ['POST', '/admin/api/sale/orders/{id}/returns', 'admin.sale.orders.returns.store.v1', 'sale.returns.manage'],
    ['POST', '/admin/api/sale/returns/{id}/transition', 'admin.sale.returns.transition.v1', 'sale.returns.manage'],
    ['GET', '/admin/api/sale/orders/{id}/timeline', 'admin.sale.orders.timeline.v1', 'sale.orders.read'],
    ['POST', '/admin/api/sale/orders/{id}/customer-reconciliation', 'admin.sale.orders.customer_reconciliation.v1', 'sale.orders.manage'],
    ['POST', '/admin/api/sale/orders/{id}/payments/corrections', 'admin.sale.orders.payments.correction.v1', 'sale.payments.manage'],
    ['POST', '/admin/api/sale/payment-intents/{id}/void', 'admin.sale.payment_intents.void.v1', 'sale.payments.manage'],
] as [$method, $path, $key, $permission]) {
    $matched = $router->match($method, samplePath($path), $adminRoutes);
    $h->assertTrue($matched !== null, 'sale route matches router: ' . $method . ' ' . $path);
    $contract = $routeContracts[routeKey($method, $path)] ?? [];
    $h->assertSame($key, $contract['key'] ?? null, 'sale route contract key is stable: ' . $path);
    $h->assertSame($permission, $contract['permission'] ?? null, 'sale route contract permission is stable: ' . $path);
}

$h->assertSame(null, $router->match('GET', '/api/v1/sale/orders', $publicRoutes), 'sale has no public order listing route');
$h->assertSame(null, $router->match('GET', '/api/v1/sale/export/order-lines.csv', $publicRoutes), 'sale has no public export route');

[$saleDir, $salePath, $saleDb] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/sale.sql');

try {
    foreach ($expectedTables as $table) {
        $h->assertTrue($saleDb->tableExists($table), 'sale schema creates table: ' . $table);
    }

    $seededChannels = $saleDb->all('SELECT code, channel_type, status, is_public FROM sale_channels ORDER BY code');
    $h->assertSame([
        ['code' => 'admin-manual', 'channel_type' => 'admin', 'status' => 'active', 'is_public' => 0],
        ['code' => 'pos-main', 'channel_type' => 'pos', 'status' => 'draft', 'is_public' => 0],
        ['code' => 'web-main', 'channel_type' => 'ecommerce', 'status' => 'active', 'is_public' => 1],
    ], $seededChannels, 'sale schema seeds a public ecommerce channel for headless smoke coverage');

    $adminChannel = $saleDb->one("SELECT id FROM sale_channels WHERE code = 'admin-manual'");
    $h->expectException(
        fn() => $saleDb->run("INSERT INTO sale_channels(site_id, code, name, channel_type, status, is_public) VALUES(1, 'public-pos', 'Public POS', 'pos', 'active', 1)"),
        PDOException::class,
        'non ecommerce sale channel cannot be public'
    );
    $h->expectException(
        fn() => $saleDb->run('INSERT INTO sale_orders(site_id, channel_id, order_number, source, status, payment_status, currency, customer_snapshot_json, billing_address_json, shipping_address_json, grand_total_minor) VALUES(1, ?, ?, "admin", "placed", "unpaid", "CHF", "{}", "{}", "{}", -1)', [(int) $adminChannel['id'], 'NEG-' . bin2hex(random_bytes(3))]),
        PDOException::class,
        'sale order total cannot be negative'
    );

    $saleDb->run("INSERT INTO sale_stock_locations(site_id, code, name, location_type, status) VALUES(1, 'test-stock', 'Test stock', 'main', 'active')");
    $locationId = $saleDb->lastInsertId();
    $saleDb->run('INSERT INTO sale_inventory_items(site_id, business_variant_id, sellable_id, stock_location_id, sku, on_hand_quantity, reserved_quantity, available_quantity) VALUES(1, 990001, 990001, ?, "TEST-STOCK", 5, 0, 5)', [$locationId]);
    $inventoryItemId = $saleDb->lastInsertId();
    $h->expectException(
        fn() => $saleDb->run('INSERT INTO sale_stock_movements(inventory_item_id, movement_type, quantity) VALUES(?, "adjustment", 0)', [$inventoryItemId]),
        PDOException::class,
        'sale stock movement cannot be zero'
    );
    $h->expectException(
        fn() => $saleDb->run('INSERT INTO sale_inventory_items(site_id, business_variant_id, sellable_id, stock_location_id, sku, on_hand_quantity, reserved_quantity, available_quantity) VALUES(1, 990002, 990002, ?, "BAD-STOCK", 5, 2, 5)', [$locationId]),
        PDOException::class,
        'sale inventory availability must match on-hand minus reserved'
    );
    $returnColumns = array_column($saleDb->all('PRAGMA table_info(sale_returns)'), 'name');
    $receiptColumns = array_column($saleDb->all('PRAGMA table_info(sale_receipts)'), 'name');
    $transactionColumns = array_column($saleDb->all('PRAGMA table_info(sale_payment_transactions)'), 'name');
    $h->assertTrue(in_array('request_hash', $returnColumns, true), 'sale returns persist the idempotency request hash');
    $h->assertTrue(in_array('language', $receiptColumns, true), 'sale receipts persist their language');
    $h->assertTrue(in_array('correlation_id', $transactionColumns, true), 'sale financial transactions persist correlation ids');
} finally {
    $saleDb = null;
    gc_collect_cycles();
    test_remove_tree($saleDir);
}

exit($h->finish('UNIT sale module contracts'));

/** @param list<array{0:string,1:string,2:string}> $routes @return array<string,bool> */
function routeKeys(array $routes): array
{
    $keys = [];
    foreach ($routes as $route) {
        $key = routeKey($route[0], $route[1]);
        $keys[$key] = true;
    }
    return $keys;
}

function routeKey(string $method, string $path): string
{
    return strtoupper($method) . ' ' . $path;
}

function samplePath(string $path): string
{
    return str_replace(
        ['{id}', '{line_id}', '{transaction_id}', '{code}', '{token}', '{type}'],
        ['1', '2', '3', 'web-main', 'test-token', 'contact'],
        $path
    );
}
