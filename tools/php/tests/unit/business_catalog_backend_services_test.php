<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Business\Catalog\CatalogPricingService;
use App\Modules\Business\Repositories\CatalogBrandRepository;
use App\Modules\Business\Repositories\CatalogCategoryRepository;
use App\Modules\Business\Repositories\CatalogDiscountRepository;
use App\Modules\Business\Repositories\CatalogPricingRepository;
use App\Modules\Business\Repositories\CatalogProductRepository;
use App\Modules\Business\Repositories\CatalogStockRepository;
use App\Modules\Business\Repositories\CatalogVariantRepository;
use App\Modules\Business\Services\CatalogDiscountService;
use App\Modules\Business\Services\CatalogProductService;
use App\Modules\Business\Services\CatalogStockService;
use App\Modules\Business\Services\CatalogVariantService;
use App\Modules\Business\Services\CatalogVisibilityService;

$h = new TestHarness();
[$dir, $dbPath, $db] = test_temp_cms_db(__DIR__ . '/../../../../database/migrations/business/0003_catalog_schema.sql');

try {
    $brands = new CatalogBrandRepository($db);
    $categories = new CatalogCategoryRepository($db);
    $products = new CatalogProductRepository($db);
    $variants = new CatalogVariantRepository($db);
    $discounts = new CatalogDiscountRepository($db);
    $stockRepository = new CatalogStockRepository($db);

    $productService = new CatalogProductService($products);
    $variantService = new CatalogVariantService($variants);
    $discountService = new CatalogDiscountService($discounts);
    $stockService = new CatalogStockService($stockRepository);
    $pricing = new CatalogPricingService(new CatalogPricingRepository($db));
    $visibility = new CatalogVisibilityService();

    $brand = $brands->create(1, ['name' => 'Services Demo'], 1);
    $category = $categories->create(1, ['name' => 'Catalogue backend'], 1);

    $h->expectException(
        fn() => $productService->create(1, [
            'name' => 'Produit actif impossible',
            'slug' => 'produit-actif-impossible',
            'status' => 'active',
            'channels' => ['public', 'ecommerce'],
            'base_sale_price' => 30,
        ], 1),
        InvalidArgumentException::class,
        'active product without active variant is rejected'
    );

    $product = $productService->create(1, [
        'brand_id' => $brand['id'],
        'category_id' => $category['id'],
        'name' => 'Backend Hoodie',
        'slug' => 'backend-hoodie',
        'type' => 'physical',
        'status' => 'draft',
        'channels' => ['public', 'ecommerce', 'pos'],
        'base_purchase_price' => 40.00,
        'base_sale_price' => 100.00,
        'currency' => 'CHF',
        'stock_enabled' => true,
    ], 1);
    $h->assertSame('Backend Hoodie', $product['name'], 'catalog product is created');

    $db->run("INSERT INTO business_product_options(site_id, code, name, type) VALUES(1, 'model', 'Model', 'select'), (1, 'size', 'Size', 'select')");
    $db->run("INSERT INTO business_product_option_values(option_id, code, label, value) SELECT id, 'classic', 'Classic', 'classic' FROM business_product_options WHERE code = 'model'");
    $db->run("INSERT INTO business_product_option_values(option_id, code, label, value) SELECT id, 'm', 'M', 'm' FROM business_product_options WHERE code = 'size'");
    $db->run("INSERT INTO business_product_option_links(product_id, option_id, is_required, sort_order) SELECT ?, id, 1, 10 FROM business_product_options WHERE code IN ('model', 'size')", [(int) $product['id']]);

    $variant = $variantService->create(1, (int) $product['id'], [
        'sku' => 'BACKEND-HOODIE-CLASSIC-M',
        'name' => 'Classic M',
        'status' => 'active',
        'stock_quantity' => 10,
        'track_stock' => true,
        'option_values' => ['model' => 'classic', 'size' => 'm'],
        'purchase_adjustment_type' => 'amount_delta',
        'purchase_adjustment_value' => 5,
        'sale_adjustment_type' => 'percent_delta',
        'sale_adjustment_value' => 20,
    ], 1);
    $variantId = (int) $variant['id'];
    $h->assertSame('BACKEND-HOODIE-CLASSIC-M', $variant['sku'], 'catalog variant is created');

    $h->expectException(
        fn() => $variantService->create(1, (int) $product['id'], ['sku' => 'BACKEND-HOODIE-CLASSIC-M', 'status' => 'draft'], 1),
        InvalidArgumentException::class,
        'duplicate SKU is rejected'
    );
    $h->expectException(
        fn() => $variantService->create(1, (int) $product['id'], [
            'sku' => 'BACKEND-HOODIE-BAD-OPTION',
            'status' => 'draft',
            'option_values' => ['color' => 'blue'],
        ], 1),
        InvalidArgumentException::class,
        'variant cannot reference an option not linked to the product'
    );

    $activated = $productService->update(1, (int) $product['id'], ['status' => 'active'], 1);
    $h->assertSame('active', $activated['status'] ?? null, 'product can be activated after active variant exists');

    $h->assertSame('45.00', $pricing->calculateRegularPrice($variantId, 'purchase')->formatted(), 'purchase adjustment is calculated by service layer');
    $h->assertSame('120.00', $pricing->calculateRegularPrice($variantId, 'sale')->formatted(), 'sale adjustment is calculated by service layer');

    $discount = $discountService->create(1, [
        'name' => 'Huge sale',
        'type' => 'amount',
        'value' => 150,
        'scope' => 'variant',
        'scope_id' => $variantId,
        'channel' => 'ecommerce',
        'priority' => 1,
    ], 1);
    $h->assertSame('amount', $discount['discount_type'], 'discount is created');
    $h->assertSame('0.00', $pricing->calculateFinalSalePrice($variantId, 'ecommerce')->formatted(), 'amount discount never produces a negative sale price');

    $stock = $stockService->move($variantId, 'initial', 2, 'Initial stock count', 1);
    $h->assertSame(12, (int) $stock['stock_quantity'], 'initial stock movement increases denormalized stock');
    $stock = $stockService->move($variantId, 'purchase', 5, 'Restock', 1);
    $h->assertSame(17, (int) $stock['stock_quantity'], 'stock purchase movement increases denormalized stock');
    $stock = $stockService->move($variantId, 'reservation', 3, 'Cart hold', 1);
    $h->assertSame(3, (int) $stock['stock_reserved'], 'reservation movement increases reserved stock');
    $h->expectException(
        fn() => $stockService->move($variantId, 'sale', 20, 'Oversell', 1),
        InvalidArgumentException::class,
        'stock cannot become negative when backorder is disabled'
    );
    $stock = $stockService->move($variantId, 'release', 3, 'Cart released', 1);
    $h->assertSame(0, (int) $stock['stock_reserved'], 'release movement decreases reserved stock');
    $stock = $stockService->move($variantId, 'sale', 4, 'Sale', 1);
    $h->assertSame(13, (int) $stock['stock_quantity'], 'sale movement decreases denormalized stock');
    $adjusted = $stockService->createMovement(1, $variantId, 'adjustment', -1, 'Inventory correction', 'inventory_count', 77, 1);
    $h->assertSame(12, (int) $adjusted['variant']['stock_quantity'], 'adjustment movement corrects denormalized stock');
    $h->assertSame('inventory_count', $adjusted['movement']['reference_type'] ?? null, 'stock movement stores reference type');
    $history = $stockService->movements(1, ['variant_id' => $variantId], 20, 0);
    $h->assertSame(6, $history['total'], 'stock movement history keeps all movements');

    $serviceProduct = $productService->create(1, [
        'name' => 'Service sans stock',
        'slug' => 'service-sans-stock',
        'type' => 'service',
        'status' => 'draft',
        'channels' => ['pos'],
        'base_purchase_price' => 0,
        'base_sale_price' => 80,
        'track_stock' => false,
    ], 1);
    $serviceVariant = $variantService->create(1, (int) $serviceProduct['id'], [
        'sku' => 'SERVICE-NO-STOCK',
        'name' => 'Service no stock',
        'status' => 'active',
        'track_stock' => false,
        'stock_quantity' => 0,
    ], 1);
    $serviceStock = $stockService->move((int) $serviceVariant['id'], 'purchase', 5, 'Ignored for service', 1);
    $h->assertSame(0, (int) $serviceStock['stock_quantity'], 'product without stock tracking ignores stock movements on quantity');
    $h->assertSame(1, $stockService->movements(1, ['variant_id' => (int) $serviceVariant['id']], 20, 0)['total'], 'ignored stock movement is still historized');

    $summary = $pricing->pricingSummary($variantId, 'ecommerce');
    $public = $visibility->publicPayload($summary);
    $h->assertTrue(!array_key_exists('regular_purchase_price', $public), 'visibility layer hides purchase price');
    $h->assertTrue(!array_key_exists('gross_margin_amount', $public), 'visibility layer hides margin');
    $h->assertSame('0.00', $public['final_sale_price'], 'visibility layer keeps public final sale price');
    $h->assertTrue($visibility->isPubliclyVisible($activated ?? [], $stockRepository->variant($variantId)), 'active ecommerce product and variant are publicly visible');
} finally {
    $db = null;
    gc_collect_cycles();
    test_remove_tree($dir);
}

exit($h->finish('UNIT business catalog backend services'));
