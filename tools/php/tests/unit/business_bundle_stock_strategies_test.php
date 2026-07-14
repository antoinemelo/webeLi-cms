<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Business\Catalog\BusinessCatalogValidator;
use App\Modules\Business\Repositories\BusinessCatalogPricingRepository;
use App\Modules\Business\Services\BusinessProductBundleService;

$h = new TestHarness();
[$dir, $path, $db] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/business.sql');

try {
    $repository = new BusinessCatalogPricingRepository($db);
    $validator = new BusinessCatalogValidator();
    $bundles = new BusinessProductBundleService($db);

    $make = static function (string $slug, string $type = 'physical', int $stock = 0, bool $tracked = true) use ($repository, $validator, $db): array {
        $product = $repository->createProduct(1, $validator->product([
            'name' => ucwords(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'type' => $type,
            'status' => 'active',
            'channels' => ['public', 'ecommerce', 'pos', 'catalogue'],
            'stock_enabled' => $tracked,
            'allow_backorder' => false,
            'base_sale_price' => 100,
            'currency' => 'CHF',
        ]));
        $variant = $repository->createVariant((int) $product['id'], $validator->variant([
            'sku' => strtoupper(str_replace('-', '_', $slug)),
            'name' => $product['name'],
            'status' => 'active',
            'stock_quantity' => $stock,
        ]));
        $db->run(
            'INSERT OR REPLACE INTO business_inventory_availability_projections(sellable_id,site_id,tracked,on_hand_quantity,reserved_quantity,available_quantity,availability_status) VALUES(?,?,?,?,?,?,?)',
            [(int) $variant['id'], 1, (int) $tracked, $stock, 0, $stock, $tracked ? ($stock > 0 ? 'in_stock' : 'unavailable') : 'deliverable']
        );
        return [$product, $variant];
    };

    [$componentA, $variantA] = $make('component-a', 'physical', 10);
    [$componentB, $variantB] = $make('component-b', 'physical', 3);
    [$bundleProduct, $bundleVariant] = $make('derived-bundle', 'bundle', 0, false);
    $derived = $bundles->replaceProductBundle(1, (int) $bundleProduct['id'], [
        'bundle_variant_id' => (int) $bundleVariant['id'],
        'stock_strategy' => 'COMPONENT_DERIVED',
        'components' => [
            ['component_product_id' => (int) $componentA['id'], 'component_variant_id' => (int) $variantA['id'], 'quantity' => 2],
            ['component_product_id' => (int) $componentB['id'], 'component_variant_id' => (int) $variantB['id'], 'quantity' => 1],
        ],
    ], 1);
    $h->assertSame('COMPONENT_DERIVED', $derived['stock_strategy'], 'canonical component-derived strategy is persisted');
    $h->assertSame('components', $derived['stock_mode'], 'legacy mode remains a deterministic compatibility projection');
    $plan = $bundles->inventoryPlanForVariant(1, (int) $bundleVariant['id']);
    $h->assertSame(2, count($plan['leaves']), 'component-derived plan exposes both inventory leaves');
    $h->assertSame(3, $plan['availability']['available_quantity'], 'bundle availability is the minimum component ratio');
    $h->assertSame((int) $variantB['id'], $plan['availability']['limiting_factor']['sellable_id'], 'limiting component is explicit');

    [$nestedProduct, $nestedVariant] = $make('nested-bundle', 'bundle', 0, false);
    $bundles->replaceProductBundle(1, (int) $nestedProduct['id'], [
        'bundle_variant_id' => (int) $nestedVariant['id'],
        'stock_strategy' => 'COMPONENT_DERIVED',
        'components' => [['component_product_id' => (int) $bundleProduct['id'], 'component_variant_id' => (int) $bundleVariant['id'], 'quantity' => 2]],
    ], 1);
    $nestedPlan = $bundles->inventoryPlanForVariant(1, (int) $nestedVariant['id']);
    $ratios = array_column($nestedPlan['leaves'], 'quantity_per_bundle', 'sellable_id');
    $h->assertSame(4.0, $ratios[(int) $variantA['id']], 'nested component ratios are flattened and multiplied');
    $h->assertSame(2.0, $ratios[(int) $variantB['id']], 'nested second component ratio is multiplied');
    $h->assertSame(1, $nestedPlan['availability']['available_quantity'], 'nested availability uses flattened leaf stock');

    [$ownProduct, $ownVariant] = $make('own-stock-bundle', 'bundle', 5, true);
    $own = $bundles->replaceProductBundle(1, (int) $ownProduct['id'], [
        'bundle_variant_id' => (int) $ownVariant['id'],
        'stock_strategy' => 'OWN_STOCK',
    ], 1);
    $ownPlan = $bundles->inventoryPlanForVariant(1, (int) $ownVariant['id']);
    $h->assertSame('virtual', $own['stock_mode'], 'own stock maps to the compatible virtual mode');
    $h->assertSame((int) $ownVariant['id'], $ownPlan['leaves'][0]['sellable_id'], 'own-stock bundle reserves itself');
    $h->assertSame(5, $ownPlan['availability']['available_quantity'], 'own-stock bundle availability uses its autonomous stock');

    [$nonStockedProduct, $nonStockedVariant] = $make('non-stocked-bundle', 'bundle', 0, false);
    $nonStocked = $bundles->replaceProductBundle(1, (int) $nonStockedProduct['id'], [
        'bundle_variant_id' => (int) $nonStockedVariant['id'],
        'stock_strategy' => 'NON_STOCKED',
    ], 1);
    $nonStockedPlan = $bundles->inventoryPlanForVariant(1, (int) $nonStockedVariant['id']);
    $h->assertSame('none', $nonStocked['stock_mode'], 'non-stocked strategy maps to the compatible none mode');
    $h->assertSame([], $nonStockedPlan['leaves'], 'non-stocked bundles produce no physical inventory movement');
    $h->assertSame('deliverable', $nonStockedPlan['availability']['status'], 'non-stocked bundle stays orderable without stock');

    $partialRejected = false;
    try {
        $bundles->replaceProductBundle(1, (int) $bundleProduct['id'], ['partial_availability_policy' => 'ALLOW_PARTIAL'], 1);
    } catch (InvalidArgumentException $e) {
        $partialRejected = $e->getMessage() === 'business.bundle_partial_fulfillment_not_supported';
    }
    $h->assertTrue($partialRejected, 'partial availability is rejected until fulfillment can represent the remainder');

    [$ordinaryProduct] = $make('ordinary-product', 'physical', 1);
    $wrongTypeRejected = false;
    try {
        $bundles->replaceProductBundle(1, (int) $ordinaryProduct['id'], ['stock_strategy' => 'OWN_STOCK'], 1);
    } catch (InvalidArgumentException $e) {
        $wrongTypeRejected = $e->getMessage() === 'business.bundle_product_type_required';
    }
    $h->assertTrue($wrongTypeRejected, 'bundle configuration is restricted to bundle products');

    $cycleRejected = false;
    try {
        $bundles->addComponent(1, (int) $derived['id'], [
            'component_product_id' => (int) $nestedProduct['id'],
            'component_variant_id' => (int) $nestedVariant['id'],
            'quantity' => 1,
        ]);
    } catch (InvalidArgumentException $e) {
        $cycleRejected = $e->getMessage() === 'business.bundle_loop_detected';
    }
    $h->assertTrue($cycleRejected, 'nested bundle cycles are rejected at configuration time');

    $db->run("UPDATE business_product_variants SET status='archived',archived_at=CURRENT_TIMESTAMP WHERE id=?", [(int) $variantB['id']]);
    $missingSummary = $bundles->bundleSummaryForVariant(1, (int) $bundleVariant['id']);
    $h->assertTrue(in_array('bundle_component_not_sellable', $missingSummary['bundle_missing_requirements'] ?? [], true), 'missing or archived component is reported as a configuration error');
    $h->assertSame('unavailable', $missingSummary['bundle_availability_status'] ?? null, 'missing component makes the public bundle unavailable');
} finally {
    $db = null;
    gc_collect_cycles();
    test_remove_tree($dir);
}

exit($h->finish('UNIT business bundle stock strategies'));
