<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Business\Catalog\CatalogPricingService;
use App\Modules\Business\Repositories\BusinessCatalogPricingRepository;
use App\Modules\Business\Repositories\PosCatalogRepository;
use App\Modules\Business\Services\BusinessCatalogSellableReadService;

$h = new TestHarness();
[$dir, $path, $db] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/business.sql');

try {
    $pricingRepository = new BusinessCatalogPricingRepository($db);
    $sellables = new BusinessCatalogSellableReadService(
        $pricingRepository,
        new CatalogPricingService($pricingRepository),
        new PosCatalogRepository($db)
    );

    $variantId = (int) ($db->one("SELECT id FROM business_product_variants WHERE sku = 'DEMO-GOURDE-BLEU' LIMIT 1")['id'] ?? 0);
    $h->assertTrue($variantId > 0, 'demo physical variant exists');
    $db->run('UPDATE business_product_variants SET sales_note = ? WHERE id = ?', ['A proposer avec mousqueton.', $variantId]);
    $pricingRepository->createOffer(1, [
        'name' => 'Snapshot POS amount',
        'type' => 'amount',
        'value' => 5,
        'currency' => 'CHF',
        'scope' => 'variant',
        'scope_id' => $variantId,
        'channel' => 'pos',
        'priority' => 1,
    ]);

    $snapshot = $sellables->getSellableVariantSnapshot(1, $variantId, ['channel' => 'pos']);
    $h->assertSame(1, $snapshot['site_id'], 'snapshot keeps site id');
    $h->assertSame($variantId, $snapshot['variant_id'], 'snapshot exposes canonical variant id');
    $h->assertSame($variantId, $snapshot['business_variant_id'], 'snapshot keeps legacy variant id for Sale');
    $h->assertSame('DEMO-GOURDE-BLEU', $snapshot['sku'], 'snapshot keeps SKU');
    $h->assertSame('A proposer avec mousqueton.', $snapshot['sales_note'], 'snapshot exposes variant sales note');
    $h->assertTrue(array_key_exists('attributes', $snapshot['metadata'] ?? []), 'snapshot exposes the PIM attributes contract for Sales');
    $h->assertSame(true, $snapshot['is_sellable'], 'complete POS variant is sellable');
    $h->assertSame([], $snapshot['missing_requirements'], 'complete POS variant has no missing requirement');
    $h->assertSame(2900, $snapshot['regular_sale_price_minor'], 'regular sale price uses minor units');
    $h->assertSame(2400, $snapshot['sale_price_minor'], 'POS discount is applied to sale price');
    $h->assertSame(2400, $snapshot['unit_price_minor'], 'legacy sale unit price matches canonical sale price');
    $h->assertTrue(!array_key_exists('purchase_price_minor', $snapshot), 'purchase price is hidden by default');
    $h->assertTrue(!array_key_exists('unit_purchase_price_minor', $snapshot), 'legacy purchase price is hidden by default');
    $h->assertSame(false, $snapshot['purchase_price_visible'], 'snapshot documents purchase price visibility');
    $h->assertSame(1, count($snapshot['discounts_applied']), 'applied discount list is present');
    $h->assertSame('Snapshot POS amount', $snapshot['discounts_applied'][0]['name'] ?? null, 'applied discount payload keeps label');
    $h->assertSame(5, $snapshot['main_asset']['media_id'] ?? null, 'variant main asset wins over product fallback');
    $h->assertSame('/media/5', $snapshot['main_asset']['url'] ?? null, 'main asset exposes a stable media URL payload');

    $privateSnapshot = $sellables->getSellableVariantSnapshot(1, $variantId, [
        'channel' => 'pos',
        'include_purchase_price' => true,
        'include_internal_fields' => true,
    ]);
    $h->assertSame(1200, $privateSnapshot['purchase_price_minor'], 'authorized context exposes purchase price');
    $h->assertSame(1200, $privateSnapshot['unit_purchase_price_minor'], 'authorized context keeps legacy purchase price');
    $h->assertSame(1200, $privateSnapshot['margin_minor'], 'authorized context exposes margin in minor units');
    $h->assertSame(5000, $privateSnapshot['margin_percent_basis_points'], 'authorized context exposes margin basis points');
    $h->assertTrue(array_key_exists('snapshot_json', $privateSnapshot), 'internal context keeps snapshot JSON placeholder');

    $publicPayload = $sellables->publicPayload($privateSnapshot);
    $h->assertTrue(!array_key_exists('purchase_price_minor', $publicPayload), 'public payload removes canonical purchase price');
    $h->assertTrue(!array_key_exists('unit_purchase_price_minor', $publicPayload), 'public payload removes legacy purchase price');
    $h->assertTrue(!array_key_exists('margin_minor', $publicPayload), 'public payload removes margin');
    $h->assertTrue(!array_key_exists('snapshot_json', $publicPayload), 'public payload removes internal snapshot JSON');

    $aiSnapshot = $sellables->aiSnapshot(1, $variantId, ['channel' => 'pos'], false);
    $h->assertTrue(!array_key_exists('purchase_price_minor', $aiSnapshot), 'AI snapshot without permission hides purchase price');
    $h->assertTrue(!array_key_exists('unit_purchase_price_minor', $aiSnapshot), 'AI snapshot without permission hides legacy purchase price');
    $h->assertTrue(!array_key_exists('margin_minor', $aiSnapshot), 'AI snapshot without permission hides margin');
    $h->assertTrue(!array_key_exists('snapshot_json', $aiSnapshot), 'AI snapshot never returns internal snapshot JSON by default');
    $h->assertSame(false, $aiSnapshot['purchase_price_visible'], 'AI snapshot documents hidden purchase price');
    $h->assertSame(true, $aiSnapshot['ai']['missing_requirements_available'] ?? null, 'AI snapshot exposes missing requirements availability');
    $h->assertTrue(array_key_exists('missing_requirements', $aiSnapshot), 'AI snapshot includes missing requirements');

    $aiPrivateSnapshot = $sellables->aiSnapshot(1, $variantId, ['channel' => 'pos'], true);
    $h->assertSame(1200, $aiPrivateSnapshot['purchase_price_minor'], 'AI snapshot with permission exposes purchase price');
    $h->assertSame(true, $aiPrivateSnapshot['ai']['purchase_price_visible'] ?? null, 'AI snapshot documents authorized purchase price');

    $db->run('UPDATE business_product_assets SET archived_at = CURRENT_TIMESTAMP WHERE variant_id = ?', [$variantId]);
    $fallback = $sellables->getSellableVariantSnapshot(1, $variantId, ['channel' => 'pos']);
    $h->assertSame(4, $fallback['main_asset']['media_id'] ?? null, 'product main asset is used when variant asset is absent');

    $db->run('UPDATE business_products SET is_pos_enabled = 0 WHERE id = ?', [(int) $snapshot['product_id']]);
    $posDenied = $sellables->explainSellability(1, $variantId, ['channel' => 'pos']);
    $h->assertSame(false, $posDenied['is_sellable'], 'POS disabled product is not sellable on POS');
    $h->assertTrue(in_array('channel_pos_disabled', $posDenied['missing_requirements'], true), 'POS disabled reason is explicit');
    $db->run('UPDATE business_products SET is_pos_enabled = 1 WHERE id = ?', [(int) $snapshot['product_id']]);

    $db->run('UPDATE business_products SET is_ecommerce_enabled = 0 WHERE id = ?', [(int) $snapshot['product_id']]);
    $ecommerceDenied = $sellables->explainSellability(1, $variantId, ['channel' => 'ecommerce']);
    $h->assertSame(false, $ecommerceDenied['is_sellable'], 'e-commerce disabled product is not sellable on e-commerce');
    $h->assertTrue(in_array('channel_ecommerce_disabled', $ecommerceDenied['missing_requirements'], true), 'e-commerce disabled reason is explicit');
    $db->run('UPDATE business_products SET is_ecommerce_enabled = 1 WHERE id = ?', [(int) $snapshot['product_id']]);

    $db->run('UPDATE business_product_variants SET stock_quantity = 0, stock_reserved = 0, allow_backorder = 0 WHERE id = ?', [$variantId]);
    $stockDenied = $sellables->explainSellability(1, $variantId, ['channel' => 'pos']);
    $h->assertTrue(in_array('stock_unavailable', $stockDenied['missing_requirements'], true), 'tracked stock without availability is reported');
    $db->run('UPDATE business_product_variants SET allow_backorder = 1, backorder_delivery_days = 12 WHERE id = ?', [$variantId]);
    $backorder = $sellables->getSellableVariantSnapshot(1, $variantId, ['channel' => 'pos']);
    $h->assertSame(true, $backorder['is_sellable'], 'tracked zero stock with backorder remains sellable');
    $h->assertSame('backorder', $backorder['availability']['status'] ?? null, 'backorder availability status is explicit');
    $h->assertSame(12, $backorder['availability']['delivery_lead_time_days'] ?? null, 'backorder delivery lead time is exposed');
    $db->run('UPDATE business_product_variants SET allow_backorder = 0 WHERE id = ?', [$variantId]);
    $catalogOnly = $sellables->explainSellability(1, $variantId, ['channel' => 'public']);
    $h->assertTrue(!in_array('stock_unavailable', $catalogOnly['missing_requirements'], true), 'catalog channel can display contact-only products');

    $incompleteVariantId = (int) ($db->one("SELECT id FROM business_product_variants WHERE sku = 'INCOMPLETE-DEMO-DRAFT' LIMIT 1")['id'] ?? 0);
    $incomplete = $sellables->explainSellability(1, $incompleteVariantId, ['channel' => 'pos']);
    $h->assertSame(false, $incomplete['is_sellable'], 'incomplete draft variant is not sellable');
    $h->assertTrue(in_array('variant_inactive', $incomplete['missing_requirements'], true), 'draft variant reason is explicit');
    $h->assertTrue(in_array('channel_pos_disabled', $incomplete['missing_requirements'], true), 'disabled POS channel is explicit');

    $list = $sellables->listSellableVariants(1, ['channel' => 'pos', 'sku' => 'DEMO-GOURDE-BLEU', 'include_not_sellable' => true]);
    $h->assertSame(1, count($list['items']), 'listSellableVariants finds SKU with the new API');
    $h->assertSame('DEMO-GOURDE-BLEU', $list['items'][0]['sku'] ?? null, 'list item keeps SKU');

    $tshirtProductId = (int) ($db->one("SELECT id FROM business_products WHERE slug = 't-shirt-demo' LIMIT 1")['id'] ?? 0);
    $tshirtVariantId = (int) ($db->one("SELECT id FROM business_product_variants WHERE sku = 'TSHIRT-DEMO-L-BLUE' LIMIT 1")['id'] ?? 0);
    $db->run(
        'INSERT OR REPLACE INTO business_product_completeness_scores(product_id, variant_id, channel, score, is_sellable, missing_json)
         VALUES(?, NULL, "pos", 50, 0, ?)',
        [$tshirtProductId, json_encode(['missing' => [['code' => 'product_level_only']]], JSON_UNESCAPED_SLASHES)]
    );
    $tshirtSnapshot = $sellables->getSellableVariantSnapshot(1, $tshirtVariantId, ['channel' => 'pos']);
    $h->assertSame(true, $tshirtSnapshot['is_sellable'], 'variant snapshot does not inherit blocking product-level completeness');
    $blackTshirtVariantId = (int) ($db->one("SELECT id FROM business_product_variants WHERE sku = 'TSHIRT-DEMO-M-BLACK' LIMIT 1")['id'] ?? 0);
    $blackTshirtSnapshot = $sellables->getSellableVariantSnapshot(1, $blackTshirtVariantId, ['channel' => 'pos']);
    $h->assertSame([], $blackTshirtSnapshot['variant_options'] ?? [], 'T-shirt demo does not expose duplicate product option axes');
    $tshirtAttributes = [];
    foreach (($blackTshirtSnapshot['metadata']['attributes']['merged'] ?? []) as $attribute) {
        $tshirtAttributes[(string) ($attribute['code'] ?? '')] = (string) ($attribute['value'] ?? '');
    }
    $h->assertSame('M', $tshirtAttributes['taille'] ?? null, 'snapshot exposes T-shirt size from Textile attributes');
    $h->assertSame('Noir', $tshirtAttributes['couleur'] ?? null, 'snapshot exposes T-shirt color from Textile attributes');
    $h->assertSame('Coton', $tshirtAttributes['matiere'] ?? null, 'snapshot exposes T-shirt material from Textile attributes');
    $db->run(
        'INSERT OR REPLACE INTO business_product_completeness_scores(product_id, variant_id, channel, score, is_sellable, missing_json)
         SELECT p.id, v.id, "pos", 50, 0, ?
         FROM business_products p
         INNER JOIN business_product_variants v ON v.product_id = p.id
         WHERE p.slug = "t-shirt-demo"',
        [json_encode(['missing' => [['code' => 'stale_required_attribute_missing']]], JSON_UNESCAPED_SLASHES)]
    );
    $tshirtList = $sellables->listSellableVariants(1, ['channel' => 'pos', 'q' => 'T-shirt', 'limit' => 20]);
    $tshirtSkus = array_values(array_filter(array_map(static fn(array $item): string => (string) ($item['sku'] ?? ''), $tshirtList['items']), static fn(string $sku): bool => str_starts_with($sku, 'TSHIRT-DEMO-')));
    sort($tshirtSkus);
    $h->assertSame(
        ['TSHIRT-DEMO-L-BLUE', 'TSHIRT-DEMO-M-BLACK', 'TSHIRT-DEMO-M-BLUE'],
        $tshirtSkus,
        'POS list recalculates stale T-shirt variant completeness live'
    );

    $h->assertSame(1235, $sellables->moneyToMinor('12.35'), 'minor unit conversion remains stable');
} finally {
    $db = null;
    gc_collect_cycles();
    test_remove_tree($dir);
}

exit($h->finish('UNIT business sellable variant snapshot service'));
