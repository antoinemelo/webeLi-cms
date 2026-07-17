<?php
declare(strict_types=1);
require_once __DIR__.'/../TestHarness.php';
require_once __DIR__.'/../../../../backend/bootstrap/runtime.php';

use App\Modules\Sale\Exceptions\SaleValidationException;
use App\Modules\Sale\Repositories\SaleInventoryRepository;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Modules\Sale\Services\SaleFulfillmentService;
use App\Modules\Sale\Services\SaleInventoryService;
use App\Modules\Sale\Services\SaleStateMachineService;

$h=new TestHarness();
[$dir,$path,$db]=test_temp_cms_db(__DIR__.'/../../../../database/modules/sale.sql');
try{
    $connection=new SaleDatabaseConnection($path);$inventory=new SaleInventoryService(new SaleInventoryRepository($connection));$states=new SaleStateMachineService($db);$service=new SaleFulfillmentService($connection,$inventory,$states);
    $channel=(int)$db->one("SELECT id FROM sale_channels WHERE site_id=1 AND code='admin-manual'")['id'];
    $main=(int)$db->one("SELECT id FROM sale_stock_locations WHERE site_id=1 AND code='channel-default'")['id'];
    $db->run("INSERT INTO sale_stock_locations(site_id,code,name,location_type,status) VALUES(1,'branch','Agence','external','active')");$branch=(int)$db->lastInsertId();
    $inventory->adjust(1,701,10,'SKU-701','Opening stock',1,$main,'receipt','opening-701');

    $db->run("INSERT INTO sale_orders(site_id,channel_id,order_number,source,status,payment_status,fulfillment_status,currency,customer_snapshot_json,billing_address_json,shipping_address_json,shipping_method_snapshot_json,grand_total_minor) VALUES(1,?,'LOG-UNPAID','ecommerce','pending_payment','pending','unfulfilled','CHF','{\"name\":\"Client\"}','{}','{}','{\"type\":\"pickup\"}',1000)",[$channel]);$unpaidOrder=(int)$db->lastInsertId();
    $db->run("INSERT INTO sale_order_lines(order_id,line_number,business_product_id,business_variant_id,sku,product_name,product_type,quantity,unit_price_minor,regular_unit_price_minor,currency,line_subtotal_minor,line_total_minor,snapshot_json) VALUES(?,1,70,701,'SKU-701','Produit impayé','physical',1,1000,1000,'CHF',1000,1000,'{}')",[$unpaidOrder]);$unpaidLine=(int)$db->lastInsertId();
    $h->expectException(fn()=>$service->createOperation(1,$unpaidOrder,['fulfillment_type'=>'pickup','stock_location_id'=>$main,'lines'=>[['order_line_id'=>$unpaidLine,'quantity'=>1]]],1),SaleValidationException::class,'unpaid order cannot enter fulfillment through the API');

    $db->run("INSERT INTO sale_orders(site_id,channel_id,order_number,source,status,payment_status,fulfillment_status,currency,customer_snapshot_json,billing_address_json,shipping_address_json,shipping_method_snapshot_json,grand_total_minor,placed_at) VALUES(1,?,'LOG-1','ecommerce','confirmed','paid','unfulfilled','CHF','{\"name\":\"Ada\"}','{}','{}','{\"type\":\"pickup\"}',2000,CURRENT_TIMESTAMP)",[$channel]);$order=(int)$db->lastInsertId();
    $db->run("INSERT INTO sale_order_lines(order_id,line_number,business_product_id,business_variant_id,sku,product_name,product_type,quantity,unit_price_minor,regular_unit_price_minor,currency,line_subtotal_minor,line_total_minor,snapshot_json) VALUES(?,1,70,701,'SKU-701','Produit logistique','physical',2,1000,1000,'CHF',2000,2000,'{}')",[$order]);$orderLine=(int)$db->lastInsertId();
    $pickup=$service->createOperation(1,$order,['fulfillment_type'=>'pickup','stock_location_id'=>$main,'lines'=>[['order_line_id'=>$orderLine,'quantity'=>1]]],1);
    $h->assertSame('allocated',$pickup['status'],'fulfillment snapshots its stock allocation');
    $h->assertSame('prepare',$pickup['next_action'],'an incomplete allocation guides the operator to preparation rather than shipping');
    $h->assertTrue(trim((string)$pickup['pickup_code'])!=='','pickup receives a customer code');
    $h->expectException(fn()=>$service->createOperation(1,$order,['fulfillment_type'=>'pickup','stock_location_id'=>$main,'lines'=>[['order_line_id'=>$orderLine,'quantity'=>2]]],1),SaleValidationException::class,'pending allocations cannot over-allocate an order line');
    $pickup=$service->savePreparation(1,(int)$pickup['id'],(int)$pickup['lines'][0]['id'],['prepared_quantity'=>1],1);
    $h->assertSame('preparing',$pickup['status'],'complete preparation remains ready for the operator decision');
    $h->assertSame('mark_ready',$pickup['next_action'],'pickup handover becomes available only after every line is prepared');
    $pickup=$service->savePreparation(1,(int)$pickup['id'],(int)$pickup['lines'][0]['id'],['prepared_quantity'=>0],1);
    $h->assertSame('allocated',$pickup['status'],'operator can correct preparation back to zero without losing the task');
    $h->assertSame('prepare',$pickup['next_action'],'corrected incomplete preparation cannot suggest a delivery transition');
    $pickup=$service->savePreparation(1,(int)$pickup['id'],(int)$pickup['lines'][0]['id'],['prepared_quantity'=>1],1);
    $pickup=$service->transitionOperation(1,(int)$pickup['id'],'ready_for_pickup',[],1);
    $db->run("UPDATE sale_orders SET payment_status='pending' WHERE id=?",[$order]);
    $h->expectException(fn()=>$service->transitionOperation(1,(int)$pickup['id'],'handed_over',['pickup_code'=>$pickup['pickup_code'],'proof'=>'ID card checked'],1),SaleValidationException::class,'handover is rejected server-side when payment is not proved');
    $db->run("UPDATE sale_orders SET payment_status='paid' WHERE id=?",[$order]);
    $h->expectException(fn()=>$service->transitionOperation(1,(int)$pickup['id'],'handed_over',['pickup_code'=>'WRONG','proof'=>'ID card'],1),SaleValidationException::class,'handover requires matching pickup code');
    $pickup=$service->transitionOperation(1,(int)$pickup['id'],'handed_over',['pickup_code'=>$pickup['pickup_code'],'proof'=>'ID card checked'],1);
    $h->assertSame('handed_over',$pickup['status'],'pickup handover is explicit and proved');
    $h->assertSame('partially_fulfilled',$db->one('SELECT fulfillment_status FROM sale_orders WHERE id=?',[$order])['fulfillment_status'],'one of two units produces partial fulfillment');

    $transfer=$service->createTransfer(1,['from_location_id'=>$main,'to_location_id'=>$branch,'reason'=>'Branch replenishment','lines'=>[['sellable_id'=>701,'quantity'=>3]]],1);
    $h->assertSame('requested',$transfer['status'],'transfer request does not move stock');
    $h->assertSame(10,(int)$db->one('SELECT on_hand_quantity FROM sale_inventory_items WHERE business_variant_id=701 AND stock_location_id=?',[$main])['on_hand_quantity'],'request keeps origin unchanged');
    $transfer=$service->shipTransfer(1,(int)$transfer['id'],[],1);
    $h->assertSame(7,(int)$db->one('SELECT on_hand_quantity FROM sale_inventory_items WHERE business_variant_id=701 AND stock_location_id=?',[$main])['on_hand_quantity'],'shipment creates transfer out only');
    $transfer=$service->receiveTransfer(1,(int)$transfer['id'],[],1);
    $h->assertSame('received',$transfer['status'],'matching receipt closes transfer');
    $h->assertSame(3,(int)$db->one('SELECT on_hand_quantity FROM sale_inventory_items WHERE business_variant_id=701 AND stock_location_id=?',[$branch])['on_hand_quantity'],'receipt creates destination stock');
    $inventory->adjust(1,702,4,'SKU-702','Opening stock',1,$main,'receipt','opening-702');
    $variance=$service->createTransfer(1,['from_location_id'=>$main,'to_location_id'=>$branch,'reason'=>'Variance scenario','lines'=>[['sellable_id'=>702,'quantity'=>2]]],1);
    $variance=$service->shipTransfer(1,(int)$variance['id'],[],1);$varianceLine=$variance['lines'][0];
    $variance=$service->receiveTransfer(1,(int)$variance['id'],['quantities'=>[(string)$varianceLine['id']=>1],'discrepancy_reasons'=>[(string)$varianceLine['id']=>'One parcel missing']],1);
    $h->assertSame('discrepancy',$variance['status'],'partial receipt requires and preserves a discrepancy reason');
    $variance=$service->receiveTransfer(1,(int)$variance['id'],['quantities'=>[(string)$varianceLine['id']=>2]],1);
    $h->assertSame('received',$variance['status'],'later receipt can resolve an in-transit discrepancy idempotently');

    $session=$service->createInventorySession(1,['stock_location_id'=>$branch,'label'=>'Branch count','hide_theoretical'=>true],1);
    $line=array_values(array_filter($session['lines'],static fn(array $row):bool=>(int)$row['business_variant_id']===701))[0];$h->assertSame(null,$line['expected_quantity'],'blind count hides theoretical until entered');
    $session=$service->saveInventoryCount(1,(int)$session['id'],(int)$line['id'],5,'Two units found',1);
    $other=array_values(array_filter($session['lines'],static fn(array $row):bool=>(int)$row['business_variant_id']===702))[0];
    $session=$service->saveInventoryCount(1,(int)$session['id'],(int)$other['id'],2,null,1);
    $session=$service->submitInventorySession(1,(int)$session['id']);$h->assertSame('review',$session['status'],'complete count enters review');
    $session=$service->approveInventorySession(1,(int)$session['id'],2);$h->assertSame('approved',$session['status'],'separate permission path approves inventory');
    $h->assertSame(5,(int)$db->one('SELECT on_hand_quantity FROM sale_inventory_items WHERE business_variant_id=701 AND stock_location_id=?',[$branch])['on_hand_quantity'],'approval applies audited variance');
    $h->assertSame(1,(int)$db->one("SELECT COUNT(*) AS count FROM sale_stock_movements WHERE movement_type='inventory_adjustment'")['count'],'inventory variance produces one ledger movement');
}finally{$db=null;test_remove_tree($dir);}
exit($h->finish('UNIT sale fulfillment transfers pickup and inventory'));
