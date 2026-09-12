<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Business\Repositories\CatalogDiscountRepository;
use App\Modules\Business\Repositories\CatalogProductRepository;
use App\Modules\Business\Services\BusinessDatabaseConnection;
use App\Modules\Business\Services\BusinessOperationsDashboardService;
use App\Modules\Business\Services\CatalogDiscountService;
use App\Modules\Sale\Exceptions\SaleInventoryException;
use App\Modules\Sale\Repositories\SaleInventoryRepository;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Modules\Sale\Services\SaleInventoryService;

$h = new TestHarness();
[$businessDir, $businessPath, $businessDb] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/business.sql');
[$saleDir, $salePath, $saleDb] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/sale.sql');

try {
    $businessConnection = new BusinessDatabaseConnection($businessPath);
    $saleConnection = new SaleDatabaseConnection($salePath);
    $inventory = new SaleInventoryService(new SaleInventoryRepository($saleConnection), null, null, null, $businessConnection);
    $dashboard = new BusinessOperationsDashboardService($businessConnection, $saleConnection);
    $products = new CatalogProductRepository($businessDb);
    $discounts = new CatalogDiscountService(new CatalogDiscountRepository($businessDb));

    $variant = $businessDb->one("SELECT v.id,v.sku,v.product_id FROM business_product_variants v INNER JOIN business_products p ON p.id=v.product_id WHERE p.site_id=1 AND v.archived_at IS NULL ORDER BY v.id LIMIT 1") ?? [];
    $variantId = (int) ($variant['id'] ?? 0);
    $productId = (int) ($variant['product_id'] ?? 0);
    $h->assertTrue($variantId > 0 && $productId > 0, 'seed exposes a sellable variant for the Operations workflow');

    $receipt = $inventory->adjust(1, $variantId, 4, (string) $variant['sku'], 'Réception fournisseur BL-38D', 1, null, 'receipt', '38d-receipt');
    $locationId = (int) ($receipt['stock_location_id'] ?? 0);
    $h->assertSame(4, (int) $receipt['on_hand_quantity'], 'receipt appends stock to the Sale ledger');
    $inventory->adjust(1, $variantId, -2, (string) $variant['sku'], 'Deux articles cassés', 1, $locationId, 'issue', '38d-loss');
    $inventory->adjust(1, $variantId, 1, (string) $variant['sku'], 'Retour client contrôlé', 1, $locationId, 'return', '38d-return');
    $corrected = $inventory->adjust(1, $variantId, -1, (string) $variant['sku'], 'Correction compensatoire inventaire', 1, $locationId, 'inventory_adjustment', '38d-count');
    $h->assertSame(2, (int) $corrected['on_hand_quantity'], 'receipt, loss, return and counted correction converge without rewriting history');

    $projected = $businessDb->one('SELECT * FROM business_inventory_availability_projections WHERE site_id=1 AND sellable_id=?', [$variantId]) ?? [];
    $h->assertSame(2, (int) ($projected['on_hand_quantity'] ?? -1), 'Business receives the Sale availability projection');
    $product = $products->find(1, $productId) ?? [];
    $h->assertTrue((int) ($product['stock_quantity_total'] ?? 0) >= 2, 'product list consumes projected stock instead of a second editable quantity');

    $movementId = (int) ($saleDb->one('SELECT id FROM sale_stock_movements WHERE inventory_item_id=? ORDER BY id LIMIT 1', [(int) $receipt['id']])['id'] ?? 0);
    $h->expectException(fn() => $saleDb->run('UPDATE sale_stock_movements SET reason=? WHERE id=?', ['rewrite forbidden',$movementId]), PDOException::class, 'Sale ledger movement cannot be rewritten');
    $h->expectException(fn() => $saleDb->run('DELETE FROM sale_stock_movements WHERE id=?', [$movementId]), PDOException::class, 'Sale ledger movement cannot be deleted');
    $h->expectException(fn() => $inventory->adjust(1, $variantId, -1000, (string) $variant['sku'], 'Invalid negative stock', 1, $locationId, 'correction', '38d-negative'), SaleInventoryException::class, 'negative available stock is rejected');

    $offerOne = $discounts->create(1, ['name'=>'38d launch','type'=>'percent','value'=>10,'scope'=>'product','scope_id'=>$productId,'channel'=>'ecommerce','starts_at'=>'2026-08-01 00:00:00','ends_at'=>'2026-08-31 23:59:59','status'=>'active'], 1);
    $preview = $discounts->preview(1, ['name'=>'38d overlap','type'=>'percent','value'=>5,'scope'=>'product','scope_id'=>$productId,'channel'=>'all','starts_at'=>'2026-08-10 00:00:00','ends_at'=>'2026-08-20 00:00:00','customer_segment'=>'clients-fideles','include_audience_count'=>true]);
    $h->assertSame(1, (int) $preview['affected_products'], 'offer preview explains affected product volume');
    $h->assertTrue(count($preview['conflicts']) >= 1, 'offer preview detects overlapping active scope and period');
    $h->assertSame(false, $preview['activation_allowed'], 'conflicting offer requires explicit acknowledgement');
    $h->assertSame(false, $preview['audience']['is_consent'] ?? true, 'Audience is explicitly distinct from consent');
    $h->assertTrue((int) ($offerOne['id'] ?? 0) > 0, 'simple scheduled offer remains creatable');

    $ordinary = $dashboard->actionable(1, ['catalog'=>true,'inventory'=>true,'relations'=>true,'advanced'=>false]);
    $h->assertTrue(in_array('inventory.low', array_column($ordinary['tasks'], 'key'), true), 'ordinary dashboard exposes low stock as a business task');
    $h->assertTrue(!in_array('inventory.inconsistent', array_column($ordinary['tasks'], 'key'), true), 'ordinary dashboard hides diagnostic reconciliation');
    $saleDb->run('UPDATE sale_inventory_items SET on_hand_quantity=on_hand_quantity+1,available_quantity=available_quantity+1 WHERE id=?', [(int) $receipt['id']]);
    $advanced = $dashboard->actionable(1, ['catalog'=>true,'inventory'=>true,'relations'=>true,'advanced'=>true]);
    $h->assertTrue(in_array('inventory.inconsistent', array_column($advanced['tasks'], 'key'), true), 'advanced role receives ledger inconsistency diagnostics');
} finally {
    $businessDb = null;
    $saleDb = null;
    gc_collect_cycles();
    test_remove_tree($businessDir);
    test_remove_tree($saleDir);
}

exit($h->finish('UNIT Operations products stock offers 38d'));
