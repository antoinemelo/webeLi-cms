<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Business\Services\BusinessProductCompletenessService;

$h = new TestHarness();
[$dir, $path, $db] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/business.sql');

try {
    $service = new BusinessProductCompletenessService($db);

    $gourdeProductId = (int) ($db->one("SELECT id FROM business_products WHERE slug = 'gourde-demo' LIMIT 1")['id'] ?? 0);
    $gourdeVariantId = (int) ($db->one("SELECT id FROM business_product_variants WHERE sku = 'DEMO-GOURDE-BLEU' LIMIT 1")['id'] ?? 0);
    $serviceProductId = (int) ($db->one("SELECT id FROM business_products WHERE slug = 'vol-decouverte' LIMIT 1")['id'] ?? 0);
    $serviceVariantId = (int) ($db->one("SELECT id FROM business_product_variants WHERE sku = 'DEMO-VOL-CLASSIC-20' LIMIT 1")['id'] ?? 0);
    $h->assertTrue($gourdeProductId > 0 && $gourdeVariantId > 0 && $serviceProductId > 0 && $serviceVariantId > 0, 'demo products and variants exist');

    $complete = $service->calculateProductScore($gourdeProductId, 'pos');
    $h->assertSame(true, $complete['is_sellable'], 'complete POS product is sellable');
    $h->assertTrue((int) $complete['score'] >= 90, 'complete product keeps a high score');
    $h->assertSame('Prêt à vendre', $complete['label'], 'complete product exposes a human sellable label');

    $db->run(
        "INSERT INTO business_product_completeness_rules(site_id,code,name,scope,required_field,product_type,severity,required_language,channel,weight,is_active)
         VALUES(1,'physical-de-translation','Traduction allemande recommandée','product','translation','physical','warn','de','ecommerce',2,1)"
    );
    $configuredWarning = $service->calculateProductScore($gourdeProductId, 'ecommerce');
    $configuredWarningCodes = array_map(static fn(array $issue): string => (string) $issue['code'], $configuredWarning['warnings']);
    $h->assertTrue(in_array('physical-de-translation', $configuredWarningCodes, true), 'product-type translation rule is explainable');
    $h->assertSame(true, $configuredWarning['is_sellable'], 'warning policy does not block publication');
    $serviceConfigured = $service->calculateProductScore($serviceProductId, 'ecommerce');
    $serviceWarningCodes = array_map(static fn(array $issue): string => (string) $issue['code'], $serviceConfigured['warnings']);
    $h->assertSame(false, in_array('physical-de-translation', $serviceWarningCodes, true), 'product-type rule is isolated from other product types');

    $db->run("INSERT INTO business_product_channel_visibility(site_id,product_id,channel,status,starts_at) VALUES(1,?,'pos','active','2999-01-01 00:00:00')", [$gourdeProductId]);
    $futureVisibility = $service->calculateVariantSellability($gourdeVariantId, 'pos');
    $futureCodes = array_map(static fn(array $issue): string => (string) $issue['code'], $futureVisibility['missing']);
    $h->assertSame(false, $futureVisibility['is_sellable'], 'future visibility period blocks current publication');
    $h->assertTrue(in_array('channel_visibility_inactive', $futureCodes, true), 'visibility failure is explicitly explained');
    $h->expectException(
        fn() => $db->run("INSERT INTO business_product_channel_visibility(site_id,product_id,channel,status) VALUES(2,?,'ecommerce','active')", [$gourdeProductId]),
        PDOException::class,
        'channel visibility cannot target a product from another site'
    );
    $db->run('DELETE FROM business_product_channel_visibility WHERE product_id = ?', [$gourdeProductId]);

    $db->run("INSERT INTO business_attribute_groups(site_id, code, name) VALUES(1, 'required_specs', 'Spécifications requises')");
    $requiredGroupId = (int) $db->lastInsertId();
    $db->run(
        "INSERT INTO business_attributes(site_id, group_id, code, name, data_type, is_required, validation_json) VALUES(1, ?, 'required_material', 'Matière requise', 'select', 1, '{}')",
        [$requiredGroupId]
    );
    $requiredAttributeId = (int) $db->lastInsertId();
    $db->run("INSERT INTO business_attribute_options(attribute_id, code, label, value) VALUES(?, 'steel', 'Acier', 'steel')", [$requiredAttributeId]);
    $db->run('INSERT INTO business_product_attribute_group_links(product_id, group_id, sort_order) VALUES(?, ?, 10)', [$gourdeProductId, $requiredGroupId]);

    $missingRequiredAttribute = $service->calculateProductScore($gourdeProductId, 'pos');
    $missingCodes = array_map(static fn(array $issue): string => (string) $issue['code'], $missingRequiredAttribute['missing']);
    $h->assertTrue(in_array('required_variant_attribute_missing_required_material', $missingCodes, true), 'required variant attribute missing is part of completeness');
    $h->assertTrue((int) $missingRequiredAttribute['score'] < 100, 'product score is lowered when a variant required attribute is missing');

    $db->run("INSERT INTO business_product_attribute_values(product_id, attribute_id, language, value_text) VALUES(?, ?, 'und', 'steel')", [$gourdeProductId, $requiredAttributeId]);
    $productValueOnly = $service->calculateVariantSellability($gourdeVariantId, 'pos');
    $missingCodes = array_map(static fn(array $issue): string => (string) $issue['code'], $productValueOnly['missing']);
    $h->assertSame(false, in_array('required_variant_attribute_missing_required_material', $missingCodes, true), 'required variant attribute can inherit product-level value');

    $db->run("INSERT INTO business_variant_attribute_values(variant_id, attribute_id, language, value_text) VALUES(?, ?, 'und', 'steel')", [$gourdeVariantId, $requiredAttributeId]);
    $withRequiredAttributes = $service->calculateVariantSellability($gourdeVariantId, 'pos');
    $missingCodes = array_map(static fn(array $issue): string => (string) $issue['code'], $withRequiredAttributes['missing']);
    $h->assertSame(false, in_array('required_variant_attribute_missing_required_material', $missingCodes, true), 'required variant attribute is satisfied by a variant value');
    $withRequiredProduct = $service->calculateProductScore($gourdeProductId, 'pos');
    $h->assertTrue((int) $withRequiredProduct['score'] >= 90, 'product score recovers when product and variant required attributes are present');

    $db->run('UPDATE business_product_assets SET archived_at = CURRENT_TIMESTAMP WHERE product_id = ?', [$gourdeProductId]);
    $withoutImage = $service->calculateProductScore($gourdeProductId, 'ecommerce');
    $warningCodes = array_map(static fn(array $issue): string => (string) $issue['code'], $withoutImage['warnings']);
    $h->assertTrue(in_array('main_image_missing', $warningCodes, true), 'product without image produces an image warning');

    $db->run('DELETE FROM business_product_base_prices WHERE product_id = ? AND price_kind = "sale"', [$gourdeProductId]);
    $withoutPrice = $service->calculateVariantSellability($gourdeVariantId, 'pos');
    $missingCodes = array_map(static fn(array $issue): string => (string) $issue['code'], $withoutPrice['missing']);
    $h->assertSame(false, $withoutPrice['is_sellable'], 'variant without sale price is blocked');
    $h->assertTrue(in_array('sale_price_missing', $missingCodes, true), 'variant without sale price has explicit reason');
    $db->run("INSERT INTO business_product_base_prices(product_id, price_kind, currency, amount, tax_included) VALUES(?, 'sale', 'CHF', 29.00, 1)", [$gourdeProductId]);

    $db->run('UPDATE business_products SET is_pos_enabled = 0 WHERE id = ?', [$gourdeProductId]);
    $disabledChannel = $service->calculateVariantSellability($gourdeVariantId, 'pos');
    $missingCodes = array_map(static fn(array $issue): string => (string) $issue['code'], $disabledChannel['missing']);
    $h->assertSame(false, $disabledChannel['is_sellable'], 'disabled POS channel blocks sellability');
    $h->assertTrue(in_array('channel_pos_disabled', $missingCodes, true), 'disabled POS channel reason is explicit');
    $db->run('UPDATE business_products SET is_pos_enabled = 1 WHERE id = ?', [$gourdeProductId]);

    $serviceWithoutStock = $service->calculateVariantSellability($serviceVariantId, 'pos');
    $h->assertSame(true, $serviceWithoutStock['is_sellable'], 'service without tracked stock remains sellable');
    $h->assertSame(false, $serviceWithoutStock['stock']['track_stock'], 'service variant is not stock tracked');

    $db->run('UPDATE business_product_variants SET stock_quantity = 0, stock_reserved = 0, allow_backorder = 0 WHERE id = ?', [$gourdeVariantId]);
    $stockBlocked = $service->calculateVariantSellability($gourdeVariantId, 'pos');
    $missingCodes = array_map(static fn(array $issue): string => (string) $issue['code'], $stockBlocked['missing']);
    $h->assertSame(false, $stockBlocked['is_sellable'], 'physical product with zero stock is not sellable');
    $h->assertTrue(in_array('stock_unavailable', $missingCodes, true), 'zero stock reason is explicit');
    $db->run('UPDATE business_product_variants SET allow_backorder = 1 WHERE id = ?', [$gourdeVariantId]);
    $backorderAllowed = $service->calculateVariantSellability($gourdeVariantId, 'pos');
    $h->assertSame(true, $backorderAllowed['is_sellable'], 'physical product with backorder remains sellable');

    $h->expectException(
        fn() => $db->run("INSERT INTO business_product_variants(product_id, status, sku, name) VALUES(?, 'active', '', 'SKU vide')", [$gourdeProductId]),
        PDOException::class,
        'schema prevents empty SKU before sellability calculation'
    );

    $service->recalculateProduct($gourdeProductId);
    $stored = $service->storedProductCompleteness($gourdeProductId);
    $channels = array_map(static fn(array $score): string => (string) $score['channel'], $stored['scores']);
    $h->assertTrue(in_array('all', $channels, true), 'recalculation stores global list summary');
    $h->assertTrue(in_array('pos', $channels, true), 'recalculation stores POS score');
    $h->assertTrue(in_array('ecommerce', $channels, true), 'recalculation stores e-commerce score');

    $tshirtProductId = (int) ($db->one("SELECT id FROM business_products WHERE slug = 't-shirt-demo' LIMIT 1")['id'] ?? 0);
    $service->recalculateProduct($tshirtProductId);
    $tshirtSummary = $db->one('SELECT score, is_sellable FROM business_product_completeness_scores WHERE product_id = ? AND variant_id IS NULL AND channel = "all"', [$tshirtProductId]);
    $h->assertSame(100, (int) ($tshirtSummary['score'] ?? 0), 'fully attributed T-shirt product has complete global score');
    $h->assertSame(1, (int) ($tshirtSummary['is_sellable'] ?? 0), 'fully attributed T-shirt product is globally sellable');
    $tshirtScores = $db->all(
        'SELECT v.sku, s.is_sellable
         FROM business_product_completeness_scores s
         INNER JOIN business_product_variants v ON v.id = s.variant_id
         WHERE s.product_id = ? AND s.channel = "pos"
         ORDER BY v.sku ASC',
        [$tshirtProductId]
    );
    $h->assertSame(
        ['TSHIRT-DEMO-L-BLUE', 'TSHIRT-DEMO-M-BLACK', 'TSHIRT-DEMO-M-BLUE'],
        array_map(static fn(array $row): string => (string) $row['sku'], $tshirtScores),
        'recalculation stores all T-shirt POS variant scores'
    );
    $h->assertSame(
        [1, 1, 1],
        array_map(static fn(array $row): int => (int) $row['is_sellable'], $tshirtScores),
        'T-shirt variant completeness can inherit product-level material'
    );

    $partialVariantId = (int) ($db->one("SELECT id FROM business_product_variants WHERE sku = 'TSHIRT-DEMO-L-BLUE' LIMIT 1")['id'] ?? 0);
    $db->run('DELETE FROM business_variant_attribute_values WHERE variant_id = ? AND attribute_id IN (SELECT id FROM business_attributes WHERE code IN ("taille", "couleur"))', [$partialVariantId]);
    $partialTshirt = $service->calculateProductScore($tshirtProductId, 'admin');
    $partialMissingCodes = array_map(static fn(array $issue): string => (string) $issue['code'], $partialTshirt['missing']);
    $h->assertTrue(in_array('required_variant_attribute_missing_taille', $partialMissingCodes, true), 'partial T-shirt score identifies missing size on a variant');
    $h->assertTrue(in_array('required_variant_attribute_missing_couleur', $partialMissingCodes, true), 'partial T-shirt score identifies missing color on a variant');
    $h->assertTrue((int) $partialTshirt['score'] > 0, 'partial T-shirt attribution does not collapse to 0 percent');
    $h->assertTrue((int) $partialTshirt['score'] < 100, 'partial T-shirt attribution is still incomplete');
} finally {
    $db = null;
    gc_collect_cycles();
    test_remove_tree($dir);
}

exit($h->finish('UNIT business product completeness service'));
