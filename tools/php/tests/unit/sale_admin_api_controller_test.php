<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Application\Api\Admin\SaleAdminApiController;
use App\Core\ApiException;
use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Modules\Business\Catalog\CatalogPricingService;
use App\Modules\Business\Repositories\BusinessCatalogPricingRepository;
use App\Modules\Business\Repositories\PosCatalogRepository;
use App\Modules\Business\Services\BusinessCatalogSellableReadService;
use App\Modules\Sale\Pricing\SalePricingService;
use App\Modules\Sale\Repositories\SaleCartRepository;
use App\Modules\Sale\Repositories\SaleChannelRepository;
use App\Modules\Sale\Repositories\SaleEventRepository;
use App\Modules\Sale\Repositories\SaleIdempotencyRepository;
use App\Modules\Sale\Repositories\SaleInventoryRepository;
use App\Modules\Sale\Repositories\SaleOrderRepository;
use App\Modules\Sale\Repositories\SalePaymentRepository;
use App\Modules\Sale\SaleModuleProvider;
use App\Modules\Sale\Services\SaleCartService;
use App\Modules\Sale\Services\SaleCatalogSnapshotService;
use App\Modules\Sale\Services\SaleCheckoutService;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Modules\Sale\Services\SaleEventService;
use App\Modules\Sale\Services\SaleIdempotencyService;
use App\Modules\Sale\Services\SaleInventoryService;
use App\Modules\Sale\Services\SaleOrderService;
use App\Modules\Sale\Services\SalePaymentService;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;

$h = new TestHarness();
[$businessDir, $businessPath, $businessDb] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/business.sql');
[$saleDir, $salePath, $saleDb] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/sale.sql');
[$iamDir, $iamPath] = test_temp_db(__DIR__ . '/../../../../database/iam.sql');
$coreDir = sys_get_temp_dir() . '/amcms-sale-api-core-' . bin2hex(random_bytes(6));
mkdir($coreDir, 0775, true);

try {
    $core = new Database($coreDir . '/core.sqlite', 1000);
    $core->run("CREATE TABLE sites(id INTEGER PRIMARY KEY, site_key TEXT NOT NULL, name TEXT NOT NULL, default_language_code TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 1)");
    $core->run("CREATE TABLE site_domains(id INTEGER PRIMARY KEY, site_id INTEGER NOT NULL, host TEXT NOT NULL, base_path TEXT NOT NULL DEFAULT '', scheme TEXT NOT NULL DEFAULT 'https', is_primary INTEGER NOT NULL DEFAULT 1, is_active INTEGER NOT NULL DEFAULT 1, enforce_https INTEGER NOT NULL DEFAULT 0)");
    $core->run("CREATE TABLE languages(code TEXT PRIMARY KEY, name TEXT NOT NULL, is_default INTEGER NOT NULL DEFAULT 0, is_active INTEGER NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0)");
    $core->run("CREATE TABLE site_languages(site_id INTEGER NOT NULL, language_code TEXT NOT NULL, is_default INTEGER NOT NULL DEFAULT 0, is_active INTEGER NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0, fallback_language_code TEXT, url_prefix TEXT, hreflang_code TEXT, is_rtl INTEGER NOT NULL DEFAULT 0)");
    $core->run("INSERT INTO sites(id, site_key, name, default_language_code, is_active) VALUES(1, 'main', 'Main', 'fr', 1)");
    $core->run("INSERT INTO site_domains(id, site_id, host, base_path, scheme, is_primary, is_active) VALUES(1, 1, 'example.test', '', 'https', 1, 1)");
    $core->run("INSERT INTO languages(code, name, is_default, is_active, sort_order) VALUES('fr', 'Français', 1, 1, 1)");
    $core->run("INSERT INTO site_languages(site_id, language_code, is_default, is_active, sort_order) VALUES(1, 'fr', 1, 1, 1)");

    $iam = new Database($iamPath, 1000);
    $iam->run("INSERT INTO iam_users(id,email,email_normalized,password_hash,is_active,login_mode) VALUES(1,'sale-admin@example.test','sale-admin@example.test','x',1,'password'),(2,'sale-empty@example.test','sale-empty@example.test','x',1,'password')");
    $iam->run("INSERT INTO iam_roles(id,role_key,name) VALUES(1,'sale_admin','Sale admin'),(2,'sale_empty','Sale empty')");
    $permissions = [
        'sale.read', 'sale.manage', 'sale.orders.read', 'sale.orders.manage',
        'sale.payments.read', 'sale.payments.manage', 'sale.refunds.manage',
        'sale.pos.use', 'sale.pos.manage', 'sale.cash.manage',
        'sale.stock.read', 'sale.stock.manage', 'sale.reports.read', 'sale.settings.manage',
    ];
    foreach ($permissions as $index => $permission) {
        $iam->run('INSERT INTO iam_permissions(id, permission_key, name) VALUES(?, ?, ?)', [$index + 1, $permission, $permission]);
        $iam->run('INSERT INTO iam_role_permissions(role_id, permission_id) VALUES(1, ?)', [$index + 1]);
    }
    $iam->run("INSERT INTO iam_user_site_roles(user_id,site_id,role_id) VALUES(1,1,1),(2,1,2)");

    $sites = new SiteRepository($core, ['cms' => ['default_site_key' => 'main'], 'app' => ['default_locale' => 'fr']]);
    $pricingRepository = new BusinessCatalogPricingRepository($businessDb);
    $sellables = new BusinessCatalogSellableReadService($pricingRepository, new CatalogPricingService($pricingRepository), new PosCatalogRepository($businessDb));
    $saleConnection = new SaleDatabaseConnection($salePath);
    $channels = new SaleChannelRepository($saleConnection);
    $carts = new SaleCartRepository($saleConnection);
    $orders = new SaleOrderRepository($saleConnection);
    $payments = new SalePaymentRepository($saleConnection);
    $inventoryRepository = new SaleInventoryRepository($saleConnection);
    $inventory = new SaleInventoryService($inventoryRepository);
    $events = new SaleEventService(new SaleEventRepository($saleConnection));
    $idempotency = new SaleIdempotencyService(new SaleIdempotencyRepository($saleConnection));
    $catalogSnapshots = new SaleCatalogSnapshotService($saleConnection, $sellables);
    $cartService = new SaleCartService($carts, $channels, $catalogSnapshots, new SalePricingService(), $inventory, $events, $idempotency);
    $checkout = new SaleCheckoutService($saleConnection, $carts, $orders, $inventory, $events, $idempotency);
    $paymentService = new SalePaymentService($payments, $orders, $events);
    $orderService = new SaleOrderService($orders, $events);

    $controllerFor = static function (int $userId, string $method, string $path, array $query = [], array $payload = []) use ($iam, $sites, $saleConnection, $channels, $carts, $orders, $payments, $inventoryRepository, $catalogSnapshots, $cartService, $checkout, $paymentService, $orderService): SaleAdminApiController {
        if ($userId > 0) {
            $token = 'sale-api-test-token-' . $userId;
            $iam->run('DELETE FROM iam_sessions WHERE user_id = :user_id', ['user_id' => $userId]);
            $iam->run(
                'INSERT INTO iam_sessions(user_id, session_token_hash, ip_address, user_agent, last_seen_at, expires_at, created_at)
                 VALUES(:user_id, :hash, :ip, :ua, :last_seen, :expires, :created)',
                [
                    'user_id' => $userId,
                    'hash' => hash('sha256', $token),
                    'ip' => '127.0.0.1',
                    'ua' => 'sale-api-controller-test',
                    'last_seen' => gmdate('Y-m-d H:i:s'),
                    'expires' => gmdate('Y-m-d H:i:s', time() + 3600),
                    'created' => gmdate('Y-m-d H:i:s', time() - 60),
                ]
            );
            $_SESSION['admin_user'] = ['id' => $userId, 'email' => 'sale-' . $userId . '@example.test', 'session_secret' => $token];
        } else {
            $_SESSION = [];
        }
        $request = new Request($method, $path, $query, $payload === [] ? [] : ['data' => $payload], ['HTTP_HOST' => 'example.test'], [], []);
        $auth = new AuthRepository($iam);
        return new SaleAdminApiController($request, $sites, $auth, new Authorization($auth), $saleConnection, $channels, $carts, $orders, $payments, $inventoryRepository, $catalogSnapshots, $cartService, $checkout, $paymentService, $orderService);
    };

    $routes = (new SaleModuleProvider())->adminRoutes();
    foreach ([
        ['GET', '/admin/api/sale/channels'],
        ['GET', '/admin/api/sale/carts'],
        ['POST', '/admin/api/sale/carts/1/checkout'],
        ['GET', '/admin/api/sale/orders/1/events'],
        ['GET', '/admin/api/sale/payment-methods'],
        ['GET', '/admin/api/sale/pos/bootstrap'],
        ['GET', '/admin/api/sale/pos/catalog'],
        ['GET', '/admin/api/sale/pos/variants'],
        ['POST', '/admin/api/sale/pos/sessions/open'],
        ['POST', '/admin/api/sale/pos/sessions/1/close'],
        ['POST', '/admin/api/sale/pos/carts'],
        ['POST', '/admin/api/sale/pos/carts/1/lines'],
        ['PATCH', '/admin/api/sale/pos/carts/1/lines/1'],
        ['POST', '/admin/api/sale/pos/checkout'],
        ['GET', '/admin/api/sale/pos/orders/1/receipt'],
        ['GET', '/admin/api/sale/stock/items'],
        ['GET', '/admin/api/sale/reports/daily'],
    ] as [$method, $path]) {
        $match = (new Router())->match($method, $path, $routes);
        $h->assertTrue($match !== null, 'sale admin route is declared: ' . $method . ' ' . $path);
        $h->assertTrue(str_starts_with((string) ($match['handler'] ?? ''), 'App\\Application\\Api\\Admin\\SaleAdminApiController@'), 'sale admin route uses wired FQCN controller');
    }
    $h->assertSame(null, (new Router())->match('GET', '/api/v1/sale/orders', (new SaleModuleProvider())->publicHeadlessRoutes()), 'sale module has no public order listing route');
    $h->assertTrue((new Router())->match('POST', '/api/v1/sale/channels/web-main/cart', (new SaleModuleProvider())->publicHeadlessRoutes()) !== null, 'sale module declares optional public ecommerce cart route');

    $h->expectException(
        fn() => $controllerFor(0, 'GET', '/admin/api/sale/channels')->channels(),
        ApiException::class,
        'anonymous cannot access sale admin API'
    );
    $h->expectException(
        fn() => $controllerFor(2, 'GET', '/admin/api/sale/channels')->channels(),
        ApiException::class,
        'user without sale permission cannot access sale admin API'
    );

    $channelsResponse = $controllerFor(1, 'GET', '/admin/api/sale/channels')->channels();
    $h->assertSame(200, $channelsResponse->status(), 'sale admin can list channels');
    $channelsPayload = json_decode($channelsResponse->body(), true);
    $channelId = (int) ($channelsPayload['data']['channels'][0]['id'] ?? 0);
    $h->assertTrue($channelId > 0, 'sale channels response exposes seed channel');

    $dashboardResponse = $controllerFor(1, 'GET', '/admin/api/sale/dashboard')->dashboard();
    $h->assertSame(200, $dashboardResponse->status(), 'sale dashboard is available');
    $dashboardPayload = json_decode($dashboardResponse->body(), true);
    $h->assertTrue(array_key_exists('today_sales_minor', $dashboardPayload['data'] ?? []), 'sale dashboard exposes today sales');
    $h->assertTrue(array_key_exists('recent_orders', $dashboardPayload['data'] ?? []), 'sale dashboard exposes recent orders');
    $h->assertTrue(array_key_exists('recent_payments', $dashboardPayload['data'] ?? []), 'sale dashboard exposes recent payments');
    $h->assertTrue(array_key_exists('open_cash_sessions', $dashboardPayload['data'] ?? []), 'sale dashboard exposes open POS sessions');

    $createdChannel = $controllerFor(1, 'POST', '/admin/api/sale/channels', [], ['code' => 'test-pos', 'name' => 'Test POS', 'channel_type' => 'pos', 'status' => 'active'])->storeChannel();
    $h->assertSame(201, $createdChannel->status(), 'sale admin can create channel');
    $createdChannelPayload = json_decode($createdChannel->body(), true);
    $h->assertSame('test-pos', $createdChannelPayload['data']['channel']['code'] ?? null, 'sale channel create returns channel');

    $cartResponse = $controllerFor(1, 'POST', '/admin/api/sale/carts', [], ['channel_id' => $channelId])->storeCart();
    $h->assertSame(201, $cartResponse->status(), 'sale admin can create cart');
    $cartPayload = json_decode($cartResponse->body(), true);
    $cartId = (int) ($cartPayload['data']['cart']['id'] ?? 0);

    $variant = $businessDb->one("SELECT id FROM business_product_variants WHERE sku = 'DEMO-GOURDE-BLEU' LIMIT 1");
    $addLineResponse = $controllerFor(1, 'POST', '/admin/api/sale/carts/' . $cartId . '/lines', [], ['business_variant_id' => (int) $variant['id'], 'quantity' => 1, 'idempotency_key' => 'api-add-line'])->addCartLine($cartId);
    $h->assertSame(201, $addLineResponse->status(), 'sale admin can add cart line');
    $addLinePayload = json_decode($addLineResponse->body(), true);
    $h->assertSame(2900, (int) ($addLinePayload['data']['cart']['grand_total_minor'] ?? 0), 'cart line endpoint recalculates totals');

    $checkoutResponse = $controllerFor(1, 'POST', '/admin/api/sale/carts/' . $cartId . '/checkout', [], ['idempotency_key' => 'api-checkout'])->checkoutCart($cartId);
    $h->assertSame(201, $checkoutResponse->status(), 'sale admin can checkout cart');
    $checkoutPayload = json_decode($checkoutResponse->body(), true);
    $orderId = (int) ($checkoutPayload['data']['order']['id'] ?? 0);
    $h->assertTrue($orderId > 0, 'checkout endpoint returns order');

    $paymentResponse = $controllerFor(1, 'POST', '/admin/api/sale/orders/' . $orderId . '/payments', [], ['amount_minor' => 2900])->storeOrderPayment($orderId);
    $h->assertSame(201, $paymentResponse->status(), 'sale admin can record order payment');
    $paymentPayload = json_decode($paymentResponse->body(), true);
    $h->assertSame('paid', $paymentPayload['data']['order']['payment_status'] ?? null, 'payment endpoint updates order payment status');

    $eventsResponse = $controllerFor(1, 'GET', '/admin/api/sale/orders/' . $orderId . '/events')->orderEvents($orderId);
    $h->assertSame(200, $eventsResponse->status(), 'sale admin can list order events');
    $eventsPayload = json_decode($eventsResponse->body(), true);
    $h->assertTrue(in_array('sale.order.placed', array_column($eventsPayload['data']['events'] ?? [], 'event_type'), true), 'order events include placed event');

    $stockResponse = $controllerFor(1, 'GET', '/admin/api/sale/stock/items')->stockItems();
    $h->assertSame(200, $stockResponse->status(), 'sale admin can list stock items');

    $posBootstrapResponse = $controllerFor(1, 'GET', '/admin/api/sale/pos/bootstrap')->posBootstrap();
    $h->assertSame(200, $posBootstrapResponse->status(), 'sale POS bootstrap is available');
    $posBootstrapPayload = json_decode($posBootstrapResponse->body(), true);
    $h->assertTrue(isset($posBootstrapPayload['data']['payment_methods']), 'sale POS bootstrap exposes payment methods');

    $posCatalogResponse = $controllerFor(1, 'GET', '/admin/api/sale/pos/catalog', ['q' => 'gourde'])->posCatalog();
    $h->assertSame(200, $posCatalogResponse->status(), 'sale POS catalog is searchable');
    $posCatalogPayload = json_decode($posCatalogResponse->body(), true);
    $h->assertTrue(count($posCatalogPayload['data']['variants'] ?? []) > 0, 'sale POS catalog returns sellable variants');

    $posVariantsResponse = $controllerFor(1, 'GET', '/admin/api/sale/pos/variants', ['sku' => 'DEMO-GOURDE-BLEU'])->posVariants();
    $h->assertSame(200, $posVariantsResponse->status(), 'sale POS variants endpoint supports SKU lookup');
    $posVariantsPayload = json_decode($posVariantsResponse->body(), true);
    $h->assertSame('DEMO-GOURDE-BLEU', $posVariantsPayload['data']['variants'][0]['sku'] ?? null, 'sale POS SKU lookup returns expected variant');

    $sessionResponse = $controllerFor(1, 'POST', '/admin/api/sale/pos/sessions/open', [], ['opening_cash_minor' => 1000])->openCashSession();
    $h->assertSame(201, $sessionResponse->status(), 'sale POS can open a cash session');
    $sessionPayload = json_decode($sessionResponse->body(), true);
    $cashSessionId = (int) ($sessionPayload['data']['session']['id'] ?? 0);
    $h->assertTrue($cashSessionId > 0, 'sale POS open session returns session id');

    $posCartResponse = $controllerFor(1, 'POST', '/admin/api/sale/pos/carts')->posStoreCart();
    $h->assertSame(201, $posCartResponse->status(), 'sale POS can create a cart');
    $posCartPayload = json_decode($posCartResponse->body(), true);
    $posCartId = (int) ($posCartPayload['data']['cart']['id'] ?? 0);

    $posLineResponse = $controllerFor(1, 'POST', '/admin/api/sale/pos/carts/' . $posCartId . '/lines', [], ['business_variant_id' => (int) $variant['id'], 'quantity' => 1, 'idempotency_key' => 'pos-api-add-line'])->posAddCartLine($posCartId);
    $h->assertSame(201, $posLineResponse->status(), 'sale POS can add a cart line');
    $posLinePayload = json_decode($posLineResponse->body(), true);
    $posLineId = (int) ($posLinePayload['data']['line']['id'] ?? 0);
    $h->assertSame(2900, (int) ($posLinePayload['data']['cart']['grand_total_minor'] ?? 0), 'sale POS cart uses server-side catalog price');

    $posUpdateLineResponse = $controllerFor(1, 'PATCH', '/admin/api/sale/pos/carts/' . $posCartId . '/lines/' . $posLineId, [], ['quantity' => 2])->posUpdateCartLine($posCartId, $posLineId);
    $h->assertSame(200, $posUpdateLineResponse->status(), 'sale POS can update cart line quantity');
    $posUpdateLinePayload = json_decode($posUpdateLineResponse->body(), true);
    $h->assertSame(5800, (int) ($posUpdateLinePayload['data']['cart']['grand_total_minor'] ?? 0), 'sale POS quantity update recalculates totals');

    $posCheckoutPayload = ['cart_id' => $posCartId, 'cash_session_id' => $cashSessionId, 'payment_method' => 'cash', 'idempotency_key' => 'pos-api-checkout'];
    $posCheckoutResponse = $controllerFor(1, 'POST', '/admin/api/sale/pos/checkout', [], $posCheckoutPayload)->posCheckout();
    $h->assertSame(201, $posCheckoutResponse->status(), 'sale POS can checkout a cash cart');
    $posCheckoutBody = json_decode($posCheckoutResponse->body(), true);
    $posOrderId = (int) ($posCheckoutBody['data']['order']['id'] ?? 0);
    $h->assertSame('paid', $posCheckoutBody['data']['order']['payment_status'] ?? null, 'sale POS checkout records payment');
    $h->assertTrue(isset($posCheckoutBody['data']['receipt']['printable_text']), 'sale POS checkout returns printable receipt payload');

    $posCheckoutReplayResponse = $controllerFor(1, 'POST', '/admin/api/sale/pos/checkout', [], $posCheckoutPayload)->posCheckout();
    $h->assertSame(201, $posCheckoutReplayResponse->status(), 'sale POS checkout is idempotent');
    $paymentCount = (int) ($saleDb->one('SELECT COUNT(*) AS count FROM sale_payment_transactions WHERE order_id = ?', [$posOrderId])['count'] ?? 0);
    $h->assertSame(1, $paymentCount, 'sale POS idempotent replay does not duplicate payment');

    $receiptResponse = $controllerFor(1, 'GET', '/admin/api/sale/pos/orders/' . $posOrderId . '/receipt')->posOrderReceipt($posOrderId);
    $h->assertSame(200, $receiptResponse->status(), 'sale POS can reload an order receipt');

    $sessionAfterSale = $saleDb->one('SELECT * FROM sale_cash_sessions WHERE id = ?', [$cashSessionId]);
    $expectedCash = (int) ($sessionAfterSale['expected_cash_minor'] ?? 0);
    $h->assertSame(6800, $expectedCash, 'sale POS cash session expected amount includes cash sale');

    $closeSessionResponse = $controllerFor(1, 'POST', '/admin/api/sale/pos/sessions/' . $cashSessionId . '/close', [], ['counted_cash_minor' => $expectedCash])->closeCashSession($cashSessionId);
    $h->assertSame(200, $closeSessionResponse->status(), 'sale POS can close cash session');
    $closeSessionPayload = json_decode($closeSessionResponse->body(), true);
    $h->assertSame('closed', $closeSessionPayload['data']['session']['status'] ?? null, 'sale POS closed session is marked closed');
    $h->assertSame(0, (int) ($closeSessionPayload['data']['session']['difference_minor'] ?? -1), 'sale POS closed session computes cash difference');

    $reportResponse = $controllerFor(1, 'GET', '/admin/api/sale/reports/daily')->dailyReport();
    $h->assertSame(200, $reportResponse->status(), 'sale admin can read daily report');
} finally {
    $businessDb = null;
    $saleDb = null;
    gc_collect_cycles();
    test_remove_tree($businessDir);
    test_remove_tree($saleDir);
    test_remove_tree($iamDir);
    test_remove_tree($coreDir);
}

exit($h->finish('UNIT sale admin API controller'));
