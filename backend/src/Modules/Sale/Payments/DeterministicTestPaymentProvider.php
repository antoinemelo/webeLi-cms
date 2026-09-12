<?php

declare(strict_types=1);

namespace App\Modules\Sale\Payments;

use App\Core\Database;
use App\Modules\Sale\Exceptions\SalePaymentException;

final class DeterministicTestPaymentProvider implements OnlinePaymentProvider
{
    private const CONTRACT = 'sale.payment_provider.v1';
    private const SCENARIOS = ['success_immediate','authorize_then_capture','refused','temporary_error','timeout','cancelled','duplicate_webhook','out_of_order_webhook','reconciliation_divergence'];

    public function __construct(private readonly Database $db, private readonly string $secret) {}
    public function key(): string { return 'test'; }
    public function contractVersion(): string { return self::CONTRACT; }
    public function supports(string $operation): bool { return in_array($operation, ['create_intent','capture','partial_capture','multiple_capture','refund','partial_refund','void','read_state','webhook'], true); }

    public function createIntent(array $payload): array
    {
        $scenario = strtolower(trim((string) ($payload['scenario'] ?? 'success_immediate')));
        if (!in_array($scenario, self::SCENARIOS, true)) throw new SalePaymentException('sale.payment_test_scenario_invalid');
        $intentId = (int) ($payload['intent_id'] ?? 0); $amount = (int) ($payload['amount_minor'] ?? 0); $currency = strtoupper((string) ($payload['currency'] ?? ''));
        $reference = 'tst_pi_' . $intentId . '_' . substr(hash_hmac('sha256', (string) ($payload['idempotency_key'] ?? $intentId), $this->secret), 0, 12);
        $token = hash_hmac('sha256', 'action|' . $reference, $this->secret);
        $status = match ($scenario) { 'success_immediate' => 'captured', 'authorize_then_capture' => 'authorized', 'refused', 'temporary_error' => 'failed', 'timeout' => 'expired', 'cancelled' => 'cancelled', default => 'requires_action' };
        $authorized = in_array($status, ['authorized','captured'], true) ? $amount : 0; $captured = $status === 'captured' ? $amount : 0;
        $this->db->run('INSERT INTO sale_test_payment_states(provider_reference,payment_intent_id,scenario,status,amount_minor,authorized_minor,captured_minor,currency,action_token_hash) VALUES(?,?,?,?,?,?,?,?,?)', [$reference,$intentId,$scenario,$status,$amount,$authorized,$captured,$currency,hash('sha256',$token)]);
        return ['provider_key'=>$this->key(),'contract_version'=>self::CONTRACT,'status'=>$status,'provider_reference'=>$reference,'test_token'=>$token,'test_mode'=>true,'scenario'=>$scenario,'event_type'=>$status === 'captured' ? 'payment.captured' : ($status === 'authorized' ? 'payment.authorized' : (in_array($status,['failed','expired','cancelled'],true) ? 'payment.'.$status : null)),'payload'=>['provider'=>$this->key(),'test_mode'=>true,'scenario'=>$scenario]];
    }

    public function recordPayment(array $payload): array { throw new SalePaymentException('sale.payment_provider_operation_unsupported'); }
    public function capture(array $payload): array { return $this->operation($payload, 'captured', 'capture'); }
    public function refund(array $payload): array { return $this->operation($payload, 'succeeded', 'refund'); }
    public function void(array $payload): array { return $this->operation($payload, 'cancelled', 'void'); }
    public function readState(array $payload): array { $s=$this->state($payload); return ['provider_key'=>$this->key(),'contract_version'=>self::CONTRACT,'provider_reference'=>$s['provider_reference'],'status'=>$s['status'],'amount_minor'=>(int)$s['amount_minor'],'authorized_minor'=>(int)$s['authorized_minor'],'captured_minor'=>(int)$s['captured_minor'],'refunded_minor'=>(int)$s['refunded_minor'],'currency'=>$s['currency'],'provider_synced_at'=>$s['updated_at']]; }

    public function verifyWebhookSignature(string $rawBody, array $headers): void
    {
        $signature=(string)($headers['x-sale-signature']??''); if (!hash_equals(hash_hmac('sha256',$rawBody,$this->secret),$signature)) throw new SalePaymentException('sale.payment_webhook_signature_invalid');
    }
    public function parseWebhook(string $rawBody, array $headers): array
    {
        $event=json_decode($rawBody,true); if(!is_array($event)) throw new SalePaymentException('sale.payment_webhook_payload_invalid'); return $event;
    }

    /** @return array{body:string,signature:string,event:array<string,mixed>,deliver_webhook:bool} */
    public function simulate(string $reference,string $token,string $outcome): array
    {
        $s=$this->state(['provider_reference'=>$reference]); if(!hash_equals((string)$s['action_token_hash'],hash('sha256',$token))) throw new SalePaymentException('sale.payment_test_token_invalid');
        $outcome=strtolower(trim($outcome)); $status=match($outcome){'success','success_immediate','capture','authorize_then_capture','duplicate_webhook','out_of_order_webhook','reconciliation_divergence'=>'captured','authorize'=>'authorized','refused','temporary_error'=>'failed','timeout'=>'expired','cancel','cancelled'=>'cancelled',default=>throw new SalePaymentException('sale.payment_test_scenario_invalid')};
        $amount=(int)$s['amount_minor']; $this->db->run('UPDATE sale_test_payment_states SET status=?,authorized_minor=?,captured_minor=?,updated_at=CURRENT_TIMESTAMP WHERE provider_reference=?',[$status,in_array($status,['authorized','captured'],true)?$amount:0,$status==='captured'?$amount:0,$reference]);
        $type=match($status){'captured'=>'payment.captured','authorized'=>'payment.authorized','failed'=>'payment.failed','expired'=>'payment.expired',default=>'payment.cancelled'};
        $event=['event_id'=>'tst_evt_'.substr(hash('sha256',$reference.'|'.$outcome),0,16),'type'=>$type,'provider_reference'=>$reference,'occurred_at'=>$outcome==='out_of_order_webhook'?'2000-01-01T00:00:00Z':gmdate('Y-m-d\TH:i:s\Z'),'amount_minor'=>in_array($status,['authorized','captured'],true)?$amount:0,'currency'=>$s['currency'],'provider_transaction_id'=>'tst_tx_'.substr(hash('sha256',$reference.'|'.$outcome),0,16),'data'=>['status'=>$status,'captured_minor'=>$status==='captured'?$amount:0,'test_mode'=>true]];
        $body=json_encode($event,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'{}'; return ['body'=>$body,'signature'=>hash_hmac('sha256',$body,$this->secret),'event'=>$event,'deliver_webhook'=>$outcome!=='reconciliation_divergence'];
    }

    private function operation(array $payload,string $status,string $operation): array { $s=$this->state($payload); $amount=(int)($payload['amount_minor']??0);$key=trim((string)($payload['idempotency_key']??''));if($key!==''){$row=$this->db->one('SELECT * FROM sale_test_payment_operations WHERE provider_key=? AND provider_reference=? AND operation_kind=? AND operation_key=?',[$this->key(),$s['provider_reference'],$operation,$key]);if($row!==null){if((int)$row['amount_minor']!==$amount)throw new SalePaymentException('sale.payment_operation_idempotency_conflict');$saved=json_decode((string)$row['result_json'],true);if(is_array($saved))return $saved;}} if($operation==='capture'){$captured=min((int)$s['amount_minor'],(int)$s['captured_minor']+$amount);$this->db->run('UPDATE sale_test_payment_states SET status=?,authorized_minor=MAX(authorized_minor,?),captured_minor=?,updated_at=CURRENT_TIMESTAMP WHERE provider_reference=?',[$captured>=(int)$s['amount_minor']?'captured':'authorized',$captured,$captured,$s['provider_reference']]);}elseif($operation==='refund'){$this->db->run('UPDATE sale_test_payment_states SET refunded_minor=MIN(captured_minor,refunded_minor+?),updated_at=CURRENT_TIMESTAMP WHERE provider_reference=?',[$amount,$s['provider_reference']]);}elseif($operation==='void'){$this->db->run("UPDATE sale_test_payment_states SET status='cancelled',updated_at=CURRENT_TIMESTAMP WHERE provider_reference=?",[$s['provider_reference']]);} $result=['provider_key'=>$this->key(),'status'=>$status,'provider_reference'=>$s['provider_reference'],'provider_transaction_id'=>'tst_'.substr(hash('sha256',$s['provider_reference'].'|'.$operation.'|'.$key.'|'.$amount),0,20),'payload'=>['provider'=>$this->key(),'operation'=>$operation,'test_mode'=>true]];if($key!=='')$this->db->run('INSERT INTO sale_test_payment_operations(provider_key,provider_reference,operation_kind,operation_key,amount_minor,result_json) VALUES(?,?,?,?,?,?)',[$this->key(),$s['provider_reference'],$operation,$key,$amount,json_encode($result,JSON_UNESCAPED_SLASHES)?:'{}']);return $result; }
    private function state(array $payload): array { $s=isset($payload['provider_reference'])?$this->db->one('SELECT * FROM sale_test_payment_states WHERE provider_reference=?',[(string)$payload['provider_reference']]):$this->db->one('SELECT * FROM sale_test_payment_states WHERE payment_intent_id=?',[(int)($payload['intent_id']??0)]); if($s===null) throw new SalePaymentException('sale.payment_provider_state_not_found'); return $s; }
}
