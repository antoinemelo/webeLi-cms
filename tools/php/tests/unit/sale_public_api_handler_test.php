<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Application\PublicApi\PublicSaleApiHandler;
use App\Application\Frontend\PublicSaleCheckoutController;
use App\Application\Frontend\PublicCustomerAccountController;
use App\Application\Frontend\PublicStorefrontCartController;
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
use App\Modules\Sale\Repositories\SalePaymentRepository;
use App\Modules\Sale\Payments\PaymentProviderRegistry;
use App\Modules\Sale\SaleModuleProvider;
use App\Modules\Sale\Services\SaleCartService;
use App\Modules\Sale\Services\SaleCatalogSnapshotService;
use App\Modules\Sale\Services\SaleCheckoutService;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Modules\Sale\Services\SaleEventService;
use App\Modules\Sale\Services\SaleGuestCheckoutService;
use App\Modules\Sale\Services\SaleIdempotencyService;
use App\Modules\Sale\Services\SaleInventoryService;
use App\Modules\Sale\Services\SaleOnlinePaymentService;
use App\Modules\Sale\Services\SaleStateMachineService;
use App\Repository\SiteRepository;

$h = new TestHarness();
$appConfig=require __DIR__.'/../../../../backend/config/app.php';
$h->assertSame(true,$appConfig['public_api_module_routes']??false,'native Storefront deployments load the public Sale routes required by cart and checkout');
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
    $guestCheckout = new SaleGuestCheckoutService($saleConnection, $carts, $channels, $catalogSnapshots, new SalePricingService(), $inventory, new SaleStateMachineService($saleConnection->database()));
    $onlinePayments = new SaleOnlinePaymentService($saleConnection,new SalePaymentRepository($saleConnection),$orders,$inventory,new SaleStateMachineService($saleConnection->database()),new PaymentProviderRegistry(null,$saleConnection->database(),null,'test'));

    $handlerFor = static function (string $method, string $path, array $payload = [], array $query = []) use ($sites, $saleConnection, $channels, $carts, $orders, $cartService, $checkout, $guestCheckout, $onlinePayments): PublicSaleApiHandler {
        $request = new Request($method, $path, $query, $payload === [] ? [] : ['data' => $payload], ['HTTP_HOST' => 'example.test'], [], []);
        return new PublicSaleApiHandler($request, $sites, $saleConnection, $channels, $carts, $orders, $cartService, $checkout, $guestCheckout, null, null, null, $onlinePayments);
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
        ['PATCH', '/api/v1/sale/channels/web-main/cart/test-token/checkout'],
        ['DELETE', '/api/v1/sale/channels/web-main/cart/test-token'],
        ['POST', '/api/v1/sale/channels/web-main/checkout'],
        ['POST', '/api/v1/sale/channels/web-main/gift-cards/validate'],
        ['POST', '/api/v1/sale/channels/web-main/gift-cards/claim'],
        ['POST', '/api/v1/sale/channels/web-main/cart/test-token/payment-retry'],
    ] as [$method, $path]) {
        $h->assertTrue((new Router())->match($method, $path, $routes) !== null, 'sale public ecommerce route is declared: ' . $method . ' ' . $path);
    }
    $h->assertSame(null, (new Router())->match('GET', '/api/v1/sale/orders', $routes), 'sale public API still has no public order listing');
    $webRoutes = require __DIR__ . '/../../../../backend/routes/web.php';
    $h->assertTrue((new Router())->match('GET', '/checkout', $webRoutes) !== null, 'native guest checkout SSR route is declared');
    $h->assertTrue((new Router())->match('GET', '/gift-card', $webRoutes) !== null, 'one-time gift card reveal SSR route is declared');
    $h->assertTrue((new Router())->match('GET', '/cart', $webRoutes) !== null, 'native storefront cart route is declared');
    $h->assertTrue((new Router())->match('GET', '/account', $webRoutes) !== null, 'secure customer account SSR route is declared');
    $ssr = (new PublicSaleCheckoutController(new Request('GET', '/checkout', ['channel' => 'web-main', 'cart_token' => str_repeat('A', 43)], [], ['HTTP_HOST' => 'example.test'], [], [])))->show();
    $h->assertSame(200, $ssr->status(), 'native guest checkout SSR renders');
    $h->assertTrue(str_contains($ssr->body(), 'data-checkout-form'), 'native guest checkout SSR includes the accessible form');
    $h->assertTrue(str_contains($ssr->body(), 'guest-checkout.js'), 'native guest checkout SSR loads the checkout client progressively');
    $cartPage = (new PublicStorefrontCartController())->show();
    $h->assertSame(200, $cartPage->status(), 'native storefront cart page renders');
    $h->assertTrue(str_contains($cartPage->body(), 'data-cart-page'), 'storefront cart page exposes the accessible cart root');
    $h->assertTrue(str_contains($cartPage->body(),'data-storefront-api-base="'.public_api_url_path('sale/channels/web-main').'"'),'standalone cart page exposes the installation-aware public Sale API base');
    $accountPage = (new PublicCustomerAccountController())->show();
    $h->assertSame(200, $accountPage->status(), 'customer account SSR renders');
    $h->assertTrue(str_contains($accountPage->body(), 'data-customer-account'), 'customer account SSR exposes the secure client root');
    $h->assertSame('no-store, private', $accountPage->headers()['Cache-Control'] ?? null, 'customer account page is never cached');

    $saleDb->run("UPDATE sale_channels SET status = 'draft', is_public = 0 WHERE site_id = 1 AND code = 'web-main'");
    $disabled = $handlerFor('GET', '/api/v1/sale/channels/web-main/bootstrap')->bootstrap('web-main');
    $h->assertSame(404, $disabled->status(), 'draft non-public ecommerce channel is refused');
    $saleDb->run("UPDATE sale_channels SET status = 'active', is_public = 1 WHERE site_id = 1 AND code = 'web-main'");
    $variant = $businessDb->one("SELECT id FROM business_product_variants WHERE sku = 'DEMO-GOURDE-BLEU' LIMIT 1");

    $bootstrap = $handlerFor('GET', '/api/v1/sale/channels/web-main/bootstrap')->bootstrap('web-main');
    $h->assertSame(200, $bootstrap->status(), 'active public ecommerce channel can bootstrap');
    $bootstrapPayload = json_decode($bootstrap->body(), true);
    $h->assertSame('web-main', $bootstrapPayload['data']['channel']['code'] ?? null, 'bootstrap exposes public channel code');
    $publicPaymentMethods = array_column($bootstrapPayload['data']['payment_methods'] ?? [], null, 'code');
    $h->assertSame('Virement bancaire', $publicPaymentMethods['bank_transfer']['label'] ?? null, 'bootstrap exposes localized payment methods');
    $h->assertSame('redirect', $publicPaymentMethods['sandbox_online']['next_action'] ?? null, 'bootstrap explains the next payment action');
    $h->assertTrue(!array_key_exists('provider_key', $publicPaymentMethods['sandbox_online'] ?? []), 'bootstrap does not expose provider implementation details');

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

    $addLine = $handlerFor('POST', '/api/v1/sale/channels/web-main/cart/' . $token . '/lines', ['sellable_id' => (int) $variant['id'], 'quantity' => 1, 'idempotency_key' => 'public-sale-line'])->addLine('web-main', $token);
    $h->assertSame(201, $addLine->status(), 'public ecommerce cart can add a line');
    $addLinePayload = json_decode($addLine->body(), true);
    $lineId = (int) ($addLinePayload['data']['line']['id'] ?? 0);
    $h->assertSame(2900, (int) ($addLinePayload['data']['cart']['grand_total_minor'] ?? 0), 'public ecommerce line uses server-side price snapshot');
    $h->assertTrue(!str_contains($addLine->body(), 'purchase'), 'public ecommerce line payload does not expose purchase price');

    $staleUpdate = $handlerFor('PATCH', '/api/v1/sale/channels/web-main/cart/' . $token . '/lines/' . $lineId, ['quantity' => 2, 'expected_version' => 0])->updateLine('web-main', $token, $lineId);
    $h->assertSame(409, $staleUpdate->status(), 'public cart rejects a stale optimistic version');
    $h->assertTrue(str_contains($staleUpdate->body(), 'REVISION_CONFLICT'), 'public cart exposes a stable concurrency error');

    $updateLine = $handlerFor('PATCH', '/api/v1/sale/channels/web-main/cart/' . $token . '/lines/' . $lineId, ['quantity' => 2])->updateLine('web-main', $token, $lineId);
    $h->assertSame(200, $updateLine->status(), 'public ecommerce cart can update a line');
    $updateLinePayload = json_decode($updateLine->body(), true);
    $h->assertSame(5800, (int) ($updateLinePayload['data']['cart']['grand_total_minor'] ?? 0), 'public ecommerce update recalculates cart totals');

    $deleteLine = $handlerFor('DELETE', '/api/v1/sale/channels/web-main/cart/' . $token . '/lines/' . $lineId)->deleteLine('web-main', $token, $lineId);
    $h->assertSame(200, $deleteLine->status(), 'public ecommerce cart can delete a line');
    $afterDeletePayload = json_decode($deleteLine->body(), true);
    $h->assertSame(0, (int) ($afterDeletePayload['data']['cart']['grand_total_minor'] ?? -1), 'public ecommerce delete recalculates cart total');

    $handlerFor('POST', '/api/v1/sale/channels/web-main/cart/' . $token . '/lines', ['sellable_id' => (int) $variant['id'], 'quantity' => 1, 'idempotency_key' => 'public-sale-line-again'])->addLine('web-main', $token);
    $guestData = [
        'identity' => ['email' => 'guest@example.test', 'first_name' => 'Anne', 'last_name' => 'Invitée', 'phone' => '+41 79 000 00 00'],
        'billing_address' => ['line1' => 'Rue du Test 1', 'postal_code' => '1000', 'city' => 'Lausanne', 'country_code' => 'CH'],
        'shipping_same_as_billing' => true,
        'shipping_method' => ['code' => 'standard', 'amount_minor' => 999999],
        'payment' => ['code' => 'bank_transfer', 'secret' => 'must-not-be-stored'],
        'terms_accepted' => true,
        'marketing_consent' => false,
        'grand_total_minor' => 1,
    ];
    $invalidEmail = $handlerFor('PATCH', '/api/v1/sale/channels/web-main/cart/' . $token . '/checkout', array_replace($guestData, ['identity' => ['email' => 'invalid', 'first_name' => 'Anne', 'last_name' => 'Invitée']]))->updateCheckout('web-main', $token);
    $h->assertSame(422, $invalidEmail->status(), 'guest checkout rejects invalid email');
    $missingAddress = $guestData;
    $missingAddress['billing_address'] = [];
    $missingAddressResponse = $handlerFor('PATCH', '/api/v1/sale/channels/web-main/cart/' . $token . '/checkout', $missingAddress)->updateCheckout('web-main', $token);
    $h->assertSame(422, $missingAddressResponse->status(), 'guest checkout rejects missing address');
    $termsMissing = $guestData;
    $termsMissing['terms_accepted'] = false;
    $termsResponse = $handlerFor('POST', '/api/v1/sale/channels/web-main/checkout', ['cart_token' => $token, 'idempotency_key' => 'public-sale-terms'] + $termsMissing)->checkout('web-main');
    $h->assertSame(422, $termsResponse->status(), 'guest checkout rejects missing terms consent');

    $review = $handlerFor('PATCH', '/api/v1/sale/channels/web-main/cart/' . $token . '/checkout', ['step' => 'review'] + $guestData)->updateCheckout('web-main', $token);
    $h->assertSame(200, $review->status(), 'guest checkout review is persisted and recalculated');
    $reviewBody = json_decode($review->body(), true);
    $h->assertSame(3800, (int) ($reviewBody['data']['cart']['grand_total_minor'] ?? 0), 'client supplied total is ignored');
    $h->assertSame(900, (int) ($reviewBody['data']['cart']['shipping_method']['amount_minor'] ?? -1), 'fixed fulfillment rate is calculated by the server');
    $h->assertSame(false, $reviewBody['data']['cart']['marketing_consent'] ?? null, 'marketing refusal remains distinct from terms consent');
    $h->assertSame('active', $saleDb->one('SELECT status FROM sale_stock_reservations WHERE cart_id=?', [$cartId])['status'] ?? null, 'Storefront review creates the checkout reservation');
    $reviewLine = $reviewBody['data']['cart']['lines'][0] ?? [];
    $h->assertSame('sale.inventory.availability.v1', $reviewLine['availability']['contract'] ?? null, 'cart line exposes the same versioned availability contract as Shop');
    $h->assertSame('En stock', $reviewLine['availability']['label'] ?? null, 'cart line uses a readable availability label');
    $h->assertSame(1, (int) ($reviewLine['reservation']['physical_quantity'] ?? 0), 'cart explains the physically protected quantity');
    $h->assertTrue(($reviewLine['reservation']['expires_at'] ?? null) !== null, 'cart exposes reservation expiry for immediate feedback');
    $h->assertTrue(in_array('reduce_quantity', $reviewLine['recovery_options'] ?? [], true), 'cart exposes a quantity reduction recovery without losing the cart');

    $checkoutPayload = ['cart_token' => $token, 'idempotency_key' => 'public-sale-checkout'] + $guestData;
    $checkoutResponse = $handlerFor('POST', '/api/v1/sale/channels/web-main/checkout', $checkoutPayload)->checkout('web-main');
    $h->assertSame(201, $checkoutResponse->status(), 'public ecommerce cart can checkout');
    $checkoutBody = json_decode($checkoutResponse->body(), true);
    $orderId = (int) ($checkoutBody['data']['order']['id'] ?? 0);
    $h->assertSame('ecommerce', $checkoutBody['data']['order']['source'] ?? null, 'public ecommerce checkout creates ecommerce order');
    $h->assertTrue(!str_contains($checkoutResponse->body(), 'customer_snapshot_json'), 'public ecommerce checkout does not expose internal customer snapshot JSON');
    $h->assertTrue(!str_contains($checkoutResponse->body(), 'must-not-be-stored'), 'public ecommerce checkout never stores payment secrets');
    $placedOrder = $saleDb->one('SELECT * FROM sale_orders WHERE id=?', [$orderId]);
    $h->assertSame(1, (int) ($placedOrder['terms_accepted'] ?? 0), 'terms consent is frozen on the order');
    $h->assertSame(0, (int) ($placedOrder['marketing_consent'] ?? 1), 'marketing refusal is frozen separately');
    $h->assertSame('guest@example.test', json_decode((string) $placedOrder['customer_snapshot_json'], true)['email'] ?? null, 'guest identity snapshot is frozen on the order');
    $h->assertSame(900, (int) $placedOrder['shipping_total_minor'], 'fulfillment total is frozen on the order');
    $h->assertSame('confirmed', $saleDb->one('SELECT status FROM sale_stock_reservations WHERE cart_id=?', [$cartId])['status'] ?? null, 'Bank transfer keeps the checkout reservation while awaiting receipt');
    $h->assertSame('pending_payment',(string)($placedOrder['status']??''),'bank transfer order remains explicitly pending');
    $h->assertSame('awaiting_receipt',$checkoutBody['data']['payment']['instructions']['status']??null,'bank transfer exposes unambiguous pending instructions');
    $retryResponse=$handlerFor('POST','/api/v1/sale/channels/web-main/cart/'.$token.'/payment-retry',['payment'=>['code'=>'manual'],'idempotency_key'=>'public-sale-payment-retry'])->retryPayment('web-main',$token);
    $h->assertSame(201,$retryResponse->status(),'pending checkout can retry with another configured provider');
    $retryBody=json_decode($retryResponse->body(),true);
    $h->assertSame('manual_card',$retryBody['data']['payment']['provider']??null,'payment retry resolves provider through configured method');
    $h->assertTrue((int) ($saleDb->one('SELECT COUNT(*) AS c FROM sale_order_tax_lines WHERE order_id=?',[$orderId])['c']??0)>0, 'tax snapshots are persisted per order line');
    $shippingSnapshot=(string)$placedOrder['shipping_method_snapshot_json'];
    $saleDb->run("UPDATE sale_fulfillment_methods SET flat_rate_minor=1500 WHERE site_id=1 AND code='standard'");
    $h->assertSame($shippingSnapshot,(string)$saleDb->one('SELECT shipping_method_snapshot_json FROM sale_orders WHERE id=?',[$orderId])['shipping_method_snapshot_json'],'fulfillment snapshot is immutable after configuration changes');
    $saleDb->run("UPDATE sale_fulfillment_methods SET flat_rate_minor=900 WHERE site_id=1 AND code='standard'");

    $checkoutReplay = $handlerFor('POST', '/api/v1/sale/channels/web-main/checkout', $checkoutPayload)->checkout('web-main');
    $h->assertSame(201, $checkoutReplay->status(), 'public ecommerce checkout is idempotent');
    $h->assertSame(1, (int) ($saleDb->one('SELECT COUNT(*) AS count FROM sale_orders WHERE id = ?', [$orderId])['count'] ?? 0), 'public ecommerce idempotent replay does not duplicate the order');
    $conflictingReplay = $checkoutPayload;
    $conflictingReplay['identity'] = ['email' => 'other@example.test', 'first_name' => 'Anne', 'last_name' => 'Invitée'];
    $conflictingReplayResponse = $handlerFor('POST', '/api/v1/sale/channels/web-main/checkout', $conflictingReplay)->checkout('web-main');
    $h->assertSame(422, $conflictingReplayResponse->status(), 'same checkout key rejects a different guest payload');
    $secondKey = $checkoutPayload;
    $secondKey['idempotency_key'] = 'public-sale-checkout-second';
    $differentKey = $handlerFor('POST', '/api/v1/sale/channels/web-main/checkout', $secondKey)->checkout('web-main');
    $h->assertSame(422, $differentKey->status(), 'second checkout key cannot create another order from a converted cart');
    $h->assertSame(1, (int) ($saleDb->one('SELECT COUNT(*) AS count FROM sale_orders WHERE source_cart_id=?', [$cartId])['count'] ?? 0), 'source cart uniqueness prevents duplicate orders across different keys');

    $priceCartResponse = $handlerFor('POST', '/api/v1/sale/channels/web-main/cart')->storeCart('web-main');
    $priceCartBody = json_decode($priceCartResponse->body(), true);
    $priceToken = (string) ($priceCartBody['data']['cart']['token'] ?? '');
    $handlerFor('POST', '/api/v1/sale/channels/web-main/cart/' . $priceToken . '/lines', ['sellable_id' => (int) $variant['id'], 'quantity' => 1, 'idempotency_key' => 'price-change-line'])->addLine('web-main', $priceToken);
    $businessDb->run('UPDATE business_product_base_prices SET amount=31 WHERE product_id=(SELECT product_id FROM business_product_variants WHERE id=?) AND price_kind=\'sale\'', [(int) $variant['id']]);
    $priceReview = $handlerFor('PATCH', '/api/v1/sale/channels/web-main/cart/' . $priceToken . '/checkout', ['step' => 'review'] + $guestData)->updateCheckout('web-main', $priceToken);
    $priceReviewBody = json_decode($priceReview->body(), true);
    $h->assertSame(true, $priceReviewBody['data']['price_changed'] ?? false, 'price modified before validation is detected');
    $h->assertSame(4000, (int) ($priceReviewBody['data']['cart']['grand_total_minor'] ?? 0), 'modified price and fulfillment are recalculated from server configuration');
    $businessDb->run('UPDATE business_product_base_prices SET amount=29 WHERE product_id=(SELECT product_id FROM business_product_variants WHERE id=?) AND price_kind=\'sale\'', [(int) $variant['id']]);

    $unavailableCartResponse = $handlerFor('POST', '/api/v1/sale/channels/web-main/cart')->storeCart('web-main');
    $unavailableBody = json_decode($unavailableCartResponse->body(), true);
    $unavailableToken = (string) ($unavailableBody['data']['cart']['token'] ?? '');
    $handlerFor('POST', '/api/v1/sale/channels/web-main/cart/' . $unavailableToken . '/lines', ['sellable_id' => (int) $variant['id'], 'quantity' => 1, 'idempotency_key' => 'unavailable-line'])->addLine('web-main', $unavailableToken);
    $businessDb->run("UPDATE business_product_variants SET status='draft' WHERE id=?", [(int) $variant['id']]);
    $unavailableReview = $handlerFor('PATCH', '/api/v1/sale/channels/web-main/cart/' . $unavailableToken . '/checkout', ['step' => 'review'] + $guestData)->updateCheckout('web-main', $unavailableToken);
    $h->assertSame(422, $unavailableReview->status(), 'product unavailable before validation blocks checkout');
    $businessDb->run("UPDATE business_product_variants SET status='active' WHERE id=?", [(int) $variant['id']]);

    $otherCartResponse = $handlerFor('POST', '/api/v1/sale/channels/web-main/cart')->storeCart('web-main');
    $otherBody = json_decode($otherCartResponse->body(), true);
    $otherToken = (string) ($otherBody['data']['cart']['token'] ?? '');
    $foreignLine = $handlerFor('POST', '/api/v1/sale/channels/web-main/cart/' . $priceToken . '/lines', ['sellable_id' => (int) $variant['id'], 'quantity' => 1, 'idempotency_key' => 'foreign-line'])->addLine('web-main', $priceToken);
    $foreignLineId = (int) (json_decode($foreignLine->body(), true)['data']['line']['id'] ?? 0);
    $foreignUpdate = $handlerFor('PATCH', '/api/v1/sale/channels/web-main/cart/' . $otherToken . '/lines/' . $foreignLineId, ['quantity' => 2])->updateLine('web-main', $otherToken, $foreignLineId);
    $h->assertSame(404, $foreignUpdate->status(), 'a cart token cannot modify a line owned by another cart');

    $expiredId = (int) ($otherBody['data']['cart']['id'] ?? 0);
    $saleDb->run("UPDATE sale_carts SET expires_at=datetime('now','-1 minute') WHERE id=?", [$expiredId]);
    $expired = $handlerFor('GET', '/api/v1/sale/channels/web-main/cart/' . $otherToken)->cart('web-main', $otherToken);
    $h->assertSame(404, $expired->status(), 'expired guest cart is no longer accessible');
    $h->assertSame('expired', (string) ($saleDb->one('SELECT status FROM sale_carts WHERE id=?', [$expiredId])['status'] ?? ''), 'expired guest cart is moved to the terminal state');

    $abandonCartResponse = $handlerFor('POST', '/api/v1/sale/channels/web-main/cart')->storeCart('web-main');
    $abandonBody = json_decode($abandonCartResponse->body(), true);
    $abandonToken = (string) ($abandonBody['data']['cart']['token'] ?? '');
    $abandoned = $handlerFor('DELETE', '/api/v1/sale/channels/web-main/cart/' . $abandonToken)->abandonCart('web-main', $abandonToken);
    $h->assertSame(200, $abandoned->status(), 'guest can explicitly abandon a cart');
    $abandonedRead = $handlerFor('GET', '/api/v1/sale/channels/web-main/cart/' . $abandonToken)->cart('web-main', $abandonToken);
    $h->assertSame(404, $abandonedRead->status(), 'abandoned cart token cannot read the cart anymore');
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
