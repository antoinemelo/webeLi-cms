<?php

declare(strict_types=1);

namespace App\Modules\Sale\Payments;

use App\Modules\Sale\Exceptions\SalePaymentException;
use Throwable;

final class StripeCheckoutPaymentProvider implements OnlinePaymentProvider
{
    private ?array $verifiedEvent = null;

    /** @param list<string> $webhookSecrets */
    public function __construct(
        private readonly StripeGateway $gateway,
        private readonly array $webhookSecrets,
        private readonly string $publicBaseUrl,
        private readonly string $environment,
        private readonly int $signatureTolerance = 300,
        private readonly string $expectedApiVersion = '',
        private readonly string $twintMode = 'dynamic',
    ) {}

    public function key(): string { return 'stripe_checkout'; }
    public function contractVersion(): string { return PaymentProviderContractV1::VERSION; }
    public function supports(string $operation): bool { return in_array($operation, ['create_intent','capture','partial_capture','refund','partial_refund','void','read_state','webhook'], true); }

    public function createIntent(array $payload): array
    {
        $orderId = (int) ($payload['order_id'] ?? 0);
        $intentId = (int) ($payload['intent_id'] ?? 0);
        $amount = (int) ($payload['amount_minor'] ?? 0);
        $currency = strtolower((string) ($payload['currency'] ?? ''));
        if ($orderId < 1 || $intentId < 1 || $amount < 1 || strlen($currency) !== 3) throw new SalePaymentException('sale.payment_provider_payload_invalid');
        $base = rtrim($this->publicBaseUrl, '/');
        if ($base === '') throw new SalePaymentException('sale.payment_provider_public_url_missing');
        $success = $base . '/checkout/confirmation?provider=stripe_checkout&reference={CHECKOUT_SESSION_ID}';
        $cancel = (string) ($payload['cancel_url'] ?? '') ?: $base . '/checkout?payment=cancelled';
        $params = [
            'mode' => 'payment',
            'success_url' => $success,
            'cancel_url' => $cancel,
            'client_reference_id' => (string) $orderId,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => ['currency' => $currency, 'unit_amount' => $amount, 'product_data' => ['name' => 'Commande ' . $orderId]],
            ]],
            'metadata' => ['cms_order_id' => (string) $orderId, 'cms_payment_intent_id' => (string) $intentId],
            'payment_intent_data' => ['metadata' => ['cms_order_id' => (string) $orderId, 'cms_payment_intent_id' => (string) $intentId]],
        ];
        if ($this->twintMode === 'explicit') {
            $params['payment_method_types'] = $currency === 'chf' ? ['card','twint'] : ['card'];
        } elseif ($this->twintMode === 'off') {
            $params['payment_method_types'] = ['card'];
        }
        $session = $this->gateway->createCheckoutSession($params, (string) ($payload['idempotency_key'] ?? 'cms-payment-' . $intentId));
        return ['provider_key'=>$this->key(),'status'=>$this->status($session),'provider_reference'=>$session['id']??null,'checkout_url'=>$session['url']??null,'payload'=>['provider'=>$this->key(),'environment'=>$this->environment,'request_id'=>$session['last_response']['request_id']??null]];
    }

    public function recordPayment(array $payload): array { throw new SalePaymentException('sale.payment_provider_operation_unsupported'); }

    public function capture(array $payload): array
    {
        $session=$this->session($payload); $paymentIntent=(string)($session['payment_intent']??''); if($paymentIntent==='') throw new SalePaymentException('sale.payment_provider_state_not_found');
        $result=$this->gateway->capturePaymentIntent($paymentIntent,['amount_to_capture'=>(int)($payload['amount_minor']??0)],(string)($payload['idempotency_key']??'capture-'.$paymentIntent));
        return $this->operationResult('capture',$session,$result);
    }

    public function refund(array $payload): array
    {
        $session=$this->session($payload); $paymentIntent=(string)($session['payment_intent']??''); if($paymentIntent==='') throw new SalePaymentException('sale.payment_provider_state_not_found');
        $result=$this->gateway->createRefund(['payment_intent'=>$paymentIntent,'amount'=>(int)($payload['amount_minor']??0)],(string)($payload['idempotency_key']??'refund-'.$paymentIntent));
        return $this->operationResult('refund',$session,$result);
    }

    public function void(array $payload): array
    {
        $session=$this->gateway->expireCheckoutSession((string)($payload['provider_reference']??''));
        return ['provider_key'=>$this->key(),'status'=>'cancelled','provider_reference'=>$session['id']??null,'provider_transaction_id'=>$session['id']??null,'payload'=>['provider'=>$this->key(),'operation'=>'cancel']];
    }

    public function readState(array $payload): array
    {
        $session=$this->session($payload); $amount=(int)($session['amount_total']??0); $status=$this->status($session);
        return ['provider_key'=>$this->key(),'contract_version'=>$this->contractVersion(),'provider_reference'=>(string)($session['id']??''),'status'=>$status,'amount_minor'=>$amount,'authorized_minor'=>in_array($status,['authorized','captured'],true)?$amount:0,'captured_minor'=>$status==='captured'?$amount:0,'refunded_minor'=>0,'currency'=>strtoupper((string)($session['currency']??'')),'provider_synced_at'=>gmdate('Y-m-d H:i:s')];
    }

    public function verifyWebhookSignature(string $rawBody, array $headers): void
    {
        $signature=(string)($headers['stripe-signature']??''); if($signature==='') throw new SalePaymentException('sale.payment_webhook_signature_invalid');
        foreach($this->webhookSecrets as $secret){try{$this->verifiedEvent=$this->gateway->verifyWebhook($rawBody,$signature,$secret,$this->signatureTolerance);return;}catch(Throwable){}}
        throw new SalePaymentException('sale.payment_webhook_signature_invalid');
    }

    public function parseWebhook(string $rawBody, array $headers): array
    {
        $event=$this->verifiedEvent; $this->verifiedEvent=null; if(!is_array($event)) throw new SalePaymentException('sale.payment_webhook_signature_invalid');
        $apiVersion=(string)($event['api_version']??''); if($this->expectedApiVersion!==''&&$apiVersion!==''&&$apiVersion!==$this->expectedApiVersion) throw new SalePaymentException('sale.payment_webhook_version_unexpected');
        $type=(string)($event['type']??''); $object=$event['data']['object']??null; if(!is_array($object)) throw new SalePaymentException('sale.payment_webhook_payload_invalid');
        $mapped=match($type){'checkout.session.completed'=>(($object['payment_status']??'')==='paid'?'payment.captured':'payment.authorized'),'checkout.session.async_payment_succeeded'=>'payment.captured','checkout.session.async_payment_failed'=>'payment.failed','checkout.session.expired'=>'payment.expired',default=>throw new SalePaymentException('sale.payment_webhook_event_unsupported')};
        $reference=(string)($object['id']??''); if(!str_starts_with($reference,'cs_')) throw new SalePaymentException('sale.payment_webhook_payload_invalid');
        $amount=(int)($object['amount_total']??0); $status=match($mapped){'payment.captured'=>'captured','payment.authorized'=>'authorized','payment.failed'=>'failed',default=>'expired'}; return ['event_id'=>(string)($event['id']??hash('sha256',$rawBody)),'type'=>$mapped,'provider_reference'=>$reference,'occurred_at'=>gmdate('Y-m-d\TH:i:s\Z',(int)($event['created']??time())),'amount_minor'=>in_array($mapped,['payment.captured','payment.authorized'],true)?$amount:0,'currency'=>strtoupper((string)($object['currency']??'')),'provider_transaction_id'=>(string)($object['payment_intent']??$reference),'data'=>['status'=>$status,'captured_minor'=>$mapped==='payment.captured'?$amount:0,'stripe_event_type'=>$type,'livemode'=>($event['livemode']??false)===true]];
    }

    private function session(array $payload): array { $id=(string)($payload['provider_reference']??''); if($id==='') throw new SalePaymentException('sale.payment_provider_state_not_found'); return $this->gateway->retrieveCheckoutSession($id); }
    private function status(array $session): string { return ($session['payment_status']??'')==='paid'?'captured':match((string)($session['status']??'')){'expired'=>'expired','complete'=>'requires_action',default=>'requires_action'}; }
    private function operationResult(string $operation,array $session,array $result): array { return ['provider_key'=>$this->key(),'status'=>'succeeded','provider_reference'=>$session['id']??null,'provider_transaction_id'=>$result['id']??null,'payload'=>['provider'=>$this->key(),'operation'=>$operation,'status'=>$result['status']??null]]; }
}
