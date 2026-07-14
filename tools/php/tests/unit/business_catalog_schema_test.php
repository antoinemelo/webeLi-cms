<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

$h = new TestHarness();
$schemaPath = __DIR__ . '/../../../../database/modules/business.sql';
$crmSchemaPath = __DIR__ . '/../../../../database/migrations/business/0001_init.sql';
$migrationPath = __DIR__ . '/../../../../database/migrations/business/0003_catalog_schema.sql';
$demoSeedPath = __DIR__ . '/../../../../database/migrations/business/0004_catalog_demo_seed.sql';
[$dir, $dbPath, $db] = test_temp_cms_db($crmSchemaPath);

try {
    $catalogSchema = file_get_contents($migrationPath);
    $h->assertTrue($catalogSchema !== false, 'catalog schema migration is readable');
    $db->pdo()->exec((string) $catalogSchema);

    $demoSeed = file_get_contents($demoSeedPath);
    $h->assertTrue($demoSeed !== false, 'catalog demo seed migration is readable');
    $h->assertTrue(!str_contains(strtoupper((string) $demoSeed), 'DROP TABLE'), 'catalog demo seed does not drop tables');
    $db->pdo()->exec((string) $demoSeed);

    $requiredTables = [
        'business_product_brands',
        'business_product_categories',
        'business_tax_classes',
        'business_products',
        'business_product_options',
        'business_product_option_values',
        'business_product_option_links',
        'business_product_variants',
        'business_product_variant_option_values',
        'business_product_base_prices',
        'business_product_variant_price_adjustments',
        'business_catalog_discounts',
        'business_product_bundles',
        'business_bundle_components',
        'business_stock_movements',
        'business_product_assets',
        'business_product_tags',
        'business_product_tag_links',
    ];
    foreach ($requiredTables as $table) {
        $h->assertTrue($db->tableExists($table), $table . ' exists');
    }
    $nativeSchema = file_get_contents($schemaPath);
    $h->assertTrue($nativeSchema !== false, 'native business schema is readable');
    $h->assertTrue(str_contains((string) $nativeSchema, 'CREATE TABLE IF NOT EXISTS business_companies'), 'native business schema keeps CRM companies table');
    $h->assertTrue(str_contains((string) $nativeSchema, 'CREATE TABLE IF NOT EXISTS crm_memos'), 'native business schema keeps CRM memos table');
    foreach ($requiredTables as $table) {
        $h->assertTrue(str_contains((string) $nativeSchema, 'CREATE TABLE IF NOT EXISTS ' . $table), $table . ' exists in native business schema');
    }

    $integrity = $db->one('PRAGMA integrity_check');
    $h->assertSame('ok', (string) array_values($integrity ?? [''])[0], 'catalog schema passes SQLite integrity check');

    $fkViolations = $db->all('PRAGMA foreign_key_check');
    $h->assertSame(0, count($fkViolations), 'catalog demo data has no foreign key violation');
    $orphanProductAttributes = $db->one(
        'SELECT COUNT(*) AS count
         FROM business_product_attribute_values v
         INNER JOIN business_attributes a ON a.id = v.attribute_id
         LEFT JOIN business_product_attribute_group_links l ON l.product_id = v.product_id AND l.group_id = a.group_id
         WHERE a.group_id IS NOT NULL AND l.product_id IS NULL'
    );
    $h->assertSame(0, (int) ($orphanProductAttributes['count'] ?? -1), 'seed product attributes always belong to a linked group');
    $orphanVariantAttributes = $db->one(
        'SELECT COUNT(*) AS count
         FROM business_variant_attribute_values av
         INNER JOIN business_attributes a ON a.id = av.attribute_id
         INNER JOIN business_product_variants v ON v.id = av.variant_id
         LEFT JOIN business_product_attribute_group_links l ON l.product_id = v.product_id AND l.group_id = a.group_id
         WHERE a.group_id IS NOT NULL AND l.product_id IS NULL'
    );
    $h->assertSame(0, (int) ($orphanVariantAttributes['count'] ?? -1), 'seed variant attributes always belong to a linked group');

    $requiredIndexes = [
        'idx_business_product_categories_root_slug',
        'idx_business_product_variants_sku_active',
        'idx_business_products_site_status',
        'idx_business_products_brand',
        'idx_business_products_category',
        'idx_business_product_variants_barcode',
        'idx_business_catalog_discounts_scope',
        'idx_business_catalog_discounts_site_channel',
        'idx_business_product_bundles_product_active',
        'idx_business_product_bundles_variant_active',
        'idx_business_bundle_components_bundle',
        'idx_business_bundle_components_product',
        'idx_business_stock_movements_variant',
    ];
    foreach ($requiredIndexes as $index) {
        $h->assertTrue($db->one('SELECT name FROM sqlite_master WHERE type = "index" AND name = ?', [$index]) !== null, $index . ' index exists');
    }

    $h->expectException(
        fn() => $db->run("INSERT INTO business_products(site_id, type, status, visibility, name, slug) VALUES(1, 'bad_type', 'draft', 'internal', 'Bad Type', 'bad-type')"),
        PDOException::class,
        'product type enum is enforced'
    );
    $h->expectException(
        fn() => $db->run("INSERT INTO business_products(site_id, brand_id, type, status, visibility, name, slug) VALUES(1, 99999, 'physical', 'draft', 'internal', 'Bad FK', 'bad-fk')"),
        PDOException::class,
        'product brand foreign key is enforced'
    );
    $db->run("INSERT INTO business_products(site_id, type, status, visibility, name, slug) VALUES(1, 'physical', 'draft', 'internal', 'Unique Product', 'unique-product')");
    $productId = $db->lastInsertId();
    $h->expectException(
        fn() => $db->run("INSERT INTO business_products(site_id, type, status, visibility, name, slug) VALUES(1, 'physical', 'draft', 'internal', 'Duplicate Slug', 'unique-product')"),
        PDOException::class,
        'product slug uniqueness is enforced per site'
    );
    $db->run("INSERT INTO business_product_variants(product_id, status, sku, name) VALUES(?, 'active', 'UNIQUE-SKU', 'Unique SKU')", [$productId]);
    $h->expectException(
        fn() => $db->run("INSERT INTO business_product_variants(product_id, status, sku, name) VALUES(?, 'active', 'UNIQUE-SKU', 'Duplicate SKU')", [$productId]),
        PDOException::class,
        'variant SKU uniqueness is enforced'
    );

    $brand = $db->one('SELECT id FROM business_product_brands WHERE site_id = 1 AND slug = ?', ['demo-outdoor']);
    $h->assertTrue($brand !== null, 'demo brand exists');

    $categories = $db->all('SELECT slug FROM business_product_categories WHERE site_id = 1 ORDER BY slug');
    $h->assertTrue(count($categories) >= 2, 'demo categories exist');

    $products = $db->all('SELECT type FROM business_products WHERE site_id = 1 AND slug IN (?, ?, ?) ORDER BY type', ['vol-decouverte', 'gourde-demo', 'bon-cadeau-demo']);
    $h->assertSame(['gift_card', 'physical', 'service'], array_map(static fn(array $row): string => (string) $row['type'], $products), 'demo product, service and gift card exist');

    $prices = $db->all(
        'SELECT bp.price_kind
         FROM business_product_base_prices bp
         INNER JOIN business_products p ON p.id = bp.product_id
         WHERE p.slug = ?
         ORDER BY bp.price_kind',
        ['vol-decouverte']
    );
    $h->assertSame(['purchase', 'sale'], array_map(static fn(array $row): string => (string) $row['price_kind'], $prices), 'demo service has purchase and sale base prices');

    $variant = $db->one('SELECT id FROM business_product_variants WHERE sku = ?', ['DEMO-VOL-PREMIUM-40']);
    $h->assertTrue($variant !== null, 'demo adjusted variant exists');

    $adjustments = $db->all(
        'SELECT price_kind, adjustment_type
         FROM business_product_variant_price_adjustments
         WHERE variant_id = ?
         ORDER BY price_kind',
        [(int) ($variant['id'] ?? 0)]
    );
    $h->assertSame(['purchase:percent_delta', 'sale:amount_delta'], array_map(static fn(array $row): string => $row['price_kind'] . ':' . $row['adjustment_type'], $adjustments), 'demo variant has separate purchase and sale adjustments');

    $discount = $db->one('SELECT discount_type, scope_type, channel FROM business_catalog_discounts WHERE name = ?', ['Lancement POS']);
    $h->assertSame('percent', (string) ($discount['discount_type'] ?? ''), 'demo discount is percent');
    $h->assertSame('product', (string) ($discount['scope_type'] ?? ''), 'demo discount targets product');
    $h->assertSame('pos', (string) ($discount['channel'] ?? ''), 'demo discount targets POS channel');

    $db->run("INSERT INTO business_products(site_id, type, status, visibility, sku_base, name, slug, track_stock, allow_backorder, is_public, is_ecommerce_enabled, is_pos_enabled, is_catalogue_enabled) VALUES(1, 'bundle', 'draft', 'public', 'BUNDLE-DEMO', 'Bundle demo', 'bundle-demo', 0, 0, 1, 1, 1, 1)");
    $bundleProduct = $db->one("SELECT id, type FROM business_products WHERE slug = 'bundle-demo'");
    $h->assertSame('bundle', (string) ($bundleProduct['type'] ?? ''), 'bundle product type can identify a distinct sellable bundle');
    $bundleProductId = (int) ($bundleProduct['id'] ?? 0);
    $componentProductId = (int) ($db->one("SELECT id FROM business_products WHERE slug = 'bon-cadeau-demo'")['id'] ?? 0);
    $componentVariantId = (int) ($db->one("SELECT id FROM business_product_variants WHERE sku = 'DEMO-GIFT-100'")['id'] ?? 0);
    $db->run('INSERT INTO business_product_bundles(site_id, bundle_product_id, pricing_mode, stock_mode, is_active) VALUES(1, ?, "fixed", "components", 1)', [$bundleProductId]);
    $bundleId = (int) $db->lastInsertId();
    $db->run('INSERT INTO business_bundle_components(bundle_id, component_product_id, component_variant_id, quantity, is_required) VALUES(?, ?, ?, 2, 1)', [$bundleId, $componentProductId, $componentVariantId]);
    $h->assertTrue(str_contains((string) $nativeSchema, "stock_strategy TEXT NOT NULL DEFAULT 'COMPONENT_DERIVED'"), 'canonical bundle schema defaults to an explicit component-derived stock strategy');
    $h->assertTrue(str_contains((string) $nativeSchema, "partial_availability_policy TEXT NOT NULL DEFAULT 'REQUIRE_ALL'"), 'canonical bundle schema defaults to complete availability');
    $h->assertTrue(str_contains((string) $nativeSchema, "component_return_policy TEXT NOT NULL DEFAULT 'BUNDLE_ONLY'"), 'canonical bundle schema defaults to whole-bundle returns');
    $bundleComponent = $db->one('SELECT quantity FROM business_bundle_components WHERE bundle_id = ?', [$bundleId]);
    $h->assertSame(2.0, (float) ($bundleComponent['quantity'] ?? 0), 'bundle component stores positive quantity');
    $h->expectException(
        fn() => $db->run('INSERT INTO business_bundle_components(bundle_id, component_product_id, quantity) VALUES(?, ?, 0)', [$bundleId, $componentProductId]),
        PDOException::class,
        'bundle component quantity must be positive'
    );

    $point06Brand = $db->one('SELECT name FROM business_product_brands WHERE site_id = 1 AND slug = ?', ['nouvelle-marque']);
    $h->assertSame('NOUVELLE MARQUE', (string) ($point06Brand['name'] ?? ''), 'point 06 demo brand exists');
    $point06Categories = $db->all('SELECT slug FROM business_product_categories WHERE site_id = 1 AND slug IN (?, ?) ORDER BY slug', ['services', 'marchandises']);
    $h->assertSame(['marchandises', 'services'], array_map(static fn(array $row): string => (string) $row['slug'], $point06Categories), 'point 06 demo categories exist');
    $point06Products = $db->all('SELECT type FROM business_products WHERE site_id = 1 AND slug IN (?, ?, ?) ORDER BY type', ['t-shirt-demo', 'consultation', 'bon-cadeau-simple']);
    $h->assertSame(['gift_card', 'physical', 'service'], array_map(static fn(array $row): string => (string) $row['type'], $point06Products), 'point 06 demo merchandise, service and gift card exist');
    $tshirtOptionLinks = $db->one(
        'SELECT COUNT(*) AS count
         FROM business_product_option_links l
         INNER JOIN business_products p ON p.id = l.product_id
         WHERE p.slug = ?',
        ['t-shirt-demo']
    );
    $h->assertSame(0, (int) ($tshirtOptionLinks['count'] ?? -1), 'T-shirt demo has no duplicate product option axes');
    $tshirtVariantOptions = $db->one(
        'SELECT COUNT(*) AS count
         FROM business_product_variant_option_values ov
         INNER JOIN business_product_variants v ON v.id = ov.variant_id
         WHERE v.sku LIKE ?',
        ['TSHIRT-DEMO-%']
    );
    $h->assertSame(0, (int) ($tshirtVariantOptions['count'] ?? -1), 'T-shirt demo variants use grouped PIM attributes instead of option values');
    $point06Variants = $db->all('SELECT sku FROM business_product_variants WHERE sku LIKE ? ORDER BY sku', ['TSHIRT-DEMO-%']);
    $h->assertSame(['TSHIRT-DEMO-L-BLUE', 'TSHIRT-DEMO-M-BLACK', 'TSHIRT-DEMO-M-BLUE'], array_map(static fn(array $row): string => (string) $row['sku'], $point06Variants), 'point 06 demo variants exist');
    $point06VariantAttributes = $db->all(
        'SELECT v.sku, a.code, av.value_text
         FROM business_variant_attribute_values av
         INNER JOIN business_product_variants v ON v.id = av.variant_id
         INNER JOIN business_attributes a ON a.id = av.attribute_id
         WHERE v.sku LIKE ?
         ORDER BY v.sku, a.code',
        ['TSHIRT-DEMO-%']
    );
    $h->assertSame(
        [
            'TSHIRT-DEMO-L-BLUE:couleur:Bleu',
            'TSHIRT-DEMO-L-BLUE:taille:L',
            'TSHIRT-DEMO-M-BLACK:couleur:Noir',
            'TSHIRT-DEMO-M-BLACK:taille:M',
            'TSHIRT-DEMO-M-BLUE:couleur:Bleu',
            'TSHIRT-DEMO-M-BLUE:taille:M',
        ],
        array_map(static fn(array $row): string => $row['sku'] . ':' . $row['code'] . ':' . $row['value_text'], $point06VariantAttributes),
        'T-shirt demo variant axes are stored as Textile attributes'
    );
    $point06Adjustments = $db->all(
        'SELECT v.sku, a.price_kind, a.adjustment_type, a.adjustment_value
         FROM business_product_variant_price_adjustments a
         INNER JOIN business_product_variants v ON v.id = a.variant_id
         WHERE v.sku LIKE ?
         ORDER BY v.sku, a.price_kind',
        ['TSHIRT-DEMO-%']
    );
    $h->assertSame(
        [
            'TSHIRT-DEMO-L-BLUE:purchase:amount_delta:2',
            'TSHIRT-DEMO-L-BLUE:sale:amount_delta:5',
            'TSHIRT-DEMO-M-BLACK:purchase:percent_delta:15',
            'TSHIRT-DEMO-M-BLACK:sale:percent_delta:25',
        ],
        array_map(static fn(array $row): string => $row['sku'] . ':' . $row['price_kind'] . ':' . $row['adjustment_type'] . ':' . (string) (float) $row['adjustment_value'], $point06Adjustments),
        'point 06 demo variant adjustments match prompt'
    );
} finally {
    $db = null;
    gc_collect_cycles();
    test_remove_tree($dir);
}

exit($h->finish('UNIT business catalog schema'));
