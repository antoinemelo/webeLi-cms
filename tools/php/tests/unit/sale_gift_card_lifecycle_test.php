<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Sale\Repositories\SaleEventRepository;
use App\Modules\Sale\Repositories\SaleOrderRepository;
use App\Modules\Sale\Repositories\SalePaymentRepository;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Modules\Sale\Services\SaleEventService;
use App\Modules\Sale\Services\SaleGiftCardService;

$h=new TestHarness();
[$dir,$path,$db]=test_temp_cms_db(__DIR__.'/../../../../database/modules/sale.sql');
try{
    $connection=new SaleDatabaseConnection($path);$orders=new SaleOrderRepository($connection);$payments=new SalePaymentRepository($connection);$events=new SaleEventService(new SaleEventRepository($connection));
    $service=new SaleGiftCardService($connection,$payments,$orders,$events,'unit-gift-secret');
    $channelId=(int)($db->one("SELECT id FROM sale_channels WHERE site_id=1 AND currency='CHF' ORDER BY id LIMIT 1")['id']??0);

    $db->run("INSERT INTO sale_orders(site_id,channel_id,order_number,source,status,payment_status,currency,grand_total_minor,paid_total_minor) VALUES(1,?,'GIFT-ORIGIN','ecommerce','confirmed','paid','CHF',10000,10000)",[$channelId]);
    $origin=(int)$db->lastInsertId();
    $db->run("INSERT INTO sale_order_lines(order_id,line_number,business_product_id,business_variant_id,product_name,product_type,quantity,unit_price_minor,regular_unit_price_minor,currency,line_subtotal_minor,line_total_minor,snapshot_json) VALUES(?,1,10,11,'Bon cadeau','gift_card',1,10000,10000,'CHF',10000,10000,?)",[$origin,json_encode(['personalization'=>['recipient_name'=>'Ada','recipient_email'=>'ada@example.test','message'=>'Merci']])]);
    $issued=$service->issuePaidOrder($origin,'corr-issue');
    $h->assertSame(1,count($issued),'one paid gift line issues one card');
    $h->assertTrue(isset($issued[0]['delivery']['claim_token']),'issuance returns an opaque one-time claim token, not the code');
    $h->assertSame(1,count($service->issuePaidOrder($origin,'corr-replay')),'issuance is replayable without a duplicate card');
    $h->assertSame(1,(int)($db->one('SELECT COUNT(*) AS total FROM sale_gift_cards')['total']??0),'origin line and unit form the issuance idempotency boundary');

    $claimed=$service->claim((string)$issued[0]['delivery']['claim_token']);$code=(string)$claimed['code'];
    $h->assertTrue(str_starts_with($code,'GC-') && strlen($code)>30,'revealed code has high entropy and an explicit format');
    try{$service->claim((string)$issued[0]['delivery']['claim_token']);$h->assertTrue(false,'claim token cannot be replayed');}catch(Throwable){$h->assertTrue(true,'claim token cannot be replayed');}
    $stored=json_encode($db->all("SELECT * FROM sale_gift_cards"));
    $h->assertTrue(!str_contains((string)$stored,$code),'canonical database stores no full gift card code');

    $validation=$service->validatePublic(1,'CHF',$code,6500,'127.0.0.1|test');
    $h->assertSame(6500,(int)$validation['applicable_minor'],'public validation only reveals the applicable amount');
    $h->assertSame(false,$service->validatePublic(1,'CHF','GC-INVALID-CODE-0000000000000000',6500,'127.0.0.2|test')['valid'],'invalid lookup is neutral');

    $db->run("INSERT INTO sale_orders(site_id,channel_id,order_number,source,status,payment_status,currency,grand_total_minor) VALUES(1,?,'GIFT-USE','ecommerce','pending_payment','pending','CHF',15000)",[$channelId]);
    $useOrder=(int)$db->lastInsertId();
    $db->run("INSERT INTO sale_order_lines(order_id,line_number,business_product_id,business_variant_id,product_name,product_type,quantity,unit_price_minor,regular_unit_price_minor,currency,line_subtotal_minor,line_total_minor) VALUES(?,1,20,21,'Produit physique','physical',1,15000,15000,'CHF',15000,15000)",[$useOrder]);
    $redeemed=$service->redeemOrder($useOrder,$code,'checkout-use','corr-use');
    $h->assertSame(10000,(int)$redeemed['amount_minor'],'partial order payment consumes the available card balance atomically');
    $h->assertSame(5000,15000-(int)$orders->requireOrder($useOrder)['paid_total_minor'],'provider complement equals the remaining order balance');
    $h->assertSame(true,$service->redeemOrder($useOrder,$code,'checkout-use','corr-use')['replayed'],'same checkout key cannot debit twice');
    $h->assertSame(1,(int)($db->one("SELECT COUNT(*) AS total FROM sale_gift_card_ledger WHERE entry_type='debit'")['total']??0),'ledger contains one debit after replay');

    $transaction=(int)($db->one("SELECT id FROM sale_payment_transactions WHERE order_id=? AND provider_transaction_id LIKE 'gift-card-ledger-%'",[$useOrder])['id']??0);
    $refund=$service->refundPaymentTransaction($transaction,4000,'gift-refund-1','customer return',7);
    $h->assertSame(4000,(int)$refund['refund']['amount_minor'],'gift-funded amount is credited back to the same card');
    $detail=$service->detail(1,(int)$issued[0]['id']);
    $h->assertSame(4000,(int)$detail['balance_minor'],'refund restores the balance through a credit ledger entry');
    $h->assertTrue(!str_contains((string)json_encode($db->all('SELECT payload_json FROM sale_events')),$code),'events and CRM projection input contain no code');
    $preview=$service->adjust(1,(int)$issued[0]['id'],1000,'commercial correction',7,'adjust-1',false,'corr-adjust');
    $h->assertSame(5000,(int)$preview['new_balance_minor'],'exceptional adjustment is previewed before mutation');
    $adjusted=$service->adjust(1,(int)$issued[0]['id'],1000,'commercial correction',7,'adjust-1',true,'corr-adjust');
    $h->assertSame(true,$adjusted['applied'],'reasoned operator adjustment is appended to the ledger');
    $h->assertSame(true,$service->adjust(1,(int)$issued[0]['id'],1000,'commercial correction',7,'adjust-1',true,'corr-adjust')['replayed'],'adjustment idempotency prevents a second credit');

    try{$db->run("UPDATE sale_gift_card_ledger SET reason='tampered' WHERE id=1");$h->assertTrue(false,'ledger update must fail');}catch(Throwable){$h->assertTrue(true,'ledger update is blocked by the canonical trigger');}
    $cancelled=$service->cancel(1,(int)$issued[0]['id'],'fraud review',7,'corr-cancel');
    $h->assertSame('cancelled',(string)$cancelled['status'],'operator cancellation closes the card with a mandatory reason');
    $h->assertSame(0,(int)$cancelled['balance_minor'],'cancelled value cannot remain spendable');
    $h->assertSame(0,array_sum(array_map(static fn(array $row):int=>(int)$row['amount_delta_minor'],$db->all('SELECT amount_delta_minor FROM sale_gift_card_ledger WHERE gift_card_id=?',[(int)$issued[0]['id']]))),'ledger arithmetic reconstructs the terminal zero balance');

    $db->run("INSERT INTO sale_orders(site_id,channel_id,order_number,source,status,payment_status,currency,grand_total_minor,paid_total_minor) VALUES(1,?,'GIFT-MIXED','ecommerce','confirmed','paid','CHF',7000,7000)",[$channelId]);$mixedOrder=(int)$db->lastInsertId();
    $db->run("INSERT INTO sale_order_lines(order_id,line_number,business_product_id,business_variant_id,product_name,product_type,quantity,unit_price_minor,regular_unit_price_minor,currency,line_subtotal_minor,line_total_minor) VALUES(?,1,30,31,'Bon cadeau mixte','gift_card',1,5000,5000,'CHF',5000,5000)",[$mixedOrder]);
    $db->run("INSERT INTO sale_order_lines(order_id,line_number,business_product_id,business_variant_id,product_name,product_type,quantity,unit_price_minor,regular_unit_price_minor,currency,line_subtotal_minor,line_total_minor) VALUES(?,2,32,33,'Service','service',1,2000,2000,'CHF',2000,2000)",[$mixedOrder]);
    $mixed=$service->issuePaidOrder($mixedOrder,'corr-mixed');$h->assertSame(1,count($mixed),'a paid mixed service and gift-card order issues only its gift line');
    $resend=$service->resend(1,(int)$mixed[0]['id'],7,'corr-resend');
    try{$service->claim((string)$mixed[0]['delivery']['claim_token']);$h->assertTrue(false,'resend must invalidate the previous reveal token');}catch(Throwable){$h->assertTrue(true,'resend invalidates the previous reveal token');}
    $rotated=$service->claim((string)$resend['claim_token']);$h->assertTrue(str_starts_with((string)$rotated['code'],'GC-'),'permissioned resend rotates the code and creates a new one-time delivery');
    $competing=[];foreach(['A','B'] as $suffix){$db->run("INSERT INTO sale_orders(site_id,channel_id,order_number,source,status,payment_status,currency,grand_total_minor) VALUES(1,?,?,'ecommerce','pending_payment','pending','CHF',5000)",[$channelId,'GIFT-RACE-'.$suffix]);$candidate=(int)$db->lastInsertId();$db->run("INSERT INTO sale_order_lines(order_id,line_number,business_product_id,business_variant_id,product_name,product_type,quantity,unit_price_minor,regular_unit_price_minor,currency,line_subtotal_minor,line_total_minor) VALUES(?,1,50,51,'Produit concurrent','physical',1,5000,5000,'CHF',5000,5000)",[$candidate]);$competing[]=$candidate;}
    $service->redeemOrder($competing[0],(string)$rotated['code'],'race-a','corr-race');
    try{$service->redeemOrder($competing[1],(string)$rotated['code'],'race-b','corr-race');$h->assertTrue(false,'a competing checkout must not spend an already depleted card');}catch(Throwable){$h->assertTrue(true,'compare-and-swap prevents a second competing checkout debit');}
    $h->assertSame(1,(int)($db->one("SELECT COUNT(*) AS total FROM sale_gift_card_ledger WHERE gift_card_id=? AND entry_type='debit'",[(int)$mixed[0]['id']])['total']??0),'competing checkouts leave exactly one debit');
    $mixedTx=(int)($db->one("SELECT id FROM sale_payment_transactions WHERE order_id=? AND provider_transaction_id LIKE 'gift-card-ledger-%'",[$competing[0]])['id']??0);$service->refundPaymentTransaction($mixedTx,5000,'race-refund','checkout released',7);
    $db->run("UPDATE sale_gift_cards SET expires_at=datetime('now','-1 day') WHERE id=?",[(int)$mixed[0]['id']]);
    $h->assertSame(false,$service->validatePublic(1,'CHF',(string)$rotated['code'],5000,'expiry-test')['valid'],'expired card is rejected and expired through a ledger entry');
    $h->assertSame('expired',(string)$service->detail(1,(int)$mixed[0]['id'])['status'],'lazy expiration updates the canonical status');

    $db->run("INSERT INTO sale_orders(site_id,channel_id,order_number,source,status,payment_status,currency,grand_total_minor,paid_total_minor) VALUES(1,?,'GIFT-UNPAID','ecommerce','pending_payment','pending','CHF',3000,0)",[$channelId]);$unpaid=(int)$db->lastInsertId();
    $db->run("INSERT INTO sale_order_lines(order_id,line_number,business_product_id,business_variant_id,product_name,product_type,quantity,unit_price_minor,regular_unit_price_minor,currency,line_subtotal_minor,line_total_minor) VALUES(?,1,40,41,'Bon non payé','gift_card',1,3000,3000,'CHF',3000,3000)",[$unpaid]);
    try{$service->issuePaidOrder($unpaid);$h->assertTrue(false,'unpaid order must not issue a gift card');}catch(Throwable){$h->assertTrue(true,'issuance is refused before confirmed payment');}
    $db->run('UPDATE sale_gift_card_policies SET public_attempt_limit=3 WHERE site_id=1 AND currency=\'CHF\'');
    for($attempt=0;$attempt<3;$attempt++)$service->validatePublic(1,'CHF','GC-INVALID-AAAA-BBBB-CCCC-DDDD-EEEE-'.$attempt,1000,'brute-force-client');
    try{$service->validatePublic(1,'CHF','GC-INVALID-RATE-LIMIT-AAAA-BBBB-CCCC',1000,'brute-force-client');$h->assertTrue(false,'rate limiter must stop repeated enumeration');}catch(Throwable){$h->assertTrue(true,'persistent rate limiter stops repeated enumeration');}
    try{$service->validatePublic(999,'CHF',(string)$rotated['code'],1000,'other-site');$h->assertTrue(false,'another site cannot validate the code');}catch(Throwable){$h->assertTrue(true,'site and currency policy isolate gift cards');}

    exit($h->finish('UNIT gift card lifecycle M8.7'));
}finally{test_remove_tree($dir);}
