<?php
declare(strict_types=1);
require_once __DIR__.'/../TestHarness.php';
require_once __DIR__.'/../../../../backend/bootstrap/runtime.php';

use App\Modules\Business\Services\BusinessDatabaseConnection;
use App\Modules\Sale\Repositories\SaleInventoryRepository;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Modules\Sale\Services\SaleFulfillmentService;
use App\Modules\Sale\Services\SaleInventoryReconciliationService;
use App\Modules\Sale\Services\SaleInventoryService;
use App\Modules\Sale\Services\SaleReturnService;
use App\Modules\Sale\Services\SaleStateMachineService;

$h=new TestHarness();
[$businessDir,$businessPath,$businessDb]=test_temp_cms_db(__DIR__.'/../../../../database/modules/business.sql');
[$saleDir,$salePath,$saleDb]=test_temp_cms_db(__DIR__.'/../../../../database/modules/sale.sql');
try{
    $variant=$businessDb->one("SELECT id,sku FROM business_product_variants WHERE sku='DEMO-GOURDE-BLEU'");$variantId=(int)$variant['id'];$sku=(string)$variant['sku'];
    $saleDb=null;$connection=new SaleDatabaseConnection($salePath);$saleDb=$connection->database();$inventory=new SaleInventoryService(new SaleInventoryRepository($connection));$states=new SaleStateMachineService($saleDb);$fulfillment=new SaleFulfillmentService($connection,$inventory,$states);$returns=new SaleReturnService($connection,$states,$inventory);
    $reconciliation=new SaleInventoryReconciliationService($connection,new BusinessDatabaseConnection($businessPath),$inventory,$saleDir.'/backups');
    $empty=$reconciliation->run(1);$h->assertSame(0,(int)$empty['items_checked'],'an empty database built from the current canonical schema is supported');$h->assertSame(0,(int)$empty['differences_count'],'empty reconstruction is clean without migration');
    $channel=(int)$saleDb->one("SELECT id FROM sale_channels WHERE site_id=1 AND code='admin-manual'")['id'];$main=(int)$saleDb->one("SELECT id FROM sale_stock_locations WHERE site_id=1 AND code='channel-default'")['id'];
    $saleDb->run("INSERT INTO sale_stock_locations(site_id,code,name,location_type,status) VALUES(1,'m6-branch','M6 branch','external','active')");$branch=(int)$saleDb->lastInsertId();

    $inventory->adjust(1,$variantId,12,$sku,'M6 initial stock',1,$main,'receipt','m6:initial-stock');
    $h->assertSame(12,(int)$saleDb->one('SELECT on_hand_quantity FROM sale_inventory_items WHERE business_variant_id=? AND stock_location_id=?',[$variantId,$main])['on_hand_quantity'],'initial stock is represented by a movement');
    $saleDb->run("INSERT INTO sale_carts(site_id,channel_id,status,currency) VALUES(1,?,'active','CHF')",[$channel]);$cart=(int)$saleDb->lastInsertId();
    $reservation=$inventory->reserveForCart(1,$cart,['business_variant_id'=>$variantId,'sellable_id'=>$variantId,'sku'=>$sku,'track_stock'=>true,'stock_location_id'=>$main,'metadata'=>['available_quantity'=>12]],3,1800);
    $h->assertSame('active',$reservation['status'],'reservation is included in the complete scenario');

    $saleDb->run("INSERT INTO sale_orders(site_id,channel_id,order_number,source,status,payment_status,fulfillment_status,currency,source_cart_id,customer_snapshot_json,billing_address_json,shipping_address_json,shipping_method_snapshot_json,grand_total_minor,placed_at) VALUES(1,?,'M6-REBUILD-1','ecommerce','confirmed','paid','unfulfilled','CHF',?,'{}','{}','{}','{\"type\":\"shipping\",\"stock_location_id\":$main}',3000,CURRENT_TIMESTAMP)",[$channel,$cart]);$order=(int)$saleDb->lastInsertId();
    $saleDb->run("INSERT INTO sale_order_lines(order_id,line_number,business_product_id,business_variant_id,sku,product_name,product_type,quantity,unit_price_minor,regular_unit_price_minor,currency,line_subtotal_minor,line_total_minor,snapshot_json) VALUES(?,1,900,?,?, 'M6 product','physical',3,1000,1000,'CHF',3000,3000,'{}')",[$order,$variantId,$sku]);$orderLine=(int)$saleDb->lastInsertId();
    $saleDb->run("INSERT INTO sale_payment_transactions(order_id,transaction_type,status,amount_minor,currency,provider_transaction_id,processed_at) VALUES(?,'payment','succeeded',3000,'CHF','m6-payment',CURRENT_TIMESTAMP)",[$order]);
    $h->assertSame(1,(int)$saleDb->one("SELECT COUNT(*) AS count FROM sale_payment_transactions WHERE order_id=? AND status='succeeded'",[$order])['count'],'payment is included before stock sale consumption');
    $inventory->consumeCartReservations($cart,$order);
    $h->assertSame(9,(int)$saleDb->one('SELECT on_hand_quantity FROM sale_inventory_items WHERE business_variant_id=? AND stock_location_id=?',[$variantId,$main])['on_hand_quantity'],'sale consumes the confirmed reservation once');

    $shipment=$fulfillment->createOperation(1,$order,['fulfillment_type'=>'shipping','stock_location_id'=>$main,'lines'=>[['order_line_id'=>$orderLine,'quantity'=>1]]],1);
    $shipment=$fulfillment->savePreparation(1,(int)$shipment['id'],(int)$shipment['lines'][0]['id'],['prepared_quantity'=>1],1);$shipment=$fulfillment->transitionOperation(1,(int)$shipment['id'],'shipped',['tracking_reference'=>'M6-TRACK'],1);
    $h->assertSame('partially_fulfilled',$saleDb->one('SELECT fulfillment_status FROM sale_orders WHERE id=?',[$order])['fulfillment_status'],'partial fulfillment remains distinct from the stock sale');
    $return=$returns->request($order,[['order_line_id'=>$orderLine,'quantity'=>1,'stock_disposition'=>'sellable']],'M6 return',1,'m6:return');foreach(['approved','received','completed'] as $status)$return=$returns->transition((int)$return['id'],$status,1,'M6 return');
    $h->assertSame('completed',$return['status'],'return is restocked through its own movement');
    $inventory->adjust(1,$variantId,1,$sku,'M6 corrective movement',1,$main,'correction','m6:correction');
    $h->assertSame(1,(int)$saleDb->one("SELECT COUNT(*) AS count FROM sale_stock_movements WHERE idempotency_key='m6:correction'")['count'],'corrective movement is explicit');

    $transfer=$fulfillment->createTransfer(1,['from_location_id'=>$main,'to_location_id'=>$branch,'reason'=>'M6 transfer','lines'=>[['sellable_id'=>$variantId,'quantity'=>2]]],1);$transfer=$fulfillment->shipTransfer(1,(int)$transfer['id'],[],1);$transfer=$fulfillment->receiveTransfer(1,(int)$transfer['id'],[],1);
    $h->assertSame('received',$transfer['status'],'transfer contributes separate origin and destination movements');
    $saleDb->run("INSERT INTO sale_carts(site_id,channel_id,status,currency) VALUES(1,?,'active','CHF')",[$channel]);$expiringCart=(int)$saleDb->lastInsertId();$expiring=$inventory->reserveForCart(1,$expiringCart,['business_variant_id'=>$variantId,'sellable_id'=>$variantId,'sku'=>$sku,'track_stock'=>true,'stock_location_id'=>$main,'metadata'=>['available_quantity'=>8]],1,60);$saleDb->run("UPDATE sale_stock_reservations SET expires_at=datetime('now','-1 minute') WHERE id=?",[(int)$expiring['id']]);
    $h->assertSame(1,$inventory->expireDueReservations(1),'expiration releases the final scenario reservation');

    $preview=$reconciliation->run(1);$h->assertSame('dry_run',$preview['mode'],'complete scenario reconstruction starts with a dry-run');$h->assertTrue((int)$preview['differences_count']>=1,'stale Business Shop projection is detected');
    $report=$reconciliation->run(1,true,1,'M6 scenario projection rebuild');
    $h->assertSame(0,(int)$report['remaining_differences_count'],'repair converges movement, reservation and Shop invariants');
    foreach(['current_equals_movement_sum','available_equals_physical_minus_reserved','movement_chain_valid','business_shop_projection_current'] as $invariant)$h->assertSame(true,$report['invariants'][$invariant],$invariant.' is proven');
    foreach(['reservations','fulfillments','returns','transfers','movements'] as $relation)$h->assertTrue((int)$report['relations_checked'][$relation]>0,$relation.' relation is compared');
    $backupPath=(string)$report['backup']['path'];$h->assertTrue(is_file($backupPath.'/sale.sqlite'),'repair backup contains Sale data from the current canonical schema');
    $restoredSalePath=$saleDir.'/restored-sale.sqlite';$restoredBusinessPath=$businessDir.'/restored-business.sqlite';copy($backupPath.'/sale.sqlite',$restoredSalePath);copy($backupPath.'/business.sqlite',$restoredBusinessPath);
    $restoredSale=new App\Core\Database($restoredSalePath);$restoredBusiness=new App\Core\Database($restoredBusinessPath);
    $h->assertTrue((int)$restoredSale->one('SELECT COUNT(*) AS count FROM sale_stock_movements')['count']>0,'Sale backup restores into a fresh SQLite file without migration');
    $h->assertTrue((int)$restoredBusiness->one('SELECT COUNT(*) AS count FROM business_product_variants')['count']>0,'Business backup restores into a fresh SQLite file without migration');
}finally{$businessDb=null;$saleDb=null;gc_collect_cycles();test_remove_tree($businessDir);test_remove_tree($saleDir);}
exit($h->finish('UNIT M6 stock reconstruction complete scenario'));
