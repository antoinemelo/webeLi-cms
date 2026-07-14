<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Business\Catalog\CatalogPricingService;
use App\Modules\Business\Repositories\BusinessCatalogPricingRepository;
use App\Modules\Business\Repositories\PosCatalogRepository;
use App\Modules\Business\Services\BusinessCatalogSellableReadService;
use App\Modules\Business\Services\BusinessDatabaseConnection;
use App\Modules\Sale\Repositories\SaleInventoryRepository;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Modules\Sale\Services\SaleInventoryReconciliationService;

$h = new TestHarness();
[$businessDir, $businessPath, $businessDb] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/business.sql');
[$saleDir, $salePath, $saleDb] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/sale.sql');

try {
    $variant = $businessDb->one("SELECT id,stock_quantity FROM business_product_variants WHERE sku='DEMO-GOURDE-BLEU'");
    $variantId = (int) $variant['id'];
    $repository = new SaleInventoryRepository(new SaleDatabaseConnection($salePath));
    $repository->adjust(1, $variantId, 5, 'DEMO-GOURDE-BLEU', 'initial receipt', 1, null, 'receipt', 'receipt:test:1');
    $repository->adjust(1, $variantId, 5, 'DEMO-GOURDE-BLEU', 'initial receipt retry', 1, null, 'receipt', 'receipt:test:1');
    $h->assertSame(5, (int) ($saleDb->one('SELECT on_hand_quantity FROM sale_inventory_items WHERE sellable_id=?', [$variantId])['on_hand_quantity'] ?? 0), 'movement idempotency prevents a duplicate receipt');
    $h->assertSame(1, (int) ($saleDb->one("SELECT COUNT(*) AS count FROM sale_stock_movements WHERE idempotency_key='receipt:test:1'")['count'] ?? 0), 'movement idempotency key is unique');
    $movement = $saleDb->one("SELECT * FROM sale_stock_movements WHERE idempotency_key='receipt:test:1'");
    $h->assertSame((int) $movement['stock_location_id'], (int) ($saleDb->one('SELECT stock_location_id FROM sale_inventory_items WHERE sellable_id=?', [$variantId])['stock_location_id'] ?? 0), 'movement stores its location explicitly');
    $h->assertSame('receipt:test:1', $movement['correlation_id'] ?? null, 'movement stores a stable correlation id');
    $h->assertSame(5, (int) ($movement['balance_after_quantity'] ?? 0), 'movement stores the resulting physical balance');

    $saleDb->run('UPDATE sale_inventory_items SET reserved_quantity=2,available_quantity=on_hand_quantity-2 WHERE sellable_id=?', [$variantId]);
    $reconciliation = new SaleInventoryReconciliationService(
        new SaleDatabaseConnection($salePath),
        new BusinessDatabaseConnection($businessPath)
    );
    $report = $reconciliation->run(1, true, 1);
    $h->assertSame(1, (int) $report['differences_count'], 'reconciliation detects the derived reservation discrepancy');
    $h->assertSame(1, (int) $report['repaired_count'], 'reconciliation repairs derived quantities');
    $h->assertSame(0, (int) ($saleDb->one('SELECT reserved_quantity FROM sale_inventory_items WHERE sellable_id=?', [$variantId])['reserved_quantity'] ?? -1), 'reconciliation restores deterministic reserved quantity');

    $projection = $businessDb->one('SELECT * FROM business_inventory_availability_projections WHERE sellable_id=?', [$variantId]);
    $h->assertSame(5, (int) ($projection['on_hand_quantity'] ?? 0), 'Sale physical stock is projected into Business');
    $h->assertSame(5, (int) ($projection['available_quantity'] ?? 0), 'projected availability is deterministic');
    $h->assertSame((int) $variant['stock_quantity'], (int) ($businessDb->one('SELECT stock_quantity FROM business_product_variants WHERE id=?', [$variantId])['stock_quantity'] ?? 0), 'legacy Business bootstrap quantity is not transactionally mutated');
    $h->assertTrue((int) ($businessDb->one("SELECT COUNT(*) AS count FROM business_storefront_projection_invalidations WHERE reason='availability'")['count'] ?? 0) > 0, 'projection invalidates rebuildable Storefront views');

    $pricingRepository = new BusinessCatalogPricingRepository($businessDb);
    $sellables = new BusinessCatalogSellableReadService($pricingRepository, new CatalogPricingService($pricingRepository), new PosCatalogRepository($businessDb));
    $snapshot = $sellables->getSellableVariantSnapshot(1, $variantId, ['channel' => 'ecommerce']);
    $h->assertSame(5.0, (float) ($snapshot['metadata']['available_quantity'] ?? 0), 'Business sellable reads the Sale availability projection');
    $h->assertSame('sale_projection', $snapshot['metadata']['inventory_source'] ?? null, 'projection exposes its non-transactional source explicitly');
    $saleDb->run('UPDATE sale_inventory_items SET on_hand_quantity=9,available_quantity=9 WHERE sellable_id=?', [$variantId]);
    $physicalRepair = $reconciliation->run(1, true, 1);
    $h->assertSame(1, (int) $physicalRepair['differences_count'], 'reconciliation detects direct physical cache drift');
    $h->assertSame(1, (int) $physicalRepair['repaired_count'], 'reconciliation repairs physical cache from the immutable ledger');
    $h->assertSame(5, (int) ($saleDb->one('SELECT on_hand_quantity FROM sale_inventory_items WHERE sellable_id=?', [$variantId])['on_hand_quantity'] ?? 0), 'physical quantity is reconstructed from movements');
    $h->assertSame(0, (int) $reconciliation->run(1, true, 1)['differences_count'], 'a second reconciliation is clean');

    $movementId = (int) ($saleDb->one("SELECT id FROM sale_stock_movements WHERE idempotency_key='receipt:test:1'")['id'] ?? 0);
    $h->expectException(
        fn() => $saleDb->run('UPDATE sale_stock_movements SET quantity=99 WHERE id=?', [$movementId]),
        PDOException::class,
        'stock movement journal is immutable'
    );
} finally {
    $businessDb = null;
    $saleDb = null;
    gc_collect_cycles();
    test_remove_tree($businessDir);
    test_remove_tree($saleDir);
}

exit($h->finish('UNIT sale inventory reconciliation'));
