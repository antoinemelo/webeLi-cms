<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Business\Catalog\CatalogPricingService;
use App\Modules\Business\Repositories\BusinessCatalogPricingRepository;
use App\Modules\Business\Repositories\CatalogBrandRepository;
use App\Modules\Business\Repositories\CatalogCategoryRepository;
use App\Modules\Business\Repositories\CatalogOptionRepository;
use App\Modules\Business\Repositories\CatalogProductRepository;
use App\Modules\Business\Repositories\CatalogVariantRepository;
use App\Modules\Business\Services\CatalogCsvService;

$h = new TestHarness();
[$dir, $path, $db] = test_temp_cms_db(__DIR__ . '/../../../../database/migrations/business/0001_init.sql');
$catalogSchema = file_get_contents(__DIR__ . '/../../../../database/migrations/business/0003_catalog_schema.sql');
if ($catalogSchema === false) {
    throw new RuntimeException('Unable to read business catalog schema.');
}
$db->pdo()->exec($catalogSchema);
$db->pdo()->exec((string) file_get_contents(__DIR__ . '/../../../../database/migrations/business/0007_pricing_offers_bundles.sql'));
$db->pdo()->exec((string) file_get_contents(__DIR__ . '/../../../../database/migrations/business/0008_pim_quality_import_channels.sql'));

try {
    $brands = new CatalogBrandRepository($db);
    $categories = new CatalogCategoryRepository($db);
    $products = new CatalogProductRepository($db);
    $variants = new CatalogVariantRepository($db);
    $options = new CatalogOptionRepository($db);
    $pricing = new CatalogPricingService(new BusinessCatalogPricingRepository($db));
    $csv = new CatalogCsvService($db, $brands, $categories, $products, $variants, $options, $pricing);

    $brand = $brands->create(1, ['name' => 'CSV Brand'], 1);
    $category = $categories->create(1, ['name' => 'CSV Category'], 1);
    $size = $options->create(1, ['code' => 'size', 'name' => 'Size'], 1);
    $options->addValue(1, (int) $size['id'], ['code' => 'm', 'label' => 'M', 'value' => 'm'], 1);
    $product = $products->create(1, [
        'name' => 'CSV Product',
        'slug' => 'csv-product',
        'brand_id' => $brand['id'],
        'category_id' => $category['id'],
        'status' => 'active',
        'channels' => ['public', 'ecommerce'],
        'base_purchase_price' => 40,
        'base_sale_price' => 100,
        'currency' => 'CHF',
        'option_ids' => [$size['id']],
    ], 1);
    $products->replaceOptionLinks(1, (int) $product['id'], [(int) $size['id']]);
    $variant = $variants->create(1, (int) $product['id'], [
        'sku' => 'CSV-PRODUCT-M',
        'name' => 'CSV Product M',
        'status' => 'active',
        'stock_quantity' => 7,
        'option_values' => ['size' => 'm'],
        'purchase_adjustment_type' => 'amount_delta',
        'purchase_adjustment_value' => 3,
        'sale_adjustment_type' => 'amount_delta',
        'sale_adjustment_value' => 5,
    ], 1);

    $fullExport = parse_catalog_csv($csv->exportProductsCsv(1, true));
    $header = $fullExport[0];
    $row = $fullExport[1];
    $h->assertSame('format_version', $header[0], 'export format is explicitly versioned');
    $h->assertSame('pim.catalog.v1', $row[0], 'export row declares stable format version');
    $basePurchaseIndex = array_search('base_purchase_price', $header, true);
    $computedPurchaseIndex = array_search('computed_purchase_price', $header, true);
    $variantOptionsIndex = array_search('variant_options', $header, true);
    $h->assertSame('40', $row[$basePurchaseIndex], 'privileged export includes base purchase price');
    $h->assertSame('43.00', $row[$computedPurchaseIndex], 'privileged export includes computed purchase price');
    $h->assertSame('size:m', $row[$variantOptionsIndex], 'export includes variant option mapping');

    $maskedExport = parse_catalog_csv($csv->exportProductsCsv(1, false));
    $masked = $maskedExport[1];
    $h->assertSame('', $masked[$basePurchaseIndex], 'limited export masks base purchase price');
    $h->assertSame('', $masked[$computedPurchaseIndex], 'limited export masks computed purchase price');

    $negative = catalog_import_csv([
        'product_name' => 'Bad Product',
        'product_slug' => 'bad-product',
        'variant_sku' => 'BAD-NEGATIVE',
        'base_sale_price' => '-1',
    ]);
    $negativePreview = $csv->importProductsCsv(1, $negative, ['dry_run' => '1', 'create_options' => '1'], 1);
    $h->assertSame(1, $negativePreview['skipped'], 'import dry-run detects invalid row');
    $h->assertSame('business.catalog.base_sale_price_negative', $negativePreview['errors'][0]['message'] ?? null, 'negative price error is explicit');

    $import = catalog_import_csv([
        'product_name' => 'Imported Product',
        'product_slug' => 'imported-product',
        'type' => 'physical',
        'status' => 'active',
        'brand' => 'Imported Brand',
        'category' => 'Imported Category',
        'sku_base' => 'IMP',
        'is_public' => '1',
        'is_ecommerce_enabled' => '1',
        'base_purchase_price' => '12.50',
        'base_sale_price' => '25',
        'currency' => 'CHF',
        'variant_sku' => 'IMPORT-V1',
        'variant_name' => 'Imported Variant',
        'variant_options' => 'format:box',
        'purchase_adjustment_type' => 'amount_delta',
        'purchase_adjustment_value' => '2.50',
        'sale_adjustment_type' => 'percent_delta',
        'sale_adjustment_value' => '10',
        'stock_quantity' => '5',
    ]);
    $applied = $csv->importProductsCsv(1, $import, [
        'dry_run' => '0',
        'create_brands' => '1',
        'create_categories' => '1',
        'create_options' => '1',
    ], 1);
    $h->assertSame(1, $applied['created_products'], 'real import creates product');
    $h->assertSame(1, $applied['created_variants'], 'real import creates variant');
    $imported = $db->one('SELECT * FROM business_products WHERE slug = ?', ['imported-product']);
    $h->assertTrue(is_array($imported), 'import writes product');
    $importedVariant = $db->one('SELECT * FROM business_product_variants WHERE sku = ?', ['IMPORT-V1']);
    $h->assertTrue(is_array($importedVariant), 'import writes variant');
    $saleAdjustment = $db->one('SELECT * FROM business_product_variant_price_adjustments WHERE variant_id = ? AND price_kind = "sale"', [(int) $importedVariant['id']]);
    $h->assertSame('percent_delta', $saleAdjustment['adjustment_type'] ?? null, 'import writes sale adjustment');
    $journal = $db->one('SELECT status, rows_total, changed_rows FROM business_catalog_import_runs WHERE id = ?', [(int) ($applied['journal']['run_id'] ?? 0)]);
    $h->assertSame('applied', $journal['status'] ?? null, 'applied import writes a synthetic journal');
    $h->assertSame(1, (int) ($journal['changed_rows'] ?? 0), 'journal records changed row count');

    $conflict = $csv->importProductsCsv(1, $import, [
        'dry_run' => '0',
        'create_brands' => '1',
        'create_categories' => '1',
        'create_options' => '1',
    ], 1);
    $h->assertSame(true, $conflict['replayed'] ?? false, 'exact reimport is idempotently replayed');
    $h->assertSame(false, $conflict['writes_performed'], 'idempotent reimport performs no writes');

    $noOp = $csv->importProductsCsv(1, $import, [
        'dry_run' => '0',
        'create_brands' => '1',
        'create_categories' => '1',
        'create_options' => '1',
        'idempotency_key' => 'same-content-new-run',
    ], 1);
    $h->assertSame(1, $noOp['unchanged_rows'], 'same content with another run key resolves to a no-op diff');
    $h->assertSame(false, $noOp['writes_performed'], 'no-op diff does not mutate the catalog');

    $overwrite = $csv->importProductsCsv(1, catalog_import_csv([
        'product_name' => 'Imported Product Updated',
        'product_slug' => 'imported-product',
        'status' => 'active',
        'variant_sku' => 'IMPORT-V1',
        'variant_name' => 'Imported Variant Updated',
        'variant_options' => 'format:box',
        'base_sale_price' => '30',
        'stock_quantity' => '9',
    ]), [
        'dry_run' => '0',
        'create_options' => '1',
        'overwrite_existing' => '1',
    ], 1);
    $h->assertSame(1, $overwrite['updated_products'], 'confirmed overwrite updates product');
    $updatedVariant = $db->one('SELECT * FROM business_product_variants WHERE sku = ?', ['IMPORT-V1']);
    $h->assertSame(5.0, (float) ($updatedVariant['stock_quantity'] ?? 0), 'confirmed overwrite preserves deprecated Business bootstrap stock');

    $diffPreview = $csv->importProductsCsv(1, catalog_import_csv([
        'product_name' => 'Imported Product Updated',
        'product_slug' => 'imported-product',
        'status' => 'active',
        'variant_sku' => 'IMPORT-V1',
        'variant_name' => 'Imported Variant Updated',
        'variant_options' => 'format:box',
        'base_sale_price' => '31',
        'stock_quantity' => '10',
    ]), ['dry_run' => '1', 'create_options' => '1', 'overwrite_existing' => '1'], 1);
    $h->assertTrue(isset($diffPreview['rows'][0]['diff']['product']['base_sale_price']), 'preview explains product price diff before mutation');
    $h->assertTrue(!isset($diffPreview['rows'][0]['diff']['variant']['stock_quantity']), 'preview excludes stock changes now owned by Sale');
    $h->assertSame(5.0, (float) ($db->one('SELECT stock_quantity FROM business_product_variants WHERE sku = ?', ['IMPORT-V1'])['stock_quantity'] ?? 0), 'dry-run and overwrite leave bootstrap stock unchanged');

    $partial = catalog_import_rows([
        ['product_name' => 'Atomic Valid', 'product_slug' => 'atomic-valid', 'variant_sku' => 'ATOMIC-VALID', 'base_sale_price' => '10'],
        ['product_name' => 'Atomic Invalid', 'product_slug' => 'atomic-invalid', 'variant_sku' => 'ATOMIC-INVALID', 'base_sale_price' => '-2'],
    ]);
    $partialResult = $csv->importProductsCsv(1, $partial, ['dry_run' => '0'], 1);
    $h->assertSame(1, $partialResult['valid_rows'], 'partially invalid file reports its valid row');
    $h->assertSame(1, $partialResult['skipped'], 'partially invalid file localizes its invalid row');
    $h->assertSame(false, $partialResult['writes_performed'], 'any validation error prevents partial import');
    $h->assertSame(null, $db->one('SELECT id FROM business_products WHERE slug = ?', ['atomic-valid']), 'atomic validation failure leaves no created product');

    $external = catalog_import_csv([
        'format_version' => 'pim.catalog.v1', 'site_id' => '1', 'external_id' => 'erp-product-42',
        'product_name' => 'External Product', 'product_slug' => 'external-product', 'variant_sku' => 'EXTERNAL-42', 'base_sale_price' => '42',
    ]);
    $externalApply = $csv->importProductsCsv(1, $external, ['dry_run' => '0', 'idempotency_key' => 'external-42'], 1);
    $h->assertSame(1, $externalApply['created_products'], 'external identifier import creates a product');
    $h->assertTrue($db->one('SELECT id FROM business_products WHERE site_id = 1 AND external_id = ?', ['erp-product-42']) !== null, 'external identifier is persisted as reconciliation key');

    $db->run("INSERT INTO business_products(site_id,type,status,visibility,name,slug,is_catalogue_enabled) VALUES(2,'physical','draft','internal','Other site external','other-site-external',1)");
    $otherSiteProductId = (int) $db->lastInsertId();
    $db->run('UPDATE business_products SET external_id = ? WHERE id = ?', ['erp-other-site', $otherSiteProductId]);
    $crossSite = $csv->importProductsCsv(1, catalog_import_csv([
        'external_id' => 'erp-other-site', 'product_name' => 'Wrong site', 'product_slug' => 'wrong-site', 'variant_sku' => 'WRONG-SITE',
    ]), ['dry_run' => '1'], 1);
    $h->assertSame('business.catalog.import_product_site_mismatch', $crossSite['errors'][0]['message'] ?? null, 'external identifier cannot cross site boundaries');

    $db->run("INSERT INTO business_product_channel_visibility(site_id,product_id,channel,status,starts_at) VALUES(1,?,'ecommerce','active','2999-01-01 00:00:00')", [(int) $product['id']]);
    $ecommerceExport = parse_catalog_csv($csv->exportProductsCsv(1, false, ['channel' => 'ecommerce', 'status' => 'active']));
    $exportedSlugs = array_column(array_slice($ecommerceExport, 1), (int) array_search('product_slug', $ecommerceExport[0], true));
    $h->assertSame(false, in_array('csv-product', $exportedSlugs, true), 'future channel visibility excludes product from contextual export');
} finally {
    $db = null;
    gc_collect_cycles();
    test_remove_tree($dir);
}

exit($h->finish('UNIT business catalog CSV import/export'));

/** @return list<list<string>> */
function parse_catalog_csv(string $csv): array
{
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, $csv);
    rewind($handle);
    $rows = [];
    while (($row = fgetcsv($handle, 0, ';', '"', '')) !== false) {
        $rows[] = $row;
    }
    fclose($handle);
    return $rows;
}

/** @param array<string,string> $values */
function catalog_import_csv(array $values): string
{
    $headers = [
        'format_version', 'site_id', 'external_id', 'product_name', 'product_slug', 'type', 'status', 'brand', 'category', 'sku_base',
        'is_public', 'is_ecommerce_enabled', 'is_pos_enabled', 'is_catalogue_enabled', 'base_purchase_price',
        'base_sale_price', 'currency', 'variant_sku', 'variant_name', 'variant_options',
        'purchase_adjustment_type', 'purchase_adjustment_value', 'sale_adjustment_type',
        'sale_adjustment_value', 'stock_quantity',
    ];
    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, $headers, ';', '"', '');
    fputcsv($handle, array_map(static fn(string $header): string => $values[$header] ?? '', $headers), ';', '"', '');
    rewind($handle);
    $csv = stream_get_contents($handle) ?: '';
    fclose($handle);
    return $csv;
}

/** @param list<array<string,string>> $rows */
function catalog_import_rows(array $rows): string
{
    $headers = ['product_name', 'product_slug', 'variant_sku', 'base_sale_price'];
    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, $headers, ';', '"', '');
    foreach ($rows as $values) {
        fputcsv($handle, array_map(static fn(string $header): string => $values[$header] ?? '', $headers), ';', '"', '');
    }
    rewind($handle);
    $csv = stream_get_contents($handle) ?: '';
    fclose($handle);
    return $csv;
}
