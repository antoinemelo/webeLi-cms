<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

$h = new TestHarness();
$schemaPath = __DIR__ . '/../../../../database/modules/business.sql';
[$dir, $dbPath, $db] = test_temp_cms_db($schemaPath);

try {
    $requiredTables = [
        'business_product_assets',
        'business_asset_metadata',
        'business_asset_renditions',
        'business_attribute_groups',
        'business_product_attribute_group_links',
        'business_attributes',
        'business_attribute_options',
        'business_product_attribute_values',
        'business_variant_attribute_values',
        'business_product_completeness_rules',
        'business_product_completeness_scores',
        'business_product_relations',
    ];
    foreach ($requiredTables as $table) {
        $h->assertTrue($db->tableExists($table), $table . ' exists in native business schema');
    }

    $nativeSchema = file_get_contents($schemaPath);
    $h->assertTrue($nativeSchema !== false, 'native business schema is readable');
    foreach ($requiredTables as $table) {
        $h->assertTrue(str_contains((string) $nativeSchema, 'CREATE TABLE IF NOT EXISTS ' . $table), $table . ' exists in native business schema');
    }

    $expectedColumns = [
        'business_product_assets' => ['site_id', 'product_id', 'variant_id', 'media_id', 'role', 'channel_scope', 'is_public'],
        'business_asset_metadata' => ['site_id', 'media_id', 'asset_type', 'usage_rights', 'metadata_json'],
        'business_asset_renditions' => ['site_id', 'media_id', 'channel', 'rendition_key', 'generated_media_id'],
        'business_attributes' => ['site_id', 'group_id', 'code', 'data_type', 'validation_json', 'is_public'],
        'business_product_attribute_group_links' => ['product_id', 'group_id', 'sort_order'],
        'business_product_completeness_scores' => ['product_id', 'variant_id', 'channel', 'score', 'is_sellable', 'missing_json'],
    ];
    foreach ($expectedColumns as $table => $columns) {
        $actual = array_map(static fn(array $row): string => (string) $row['name'], $db->all('PRAGMA table_info(' . $table . ')'));
        foreach ($columns as $column) {
            $h->assertTrue(in_array($column, $actual, true), $table . '.' . $column . ' column exists');
        }
    }

    $requiredIndexes = [
        'idx_business_product_assets_product',
        'idx_business_product_assets_variant',
        'idx_business_product_assets_main_product',
        'idx_business_product_assets_main_variant',
        'idx_business_asset_metadata_media',
        'idx_business_asset_renditions_media',
        'idx_business_attributes_group',
        'idx_business_product_attribute_group_links_group',
        'idx_business_product_completeness_rules_scope',
        'idx_business_product_completeness_scores_sellable',
        'idx_business_product_relations_product',
    ];
    foreach ($requiredIndexes as $index) {
        $h->assertTrue($db->one('SELECT name FROM sqlite_master WHERE type = "index" AND name = ?', [$index]) !== null, $index . ' index exists');
    }

    $db->run("INSERT INTO business_products(site_id, type, status, visibility, name, slug) VALUES(1, 'physical', 'active', 'public', 'PIM Product', 'pim-product')");
    $productId = $db->lastInsertId();
    $db->run("INSERT INTO business_product_variants(product_id, status, sku, name) VALUES(?, 'active', 'PIM-SKU-1', 'PIM Variant')", [$productId]);
    $variantId = $db->lastInsertId();

    $db->run(
        "INSERT INTO business_product_assets(site_id, product_id, media_id, role, channel_scope, is_public) VALUES(1, ?, 1001, 'main', 'pos', 1)",
        [$productId]
    );
    $h->expectException(
        fn() => $db->run("INSERT INTO business_product_assets(site_id, product_id, media_id, role, channel_scope) VALUES(1, ?, 1002, 'main', 'pos')", [$productId]),
        PDOException::class,
        'only one active main product asset is allowed per channel'
    );
    $db->run(
        "INSERT INTO business_product_assets(site_id, product_id, variant_id, media_id, role, channel_scope, is_public) VALUES(1, ?, ?, 1003, 'main', 'pos', 1)",
        [$productId, $variantId]
    );

    $db->run("INSERT INTO business_asset_metadata(site_id, media_id, asset_type, usage_rights, metadata_json) VALUES(1, 1001, 'image', 'owned', '{}')");
    $h->expectException(
        fn() => $db->run("INSERT INTO business_asset_metadata(site_id, media_id, asset_type, usage_rights, metadata_json) VALUES(1, 1001, 'image', 'owned', '{}')"),
        PDOException::class,
        'asset metadata is unique per site/media'
    );

    $db->run("INSERT INTO business_attribute_groups(site_id, code, name) VALUES(1, 'dimensions', 'Dimensions')");
    $groupId = $db->lastInsertId();
    $db->run(
        "INSERT INTO business_attributes(site_id, group_id, code, name, data_type, is_required, validation_json) VALUES(1, ?, 'material', 'Matiere', 'select', 1, '{}')",
        [$groupId]
    );
    $attributeId = $db->lastInsertId();
    $db->run("INSERT INTO business_attribute_options(attribute_id, code, label, value) VALUES(?, 'cotton', 'Coton', 'cotton')", [$attributeId]);
    $db->run("INSERT INTO business_product_attribute_group_links(product_id, group_id, sort_order) VALUES(?, ?, 10)", [$productId, $groupId]);
    $db->run("INSERT INTO business_product_attribute_values(product_id, attribute_id, language, value_text) VALUES(?, ?, 'fr', 'Coton')", [$productId, $attributeId]);
    $db->run("INSERT INTO business_variant_attribute_values(variant_id, attribute_id, language, value_text) VALUES(?, ?, 'fr', 'Coton bleu')", [$variantId, $attributeId]);

    $h->expectException(
        fn() => $db->run("INSERT INTO business_attributes(site_id, code, name, data_type) VALUES(1, 'nogroup', 'No Group', 'text')"),
        PDOException::class,
        'attribute group is required'
    );
    $h->expectException(
        fn() => $db->run("INSERT INTO business_attributes(site_id, group_id, code, name, data_type) VALUES(1, ?, 'badtype', 'Bad Type', 'object')", [$groupId]),
        PDOException::class,
        'attribute data_type enum is enforced'
    );
    $h->expectException(
        fn() => $db->run("INSERT INTO business_product_assets(site_id, product_id, media_id, role, channel_scope) VALUES(1, ?, 1004, 'poster', 'pos')", [$productId]),
        PDOException::class,
        'asset role enum is enforced'
    );

    $db->run("INSERT INTO business_product_completeness_rules(site_id, code, name, scope, required_field, channel, weight) VALUES(1, 'main-image', 'Image principale', 'asset', 'main_asset', 'pos', 2)");
    $db->run("INSERT INTO business_product_completeness_scores(product_id, variant_id, channel, score, is_sellable, missing_json) VALUES(?, ?, 'pos', 85, 1, '[]')", [$productId, $variantId]);
    $score = $db->one('SELECT score, is_sellable FROM business_product_completeness_scores WHERE variant_id = ?', [$variantId]);
    $h->assertSame(85, (int) ($score['score'] ?? 0), 'completeness score stores score');
    $h->assertSame(1, (int) ($score['is_sellable'] ?? 0), 'completeness score stores is_sellable');
    $h->expectException(
        fn() => $db->run("INSERT INTO business_product_completeness_scores(product_id, channel, score, is_sellable, missing_json) VALUES(?, 'pos', 120, 1, '[]')", [$productId]),
        PDOException::class,
        'completeness score bounds are enforced'
    );

    $db->run("INSERT INTO business_products(site_id, type, status, visibility, name, slug) VALUES(1, 'physical', 'active', 'public', 'Related PIM Product', 'related-pim-product')");
    $relatedProductId = $db->lastInsertId();
    $db->run("INSERT INTO business_product_relations(site_id, product_id, related_product_id, relation_type) VALUES(1, ?, ?, 'upsell')", [$productId, $relatedProductId]);
    $h->expectException(
        fn() => $db->run("INSERT INTO business_product_relations(site_id, product_id, related_product_id, relation_type) VALUES(1, ?, ?, 'upsell')", [$productId, $productId]),
        PDOException::class,
        'product relation cannot target itself'
    );

    $integrity = $db->one('PRAGMA integrity_check');
    $h->assertSame('ok', (string) array_values($integrity ?? [''])[0], 'PIM-lite schema passes SQLite integrity check');
    $fkViolations = $db->all('PRAGMA foreign_key_check');
    $h->assertSame(0, count($fkViolations), 'PIM-lite schema has no foreign key violation');
} finally {
    $db = null;
    gc_collect_cycles();
    test_remove_tree($dir);
}

exit($h->finish('UNIT business PIM-lite schema'));
