<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Core\Database;
use App\Mail\MailerInterface;
use App\Modules\Sale\Exceptions\SalePaymentException;
use App\Modules\Sale\Exceptions\SaleValidationException;
use App\Modules\Sale\Repositories\SaleOrderRepository;
use App\Modules\Sale\Repositories\SalePaymentRepository;

/**
 * Instrument de valeur Bon cadeau.
 *
 * Le code complet ne quitte cette classe que lors d'une revelation securisee.
 * La base, les evenements et le CRM ne recoivent que son verificateur ou son
 * suffixe masque. Le journal est la source reconstructible du solde.
 */
final class SaleGiftCardService
{
    private string $secret;

    public function __construct(
        private readonly SaleDatabaseConnection $connection,
        private readonly SalePaymentRepository $payments,
        private readonly SaleOrderRepository $orders,
        private readonly SaleEventService $events,
        ?string $secret = null,
        private readonly ?MailerInterface $mailer = null,
    ) {
        $configured = trim((string) $secret);
        $this->secret = $configured !== '' ? hash('sha256', $configured, true) : hash('sha256', 'dec-cms-gift-card-local-key', true);
    }

    /** @return array<string,mixed> */
    public function policy(int $siteId, string $currency): array
    {
        $currency = strtoupper(trim($currency));
        $row = $this->db()->one('SELECT * FROM sale_gift_card_policies WHERE site_id=? AND currency=?', [$siteId, $currency]);
        if ($row === null || (string) $row['status'] !== 'active') {
            throw new SaleValidationException('sale.gift_card_unavailable');
        }
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function issuePaidOrder(int $orderId, ?string $correlationId = null): array
    {
        $order = $this->orders->requireOrder($orderId);
        if ((int) $order['paid_total_minor'] < (int) $order['grand_total_minor'] || (string) $order['payment_status'] !== 'paid') {
            throw new SaleValidationException('sale.gift_card_payment_required');
        }
        $policy = $this->policy((int) $order['site_id'], (string) $order['currency']);
        $lines = $this->db()->all("SELECT * FROM sale_order_lines WHERE order_id=? AND product_type='gift_card' ORDER BY id", [$orderId]);
        if ($lines === []) return [];

        return $this->db()->transaction(function () use ($order, $orderId, $lines, $policy, $correlationId): array {
            $issued = [];
            $customer=json_decode((string)($order['customer_snapshot_json']??'{}'),true);$buyerEmail=is_array($customer)?($customer['email']??null):null;
            foreach ($lines as $line) {
                $personalization = json_decode((string) ($line['snapshot_json'] ?? '{}'), true);
                $personalization = is_array($personalization) ? (array) ($personalization['personalization'] ?? $personalization['gift_card'] ?? []) : [];
                for ($unit = 1; $unit <= (int) $line['quantity']; $unit++) {
                    $existing = $this->db()->one('SELECT * FROM sale_gift_cards WHERE origin_order_line_id=? AND origin_unit_number=?', [(int) $line['id'], $unit]);
                    if ($existing !== null) {
                        $issued[] = $this->safeCard($existing) + ['replayed' => true];
                        continue;
                    }
                    $code = $this->newCode();
                    $amount = (int) $line['unit_price_minor'];
                    if ($amount < 1) throw new SaleValidationException('sale.gift_card_value_invalid');
                    $expiresAt = $policy['expires_after_days'] === null ? null : gmdate('Y-m-d H:i:s', time() + ((int) $policy['expires_after_days'] * 86400));
                    $reference = 'GFT-' . strtoupper(bin2hex(random_bytes(6)));
                    $this->db()->run(
                        'INSERT INTO sale_gift_cards(site_id,currency,public_reference,code_verifier,code_last4,initial_value_minor,balance_minor,status,origin_order_id,origin_order_line_id,origin_unit_number,recipient_name,recipient_email,message,send_at,issued_at,expires_at,correlation_id)
                         VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,?,?)',
                        [(int) $order['site_id'],(string) $order['currency'],$reference,$this->verifier($code),substr($this->canonicalCode($code),-4),$amount,$amount,'active',$order['id'],$line['id'],$unit,
                         $this->nullable($personalization['recipient_name'] ?? null),$this->nullable($personalization['recipient_email'] ?? null),$this->nullable($personalization['message'] ?? null),$this->nullable($personalization['send_at'] ?? null),$expiresAt,$correlationId]
                    );
                    $cardId = $this->db()->lastInsertId();
                    $this->ledger($cardId, 'issuance', $amount, $amount, 'issue:' . $line['id'] . ':' . $unit, $orderId, (int) $line['id'], $correlationId, 'system', null, 'paid order');
                    $delivery = $this->createDelivery($cardId, $code, 'initial', $personalization['recipient_email'] ?? $buyerEmail, null, $correlationId);
                    $this->events->emit((int) $order['site_id'], 'sale.gift_card.issued', 'gift_card', $cardId, [
                        'site_id'=>(int)$order['site_id'],'gift_card_id'=>$cardId,'order_id'=>$orderId,'order_line_id'=>(int)$line['id'],
                        'amount_minor'=>$amount,'currency'=>(string)$order['currency'],'status'=>'active',
                    ], null, $correlationId);
                    $card = $this->db()->one('SELECT * FROM sale_gift_cards WHERE id=?', [$cardId]) ?? [];
                    $issued[] = $this->safeCard($card) + ['delivery' => $delivery, 'replayed' => false];
                }
            }
            return $issued;
        });
    }

    /** @return array<string,mixed> */
    public function validatePublic(int $siteId, string $currency, string $code, int $orderAmountMinor, string $requester): array
    {
        $policy = $this->policy($siteId, $currency);
        $fingerprint = hash_hmac('sha256', trim($requester), $this->secret);
        $since = gmdate('Y-m-d H:i:s', time() - (int) $policy['public_attempt_window_seconds']);
        $attempts = (int) ($this->db()->one('SELECT COUNT(*) AS total FROM sale_gift_card_public_attempts WHERE site_id=? AND requester_fingerprint=? AND attempted_at>=?', [$siteId,$fingerprint,$since])['total'] ?? 0);
        if ($attempts >= (int) $policy['public_attempt_limit']) throw new SaleValidationException('sale.gift_card_rate_limited');
        $card = $this->findUsable($siteId, $currency, $code);
        $this->db()->run('INSERT INTO sale_gift_card_public_attempts(site_id,requester_fingerprint,successful) VALUES(?,?,?)', [$siteId,$fingerprint,$card === null ? 0 : 1]);
        if ($card === null) return ['valid'=>false,'message_key'=>'gift_card.unavailable'];
        return [
            'valid'=>true,
            'masked_code'=>'•••• ' . (string)$card['code_last4'],
            'applicable_minor'=>min(max(0,$orderAmountMinor),(int)$card['balance_minor']),
            'currency'=>(string)$card['currency'],
            'status'=>(string)$card['status'],
        ];
    }

    /** @return array<string,mixed> */
    public function redeemOrder(int $orderId, string $code, string $idempotencyKey, ?string $correlationId = null): array
    {
        return $this->db()->transaction(function () use ($orderId,$code,$idempotencyKey,$correlationId): array {
            $order = $this->orders->requireOrder($orderId);
            $policy = $this->policy((int)$order['site_id'], (string)$order['currency']);
            $keyHash = hash('sha256', 'redeem:' . $idempotencyKey);
            $existing = $this->db()->one("SELECT l.*,g.code_last4,g.currency FROM sale_gift_card_ledger l JOIN sale_gift_cards g ON g.id=l.gift_card_id WHERE l.order_id=? AND l.entry_type='debit' AND l.idempotency_key_hash=?", [$orderId,$keyHash]);
            if ($existing !== null) return ['amount_minor'=>abs((int)$existing['amount_delta_minor']),'masked_code'=>'•••• '.(string)$existing['code_last4'],'replayed'=>true];
            $card = $this->findUsable((int)$order['site_id'], (string)$order['currency'], $code);
            if ($card === null) throw new SaleValidationException('sale.gift_card_unavailable');
            if ((int)$policy['maximum_cards_per_order'] !== 1) throw new SaleValidationException('sale.gift_card_multiple_unsupported');
            if ((int)$policy['promotions_stack'] !== 1 && (int)$order['discount_total_minor'] > 0) throw new SaleValidationException('sale.gift_card_promotion_stack_unsupported');

            // Les promotions sont deja incluses dans le total. Par politique,
            // un bon ne peut pas financer l'achat d'un autre bon.
            $giftProducts = (int)($this->db()->one("SELECT COALESCE(SUM(line_total_minor),0) AS total FROM sale_order_lines WHERE order_id=? AND product_type='gift_card'", [$orderId])['total'] ?? 0);
            $eligible = (int)$order['grand_total_minor'] - $giftProducts;
            if((int)$policy['applies_to_tax']!==1)$eligible-=(int)$order['tax_total_minor'];
            if((int)$policy['applies_to_shipping']!==1)$eligible-=(int)$order['shipping_total_minor'];
            $eligible=max(0,$eligible);
            $alreadyAllocated = $this->payments->allocatedTotal($orderId);
            $amount = min((int)$card['balance_minor'], max(0, $eligible - $alreadyAllocated));
            if ($amount < 1) throw new SaleValidationException('sale.gift_card_not_applicable');
            $newBalance = (int)$card['balance_minor'] - $amount;
            $newStatus = $newBalance === 0 ? 'depleted' : 'active';
            $this->db()->run('UPDATE sale_gift_cards SET balance_minor=?,status=?,version=version+1,updated_at=CURRENT_TIMESTAMP WHERE id=? AND version=? AND status=? AND balance_minor>=?', [$newBalance,$newStatus,(int)$card['id'],(int)$card['version'],'active',$amount]);
            if ((int)($this->db()->one('SELECT changes() AS total')['total'] ?? 0) !== 1) throw new SalePaymentException('sale.gift_card_concurrent_redemption');
            $ledgerId = $this->ledger((int)$card['id'],'debit',-$amount,$newBalance,'redeem:'.$idempotencyKey,$orderId,null,$correlationId,'customer',null,'checkout');
            $this->payments->recordTransaction($orderId,$amount,(string)$order['currency'],'payment',[
                'status'=>'succeeded','provider_transaction_id'=>'gift-card-ledger-'.$ledgerId,
                'provider_payload'=>['tender'=>'gift_card','gift_card_id'=>(int)$card['id'],'masked_code'=>'•••• '.(string)$card['code_last4']],
                'correlation_id'=>$correlationId,
            ]);
            $order = $this->orders->updatePaidTotal($orderId,$this->payments->allocatedTotal($orderId));
            $this->events->emit((int)$order['site_id'],'sale.gift_card.redeemed','gift_card',(int)$card['id'],[
                'site_id'=>(int)$order['site_id'],'gift_card_id'=>(int)$card['id'],'order_id'=>$orderId,
                'amount_minor'=>$amount,'currency'=>(string)$order['currency'],'status'=>$newStatus,
            ],null,$correlationId);
            return ['amount_minor'=>$amount,'remaining_balance_minor'=>$newBalance,'masked_code'=>'•••• '.(string)$card['code_last4'],'replayed'=>false];
        });
    }

    /** @return list<array<string,mixed>> */
    public function releaseOrderRedemptions(int $orderId, string $reason, ?string $correlationId = null): array
    {
        return $this->db()->transaction(function () use ($orderId,$reason,$correlationId): array {
            $rows=$this->db()->all("SELECT l.*,g.balance_minor,g.initial_value_minor,g.status,g.version,t.id AS payment_transaction_id FROM sale_gift_card_ledger l JOIN sale_gift_cards g ON g.id=l.gift_card_id LEFT JOIN sale_payment_transactions t ON t.provider_transaction_id=('gift-card-ledger-' || l.id) WHERE l.order_id=? AND l.entry_type='debit' ORDER BY l.id",[$orderId]);
            $released=[];
            foreach($rows as $row){
                $key='release:'.$orderId.':'.$row['id'];
                if($this->db()->one('SELECT id FROM sale_gift_card_ledger WHERE gift_card_id=? AND idempotency_key_hash=?',[(int)$row['gift_card_id'],hash('sha256',$key)])!==null) continue;
                $amount=abs((int)$row['amount_delta_minor']);
                $balance=min((int)$row['initial_value_minor'],(int)$row['balance_minor']+$amount);
                $this->db()->run("UPDATE sale_gift_cards SET balance_minor=?,status='active',version=version+1,updated_at=CURRENT_TIMESTAMP WHERE id=? AND version=?",[$balance,(int)$row['gift_card_id'],(int)$row['version']]);
                $this->ledger((int)$row['gift_card_id'],'credit',$amount,$balance,$key,$orderId,null,$correlationId,'system',null,$reason);
                $this->db()->run('INSERT OR IGNORE INTO sale_financial_corrections(order_id,payment_transaction_id,amount_delta_minor,currency,reason,idempotency_key,correlation_id) VALUES(?,?,?,?,?,?,?)',[$orderId,$row['payment_transaction_id']??null,-$amount,(string)($this->orders->requireOrder($orderId)['currency']),$reason,$key,$correlationId?:SaleStateMachineService::correlationId()]);
                $released[]=['gift_card_id'=>(int)$row['gift_card_id'],'amount_minor'=>$amount];
            }
            $this->orders->updatePaidTotal($orderId,$this->payments->allocatedTotal($orderId));
            return $released;
        });
    }

    /** @return array<string,mixed> */
    public function refundPaymentTransaction(int $transactionId,int $amountMinor,string $key,string $reason,?int $actorId=null): array
    {
        return $this->db()->transaction(function() use($transactionId,$amountMinor,$key,$reason,$actorId): array {
            $tx=$this->payments->requireTransactionWithOrder($transactionId);
            if(!str_starts_with((string)($tx['provider_transaction_id']??''),'gift-card-ledger-')) throw new SalePaymentException('sale.payment_transaction_not_gift_card');
            $debitId=(int)substr((string)$tx['provider_transaction_id'],strlen('gift-card-ledger-'));
            $debit=$this->db()->one("SELECT l.*,g.balance_minor,g.initial_value_minor,g.version,g.status FROM sale_gift_card_ledger l JOIN sale_gift_cards g ON g.id=l.gift_card_id WHERE l.id=? AND l.entry_type='debit'",[$debitId]);
            if($debit===null) throw new SalePaymentException('sale.gift_card_ledger_not_found');
            $existing=$this->payments->refundByIdempotency((int)$tx['order_id'],$key);
            if($existing!==null) return ['refund'=>$existing,'order'=>$this->orders->requireOrder((int)$tx['order_id']),'replayed'=>true];
            $available=(int)$tx['amount_minor']-$this->payments->refundedForTransaction($transactionId);
            if($amountMinor<1||$amountMinor>$available) throw new SalePaymentException('sale.refund_exceeds_payment');
            $balance=min((int)$debit['initial_value_minor'],(int)$debit['balance_minor']+$amountMinor);
            $this->db()->run("UPDATE sale_gift_cards SET balance_minor=?,status='active',version=version+1,updated_at=CURRENT_TIMESTAMP WHERE id=? AND version=?",[$balance,(int)$debit['gift_card_id'],(int)$debit['version']]);
            $correlation=SaleStateMachineService::correlationId();
            $this->ledger((int)$debit['gift_card_id'],'credit',$amountMinor,$balance,'refund:'.$key,(int)$tx['order_id'],null,$correlation,'operator',$actorId,$reason);
            $refund=$this->payments->createRefund((int)$tx['order_id'],$transactionId,$amountMinor,(string)$tx['currency'],$reason,$actorId,'succeeded',['reason_code'=>'customer_request','reason_note'=>$reason,'idempotency_key'=>$key,'provider_reference'=>'gift-card-credit-'.$debitId]);
            $total=(int)($this->db()->one("SELECT COALESCE(SUM(amount_minor),0) AS total FROM sale_refunds WHERE order_id=? AND status='succeeded'",[(int)$tx['order_id']])['total']??0);
            $order=$this->orders->updateRefundedTotal((int)$tx['order_id'],$total);
            $this->events->emit((int)$tx['site_id'],'sale.refund.completed','order',(int)$tx['order_id'],['site_id'=>(int)$tx['site_id'],'order_id'=>(int)$tx['order_id'],'refund_id'=>(int)$refund['id'],'amount_minor'=>$amountMinor,'currency'=>(string)$tx['currency'],'reason_code'=>'customer_request','iam_user_id'=>$actorId],$actorId,$correlation);
            return ['refund'=>$refund,'order'=>$order,'replayed'=>false];
        });
    }

    /** @return array<string,mixed> */
    public function claim(string $token, ?int $expectedSiteId = null): array
    {
        $token=trim($token); $hash=hash('sha256',$token);
        return $this->db()->transaction(function() use($token,$hash,$expectedSiteId): array {
            $delivery=$this->db()->one("SELECT * FROM sale_gift_card_deliveries WHERE claim_token_hash=? AND status='pending' AND expires_at>CURRENT_TIMESTAMP",[$hash]);
            if($delivery===null) throw new SaleValidationException('sale.gift_card_claim_unavailable');
            $payload=$this->decryptToken($token);
            $card=$this->db()->one('SELECT * FROM sale_gift_cards WHERE id=?',[(int)($payload['gift_card_id']??0)]);
            $code=(string)($payload['code']??'');
            if($card===null || ($expectedSiteId!==null && (int)$card['site_id']!==$expectedSiteId) || (int)$card['id']!==(int)$delivery['gift_card_id'] || !hash_equals((string)$card['code_verifier'],$this->verifier($code))) throw new SaleValidationException('sale.gift_card_claim_unavailable');
            $this->db()->run("UPDATE sale_gift_card_deliveries SET status='claimed',claimed_at=CURRENT_TIMESTAMP WHERE id=? AND status='pending'",[(int)$delivery['id']]);
            return ['code'=>$code,'masked_code'=>'•••• '.(string)$card['code_last4'],'balance_minor'=>(int)$card['balance_minor'],'initial_value_minor'=>(int)$card['initial_value_minor'],'currency'=>(string)$card['currency'],'status'=>(string)$card['status'],'expires_at'=>$card['expires_at']];
        });
    }

    /** @return array<string,mixed> */
    public function resend(int $siteId,int $cardId,int $actorId,?string $correlationId=null): array
    {
        return $this->db()->transaction(function() use($siteId,$cardId,$actorId,$correlationId): array {
            $card=$this->requireCard($siteId,$cardId); $policy=$this->policy($siteId,(string)$card['currency']);
            $count=(int)($this->db()->one("SELECT COUNT(*) AS total FROM sale_gift_card_deliveries WHERE gift_card_id=? AND delivery_type='resend'",[$cardId])['total']??0);
            if($count >= (int)$policy['resend_limit']) throw new SaleValidationException('sale.gift_card_resend_limit');
            if(!in_array((string)$card['status'],['active','depleted'],true)) throw new SaleValidationException('sale.gift_card_not_resendable');
            $code=$this->newCode();
            $this->db()->run("UPDATE sale_gift_cards SET code_verifier=?,code_last4=?,updated_by_iam_user_id=?,version=version+1,updated_at=CURRENT_TIMESTAMP WHERE id=?",[$this->verifier($code),substr($this->canonicalCode($code),-4),$actorId,$cardId]);
            $this->db()->run("UPDATE sale_gift_card_deliveries SET status='cancelled' WHERE gift_card_id=? AND status='pending'",[$cardId]);
            return $this->createDelivery($cardId,$code,'resend',$card['recipient_email']??null,$actorId,$correlationId);
        });
    }

    /** @return array<string,mixed> */
    public function cancel(int $siteId,int $cardId,string $reason,int $actorId,?string $correlationId=null): array
    {
        $reason=trim($reason); if($reason==='') throw new SaleValidationException('sale.gift_card_reason_required');
        return $this->db()->transaction(function() use($siteId,$cardId,$reason,$actorId,$correlationId): array {
            $card=$this->requireCard($siteId,$cardId); if(in_array((string)$card['status'],['cancelled','expired'],true)) return $this->safeCard($card)+['replayed'=>true];
            $balance=(int)$card['balance_minor'];
            $this->db()->run("UPDATE sale_gift_cards SET balance_minor=0,status='cancelled',cancelled_at=CURRENT_TIMESTAMP,updated_by_iam_user_id=?,version=version+1,updated_at=CURRENT_TIMESTAMP WHERE id=?",[$actorId,$cardId]);
            $this->ledger($cardId,'cancellation',-$balance,0,'cancel:'.$cardId,$card['origin_order_id']??null,null,$correlationId,'operator',$actorId,$reason);
            return $this->safeCard($this->requireCard($siteId,$cardId))+['replayed'=>false];
        });
    }

    /** @return array<string,mixed> */
    public function adjust(int $siteId,int $cardId,int $deltaMinor,string $reason,int $actorId,string $idempotencyKey,bool $apply=false,?string $correlationId=null): array
    {
        $reason=trim($reason);$idempotencyKey=trim($idempotencyKey);
        if($deltaMinor===0)throw new SaleValidationException('sale.gift_card_adjustment_zero');
        if($reason===''||$idempotencyKey==='')throw new SaleValidationException('sale.gift_card_adjustment_reason_required');
        return $this->db()->transaction(function() use($siteId,$cardId,$deltaMinor,$reason,$actorId,$idempotencyKey,$apply,$correlationId): array {
            $card=$this->requireCard($siteId,$cardId);if(!in_array((string)$card['status'],['active','depleted'],true))throw new SaleValidationException('sale.gift_card_not_adjustable');
            $newBalance=(int)$card['balance_minor']+$deltaMinor;
            if($newBalance<0||$newBalance>(int)$card['initial_value_minor'])throw new SaleValidationException('sale.gift_card_adjustment_out_of_bounds');
            $preview=['gift_card_id'=>$cardId,'current_balance_minor'=>(int)$card['balance_minor'],'amount_delta_minor'=>$deltaMinor,'new_balance_minor'=>$newBalance,'currency'=>(string)$card['currency'],'reason'=>$reason,'applied'=>false];
            if(!$apply)return $preview;
            $key='adjust:'.$idempotencyKey;$existing=$this->db()->one('SELECT * FROM sale_gift_card_ledger WHERE gift_card_id=? AND idempotency_key_hash=?',[$cardId,hash('sha256',$key)]);
            if($existing!==null)return array_merge($preview,['new_balance_minor'=>(int)$existing['balance_after_minor'],'applied'=>true,'replayed'=>true]);
            $status=$newBalance===0?'depleted':'active';
            $this->db()->run('UPDATE sale_gift_cards SET balance_minor=?,status=?,updated_by_iam_user_id=?,version=version+1,updated_at=CURRENT_TIMESTAMP WHERE id=? AND version=?',[$newBalance,$status,$actorId,$cardId,(int)$card['version']]);
            if((int)($this->db()->one('SELECT changes() AS total')['total']??0)!==1)throw new SalePaymentException('sale.gift_card_concurrent_adjustment');
            $this->ledger($cardId,'adjustment',$deltaMinor,$newBalance,$key,null,null,$correlationId,'operator',$actorId,$reason);
            return array_merge($preview,['applied'=>true,'replayed'=>false]);
        });
    }

    /** @return list<array<string,mixed>> */
    public function index(int $siteId,array $filters=[]): array
    {
        $where=['site_id=?'];$params=[$siteId];
        if(trim((string)($filters['status']??''))!==''){$where[]='status=?';$params[]=trim((string)$filters['status']);}
        $q=strtoupper(preg_replace('/[^A-Z0-9]/','',(string)($filters['q']??''))??'');
        if($q!==''){$where[]='(public_reference LIKE ? OR code_last4=?)';$params[]='%'.$q.'%';$params[]=substr($q,-4);}
        return array_map(fn(array $row):array=>$this->safeCard($row),$this->db()->all('SELECT * FROM sale_gift_cards WHERE '.implode(' AND ',$where).' ORDER BY id DESC LIMIT 200',$params));
    }

    /** @return array<string,mixed> */
    public function detail(int $siteId,int $cardId): array
    {
        $card=$this->requireCard($siteId,$cardId);
        return $this->safeCard($card)+['ledger'=>$this->db()->all('SELECT id,entry_type,amount_delta_minor,balance_after_minor,order_id,correlation_id,actor_type,created_by_iam_user_id,reason,created_at FROM sale_gift_card_ledger WHERE gift_card_id=? ORDER BY id',[$cardId]),'deliveries'=>$this->db()->all('SELECT id,delivery_type,channel,status,recipient_hint,expires_at,claimed_at,created_by_iam_user_id,created_at FROM sale_gift_card_deliveries WHERE gift_card_id=? ORDER BY id DESC',[$cardId])];
    }

    /** @return array<string,mixed> */
    private function createDelivery(int $cardId,string $code,string $type,mixed $recipient,?int $actor,?string $correlation): array
    {
        $token=$this->encryptToken(['gift_card_id'=>$cardId,'code'=>$code,'nonce'=>bin2hex(random_bytes(8))]);
        $hint=$this->recipientHint($recipient);
        $expires=gmdate('Y-m-d H:i:s',time()+604800);
        $this->db()->run('INSERT INTO sale_gift_card_deliveries(gift_card_id,delivery_type,channel,status,claim_token_hash,recipient_hint,expires_at,created_by_iam_user_id,correlation_id) VALUES(?,?,\'secure_claim\',\'pending\',?,?,?,?,?)',[$cardId,$type,hash('sha256',$token),$hint,$expires,$actor,$correlation]);
        $deliveryId=$this->db()->lastInsertId();
        $email=trim((string)$recipient);
        if($this->mailer!==null && filter_var($email,FILTER_VALIDATE_EMAIL)!==false){
            $channel=(string)($this->db()->one('SELECT c.code FROM sale_gift_cards g JOIN sale_orders o ON o.id=g.origin_order_id JOIN sale_channels c ON c.id=o.channel_id WHERE g.id=?',[$cardId])['code']??'web-main');
            $url=rtrim((string)(function_exists('app_base_path')?app_base_path():''),'/').'/gift-card?channel='.rawurlencode($channel).'#claim='.rawurlencode($token);
            $sent=$this->mailer->send($email,'Votre bon cadeau / Your gift card',"Votre bon cadeau est disponible via ce lien sécurisé, valable une seule fois :\n".$url."\n\nYour gift card is available through this one-time secure link.");
            if($sent)$this->db()->run("UPDATE sale_gift_card_deliveries SET channel='email' WHERE id=?",[$deliveryId]);
        }
        return ['delivery_id'=>$deliveryId,'claim_token'=>$token,'expires_at'=>$expires,'recipient_hint'=>$hint];
    }

    private function ledger(int $cardId,string $type,int $delta,int $balance,string $key,?int $orderId,?int $lineId,?string $correlation,string $actorType,?int $actorId,?string $reason): int
    {
        $this->db()->run('INSERT INTO sale_gift_card_ledger(gift_card_id,entry_type,amount_delta_minor,balance_after_minor,order_id,order_line_id,idempotency_key_hash,correlation_id,actor_type,created_by_iam_user_id,reason) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$cardId,$type,$delta,$balance,$orderId,$lineId,hash('sha256',$key),$correlation,$actorType,$actorId,$reason]);
        return $this->db()->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    private function findUsable(int $siteId,string $currency,string $code): ?array
    {
        $canonical=$this->canonicalCode($code); if(strlen($canonical)<20) return null;
        $card=$this->db()->one('SELECT * FROM sale_gift_cards WHERE site_id=? AND currency=? AND code_verifier=?',[$siteId,strtoupper($currency),$this->verifier($canonical)]);
        if($card===null) return null;
        if((string)$card['status']==='active' && $card['expires_at']!==null && (string)$card['expires_at']<=gmdate('Y-m-d H:i:s')){
            $balance=(int)$card['balance_minor'];
            $this->db()->transaction(function() use($card,$balance):void{$this->db()->run("UPDATE sale_gift_cards SET balance_minor=0,status='expired',version=version+1,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='active'",[(int)$card['id']]);if((int)($this->db()->one('SELECT changes() AS total')['total']??0)===1)$this->ledger((int)$card['id'],'expiration',-$balance,0,'expire:'.$card['id'],null,null,null,'system',null,'expiry');});
            return null;
        }
        return (string)$card['status']==='active' && (int)$card['balance_minor']>0 ? $card : null;
    }

    /** @return array<string,mixed> */
    private function requireCard(int $siteId,int $cardId): array
    {
        $row=$this->db()->one('SELECT * FROM sale_gift_cards WHERE site_id=? AND id=?',[$siteId,$cardId]);
        if($row===null) throw new SaleValidationException('sale.gift_card_not_found'); return $row;
    }

    /** @param array<string,mixed> $card @return array<string,mixed> */
    private function safeCard(array $card): array
    {
        return ['id'=>(int)($card['id']??0),'site_id'=>(int)($card['site_id']??0),'public_reference'=>(string)($card['public_reference']??''),'masked_code'=>'•••• '.(string)($card['code_last4']??''),'initial_value_minor'=>(int)($card['initial_value_minor']??0),'balance_minor'=>(int)($card['balance_minor']??0),'currency'=>(string)($card['currency']??''),'status'=>(string)($card['status']??''),'origin_order_id'=>(int)($card['origin_order_id']??0),'origin_order_line_id'=>(int)($card['origin_order_line_id']??0),'recipient_name'=>$card['recipient_name']??null,'recipient_hint'=>$this->recipientHint($card['recipient_email']??null),'send_at'=>$card['send_at']??null,'issued_at'=>$card['issued_at']??null,'expires_at'=>$card['expires_at']??null,'cancelled_at'=>$card['cancelled_at']??null];
    }

    private function newCode(): string
    {
        $raw=strtoupper(rtrim(strtr(base64_encode(random_bytes(24)),'+/','AZ'),'='));
        $raw=preg_replace('/[^A-Z0-9]/','',$raw)??''; return 'GC-'.implode('-',str_split(substr($raw,0,28),4));
    }
    private function canonicalCode(string $code): string { return strtoupper(preg_replace('/[^A-Z0-9]/','',trim($code))??''); }
    private function verifier(string $code): string { return hash_hmac('sha256',$this->canonicalCode($code),$this->secret); }
    private function nullable(mixed $value): ?string { $v=trim((string)$value); return $v===''?null:$v; }
    private function recipientHint(mixed $email): ?string { $email=trim((string)$email); if(!str_contains($email,'@'))return null;[$a,$b]=explode('@',$email,2);return substr($a,0,1).'***@'.$b; }
    private function encryptToken(array $payload): string
    {
        $iv=random_bytes(12);$tag='';$plain=json_encode($payload,JSON_UNESCAPED_SLASHES)?:'{}';
        $cipher=openssl_encrypt($plain,'aes-256-gcm',$this->secret,OPENSSL_RAW_DATA,$iv,$tag,'gift-card-claim');
        if($cipher===false) throw new SalePaymentException('sale.gift_card_delivery_unavailable');
        return rtrim(strtr(base64_encode($iv.$tag.$cipher),'+/','-_'),'=');
    }
    /** @return array<string,mixed> */
    private function decryptToken(string $token): array
    {
        $raw=base64_decode(strtr($token,'-_','+/'),true); if($raw===false||strlen($raw)<29)throw new SaleValidationException('sale.gift_card_claim_unavailable');
        $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$this->secret,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'gift-card-claim');
        $data=$plain===false?null:json_decode($plain,true); if(!is_array($data))throw new SaleValidationException('sale.gift_card_claim_unavailable'); return $data;
    }
    private function db(): Database { return $this->connection->database() ?? throw new SaleValidationException('sale.database_unavailable'); }
}
