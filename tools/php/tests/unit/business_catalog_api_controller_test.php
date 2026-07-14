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
use App\Modules\Business\Services\CatalogPdfService;
use App\Modules\Business\Services\CatalogProductService;
use App\Modules\Business\Services\CatalogStockService;
use App\Modules\Business\Services\CatalogVariantService;
use App\Modules\Business\Services\BusinessProductCompletenessService;
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
$businessDb->pdo()->exec((string) file_get_contents(__DIR__ . '/../../../../database/migrations/business/0007_pricing_offers_bundles.sql'));
$businessDb->pdo()->exec((string) file_get_contents(__DIR__ . '/../../../../database/migrations/business/0008_pim_quality_import_channels.sql'));
$variantSalesNoteMigration = file_get_contents(__DIR__ . '/../../../../database/migrations/business/0005_variant_sales_note.sql');
if ($variantSalesNoteMigration === false) {
    throw new RuntimeException('Unable to read business variant sales note migration.');
}
$businessDb->pdo()->exec($variantSalesNoteMigration);
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
    $pdf = new CatalogPdfService($products, $variants);
    $completeness = new BusinessProductCompletenessService($businessDb);
    $sites = new SiteRepository($core, ['cms' => ['default_site_key' => 'main'], 'app' => ['default_locale' => 'fr']]);
    $standardTaxClass = $businessDb->one("SELECT id FROM business_tax_classes WHERE site_id = 1 AND code = 'standard' LIMIT 1");
    $standardTaxClassId = (int) ($standardTaxClass['id'] ?? 0);
    $h->assertTrue($standardTaxClassId > 0, 'standard tax class is available');

    $controllerFor = static function (int $userId, string $method, string $path, array $query = [], array $payload = []) use ($iam, $sites, $brands, $categories, $products, $variants, $options, $discounts, $productService, $variantService, $discountService, $stockService, $csv, $pdf, $pricing, $completeness): BusinessCatalogApiController {
        $token = 'business-catalog-api-test-token-' . $userId;
        $_SESSION['admin_user'] = ['id' => $userId, 'email' => 'catalog-' . $userId . '@example.test', 'session_secret' => $token];
        $request = new Request($method, $path, $query, $payload === [] ? [] : ['data' => $payload], ['HTTP_HOST' => 'example.test'], [], []);
        $auth = new AuthRepository($iam);
        return new BusinessCatalogApiController($request, $sites, $auth, new Authorization($auth), $brands, $categories, $products, $variants, $options, $discounts, $productService, $variantService, $discountService, $stockService, $csv, $pdf, $pricing, $completeness);
    };

    $businessDb->run("INSERT INTO business_companies(id, site_id, name, normalized_name, status, email) VALUES(10, 1, 'API Supplier', 'api supplier', 'supplier', 'supplier@example.test')");

    $brandResponse = $controllerFor(1, 'POST', '/admin/api/business/catalog/brands', [], ['name' => 'API Brand', 'company_id' => 10])->storeBrand();
    $h->assertSame(201, $brandResponse->status(), 'catalog admin can create brand');
    $brandPayload = json_decode($brandResponse->body(), true);
    $brandId = (int) ($brandPayload['data']['brand']['id'] ?? 0);
    $h->assertTrue($brandId > 0, 'brand id is returned');
    $h->assertSame(10, $brandPayload['data']['brand']['company_id'] ?? null, 'brand can be linked to a CRM company');
    $h->assertSame('API Supplier', $brandPayload['data']['brand']['company_name'] ?? null, 'brand response exposes linked CRM company name');
    $h->assertSame(200, $controllerFor(1, 'PATCH', '/admin/api/business/catalog/brands/' . $brandId, [], ['name' => 'API Brand Updated'])->updateBrand($brandId)->status(), 'catalog admin can update brand');
    $invalidBrandResponse = $controllerFor(1, 'POST', '/admin/api/business/catalog/brands', [], ['name' => 'Invalid Company Brand', 'company_id' => 9999])->storeBrand();
    $h->assertSame(422, $invalidBrandResponse->status(), 'brand rejects an unknown CRM company link');
    $h->assertSame(200, $controllerFor(1, 'GET', '/admin/api/business/catalog/brands')->brands()->status(), 'catalog admin can list brands');

    $category = $categories->create(1, ['name' => 'API Category'], 1);
    $temporaryCategory = json_decode($controllerFor(1, 'POST', '/admin/api/business/catalog/categories', [], ['name' => 'API Temporary Category'])->storeCategory()->body(), true)['data']['category'];
    $h->assertSame(200, $controllerFor(1, 'DELETE', '/admin/api/business/catalog/categories/' . $temporaryCategory['id'])->deleteCategory((int) $temporaryCategory['id'])->status(), 'catalog admin can archive category');
    $size = json_decode($controllerFor(1, 'POST', '/admin/api/business/catalog/options', [], ['code' => 'size_api', 'name' => 'Size', 'type' => 'select'])->storeOption()->body(), true)['data']['option'];
    $color = json_decode($controllerFor(1, 'POST', '/admin/api/business/catalog/options', [], ['code' => 'color_api', 'name' => 'Color', 'type' => 'color'])->storeOption()->body(), true)['data']['option'];
    $model = json_decode($controllerFor(1, 'POST', '/admin/api/business/catalog/options', [], ['code' => 'model_api', 'name' => 'Model', 'type' => 'select'])->storeOption()->body(), true)['data']['option'];
    $sizeM = json_decode($controllerFor(1, 'POST', '/admin/api/business/catalog/options/' . $size['id'] . '/values', [], ['code' => 'm', 'label' => 'M', 'value' => 'm'])->storeOptionValue((int) $size['id'])->body(), true)['data']['option_value'];
    $sizeL = json_decode($controllerFor(1, 'POST', '/admin/api/business/catalog/options/' . $size['id'] . '/values', [], ['code' => 'l', 'label' => 'L', 'value' => 'l'])->storeOptionValue((int) $size['id'])->body(), true)['data']['option_value'];
    $colorBlue = json_decode($controllerFor(1, 'POST', '/admin/api/business/catalog/options/' . $color['id'] . '/values', [], ['code' => 'blue', 'label' => 'Blue', 'value' => 'blue', 'color_hex' => '#0066CC'])->storeOptionValue((int) $color['id'])->body(), true)['data']['option_value'];
    $modelClassic = json_decode($controllerFor(1, 'POST', '/admin/api/business/catalog/options/' . $model['id'] . '/values', [], ['code' => 'classic', 'label' => 'Classic', 'value' => 'classic'])->storeOptionValue((int) $model['id'])->body(), true)['data']['option_value'];
    $h->assertTrue((int) $sizeM['id'] > 0 && (int) $sizeL['id'] > 0 && (int) $colorBlue['id'] > 0 && (int) $modelClassic['id'] > 0, 'generic option values are created');

    $productResponse = $controllerFor(1, 'POST', '/admin/api/business/catalog/products', [], [
        'name' => 'API Product',
        'sku_base' => 'api product',
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
    $h->assertSame('APIPRODUCT', $productPayload['data']['product']['data']['sku_base'] ?? null, 'product create normalizes SKU base');
    $updateProductResponse = $controllerFor(1, 'PATCH', '/admin/api/business/catalog/products/' . $productId, [], [
        'name' => 'API Product Updated',
        'sku_base' => 'api product upd',
        'slug' => 'api-product-updated',
        'brand_id' => $brandId,
        'category_id' => $category['id'],
        'type' => 'physical',
        'status' => 'draft',
        'is_public' => false,
        'is_ecommerce_enabled' => false,
        'is_pos_enabled' => true,
        'unit' => 'piece',
        'track_stock' => true,
        'allow_backorder' => true,
        'option_ids' => [$size['id'], $color['id'], $model['id']],
    ])->updateProduct($productId);
    $h->assertSame(200, $updateProductResponse->status(), 'catalog admin can update product fields');
    $updatedProductPayload = json_decode($updateProductResponse->body(), true);
    $h->assertSame('API Product Updated', $updatedProductPayload['data']['product']['data']['name'] ?? null, 'product update persists name');
    $h->assertSame('APIPRODUCTUPD', $updatedProductPayload['data']['product']['data']['sku_base'] ?? null, 'product update normalizes SKU base');
    $h->assertSame('api-product-updated', $updatedProductPayload['data']['product']['data']['slug'] ?? null, 'product update persists slug');
    $h->assertSame(true, (bool) ($updatedProductPayload['data']['product']['data']['is_pos_enabled'] ?? false), 'product update persists POS channel');
    $h->assertSame(false, (bool) ($updatedProductPayload['data']['product']['data']['is_ecommerce_enabled'] ?? true), 'product update persists ecommerce channel');
    $h->assertSame('piece', $updatedProductPayload['data']['product']['data']['unit'] ?? null, 'product update persists unit');
    $h->assertSame(true, (bool) ($updatedProductPayload['data']['product']['data']['track_stock'] ?? false), 'product update persists stock tracking');
    $h->assertSame(true, (bool) ($updatedProductPayload['data']['product']['data']['allow_backorder'] ?? false), 'product update persists backorder setting');
    $h->assertSame(3, count($updatedProductPayload['data']['product']['options'] ?? []), 'product update can persist all linked options');
    $autoScores = (int) ($businessDb->one('SELECT COUNT(*) AS count FROM business_product_completeness_scores WHERE product_id = ?', [$productId])['count'] ?? 0);
    $h->assertTrue($autoScores > 0, 'product save recalculates completeness scores');

    $h->assertSame(200, $controllerFor(1, 'PUT', '/admin/api/business/catalog/products/' . $productId . '/base-prices', [], ['base_purchase_price' => 42, 'base_sale_price' => 120, 'currency' => 'CHF'])->setProductBasePrices($productId)->status(), 'catalog admin can update base prices');

    $variantResponse = $controllerFor(1, 'POST', '/admin/api/business/catalog/products/' . $productId . '/variants', [], [
        'sku' => 'API-PRODUCT-M-BLUE-CLASSIC',
        'name' => 'API Product M Blue Classic',
        'status' => 'active',
        'sales_note' => 'Commentaire POS initial',
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
    $h->assertSame('Commentaire POS initial', $variantPayload['data']['variant']['sales_note'] ?? null, 'variant creation persists POS sales note');
    $h->assertSame(3, count($variantPayload['data']['variant']['option_values'] ?? []), 'variant exposes its option values');
    $variantScores = (int) ($businessDb->one('SELECT COUNT(*) AS count FROM business_product_completeness_scores WHERE variant_id = ?', [$variantId])['count'] ?? 0);
    $h->assertTrue($variantScores > 0, 'variant save recalculates completeness scores');

    $variantUpdateResponse = $controllerFor(1, 'PATCH', '/admin/api/business/catalog/variants/' . $variantId, [], [
        'sku' => 'API-PRODUCT-L-BLUE-CLASSIC',
        'name' => 'API Product L Blue Classic',
        'sales_note' => 'Commentaire POS modifié',
        'option_values' => ['size_api' => 'l', 'color_api' => 'blue', 'model_api' => 'classic'],
    ])->updateVariant($variantId);
    $h->assertSame(200, $variantUpdateResponse->status(), 'catalog admin can update variant sales note');
    $variantUpdatePayload = json_decode($variantUpdateResponse->body(), true);
    $h->assertSame('API-PRODUCT-L-BLUE-CLASSIC', $variantUpdatePayload['data']['variant']['sku'] ?? null, 'variant update changes SKU');
    $h->assertSame('API Product L Blue Classic', $variantUpdatePayload['data']['variant']['name'] ?? null, 'variant update changes name');
    $h->assertSame('Commentaire POS modifié', $variantUpdatePayload['data']['variant']['sales_note'] ?? null, 'variant update returns POS sales note');
    $variantSizeOption = array_values(array_filter($variantUpdatePayload['data']['variant']['option_values'] ?? [], static fn(array $option): bool => ($option['option_code'] ?? '') === 'size_api'))[0] ?? [];
    $h->assertSame('l', $variantSizeOption['value_code'] ?? null, 'variant update changes option values');

    $businessDb->run(
        'INSERT INTO business_product_assets(site_id, product_id, media_id, role, title, alt_text, is_public, channel_scope)
         VALUES(1, ?, 1001, "main", "API product image", "API product image", 1, "all")',
        [$productId]
    );
    $businessDb->run(
        'INSERT OR REPLACE INTO business_product_completeness_scores(product_id, channel, score, is_sellable, missing_json)
         VALUES(?, "all", 90, 1, "[]")',
        [$productId]
    );
    $productsResponse = $controllerFor(1, 'GET', '/admin/api/business/catalog/products')->products();
    $h->assertSame(200, $productsResponse->status(), 'catalog admin can list product UX indicators');
    $productsPayload = json_decode($productsResponse->body(), true);
    $listedProduct = $productsPayload['data']['products'][0] ?? [];
    $h->assertSame(1, (int) ($listedProduct['variant_count'] ?? 0), 'product list exposes variant count');
    $h->assertSame(1, (int) ($listedProduct['active_variant_count'] ?? 0), 'product list exposes active variant count');
    $h->assertSame(8, (int) ($listedProduct['stock_quantity_total'] ?? 0), 'product list exposes total stock quantity');
    $h->assertSame(0, (int) ($listedProduct['stock_reserved_total'] ?? 0), 'product list exposes total reserved stock');
    $h->assertSame(1, (int) ($listedProduct['stock_tracked_variant_count'] ?? 0), 'product list exposes tracked stock variant count');
    $h->assertSame(120.0, (float) ($listedProduct['sale_price_min'] ?? 0), 'product list exposes sale price floor');
    $h->assertSame(1, (int) ($listedProduct['image_count'] ?? 0), 'product list exposes image availability');
    $h->assertSame(90, (int) ($listedProduct['completeness_score'] ?? 0), 'product list exposes completeness score');
    $h->assertSame(true, (bool) ($listedProduct['is_sellable_summary'] ?? false), 'product list exposes sellable summary');
    $shownProductPayload = json_decode($controllerFor(1, 'GET', '/admin/api/business/catalog/products/' . $productId)->showProduct($productId)->body(), true);
    $shownProductData = $shownProductPayload['data']['product']['data'] ?? [];
    $h->assertSame(1, (int) ($shownProductData['image_count'] ?? 0), 'product detail exposes image availability');
    $h->assertSame(90, (int) ($shownProductData['completeness_score'] ?? 0), 'product detail exposes completeness score');

    $missingProduct = $products->create(1, [
        'name' => 'API Filter Missing',
        'sku_base' => 'filter missing',
        'slug' => 'api-filter-missing',
        'type' => 'physical',
        'status' => 'draft',
        'channels' => [],
    ], 1);
    $missingProductId = (int) ($missingProduct['id'] ?? 0);
    $businessDb->run(
        'INSERT OR REPLACE INTO business_product_completeness_scores(product_id, channel, score, is_sellable, missing_json)
         VALUES(?, "all", 25, 0, ?)',
        [$missingProductId, json_encode(['image', 'sale_price', 'tax_class'], JSON_THROW_ON_ERROR)]
    );
    $readyProduct = $products->create(1, [
        'name' => 'API POS Ready',
        'sku_base' => 'pos ready',
        'slug' => 'api-pos-ready',
        'type' => 'physical',
        'status' => 'active',
        'channels' => ['public', 'ecommerce', 'pos'],
        'base_purchase_price' => 12,
        'base_sale_price' => 30,
        'currency' => 'CHF',
        'tax_class_id' => $standardTaxClassId,
    ], 1);
    $readyProductId = (int) ($readyProduct['id'] ?? 0);
    $businessDb->run(
        'INSERT INTO business_product_variants(product_id, status, sku, name, track_stock, stock_quantity, allow_backorder, created_by_iam_user_id, updated_by_iam_user_id)
         VALUES(?, "active", "API-POS-READY-ONE", "API POS Ready One", 1, 3, 0, 1, 1)',
        [$readyProductId]
    );
    $businessDb->run(
        'INSERT INTO business_product_assets(site_id, product_id, media_id, role, title, alt_text, is_public, channel_scope)
         VALUES(1, ?, 1002, "main", "API POS ready image", "API POS ready image", 1, "all")',
        [$readyProductId]
    );
    foreach (['all', 'pos', 'ecommerce'] as $channel) {
        $businessDb->run(
            'INSERT OR REPLACE INTO business_product_completeness_scores(product_id, channel, score, is_sellable, missing_json)
             VALUES(?, ?, 100, 1, "[]")',
            [$readyProductId, $channel]
        );
    }
    $productIdsFor = static function (array $query) use ($controllerFor): array {
        $payload = json_decode($controllerFor(1, 'GET', '/admin/api/business/catalog/products', $query)->products()->body(), true);
        return array_map(static fn(array $product): int => (int) ($product['id'] ?? 0), $payload['data']['products'] ?? []);
    };
    $h->assertTrue(in_array($productId, $productIdsFor(['image' => 'with']), true), 'image=with includes products with catalogue media');
    $h->assertTrue(in_array($missingProductId, $productIdsFor(['image' => 'without']), true), 'image=without includes products without catalogue media');
    $h->assertTrue(in_array($productId, $productIdsFor(['price' => 'with']), true), 'price=with includes products with sale price');
    $h->assertTrue(in_array($missingProductId, $productIdsFor(['price' => 'without']), true), 'price=without includes products without sale price');
    $h->assertTrue(in_array($missingProductId, $productIdsFor(['purchase_price' => 'missing']), true), 'purchase_price=missing includes products without purchase price');
    $h->assertTrue(in_array($missingProductId, $productIdsFor(['tax' => 'missing']), true), 'tax=missing includes products without tax class');
    $h->assertTrue(in_array($readyProductId, $productIdsFor(['completeness' => 'complete']), true), 'completeness=complete includes complete products');
    $h->assertTrue(in_array($missingProductId, $productIdsFor(['completeness' => 'incomplete']), true), 'completeness=incomplete includes incomplete products');
    $h->assertTrue(in_array($readyProductId, $productIdsFor(['sellable' => 'pos']), true), 'sellable=pos includes POS-ready products');
    $h->assertTrue(in_array($readyProductId, $productIdsFor(['view' => 'ready_pos']), true), 'ready_pos system view maps to POS-ready products');
    $h->assertTrue(in_array($missingProductId, $productIdsFor(['view' => 'to_complete']), true), 'to_complete system view maps to incomplete products');
    $h->assertTrue(in_array($missingProductId, $productIdsFor(['view' => 'without_image']), true), 'without_image system view maps to products without media');
    $h->assertTrue(in_array($productId, $productIdsFor(['q' => 'API Category']), true), 'product search includes category names');

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
    $h->assertSame(422, $movementResponse->status(), 'Business API rejects stock movement creation in favor of Sale');
    $movementPayload = json_decode($movementResponse->body(), true);
    $h->assertSame('VALIDATION_FAILED', $movementPayload['error']['code'] ?? null, 'Business stock write rejection keeps the validation contract');

    $movementsResponse = $controllerFor(1, 'GET', '/admin/api/business/catalog/stock-movements', ['variant_id' => $variantId])->stockMovements();
    $h->assertSame(200, $movementsResponse->status(), 'catalog admin can list stock movements');
    $movementsPayload = json_decode($movementsResponse->body(), true);
    $h->assertSame(0, count($movementsPayload['data']['movements'] ?? []), 'deprecated Business stock history remains empty');

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
    $archivedProduct = $businessDb->one('SELECT status, archived_at FROM business_products WHERE id = ?', [$productId]);
    $h->assertSame('archived', $archivedProduct['status'] ?? null, 'product archive updates status');
    $h->assertTrue(!empty($archivedProduct['archived_at'] ?? null), 'product archive keeps the product with archived_at');
    $h->assertSame(422, $controllerFor(1, 'DELETE', '/admin/api/business/catalog/brands/' . $brandId)->deleteBrand($brandId)->status(), 'catalog admin cannot delete a brand linked to a product');
    $h->assertSame(200, $controllerFor(1, 'POST', '/admin/api/business/catalog/products/' . $productId . '/restore')->restoreProduct($productId)->status(), 'catalog admin can restore archived product');
    $restoredProduct = $businessDb->one('SELECT status, archived_at FROM business_products WHERE id = ?', [$productId]);
    $h->assertSame('draft', $restoredProduct['status'] ?? null, 'product restore returns to draft');
    $h->assertSame(null, $restoredProduct['archived_at'] ?? null, 'product restore clears archived_at');
    $h->assertSame(200, $controllerFor(1, 'DELETE', '/admin/api/business/catalog/products/' . $productId)->deleteProduct($productId)->status(), 'catalog admin can archive product before permanent delete');
    $h->assertSame(200, $controllerFor(1, 'DELETE', '/admin/api/business/catalog/products/' . $productId . '/permanent')->purgeProduct($productId)->status(), 'catalog admin can permanently delete archived product');
    $h->assertSame(null, $businessDb->one('SELECT id FROM business_products WHERE id = ?', [$productId]), 'permanent delete removes archived product');
    $h->assertSame(200, $controllerFor(1, 'DELETE', '/admin/api/business/catalog/brands/' . $brandId)->deleteBrand($brandId)->status(), 'catalog admin can delete an unused brand');
    $h->assertSame(null, $businessDb->one('SELECT id FROM business_product_brands WHERE id = ?', [$brandId]), 'unused brand is physically deleted');
} finally {
    $_SESSION = [];
    $businessDb = null;
    gc_collect_cycles();
    test_remove_tree($businessDir);
    test_remove_tree($iamDir);
    test_remove_tree($coreDir);
}

exit($h->finish('UNIT business Catalog admin API'));
