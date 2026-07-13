<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Modules\Business\Services\BusinessDatabaseConnection;
use App\Modules\Sale\Exceptions\SaleInventoryException;

final class SaleInventoryReconciliationService
{
    public function __construct(
        private readonly SaleDatabaseConnection $sale,
        private readonly BusinessDatabaseConnection $business,
    ) {}

    /** @return array<string,mixed> */
    public function run(int $siteId, bool $repairDerived = true, ?int $actorId = null): array
    {
        $sale = $this->sale->database();
        $business = $this->business->database();
        if ($sale === null || $business === null) {
            throw new SaleInventoryException('sale.inventory_reconciliation_database_unavailable');
        }
        $sale->run("INSERT INTO sale_inventory_reconciliation_runs(site_id,status,created_by_iam_user_id) VALUES(?,'running',?)", [$siteId, $actorId]);
        $runId = $sale->lastInsertId();
        try {
            $items = $sale->all('SELECT * FROM sale_inventory_items WHERE site_id=? ORDER BY id', [$siteId]);
            $differences = [];
            $repaired = 0;
            foreach ($items as $item) {
                $activeReserved = (int) ($sale->one(
                    "SELECT COALESCE(SUM(quantity),0) AS quantity FROM sale_stock_reservations WHERE inventory_item_id=? AND status IN ('active','confirmed')",
                    [(int) $item['id']]
                )['quantity'] ?? 0);
                $ledger = (int) ($sale->one(
                    "SELECT COALESCE(SUM(quantity),0) AS quantity FROM sale_stock_movements WHERE inventory_item_id=? AND movement_type IN ('initial','receipt','issue','adjustment','correction','return','transfer_in','transfer_out','consumption')",
                    [(int) $item['id']]
                )['quantity'] ?? 0);
                if ($activeReserved !== (int) $item['reserved_quantity']) {
                    $differences[] = ['inventory_item_id' => (int) $item['id'], 'kind' => 'reserved_quantity', 'stored' => (int) $item['reserved_quantity'], 'expected' => $activeReserved];
                    if ($repairDerived) {
                        $sale->run('UPDATE sale_inventory_items SET reserved_quantity=?,available_quantity=on_hand_quantity-?,version=version+1,updated_at=CURRENT_TIMESTAMP WHERE id=?', [$activeReserved, $activeReserved, (int) $item['id']]);
                        $repaired++;
                    }
                }
                if ($ledger !== (int) $item['on_hand_quantity']) {
                    $differences[] = ['inventory_item_id' => (int) $item['id'], 'kind' => 'physical_ledger', 'stored' => (int) $item['on_hand_quantity'], 'expected' => $ledger];
                }
            }

            $projectionRows = $sale->all(
                'SELECT sellable_id,MAX(tracked) AS tracked,SUM(on_hand_quantity) AS on_hand_quantity,SUM(reserved_quantity) AS reserved_quantity,SUM(available_quantity) AS available_quantity,MAX(version) AS source_version
                 FROM sale_inventory_items WHERE site_id=? GROUP BY sellable_id',
                [$siteId]
            );
            $business->transaction(function () use ($business, $siteId, $projectionRows): void {
                $business->run('DELETE FROM business_inventory_availability_projections WHERE site_id=?', [$siteId]);
                foreach ($projectionRows as $row) {
                    $tracked = (int) $row['tracked'];
                    $available = (int) $row['available_quantity'];
                    $business->run(
                        'INSERT INTO business_inventory_availability_projections(sellable_id,site_id,tracked,on_hand_quantity,reserved_quantity,available_quantity,availability_status,source_version)
                         VALUES(?,?,?,?,?,?,?,?)',
                        [(int) $row['sellable_id'], $siteId, $tracked, (int) $row['on_hand_quantity'], (int) $row['reserved_quantity'], $available, $tracked === 0 ? 'not_tracked' : ($available > 0 ? 'available' : 'unavailable'), (int) $row['source_version']]
                    );
                    $business->run(
                        "INSERT INTO business_storefront_projection_invalidations(site_id,product_id,reason) SELECT site_id,product_id,'availability' FROM business_sellables WHERE sellable_id=?",
                        [(int) $row['sellable_id']]
                    );
                }
            });

            $status = $differences === [] ? 'clean' : 'differences';
            $report = ['run_id' => $runId, 'site_id' => $siteId, 'source_of_truth' => 'sale.sqlite', 'items_checked' => count($items), 'differences_count' => count($differences), 'repaired_count' => $repaired, 'projection_rows' => count($projectionRows), 'differences' => $differences];
            $sale->run('UPDATE sale_inventory_reconciliation_runs SET status=?,items_checked=?,differences_count=?,repaired_count=?,report_json=?,completed_at=CURRENT_TIMESTAMP WHERE id=?', [$status, count($items), count($differences), $repaired, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $runId]);
            return $report;
        } catch (\Throwable $e) {
            $sale->run("UPDATE sale_inventory_reconciliation_runs SET status='failed',report_json=?,completed_at=CURRENT_TIMESTAMP WHERE id=?", [json_encode(['error' => $e->getMessage()]), $runId]);
            throw $e;
        }
    }
}
