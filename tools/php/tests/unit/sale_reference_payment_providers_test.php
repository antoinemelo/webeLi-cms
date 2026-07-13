<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Sale\Exceptions\SalePaymentException;
use App\Modules\Sale\Payments\BankTransferPaymentProvider;
use App\Modules\Sale\Payments\DeterministicTestPaymentProvider;
use App\Modules\Sale\Payments\ManualPaymentProvider;
use App\Modules\Sale\Payments\PaymentProviderRegistry;
use App\Modules\Sale\Repositories\SaleEventRepository;
use App\Modules\Sale\Repositories\SaleIdempotencyRepository;
use App\Modules\Sale\Repositories\SaleOrderRepository;
use App\Modules\Sale\Repositories\SalePaymentRepository;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Modules\Sale\Services\SaleEventService;
use App\Modules\Sale\Services\SaleIdempotencyService;
use App\Modules\Sale\Services\SalePaymentService;
use App\Modules\Sale\Services\SaleStateMachineService;

$h=new TestHarness();
[$dir,$path,$db]=test_temp_cms_db(__DIR__.'/../../../../database/modules/sale.sql');
try {
    $connection=new SaleDatabaseConnection($path); $serviceDb=$connection->database();
    $testRegistry=new PaymentProviderRegistry(null,$serviceDb,'reference-provider-secret','test');
    foreach(['manual_card','bank_transfer','test'] as $key) $h->assertTrue(in_array($key,$testRegistry->keys(),true),'reference provider is registered: '.$key);
    $productionRegistry=new PaymentProviderRegistry(null,$serviceDb,'reference-provider-secret','production');
    $h->assertTrue(!in_array('test',$productionRegistry->keys(),true),'deterministic test provider is absent in production');
    $h->assertTrue(!in_array('sandbox',$productionRegistry->keys(),true),'sandbox provider is absent in production');
    $h->expectException(fn()=> $productionRegistry->get('test'),SalePaymentException::class,'production cannot resolve the test provider');

    $bank=new BankTransferPaymentProvider();
    $instructions=$bank->createIntent(['intent_id'=>1,'order_id'=>42,'amount_minor'=>1290,'currency'=>'CHF','idempotency_key'=>'bank-reference','provider_config'=>['beneficiary'=>'Démo SA','iban'=>'CH00 TEST','expected_delay'=>'2 jours']]);
    $h->assertSame('requires_payment',$instructions['status'],'bank transfer remains pending');
    $h->assertSame('Démo SA',$instructions['instructions']['beneficiary']??null,'bank instructions contain beneficiary');
    $h->assertSame(1290,$instructions['instructions']['amount_minor']??null,'bank instructions freeze amount');
    $h->assertTrue(str_starts_with((string)$instructions['provider_reference'],'VIR-42-'),'bank reconciliation reference is deterministic');

    $manual=new ManualPaymentProvider();
    $manualResult=$manual->recordPayment(['order_id'=>42,'amount_minor'=>400,'operator_reference'=>'GUICHET-7','comment'=>'Acompte','proof_asset_id'=>12]);
    $h->assertSame('GUICHET-7',$manualResult['payload']['operator_reference']??null,'manual provider keeps operator reference');
    $h->assertSame(12,$manualResult['payload']['proof_asset_id']??null,'manual provider keeps optional proof reference');

    $test=$testRegistry->get('test'); $h->assertTrue($test instanceof DeterministicTestPaymentProvider,'test registry exposes deterministic implementation');
    $db->run("INSERT INTO sale_carts(site_id,channel_id,cart_kind,status,currency,grand_total_minor,checkout_step,terms_accepted) SELECT 1,id,'web','active','CHF',1000,'validated',1 FROM sale_channels WHERE code='web-main'");
    $cartId=(int)$db->lastInsertId();
    $channelId=(int)($db->one("SELECT id FROM sale_channels WHERE code='web-main'")['id']??0);
    $db->run("INSERT INTO sale_orders(site_id,channel_id,order_number,source,status,payment_status,currency,source_cart_id,grand_total_minor) VALUES(1,?,'REF-PROVIDER-1','ecommerce','pending_payment','pending','CHF',?,1000)",[$channelId,$cartId]);
    $orderId=(int)$db->lastInsertId();
    $db->run("UPDATE sale_carts SET status='converted',converted_order_id=? WHERE id=?",[$orderId,$cartId]);
    $payments=new SalePaymentRepository($connection); $intent=$payments->createIntent(1,$channelId,$orderId,'manual_card',1000,'CHF','manual-session');
    $testSession=$test->createIntent(['intent_id'=>(int)$intent['id'],'order_id'=>$orderId,'amount_minor'=>1000,'currency'=>'CHF','idempotency_key'=>'deterministic-session','scenario'=>'authorize_then_capture']);
    $h->assertSame('authorized',$testSession['status']??null,'deterministic authorization scenario is reproducible');
    $h->assertTrue(str_starts_with((string)($testSession['provider_reference']??''),'tst_pi_'.(int)$intent['id'].'_'),'deterministic reference derives from stable input');
    $duplicateOne=$test->simulate((string)$testSession['provider_reference'],(string)$testSession['test_token'],'duplicate_webhook');
    $duplicateTwo=$test->simulate((string)$testSession['provider_reference'],(string)$testSession['test_token'],'duplicate_webhook');
    $h->assertSame($duplicateOne['event']['event_id']??null,$duplicateTwo['event']['event_id']??null,'duplicate webhook scenario reuses the same event id');
    $db->run("INSERT INTO sale_payment_attempts(payment_intent_id,attempt_number,status) VALUES(?,1,'pending')",[(int)$intent['id']]);
    $events=new SaleEventService(new SaleEventRepository($connection));
    $service=new SalePaymentService($payments,new SaleOrderRepository($connection),$events,new SaleIdempotencyService(new SaleIdempotencyRepository($connection)),$testRegistry,new SaleStateMachineService($serviceDb,$events));
    $partial=$service->confirmIntent((int)$intent['id'],400,7,['idempotency_key'=>'confirm-partial','operator_reference'=>'MAN-400','comment'=>'Acompte','proof_asset_id'=>12]);
    $h->assertSame(600,$partial['remaining_minor'],'partial manual confirmation exposes remaining balance');
    $h->assertSame('partially_captured',$partial['intent']['status']??null,'partial confirmation keeps intent open');
    $replay=$service->confirmIntent((int)$intent['id'],400,7,['idempotency_key'=>'confirm-partial','operator_reference'=>'MAN-400','comment'=>'Acompte','proof_asset_id'=>12]);
    $h->assertSame(1,(int)($db->one('SELECT COUNT(*) AS c FROM sale_payment_transactions WHERE payment_intent_id=? AND amount_minor=400',[(int)$intent['id']])['c']??0),'manual confirmation replay does not duplicate a transaction');
    $complete=$service->confirmIntent((int)$intent['id'],600,7,['idempotency_key'=>'confirm-final','operator_reference'=>'MAN-600']);
    $h->assertSame(0,$complete['remaining_minor'],'second confirmation settles balance');
    $h->assertSame('paid',$complete['order']['payment_status']??null,'full confirmation marks order paid');
    $h->assertSame(1,(int)($db->one("SELECT COUNT(*) AS c FROM sale_events WHERE event_type='sale.payment.confirmed'")['c']??0)>0?1:0,'operator confirmations are audited');
    $payload=json_decode((string)($db->one("SELECT provider_payload_json FROM sale_payment_transactions WHERE amount_minor=400")['provider_payload_json']??'{}'),true);
    $h->assertSame(12,$payload['proof_asset_id']??null,'payment transaction traces optional proof');
} finally { $db=null; test_remove_tree($dir); }
$h->finish('UNIT deterministic, manual and bank transfer providers');
