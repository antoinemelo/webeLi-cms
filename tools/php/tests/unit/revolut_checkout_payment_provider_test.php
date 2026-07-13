<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Sale\Exceptions\SalePaymentException;
use App\Modules\Sale\Payments\PaymentProviderRegistry;
use App\Modules\Sale\Payments\RevolutCheckoutPaymentProvider;
use App\Modules\Sale\Payments\RevolutGateway;

final class FakeRevolutGateway implements RevolutGateway
{
    public array $created=[];
    public array $updated=[];
    public array $order=[
        'id'=>'6516e61c-d279-a454-a837-bc52ce55ed49','state'=>'completed','amount'=>1290,'currency'=>'CHF',
        'updated_at'=>'2026-07-13T10:00:00Z','payments'=>[['id'=>'8f7d3c2b-1e4a-4b9c-8d6e-2f5a7c9e1b3d']],
    ];
    public function createOrder(array $params,string $idempotencyKey):array
    {
        $this->created=[$params,$idempotencyKey];
        return array_replace($this->order,['state'=>'pending','checkout_url'=>'https://checkout.revolut.test/payment-link/fixture']);
    }
    public function updateOrder(string $id,array $params):array{$this->updated=[$id,$params];return $this->order+$params;}
    public function retrieveOrder(string $id):array{return $this->order;}
    public function cancelOrder(string $id):array{return array_replace($this->order,['state'=>'cancelled']);}
}

$h=new TestHarness();
$gateway=new FakeRevolutGateway();
$secret='wsk_current_fixture';
$provider=new RevolutCheckoutPaymentProvider($gateway,[$secret,'wsk_previous_fixture'],'https://shop.example.test','sandbox',300);
$session=$provider->createIntent(['intent_id'=>7,'order_id'=>42,'amount_minor'=>1290,'currency'=>'CHF','idempotency_key'=>'checkout-once']);
$h->assertSame('6516e61c-d279-a454-a837-bc52ce55ed49',$session['provider_reference']??null,'Revolut order id is the stable provider reference');
$h->assertSame('https://checkout.revolut.test/payment-link/fixture',$session['checkout_url']??null,'hosted Revolut checkout URL is returned');
$h->assertSame(1290,$gateway->created[0]['amount']??null,'immutable amount is sent in the minor currency unit');
$h->assertSame('cms-payment-intent-7',$gateway->created[0]['merchant_order_data']['reference']??null,'CMS intent correlation is sent to Revolut');
$h->assertSame('checkout-once',$gateway->created[1]??null,'create uses the CMS idempotency key');
$h->assertTrue(str_contains((string)($gateway->updated[1]['redirect_url']??''),'reference=6516e61c-d279-a454-a837-bc52ce55ed49'),'return URL contains the Revolut order id');
$h->assertSame(false,$provider->supports('capture'),'automatic Revolut Checkout does not advertise manual capture');
$h->assertSame(false,$provider->supports('refund'),'asynchronous Revolut refunds stay disabled until their lifecycle is modelled explicitly');

$event=['event'=>'ORDER_COMPLETED','order_id'=>'6516e61c-d279-a454-a837-bc52ce55ed49'];
$raw=json_encode($event,JSON_UNESCAPED_SLASHES)?:'{}';
$timestamp=(string)((int)floor(microtime(true)*1000));
$signature='v1='.hash_hmac('sha256','v1.'.$timestamp.'.'.$raw,$secret);
$headers=['revolut-signature'=>'v1='.str_repeat('0',64).','.$signature,'revolut-request-timestamp'=>$timestamp];
$provider->verifyWebhookSignature($raw,$headers);
$parsed=$provider->parseWebhook($raw,[]);
$h->assertSame('payment.captured',$parsed['type']??null,'signed ORDER_COMPLETED maps to internal capture');
$h->assertSame(1290,$parsed['amount_minor']??null,'webhook reloads the authoritative Revolut order amount');
$h->assertSame('8f7d3c2b-1e4a-4b9c-8d6e-2f5a7c9e1b3d',$parsed['provider_transaction_id']??null,'Revolut payment id is retained');
$authorisedRaw=json_encode(['event'=>'ORDER_AUTHORISED','order_id'=>'6516e61c-d279-a454-a837-bc52ce55ed49'],JSON_UNESCAPED_SLASHES)?:'{}';
$authorisedSignature='v1='.hash_hmac('sha256','v1.'.$timestamp.'.'.$authorisedRaw,$secret);
$provider->verifyWebhookSignature($authorisedRaw,['revolut-signature'=>$authorisedSignature,'revolut-request-timestamp'=>$timestamp]);
$h->assertSame('payment.captured',$provider->parseWebhook($authorisedRaw,[])['type']??null,'a delayed authorisation cannot downgrade an already completed Revolut order');
$h->expectException(fn()=> $provider->parseWebhook($raw,[]),SalePaymentException::class,'parsing cannot be replayed without signature verification');
$h->expectException(fn()=> $provider->verifyWebhookSignature($raw,['revolut-signature'=>'v1='.str_repeat('0',64),'revolut-request-timestamp'=>$timestamp]),SalePaymentException::class,'invalid Revolut signature is rejected');
$old=(string)(((int)floor(microtime(true)*1000))-600000);
$oldSignature='v1='.hash_hmac('sha256','v1.'.$old.'.'.$raw,$secret);
$h->expectException(fn()=> $provider->verifyWebhookSignature($raw,['revolut-signature'=>$oldSignature,'revolut-request-timestamp'=>$old]),SalePaymentException::class,'stale Revolut webhook is rejected');

$registry=new PaymentProviderRegistry(null,null,null,'production',[
    'real_providers'=>['stripe_checkout','revolut_checkout'],'public_base_url'=>'https://shop.example.test',
    'stripe'=>['enabled'=>true,'environment'=>'test','secret_key'=>'sk_private_fixture','webhook_secrets'=>['whsec_private_fixture']],
    'revolut'=>['enabled'=>true,'environment'=>'sandbox','secret_key'=>'sk_revolut_private_fixture','webhook_secrets'=>['wsk_private_fixture']],
]);
$status=$registry->realProviderStatus();
$h->assertTrue(in_array('revolut_checkout',$registry->keys(),true),'complete configuration activates Revolut alongside Stripe');
$revolutStatus=array_values(array_filter($status['providers']??[],static fn(array $item):bool=>($item['provider']??'')==='revolut_checkout'))[0]??[];
$h->assertSame(true,$revolutStatus['connected']??false,'Revolut diagnostics report the provider connection');
$h->assertTrue(!str_contains(json_encode($status)?:'','private_fixture'),'diagnostics never expose Revolut or Stripe secrets');

exit($h->finish('UNIT Revolut Checkout provider security'));
