<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Core\Database;
use App\Modules\Business\Services\BusinessDatabaseConnection;
use App\Modules\Sale\Exceptions\SaleInventoryException;

final class SaleInventoryReconciliationService
{
    public function __construct(
        private readonly SaleDatabaseConnection $sale,
        private readonly BusinessDatabaseConnection $business,
        private readonly ?SaleInventoryService $inventory = null,
        private readonly ?string $backupRoot = null,
    ) {}

    /**
     * @param list<array{inventory_item_id:int,target_on_hand_quantity:int}> $corrections
     * @param list<int> $onlyItemIds
     * @return array<string,mixed>
     */
    public function run(
        int $siteId,
        bool $repair = false,
        ?int $actorId = null,
        ?string $reason = null,
        array $corrections = [],
        array $onlyItemIds = [],
    ): array {
        $sale = $this->sale->database();
        $business = $this->business->database();
        if ($sale === null || $business === null) {
            throw new SaleInventoryException('sale.inventory_reconciliation_database_unavailable');
        }
        $reason = trim((string) $reason);
        if ($repair && mb_strlen($reason) < 3) {
            throw new SaleInventoryException('sale.inventory_reconciliation_reason_required');
        }
        if ($corrections !== [] && !$repair) {
            throw new SaleInventoryException('sale.inventory_reconciliation_corrections_require_repair');
        }
        $correctionIds = [];
        foreach ($corrections as $correction) {
            $itemId = (int) ($correction['inventory_item_id'] ?? 0);
            $target = $correction['target_on_hand_quantity'] ?? null;
            if ($itemId < 1 || !is_int($target) && !ctype_digit((string) $target) || (int) $target < 0 || isset($correctionIds[$itemId])) {
                throw new SaleInventoryException('sale.inventory_reconciliation_correction_invalid');
            }
            $correctionIds[$itemId] = true;
        }
        $onlyItemIds = array_values(array_unique(array_filter(array_map('intval', $onlyItemIds), static fn(int $id): bool => $id > 0)));
        $backup = $repair ? $this->backup() : null;
        $sale->run(
            "INSERT INTO sale_inventory_reconciliation_runs(site_id,mode,status,reason,backup_path,created_by_iam_user_id) VALUES(?,?,'running',?,?,?)",
            [$siteId, $repair ? 'repair' : 'dry_run', $repair ? $reason : null, $backup['path'] ?? null, $actorId]
        );
        $runId = $sale->lastInsertId();

        try {
            $before = $this->analyse($sale, $business, $siteId, $onlyItemIds);
            $proofs = [];
            if ($repair) {
                if (!$before['invariants']['movement_chain_valid']) {
                    throw new SaleInventoryException('sale.inventory_reconciliation_ledger_investigation_required');
                }
                foreach ($before['item_states'] as $state) {
                    if (!$state['cache_drift']) continue;
                    if ((int) $state['allow_negative'] !== 1 && ((int) $state['ledger_on_hand'] < 0 || (int) $state['expected_available'] < 0)) {
                        throw new SaleInventoryException('sale.inventory_reconciliation_negative_derived_state');
                    }
                    $sale->run(
                        'UPDATE sale_inventory_items SET on_hand_quantity=?,reserved_quantity=?,available_quantity=?,version=version+1,updated_at=CURRENT_TIMESTAMP WHERE id=?',
                        [(int) $state['ledger_on_hand'], (int) $state['active_reserved'], (int) $state['expected_available'], (int) $state['inventory_item_id']]
                    );
                    $proofs[] = [
                        'inventory_item_id' => (int) $state['inventory_item_id'],
                        'repair_kind' => 'derived_cache_rebuild',
                        'before' => $state['stored'],
                        'after' => ['on_hand_quantity'=>(int)$state['ledger_on_hand'],'reserved_quantity'=>(int)$state['active_reserved'],'available_quantity'=>(int)$state['expected_available']],
                        'source' => 'immutable_movements_and_active_reservations',
                    ];
                }
                foreach ($corrections as $correction) {
                    $proofs[] = $this->applyCorrection($sale, $siteId, $runId, $correction, $reason, $actorId);
                }
                $projectionRows = $this->rebuildProjection($sale, $business, $siteId);
                $after = $this->analyse($sale, $business, $siteId, $onlyItemIds);
            } else {
                $projectionRows = 0;
                $after = $before;
            }

            $differences = $before['findings'];
            $remaining = $after['findings'];
            $status = !$repair
                ? ($differences === [] ? 'clean' : 'differences')
                : ($remaining === [] ? 'repaired' : 'partially_repaired');
            $report = [
                'format_version' => 1,
                'run_id' => $runId,
                'site_id' => $siteId,
                'mode' => $repair ? 'repair' : 'dry_run',
                'dry_run' => !$repair,
                'status' => $status,
                'source_of_truth' => 'sale_stock_movements',
                'items_checked' => count($before['item_states']),
                'differences_count' => count($differences),
                'remaining_differences_count' => count($remaining),
                'critical_count' => count(array_filter($differences, static fn(array $row): bool => $row['severity'] === 'critical')),
                'warning_count' => count(array_filter($differences, static fn(array $row): bool => $row['severity'] === 'warning')),
                'repaired_count' => count($proofs),
                'projection_rows' => $projectionRows,
                'backup' => $backup,
                'reason' => $repair ? $reason : null,
                'scope_inventory_item_ids' => $onlyItemIds,
                'differences' => $differences,
                'remaining_differences' => $remaining,
                'repair_proofs' => $proofs,
                'relations_checked' => $before['relations_checked'],
                'shop_projection' => $after['shop_projection'],
                'invariants' => [
                    'current_equals_movement_sum' => $after['invariants']['current_equals_movement_sum'],
                    'available_equals_physical_minus_reserved' => $after['invariants']['available_equals_physical_minus_reserved'],
                    'movement_chain_valid' => $after['invariants']['movement_chain_valid'],
                    'business_shop_projection_current' => $after['invariants']['business_shop_projection_current'],
                ],
            ];
            $sale->run(
                'UPDATE sale_inventory_reconciliation_runs SET status=?,items_checked=?,differences_count=?,remaining_differences_count=?,repaired_count=?,report_json=?,completed_at=CURRENT_TIMESTAMP WHERE id=?',
                [$status, count($before['item_states']), count($differences), count($remaining), count($proofs), json_encode($report, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), $runId]
            );
            return $report;
        } catch (\Throwable $e) {
            $sale->run("UPDATE sale_inventory_reconciliation_runs SET status='failed',report_json=?,completed_at=CURRENT_TIMESTAMP WHERE id=?", [json_encode(['error'=>$e->getMessage(),'backup'=>$backup], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), $runId]);
            throw $e;
        }
    }

    /** @return list<array<string,mixed>> */
    public function history(int $siteId, int $limit = 20): array
    {
        $db = $this->sale->database() ?? throw new SaleInventoryException('sale.inventory_reconciliation_database_unavailable');
        $rows = $db->all('SELECT * FROM sale_inventory_reconciliation_runs WHERE site_id=? ORDER BY id DESC LIMIT ?', [$siteId, max(1,min(100,$limit))]);
        foreach ($rows as &$row) $row['report'] = json_decode((string)$row['report_json'], true) ?: [];
        return $rows;
    }

    /** @param list<int> $onlyItemIds @return array<string,mixed> */
    private function analyse(Database $sale, Database $business, int $siteId, array $onlyItemIds): array
    {
        $params = [$siteId]; $scope = '';
        if ($onlyItemIds !== []) {
            $scope = ' AND id IN (' . implode(',', array_fill(0,count($onlyItemIds),'?')) . ')';
            array_push($params, ...$onlyItemIds);
        }
        $items = $sale->all('SELECT * FROM sale_inventory_items WHERE site_id=?'.$scope.' ORDER BY id', $params);
        $findings=[];$states=[];$projectionChecked=[];
        $invariants=['current_equals_movement_sum'=>true,'available_equals_physical_minus_reserved'=>true,'movement_chain_valid'=>true,'business_shop_projection_current'=>true];
        $relations=['reservations'=>0,'fulfillments'=>0,'returns'=>0,'transfers'=>0,'movements'=>0];
        foreach($items as $item){
            $id=(int)$item['id'];$variant=(int)$item['business_variant_id'];$sellable=(int)$item['sellable_id'];$location=(int)$item['stock_location_id'];
            $reservations=$sale->all('SELECT id,status,quantity,cart_id,order_id FROM sale_stock_reservations WHERE inventory_item_id=? ORDER BY id',[$id]);
            $activeReservations=array_values(array_filter($reservations,static fn(array $r):bool=>in_array((string)$r['status'],['active','confirmed'],true)));
            $activeReserved=array_sum(array_map(static fn(array $r):int=>(int)$r['quantity'],$activeReservations));$relations['reservations']+=count($reservations);
            $movements=$sale->all('SELECT id,movement_type,quantity,balance_after_quantity,idempotency_key,reference_type,reference_id FROM sale_stock_movements WHERE inventory_item_id=? ORDER BY id',[$id]);$relations['movements']+=count($movements);
            $running=0;$chainErrors=[];
            foreach($movements as $movement){if(!in_array((string)$movement['movement_type'],['reservation','release'],true))$running+=(int)$movement['quantity'];if((int)$movement['balance_after_quantity']!==$running)$chainErrors[]=(int)$movement['id'];}
            $ledger=$running;$expectedAvailable=$ledger-$activeReserved;
            $fulfillments=$sale->all("SELECT f.id,fl.quantity,fl.prepared_quantity,f.status FROM sale_fulfillment_lines fl INNER JOIN sale_fulfillments f ON f.id=fl.fulfillment_id INNER JOIN sale_order_lines ol ON ol.id=fl.order_line_id WHERE ol.business_variant_id=? AND f.stock_location_id=? AND f.status NOT IN ('cancelled','returned')",[$variant,$location]);$relations['fulfillments']+=count($fulfillments);
            $returns=$sale->all("SELECT r.id,rl.quantity,rl.stock_disposition FROM sale_return_lines rl INNER JOIN sale_returns r ON r.id=rl.return_id INNER JOIN sale_order_lines ol ON ol.id=rl.order_line_id WHERE ol.business_variant_id=? AND r.status='completed' AND rl.restock=1",[$variant]);$relations['returns']+=count($returns);
            $transfers=$sale->all("SELECT t.id,t.status,tl.shipped_quantity,tl.received_quantity,t.from_location_id,t.to_location_id FROM sale_stock_transfer_lines tl INNER JOIN sale_stock_transfers t ON t.id=tl.transfer_id WHERE tl.business_variant_id=? AND (t.from_location_id=? OR t.to_location_id=?)",[$variant,$location,$location]);$relations['transfers']+=count($transfers);
            $references=['movement_ids'=>array_map('intval',array_column($movements,'id')),'reservation_ids'=>array_map('intval',array_column($reservations,'id')),'fulfillment_ids'=>array_values(array_unique(array_map('intval',array_column($fulfillments,'id')))),'return_ids'=>array_values(array_unique(array_map('intval',array_column($returns,'id')))),'transfer_ids'=>array_values(array_unique(array_map('intval',array_column($transfers,'id')))),'links'=>['movements'=>'/admin/sale/stock?item_id='.$id,'reservations'=>'/sale/reservations?item_id='.$id]];
            if((int)$item['on_hand_quantity']!==$ledger){$invariants['current_equals_movement_sum']=false;$findings[]=$this->finding($id,'physical_ledger','critical',(int)$item['on_hand_quantity'],$ledger,'Le cache physique ne correspond plus au journal immuable.','Disponibilité et valorisation Shop potentiellement obsolètes.',$references);}
            if((int)$item['reserved_quantity']!==$activeReserved){$findings[]=$this->finding($id,'reserved_quantity','error',(int)$item['reserved_quantity'],$activeReserved,'Une réservation a été modifiée ou projetée sans recalcul du cache.','Le stock disponible peut être sur- ou sous-estimé.',$references);}
            if((int)$item['available_quantity']!==$expectedAvailable){$invariants['available_equals_physical_minus_reserved']=false;$findings[]=$this->finding($id,'available_formula','critical',(int)$item['available_quantity'],$expectedAvailable,'La quantité disponible ne respecte pas physique moins réservé.','Filtres, cartes produit et panier Shop peuvent diverger.',$references);}
            if($chainErrors!==[]){$invariants['movement_chain_valid']=false;$refs=$references;$refs['invalid_balance_movement_ids']=$chainErrors;$findings[]=$this->finding($id,'movement_balance_chain','critical',count($chainErrors),0,'Un balance_after_quantity ne suit pas la somme ordonnée des mouvements.','Le journal nécessite une investigation avant toute réparation.',$refs);}
            $states[]=['inventory_item_id'=>$id,'business_variant_id'=>$variant,'sellable_id'=>$sellable,'stock_location_id'=>$location,'allow_negative'=>(int)$item['allow_negative'],'stored'=>['on_hand_quantity'=>(int)$item['on_hand_quantity'],'reserved_quantity'=>(int)$item['reserved_quantity'],'available_quantity'=>(int)$item['available_quantity']],'ledger_on_hand'=>$ledger,'active_reserved'=>$activeReserved,'expected_available'=>$expectedAvailable,'cache_drift'=>(int)$item['on_hand_quantity']!==$ledger||(int)$item['reserved_quantity']!==$activeReserved||(int)$item['available_quantity']!==$expectedAvailable];
            if(isset($projectionChecked[$sellable]))continue;$projectionChecked[$sellable]=true;
            $aggregate=$sale->one('SELECT MAX(tracked) AS tracked,MAX(allow_backorder) AS allow_backorder,SUM(on_hand_quantity) AS on_hand_quantity,SUM(reserved_quantity) AS reserved_quantity,SUM(available_quantity) AS available_quantity FROM sale_inventory_items WHERE site_id=? AND sellable_id=?',[$siteId,$sellable])??[];
            $projection=$business->one('SELECT * FROM business_inventory_availability_projections WHERE site_id=? AND sellable_id=?',[$siteId,$sellable]);$expectedStatus=(int)($aggregate['tracked']??0)===0?'deliverable':((int)($aggregate['available_quantity']??0)>0?'in_stock':((int)($aggregate['allow_backorder']??0)===1?'backorder':'unavailable'));
            if($projection===null||(int)$projection['on_hand_quantity']!==(int)$aggregate['on_hand_quantity']||(int)$projection['reserved_quantity']!==(int)$aggregate['reserved_quantity']||(int)$projection['available_quantity']!==(int)$aggregate['available_quantity']||(string)$projection['availability_status']!==$expectedStatus){$invariants['business_shop_projection_current']=false;$findings[]=$this->finding($id,'business_shop_projection','critical',$projection===null?null:['on_hand'=>(int)$projection['on_hand_quantity'],'reserved'=>(int)$projection['reserved_quantity'],'available'=>(int)$projection['available_quantity'],'status'=>(string)$projection['availability_status']],['on_hand'=>(int)$aggregate['on_hand_quantity'],'reserved'=>(int)$aggregate['reserved_quantity'],'available'=>(int)$aggregate['available_quantity'],'status'=>$expectedStatus],'La projection Business utilisée par Shop est absente ou en retard.','Filtres, fiches, cartes et panier Shop peuvent publier un état périmé.',$references);}
        }
        return ['findings'=>$findings,'item_states'=>$states,'relations_checked'=>$relations,'shop_projection'=>['sellables_checked'=>count($projectionChecked),'consistent'=>$invariants['business_shop_projection_current'],'public_contract'=>'sale.inventory.availability.v1'],'invariants'=>$invariants];
    }

    /** @param mixed $stored @param mixed $expected @param array<string,mixed> $references @return array<string,mixed> */
    private function finding(int $itemId,string $kind,string $severity,mixed $stored,mixed $expected,string $cause,string $impact,array $references):array{return ['id'=>'item:'.$itemId.':'.$kind,'inventory_item_id'=>$itemId,'kind'=>$kind,'severity'=>$severity,'stored'=>$stored,'expected'=>$expected,'before'=>$stored,'after_preview'=>$expected,'probable_cause'=>$cause,'impact'=>$impact,'references'=>$references];}

    /** @param array<string,mixed> $correction @return array<string,mixed> */
    private function applyCorrection(Database $sale,int $siteId,int $runId,array $correction,string $reason,?int $actorId):array
    {
        if($this->inventory===null)throw new SaleInventoryException('sale.inventory_reconciliation_correction_unavailable');
        $itemId=(int)($correction['inventory_item_id']??0);$target=(int)($correction['target_on_hand_quantity']??-1);$item=$sale->one('SELECT * FROM sale_inventory_items WHERE id=? AND site_id=?',[$itemId,$siteId]);
        if($item===null||$target<0)throw new SaleInventoryException('sale.inventory_reconciliation_correction_invalid');
        $ledger=(int)($sale->one("SELECT COALESCE(SUM(quantity),0) AS quantity FROM sale_stock_movements WHERE inventory_item_id=? AND movement_type NOT IN ('reservation','release')",[$itemId])['quantity']??0);$delta=$target-$ledger;
        if($delta===0)return ['inventory_item_id'=>$itemId,'repair_kind'=>'corrective_movement','before'=>$ledger,'after'=>$target,'movement_id'=>null,'noop'=>true];
        $this->inventory->adjust($siteId,(int)$item['business_variant_id'],$delta,$item['sku'],$reason,$actorId,(int)$item['stock_location_id'],'correction','reconciliation:'.$runId.':item:'.$itemId);
        $movement=$sale->one('SELECT id,correlation_id,quantity,balance_after_quantity FROM sale_stock_movements WHERE idempotency_key=?',['reconciliation:'.$runId.':item:'.$itemId])??[];
        return ['inventory_item_id'=>$itemId,'repair_kind'=>'corrective_movement','before'=>$ledger,'after'=>$target,'movement_id'=>(int)($movement['id']??0),'correlation_id'=>$movement['correlation_id']??null,'quantity_delta'=>$delta];
    }

    private function rebuildProjection(Database $sale,Database $business,int $siteId):int
    {
        $rows=$sale->all('SELECT sellable_id,MAX(tracked) AS tracked,MAX(allow_backorder) AS allow_backorder,SUM(on_hand_quantity) AS on_hand_quantity,SUM(reserved_quantity) AS reserved_quantity,SUM(available_quantity) AS available_quantity,MAX(version) AS source_version FROM sale_inventory_items WHERE site_id=? GROUP BY sellable_id',[$siteId]);
        $business->transaction(function()use($business,$siteId,$rows):void{$business->run('DELETE FROM business_inventory_availability_projections WHERE site_id=?',[$siteId]);foreach($rows as $row){$tracked=(int)$row['tracked'];$available=(int)$row['available_quantity'];$business->run('INSERT INTO business_inventory_availability_projections(sellable_id,site_id,tracked,on_hand_quantity,reserved_quantity,available_quantity,availability_status,source_version) VALUES(?,?,?,?,?,?,?,?)',[(int)$row['sellable_id'],$siteId,$tracked,(int)$row['on_hand_quantity'],(int)$row['reserved_quantity'],$available,$tracked===0?'deliverable':($available>0?'in_stock':((int)$row['allow_backorder']===1?'backorder':'unavailable')),(int)$row['source_version']]);$business->run("INSERT INTO business_storefront_projection_invalidations(site_id,product_id,reason) SELECT site_id,product_id,'availability' FROM business_sellables WHERE sellable_id=?",[(int)$row['sellable_id']]);}});return count($rows);
    }

    /** @return array{path:string,sale_sha256:string,business_sha256:string,created_at:string} */
    private function backup():array
    {
        $salePath=$this->sale->path();$businessPath=$this->business->path();
        $root=$this->backupRoot??dirname(dirname($salePath)).'/backups/inventory-reconciliation';$path=rtrim($root,'/').'/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
        if(!is_dir($path)&&!mkdir($path,0770,true)&&!is_dir($path))throw new SaleInventoryException('sale.inventory_reconciliation_backup_failed');
        $this->sale->database()?->one('PRAGMA wal_checkpoint(FULL)');$this->business->database()?->one('PRAGMA wal_checkpoint(FULL)');
        $saleCopy=$path.'/sale.sqlite';$businessCopy=$path.'/business.sqlite';
        if(!copy($salePath,$saleCopy)||!copy($businessPath,$businessCopy))throw new SaleInventoryException('sale.inventory_reconciliation_backup_failed');
        return ['path'=>$path,'sale_sha256'=>hash_file('sha256',$saleCopy)?:'','business_sha256'=>hash_file('sha256',$businessCopy)?:'','created_at'=>gmdate('c')];
    }
}
