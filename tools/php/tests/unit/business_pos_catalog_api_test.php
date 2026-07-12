<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Application\PublicApi\PosCatalogApiHandler;
use App\Core\Database;
use App\Core\Request;
use App\Modules\Business\Catalog\CatalogPricingService;
use App\Modules\Business\Repositories\BusinessCatalogPricingRepository;
use App\Modules\Business\Repositories\CatalogBrandRepository;
use App\Modules\Business\Repositories\CatalogCategoryRepository;
use App\Modules\Business\Repositories\CatalogDiscountRepository;
use App\Modules\Business\Repositories\CatalogOptionRepository;
use App\Modules\Business\Repositories\CatalogProductRepository;
use App\Modules\Business\Repositories\CatalogVariantRepository;
use App\Modules\Business\Repositories\PosCatalogRepository;
use App\Modules\Business\Services\BusinessProductBundleService;
use App\Repository\SiteRepository;
use App\Security\PublicApiTokenGuard;

$h = new TestHarness();
[$businessDir, $businessPath, $businessDb] = test_temp_cms_db(__DIR__ . '/../../../../database/migrations/business/0001_init.sql');
$catalogSchema = file_get_contents(__DIR__ . '/../../../../database/migrations/business/0003_catalog_schema.sql');
if ($catalogSchema === false) {
    throw new RuntimeException('Unable to read business catalog schema.');
}
$businessDb->pdo()->exec($catalogSchema);
$businessDb->pdo()->exec((string) file_get_contents(__DIR__ . '/../../../../database/migrations/business/0007_pricing_offers_bundles.sql'));
$businessDb->pdo()->exec((string) file_get_contents(__DIR__ . '/../../../../database/migrations/business/0008_pim_quality_import_channels.sql'));

$coreDir = sys_get_temp_dir() . '/amcms-business-pos-catalog-core-' . bin2hex(random_bytes(6));
$iamDir = sys_get_temp_dir() . '/amcms-business-pos-catalog-iam-' . bin2hex(random_bytes(6));
mkdir($coreDir, 0775, true);
mkdir($iamDir, 0775, true);

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

    $iam = new Database($iamDir . '/iam.sqlite', 1000);
    $iamSchema = file_get_contents(__DIR__ . '/../../../../database/iam.sql');
    if ($iamSchema === false) {
        throw new RuntimeException('Unable to read IAM schema.');
    }
    $iam->pdo()->exec($iamSchema);

    $appConfig = require base_path('backend/config/app.php');
    $appConfig['public_api_auth']['enabled'] = true;
    $appConfig['public_api_auth']['protect_all_v1_by_default'] = true;
    $config = ['app' => $appConfig];

    $badToken = 'amcms_bad_pos_scope_' . bin2hex(random_bytes(4));
    $goodToken = 'amcms_good_pos_scope_' . bin2hex(random_bytes(4));
    $iam->run(
        'INSERT INTO api_tokens(name, token_hash, site_id, scopes, is_active, created_at, updated_at) VALUES(?, ?, ?, ?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
        ['Bad scope', hash('sha256', $badToken), 1, 'catalog:read']
    );
    $iam->run(
        'INSERT INTO api_tokens(name, token_hash, site_id, scopes, is_active, created_at, updated_at) VALUES(?, ?, ?, ?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
        ['POS scope', hash('sha256', $goodToken), 1, 'pos.catalog.read']
    );

    $guardFor = static function (?string $token) use ($iam, $config): PublicApiTokenGuard {
        $server = ['HTTP_HOST' => 'example.test', 'CMS_SITE_ID' => 1];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        return new PublicApiTokenGuard(new Request('GET', '/api/v1/pos/catalog/bootstrap', [], [], $server, [], []), $iam, $config);
    };

    $h->assertSame(401, $guardFor(null)->enforce()?->status(), 'POS catalog requires a bearer token');
    $h->assertSame(403, $guardFor($badToken)->enforce()?->status(), 'POS catalog refuses tokens without pos.catalog.read');
    $h->assertSame(null, $guardFor($goodToken)->enforce(), 'POS catalog accepts pos.catalog.read');

    $brands = new CatalogBrandRepository($businessDb);
    $categories = new CatalogCategoryRepository($businessDb);
    $products = new CatalogProductRepository($businessDb);
    $variants = new CatalogVariantRepository($businessDb);
    $options = new CatalogOptionRepository($businessDb);
    $discounts = new CatalogDiscountRepository($businessDb);
    $bundles = new BusinessProductBundleService($businessDb);

    $brand = $brands->create(1, ['name' => 'POS Brand', 'slug' => 'pos-brand', 'is_public' => true], 1);
    $category = $categories->create(1, ['name' => 'POS Category', 'slug' => 'pos-category', 'is_public' => true], 1);
    $taxClass = $businessDb->one("SELECT id FROM business_tax_classes WHERE site_id = 1 AND code = 'standard' LIMIT 1");
    $color = $options->create(1, ['code' => 'color', 'name' => 'Couleur'], 1);
    $options->addValue(1, (int) $color['id'], ['code' => 'red', 'label' => 'Rouge', 'value' => 'red'], 1);

    $posProduct = $products->create(1, [
        'brand_id' => $brand['id'],
        'category_id' => $category['id'],
        'tax_class_id' => $taxClass['id'] ?? null,
        'name' => 'POS Product ' . bin2hex(random_bytes(3)),
        'slug' => 'pos-product-' . bin2hex(random_bytes(3)),
        'status' => 'draft',
        'channels' => ['pos'],
        'base_purchase_price' => 40,
        'base_sale_price' => 100,
        'currency' => 'CHF',
        'track_stock' => true,
    ], 1);
    $products->replaceOptionLinks(1, (int) $posProduct['id'], [(int) $color['id']]);
    $variant = $variants->create(1, (int) $posProduct['id'], [
        'sku' => 'POS-' . strtoupper(bin2hex(random_bytes(3))),
        'barcode' => '7612345678901',
        'name' => 'POS Red',
        'status' => 'active',
        'stock_quantity' => 8,
        'stock_reserved' => 2,
        'option_values' => ['color' => 'red'],
        'sale_adjustment_type' => 'amount_delta',
        'sale_adjustment_value' => 20,
    ], 1);
    $products->update(1, (int) $posProduct['id'], ['status' => 'active'], 1);
    $discounts->create(1, [
        'name' => 'Remise POS',
        'type' => 'percent',
        'value' => 10,
        'scope' => 'product',
        'scope_id' => $posProduct['id'],
        'channel' => 'pos',
        'status' => 'active',
    ], 1);

    $bundleComponent = $products->create(1, [
        'brand_id' => $brand['id'],
        'category_id' => $category['id'],
        'tax_class_id' => $taxClass['id'] ?? null,
        'name' => 'POS Bundle Component',
        'slug' => 'pos-bundle-component-' . bin2hex(random_bytes(3)),
        'status' => 'draft',
        'channels' => ['pos'],
        'base_sale_price' => 25,
        'track_stock' => true,
        'allow_backorder' => true,
        'backorder_delivery_days' => 4,
    ], 1);
    $bundleComponentVariant = $variants->create(1, (int) $bundleComponent['id'], [
        'sku' => 'POS-BUNDLE-COMP-' . strtoupper(bin2hex(random_bytes(2))),
        'name' => 'Composant POS différé',
        'status' => 'active',
        'stock_quantity' => 0,
    ], 1);
    $products->update(1, (int) $bundleComponent['id'], ['status' => 'active'], 1);
    $bundleProduct = $products->create(1, [
        'brand_id' => $brand['id'],
        'category_id' => $category['id'],
        'tax_class_id' => $taxClass['id'] ?? null,
        'name' => 'POS Backorder Pack',
        'slug' => 'pos-backorder-pack-' . bin2hex(random_bytes(3)),
        'type' => 'bundle',
        'status' => 'draft',
        'channels' => ['pos'],
        'base_sale_price' => 75,
        'track_stock' => false,
    ], 1);
    $bundleVariant = $variants->create(1, (int) $bundleProduct['id'], [
        'sku' => 'POS-BACKORDER-PACK-' . strtoupper(bin2hex(random_bytes(2))),
        'name' => 'Pack POS différé',
        'status' => 'active',
        'track_stock' => false,
    ], 1);
    $bundles->replaceProductBundle(1, (int) $bundleProduct['id'], [
        'bundle_variant_id' => $bundleVariant['id'],
        'pricing_mode' => 'fixed',
        'stock_mode' => 'components',
        'is_active' => true,
        'components' => [[
            'component_product_id' => $bundleComponent['id'],
            'component_variant_id' => $bundleComponentVariant['id'],
            'quantity' => 1,
            'is_required' => true,
        ]],
    ], 1);
    $products->update(1, (int) $bundleProduct['id'], ['status' => 'active'], 1);

    $contactComponent = $products->create(1, [
        'brand_id' => $brand['id'],
        'category_id' => $category['id'],
        'tax_class_id' => $taxClass['id'] ?? null,
        'name' => 'POS Contact Component',
        'slug' => 'pos-contact-component-' . bin2hex(random_bytes(3)),
        'status' => 'draft',
        'channels' => ['pos'],
        'base_sale_price' => 20,
        'track_stock' => true,
        'allow_backorder' => false,
    ], 1);
    $contactComponentVariant = $variants->create(1, (int) $contactComponent['id'], [
        'sku' => 'POS-CONTACT-COMP-' . strtoupper(bin2hex(random_bytes(2))),
        'name' => 'Composant POS contact',
        'status' => 'active',
        'stock_quantity' => 0,
    ], 1);
    $products->update(1, (int) $contactComponent['id'], ['status' => 'active'], 1);
    $contactBundleProduct = $products->create(1, [
        'brand_id' => $brand['id'],
        'category_id' => $category['id'],
        'tax_class_id' => $taxClass['id'] ?? null,
        'name' => 'POS Contact Pack',
        'slug' => 'pos-contact-pack-' . bin2hex(random_bytes(3)),
        'type' => 'bundle',
        'status' => 'draft',
        'channels' => ['pos'],
        'base_sale_price' => 65,
        'track_stock' => false,
    ], 1);
    $contactBundleVariant = $variants->create(1, (int) $contactBundleProduct['id'], [
        'sku' => 'POS-CONTACT-PACK-' . strtoupper(bin2hex(random_bytes(2))),
        'name' => 'Pack POS contact',
        'status' => 'active',
        'track_stock' => false,
    ], 1);
    $bundles->replaceProductBundle(1, (int) $contactBundleProduct['id'], [
        'bundle_variant_id' => $contactBundleVariant['id'],
        'pricing_mode' => 'fixed',
        'stock_mode' => 'components',
        'is_active' => true,
        'components' => [[
            'component_product_id' => $contactComponent['id'],
            'component_variant_id' => $contactComponentVariant['id'],
            'quantity' => 1,
            'is_required' => true,
        ]],
    ], 1);
    $products->update(1, (int) $contactBundleProduct['id'], ['status' => 'active'], 1);

    $ecommerceOnly = $products->create(1, [
        'name' => 'E-commerce Only POS Excluded',
        'slug' => 'ecommerce-only-pos-excluded',
        'status' => 'active',
        'channels' => ['public', 'ecommerce'],
        'base_sale_price' => 70,
    ], 1);
    $variants->create(1, (int) $ecommerceOnly['id'], [
        'sku' => 'ECOM-' . strtoupper(bin2hex(random_bytes(3))),
        'barcode' => '7612345678999',
        'name' => 'E-commerce Only',
        'status' => 'active',
    ], 1);

    $sites = new SiteRepository($core, ['cms' => ['default_site_key' => 'main'], 'app' => ['default_locale' => 'fr']]);
    $handlerFor = static function (array $query = [], string $path = '/api/v1/pos/catalog/bootstrap') use ($sites, $businessDb, $bundles): PosCatalogApiHandler {
        $request = new Request('GET', $path, $query, [], ['HTTP_HOST' => 'example.test'], [], []);
        return new PosCatalogApiHandler($request, $sites, new PosCatalogRepository($businessDb), new CatalogPricingService(new BusinessCatalogPricingRepository($businessDb)), $bundles);
    };

    $bootstrap = $handlerFor()->bootstrap();
    $h->assertSame(200, $bootstrap->status(), 'POS bootstrap endpoint responds');
    $body = $bootstrap->body();
    $h->assertTrue(str_contains($body, (string) $posProduct['slug']), 'active POS product is present');
    $h->assertTrue(!str_contains($body, 'ecommerce-only-pos-excluded'), 'active e-commerce product without POS channel is absent');
    $h->assertTrue(!str_contains($body, 'purchase'), 'POS payload does not expose purchase price');
    $h->assertTrue(!str_contains($body, 'margin'), 'POS payload does not expose margin');
    $h->assertTrue(!str_contains($body, 'stock_quantity'), 'POS payload does not expose exact stock quantity');

    $decoded = json_decode($body, true);
    $foundVariant = null;
    foreach (($decoded['data']['products'] ?? []) as $product) {
        if (($product['product_id'] ?? 0) !== (int) $posProduct['id']) {
            continue;
        }
        $foundVariant = $product['variants'][0] ?? null;
        break;
    }
    $h->assertTrue(is_array($foundVariant), 'POS product includes its active variants in bootstrap');
    $pricing = is_array($foundVariant) ? ($foundVariant['pricing'] ?? []) : [];
    $h->assertSame('120.00', $pricing['regular_sale_price'] ?? null, 'POS regular sale price includes variant adjustment');
    $h->assertSame('108.00', $pricing['final_sale_price'] ?? null, 'POS final sale price includes active POS discount');
    $h->assertSame('Remise POS', $pricing['discount']['label'] ?? null, 'POS discount label is exposed');
    $backorderBundleVariant = null;
    $contactBundleVariantPayload = null;
    foreach (($decoded['data']['products'] ?? []) as $product) {
        if (($product['product_id'] ?? 0) === (int) $bundleProduct['id']) {
            $backorderBundleVariant = $product['variants'][0] ?? null;
        }
        if (($product['product_id'] ?? 0) === (int) $contactBundleProduct['id']) {
            $contactBundleVariantPayload = $product['variants'][0] ?? null;
        }
    }
    $h->assertSame('backorder', $backorderBundleVariant['availability']['status'] ?? null, 'POS bundle inherits backorder availability from components');
    $h->assertSame(4, $backorderBundleVariant['availability']['delivery_lead_time_days'] ?? null, 'POS bundle exposes component backorder delay');
    $h->assertSame('contact_us', $contactBundleVariantPayload['availability']['status'] ?? null, 'POS bundle switches to contact when a required component is not deliverable');
    $h->assertSame(false, $contactBundleVariantPayload['availability']['available'] ?? null, 'POS contact bundle is not orderable');

    $barcode = $handlerFor(['barcode' => '7612345678901'], '/api/v1/pos/catalog/variants')->variants();
    $h->assertSame(200, $barcode->status(), 'POS variants endpoint responds');
    $barcodePayload = json_decode($barcode->body(), true);
    $h->assertSame(1, count($barcodePayload['data']['items'] ?? []), 'POS barcode search returns one variant');
    $h->assertSame((int) $variant['id'], $barcodePayload['data']['items'][0]['variant_id'] ?? null, 'POS barcode search returns the expected variant');

    $h->assertSame(200, $handlerFor([], '/api/v1/pos/catalog/products')->products()->status(), 'POS products endpoint responds');
    $h->assertSame(200, $handlerFor([], '/api/v1/pos/catalog/brands')->brands()->status(), 'POS brands endpoint responds');
    $h->assertSame(200, $handlerFor([], '/api/v1/pos/catalog/categories')->categories()->status(), 'POS categories endpoint responds');
} finally {
    $businessDb = null;
    $core = null;
    $iam = null;
    gc_collect_cycles();
    test_remove_tree($businessDir);
    test_remove_tree($coreDir);
    test_remove_tree($iamDir);
}

exit($h->finish('UNIT business POS catalog API'));
