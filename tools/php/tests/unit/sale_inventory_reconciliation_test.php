<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Business\Catalog\CatalogPricingService;
use App\Modules\Business\Repositories\BusinessCatalogPricingRepository;
use App\Modules\Business\Repositories\PosCatalogRepository;
use App\Modules\Business\Services\BusinessCatalogSellableReadService;
use App\Modules\Business\Services\BusinessDatabaseConnection;
use App\Modules\Sale\Exceptions\SaleInventoryException;
use App\Modules\Sale\Repositories\SaleInventoryRepository;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Modules\Sale\Services\SaleInventoryService;
use App\Modules\Sale\Services\SaleInventoryReconciliationService;

$h = new TestHarness();
[$businessDir, $businessPath, $businessDb] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/business.sql');
[$saleDir, $salePath, $saleDb] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/sale.sql');

try {
    $variant = $businessDb->one("SELECT id,stock_quantity FROM business_product_variants WHERE sku='DEMO-GOURDE-BLEU'");
    $variantId = (int) $variant['id'];
    $saleConnection = new SaleDatabaseConnection($salePath);
    $repository = new SaleInventoryRepository($saleConnection);
    $inventory = new SaleInventoryService($repository);
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
        $saleConnection,
        new BusinessDatabaseConnection($businessPath),
        $inventory,
        $saleDir.'/reconciliation-backups'
    );
    $preview = $reconciliation->run(1);
    $h->assertSame('dry_run', $preview['mode'], 'reconciliation is a dry-run by default');
    $h->assertTrue((int) $preview['differences_count'] >= 2, 'preview detects cache and missing Shop projection differences');
    $h->assertSame(0, (int) $preview['repaired_count'], 'preview never applies a repair');
    $h->assertSame(2, (int) ($saleDb->one('SELECT reserved_quantity FROM sale_inventory_items WHERE sellable_id=?', [$variantId])['reserved_quantity'] ?? -1), 'dry-run leaves the materialized cache untouched');
    $h->expectException(fn()=>$reconciliation->run(1,true,1,''),SaleInventoryException::class,'repair requires an operator reason before backup or write');
    $h->expectException(fn()=>$reconciliation->run(1,true,1,'Invalid correction',[['inventory_item_id'=>(int)$movement['inventory_item_id'],'target_on_hand_quantity'=>'not-a-number']]),SaleInventoryException::class,'invalid correction is rejected before any repair');
    $report = $reconciliation->run(1, true, 1, 'Repair derived stock cache');
    $h->assertSame(0, (int) $report['remaining_differences_count'], 'controlled repair converges all invariants');
    $h->assertTrue((int) $report['repaired_count'] >= 1, 'repair records proof for each corrected cache');
    $h->assertTrue(is_file((string)$report['backup']['path'].'/sale.sqlite') && is_file((string)$report['backup']['path'].'/business.sqlite'), 'repair creates Sale and Business backups first');
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
    $physicalPreview = $reconciliation->run(1);
    $h->assertTrue((int) $physicalPreview['differences_count'] >= 1, 'reconciliation detects direct physical cache drift');
    $h->assertSame(9, (int) ($saleDb->one('SELECT on_hand_quantity FROM sale_inventory_items WHERE sellable_id=?', [$variantId])['on_hand_quantity'] ?? 0), 'physical preview remains non-mutating');
    $physicalRepair = $reconciliation->run(1, true, 1, 'Rebuild cache from immutable ledger');
    $h->assertTrue((int) $physicalRepair['repaired_count'] >= 1, 'reconciliation repairs physical cache from the immutable ledger');
    $h->assertSame(5, (int) ($saleDb->one('SELECT on_hand_quantity FROM sale_inventory_items WHERE sellable_id=?', [$variantId])['on_hand_quantity'] ?? 0), 'physical quantity is reconstructed from movements');
    $corrected = $reconciliation->run(1, true, 1, 'Counted seven units physically', [['inventory_item_id'=>(int)$movement['inventory_item_id'],'target_on_hand_quantity'=>7]]);
    $h->assertSame(0, (int)$corrected['remaining_differences_count'], 'external physical count converges through a corrective movement');
    $h->assertSame(7, (int)($saleDb->one('SELECT on_hand_quantity FROM sale_inventory_items WHERE sellable_id=?',[$variantId])['on_hand_quantity']??0), 'corrective movement updates materialized physical quantity');
    $h->assertSame(1, (int)($saleDb->one("SELECT COUNT(*) AS count FROM sale_stock_movements WHERE movement_type='correction' AND reason='Counted seven units physically'")['count']??0), 'physical correction is an immutable ledger movement, never a direct total edit');
    $h->assertSame(0, (int) $reconciliation->run(1)['differences_count'], 'a second dry-run reconciliation is clean');

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
