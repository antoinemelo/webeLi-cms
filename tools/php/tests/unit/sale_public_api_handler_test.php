<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Application\PublicApi\PublicSaleApiHandler;
use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Modules\Business\Catalog\CatalogPricingService;
use App\Modules\Business\Repositories\BusinessCatalogPricingRepository;
use App\Modules\Business\Repositories\PosCatalogRepository;
use App\Modules\Business\Services\BusinessCatalogSellableReadService;
use App\Modules\Sale\Adapters\BusinessSellableCatalogAdapter;
use App\Modules\Sale\Pricing\SalePricingService;
use App\Modules\Sale\Repositories\SaleCartRepository;
use App\Modules\Sale\Repositories\SaleChannelRepository;
use App\Modules\Sale\Repositories\SaleEventRepository;
use App\Modules\Sale\Repositories\SaleIdempotencyRepository;
use App\Modules\Sale\Repositories\SaleInventoryRepository;
use App\Modules\Sale\Repositories\SaleOrderRepository;
use App\Modules\Sale\SaleModuleProvider;
use App\Modules\Sale\Services\SaleCartService;
use App\Modules\Sale\Services\SaleCatalogSnapshotService;
use App\Modules\Sale\Services\SaleCheckoutService;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Modules\Sale\Services\SaleEventService;
use App\Modules\Sale\Services\SaleIdempotencyService;
use App\Modules\Sale\Services\SaleInventoryService;
use App\Repository\SiteRepository;

$h = new TestHarness();
[$businessDir, $businessPath, $businessDb] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/business.sql');
[$saleDir, $salePath, $saleDb] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/sale.sql');
$coreDir = sys_get_temp_dir() . '/amcms-sale-public-core-' . bin2hex(random_bytes(6));
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

    $sites = new SiteRepository($core, ['cms' => ['default_site_key' => 'main'], 'app' => ['default_locale' => 'fr']]);
    $pricingRepository = new BusinessCatalogPricingRepository($businessDb);
    $sellables = new BusinessCatalogSellableReadService($pricingRepository, new CatalogPricingService($pricingRepository), new PosCatalogRepository($businessDb));
    $saleConnection = new SaleDatabaseConnection($salePath);
    $channels = new SaleChannelRepository($saleConnection);
    $carts = new SaleCartRepository($saleConnection);
    $orders = new SaleOrderRepository($saleConnection);
    $inventory = new SaleInventoryService(new SaleInventoryRepository($saleConnection));
    $events = new SaleEventService(new SaleEventRepository($saleConnection));
    $idempotency = new SaleIdempotencyService(new SaleIdempotencyRepository($saleConnection));
    $catalogSnapshots = new SaleCatalogSnapshotService($saleConnection, new BusinessSellableCatalogAdapter($sellables));
    $cartService = new SaleCartService($carts, $channels, $catalogSnapshots, new SalePricingService(), $inventory, $events, $idempotency);
    $checkout = new SaleCheckoutService($saleConnection, $carts, $orders, $inventory, $events, $idempotency);

    $handlerFor = static function (string $method, string $path, array $payload = [], array $query = []) use ($sites, $saleConnection, $channels, $carts, $orders, $cartService, $checkout): PublicSaleApiHandler {
        $request = new Request($method, $path, $query, $payload === [] ? [] : ['data' => $payload], ['HTTP_HOST' => 'example.test'], [], []);
        return new PublicSaleApiHandler($request, $sites, $saleConnection, $channels, $carts, $orders, $cartService, $checkout);
    };

    $provider = new SaleModuleProvider();
    $routes = $provider->publicHeadlessRoutes();
    foreach ([
        ['GET', '/api/v1/sale/channels/web-main/bootstrap'],
        ['POST', '/api/v1/sale/channels/web-main/cart'],
        ['GET', '/api/v1/sale/channels/web-main/cart/test-token'],
        ['POST', '/api/v1/sale/channels/web-main/cart/test-token/lines'],
        ['PATCH', '/api/v1/sale/channels/web-main/cart/test-token/lines/1'],
        ['DELETE', '/api/v1/sale/channels/web-main/cart/test-token/lines/1'],
        ['POST', '/api/v1/sale/channels/web-main/checkout'],
    ] as [$method, $path]) {
        $h->assertTrue((new Router())->match($method, $path, $routes) !== null, 'sale public ecommerce route is declared: ' . $method . ' ' . $path);
    }
    $h->assertSame(null, (new Router())->match('GET', '/api/v1/sale/orders', $routes), 'sale public API still has no public order listing');

    $disabled = $handlerFor('GET', '/api/v1/sale/channels/web-main/bootstrap')->bootstrap('web-main');
    $h->assertSame(404, $disabled->status(), 'draft non-public ecommerce channel is refused by default');

    $saleDb->run("UPDATE sale_channels SET status = 'active', is_public = 1 WHERE site_id = 1 AND code = 'web-main'");
    $variant = $businessDb->one("SELECT id FROM business_product_variants WHERE sku = 'DEMO-GOURDE-BLEU' LIMIT 1");

    $bootstrap = $handlerFor('GET', '/api/v1/sale/channels/web-main/bootstrap')->bootstrap('web-main');
    $h->assertSame(200, $bootstrap->status(), 'active public ecommerce channel can bootstrap');
    $bootstrapPayload = json_decode($bootstrap->body(), true);
    $h->assertSame('web-main', $bootstrapPayload['data']['channel']['code'] ?? null, 'bootstrap exposes public channel code');

    $cartResponse = $handlerFor('POST', '/api/v1/sale/channels/web-main/cart')->storeCart('web-main');
    $h->assertSame(201, $cartResponse->status(), 'public ecommerce cart can be created');
    $cartPayload = json_decode($cartResponse->body(), true);
    $token = (string) ($cartPayload['data']['cart']['token'] ?? '');
    $cartId = (int) ($cartPayload['data']['cart']['id'] ?? 0);
    $h->assertTrue(strlen($token) >= 32, 'public ecommerce cart returns opaque token once');
    $storedCart = $saleDb->one('SELECT * FROM sale_carts WHERE id = ?', [$cartId]);
    $h->assertTrue(($storedCart['cart_token_hash'] ?? '') !== $token, 'public ecommerce cart token is stored only as hash');
    $h->assertSame(hash('sha256', $token), $storedCart['cart_token_hash'] ?? null, 'public ecommerce cart hash matches token');

    $showCart = $handlerFor('GET', '/api/v1/sale/channels/web-main/cart/' . $token)->cart('web-main', $token);
    $h->assertSame(200, $showCart->status(), 'public ecommerce cart can be reloaded by token');
    $h->assertTrue(!str_contains($showCart->body(), 'cart_token_hash'), 'public ecommerce cart response does not expose token hash');

    $addLine = $handlerFor('POST', '/api/v1/sale/channels/web-main/cart/' . $token . '/lines', ['business_variant_id' => (int) $variant['id'], 'quantity' => 1, 'idempotency_key' => 'public-sale-line'])->addLine('web-main', $token);
    $h->assertSame(201, $addLine->status(), 'public ecommerce cart can add a line');
    $addLinePayload = json_decode($addLine->body(), true);
    $lineId = (int) ($addLinePayload['data']['line']['id'] ?? 0);
    $h->assertSame(2900, (int) ($addLinePayload['data']['cart']['grand_total_minor'] ?? 0), 'public ecommerce line uses server-side price snapshot');
    $h->assertTrue(!str_contains($addLine->body(), 'purchase'), 'public ecommerce line payload does not expose purchase price');

    $updateLine = $handlerFor('PATCH', '/api/v1/sale/channels/web-main/cart/' . $token . '/lines/' . $lineId, ['quantity' => 2])->updateLine('web-main', $token, $lineId);
    $h->assertSame(200, $updateLine->status(), 'public ecommerce cart can update a line');
    $updateLinePayload = json_decode($updateLine->body(), true);
    $h->assertSame(5800, (int) ($updateLinePayload['data']['cart']['grand_total_minor'] ?? 0), 'public ecommerce update recalculates cart totals');

    $deleteLine = $handlerFor('DELETE', '/api/v1/sale/channels/web-main/cart/' . $token . '/lines/' . $lineId)->deleteLine('web-main', $token, $lineId);
    $h->assertSame(200, $deleteLine->status(), 'public ecommerce cart can delete a line');
    $afterDeletePayload = json_decode($deleteLine->body(), true);
    $h->assertSame(0, (int) ($afterDeletePayload['data']['cart']['grand_total_minor'] ?? -1), 'public ecommerce delete recalculates cart total');

    $handlerFor('POST', '/api/v1/sale/channels/web-main/cart/' . $token . '/lines', ['business_variant_id' => (int) $variant['id'], 'quantity' => 1, 'idempotency_key' => 'public-sale-line-again'])->addLine('web-main', $token);
    $checkoutPayload = ['cart_token' => $token, 'idempotency_key' => 'public-sale-checkout'];
    $checkoutResponse = $handlerFor('POST', '/api/v1/sale/channels/web-main/checkout', $checkoutPayload)->checkout('web-main');
    $h->assertSame(201, $checkoutResponse->status(), 'public ecommerce cart can checkout');
    $checkoutBody = json_decode($checkoutResponse->body(), true);
    $orderId = (int) ($checkoutBody['data']['order']['id'] ?? 0);
    $h->assertSame('ecommerce', $checkoutBody['data']['order']['source'] ?? null, 'public ecommerce checkout creates ecommerce order');
    $h->assertTrue(!str_contains($checkoutResponse->body(), 'customer_snapshot_json'), 'public ecommerce checkout does not expose internal customer snapshot JSON');

    $checkoutReplay = $handlerFor('POST', '/api/v1/sale/channels/web-main/checkout', $checkoutPayload)->checkout('web-main');
    $h->assertSame(201, $checkoutReplay->status(), 'public ecommerce checkout is idempotent');
    $h->assertSame(1, (int) ($saleDb->one('SELECT COUNT(*) AS count FROM sale_orders WHERE id = ?', [$orderId])['count'] ?? 0), 'public ecommerce idempotent replay does not duplicate the order');
} finally {
    $businessDb = null;
    $saleDb = null;
    $core = null;
    gc_collect_cycles();
    test_remove_tree($businessDir);
    test_remove_tree($saleDir);
    test_remove_tree($coreDir);
}

exit($h->finish('UNIT sale public ecommerce API'));
