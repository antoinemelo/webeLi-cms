<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Application\PublicApi\PublicCatalogApiHandler;
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
use App\Modules\Business\Repositories\PublicCatalogRepository;
use App\Modules\Business\Services\BusinessProductBundleService;
use App\Repository\SiteRepository;

$h = new TestHarness();
[$businessDir, $businessPath, $businessDb] = test_temp_cms_db(__DIR__ . '/../../../../database/migrations/business/0001_init.sql');
$catalogSchema = file_get_contents(__DIR__ . '/../../../../database/migrations/business/0003_catalog_schema.sql');
if ($catalogSchema === false) {
    throw new RuntimeException('Unable to read business catalog schema.');
}
$businessDb->pdo()->exec($catalogSchema);
$coreDir = sys_get_temp_dir() . '/amcms-business-public-catalog-core-' . bin2hex(random_bytes(6));
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

    $brands = new CatalogBrandRepository($businessDb);
    $categories = new CatalogCategoryRepository($businessDb);
    $products = new CatalogProductRepository($businessDb);
    $variants = new CatalogVariantRepository($businessDb);
    $options = new CatalogOptionRepository($businessDb);
    $discounts = new CatalogDiscountRepository($businessDb);
    $bundles = new BusinessProductBundleService($businessDb);

    $brand = $brands->create(1, ['name' => 'Public Brand', 'slug' => 'public-brand', 'is_public' => true], 1);
    $otherBrand = $brands->create(1, ['name' => 'Other Brand', 'slug' => 'other-brand', 'is_public' => true], 1);
    $privateBrand = $brands->create(1, ['name' => 'Private Brand', 'slug' => 'private-brand', 'is_public' => false], 1);
    $category = $categories->create(1, ['name' => 'Public Category', 'slug' => 'public-category', 'is_public' => true], 1);
    $otherCategory = $categories->create(1, ['name' => 'Other Category', 'slug' => 'other-category', 'is_public' => true], 1);

    $size = $options->create(1, ['code' => 'size', 'name' => 'Size'], 1);
    $options->addValue(1, (int) $size['id'], ['code' => 'm', 'label' => 'M', 'value' => 'm'], 1);

    $product = $products->create(1, [
        'brand_id' => $brand['id'],
        'category_id' => $category['id'],
        'name' => 'Public Product',
        'slug' => 'public-product',
        'status' => 'draft',
        'channels' => ['public', 'ecommerce'],
        'base_purchase_price' => 30,
        'base_sale_price' => 100,
        'currency' => 'CHF',
        'track_stock' => true,
    ], 1);
    $products->replaceOptionLinks(1, (int) $product['id'], [(int) $size['id']]);
    $variant = $variants->create(1, (int) $product['id'], [
        'sku' => 'PUBLIC-M',
        'name' => 'Public M',
        'status' => 'active',
        'stock_quantity' => 3,
        'option_values' => ['size' => 'm'],
        'sale_adjustment_type' => 'amount_delta',
        'sale_adjustment_value' => 20,
    ], 1);
    $products->update(1, (int) $product['id'], ['status' => 'active'], 1);
    $businessDb->run(
        'INSERT INTO business_product_assets(site_id, product_id, media_id, role, title, alt_text, caption, sort_order, is_public, channel_scope)
         VALUES(1, ?, 101, "main", "Image publique principale", "Produit public", "Image visible publiquement.", 10, 1, "ecommerce")',
        [(int) $product['id']]
    );
    $businessDb->run(
        'INSERT INTO business_product_assets(site_id, product_id, media_id, role, title, alt_text, caption, sort_order, is_public, channel_scope)
         VALUES(1, ?, 102, "gallery", "Image publique galerie", "Produit public galerie", "Image galerie visible.", 20, 1, "ecommerce")',
        [(int) $product['id']]
    );
    $businessDb->run(
        'INSERT INTO business_product_assets(site_id, product_id, media_id, role, title, alt_text, caption, sort_order, is_public, channel_scope)
         VALUES(1, ?, 103, "internal", "Image interne", "Ne doit pas sortir", "Asset interne.", 30, 1, "ecommerce")',
        [(int) $product['id']]
    );
    $businessDb->run("INSERT INTO business_attribute_groups(site_id, code, name, sort_order) VALUES(1, 'api_public', 'API publique', 10)");
    $businessDb->run("INSERT INTO business_attributes(site_id, group_id, code, name, data_type, is_public, is_filterable, is_searchable, sort_order) SELECT 1, id, 'matiere_publique', 'Matière publique', 'text', 1, 1, 1, 10 FROM business_attribute_groups WHERE code = 'api_public'");
    $businessDb->run("INSERT INTO business_attributes(site_id, group_id, code, name, data_type, is_public, is_filterable, is_searchable, sort_order) SELECT 1, id, 'note_interne', 'Note interne', 'text', 0, 0, 0, 20 FROM business_attribute_groups WHERE code = 'api_public'");
    $businessDb->run("INSERT INTO business_product_attribute_values(product_id, attribute_id, language, value_text) SELECT ?, id, 'fr', 'Coton public' FROM business_attributes WHERE code = 'matiere_publique'", [(int) $product['id']]);
    $businessDb->run("INSERT INTO business_product_attribute_values(product_id, attribute_id, language, value_text) SELECT ?, id, 'fr', 'Coût fournisseur interne' FROM business_attributes WHERE code = 'note_interne'", [(int) $product['id']]);
    $businessDb->run("INSERT INTO business_variant_attribute_values(variant_id, attribute_id, language, value_text) SELECT ?, id, 'fr', 'Bleu public' FROM business_attributes WHERE code = 'matiere_publique'", [(int) $variant['id']]);
    $discounts->create(1, [
        'name' => 'Offre spéciale',
        'type' => 'percent',
        'value' => 10,
        'scope' => 'product',
        'scope_id' => $product['id'],
        'channel' => 'ecommerce',
        'status' => 'active',
    ], 1);

    $bundleComponent = $products->create(1, [
        'brand_id' => $brand['id'],
        'category_id' => $category['id'],
        'name' => 'Public Bundle Component',
        'slug' => 'public-bundle-component',
        'status' => 'draft',
        'channels' => ['public', 'ecommerce'],
        'base_sale_price' => 20,
        'track_stock' => true,
        'allow_backorder' => true,
        'backorder_delivery_days' => 6,
    ], 1);
    $bundleComponentVariant = $variants->create(1, (int) $bundleComponent['id'], [
        'sku' => 'PUBLIC-BUNDLE-COMPONENT',
        'name' => 'Composant différé',
        'status' => 'active',
        'stock_quantity' => 0,
    ], 1);
    $products->update(1, (int) $bundleComponent['id'], ['status' => 'active'], 1);
    $bundleProduct = $products->create(1, [
        'brand_id' => $brand['id'],
        'category_id' => $category['id'],
        'name' => 'Public Backorder Pack',
        'slug' => 'public-backorder-pack',
        'type' => 'bundle',
        'status' => 'draft',
        'channels' => ['public', 'ecommerce'],
        'base_sale_price' => 60,
        'track_stock' => false,
    ], 1);
    $bundleVariant = $variants->create(1, (int) $bundleProduct['id'], [
        'sku' => 'PUBLIC-BACKORDER-PACK',
        'name' => 'Pack différé',
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
        'name' => 'Public Contact Component',
        'slug' => 'public-contact-component',
        'status' => 'draft',
        'channels' => ['public', 'ecommerce'],
        'base_sale_price' => 15,
        'track_stock' => true,
        'allow_backorder' => false,
    ], 1);
    $contactComponentVariant = $variants->create(1, (int) $contactComponent['id'], [
        'sku' => 'PUBLIC-CONTACT-COMPONENT',
        'name' => 'Composant contact',
        'status' => 'active',
        'stock_quantity' => 0,
    ], 1);
    $products->update(1, (int) $contactComponent['id'], ['status' => 'active'], 1);
    $contactBundleProduct = $products->create(1, [
        'brand_id' => $brand['id'],
        'category_id' => $category['id'],
        'name' => 'Public Contact Pack',
        'slug' => 'public-contact-pack',
        'type' => 'bundle',
        'status' => 'draft',
        'channels' => ['public', 'ecommerce'],
        'base_sale_price' => 45,
        'track_stock' => false,
    ], 1);
    $contactBundleVariant = $variants->create(1, (int) $contactBundleProduct['id'], [
        'sku' => 'PUBLIC-CONTACT-PACK',
        'name' => 'Pack contact',
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

    $hidden = $products->create(1, ['name' => 'Hidden Product', 'slug' => 'hidden-product', 'status' => 'active', 'channels' => ['ecommerce'], 'base_sale_price' => 50], 1);
    $internal = $products->create(1, ['name' => 'Internal Product', 'slug' => 'internal-product', 'status' => 'active', 'channels' => ['public'], 'base_sale_price' => 60], 1);
    $internalVariant = $variants->create(1, (int) $internal['id'], [
        'sku' => 'INTERNAL-M',
        'name' => 'Internal M',
        'status' => 'active',
        'stock_quantity' => 3,
    ], 1);
    $archived = $products->create(1, ['name' => 'Archived Product', 'slug' => 'archived-product', 'status' => 'active', 'channels' => ['public', 'ecommerce'], 'base_sale_price' => 70], 1);
    $products->archive(1, (int) $archived['id'], 1);
    $privateProduct = $products->create(1, ['brand_id' => $privateBrand['id'], 'name' => 'Private Brand Product', 'slug' => 'private-brand-product', 'status' => 'active', 'channels' => ['public', 'ecommerce'], 'base_sale_price' => 80], 1);

    $sites = new SiteRepository($core, ['cms' => ['default_site_key' => 'main'], 'app' => ['default_locale' => 'fr']]);
    $handlerFor = static function (array $query = [], string $path = '/api/v1/catalog/products') use ($sites, $businessDb, $bundles): PublicCatalogApiHandler {
        $request = new Request('GET', $path, $query, [], ['HTTP_HOST' => 'example.test'], [], []);
        return new PublicCatalogApiHandler($request, $sites, new PublicCatalogRepository($businessDb), new CatalogPricingService(new BusinessCatalogPricingRepository($businessDb)), $bundles);
    };

    $list = $handlerFor()->products();
    $h->assertSame(200, $list->status(), 'public catalog products endpoint responds');
    $body = $list->body();
    $h->assertTrue(str_contains($body, 'Public Product'), 'active public ecommerce product is present');
    $h->assertTrue(!str_contains($body, 'Hidden Product'), 'non public product is absent');
    $h->assertTrue(!str_contains($body, 'Internal Product'), 'non ecommerce product is absent');
    $h->assertTrue(!str_contains($body, 'Archived Product'), 'archived product is absent');
    $h->assertTrue(!str_contains($body, 'purchase'), 'public product payload does not expose purchase price');
    $h->assertTrue(!str_contains($body, 'margin'), 'public product payload does not expose margin');
    $h->assertTrue(!str_contains($body, 'stock_quantity'), 'public product payload does not expose exact stock quantity');

    $decoded = json_decode($body, true);
    $publicItem = null;
    foreach (($decoded['data']['items'] ?? []) as $item) {
        if (($item['slug'] ?? '') === 'public-product') {
            $publicItem = $item;
            break;
        }
    }
    $pricing = $publicItem['variants'][0]['pricing'] ?? [];
    $h->assertSame('120.00', $pricing['regular_sale_price'] ?? null, 'public regular sale price includes variant adjustment');
    $h->assertSame('108.00', $pricing['final_sale_price'] ?? null, 'public final sale price includes active discount');
    $h->assertSame('Offre spéciale', $pricing['discount']['label'] ?? null, 'public discount label is exposed');
    $h->assertSame(101, $publicItem['main_asset']['media_id'] ?? null, 'public catalog list exposes main asset');
    $h->assertSame(1, count($publicItem['gallery_assets'] ?? []), 'public catalog list exposes public gallery assets');
    $h->assertTrue(str_contains($body, 'Coton public'), 'public catalog list exposes public PIM attributes');
    $h->assertTrue(!str_contains($body, 'Coût fournisseur interne'), 'public catalog list hides non-public PIM attributes');
    $h->assertTrue(!str_contains($body, 'Image interne'), 'public catalog list hides internal assets');
    $h->assertSame(true, $publicItem['is_sellable_public'] ?? null, 'public catalog list exposes public sellability verdict');
    $bundleItem = null;
    $contactBundleItem = null;
    foreach (($decoded['data']['items'] ?? []) as $item) {
        if (($item['slug'] ?? '') === 'public-backorder-pack') {
            $bundleItem = $item;
        }
        if (($item['slug'] ?? '') === 'public-contact-pack') {
            $contactBundleItem = $item;
        }
    }
    $h->assertSame('backorder', $bundleItem['availability']['status'] ?? null, 'public bundle inherits backorder availability from components');
    $h->assertSame(6, $bundleItem['availability']['delivery_lead_time_days'] ?? null, 'public bundle exposes component backorder delay');
    $h->assertSame(true, $bundleItem['is_sellable_public'] ?? null, 'public backorder bundle remains sellable');
    $h->assertSame('contact_us', $contactBundleItem['availability']['status'] ?? null, 'public bundle switches to contact when a required component is not deliverable');
    $h->assertSame(false, $contactBundleItem['is_sellable_public'] ?? null, 'public contact bundle is not directly sellable');

    $show = $handlerFor([], '/api/v1/catalog/products/public-product')->product('public-product');
    $h->assertSame(200, $show->status(), 'public product show works');
    $h->assertTrue(str_contains($show->body(), 'public.catalog.products.show.v1'), 'public product show contract is returned');
    $showPayload = json_decode($show->body(), true)['data']['product'] ?? [];
    $h->assertSame(101, $showPayload['main_asset']['media_id'] ?? null, 'public product show exposes main asset');
    $h->assertSame(102, $showPayload['gallery_assets'][0]['media_id'] ?? null, 'public product show exposes gallery asset');
    $h->assertSame('matiere_publique', $showPayload['public_attributes'][0]['code'] ?? null, 'public product show exposes public attribute code');
    $h->assertTrue(!array_key_exists('usage_rights', $showPayload['main_asset'] ?? []), 'public product show does not expose asset usage rights');
    $h->assertTrue(!array_key_exists('source', $showPayload['main_asset'] ?? []), 'public product show does not expose asset source');

    $variantResponse = $handlerFor([], '/api/v1/catalog/variants/' . $variant['id'])->variant((int) $variant['id']);
    $h->assertSame(200, $variantResponse->status(), 'public active variant show works');
    $h->assertTrue(!str_contains($variantResponse->body(), 'purchase'), 'public variant payload does not expose purchase price');
    $variantPayload = json_decode($variantResponse->body(), true)['data']['variant'] ?? [];
    $h->assertSame('Bleu public', $variantPayload['public_attributes'][0]['value'] ?? null, 'public variant show exposes public variant attributes');
    $h->assertSame(true, $variantPayload['is_sellable_public'] ?? null, 'public variant show exposes public sellability verdict');

    $brandFiltered = $handlerFor(['brand' => 'public-brand'])->products();
    $h->assertTrue(str_contains($brandFiltered->body(), 'Public Product'), 'public products are filterable by brand');
    $otherBrandFiltered = $handlerFor(['brand' => 'other-brand'])->products();
    $h->assertTrue(!str_contains($otherBrandFiltered->body(), 'Public Product'), 'brand filter excludes other products');
    $categoryFiltered = $handlerFor(['category' => 'public-category'])->products();
    $h->assertTrue(str_contains($categoryFiltered->body(), 'Public Product'), 'public products are filterable by category');
    $otherCategoryFiltered = $handlerFor(['category' => 'other-category'])->products();
    $h->assertTrue(!str_contains($otherCategoryFiltered->body(), 'Public Product'), 'category filter excludes other products');

    $h->assertSame(200, $handlerFor([], '/api/v1/catalog/brands')->brands()->status(), 'public brands endpoint responds');
    $h->assertSame(200, $handlerFor([], '/api/v1/catalog/categories')->categories()->status(), 'public categories endpoint responds');
    $h->assertSame(404, $handlerFor([], '/api/v1/catalog/products/hidden-product')->product('hidden-product')->status(), 'non public product show is refused');
    $h->assertSame(404, $handlerFor([], '/api/v1/catalog/products/archived-product')->product('archived-product')->status(), 'archived product show is refused');
    $h->assertSame(404, $handlerFor([], '/api/v1/catalog/variants/' . $internalVariant['id'])->variant((int) $internalVariant['id'])->status(), 'variant attached to non ecommerce product is refused publicly');
    $h->assertSame(0, count(json_decode($handlerFor(['brand' => 'private-brand'])->products()->body(), true)['data']['items'] ?? []), 'private brands are not filterable in public catalog');

    unset($hidden, $otherBrand, $otherCategory, $privateProduct);
} finally {
    $businessDb = null;
    $core = null;
    gc_collect_cycles();
    test_remove_tree($businessDir);
    test_remove_tree($coreDir);
}

exit($h->finish('UNIT business Catalog public API'));
