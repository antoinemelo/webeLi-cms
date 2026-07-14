<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Core\Database;
use App\Modules\Sale\Exceptions\SaleValidationException;

final class SaleFulfillmentService
{
    public function __construct(
        private readonly SaleDatabaseConnection $connection,
        private readonly ?SaleInventoryService $inventory = null,
        private readonly ?SaleStateMachineService $states = null,
    ) {}

    /** @return list<array<string,mixed>> */
    public function availableMethods(int $siteId, string $language = 'fr'): array
    {
        $label = strtolower($language) === 'en' ? 'label_en' : 'label_fr';
        return array_map(static fn(array $row): array => [
            'code' => (string) $row['code'],
            'label' => (string) $row[$label],
            'type' => (string) $row['fulfillment_type'],
            'flat_rate_minor' => (int) $row['flat_rate_minor'],
            'free_above_minor' => $row['free_above_minor'] === null ? null : (int) $row['free_above_minor'],
            'requires_shipping_address' => (bool) $row['requires_shipping_address'],
            'stock_location_id' => $row['stock_location_id'] === null ? null : (int) $row['stock_location_id'],
        ], $this->activeRows($siteId));
    }

    /** @param list<array<string,mixed>> $lines @param array<string,mixed> $address @return array<string,mixed> */
    public function quote(int $siteId, array $lines, array $address, string $code, string $language = 'fr'): array
    {
        $code = strtolower(trim($code));
        $method = null;
        foreach ($this->activeRows($siteId) as $candidate) {
            if ((string) $candidate['code'] === $code) { $method = $candidate; break; }
        }
        if ($method === null || $lines === []) {
            throw new SaleValidationException('sale.checkout.fulfillment_method_invalid');
        }
        $physical = array_filter($lines, static fn(array $line): bool => in_array((string) ($line['product_type'] ?? 'physical'), ['physical','bundle','other'], true));
        $hasPhysical = $physical !== [];
        $type = (string) $method['fulfillment_type'];
        if (($hasPhysical && $type === 'none') || (!$hasPhysical && $type !== 'none' && !(bool) $method['allow_non_physical'])) {
            throw new SaleValidationException('sale.checkout.fulfillment_method_invalid_for_cart');
        }
        if ((bool) $method['requires_shipping_address']) {
            $this->validateAddress($address);
            $this->assertZone($method, $address);
        }
        $itemsTotal = array_sum(array_map(static fn(array $line): int => max(0, (int) ($line['line_total_minor'] ?? 0)), $lines));
        $freeAbove = $method['free_above_minor'] === null ? null : (int) $method['free_above_minor'];
        $amount = $freeAbove !== null && $itemsTotal >= $freeAbove ? 0 : (int) $method['flat_rate_minor'];
        $labelKey = strtolower($language) === 'en' ? 'label_en' : 'label_fr';
        return [
            'method_id' => (int) $method['id'], 'code' => $code, 'label' => (string) $method[$labelKey],
            'type' => $type, 'amount_minor' => $amount, 'currency' => strtoupper((string) ($lines[0]['currency'] ?? 'CHF')),
            'requires_shipping_address' => (bool) $method['requires_shipping_address'],
            'stock_location_id' => $method['stock_location_id'] === null ? null : (int) $method['stock_location_id'],
            'zone' => $method['zone_code'] === null ? null : ['code' => $method['zone_code'], 'name' => $method['zone_name']],
            'pricing_rule' => ['flat_rate_minor' => (int) $method['flat_rate_minor'], 'free_above_minor' => $freeAbove, 'items_total_minor' => $itemsTotal],
            'snapshot_version' => 1,
        ];
    }

    /** @return array{zones:list<array<string,mixed>>,methods:list<array<string,mixed>>} */
    public function configuration(int $siteId): array
    {
        return ['zones' => $this->db()->all('SELECT * FROM sale_fulfillment_zones WHERE site_id=? ORDER BY code', [$siteId]), 'methods' => $this->db()->all('SELECT * FROM sale_fulfillment_methods WHERE site_id=? ORDER BY sort_order,code', [$siteId])];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function saveMethod(int $siteId, array $payload): array
    {
        $code = strtolower(trim((string) ($payload['code'] ?? '')));
        $type = (string) ($payload['fulfillment_type'] ?? 'shipping');
        if (!preg_match('/^[a-z0-9_-]+$/', $code) || !in_array($type, ['shipping','pickup','none'], true)) throw new SaleValidationException('sale.fulfillment.method_invalid');
        $locationId = isset($payload['stock_location_id']) ? (int) $payload['stock_location_id'] : null;
        if ($type === 'pickup' && ($locationId === null || $this->db()->one("SELECT id FROM sale_stock_locations WHERE id=? AND site_id=? AND status='active'", [$locationId,$siteId]) === null)) throw new SaleValidationException('sale.fulfillment.pickup_location_required');
        $params = [$siteId,$payload['zone_id']??null,$locationId,$code,trim((string)($payload['label_fr']??$code)),trim((string)($payload['label_en']??$code)),$type,max(0,(int)($payload['flat_rate_minor']??0)),isset($payload['free_above_minor'])?(int)$payload['free_above_minor']:null,($payload['requires_shipping_address']??$type==='shipping')?1:0,($payload['allow_non_physical']??false)?1:0,(string)($payload['status']??'active'),$payload['active_from']??null,$payload['active_until']??null,(int)($payload['sort_order']??0)];
        $this->db()->run('INSERT INTO sale_fulfillment_methods(site_id,zone_id,stock_location_id,code,label_fr,label_en,fulfillment_type,flat_rate_minor,free_above_minor,requires_shipping_address,allow_non_physical,status,active_from,active_until,sort_order) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(site_id,code) DO UPDATE SET zone_id=excluded.zone_id,stock_location_id=excluded.stock_location_id,label_fr=excluded.label_fr,label_en=excluded.label_en,fulfillment_type=excluded.fulfillment_type,flat_rate_minor=excluded.flat_rate_minor,free_above_minor=excluded.free_above_minor,requires_shipping_address=excluded.requires_shipping_address,allow_non_physical=excluded.allow_non_physical,status=excluded.status,active_from=excluded.active_from,active_until=excluded.active_until,sort_order=excluded.sort_order,updated_at=CURRENT_TIMESTAMP',$params);
        return $this->db()->one('SELECT * FROM sale_fulfillment_methods WHERE site_id=? AND code=?',[$siteId,$code])??[];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function saveZone(int $siteId, array $payload): array
    {
        $code=strtolower(trim((string)($payload['code']??''))); $name=trim((string)($payload['name']??''));
        $countries=array_values(array_unique(array_map(static fn(mixed $v):string=>strtoupper(trim((string)$v)),is_array($payload['country_codes']??null)?$payload['country_codes']:[])));
        $prefixes=array_values(array_filter(array_map(static fn(mixed $v):string=>trim((string)$v),is_array($payload['postal_prefixes']??null)?$payload['postal_prefixes']:[])));
        if(!preg_match('/^[a-z0-9_-]+$/',$code)||$name===''||array_filter($countries,static fn(string $v):bool=>!preg_match('/^[A-Z]{2}$/',$v))) throw new SaleValidationException('sale.fulfillment.zone_invalid');
        $this->db()->run('INSERT INTO sale_fulfillment_zones(site_id,code,name,country_codes_json,postal_prefixes_json,status,active_from,active_until) VALUES(?,?,?,?,?,?,?,?) ON CONFLICT(site_id,code) DO UPDATE SET name=excluded.name,country_codes_json=excluded.country_codes_json,postal_prefixes_json=excluded.postal_prefixes_json,status=excluded.status,active_from=excluded.active_from,active_until=excluded.active_until,updated_at=CURRENT_TIMESTAMP',[$siteId,$code,$name,json_encode($countries),json_encode($prefixes),(string)($payload['status']??'active'),$payload['active_from']??null,$payload['active_until']??null]);
        return $this->db()->one('SELECT * FROM sale_fulfillment_zones WHERE site_id=? AND code=?',[$siteId,$code])??[];
    }

    /** @param array<string,mixed> $filters @return list<array<string,mixed>> */
    public function workQueue(int $siteId, array $filters = []): array
    {
        $where = ['o.site_id=:site']; $params = ['site'=>$siteId];
        if (trim((string)($filters['status']??'')) !== '') { $where[]='f.status=:status'; $params['status']=trim((string)$filters['status']); }
        if ((int)($filters['location_id']??0) > 0) { $where[]='f.stock_location_id=:location'; $params['location']=(int)$filters['location_id']; }
        if (trim((string)($filters['q']??'')) !== '') { $where[]='(f.fulfillment_number LIKE :q OR o.order_number LIKE :q OR f.pickup_code LIKE :q OR o.customer_snapshot_json LIKE :q)'; $params['q']='%'.trim((string)$filters['q']).'%'; }
        $rows=$this->db()->all('SELECT f.*,o.order_number,o.fulfillment_status AS order_fulfillment_status,o.customer_snapshot_json,l.code AS location_code,l.name AS location_name FROM sale_fulfillments f INNER JOIN sale_orders o ON o.id=f.order_id LEFT JOIN sale_stock_locations l ON l.id=f.stock_location_id WHERE '.implode(' AND ',$where).' ORDER BY CASE f.status WHEN \'blocked\' THEN 0 WHEN \'ready_for_pickup\' THEN 1 WHEN \'partially_prepared\' THEN 2 WHEN \'preparing\' THEN 3 ELSE 4 END,COALESCE(f.due_at,f.created_at),f.id', $params);
        foreach($rows as &$row){$row['lines']=$this->db()->all('SELECT fl.*,ol.sku,ol.product_name,ol.variant_name FROM sale_fulfillment_lines fl INNER JOIN sale_order_lines ol ON ol.id=fl.order_line_id WHERE fl.fulfillment_id=? ORDER BY fl.id',[(int)$row['id']]);$row['customer']=json_decode((string)$row['customer_snapshot_json'],true)?:[];unset($row['customer_snapshot_json']);$row['next_action']=$this->nextAction((string)$row['status'],(string)$row['fulfillment_type']);}
        return $rows;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function createOperation(int $siteId, int $orderId, array $payload, ?int $actorId): array
    {
        $order=$this->db()->one('SELECT * FROM sale_orders WHERE id=? AND site_id=?',[$orderId,$siteId]);
        if($order===null) throw new SaleValidationException('sale.fulfillment.order_not_found');
        $snapshot=json_decode((string)$order['shipping_method_snapshot_json'],true)?:[];
        $type=(string)($payload['fulfillment_type']??$snapshot['type']??'shipping');
        if(!in_array($type,['shipping','pickup'],true)) throw new SaleValidationException('sale.fulfillment.type_invalid');
        $locationId=(int)($payload['stock_location_id']??$snapshot['stock_location_id']??0);
        if($locationId<1 || $this->db()->one("SELECT id FROM sale_stock_locations WHERE id=? AND site_id=? AND status='active'",[$locationId,$siteId])===null) throw new SaleValidationException('sale.fulfillment.location_required');
        if($this->states===null) throw new SaleValidationException('sale.fulfillment.operations_unavailable');
        $lines=is_array($payload['lines']??null)?$payload['lines']:[];
        $fulfillment=$this->states->createFulfillment($orderId,$lines,$actorId,(string)($payload['correlation_id']??''),isset($payload['tracking_reference'])?(string)$payload['tracking_reference']:null);
        $pickupCode=$type==='pickup'?strtoupper(substr(hash('sha256',$siteId.':'.(int)$fulfillment['id'].':'.random_bytes(8)),0,8)):null;
        $this->db()->run('UPDATE sale_fulfillments SET fulfillment_type=?,stock_location_id=?,pickup_code=?,due_at=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$type,$locationId,$pickupCode,$payload['due_at']??null,(int)$fulfillment['id']]);
        $this->states->transition('fulfillment',(int)$fulfillment['id'],'allocated',$actorId,'stock location allocated',(string)($payload['correlation_id']??''));
        return $this->operation((int)$fulfillment['id'],$siteId);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function savePreparation(int $siteId, int $fulfillmentId, int $lineId, array $payload, ?int $actorId): array
    {
        $operation=$this->operation($fulfillmentId,$siteId); $line=null;
        foreach($operation['lines'] as $candidate) if((int)$candidate['id']===$lineId){$line=$candidate;break;}
        if($line===null) throw new SaleValidationException('sale.fulfillment.line_not_found');
        if(!in_array((string)$operation['status'],['allocated','preparing','partially_prepared','blocked'],true)) throw new SaleValidationException('sale.fulfillment.not_preparable');
        $quantity=(int)($payload['prepared_quantity']??-1);
        if($quantity<0 || $quantity>(int)$line['quantity']) throw new SaleValidationException('sale.fulfillment.prepared_quantity_invalid');
        $problemCode=trim((string)($payload['problem_code']??'')); $problemNote=trim((string)($payload['problem_note']??''));
        $this->db()->run('UPDATE sale_fulfillment_lines SET prepared_quantity=?,problem_code=?,problem_note=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$quantity,$problemCode===''?null:$problemCode,$problemNote===''?null:$problemNote,$lineId]);
        $totals=$this->db()->one('SELECT SUM(quantity) AS total,SUM(prepared_quantity) AS prepared,SUM(CASE WHEN problem_code IS NOT NULL THEN 1 ELSE 0 END) AS problems FROM sale_fulfillment_lines WHERE fulfillment_id=?',[$fulfillmentId])??[];
        $status=(int)$totals['problems']>0?'blocked':((int)$totals['prepared']>0&&$totals['prepared']<$totals['total']?'partially_prepared':((int)$totals['prepared']===(int)$totals['total']?'preparing':'allocated'));
        $problem=$status==='blocked'?json_encode(['line_id'=>$lineId,'code'=>$problemCode,'note'=>$problemNote,'reported_by'=>$actorId],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):'{}';
        $this->db()->run('UPDATE sale_fulfillments SET problem_json=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$problem,$fulfillmentId]);
        $current=(string)$operation['status'];
        if($this->states!==null && $current!==$status){
            if(in_array($current,['allocated','blocked'],true)&&$status==='partially_prepared'){$this->states->transition('fulfillment',$fulfillmentId,'preparing',$actorId,'preparation started');$current='preparing';}
            if($current==='blocked'&&$status==='allocated'){$this->states->transition('fulfillment',$fulfillmentId,'allocated',$actorId,'problem resolved');$current='allocated';}
            if($current!==$status)$this->states->transition('fulfillment',$fulfillmentId,$status,$actorId,$status==='blocked'?'preparation problem':'preparation progress');
        }
        return $this->operation($fulfillmentId,$siteId);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function transitionOperation(int $siteId, int $fulfillmentId, string $status, array $payload, ?int $actorId): array
    {
        $operation=$this->operation($fulfillmentId,$siteId);
        if(in_array($status,['ready_for_pickup','shipped'],true)) foreach($operation['lines'] as $line) if((int)$line['prepared_quantity']<(int)$line['quantity']) throw new SaleValidationException('sale.fulfillment.preparation_incomplete');
        if($status==='ready_for_pickup' && (string)$operation['fulfillment_type']!=='pickup') throw new SaleValidationException('sale.fulfillment.pickup_only_transition');
        if($status==='handed_over'){
            $proof=trim((string)($payload['proof']??'')); $code=strtoupper(trim((string)($payload['pickup_code']??'')));
            if($proof==='' || !hash_equals((string)$operation['pickup_code'],$code)) throw new SaleValidationException('sale.fulfillment.handover_proof_required');
            $this->db()->run('UPDATE sale_fulfillments SET operator_proof_json=? WHERE id=?',[json_encode(['proof'=>$proof,'actor_id'=>$actorId,'recorded_at'=>gmdate('c')],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$fulfillmentId]);
        }
        if($status==='shipped' && trim((string)($payload['tracking_reference']??$operation['tracking_reference']??''))!=='') $this->db()->run('UPDATE sale_fulfillments SET tracking_reference=? WHERE id=?',[trim((string)($payload['tracking_reference']??$operation['tracking_reference'])),$fulfillmentId]);
        if($this->states===null) throw new SaleValidationException('sale.fulfillment.operations_unavailable');
        $this->states->transition('fulfillment',$fulfillmentId,$status,$actorId,(string)($payload['reason']??''),(string)($payload['correlation_id']??''),isset($payload['expected_version'])?(int)$payload['expected_version']:null);
        return $this->operation($fulfillmentId,$siteId);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function createTransfer(int $siteId, array $payload, ?int $actorId): array
    {
        $from=(int)($payload['from_location_id']??0);$to=(int)($payload['to_location_id']??0);$reason=trim((string)($payload['reason']??''));$lines=is_array($payload['lines']??null)?$payload['lines']:[];
        if($from<1||$to<1||$from===$to||$reason===''||$lines===[]) throw new SaleValidationException('sale.stock_transfer_invalid');
        foreach([$from,$to] as $location) if($this->db()->one("SELECT id FROM sale_stock_locations WHERE id=? AND site_id=? AND status='active'",[$location,$siteId])===null) throw new SaleValidationException('sale.stock_location_invalid');
        return $this->db()->transaction(function()use($siteId,$from,$to,$reason,$lines,$payload,$actorId):array{
            $number='TRF-'.gmdate('YmdHis').'-'.strtoupper(bin2hex(random_bytes(2)));$correlation=SaleStateMachineService::correlationId((string)($payload['correlation_id']??''));
            $this->db()->run("INSERT INTO sale_stock_transfers(site_id,transfer_number,from_location_id,to_location_id,status,reason,expected_at,correlation_id,created_by_iam_user_id) VALUES(?,?,?,?,'requested',?,?,?,?)",[$siteId,$number,$from,$to,$reason,$payload['expected_at']??null,$correlation,$actorId]);
            $id=$this->db()->lastInsertId();
            foreach($lines as $line){$sellable=(int)($line['sellable_id']??$line['business_variant_id']??0);$quantity=(int)($line['quantity']??0);$item=$this->db()->one('SELECT * FROM sale_inventory_items WHERE site_id=? AND sellable_id=? AND stock_location_id=?',[$siteId,$sellable,$from]);if($item===null||$quantity<1)throw new SaleValidationException('sale.stock_transfer_line_invalid');$this->db()->run('INSERT INTO sale_stock_transfer_lines(transfer_id,business_variant_id,sellable_id,sku,requested_quantity) VALUES(?,?,?,?,?)',[$id,(int)$item['business_variant_id'],(int)$item['sellable_id'],$item['sku'],$quantity]);}
            return $this->transfer($id,$siteId);
        });
    }

    /** @return list<array<string,mixed>> */
    public function transfers(int $siteId):array{$rows=$this->db()->all('SELECT t.*,a.name AS from_location_name,b.name AS to_location_name FROM sale_stock_transfers t INNER JOIN sale_stock_locations a ON a.id=t.from_location_id INNER JOIN sale_stock_locations b ON b.id=t.to_location_id WHERE t.site_id=? ORDER BY t.id DESC',[$siteId]);foreach($rows as &$r)$r['lines']=$this->db()->all('SELECT * FROM sale_stock_transfer_lines WHERE transfer_id=? ORDER BY id',[(int)$r['id']]);return $rows;}

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function shipTransfer(int $siteId,int $id,array $payload,?int $actorId):array
    {
        if($this->inventory===null)throw new SaleValidationException('sale.inventory_unavailable');$transfer=$this->transfer($id,$siteId);if((string)$transfer['status']!=='requested')throw new SaleValidationException('sale.stock_transfer_not_shippable');
        return $this->db()->transaction(function()use($transfer,$siteId,$id,$payload,$actorId):array{foreach($transfer['lines'] as $line){$quantity=(int)($payload['quantities'][(string)$line['id']]??$line['requested_quantity']);if($quantity<1||$quantity>(int)$line['requested_quantity'])throw new SaleValidationException('sale.stock_transfer_quantity_invalid');$this->inventory->adjust($siteId,(int)$line['business_variant_id'],-$quantity,$line['sku'],'Transfer '.$transfer['transfer_number'],$actorId,(int)$transfer['from_location_id'],'transfer_out','transfer:'.$id.':out:'.$line['id']);$this->db()->run('UPDATE sale_stock_transfer_lines SET shipped_quantity=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$quantity,(int)$line['id']]);}$this->db()->run("UPDATE sale_stock_transfers SET status='in_transit',shipped_by_iam_user_id=?,shipped_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP,version=version+1 WHERE id=?",[$actorId,$id]);return $this->transfer($id,$siteId);});
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function receiveTransfer(int $siteId,int $id,array $payload,?int $actorId):array
    {
        if($this->inventory===null)throw new SaleValidationException('sale.inventory_unavailable');$transfer=$this->transfer($id,$siteId);if(!in_array((string)$transfer['status'],['in_transit','partially_received','discrepancy'],true))throw new SaleValidationException('sale.stock_transfer_not_receivable');
        return $this->db()->transaction(function()use($transfer,$siteId,$id,$payload,$actorId):array{$hasVariance=false;$partial=false;foreach($transfer['lines'] as $line){$received=(int)($payload['quantities'][(string)$line['id']]??$line['shipped_quantity']);if($received<0||$received>(int)$line['shipped_quantity'])throw new SaleValidationException('sale.stock_transfer_quantity_invalid');$delta=$received-(int)$line['received_quantity'];if($delta>0)$this->inventory->adjust($siteId,(int)$line['business_variant_id'],$delta,$line['sku'],'Transfer '.$transfer['transfer_number'],$actorId,(int)$transfer['to_location_id'],'transfer_in','transfer:'.$id.':in:'.$line['id'].':'.$received);$reason=trim((string)($payload['discrepancy_reasons'][(string)$line['id']]??''));if($received!==(int)$line['shipped_quantity']){$hasVariance=true;if($reason==='')throw new SaleValidationException('sale.stock_transfer_discrepancy_reason_required');}$partial=$partial||$received<(int)$line['shipped_quantity'];$this->db()->run('UPDATE sale_stock_transfer_lines SET received_quantity=?,discrepancy_reason=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$received,$reason===''?null:$reason,(int)$line['id']]);}$status=$hasVariance?'discrepancy':($partial?'partially_received':'received');$this->db()->run('UPDATE sale_stock_transfers SET status=?,received_by_iam_user_id=?,received_at=CASE WHEN ?=\'received\' THEN CURRENT_TIMESTAMP ELSE received_at END,updated_at=CURRENT_TIMESTAMP,version=version+1 WHERE id=?',[$status,$actorId,$status,$id]);return $this->transfer($id,$siteId);});
    }

    public function cancelTransfer(int $siteId,int $id,?int $actorId):array{$transfer=$this->transfer($id,$siteId);if(!in_array((string)$transfer['status'],['draft','requested'],true))throw new SaleValidationException('sale.stock_transfer_not_cancellable');$this->db()->run("UPDATE sale_stock_transfers SET status='cancelled',cancelled_by_iam_user_id=?,cancelled_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP,version=version+1 WHERE id=?",[$actorId,$id]);return $this->transfer($id,$siteId);}

    /** @return list<array<string,mixed>> */
    public function inventorySessions(int $siteId):array{$rows=$this->db()->all('SELECT s.*,l.name AS location_name FROM sale_inventory_count_sessions s INNER JOIN sale_stock_locations l ON l.id=s.stock_location_id WHERE s.site_id=? ORDER BY s.id DESC',[$siteId]);foreach($rows as &$r)$r['lines']=$this->countLines((int)$r['id'],(bool)$r['hide_theoretical']);return $rows;}

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function createInventorySession(int $siteId,array $payload,?int $actorId):array{$location=(int)($payload['stock_location_id']??0);$label=trim((string)($payload['label']??''));if($location<1||$label===''||$this->db()->one("SELECT id FROM sale_stock_locations WHERE id=? AND site_id=? AND status='active'",[$location,$siteId])===null)throw new SaleValidationException('sale.inventory_count_invalid');return $this->db()->transaction(function()use($siteId,$location,$label,$payload,$actorId):array{$number='INV-'.gmdate('YmdHis').'-'.strtoupper(bin2hex(random_bytes(2)));$this->db()->run("INSERT INTO sale_inventory_count_sessions(site_id,stock_location_id,session_number,label,status,hide_theoretical,created_by_iam_user_id,started_at) VALUES(?,?,?,?,'counting',?,?,CURRENT_TIMESTAMP)",[$siteId,$location,$number,$label,($payload['hide_theoretical']??false)?1:0,$actorId]);$id=$this->db()->lastInsertId();$this->db()->run('INSERT INTO sale_inventory_count_lines(session_id,inventory_item_id,expected_quantity) SELECT ?,id,on_hand_quantity FROM sale_inventory_items WHERE site_id=? AND stock_location_id=? AND tracked=1',[$id,$siteId,$location]);return $this->inventorySession($id,$siteId);});}

    public function saveInventoryCount(int $siteId,int $sessionId,int $lineId,int $quantity,?string $reason,?int $actorId):array{$session=$this->inventorySession($sessionId,$siteId);if((string)$session['status']!=='counting'||$quantity<0)throw new SaleValidationException('sale.inventory_count_not_editable');$this->db()->run('UPDATE sale_inventory_count_lines SET counted_quantity=?,discrepancy_reason=?,counted_by_iam_user_id=?,counted_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND session_id=?',[$quantity,trim((string)$reason)===''?null:trim((string)$reason),$actorId,$lineId,$sessionId]);if((int)($this->db()->one('SELECT changes() AS count')['count']??0)!==1)throw new SaleValidationException('sale.inventory_count_line_not_found');return $this->inventorySession($sessionId,$siteId);}

    public function submitInventorySession(int $siteId,int $id):array{$session=$this->inventorySession($id,$siteId);if((string)$session['status']!=='counting')throw new SaleValidationException('sale.inventory_count_not_submittable');$missing=(int)($this->db()->one('SELECT COUNT(*) AS count FROM sale_inventory_count_lines WHERE session_id=? AND counted_quantity IS NULL',[$id])['count']??0);if($missing>0)throw new SaleValidationException('sale.inventory_count_incomplete');$this->db()->run("UPDATE sale_inventory_count_sessions SET status='review',submitted_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP,version=version+1 WHERE id=?",[$id]);return $this->inventorySession($id,$siteId);}

    public function approveInventorySession(int $siteId,int $id,?int $actorId):array{if($this->inventory===null)throw new SaleValidationException('sale.inventory_unavailable');$session=$this->inventorySession($id,$siteId);if((string)$session['status']!=='review')throw new SaleValidationException('sale.inventory_count_not_approvable');return $this->db()->transaction(function()use($session,$siteId,$id,$actorId):array{foreach($session['lines'] as $line){$delta=(int)$line['counted_quantity']-(int)$line['expected_quantity'];if($delta===0)continue;if(trim((string)($line['discrepancy_reason']??''))==='')throw new SaleValidationException('sale.inventory_count_discrepancy_reason_required');$this->inventory->adjust($siteId,(int)$line['business_variant_id'],$delta,$line['sku'],'Inventory '.$session['session_number'].': '.$line['discrepancy_reason'],$actorId,(int)$session['stock_location_id'],'inventory_adjustment','inventory:'.$id.':line:'.$line['id']);}$this->db()->run("UPDATE sale_inventory_count_sessions SET status='approved',approved_by_iam_user_id=?,approved_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP,version=version+1 WHERE id=?",[$actorId,$id]);return $this->inventorySession($id,$siteId);});}

    /** @return array<string,mixed> */
    private function operation(int $id,int $siteId):array{$row=$this->db()->one('SELECT f.*,o.order_number,o.customer_snapshot_json,l.code AS location_code,l.name AS location_name FROM sale_fulfillments f INNER JOIN sale_orders o ON o.id=f.order_id LEFT JOIN sale_stock_locations l ON l.id=f.stock_location_id WHERE f.id=? AND o.site_id=?',[$id,$siteId]);if($row===null)throw new SaleValidationException('sale.fulfillment.not_found');$row['lines']=$this->db()->all('SELECT fl.*,ol.sku,ol.product_name,ol.variant_name FROM sale_fulfillment_lines fl INNER JOIN sale_order_lines ol ON ol.id=fl.order_line_id WHERE fl.fulfillment_id=? ORDER BY fl.id',[$id]);$row['customer']=json_decode((string)$row['customer_snapshot_json'],true)?:[];unset($row['customer_snapshot_json']);return $row;}
    /** @return array<string,mixed> */
    private function transfer(int $id,int $siteId):array{$row=$this->db()->one('SELECT * FROM sale_stock_transfers WHERE id=? AND site_id=?',[$id,$siteId]);if($row===null)throw new SaleValidationException('sale.stock_transfer_not_found');$row['lines']=$this->db()->all('SELECT * FROM sale_stock_transfer_lines WHERE transfer_id=? ORDER BY id',[$id]);return $row;}
    /** @return array<string,mixed> */
    private function inventorySession(int $id,int $siteId):array{$row=$this->db()->one('SELECT s.*,l.name AS location_name FROM sale_inventory_count_sessions s INNER JOIN sale_stock_locations l ON l.id=s.stock_location_id WHERE s.id=? AND s.site_id=?',[$id,$siteId]);if($row===null)throw new SaleValidationException('sale.inventory_count_not_found');$row['lines']=$this->countLines($id,(bool)$row['hide_theoretical']);return $row;}
    /** @return list<array<string,mixed>> */
    private function countLines(int $id,bool $hide):array{$rows=$this->db()->all('SELECT c.*,i.business_variant_id,i.sellable_id,i.sku,r.product_name,r.variant_name FROM sale_inventory_count_lines c INNER JOIN sale_inventory_items i ON i.id=c.inventory_item_id LEFT JOIN sale_catalog_variant_refs r ON r.site_id=i.site_id AND r.sellable_id=i.sellable_id WHERE c.session_id=? ORDER BY COALESCE(r.product_name,i.sku),c.id',[$id]);if($hide)foreach($rows as &$row)if($row['counted_quantity']===null)$row['expected_quantity']=null;return $rows;}
    private function nextAction(string $status,string $type):string{return match($status){'pending'=>'allocate','allocated','partially_prepared'=>'prepare','preparing'=>$type==='pickup'?'mark_ready':'ship','ready_for_pickup'=>'hand_over','shipped'=>'confirm_delivery','blocked'=>'resolve_problem',default=>'none'};}

    /** @return list<array<string,mixed>> */
    private function activeRows(int $siteId): array
    {
        return $this->db()->all("SELECT m.*,z.code AS zone_code,z.name AS zone_name,z.country_codes_json,z.postal_prefixes_json FROM sale_fulfillment_methods m LEFT JOIN sale_fulfillment_zones z ON z.id=m.zone_id WHERE m.site_id=? AND m.status='active' AND (m.active_from IS NULL OR m.active_from<=CURRENT_TIMESTAMP) AND (m.active_until IS NULL OR m.active_until>CURRENT_TIMESTAMP) AND (z.id IS NULL OR (z.status='active' AND (z.active_from IS NULL OR z.active_from<=CURRENT_TIMESTAMP) AND (z.active_until IS NULL OR z.active_until>CURRENT_TIMESTAMP))) ORDER BY m.sort_order,m.code",[$siteId]);
    }

    /** @param array<string,mixed> $method @param array<string,mixed> $address */
    private function assertZone(array $method, array $address): void
    {
        if ($method['zone_code'] === null) return;
        $countries = json_decode((string) $method['country_codes_json'], true) ?: [];
        $prefixes = json_decode((string) $method['postal_prefixes_json'], true) ?: [];
        $country = strtoupper(trim((string) ($address['country_code'] ?? ''))); $postal = trim((string) ($address['postal_code'] ?? ''));
        if ($countries !== [] && !in_array($country, $countries, true)) throw new SaleValidationException('sale.checkout.fulfillment_address_outside_zone');
        if ($prefixes !== [] && !array_filter($prefixes, static fn(string $prefix): bool => str_starts_with($postal, $prefix))) throw new SaleValidationException('sale.checkout.fulfillment_address_outside_zone');
    }

    /** @param array<string,mixed> $address */
    private function validateAddress(array $address): void
    {
        foreach (['line1','postal_code','city','country_code'] as $field) if (trim((string)($address[$field]??''))==='') throw new SaleValidationException('sale.checkout.shipping_address_invalid');
        if (!preg_match('/^[A-Z]{2}$/',strtoupper((string)$address['country_code']))) throw new SaleValidationException('sale.checkout.shipping_address_invalid');
    }

    private function db(): Database { return $this->connection->database() ?? throw new SaleValidationException('sale.database_unavailable'); }
}
