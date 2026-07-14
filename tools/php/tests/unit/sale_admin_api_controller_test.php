<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Application\Api\Admin\SaleAdminApiController;
use App\Core\ApiException;
use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Mail\MailerInterface;
use App\Modules\Business\Catalog\CatalogPricingService;
use App\Modules\Business\Repositories\BusinessCatalogPricingRepository;
use App\Modules\Business\Repositories\PosCatalogRepository;
use App\Modules\Business\Services\BusinessCatalogSellableReadService;
use App\Modules\Business\Services\BusinessDatabaseConnection;
use App\Modules\Sale\Adapters\BusinessSellableCatalogAdapter;
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
use App\Modules\Sale\Services\SaleCatalogExportService;
use App\Modules\Sale\Services\SaleCatalogSnapshotService;
use App\Modules\Sale\Services\SaleCheckoutService;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Modules\Sale\Services\SaleEventService;
use App\Modules\Sale\Services\SaleIdempotencyService;
use App\Modules\Sale\Services\SaleImportExportReportService;
use App\Modules\Sale\Services\SaleInventoryService;
use App\Modules\Sale\Services\SaleInventoryReconciliationService;
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
        'sale.pos.sessions.open', 'sale.pos.sessions.close', 'sale.pos.discounts.manage',
        'sale.pos.refunds.manage', 'sale.pos.cash.correct', 'sale.pos.receipts.reprint',
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
    $inventoryReconciliation = new SaleInventoryReconciliationService($saleConnection, new BusinessDatabaseConnection($businessPath));
    $events = new SaleEventService(new SaleEventRepository($saleConnection));
    $idempotency = new SaleIdempotencyService(new SaleIdempotencyRepository($saleConnection));
    $catalogSnapshots = new SaleCatalogSnapshotService($saleConnection, new BusinessSellableCatalogAdapter($sellables));
    $salePricing = new SalePricingService();
    $cartService = new SaleCartService($carts, $channels, $catalogSnapshots, $salePricing, $inventory, $events, $idempotency);
    $catalogExport = new SaleCatalogExportService($channels, $catalogSnapshots, $salePricing);
    $importExportReports = new SaleImportExportReportService($saleConnection, $inventory);
    $checkout = new SaleCheckoutService($saleConnection, $carts, $orders, $inventory, $events, $idempotency);
    $paymentService = new SalePaymentService($payments, $orders, $events, $idempotency);
    $orderService = new SaleOrderService($orders, $events);
    $mailer = new class implements MailerInterface {
        /** @var list<array{to:string,subject:string,text:string,html:?string}> */
        public array $messages = [];

        public function send(string $to, string $subject, string $textBody, ?string $htmlBody = null): bool
        {
            $this->messages[] = ['to' => $to, 'subject' => $subject, 'text' => $textBody, 'html' => $htmlBody];
            return true;
        }
    };

    $controllerFor = static function (int $userId, string $method, string $path, array $query = [], array $payload = []) use ($iam, $sites, $saleConnection, $channels, $carts, $orders, $payments, $inventory, $inventoryReconciliation, $catalogSnapshots, $catalogExport, $cartService, $checkout, $paymentService, $orderService, $events, $importExportReports, $idempotency, $mailer): SaleAdminApiController {
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
        return new SaleAdminApiController($request, $sites, $auth, new Authorization($auth), $saleConnection, $channels, $carts, $orders, $payments, $inventory, $catalogSnapshots, $catalogExport, $cartService, $checkout, $paymentService, $orderService, $events, $importExportReports, $idempotency, $mailer, null, null, null, null, null, null, null, $inventoryReconciliation);
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
        ['GET', '/admin/api/sale/export/orders.csv'],
        ['GET', '/admin/api/sale/export/order-lines.csv'],
        ['GET', '/admin/api/sale/export/payments.csv'],
        ['GET', '/admin/api/sale/export/pos-sessions.csv'],
        ['GET', '/admin/api/sale/export/stock-movements.csv'],
        ['GET', '/admin/api/sale/export/returns-refunds.csv'],
        ['GET', '/admin/api/sale/stock/reservations'],
        ['POST', '/admin/api/sale/stock/reservations/1/renew'],
        ['POST', '/admin/api/sale/stock/reservations/1/release'],
        ['PUT', '/admin/api/sale/stock/reservation-policies/1'],
        ['POST', '/admin/api/sale/import/stock/preview'],
        ['POST', '/admin/api/sale/import/stock/apply'],
        ['GET', '/admin/api/sale/pos/variants'],
        ['PATCH', '/admin/api/sale/pos/registers/1'],
        ['POST', '/admin/api/sale/pos/sessions/open'],
        ['POST', '/admin/api/sale/pos/sessions/1/close'],
        ['POST', '/admin/api/sale/pos/sessions/1/movements'],
        ['POST', '/admin/api/sale/pos/carts'],
        ['POST', '/admin/api/sale/pos/carts/1/lines'],
        ['PATCH', '/admin/api/sale/pos/carts/1/lines/1'],
        ['DELETE', '/admin/api/sale/pos/carts/1/lines/1'],
        ['POST', '/admin/api/sale/pos/carts/1/adjustments'],
        ['POST', '/admin/api/sale/pos/checkout'],
        ['GET', '/admin/api/sale/pos/orders/1/receipt'],
        ['POST', '/admin/api/sale/pos/orders/1/receipt/reprint'],
        ['POST', '/admin/api/sale/pos/orders/1/receipt/email'],
        ['POST', '/admin/api/sale/pos/orders/1/returns'],
        ['GET', '/admin/api/sale/ai/schema'],
        ['GET', '/admin/api/sale/ai/orders/1/summary-context'],
        ['GET', '/admin/api/sale/ai/pos/day-summary-context'],
        ['GET', '/admin/api/sale/ai/customers/contact/1/analysis-context'],
        ['GET', '/admin/api/sale/ai/unpaid-orders-context'],
        ['GET', '/admin/api/sale/stock/items'],
        ['POST', '/admin/api/sale/stock/transfers'],
        ['POST', '/admin/api/sale/stock/reconciliation'],
        ['GET', '/admin/api/sale/reports/daily'],
        ['GET', '/admin/api/sale/reports/channels'],
        ['GET', '/admin/api/sale/reports/payment-methods'],
        ['GET', '/admin/api/sale/reports/stock'],
        ['GET', '/admin/api/sale/reports/refunds'],
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

    $paymentPayloadInput = ['amount_minor' => 2900, 'payment_method' => 'manual_card', 'idempotency_key' => 'api-payment'];
    $paymentResponse = $controllerFor(1, 'POST', '/admin/api/sale/orders/' . $orderId . '/payments', [], $paymentPayloadInput)->storeOrderPayment($orderId);
    $h->assertSame(201, $paymentResponse->status(), 'sale admin can record order payment');
    $paymentPayload = json_decode($paymentResponse->body(), true);
    $h->assertSame('paid', $paymentPayload['data']['order']['payment_status'] ?? null, 'payment endpoint updates order payment status');
    $paymentTransactionId = (int) ($paymentPayload['data']['transaction']['id'] ?? 0);
    $paymentReplayResponse = $controllerFor(1, 'POST', '/admin/api/sale/orders/' . $orderId . '/payments', [], $paymentPayloadInput)->storeOrderPayment($orderId);
    $h->assertSame(201, $paymentReplayResponse->status(), 'sale admin payment endpoint is idempotent');
    $h->assertSame(1, (int) ($saleDb->one('SELECT COUNT(*) AS count FROM sale_payment_transactions WHERE order_id = ? AND transaction_type = "payment"', [$orderId])['count'] ?? 0), 'idempotent payment replay does not duplicate transaction');
    $intent = $saleDb->one('SELECT provider_key, status FROM sale_payment_intents WHERE order_id = ? LIMIT 1', [$orderId]);
    $h->assertSame('manual_card', $intent['provider_key'] ?? null, 'payment intent stores provider key');
    $h->assertSame('captured', $intent['status'] ?? null, 'payment intent is captured after provider payment');

    $refundInput = ['amount_minor' => 900, 'reason' => 'Retour partiel', 'idempotency_key' => 'api-refund'];
    $refundResponse = $controllerFor(1, 'POST', '/admin/api/sale/payments/' . $paymentTransactionId . '/refund', [], $refundInput)->refundPayment($paymentTransactionId);
    $h->assertSame(201, $refundResponse->status(), 'sale admin can refund a payment through provider service');
    $refundPayload = json_decode($refundResponse->body(), true);
    $h->assertSame('partially_refunded', $refundPayload['data']['order']['payment_status'] ?? null, 'partial refund updates order payment status');
    $refundReplayResponse = $controllerFor(1, 'POST', '/admin/api/sale/payments/' . $paymentTransactionId . '/refund', [], $refundInput)->refundPayment($paymentTransactionId);
    $h->assertSame(201, $refundReplayResponse->status(), 'sale refund endpoint is idempotent');
    $h->assertSame(1, (int) ($saleDb->one('SELECT COUNT(*) AS count FROM sale_refunds WHERE payment_transaction_id = ?', [$paymentTransactionId])['count'] ?? 0), 'idempotent refund replay does not duplicate refund');

    $eventsResponse = $controllerFor(1, 'GET', '/admin/api/sale/orders/' . $orderId . '/events')->orderEvents($orderId);
    $h->assertSame(200, $eventsResponse->status(), 'sale admin can list order events');
    $eventsPayload = json_decode($eventsResponse->body(), true);
    $h->assertTrue(in_array('sale.order.placed', array_column($eventsPayload['data']['events'] ?? [], 'event_type'), true), 'order events include placed event');

    $aiSchemaResponse = $controllerFor(1, 'GET', '/admin/api/sale/ai/schema')->aiSchema();
    $h->assertSame(200, $aiSchemaResponse->status(), 'sale AI private schema is available');
    $aiSchemaPayload = json_decode($aiSchemaResponse->body(), true);
    $h->assertSame(false, $aiSchemaPayload['data']['external_ai_allowed'] ?? true, 'sale AI schema forbids external AI by default');
    $h->assertTrue(isset($aiSchemaPayload['data']['contexts']['sale.ai.order_summary']), 'sale AI schema declares order summary context');

    $aiOrderContextResponse = $controllerFor(1, 'GET', '/admin/api/sale/ai/orders/' . $orderId . '/summary-context')->aiOrderSummaryContext($orderId);
    $h->assertSame(200, $aiOrderContextResponse->status(), 'sale AI order summary context is available');
    $aiOrderContextPayload = json_decode($aiOrderContextResponse->body(), true);
    $h->assertSame(false, $aiOrderContextPayload['data']['external_ai_allowed'] ?? true, 'sale AI order context forbids external AI by default');
    $h->assertSame($orderId, (int) ($aiOrderContextPayload['data']['order']['id'] ?? 0), 'sale AI order context exposes requested order');

    $stockResponse = $controllerFor(1, 'GET', '/admin/api/sale/stock/items')->stockItems();
    $h->assertSame(200, $stockResponse->status(), 'sale admin can list stock items');
    $stockPayload = json_decode($stockResponse->body(), true);
    $h->assertTrue(isset($stockPayload['data']['summary']['on_hand_quantity']), 'stock workspace distinguishes physical quantity');
    $h->assertTrue(isset($stockPayload['data']['summary']['reserved_quantity']), 'stock workspace distinguishes reserved quantity');
    $h->assertTrue(isset($stockPayload['data']['summary']['available_quantity']), 'stock workspace distinguishes available quantity');
    $h->assertTrue(count($stockPayload['data']['locations'] ?? []) > 0, 'stock workspace exposes active location filters');
    $stockItem = ($stockPayload['data']['items'] ?? [])[0] ?? [];
    $invalidAdjustment = $controllerFor(1, 'POST', '/admin/api/sale/stock/adjustments', [], [
        'sellable_id' => (int) ($stockItem['sellable_id'] ?? $variant['id']),
        'location_id' => (int) ($stockItem['stock_location_id'] ?? 0),
        'movement_type' => 'receipt',
        'quantity_delta' => 1,
    ])->stockAdjustments();
    $h->assertSame(422, $invalidAdjustment->status(), 'manual stock movement requires an audit reason');
    $adjustmentKey = 'manual-stock:test-admin-ledger';
    $validAdjustment = $controllerFor(1, 'POST', '/admin/api/sale/stock/adjustments', [], [
        'sellable_id' => (int) ($stockItem['sellable_id'] ?? $variant['id']),
        'location_id' => (int) ($stockItem['stock_location_id'] ?? 0),
        'movement_type' => 'receipt',
        'quantity_delta' => 1,
        'reason' => 'Réception contrôlée par test',
        'idempotency_key' => $adjustmentKey,
    ])->stockAdjustments();
    $h->assertSame(201, $validAdjustment->status(), 'guided stock movement creates an audited ledger row');
    $adjustmentMovement = $saleDb->one('SELECT * FROM sale_stock_movements WHERE idempotency_key=?', [$adjustmentKey]);
    $h->assertSame($adjustmentKey, $adjustmentMovement['correlation_id'] ?? null, 'manual movement keeps its correlation id');
    $h->assertTrue((int) ($adjustmentMovement['stock_location_id'] ?? 0) > 0, 'manual movement keeps its location');
    $reservationChannel = $saleDb->one("SELECT id FROM sale_channels WHERE code='web-main'");
    $saleDb->run('INSERT INTO sale_carts(site_id,channel_id,status,currency,cart_kind,customer_snapshot_json,billing_address_json,shipping_address_json) VALUES(1,?,"active","CHF","web","{}","{}","{}")', [(int) $reservationChannel['id']]);
    $reservationCartId = (int) $saleDb->lastInsertId();
    $reservation = $inventory->reserveForCart(1, $reservationCartId, [
        'business_variant_id' => (int) ($stockItem['business_variant_id'] ?? $variant['id']),
        'sellable_id' => (int) ($stockItem['sellable_id'] ?? $variant['id']),
        'sku' => $stockItem['sku'] ?? 'RESERVATION-API', 'track_stock' => true,
        'metadata' => ['available_quantity' => max(1, (int) ($stockItem['available_quantity'] ?? 1))],
    ], 1);
    $reservationsResponse = $controllerFor(1, 'GET', '/admin/api/sale/stock/reservations')->stockReservations();
    $h->assertSame(200, $reservationsResponse->status(), 'sale admin can list reservations and policies');
    $reservationsPayload = json_decode($reservationsResponse->body(), true);
    $h->assertTrue(count($reservationsPayload['data']['reservations'] ?? []) > 0, 'reservation workspace returns active holds');
    $h->assertTrue(count($reservationsPayload['data']['policies'] ?? []) >= 3, 'reservation workspace exposes channel policies');
    $renewResponse = $controllerFor(1, 'POST', '/admin/api/sale/stock/reservations/' . (int) $reservation['id'] . '/renew', [], ['reservation_kind' => 'physical'])->renewStockReservation((int) $reservation['id']);
    $h->assertSame(200, $renewResponse->status(), 'authorized operator can request a controlled renewal');
    $missingReasonRelease = $controllerFor(1, 'POST', '/admin/api/sale/stock/reservations/' . (int) $reservation['id'] . '/release', [], ['reservation_kind' => 'physical'])->releaseStockReservation((int) $reservation['id']);
    $h->assertSame(422, $missingReasonRelease->status(), 'manual reservation release requires an audit reason');
    $releaseResponse = $controllerFor(1, 'POST', '/admin/api/sale/stock/reservations/' . (int) $reservation['id'] . '/release', [], ['reservation_kind' => 'physical', 'reason' => 'Libération contrôlée API'])->releaseStockReservation((int) $reservation['id']);
    $h->assertSame(200, $releaseResponse->status(), 'authorized operator can release an active reservation');
    $h->assertSame(1, (int) ($saleDb->one('SELECT created_by_iam_user_id FROM sale_stock_movements WHERE idempotency_key=?', ['release:reservation:' . (int) $reservation['id']])['created_by_iam_user_id'] ?? 0), 'manual release records authenticated operator');
    $policyResponse = $controllerFor(1, 'PUT', '/admin/api/sale/stock/reservation-policies/' . (int) $reservationChannel['id'], [], ['reservation_policy' => 'checkout_start', 'reservation_ttl_seconds' => 900, 'reservation_renewal_window_seconds' => 120, 'reservation_max_lifetime_seconds' => 3600, 'backorder_policy' => 'sellable'])->updateStockReservationPolicy((int) $reservationChannel['id']);
    $h->assertSame(200, $policyResponse->status(), 'sale settings manager can configure channel reservation policy');
    $h->assertSame(900, (int) (json_decode($policyResponse->body(), true)['data']['policy']['reservation_ttl_seconds'] ?? 0), 'configured TTL is persisted');
    $reconciliationResponse = $controllerFor(1, 'POST', '/admin/api/sale/stock/reconciliation', [], ['repair_derived' => true])->reconcileInventory();
    $h->assertSame(201, $reconciliationResponse->status(), 'sale admin can run inventory reconciliation');
    $reconciliationPayload = json_decode($reconciliationResponse->body(), true);
    $h->assertSame('sale.sqlite', $reconciliationPayload['data']['reconciliation']['source_of_truth'] ?? null, 'inventory reconciliation identifies the transactional source');

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
    $h->assertTrue((int) ($sessionPayload['data']['session']['channel_id'] ?? 0) > 0, 'sale POS session freezes its channel');
    $h->assertTrue((int) ($sessionPayload['data']['session']['stock_location_id'] ?? 0) > 0, 'sale POS session freezes its stock location');
    $h->assertSame(1, (int) ($sessionPayload['data']['session']['opened_by_iam_user_id'] ?? 0), 'sale POS session records its operator');
    $h->assertSame(1, (int) ($saleDb->one('SELECT COUNT(*) AS count FROM sale_outbox WHERE topic = "sale.pos.session.opened"')['count'] ?? 0), 'sale POS session opening is queued in outbox');

    $sessionRegisterId = (int) ($sessionPayload['data']['session']['register_id'] ?? 0);
    $cashMethodId = (int) ($saleDb->one("SELECT id FROM sale_payment_methods WHERE channel_id=? AND method_type='cash'", [(int) $sessionPayload['data']['session']['channel_id']])['id'] ?? 0);
    $registerConfigResponse = $controllerFor(1, 'PATCH', '/admin/api/sale/pos/registers/' . $sessionRegisterId, [], ['currency' => 'CHF', 'locale' => 'en', 'payment_method_ids' => [$cashMethodId]])->configurePosRegister($sessionRegisterId);
    $h->assertSame(200, $registerConfigResponse->status(), 'sale POS register configures currency, locale and allowed methods');
    $registerConfigPayload = json_decode($registerConfigResponse->body(), true);
    $h->assertSame('cash', $registerConfigPayload['data']['register']['payment_methods'][0]['method_type'] ?? null, 'sale POS register exposes only its allowed payment method');

    $cashInResponse = $controllerFor(1, 'POST', '/admin/api/sale/pos/sessions/' . $cashSessionId . '/movements', [], ['movement_type' => 'cash_in', 'amount_minor' => 200, 'reason' => 'appoint'])->storeCashMovement($cashSessionId);
    $h->assertSame(201, $cashInResponse->status(), 'sale POS records an audited cash-in');
    $cashOutResponse = $controllerFor(1, 'POST', '/admin/api/sale/pos/sessions/' . $cashSessionId . '/movements', [], ['movement_type' => 'cash_out', 'amount_minor' => 200, 'reason' => 'retrait appoint'])->storeCashMovement($cashSessionId);
    $h->assertSame(201, $cashOutResponse->status(), 'sale POS records an audited cash-out');
    $h->assertSame(1000, (int) ($saleDb->one('SELECT expected_cash_minor FROM sale_cash_sessions WHERE id=?', [$cashSessionId])['expected_cash_minor'] ?? 0), 'cash-in and cash-out reconcile expected cash');
    $h->expectException(
        fn() => $saleDb->run('UPDATE sale_cash_movements SET reason=? WHERE cash_session_id=?', ['tamper', $cashSessionId]),
        \PDOException::class,
        'sale POS cash movements are immutable'
    );

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
    $h->assertTrue(str_starts_with((string) ($posCheckoutBody['data']['order']['order_number'] ?? ''), 'POS-'), 'sale POS checkout uses POS order number prefix');
    $h->assertSame('paid', $posCheckoutBody['data']['order']['payment_status'] ?? null, 'sale POS checkout records payment');
    $posOrderContext = $saleDb->one('SELECT channel_id,stock_location_id,pos_register_id,pos_session_id,pos_operator_iam_user_id FROM sale_orders WHERE id=?', [$posOrderId]);
    $h->assertSame($cashSessionId, (int) ($posOrderContext['pos_session_id'] ?? 0), 'shared order traces the POS session');
    $h->assertSame(1, (int) ($posOrderContext['pos_operator_iam_user_id'] ?? 0), 'shared order traces the POS operator');
    $h->assertTrue((int) ($posOrderContext['pos_register_id'] ?? 0) > 0 && (int) ($posOrderContext['stock_location_id'] ?? 0) > 0, 'shared order traces register and location');
    $posStockLocation = $saleDb->one('SELECT i.stock_location_id,r.stock_location_id AS register_location_id FROM sale_stock_reservations sr INNER JOIN sale_inventory_items i ON i.id=sr.inventory_item_id INNER JOIN sale_carts c ON c.id=sr.cart_id INNER JOIN sale_cash_sessions s ON s.id=c.register_session_id INNER JOIN sale_pos_registers r ON r.id=s.register_id WHERE sr.cart_id=?', [$posCartId]);
    $h->assertSame((int) ($posStockLocation['register_location_id'] ?? 0), (int) ($posStockLocation['stock_location_id'] ?? -1), 'POS checkout consumes stock from its register location');
    $h->assertTrue(isset($posCheckoutBody['data']['receipt']['printable_text']), 'sale POS checkout returns printable receipt payload');

    $posCheckoutReplayResponse = $controllerFor(1, 'POST', '/admin/api/sale/pos/checkout', [], $posCheckoutPayload)->posCheckout();
    $h->assertSame(201, $posCheckoutReplayResponse->status(), 'sale POS checkout is idempotent');
    $paymentCount = (int) ($saleDb->one('SELECT COUNT(*) AS count FROM sale_payment_transactions WHERE order_id = ?', [$posOrderId])['count'] ?? 0);
    $h->assertSame(1, $paymentCount, 'sale POS idempotent replay does not duplicate payment');

    $receiptResponse = $controllerFor(1, 'GET', '/admin/api/sale/pos/orders/' . $posOrderId . '/receipt')->posOrderReceipt($posOrderId);
    $h->assertSame(200, $receiptResponse->status(), 'sale POS can reload an order receipt');
    $orderReceiptResponse = $controllerFor(1, 'GET', '/admin/api/sale/orders/' . $posOrderId . '/receipt')->orderReceipt($posOrderId);
    $orderReceiptPayload = json_decode($orderReceiptResponse->body(), true);
    $h->assertSame(200, $orderReceiptResponse->status(), 'sale orders can print the same receipt payload');
    $h->assertTrue(str_contains((string) ($orderReceiptPayload['data']['receipt']['printable_text'] ?? ''), 'Ticket de caisse'), 'sale order receipt uses ticket formatter');

    $reprintResponse = $controllerFor(1, 'POST', '/admin/api/sale/pos/orders/' . $posOrderId . '/receipt/reprint', [], ['reason' => 'copie client'])->reprintPosOrderReceipt($posOrderId);
    $h->assertSame(200, $reprintResponse->status(), 'sale POS can reprint a receipt with a reason');
    $h->assertSame(1, (int) ($saleDb->one("SELECT COUNT(*) AS count FROM sale_receipt_actions WHERE action_type='reprint' AND operator_iam_user_id=1")['count'] ?? 0), 'sale POS receipt reprint is audited');

    $posOrderLine = $saleDb->one('SELECT id FROM sale_order_lines WHERE order_id=? ORDER BY id LIMIT 1', [$posOrderId]);
    $posReturnResponse = $controllerFor(1, 'POST', '/admin/api/sale/pos/orders/' . $posOrderId . '/returns', [], [
        'lines' => [['order_line_id' => (int) ($posOrderLine['id'] ?? 0), 'quantity' => 1]],
        'reason' => 'retour comptoir',
        'idempotency_key' => 'pos-return-1',
    ])->storePosReturn($posOrderId);
    $h->assertSame(201, $posReturnResponse->status(), 'sale POS return stays linked to the original shared order');
    $h->assertSame($posOrderId, (int) ($saleDb->one('SELECT order_id FROM sale_returns ORDER BY id DESC LIMIT 1')['order_id'] ?? 0), 'sale POS return references original order');

    $emailReceiptResponse = $controllerFor(1, 'POST', '/admin/api/sale/pos/orders/' . $posOrderId . '/receipt/email', [], ['email' => 'client@example.test'])->posEmailReceipt($posOrderId);
    $h->assertSame(200, $emailReceiptResponse->status(), 'sale POS can email an order receipt');
    $h->assertSame('client@example.test', $mailer->messages[0]['to'] ?? null, 'sale POS receipt email uses requested recipient');
    $h->assertTrue(str_contains($mailer->messages[0]['subject'] ?? '', 'POS-'), 'sale POS receipt email subject uses POS reference');

    $sessionAfterSale = $saleDb->one('SELECT * FROM sale_cash_sessions WHERE id = ?', [$cashSessionId]);
    $expectedCash = (int) ($sessionAfterSale['expected_cash_minor'] ?? 0);
    $h->assertSame(6800, $expectedCash, 'sale POS cash session expected amount includes cash sale');

    $unjustifiedCloseResponse = $controllerFor(1, 'POST', '/admin/api/sale/pos/sessions/' . $cashSessionId . '/close', [], ['counted_cash_minor' => $expectedCash + 1])->closeCashSession($cashSessionId);
    $h->assertSame(422, $unjustifiedCloseResponse->status(), 'sale POS refuses an unexplained closing difference');
    $closeSessionResponse = $controllerFor(1, 'POST', '/admin/api/sale/pos/sessions/' . $cashSessionId . '/close', [], ['counted_cash_minor' => $expectedCash + 1, 'difference_justification' => 'un centime surnuméraire'])->closeCashSession($cashSessionId);
    $h->assertSame(200, $closeSessionResponse->status(), 'sale POS can close cash session');
    $closeSessionPayload = json_decode($closeSessionResponse->body(), true);
    $h->assertSame('closed', $closeSessionPayload['data']['session']['status'] ?? null, 'sale POS closed session is marked closed');
    $h->assertSame(1, (int) ($closeSessionPayload['data']['session']['difference_minor'] ?? 0), 'sale POS closed session computes cash difference');
    $h->assertSame('un centime surnuméraire', $closeSessionPayload['data']['session']['difference_justification'] ?? null, 'sale POS closed session keeps difference justification');
    $h->assertSame(1, (int) ($saleDb->one('SELECT COUNT(*) AS count FROM sale_outbox WHERE topic = "sale.pos.session.closed"')['count'] ?? 0), 'sale POS session closing is queued in outbox');

    $aiPosContextResponse = $controllerFor(1, 'GET', '/admin/api/sale/ai/pos/day-summary-context', ['date' => gmdate('Y-m-d')])->aiPosDaySummaryContext();
    $h->assertSame(200, $aiPosContextResponse->status(), 'sale AI POS day context is available');
    $aiPosContextPayload = json_decode($aiPosContextResponse->body(), true);
    $h->assertSame(false, $aiPosContextPayload['data']['external_ai_allowed'] ?? true, 'sale AI POS context forbids external AI by default');
    $h->assertTrue(($aiPosContextPayload['data']['totals']['orders_count'] ?? 0) >= 1, 'sale AI POS context includes daily orders');

    $aiCustomerContextResponse = $controllerFor(1, 'GET', '/admin/api/sale/ai/customers/contact/1/analysis-context')->aiCustomerSalesAnalysisContext('contact', 1);
    $h->assertSame(200, $aiCustomerContextResponse->status(), 'sale AI customer context is available');
    $aiUnpaidContextResponse = $controllerFor(1, 'GET', '/admin/api/sale/ai/unpaid-orders-context')->aiUnpaidOrdersContext();
    $h->assertSame(200, $aiUnpaidContextResponse->status(), 'sale AI unpaid orders context is available');

    $reportResponse = $controllerFor(1, 'GET', '/admin/api/sale/reports/daily')->dailyReport();
    $h->assertSame(200, $reportResponse->status(), 'sale admin can read daily report');
    $dailyReportPayload = json_decode($reportResponse->body(), true);
    $h->assertTrue(array_key_exists('by_channel', $dailyReportPayload['data']['report'] ?? []), 'sale daily report exposes channel breakdown');

    $channelsReportResponse = $controllerFor(1, 'GET', '/admin/api/sale/reports/channels')->channelsReport();
    $h->assertSame(200, $channelsReportResponse->status(), 'sale admin can read channel report');
    $paymentMethodsReportResponse = $controllerFor(1, 'GET', '/admin/api/sale/reports/payment-methods')->paymentMethodsReport();
    $h->assertSame(200, $paymentMethodsReportResponse->status(), 'sale admin can read payment methods report');
    $stockReportResponse = $controllerFor(1, 'GET', '/admin/api/sale/reports/stock')->stockReport();
    $h->assertSame(200, $stockReportResponse->status(), 'sale admin can read stock report');
    $refundsReportResponse = $controllerFor(1, 'GET', '/admin/api/sale/reports/refunds')->refundsReport();
    $h->assertSame(200, $refundsReportResponse->status(), 'sale admin can read refunds report');

    $ordersExport = $controllerFor(1, 'GET', '/admin/api/sale/export/orders.csv')->exportOrdersCsv();
    $h->assertSame(200, $ordersExport->status(), 'sale admin can export orders CSV');
    $h->assertSame('text/csv; charset=utf-8', $ordersExport->headers()['Content-Type'] ?? null, 'sale orders export is CSV');
    $h->assertTrue(str_contains($ordersExport->body(), 'order_number;channel_id;source'), 'sale orders export has stable headers');
    $h->assertTrue(str_contains($ordersExport->body(), 'POS-'), 'sale orders export contains POS order');

    $orderLinesExport = $controllerFor(1, 'GET', '/admin/api/sale/export/order-lines.csv')->exportOrderLinesCsv();
    $h->assertSame(200, $orderLinesExport->status(), 'sale admin can export order lines CSV');
    $h->assertTrue(str_contains($orderLinesExport->body(), 'unit_purchase_price_minor'), 'sale order lines export documents purchase column');
    $h->assertTrue(str_contains($orderLinesExport->body(), 'DEMO-GOURDE-BLEU'), 'sale order lines export contains SKU snapshot');

    $paymentsExport = $controllerFor(1, 'GET', '/admin/api/sale/export/payments.csv')->exportPaymentsCsv();
    $h->assertSame(200, $paymentsExport->status(), 'sale admin can export payments CSV');
    $h->assertTrue(str_contains($paymentsExport->body(), 'transaction_type;status;amount_minor'), 'sale payments export has stable headers');

    $posSessionsExport = $controllerFor(1, 'GET', '/admin/api/sale/export/pos-sessions.csv')->exportPosSessionsCsv();
    $h->assertSame(200, $posSessionsExport->status(), 'sale admin can export POS sessions CSV');
    $stockMovementsExport = $controllerFor(1, 'GET', '/admin/api/sale/export/stock-movements.csv')->exportStockMovementsCsv();
    $h->assertSame(200, $stockMovementsExport->status(), 'sale admin can export stock movements CSV');
    $returnsRefundsExport = $controllerFor(1, 'GET', '/admin/api/sale/export/returns-refunds.csv')->exportReturnsRefundsCsv();
    $h->assertSame(200, $returnsRefundsExport->status(), 'sale admin can export returns and refunds CSV');

    $stockImportCsv = "business_variant_id;sku;quantity_delta;reason\n" . (int) $variant['id'] . ";DEMO-GOURDE-BLEU;2;Import stock test\n";
    $stockPreview = $controllerFor(1, 'POST', '/admin/api/sale/import/stock/preview', [], ['csv' => $stockImportCsv])->previewStockImport();
    $h->assertSame(200, $stockPreview->status(), 'sale stock import preview validates CSV');
    $stockPreviewPayload = json_decode($stockPreview->body(), true);
    $h->assertSame(true, $stockPreviewPayload['data']['import']['dry_run'] ?? null, 'sale stock import preview is dry-run');
    $h->assertSame(1, $stockPreviewPayload['data']['import']['valid_rows'] ?? null, 'sale stock import preview reports valid rows');
    $stockApply = $controllerFor(1, 'POST', '/admin/api/sale/import/stock/apply', [], ['csv' => $stockImportCsv])->applyStockImport();
    $h->assertSame(200, $stockApply->status(), 'sale stock import apply writes adjustment');
    $stockApplyPayload = json_decode($stockApply->body(), true);
    $h->assertSame(1, $stockApplyPayload['data']['import']['adjusted'] ?? null, 'sale stock import apply reports adjustment');
    $h->assertSame(1, (int) ($saleDb->one('SELECT COUNT(*) AS count FROM sale_stock_movements WHERE reason = "Import stock test"')['count'] ?? 0), 'sale stock import writes stock movement');
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
