<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Business\Catalog\CatalogPricingService;
use App\Modules\Business\Catalog\BusinessCatalogValidator;
use App\Modules\Business\Repositories\BusinessCatalogPricingRepository;

$h = new TestHarness();
[$dir, $dbPath, $db] = test_temp_cms_db(__DIR__ . '/../../../../database/migrations/business/0001_init.sql');
$catalogSchema = file_get_contents(__DIR__ . '/../../../../database/migrations/business/0003_catalog_schema.sql');
if ($catalogSchema === false) {
    throw new RuntimeException('Unable to read business catalog schema.');
}
$db->pdo()->exec($catalogSchema);
$pricingMigration = file_get_contents(__DIR__ . '/../../../../database/migrations/business/0007_pricing_offers_bundles.sql');
if ($pricingMigration === false) {
    throw new RuntimeException('Unable to read pricing lists schema.');
}
$db->pdo()->exec($pricingMigration);

try {
    $validator = new BusinessCatalogValidator();
    $repository = new BusinessCatalogPricingRepository($db);
    $pricing = new CatalogPricingService($repository, $validator);

    $makeVariant = static function (
        BusinessCatalogPricingRepository $repository,
        BusinessCatalogValidator $validator,
        string $slug,
        float $basePurchase,
        float $baseSale,
        array $variantPayload = [],
        array $productPayload = []
    ): array {
        $rawProduct = array_merge([
            'name' => 'Produit ' . $slug,
            'slug' => $slug,
            'type' => 'physical',
            'status' => 'active',
            'channels' => ['public', 'ecommerce'],
            'currency' => 'CHF',
            'base_purchase_price' => $basePurchase,
            'base_sale_price' => $baseSale,
        ], $productPayload);
        $product = $repository->createProduct(1, $validator->product($rawProduct) + [
            'brand_id' => $rawProduct['brand_id'] ?? null,
            'category_id' => $rawProduct['category_id'] ?? null,
        ]);
        $variant = $repository->createVariant((int) $product['id'], $validator->variant(array_merge([
            'sku' => strtoupper($slug),
            'status' => 'active',
        ], $variantPayload)));
        return [$product, $variant];
    };

    [$product, $variant] = $makeVariant($repository, $validator, 'plain', 80.0, 120.0);
    $variantId = (int) $variant['id'];
    $h->assertSame('80.00', $pricing->calculateRegularPrice($variantId, 'purchase')->formatted(), 'variant without adjustment inherits base purchase price');
    $h->assertSame('120.00', $pricing->calculateRegularPrice($variantId, 'sale')->formatted(), 'variant without adjustment inherits base sale price');

    [, $purchaseAmountVariant] = $makeVariant($repository, $validator, 'purchase-amount', 80.0, 120.0, [
        'purchase_adjustment_type' => 'amount_delta',
        'purchase_adjustment_value' => 12.5,
    ]);
    $h->assertSame('92.50', $pricing->calculateRegularPrice((int) $purchaseAmountVariant['id'], 'purchase')->formatted(), 'purchase amount delta is applied');

    [, $saleAmountVariant] = $makeVariant($repository, $validator, 'sale-amount', 80.0, 120.0, [
        'sale_adjustment_type' => 'amount_delta',
        'sale_adjustment_value' => 30.0,
    ]);
    $h->assertSame('150.00', $pricing->calculateRegularPrice((int) $saleAmountVariant['id'], 'sale')->formatted(), 'sale amount delta is applied');

    [, $purchasePercentVariant] = $makeVariant($repository, $validator, 'purchase-percent', 80.0, 120.0, [
        'purchase_adjustment_type' => 'percent_delta',
        'purchase_adjustment_value' => 25.0,
    ]);
    $h->assertSame('100.00', $pricing->calculateRegularPrice((int) $purchasePercentVariant['id'], 'purchase')->formatted(), 'purchase percent delta is applied');

    [, $salePercentVariant] = $makeVariant($repository, $validator, 'sale-percent', 80.0, 120.0, [
        'sale_adjustment_type' => 'percent_delta',
        'sale_adjustment_value' => 10.0,
    ]);
    $h->assertSame('132.00', $pricing->calculateRegularPrice((int) $salePercentVariant['id'], 'sale')->formatted(), 'sale percent delta is applied');

    [, $differentVariant] = $makeVariant($repository, $validator, 'different-adjustments', 80.0, 120.0, [
        'purchase_adjustment_type' => 'amount_delta',
        'purchase_adjustment_value' => 10.0,
        'sale_adjustment_type' => 'fixed_override',
        'sale_adjustment_value' => 190.0,
    ]);
    $differentVariantId = (int) $differentVariant['id'];
    $h->assertSame('90.00', $pricing->calculateRegularPrice($differentVariantId, 'purchase')->formatted(), 'purchase and sale adjustments can differ on purchase');
    $h->assertSame('190.00', $pricing->calculateRegularPrice($differentVariantId, 'sale')->formatted(), 'purchase and sale adjustments can differ on sale');

    $repository->createOffer(1, $validator->offer([
        'name' => 'Remise pourcentage',
        'type' => 'percent',
        'value' => 10.0,
        'scope' => 'variant',
        'channel' => 'ecommerce',
    ]) + ['variant_id' => $differentVariantId]);
    $h->assertSame('171.00', $pricing->calculateFinalSalePrice($differentVariantId, 'ecommerce')->formatted(), 'percent discount applies to sale price only');
    $h->assertSame('90.00', $pricing->calculateRegularPrice($differentVariantId, 'purchase')->formatted(), 'percent discount never changes purchase price');

    [, $amountDiscountVariant] = $makeVariant($repository, $validator, 'amount-discount', 50.0, 140.0);
    $amountDiscountVariantId = (int) $amountDiscountVariant['id'];
    $repository->createOffer(1, $validator->offer([
        'name' => 'Remise montant',
        'type' => 'amount',
        'value' => 25.0,
        'scope' => 'variant',
        'channel' => 'pos',
    ]) + ['variant_id' => $amountDiscountVariantId]);
    $h->assertSame('115.00', $pricing->calculateFinalSalePrice($amountDiscountVariantId, 'pos')->formatted(), 'amount discount applies to sale price only');
    $h->assertSame('50.00', $pricing->calculateRegularPrice($amountDiscountVariantId, 'purchase')->formatted(), 'amount discount never changes purchase price');

    $db->run("INSERT INTO business_product_brands(site_id, name, slug, status) VALUES(1, 'Pricing Brand', 'pricing-brand', 'active')");
    $brandId = $db->lastInsertId();
    $db->run("INSERT INTO business_product_categories(site_id, name, slug) VALUES(1, 'Pricing Category', 'pricing-category')");
    $categoryId = $db->lastInsertId();

    [, $brandVariant] = $makeVariant($repository, $validator, 'brand-discount', 70.0, 200.0, [], ['brand_id' => $brandId]);
    $repository->createOffer(1, $validator->offer([
        'name' => 'Remise marque',
        'type' => 'percent',
        'value' => 10.0,
        'scope' => 'brand',
        'channel' => 'ecommerce',
    ]) + ['brand_id' => $brandId]);
    $h->assertSame('180.00', $pricing->calculateFinalSalePrice((int) $brandVariant['id'], 'ecommerce')->formatted(), 'brand discount applies to sale price');

    [, $categoryVariant] = $makeVariant($repository, $validator, 'category-discount', 70.0, 200.0, [], ['brand_id' => $brandId, 'category_id' => $categoryId]);
    $repository->createOffer(1, $validator->offer([
        'name' => 'Remise marque tres forte',
        'type' => 'amount',
        'value' => 80.0,
        'scope' => 'brand',
        'channel' => 'ecommerce',
    ]) + ['brand_id' => $brandId, 'priority' => 1]);
    $repository->createOffer(1, $validator->offer([
        'name' => 'Remise categorie prioritaire par specificite',
        'type' => 'percent',
        'value' => 20.0,
        'scope' => 'category',
        'channel' => 'ecommerce',
    ]) + ['category_id' => $categoryId, 'priority' => 100]);
    $categorySummary = $pricing->pricingSummary((int) $categoryVariant['id'], 'ecommerce');
    $h->assertSame('160.00', $categorySummary['final_sale_price'], 'category discount wins over brand discount by specificity');
    $h->assertSame('category', $categorySummary['active_discount']['scope'] ?? null, 'active discount exposes selected scope');

    [, $datedVariant] = $makeVariant($repository, $validator, 'dated-discounts', 30.0, 100.0);
    $datedVariantId = (int) $datedVariant['id'];
    $repository->createOffer(1, $validator->offer([
        'name' => 'Remise expiree',
        'type' => 'percent',
        'value' => 50.0,
        'scope' => 'variant',
        'channel' => 'ecommerce',
    ]) + ['variant_id' => $datedVariantId, 'starts_at' => '2024-01-01 00:00:00', 'ends_at' => '2024-01-31 23:59:59']);
    $repository->createOffer(1, $validator->offer([
        'name' => 'Remise future',
        'type' => 'percent',
        'value' => 50.0,
        'scope' => 'variant',
        'channel' => 'ecommerce',
    ]) + ['variant_id' => $datedVariantId, 'starts_at' => '2099-01-01 00:00:00']);
    $repository->createOffer(1, $validator->offer([
        'name' => 'Remise POS uniquement',
        'type' => 'percent',
        'value' => 50.0,
        'scope' => 'variant',
        'channel' => 'pos',
    ]) + ['variant_id' => $datedVariantId]);
    $h->assertSame('100.00', $pricing->calculateFinalSalePrice($datedVariantId, 'ecommerce')->formatted(), 'expired future and POS-only discounts are ignored in ecommerce');
    $h->assertSame('50.00', $pricing->calculateFinalSalePrice($datedVariantId, 'pos')->formatted(), 'POS-only discount applies on POS channel');

    [, $floorVariant] = $makeVariant($repository, $validator, 'discount-floor', 20.0, 40.0);
    $floorVariantId = (int) $floorVariant['id'];
    $repository->createOffer(1, $validator->offer([
        'name' => 'Remise montant superieure au prix',
        'type' => 'amount',
        'value' => 90.0,
        'scope' => 'variant',
        'channel' => 'ecommerce',
    ]) + ['variant_id' => $floorVariantId]);
    $h->assertSame('0.00', $pricing->calculateFinalSalePrice($floorVariantId, 'ecommerce')->formatted(), 'amount discount never produces a negative final price');
    $h->assertSame('20.00', $pricing->calculateRegularPrice($floorVariantId, 'purchase')->formatted(), 'discount never changes purchase price');

    $margin = $pricing->calculateMargin($amountDiscountVariantId, 'pos');
    $h->assertSame('65.00', $margin->amount()->formatted(), 'gross margin amount is final sale minus regular purchase');
    $h->assertSame(56.52, $margin->percent(), 'gross margin percent uses final sale price');

    $public = $pricing->publicPricingPayload($pricing->pricingSummary($amountDiscountVariantId, 'pos'));
    $h->assertTrue(!array_key_exists('base_purchase_price', $public), 'public pricing payload hides base purchase price');
    $h->assertTrue(!array_key_exists('purchase_adjustment_type', $public), 'public pricing payload hides purchase adjustment type');
    $h->assertTrue(!array_key_exists('regular_purchase_price', $public), 'public pricing payload hides regular purchase price');
    $h->assertSame('115.00', $public['final_sale_price'], 'public pricing payload keeps final sale price');

    [, $exactPlain] = $makeVariant($repository, $validator, 'exact-plain', 20.0, 30.0);
    $h->assertSame('20.00', $pricing->calculateRegularPrice((int) $exactPlain['id'], 'purchase')->formatted(), 'exact scenario plain purchase is base 20');
    $h->assertSame('30.00', $pricing->calculateRegularPrice((int) $exactPlain['id'], 'sale')->formatted(), 'exact scenario plain sale is base 30');

    [, $exactAmount] = $makeVariant($repository, $validator, 'exact-amount', 20.0, 30.0, [
        'purchase_adjustment_type' => 'amount_delta',
        'purchase_adjustment_value' => 3.0,
        'sale_adjustment_type' => 'amount_delta',
        'sale_adjustment_value' => 5.0,
    ]);
    $h->assertSame('23.00', $pricing->calculateRegularPrice((int) $exactAmount['id'], 'purchase')->formatted(), 'exact scenario amount purchase is 23');
    $h->assertSame('35.00', $pricing->calculateRegularPrice((int) $exactAmount['id'], 'sale')->formatted(), 'exact scenario amount sale is 35');

    [, $exactPercent] = $makeVariant($repository, $validator, 'exact-percent', 20.0, 30.0, [
        'purchase_adjustment_type' => 'percent_delta',
        'purchase_adjustment_value' => 10.0,
        'sale_adjustment_type' => 'percent_delta',
        'sale_adjustment_value' => 25.0,
    ]);
    $exactPercentId = (int) $exactPercent['id'];
    $h->assertSame('22.00', $pricing->calculateRegularPrice($exactPercentId, 'purchase')->formatted(), 'exact scenario percent purchase is 22');
    $h->assertSame('37.50', $pricing->calculateRegularPrice($exactPercentId, 'sale')->formatted(), 'exact scenario percent sale is 37.50');
    $repository->createOffer(1, $validator->offer([
        'name' => 'Exact moins dix',
        'type' => 'percent',
        'value' => 10.0,
        'scope' => 'variant',
        'channel' => 'ecommerce',
    ]) + ['variant_id' => $exactPercentId]);
    $exactSummary = $pricing->pricingSummary($exactPercentId, 'ecommerce');
    $h->assertSame('33.75', $exactSummary['final_sale_price'], 'exact scenario percent discount final sale is 33.75');
    $h->assertSame('22.00', $pricing->calculateRegularPrice($exactPercentId, 'purchase')->formatted(), 'exact scenario discount leaves purchase unchanged');
    $exactMargin = $pricing->calculateMargin($exactPercentId, 'ecommerce');
    $h->assertSame('11.75', $exactMargin->amount()->formatted(), 'exact scenario margin amount is final sale minus purchase');
    $h->assertSame(34.81, $exactMargin->percent(), 'exact scenario margin percent is rounded');
} finally {
    $db = null;
    gc_collect_cycles();
    test_remove_tree($dir);
}

exit($h->finish('UNIT business catalog pricing service'));
