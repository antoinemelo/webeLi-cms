<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Sale\Exceptions\SalePaymentException;
use App\Modules\Sale\Payments\OfficialStripeGateway;
use App\Modules\Sale\Payments\PaymentProviderRegistry;
use App\Modules\Sale\Payments\StripeCheckoutPaymentProvider;
use App\Modules\Sale\Payments\StripeGateway;

final class FakeStripeGateway implements StripeGateway
{
    public array $created=[];
    public function createCheckoutSession(array $params,string $idempotencyKey):array{$this->created=[$params,$idempotencyKey];return ['id'=>'cs_test_fixture','url'=>'https://checkout.stripe.test/c/pay','status'=>'open','payment_status'=>'unpaid'];}
    public function retrieveCheckoutSession(string $id):array{return ['id'=>$id,'status'=>'complete','payment_status'=>'paid','amount_total'=>1290,'currency'=>'chf','payment_intent'=>'pi_fixture'];}
    public function expireCheckoutSession(string $id):array{return ['id'=>$id,'status'=>'expired'];}
    public function capturePaymentIntent(string $id,array $params,string $idempotencyKey):array{return ['id'=>'pi_fixture','status'=>'succeeded'];}
    public function createRefund(array $params,string $idempotencyKey):array{return ['id'=>'re_fixture','status'=>'succeeded'];}
    public function verifyWebhook(string $rawBody,string $signature,string $secret,int $tolerance):array{return json_decode($rawBody,true,512,JSON_THROW_ON_ERROR);}
}

$h=new TestHarness();
$fake=new FakeStripeGateway();
$provider=new StripeCheckoutPaymentProvider($fake,['whsec_fixture'],'https://shop.example.test','test',300,'2025-01-01');
$session=$provider->createIntent(['intent_id'=>7,'order_id'=>42,'amount_minor'=>1290,'currency'=>'CHF','idempotency_key'=>'checkout-once']);
$h->assertSame('cs_test_fixture',$session['provider_reference']??null,'Stripe Checkout session reference is returned');
$h->assertSame('checkout-once',$fake->created[1]??null,'Stripe create uses CMS idempotency key');
$h->assertSame(1290,$fake->created[0]['line_items'][0]['price_data']['unit_amount']??null,'Stripe hosted checkout receives the immutable amount');
$h->assertTrue(str_contains((string)($fake->created[0]['success_url']??''),'{CHECKOUT_SESSION_ID}'),'recoverable Shop confirmation URL uses Stripe placeholder');
$explicit=new StripeCheckoutPaymentProvider($fake,['whsec_fixture'],'https://shop.example.test','test',300,'2025-01-01','explicit');
$explicit->createIntent(['intent_id'=>8,'order_id'=>43,'amount_minor'=>2000,'currency'=>'CHF','idempotency_key'=>'checkout-twint']);
$h->assertSame(['card','twint'],$fake->created[0]['payment_method_types']??null,'explicit CHF Checkout offers card and TWINT');

$event=['id'=>'evt_fixture_1','type'=>'checkout.session.completed','api_version'=>'2025-01-01','created'=>time(),'livemode'=>false,'data'=>['object'=>['id'=>'cs_test_fixture','amount_total'=>1290,'currency'=>'chf','payment_status'=>'paid','payment_intent'=>'pi_fixture']]];
$raw=json_encode($event,JSON_UNESCAPED_SLASHES)?:'{}';
$provider->verifyWebhookSignature($raw,['stripe-signature'=>'fixture']);
$parsed=$provider->parseWebhook($raw,[]);
$h->assertSame('payment.captured',$parsed['type']??null,'validated Stripe completion maps to internal capture');
$h->assertSame('evt_fixture_1',$parsed['event_id']??null,'Stripe delivery id is stable');

$officialSecret='whsec_official_fixture'; $timestamp=time(); $header='t='.$timestamp.',v1='.hash_hmac('sha256',$timestamp.'.'.$raw,$officialSecret);
$verified=(new OfficialStripeGateway('sk_test_not_used'))->verifyWebhook($raw,$header,$officialSecret,300);
$h->assertSame('evt_fixture_1',$verified['id']??null,'official Stripe SDK verifies the raw signed fixture');
$h->expectException(fn()=>(new OfficialStripeGateway('sk_test_not_used'))->verifyWebhook($raw,'t='.$timestamp.',v1='.str_repeat('0',64),$officialSecret,300),\Throwable::class,'invalid official Stripe signature is rejected');
$old=$timestamp-600; $oldHeader='t='.$old.',v1='.hash_hmac('sha256',$old.'.'.$raw,$officialSecret);
$h->expectException(fn()=>(new OfficialStripeGateway('sk_test_not_used'))->verifyWebhook($raw,$oldHeader,$officialSecret,300),\Throwable::class,'official Stripe tolerance rejects replayed stale signatures');
$h->expectException(fn()=> $provider->parseWebhook($raw,[]),SalePaymentException::class,'business parsing cannot run without prior signature validation');

$unexpected=$event; $unexpected['api_version']='unexpected-version'; $unexpectedRaw=json_encode($unexpected,JSON_UNESCAPED_SLASHES)?:'{}';
$provider->verifyWebhookSignature($unexpectedRaw,['stripe-signature'=>'fixture']);
$h->expectException(fn()=> $provider->parseWebhook($unexpectedRaw,[]),SalePaymentException::class,'unexpected configured Stripe API version is rejected');

$registry=new PaymentProviderRegistry(null,null,null,'production',['real_provider'=>'stripe_checkout','public_base_url'=>'https://shop.example.test','stripe'=>['enabled'=>true,'environment'=>'test','secret_key'=>'sk_private_fixture','webhook_secrets'=>['whsec_private_fixture']]]);
$status=$registry->realProviderStatus();
$h->assertSame(true,$status['connected']??false,'complete environment configuration activates Stripe');
$h->assertTrue(!str_contains(json_encode($status)?:'','private_fixture'),'provider diagnostics never expose configured secrets');

exit($h->finish('UNIT Stripe Checkout provider security'));
