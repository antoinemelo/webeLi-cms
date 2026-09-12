<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Core\Database;
use App\Modules\Business\Catalog\BusinessCatalogValidator;
use App\Modules\Business\Catalog\CatalogPricingService;
use App\Modules\Business\Repositories\BusinessCatalogPricingRepository;
use App\Modules\Business\Services\BusinessProductBundleService;
use App\Modules\Business\Services\CatalogCommercialRelationService;
use App\Modules\Business\Services\CatalogPriceListService;

$h = new TestHarness();
[$dir, $path, $db] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/business.sql');

try {
    $repository = new BusinessCatalogPricingRepository($db);
    $pricing = new CatalogPricingService($repository);
    $validator = new BusinessCatalogValidator();
    $priceLists = new CatalogPriceListService($db);
    $relations = new CatalogCommercialRelationService($db);
    $bundles = new BusinessProductBundleService($db);

    $makeProduct = static function (string $slug, string $type = 'physical', string $status = 'active') use ($repository, $validator): array {
        $product = $repository->createProduct(1, $validator->product([
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'type' => $type,
            'status' => $status,
            'channels' => ['public', 'ecommerce', 'pos', 'catalogue'],
            'base_purchase_price' => 40,
            'base_sale_price' => 100,
            'currency' => 'CHF',
        ]));
        $variant = $repository->createVariant((int) $product['id'], $validator->variant([
            'sku' => strtoupper($slug) . '-1',
            'status' => $status,
            'stock_quantity' => 20,
        ]));
        return [$product, $variant];
    };

    [$product, $variant] = $makeProduct('pricing-context');
    $productId = (int) $product['id'];
    $variantId = (int) $variant['id'];

    $generic = $priceLists->createList(1, ['name' => 'Web CHF', 'currency' => 'CHF', 'channel' => 'ecommerce', 'priority' => 50]);
    $priceLists->addItem(1, (int) $generic['id'], [
        'product_id' => $productId,
        'adjustment_type' => 'fixed',
        'adjustment_value' => 90,
        'compare_at_amount' => 100,
    ]);
    $vip = $priceLists->createList(1, ['name' => 'Web VIP', 'currency' => 'CHF', 'channel' => 'ecommerce', 'customer_segment' => 'vip', 'priority' => 50]);
    $priceLists->addItem(1, (int) $vip['id'], ['product_id' => $productId, 'variant_id' => $variantId, 'adjustment_type' => 'fixed', 'adjustment_value' => 80]);
    $pos = $priceLists->createList(1, ['name' => 'POS CHF', 'currency' => 'CHF', 'channel' => 'pos', 'priority' => 10]);
    $priceLists->addItem(1, (int) $pos['id'], ['product_id' => $productId, 'adjustment_type' => 'fixed', 'adjustment_value' => 95]);
    $expired = $priceLists->createList(1, ['name' => 'Expired', 'currency' => 'CHF', 'channel' => 'ecommerce', 'priority' => 1, 'starts_at' => '2020-01-01', 'ends_at' => '2020-02-01']);
    $priceLists->addItem(1, (int) $expired['id'], ['product_id' => $productId, 'adjustment_type' => 'fixed', 'adjustment_value' => 1]);

    $web = $pricing->pricingSummary($variantId, 'ecommerce', new DateTimeImmutable('2026-07-12'), ['currency' => 'CHF']);
    $h->assertSame('90.00', $web['regular_sale_price'], 'generic ecommerce price list resolves deterministically');
    $h->assertSame('100.00', $web['compare_at_price'], 'compare-at price is preserved for strikethrough display');
    $vipPrice = $pricing->pricingSummary($variantId, 'ecommerce', new DateTimeImmutable('2026-07-12'), ['currency' => 'CHF', 'customer_segment' => 'vip']);
    $h->assertSame('80.00', $vipPrice['regular_sale_price'], 'exact customer segment and variant win over generic rules');
    $h->assertSame('95.00', $pricing->pricingSummary($variantId, 'pos', null, ['currency' => 'CHF'])['regular_sale_price'], 'channel-specific POS price is isolated');
    $h->assertSame('CHF', $vipPrice['currency'], 'resolved currency is explicit');

    $repository->createOffer(1, $validator->offer(['name' => 'VIP 10', 'type' => 'percent', 'value' => 10, 'scope' => 'variant', 'channel' => 'ecommerce']) + [
        'variant_id' => $variantId,
        'customer_segment' => 'vip',
        'priority' => 10,
    ]);
    $vipDiscounted = $pricing->pricingSummary($variantId, 'ecommerce', null, ['currency' => 'CHF', 'customer_segment' => 'vip']);
    $h->assertSame('72.00', $vipDiscounted['final_sale_price'], 'segment discount applies after price-list resolution');
    $h->assertSame('vip', $vipDiscounted['active_discount']['customer_segment'] ?? null, 'discount snapshot records its segment');

    $siteIsolation = false;
    try {
        $otherSiteList = $priceLists->createList(2, ['name' => 'Other site', 'currency' => 'CHF']);
        $priceLists->addItem(2, (int) $otherSiteList['id'], ['product_id' => $productId, 'adjustment_type' => 'fixed', 'adjustment_value' => 10]);
    } catch (InvalidArgumentException) {
        $siteIsolation = true;
    }
    $h->assertTrue($siteIsolation, 'price list cannot target an unknown site context');

    [$target] = $makeProduct('cross-sell-target');
    $relation = $relations->link(1, $productId, (int) $target['id'], 'cross_sell', 10);
    $h->assertSame('cross_sell', $relation['relation_type'], 'cross-sell is stored as a catalog relation');
    $h->assertSame(1, count($relations->relations(1, $productId, 'cross_sell')), 'catalog relations are queryable without cart mutation');
    $related = $relations->link(1, $productId, (int) $target['id'], 'related', 2);
    $h->assertSame('related', $related['relation_type'], 'canonical related type is stored independently');
    $h->assertTrue($relations->deleteRelation(1, (int) $related['id']), 'a manual product relation can be removed explicitly');
    $h->assertSame(0, count($relations->relations(1, $productId, 'related')), 'removed manual relation is no longer returned');

    $categoryId = (int) ($db->one('SELECT id FROM business_product_categories WHERE site_id=1 ORDER BY id LIMIT 1')['id'] ?? 0);
    [$automaticTarget] = $makeProduct('automatic-related-target');
    [$nonPublicTarget] = $makeProduct('automatic-private-target');
    $db->run('UPDATE business_products SET category_id=? WHERE id IN (?,?)', [$categoryId, (int) $automaticTarget['id'], (int) $nonPublicTarget['id']]);
    $db->run('UPDATE business_products SET is_public=0 WHERE id=?', [(int) $nonPublicTarget['id']]);
    $rule = $relations->saveRule(1, $productId, ['relation_type'=>'related','match_type'=>'category','match_id'=>$categoryId,'result_limit'=>6,'sort_order'=>20]);
    $automaticRelations = $relations->relations(1, $productId, 'related');
    $automaticIds = array_map(static fn(array $row): int => (int) ($row['related_product_id'] ?? 0), $automaticRelations);
    $h->assertTrue(in_array((int) $automaticTarget['id'], $automaticIds, true), 'automatic category rule exposes its active public ecommerce target');
    $h->assertTrue(!in_array((int) $nonPublicTarget['id'], $automaticIds, true), 'automatic category rule excludes non-public targets');
    $h->assertSame(0, count(array_filter($automaticRelations, static fn(array $row): bool => ($row['source'] ?? null) !== 'automatic')), 'automatic relation provenance remains explicit');
    $h->assertSame(1, count($relations->rules(1, $productId)), 'automatic relation rules can be inspected in the back office');
    $h->assertTrue($relations->deleteRule(1, (int) $rule['id']), 'automatic relation rule can be removed explicitly');

    $selfRelationRejected = false;
    try { $relations->link(1, $productId, $productId, 'related'); } catch (InvalidArgumentException) { $selfRelationRejected = true; }
    $h->assertTrue($selfRelationRejected, 'a product relation cannot target the source product');
    $otherSiteProduct = $repository->createProduct(2, $validator->product(['name'=>'Other site target','slug'=>'other-site-target','type'=>'physical','status'=>'active','channels'=>['public','ecommerce'],'base_sale_price'=>10,'currency'=>'CHF']));
    $crossSiteRejected = false;
    try { $relations->link(1, $productId, (int)$otherSiteProduct['id'], 'related'); } catch (InvalidArgumentException) { $crossSiteRejected = true; }
    $h->assertTrue($crossSiteRejected, 'a relation cannot target a product belonging to another site');

    [$gift] = $makeProduct('gift-policy', 'gift_card');
    $policy = $relations->configureGiftCard(1, (int) $gift['id'], ['currency' => 'CHF', 'value_mode' => 'open', 'minimum_amount' => 20, 'maximum_amount' => 500, 'expires_after_days' => 730]);
    $h->assertSame('open', $policy['value_mode'], 'gift-card value policy is distinct from discount rules');
    $giftRejected = false;
    try {
        $relations->configureGiftCard(1, $productId, ['currency' => 'CHF']);
    } catch (InvalidArgumentException) {
        $giftRejected = true;
    }
    $h->assertTrue($giftRejected, 'gift-card policy cannot be attached to a regular product');

    [$bundleA] = $makeProduct('bundle-a', 'bundle');
    [$bundleB] = $makeProduct('bundle-b', 'bundle');
    [$bundleC] = $makeProduct('bundle-c', 'bundle');
    $a = $bundles->replaceProductBundle(1, (int) $bundleA['id'], ['composition_type' => 'kit', 'stock_mode' => 'components', 'unavailable_strategy' => 'reject'], 1);
    $b = $bundles->replaceProductBundle(1, (int) $bundleB['id'], ['composition_type' => 'bundle'], 1);
    $c = $bundles->replaceProductBundle(1, (int) $bundleC['id'], ['composition_type' => 'bundle'], 1);
    $bundles->addComponent(1, (int) $a['id'], ['component_product_id' => (int) $bundleB['id'], 'quantity' => 1]);
    $bundles->addComponent(1, (int) $b['id'], ['component_product_id' => (int) $bundleC['id'], 'quantity' => 2]);
    $h->assertSame('kit', $bundles->bundleForProduct(1, (int) $bundleA['id'])['composition_type'] ?? null, 'kit remains distinct from sellable bundle');

    $cycleRejected = false;
    try {
        $bundles->addComponent(1, (int) $c['id'], ['component_product_id' => (int) $bundleA['id'], 'quantity' => 1]);
    } catch (InvalidArgumentException) {
        $cycleRejected = true;
    }
    $h->assertTrue($cycleRejected, 'indirect bundle cycle A-B-C-A is rejected');

    $quantityRejected = false;
    try {
        $bundles->addComponent(1, (int) $a['id'], ['component_product_id' => (int) $target['id'], 'quantity' => 0]);
    } catch (InvalidArgumentException) {
        $quantityRejected = true;
    }
    $h->assertTrue($quantityRejected, 'bundle component quantity must be positive');

    [$inactive] = $makeProduct('inactive-component', 'physical', 'draft');
    $inactiveRejected = false;
    try {
        $bundles->addComponent(1, (int) $a['id'], ['component_product_id' => (int) $inactive['id'], 'quantity' => 1]);
    } catch (InvalidArgumentException) {
        $inactiveRejected = true;
    }
    $h->assertTrue($inactiveRejected, 'inactive components are rejected');

    $conflict = $priceLists->createList(1, ['name' => 'Conflicting VIP', 'currency' => 'CHF', 'channel' => 'ecommerce', 'customer_segment' => 'vip', 'priority' => 50]);
    $priceLists->addItem(1, (int) $conflict['id'], ['product_id' => $productId, 'variant_id' => $variantId, 'adjustment_type' => 'fixed', 'adjustment_value' => 70]);
    $ambiguousRejected = false;
    try {
        $pricing->pricingSummary($variantId, 'ecommerce', null, ['currency' => 'CHF', 'customer_segment' => 'vip']);
    } catch (InvalidArgumentException $e) {
        $ambiguousRejected = $e->getMessage() === 'business.pricing.ambiguous_price_rule';
    }
    $h->assertTrue($ambiguousRejected, 'same-priority conflicting rules fail explicitly');
} finally {
    $db = null;
    gc_collect_cycles();
    test_remove_tree($dir);
}

exit($h->finish('UNIT business pricing offers and bundles'));
