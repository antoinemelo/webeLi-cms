<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Application\Api\Admin\BusinessCatalogApiController;
use App\Core\ApiException;
use App\Core\Database;
use App\Core\Request;
use App\Modules\Business\Catalog\CatalogPricingService;
use App\Modules\Business\Repositories\BusinessCatalogPricingRepository;
use App\Modules\Business\Repositories\CatalogBrandRepository;
use App\Modules\Business\Repositories\CatalogCategoryRepository;
use App\Modules\Business\Repositories\CatalogDiscountRepository;
use App\Modules\Business\Repositories\CatalogOptionRepository;
use App\Modules\Business\Repositories\CatalogProductRepository;
use App\Modules\Business\Repositories\CatalogStockRepository;
use App\Modules\Business\Repositories\CatalogVariantRepository;
use App\Modules\Business\Services\CatalogCsvService;
use App\Modules\Business\Services\CatalogDiscountService;
use App\Modules\Business\Services\CatalogProductService;
use App\Modules\Business\Services\CatalogStockService;
use App\Modules\Business\Services\CatalogVariantService;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;

$h = new TestHarness();
[$businessDir, $businessPath, $businessDb] = test_temp_cms_db(__DIR__ . '/../../../../database/migrations/business/0001_init.sql');
$catalogSchema = file_get_contents(__DIR__ . '/../../../../database/migrations/business/0003_catalog_schema.sql');
if ($catalogSchema === false) {
    throw new RuntimeException('Unable to read business catalog schema.');
}
$businessDb->pdo()->exec($catalogSchema);
[$iamDir, $iamPath] = test_temp_db(__DIR__ . '/../../../../database/iam.sql');
$coreDir = sys_get_temp_dir() . '/amcms-business-catalog-api-core-' . bin2hex(random_bytes(6));
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
    $iam->run("INSERT INTO iam_users(id,email,email_normalized,password_hash,is_active,login_mode) VALUES(1,'catalog-admin@example.test','catalog-admin@example.test','x',1,'password'),(2,'catalog-sales@example.test','catalog-sales@example.test','x',1,'password'),(3,'catalog-empty@example.test','catalog-empty@example.test','x',1,'password')");
    $iam->run("INSERT INTO iam_roles(id,role_key,name) VALUES(1,'catalog_admin','Catalog admin'),(2,'catalog_sales','Catalog sales'),(3,'catalog_empty','Catalog empty')");
    $permissions = [
        'business.catalog.read',
        'business.catalog.write',
        'business.catalog.prices.read',
        'business.catalog.prices.write',
        'business.catalog.purchase_prices.read',
        'business.catalog.discounts.write',
        'business.catalog.stock.write',
    ];
    foreach ($permissions as $index => $permission) {
        $iam->run('INSERT INTO iam_permissions(id, permission_key, name) VALUES(?, ?, ?)', [$index + 1, $permission, $permission]);
    }
    foreach (range(1, count($permissions)) as $permissionId) {
        $iam->run('INSERT INTO iam_role_permissions(role_id, permission_id) VALUES(1, ?)', [$permissionId]);
    }
    foreach ([1, 3] as $permissionId) {
        $iam->run('INSERT INTO iam_role_permissions(role_id, permission_id) VALUES(2, ?)', [$permissionId]);
    }
    $iam->run('INSERT INTO iam_user_site_roles(user_id, site_id, role_id) VALUES(1,1,1),(2,1,2),(3,1,3)');
    foreach ([1, 2, 3] as $userId) {
        $token = 'business-catalog-api-test-token-' . $userId;
        $iam->run(
            'INSERT INTO iam_sessions(user_id, session_token_hash, ip_address, user_agent, last_seen_at, expires_at, created_at)
             VALUES(:user_id, :hash, :ip, :ua, :last_seen, :expires, :created)',
            [
                'user_id' => $userId,
                'hash' => hash('sha256', $token),
                'ip' => '127.0.0.1',
                'ua' => 'business-catalog-api-controller-test',
                'last_seen' => gmdate('Y-m-d H:i:s'),
                'expires' => gmdate('Y-m-d H:i:s', time() + 3600),
                'created' => gmdate('Y-m-d H:i:s', time() - 60),
            ]
        );
    }

    $brands = new CatalogBrandRepository($businessDb);
    $categories = new CatalogCategoryRepository($businessDb);
    $products = new CatalogProductRepository($businessDb);
    $variants = new CatalogVariantRepository($businessDb);
    $options = new CatalogOptionRepository($businessDb);
    $discounts = new CatalogDiscountRepository($businessDb);
    $stock = new CatalogStockRepository($businessDb);
    $productService = new CatalogProductService($products);
    $variantService = new CatalogVariantService($variants);
    $discountService = new CatalogDiscountService($discounts);
    $stockService = new CatalogStockService($stock);
    $pricing = new CatalogPricingService(new BusinessCatalogPricingRepository($businessDb));
    $csv = new CatalogCsvService($businessDb, $brands, $categories, $products, $variants, $options, $pricing);
    $sites = new SiteRepository($core, ['cms' => ['default_site_key' => 'main'], 'app' => ['default_locale' => 'fr']]);

    $controllerFor = static function (int $userId, string $method, string $path, array $query = [], array $payload = []) use ($iam, $sites, $brands, $categories, $products, $variants, $options, $discounts, $productService, $variantService, $discountService, $stockService, $csv, $pricing): BusinessCatalogApiController {
        $token = 'business-catalog-api-test-token-' . $userId;
        $_SESSION['admin_user'] = ['id' => $userId, 'email' => 'catalog-' . $userId . '@example.test', 'session_secret' => $token];
        $request = new Request($method, $path, $query, $payload === [] ? [] : ['data' => $payload], ['HTTP_HOST' => 'example.test'], [], []);
        $auth = new AuthRepository($iam);
        return new BusinessCatalogApiController($request, $sites, $auth, new Authorization($auth), $brands, $categories, $products, $variants, $options, $discounts, $productService, $variantService, $discountService, $stockService, $csv, $pricing);
    };

    $brandResponse = $controllerFor(1, 'POST', '/admin/api/business/catalog/brands', [], ['name' => 'API Brand'])->storeBrand();
    $h->assertSame(201, $brandResponse->status(), 'catalog admin can create brand');
    $brandPayload = json_decode($brandResponse->body(), true);
    $brandId = (int) ($brandPayload['data']['brand']['id'] ?? 0);
    $h->assertTrue($brandId > 0, 'brand id is returned');
    $h->assertSame(200, $controllerFor(1, 'PATCH', '/admin/api/business/catalog/brands/' . $brandId, [], ['name' => 'API Brand Updated'])->updateBrand($brandId)->status(), 'catalog admin can update brand');
    $h->assertSame(200, $controllerFor(1, 'GET', '/admin/api/business/catalog/brands')->brands()->status(), 'catalog admin can list brands');

    $category = $categories->create(1, ['name' => 'API Category'], 1);
    $size = json_decode($controllerFor(1, 'POST', '/admin/api/business/catalog/options', [], ['code' => 'size_api', 'name' => 'Size', 'type' => 'select'])->storeOption()->body(), true)['data']['option'];
    $color = json_decode($controllerFor(1, 'POST', '/admin/api/business/catalog/options', [], ['code' => 'color_api', 'name' => 'Color', 'type' => 'color'])->storeOption()->body(), true)['data']['option'];
    $model = json_decode($controllerFor(1, 'POST', '/admin/api/business/catalog/options', [], ['code' => 'model_api', 'name' => 'Model', 'type' => 'select'])->storeOption()->body(), true)['data']['option'];
    $sizeM = json_decode($controllerFor(1, 'POST', '/admin/api/business/catalog/options/' . $size['id'] . '/values', [], ['code' => 'm', 'label' => 'M', 'value' => 'm'])->storeOptionValue((int) $size['id'])->body(), true)['data']['option_value'];
    $colorBlue = json_decode($controllerFor(1, 'POST', '/admin/api/business/catalog/options/' . $color['id'] . '/values', [], ['code' => 'blue', 'label' => 'Blue', 'value' => 'blue', 'color_hex' => '#0066CC'])->storeOptionValue((int) $color['id'])->body(), true)['data']['option_value'];
    $modelClassic = json_decode($controllerFor(1, 'POST', '/admin/api/business/catalog/options/' . $model['id'] . '/values', [], ['code' => 'classic', 'label' => 'Classic', 'value' => 'classic'])->storeOptionValue((int) $model['id'])->body(), true)['data']['option_value'];
    $h->assertTrue((int) $sizeM['id'] > 0 && (int) $colorBlue['id'] > 0 && (int) $modelClassic['id'] > 0, 'generic option values are created');

    $productResponse = $controllerFor(1, 'POST', '/admin/api/business/catalog/products', [], [
        'name' => 'API Product',
        'slug' => 'api-product',
        'brand_id' => $brandId,
        'category_id' => $category['id'],
        'type' => 'physical',
        'status' => 'draft',
        'channels' => ['public', 'ecommerce'],
        'base_purchase_price' => 40,
        'base_sale_price' => 100,
        'currency' => 'CHF',
        'option_ids' => [$size['id'], $color['id'], $model['id']],
    ])->storeProduct();
    $h->assertSame(201, $productResponse->status(), 'catalog admin can create product with base prices');
    $productPayload = json_decode($productResponse->body(), true);
    $productId = (int) ($productPayload['data']['product']['data']['id'] ?? 0);
    $h->assertTrue(str_contains($productResponse->body(), 'purchase'), 'base purchase price path is present for privileged user');
    $h->assertSame(200, $controllerFor(1, 'PATCH', '/admin/api/business/catalog/products/' . $productId, [], ['name' => 'API Product Updated'])->updateProduct($productId)->status(), 'catalog admin can update product');

    $h->assertSame(200, $controllerFor(1, 'PUT', '/admin/api/business/catalog/products/' . $productId . '/base-prices', [], ['base_purchase_price' => 42, 'base_sale_price' => 120, 'currency' => 'CHF'])->setProductBasePrices($productId)->status(), 'catalog admin can update base prices');

    $variantResponse = $controllerFor(1, 'POST', '/admin/api/business/catalog/products/' . $productId . '/variants', [], [
        'sku' => 'API-PRODUCT-M-BLUE-CLASSIC',
        'name' => 'API Product M Blue Classic',
        'status' => 'active',
        'stock_quantity' => 8,
        'option_values' => ['size_api' => 'm', 'color_api' => 'blue', 'model_api' => 'classic'],
        'purchase_adjustment_type' => 'amount_delta',
        'purchase_adjustment_value' => 3,
        'sale_adjustment_type' => 'percent_delta',
        'sale_adjustment_value' => 10,
    ])->storeVariant($productId);
    $h->assertSame(201, $variantResponse->status(), 'catalog admin can create variant with generic size color model');
    $variantPayload = json_decode($variantResponse->body(), true);
    $variantId = (int) ($variantPayload['data']['variant']['id'] ?? 0);
    $h->assertSame(3, count($variantPayload['data']['variant']['option_values'] ?? []), 'variant exposes its option values');

    $stockResponse = $controllerFor(1, 'GET', '/admin/api/business/catalog/variants/' . $variantId . '/stock')->variantStock($variantId);
    $h->assertSame(200, $stockResponse->status(), 'catalog admin can read variant stock');
    $stockPayload = json_decode($stockResponse->body(), true);
    $h->assertSame(8, (int) ($stockPayload['data']['stock']['stock_quantity'] ?? 0), 'variant stock endpoint returns current quantity');

    $movementResponse = $controllerFor(1, 'POST', '/admin/api/business/catalog/variants/' . $variantId . '/stock-movements', [], [
        'movement_type' => 'purchase',
        'quantity' => 4,
        'reason' => 'API restock',
        'reference_type' => 'manual',
        'reference_id' => 1001,
    ])->storeStockMovement($variantId);
    $h->assertSame(201, $movementResponse->status(), 'catalog admin can create stock movement');
    $movementPayload = json_decode($movementResponse->body(), true);
    $h->assertSame(12, (int) ($movementPayload['data']['stock']['stock_quantity'] ?? 0), 'stock movement updates current quantity');
    $h->assertSame('manual', $movementPayload['data']['movement']['reference_type'] ?? null, 'stock movement keeps reference type');

    $movementsResponse = $controllerFor(1, 'GET', '/admin/api/business/catalog/stock-movements', ['variant_id' => $variantId])->stockMovements();
    $h->assertSame(200, $movementsResponse->status(), 'catalog admin can list stock movements');
    $movementsPayload = json_decode($movementsResponse->body(), true);
    $h->assertSame(1, count($movementsPayload['data']['movements'] ?? []), 'stock movement history is filterable by variant');

    $adjustResponse = $controllerFor(1, 'PUT', '/admin/api/business/catalog/variants/' . $variantId . '/price-adjustments', [], [
        'purchase_adjustment_type' => 'amount_delta',
        'purchase_adjustment_value' => 4,
        'sale_adjustment_type' => 'amount_delta',
        'sale_adjustment_value' => 6,
    ])->setVariantPriceAdjustments($variantId);
    $h->assertSame(200, $adjustResponse->status(), 'catalog admin can update variant purchase and sale adjustments');

    $percentDiscount = $controllerFor(1, 'POST', '/admin/api/business/catalog/discounts', [], [
        'name' => 'API Percent',
        'type' => 'percent',
        'value' => 10,
        'scope' => 'product',
        'scope_id' => $productId,
        'channel' => 'ecommerce',
    ])->storeDiscount();
    $h->assertSame(201, $percentDiscount->status(), 'catalog admin can create percent discount');

    $amountDiscount = $controllerFor(1, 'POST', '/admin/api/business/catalog/discounts', [], [
        'name' => 'API Amount',
        'type' => 'amount',
        'value' => 5,
        'currency' => 'CHF',
        'scope' => 'variant',
        'scope_id' => $variantId,
        'channel' => 'all',
    ])->storeDiscount();
    $h->assertSame(201, $amountDiscount->status(), 'catalog admin can create amount discount');

    $computed = $controllerFor(1, 'GET', '/admin/api/business/catalog/variants/' . $variantId . '/computed-prices')->computedPrices($variantId);
    $h->assertSame(200, $computed->status(), 'catalog admin can read computed prices');
    $computedPayload = json_decode($computed->body(), true);
    $h->assertTrue(isset($computedPayload['data']['computed_prices']['regular_purchase_price']), 'privileged computed prices include purchase price');

    $exportFull = $controllerFor(1, 'GET', '/admin/api/business/catalog/export.csv')->exportCatalogCsv();
    $h->assertSame(200, $exportFull->status(), 'catalog admin can export CSV');
    $h->assertTrue(str_contains($exportFull->body(), 'base_purchase_price'), 'catalog export includes purchase column');
    $h->assertTrue(str_contains($exportFull->body(), ';42;'), 'privileged catalog export includes purchase value');

    $exportLimited = $controllerFor(2, 'GET', '/admin/api/business/catalog/export.csv')->exportCatalogCsv();
    $h->assertSame(200, $exportLimited->status(), 'catalog user with read can export CSV');
    $h->assertTrue(!str_contains($exportLimited->body(), ';42;'), 'limited catalog export masks purchase value');

    $limited = $controllerFor(2, 'GET', '/admin/api/business/catalog/products/' . $productId)->showProduct($productId);
    $h->assertSame(200, $limited->status(), 'catalog user with read can show product');
    $limitedBody = $limited->body();
    $h->assertTrue(!str_contains($limitedBody, 'purchase'), 'catalog user without purchase permission does not see purchase prices');
    $h->assertTrue(!str_contains($limitedBody, 'margin'), 'catalog user without purchase permission does not see margins');

    $h->expectException(
        fn() => $controllerFor(3, 'GET', '/admin/api/business/catalog/products')->products(),
        ApiException::class,
        'user without catalog read is forbidden'
    );

    $h->assertSame(200, $controllerFor(1, 'DELETE', '/admin/api/business/catalog/products/' . $productId)->deleteProduct($productId)->status(), 'catalog admin can archive product');
    $h->assertSame(200, $controllerFor(1, 'DELETE', '/admin/api/business/catalog/brands/' . $brandId)->deleteBrand($brandId)->status(), 'catalog admin can archive brand');
} finally {
    $_SESSION = [];
    $businessDb = null;
    gc_collect_cycles();
    test_remove_tree($businessDir);
    test_remove_tree($iamDir);
    test_remove_tree($coreDir);
}

exit($h->finish('UNIT business Catalog admin API'));
