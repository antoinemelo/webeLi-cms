<?php
declare(strict_types=1);

require_once __DIR__.'/../TestHarness.php';
require_once __DIR__.'/../../../../backend/bootstrap/runtime.php';

use App\Modules\Sale\Exceptions\SaleValidationException;
use App\Modules\Sale\Repositories\SaleEventRepository;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Modules\Sale\Services\SaleEventService;
use App\Modules\Sale\Services\SaleFulfillmentService;
use App\Modules\Sale\Services\SaleOrderDocumentService;
use App\Modules\Sale\Services\SaleOrderNotificationService;
use App\Modules\Sale\Services\SaleStateMachineService;

$h=new TestHarness();
[$dir,$path,$db]=test_temp_cms_db(__DIR__.'/../../../../database/modules/sale.sql');
try{
    $connection=new SaleDatabaseConnection($path);
    $events=new SaleEventService(new SaleEventRepository($connection));
    $notifications=new SaleOrderNotificationService($connection,$events);
    $states=new SaleStateMachineService($db,$events);
    $fulfillment=new SaleFulfillmentService($connection,null,$states,$notifications);
    $documents=new SaleOrderDocumentService($connection);
    $channel=(int)$db->one("SELECT id FROM sale_channels WHERE site_id=1 AND code='web-main'")['id'];
    $location=(int)$db->one("SELECT id FROM sale_stock_locations WHERE site_id=1 AND code='channel-default'")['id'];
    $db->run("INSERT INTO sale_orders(site_id,channel_id,order_number,source,status,payment_status,fulfillment_status,currency,customer_snapshot_json,billing_address_json,shipping_address_json,shipping_method_snapshot_json,payment_method_snapshot_json,subtotal_minor,tax_total_minor,shipping_total_minor,grand_total_minor,paid_total_minor,placed_at) VALUES(1,?,'M88-ORDER-1','ecommerce','confirmed','paid','unfulfilled','CHF','{\"email\":\"ada@example.test\",\"first_name\":\"Ada\",\"locale\":\"fr-CH\"}','{\"city\":\"Lausanne\"}','{\"city\":\"Lausanne\"}','{\"type\":\"shipping\",\"label\":\"Standard\"}','{\"code\":\"card\",\"secret\":\"never-copy\"}',2900,235,900,3800,3800,CURRENT_TIMESTAMP)",[$channel]);
    $order=(int)$db->lastInsertId();
    $db->run("INSERT INTO sale_order_lines(order_id,line_number,business_product_id,business_variant_id,sellable_id,sku,product_name,product_type,quantity,unit_price_minor,regular_unit_price_minor,currency,line_subtotal_minor,line_tax_minor,line_total_minor,snapshot_json) VALUES(?,1,10,11,11,'BOTTLE-BLUE','Gourde bleue','physical',1,2900,2900,'CHF',2900,235,2900,'{}')",[$order]);
    $line=(int)$db->lastInsertId();

    $confirmation=$documents->issue($order,'order_confirmation','fr',1,['order_policy'=>['payment_timing'=>'when_available','expected_availability_at'=>'2026-08-15']]);
    $h->assertTrue(str_contains((string)$confirmation['printable_text'],'Aucune facture finale'),'deferred confirmation uses order vocabulary and explicitly excludes a final invoice');
    $h->assertTrue(!str_contains(json_encode($confirmation['snapshot'])?:'','never-copy'),'payment secrets are not copied to the document snapshot');
    $db->run("UPDATE sale_orders SET payment_status='refunded',paid_total_minor=3800,refunded_total_minor=3800 WHERE id=?",[$order]);
    $replayed=$documents->issue($order,'order_confirmation','fr',1,['order_policy'=>['payment_timing'=>'when_available','expected_availability_at'=>'2026-08-15']]);
    $h->assertSame((int)$confirmation['id'],(int)$replayed['id'],'later financial state does not create a new order confirmation');
    $db->run("UPDATE sale_orders SET payment_status='paid',paid_total_minor=3800,refunded_total_minor=0 WHERE id=?",[$order]);

    $queued=$notifications->queue($order,'order_confirmed','fr',null,(int)$confirmation['id'],1,'confirmation:m88');
    $duplicate=$notifications->queue($order,'order_confirmed','fr',null,(int)$confirmation['id'],1,'confirmation:m88');
    $h->assertSame((int)$queued['id'],(int)$duplicate['id'],'transactional notification replay is idempotent');
    $outbox=(string)$db->one('SELECT payload_json FROM sale_outbox WHERE id=?',[(int)$queued['outbox_id']])['payload_json'];
    $h->assertTrue(!str_contains($outbox,'ada@example.test')&&!str_contains($outbox,'never-copy'),'outbox payload stays minimal and contains neither email nor payment secret');
    $resent=$notifications->resend(1,(int)$queued['id'],2);
    $h->assertSame((int)$queued['id'],(int)$resent['resend_of_id'],'explicit resend keeps its audit parent');

    $operation=$fulfillment->createOperation(1,$order,['fulfillment_type'=>'shipping','stock_location_id'=>$location,'lines'=>[['order_line_id'=>$line,'quantity'=>1]]],1);
    $operation=$fulfillment->savePreparation(1,(int)$operation['id'],(int)$operation['lines'][0]['id'],['prepared_quantity'=>1],1);
    $h->expectException(fn()=>$fulfillment->transitionOperation(1,(int)$operation['id'],'shipped',['carrier_code'=>'manual','tracking_reference'=>'TRACK-1','tracking_url'=>'http://localhost/private'],1),SaleValidationException::class,'unsafe tracking URL is rejected before shipment');
    $h->expectException(fn()=>$fulfillment->transitionOperation(1,(int)$operation['id'],'shipped',['carrier_code'=>'dhl','tracking_reference'=>'TRACK-1','tracking_url'=>'https://tracking.example.test/T1'],1),SaleValidationException::class,'known carrier rejects a mismatching tracking domain');
    $operation=$fulfillment->transitionOperation(1,(int)$operation['id'],'shipped',['carrier_code'=>'swiss_post','tracking_reference'=>'TRACK-1','tracking_url'=>'https://service.post.ch/track/T1'],1);
    $h->assertSame('https://service.post.ch/track/T1',$operation['tracking_url'],'validated HTTPS tracking is stored on the canonical fulfillment');
    $h->assertSame(1,(int)$db->one("SELECT COUNT(*) AS count FROM sale_order_notifications WHERE order_id=? AND notification_type='shipment_sent'",[$order])['count'],'shipment queues one customer notification');
    $first=$fulfillment->recordTrackingEvent(1,(int)$operation['id'],['provider_event_id'=>'post:event:1','event_type'=>'parcel.exception','event_status'=>'exception','message'=>'Delivery delayed','occurred_at'=>'2026-07-17 10:00:00'],2);
    $again=$fulfillment->recordTrackingEvent(1,(int)$operation['id'],['provider_event_id'=>'post:event:1','event_type'=>'parcel.exception','event_status'=>'exception','message'=>'Duplicate','occurred_at'=>'2026-07-17 10:00:00'],2);
    $h->assertSame((int)$first['id'],(int)$again['id'],'carrier event replay is idempotent');
    $h->assertSame('shipped',(string)$db->one('SELECT status FROM sale_fulfillments WHERE id=?',[(int)$operation['id']])['status'],'provider exception remains separate from the business fulfillment state');
    $h->assertSame(1,(int)$db->one("SELECT COUNT(*) AS count FROM sale_fulfillment_tracking_events WHERE fulfillment_id=?",[(int)$operation['id']])['count'],'provider replay does not duplicate tracking chronology');
}finally{$db=null;test_remove_tree($dir);}
exit($h->finish('UNIT M8.8 order logistics tracking and notifications'));
