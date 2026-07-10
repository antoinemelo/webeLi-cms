<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

$h = new TestHarness();
[$dir, $dbPath, $db] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/business.sql');

try {
    $h->assertTrue((int) ($db->one('SELECT COUNT(*) AS count FROM business_product_brands WHERE site_id = 1')['count'] ?? 0) >= 2, 'PIM fixtures include at least two brands');
    $h->assertTrue((int) ($db->one('SELECT COUNT(*) AS count FROM business_product_categories WHERE site_id = 1')['count'] ?? 0) >= 3, 'PIM fixtures include at least three categories');
    $h->assertTrue((int) ($db->one('SELECT COUNT(*) AS count FROM business_products WHERE site_id = 1')['count'] ?? 0) >= 4, 'PIM fixtures include at least four products');

    $groups = $db->all('SELECT code FROM business_attribute_groups WHERE site_id = 1 ORDER BY code');
    $h->assertSame(['seo', 'service', 'technique', 'textile'], array_map(static fn(array $row): string => (string) $row['code'], $groups), 'PIM attribute groups are seeded');
    $attributes = $db->all('SELECT code FROM business_attributes WHERE site_id = 1 AND code IN (?, ?, ?, ?, ?, ?) ORDER BY code', ['couleur', 'taille', 'duree', 'matiere', 'niveau', 'poids']);
    $h->assertSame(['couleur', 'duree', 'matiere', 'niveau', 'poids', 'taille'], array_map(static fn(array $row): string => (string) $row['code'], $attributes), 'PIM demo attributes are seeded');

    $activeVariant = $db->one('SELECT id FROM business_product_variants WHERE status = "active" LIMIT 1');
    $draftVariant = $db->one('SELECT id FROM business_product_variants WHERE status = "draft" AND sku = ?', ['INCOMPLETE-DEMO-DRAFT']);
    $h->assertTrue($activeVariant !== null, 'PIM fixtures include active variants');
    $h->assertTrue($draftVariant !== null, 'PIM fixtures include a draft variant');

    $posSellable = $db->one(
        'SELECT p.slug, s.score
         FROM business_product_completeness_scores s
         INNER JOIN business_products p ON p.id = s.product_id
         WHERE s.channel = "pos" AND s.is_sellable = 1
         ORDER BY s.score DESC
         LIMIT 1'
    );
    $h->assertSame('gourde-demo', (string) ($posSellable['slug'] ?? ''), 'PIM fixtures include a POS sellable product');
    $h->assertSame(100, (int) ($posSellable['score'] ?? 0), 'POS sellable product has full fixture score');

    $incomplete = $db->one(
        'SELECT p.slug, s.missing_json
         FROM business_product_completeness_scores s
         INNER JOIN business_products p ON p.id = s.product_id
         WHERE s.variant_id IS NULL AND s.is_sellable = 0 AND s.missing_json <> "[]"
         ORDER BY s.score ASC
         LIMIT 1'
    );
    $h->assertSame('produit-incomplet-demo', (string) ($incomplete['slug'] ?? ''), 'PIM fixtures include an incomplete product');
    $missing = json_decode((string) ($incomplete['missing_json'] ?? '[]'), true);
    $h->assertTrue(is_array($missing) && in_array('main_asset', $missing, true), 'incomplete product exposes missing requirements');

    $mainAsset = $db->one(
        'SELECT a.media_id, a.role, a.channel_scope
         FROM business_product_assets a
         INNER JOIN business_products p ON p.id = a.product_id
         WHERE p.slug = ? AND a.variant_id IS NULL AND a.role = "main" AND a.archived_at IS NULL
         LIMIT 1',
        ['gourde-demo']
    );
    $h->assertSame('main', (string) ($mainAsset['role'] ?? ''), 'PIM fixtures include a product main asset');
    $h->assertSame('all', (string) ($mainAsset['channel_scope'] ?? ''), 'product main asset is channel-neutral');

    $variantAsset = $db->one(
        'SELECT a.media_id
         FROM business_product_assets a
         INNER JOIN business_product_variants v ON v.id = a.variant_id
         WHERE v.sku = ? AND a.role = "main" AND a.channel_scope = "pos" AND a.archived_at IS NULL
         LIMIT 1',
        ['DEMO-GOURDE-BLEU']
    );
    $h->assertTrue($variantAsset !== null, 'PIM fixtures include a variant-specific main image');

    $inheritedAsset = $db->one(
        'SELECT a.media_id
         FROM business_product_assets a
         INNER JOIN business_products p ON p.id = a.product_id
         INNER JOIN business_product_variants v ON v.product_id = p.id
         WHERE v.sku = ? AND a.variant_id IS NULL AND a.role = "main" AND a.archived_at IS NULL
         LIMIT 1',
        ['CONSULTATION-STANDARD']
    );
    $h->assertTrue($inheritedAsset !== null, 'PIM fixtures allow a variant to inherit a product main image');

    $technical = $db->one(
        'SELECT role, channel_scope, is_public
         FROM business_product_assets
         WHERE role = "technical_sheet" AND channel_scope = "pdf"
         LIMIT 1'
    );
    $h->assertSame('technical_sheet', (string) ($technical['role'] ?? ''), 'PIM fixtures include a technical sheet asset');
    $h->assertSame(0, (int) ($technical['is_public'] ?? 1), 'technical sheet fixture is not public');

    $rules = $db->all('SELECT code FROM business_product_completeness_rules WHERE site_id = 1 AND is_active = 1 ORDER BY code');
    $ruleCodes = array_map(static fn(array $row): string => (string) $row['code'], $rules);
    foreach (['name-required', 'variant-sku-required', 'sale-price-required', 'tax-required', 'main-image-pos-required', 'active-variant-required', 'pos-channel-required', 'ecommerce-channel-required'] as $code) {
        $h->assertTrue(in_array($code, $ruleCodes, true), 'PIM completeness rule exists: ' . $code);
    }

    $relation = $db->one(
        'SELECT relation_type
         FROM business_product_relations r
         INNER JOIN business_products p ON p.id = r.product_id
         INNER JOIN business_products related ON related.id = r.related_product_id
         WHERE p.slug = ? AND related.slug = ?
         LIMIT 1',
        ['t-shirt-demo', 'gourde-demo']
    );
    $h->assertSame('cross_sell', (string) ($relation['relation_type'] ?? ''), 'PIM fixtures include product relations');

    $bundle = $db->one(
        'SELECT p.slug, v.sku, b.pricing_mode, b.stock_mode
         FROM business_product_bundles b
         INNER JOIN business_products p ON p.id = b.bundle_product_id
         INNER JOIN business_product_variants v ON v.id = b.bundle_variant_id
         WHERE p.slug = ? AND b.archived_at IS NULL
         LIMIT 1',
        ['pack-demo']
    );
    $h->assertSame('pack-demo', (string) ($bundle['slug'] ?? ''), 'PIM fixtures include a sellable bundle product');
    $h->assertSame('BUNDLE-DEMO-STANDARD', (string) ($bundle['sku'] ?? ''), 'PIM bundle fixture has a dedicated sellable variant');
    $h->assertSame('fixed', (string) ($bundle['pricing_mode'] ?? ''), 'PIM bundle fixture uses fixed pricing');
    $h->assertSame('components', (string) ($bundle['stock_mode'] ?? ''), 'PIM bundle fixture reads availability from components');
    $bundleComponents = (int) ($db->one(
        'SELECT COUNT(*) AS count
         FROM business_bundle_components c
         INNER JOIN business_product_bundles b ON b.id = c.bundle_id
         INNER JOIN business_products p ON p.id = b.bundle_product_id
         WHERE p.slug = ? AND c.archived_at IS NULL',
        ['pack-demo']
    )['count'] ?? 0);
    $h->assertSame(2, $bundleComponents, 'PIM bundle fixture includes two components');
} finally {
    $db = null;
    gc_collect_cycles();
    test_remove_tree($dir);
}

exit($h->finish('UNIT business PIM-lite fixtures'));
