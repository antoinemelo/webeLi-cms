<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

$h = new TestHarness();
$schemaPath = __DIR__ . '/../../../../database/modules/business.sql';
$migrationPath = __DIR__ . '/../../../../database/migrations/business/0003_catalog_schema.sql';
$demoSeedPath = __DIR__ . '/../../../../database/migrations/business/0004_catalog_demo_seed.sql';
[$dir, $dbPath, $db] = test_temp_cms_db($migrationPath);

try {
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
        'business_stock_movements',
        'business_product_media',
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

    $requiredIndexes = [
        'idx_business_product_categories_root_slug',
        'idx_business_product_variants_sku_active',
        'idx_business_products_site_status',
        'idx_business_products_brand',
        'idx_business_products_category',
        'idx_business_product_variants_barcode',
        'idx_business_catalog_discounts_scope',
        'idx_business_catalog_discounts_site_channel',
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

    $point06Brand = $db->one('SELECT name FROM business_product_brands WHERE site_id = 1 AND slug = ?', ['nouvelle-marque']);
    $h->assertSame('NOUVELLE MARQUE', (string) ($point06Brand['name'] ?? ''), 'point 06 demo brand exists');
    $point06Categories = $db->all('SELECT slug FROM business_product_categories WHERE site_id = 1 AND slug IN (?, ?) ORDER BY slug', ['services', 'marchandises']);
    $h->assertSame(['marchandises', 'services'], array_map(static fn(array $row): string => (string) $row['slug'], $point06Categories), 'point 06 demo categories exist');
    $point06Products = $db->all('SELECT type FROM business_products WHERE site_id = 1 AND slug IN (?, ?, ?) ORDER BY type', ['t-shirt-demo', 'consultation', 'bon-cadeau-simple']);
    $h->assertSame(['gift_card', 'physical', 'service'], array_map(static fn(array $row): string => (string) $row['type'], $point06Products), 'point 06 demo merchandise, service and gift card exist');
    $point06Options = $db->all('SELECT code FROM business_product_options WHERE site_id = 1 AND code IN (?, ?, ?) ORDER BY code', ['model', 'size', 'color']);
    $h->assertSame(['color', 'model', 'size'], array_map(static fn(array $row): string => (string) $row['code'], $point06Options), 'point 06 demo options exist');
    $point06Variants = $db->all('SELECT sku FROM business_product_variants WHERE sku LIKE ? ORDER BY sku', ['TSHIRT-DEMO-%']);
    $h->assertSame(['TSHIRT-DEMO-CLASSIC-L-BLUE', 'TSHIRT-DEMO-CLASSIC-M-BLUE', 'TSHIRT-DEMO-PREMIUM-M-BLACK'], array_map(static fn(array $row): string => (string) $row['sku'], $point06Variants), 'point 06 demo variants exist');
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
            'TSHIRT-DEMO-CLASSIC-L-BLUE:purchase:amount_delta:2',
            'TSHIRT-DEMO-CLASSIC-L-BLUE:sale:amount_delta:5',
            'TSHIRT-DEMO-PREMIUM-M-BLACK:purchase:percent_delta:15',
            'TSHIRT-DEMO-PREMIUM-M-BLACK:sale:percent_delta:25',
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
