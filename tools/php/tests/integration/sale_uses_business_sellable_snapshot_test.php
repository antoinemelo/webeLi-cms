<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Business\Catalog\CatalogPricingService;
use App\Modules\Business\Repositories\BusinessCatalogPricingRepository;
use App\Modules\Business\Repositories\PosCatalogRepository;
use App\Modules\Business\Services\BusinessCatalogSellableReadService;
use App\Modules\Sale\Services\SaleCatalogSnapshotService;
use App\Modules\Sale\Services\SaleDatabaseConnection;

$h = new TestHarness();
[$businessDir, $businessPath, $businessDb] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/business.sql');
[$saleDir, $salePath, $saleDb] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/sale.sql');

try {
    $pricingRepository = new BusinessCatalogPricingRepository($businessDb);
    $sellables = new BusinessCatalogSellableReadService(
        $pricingRepository,
        new CatalogPricingService($pricingRepository),
        new PosCatalogRepository($businessDb)
    );
    $saleCatalog = new SaleCatalogSnapshotService(new SaleDatabaseConnection($salePath), $sellables);
    $variantId = (int) ($businessDb->one("SELECT id FROM business_product_variants WHERE sku = 'DEMO-GOURDE-BLEU' LIMIT 1")['id'] ?? 0);
    $h->assertTrue($variantId > 0, 'demo variant exists for Sale snapshot integration');

    $snapshot = $saleCatalog->snapshotForVariant(1, $variantId, 'pos');
    $h->assertSame('DEMO-GOURDE-BLEU', $snapshot['sku'] ?? null, 'Sale reads SKU from Business sellable snapshot');
    $h->assertSame(true, $snapshot['is_sellable'] ?? null, 'Sale receives sellable flag from Business');
    $h->assertTrue(array_key_exists('missing_requirements', $snapshot), 'Sale receives missing requirements from Business');
    $h->assertSame(2900, (int) ($snapshot['regular_unit_price_minor'] ?? 0), 'Sale receives regular price from Business snapshot');

    $public = $saleCatalog->publicPayload($snapshot);
    $h->assertTrue(!array_key_exists('unit_purchase_price_minor', $public), 'Sale public payload hides purchase price');

    $businessDb->run('UPDATE business_product_variants SET status = "draft" WHERE id = ?', [$variantId]);
    $h->expectException(
        fn() => $saleCatalog->snapshotForVariant(1, $variantId, 'pos'),
        InvalidArgumentException::class,
        'Sale refuses a Business variant that is no longer sellable'
    );
} finally {
    $businessDb = null;
    $saleDb = null;
    gc_collect_cycles();
    test_remove_tree($businessDir);
    test_remove_tree($saleDir);
}

exit($h->finish('INTEGRATION Sale uses Business sellable snapshot'));
