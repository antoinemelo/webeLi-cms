<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Modules\Sale\Exceptions\SalePaymentException;
use App\Modules\Sale\Payments\PaymentProviderRegistry;
use App\Modules\Sale\Repositories\SaleOrderRepository;
use App\Modules\Sale\Repositories\SalePaymentRepository;

final class SalePaymentService
{
    public function __construct(
        private readonly SalePaymentRepository $payments,
        private readonly SaleOrderRepository $orders,
        private readonly SaleEventService $events,
        private readonly ?SaleIdempotencyService $idempotency = null,
        private readonly ?PaymentProviderRegistry $providers = null,
        private readonly ?SaleStateMachineService $states = null,
        private readonly ?SaleInventoryService $inventory = null,
    ) {}

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function recordManualPayment(int $orderId, int $amountMinor, ?int $iamUserId = null, array $options = []): array
    {
        if ($amountMinor < 1) {
            throw new SalePaymentException('sale.payment_amount_invalid');
        }
        $order = $this->orders->requireOrder($orderId);
        $providerKey = $this->providerRegistry()->normalize((string) ($options['provider_key'] ?? $options['payment_method'] ?? 'manual_card'));
        $request = [
            'order_id' => $orderId,
            'amount_minor' => $amountMinor,
            'provider_key' => $providerKey,
        ];
        $correlationId = SaleStateMachineService::correlationId($options['correlation_id'] ?? null);
        $callback = function () use ($order, $amountMinor, $providerKey, $iamUserId, $options, $correlationId): array {
            $result = $this->payments->rawDatabase()->transaction(
                fn(): array => $this->recordPaymentNow($order, $amountMinor, $providerKey, $iamUserId, $options, $correlationId)
            );
            if (!empty($result['_provider_failed'])) {
                throw new SalePaymentException('sale.payment_provider_failed');
            }
            return $result;
        };
        if ($this->idempotency === null) {
            return $callback();
        }
        return $this->idempotency->run((int) $order['site_id'], 'payment.capture', $options['idempotency_key'] ?? null, $request, $callback);
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    private function recordPaymentNow(array $order, int $amountMinor, string $providerKey, ?int $iamUserId, array $options, string $correlationId): array
    {
        $orderId = (int) $order['id'];
        $newTotal = $this->payments->allocatedTotal($orderId) + $amountMinor;
        if ($newTotal > (int) $order['grand_total_minor']) {
            throw new SalePaymentException('sale.payment_exceeds_order_total');
        }
        $provider = $this->providerRegistry()->contract($providerKey);
        if (!($provider->capabilities()['authorize'] ?? false)) {
            throw new SalePaymentException('sale.payment_provider_unsupported');
        }
        $intent = $this->payments->createIntent(
            (int) $order['site_id'],
            (int) $order['channel_id'],
            $orderId,
            $providerKey,
            $amountMinor,
            (string) $order['currency'],
            $options['idempotency_key'] ?? null,
            ['source' => $options['source'] ?? 'admin']
        );
        $providerResult = $provider->authorize([
            'order_id' => $orderId,
            'intent_id' => (int) $intent['id'],
            'amount_minor' => $amountMinor,
            'currency' => (string) $order['currency'],
            'idempotency_key' => $options['idempotency_key'] ?? null,
            'operator_reference' => $options['operator_reference'] ?? null,
            'comment' => $options['comment'] ?? null,
            'proof_asset_id' => $options['proof_asset_id'] ?? null,
        ]);
        $status = (string) ($providerResult['status'] ?? 'succeeded');
        $this->payments->setIntentReference((int) $intent['id'], $providerResult['provider_reference'] ?? null);
        ($this->states ?? new SaleStateMachineService($this->payments->rawDatabase()))->transition(
            'payment_intent', (int) $intent['id'], $status === 'succeeded' ? 'captured' : 'failed', $iamUserId, null, $correlationId
        );
        $transaction = $this->payments->recordTransaction($orderId, $amountMinor, (string) $order['currency'], 'payment', [
            'payment_intent_id' => (int) $intent['id'],
            'status' => $status,
            'provider_transaction_id' => $providerResult['provider_transaction_id'] ?? null,
            'provider_payload' => $providerResult['payload'] ?? [],
            'correlation_id' => $correlationId,
            'created_by_iam_user_id' => $iamUserId,
        ]);
        if ($status !== 'succeeded') {
            $this->events->emit((int) $order['site_id'], 'sale.payment.failed', 'order', $orderId, [
                'site_id' => (int) $order['site_id'],
                'order_id' => $orderId,
                'transaction_id' => (int) $transaction['id'],
                'amount_minor' => $amountMinor,
                'currency' => (string) $order['currency'],
                'provider_key' => $providerKey,
                'error_code' => (string) ($providerResult['error_code'] ?? 'provider_failed'),
                'error_message' => (string) ($providerResult['error_message'] ?? 'Payment provider failed.'),
                'iam_user_id' => $iamUserId,
            ], $iamUserId, $correlationId);
            return ['order' => $order, 'transaction' => $transaction, 'intent' => $intent, '_provider_failed' => true];
        }
        $order = $this->orders->updatePaidTotal($orderId, $newTotal);
        $this->events->emit((int) $order['site_id'], 'sale.payment.recorded', 'order', $orderId, [
            'site_id' => (int) $order['site_id'],
            'order_id' => $orderId,
            'transaction_id' => (int) $transaction['id'],
            'amount_minor' => $amountMinor,
            'currency' => (string) $order['currency'],
            'provider_key' => $providerKey,
            'payment_status' => (string) $order['payment_status'],
            'iam_user_id' => $iamUserId,
        ], $iamUserId, $correlationId);
        return ['order' => $order, 'transaction' => $transaction, 'intent' => $intent];
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function refundPayment(int $transactionId, int $amountMinor, ?string $reason = null, ?int $iamUserId = null, ?string $idempotencyKey = null, array $options = []): array
    {
        $tx = $this->payments->requireTransactionWithOrder($transactionId);
        $amountMinor = $amountMinor > 0 ? $amountMinor : (int) $tx['amount_minor'];
        $key = trim((string) $idempotencyKey);
        if ($key === '') {
            throw new SalePaymentException('sale.refund_idempotency_key_required');
        }
        $reasonCode = strtolower(trim((string) ($options['reason_code'] ?? 'customer_request')));
        if (!in_array($reasonCode, ['customer_request', 'return', 'duplicate', 'fraud', 'service_failure', 'commercial_gesture', 'other'], true)) {
            throw new SalePaymentException('sale.refund_reason_invalid');
        }
        $reasonNote = trim((string) ($options['reason_note'] ?? $reason));
        if ($reasonCode === 'other' && $reasonNote === '') {
            throw new SalePaymentException('sale.refund_reason_note_required');
        }
        $providerKey = $this->providerRegistry()->normalize((string) ($tx['intent_provider_key'] ?? 'manual_card'));
        $provider = $this->providerRegistry()->contract($providerKey);
        if (!($provider->capabilities()['refund'] ?? false)) {
            throw new SalePaymentException('sale.payment_provider_unsupported');
        }
        $refund = $this->payments->rawDatabase()->transaction(function () use ($tx, $amountMinor, $reason, $reasonCode, $reasonNote, $iamUserId, $key, $options): array {
            $existing = $this->payments->refundByIdempotency((int) $tx['order_id'], $key);
            if ($existing !== null) {
                if ((int) $existing['payment_transaction_id'] !== (int) $tx['id'] || (int) $existing['amount_minor'] !== $amountMinor) {
                    throw new SalePaymentException('sale.refund_idempotency_conflict');
                }
                return $existing + ['_replayed' => true];
            }
            if ($amountMinor < 1) throw new SalePaymentException('sale.refund_amount_invalid');
            if (!in_array((string) $tx['transaction_type'], ['payment', 'capture'], true) || (string) $tx['status'] !== 'succeeded') {
                throw new SalePaymentException('sale.payment_transaction_not_refundable');
            }
            if ($this->payments->refundedForTransaction((int) $tx['id']) + $amountMinor > (int) $tx['amount_minor']) {
                throw new SalePaymentException('sale.refund_exceeds_payment');
            }
            $created = $this->payments->createRefund(
                (int) $tx['order_id'], (int) $tx['id'], $amountMinor, (string) $tx['currency'], $reasonNote !== '' ? $reasonNote : $reason,
                $iamUserId, 'draft', ['reason_code' => $reasonCode, 'reason_note' => $reasonNote, 'return_id' => $options['return_id'] ?? null, 'idempotency_key' => $key]
            );
            $correlationId = SaleStateMachineService::correlationId($options['correlation_id'] ?? null);
            $states = $this->states ?? new SaleStateMachineService($this->payments->rawDatabase(), $this->events);
            $states->recordInitial((int) $tx['site_id'], 'refund', (int) $created['id'], 'draft', $correlationId, $iamUserId, 'refund requested');
            $created = $states->transition('refund', (int) $created['id'], 'pending', $iamUserId, $reasonNote, $correlationId);
            $this->events->emit((int) $tx['site_id'], 'sale.refund.requested', 'order', (int) $tx['order_id'], [
                'site_id' => (int) $tx['site_id'], 'order_id' => (int) $tx['order_id'], 'refund_id' => (int) $created['id'],
                'payment_transaction_id' => (int) $tx['id'], 'amount_minor' => $amountMinor, 'currency' => (string) $tx['currency'],
                'reason_code' => $reasonCode, 'iam_user_id' => $iamUserId,
            ], $iamUserId, $correlationId);
            return $created;
        });
        if (($refund['_replayed'] ?? false) && (string) $refund['status'] !== 'pending') {
            return $this->refundResult((int) $refund['id'], true);
        }
        return $this->executeRefund((int) $refund['id'], $iamUserId, (bool) ($refund['_replayed'] ?? false));
    }

    private function providerRegistry(): PaymentProviderRegistry
    {
        return $this->providers ?? new PaymentProviderRegistry();
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function confirmIntent(int $intentId, int $amountMinor, int $iamUserId, array $options = []): array
    {
        $intent = $this->payments->requireIntentWithOrder($intentId);
        $contract = $this->providerRegistry()->contract((string) $intent['provider_key']);
        if (in_array((string) $intent['status'], ['authorized', 'partially_captured'], true)
            && (int) $intent['authorized_minor'] > (int) $intent['captured_minor']
            && ($contract->capabilities()['capture'] ?? false)) {
            return $this->captureIntent($intentId, $amountMinor, $iamUserId, $options);
        }
        $request = ['intent_id'=>$intentId,'amount_minor'=>$amountMinor,'operator_reference'=>trim((string)($options['operator_reference']??'')),'comment'=>trim((string)($options['comment']??'')),'proof_asset_id'=>$options['proof_asset_id']??null];
        $callback = function () use ($intent, $intentId, $amountMinor, $iamUserId, $options): array {
            return $this->payments->rawDatabase()->transaction(function () use ($intent, $intentId, $amountMinor, $iamUserId, $options): array {
                $fresh = $this->payments->requireIntentWithOrder($intentId);
                if (!in_array((string)$fresh['status'], ['requires_payment','requires_action','authorized','partially_captured'], true)) throw new SalePaymentException('sale.payment_intent_not_confirmable');
                $remaining = (int)$fresh['amount_minor'] - (int)$fresh['captured_minor'];
                if ($amountMinor < 1 || $amountMinor > $remaining) throw new SalePaymentException('sale.payment_capture_amount_invalid');
                $contract = $this->providerRegistry()->contract((string)$fresh['provider_key']);
                $result = $contract->authorize([
                    'order_id'=>(int)$fresh['order_id'],'intent_id'=>$intentId,'provider_reference'=>$fresh['intent_reference'],
                    'amount_minor'=>$amountMinor,'currency'=>(string)$fresh['currency'],'idempotency_key'=>$options['idempotency_key']??null,
                    'operator_reference'=>$options['operator_reference']??null,'comment'=>$options['comment']??null,'proof_asset_id'=>$options['proof_asset_id']??null,
                ]);
                if ((string)($result['status']??'') !== 'succeeded') throw new SalePaymentException('sale.payment_provider_failed');
                $correlationId = SaleStateMachineService::correlationId();
                $transaction = $this->payments->recordTransaction((int)$fresh['order_id'],$amountMinor,(string)$fresh['currency'],'payment',[
                    'payment_intent_id'=>$intentId,'status'=>'succeeded','provider_transaction_id'=>$result['provider_transaction_id']??null,
                    'provider_payload'=>$result['payload']??[],'correlation_id'=>$correlationId,'created_by_iam_user_id'=>$iamUserId,
                ]);
                $captured = (int)$fresh['captured_minor'] + $amountMinor;
                $target = $captured >= (int)$fresh['amount_minor'] ? 'captured' : 'partially_captured';
                $states = $this->states ?? new SaleStateMachineService($this->payments->rawDatabase(), $this->events);
                $states->transition('payment_intent',$intentId,$target,$iamUserId,trim((string)($options['comment']??''))?:'operator confirmation',$correlationId);
                $this->payments->rawDatabase()->run('UPDATE sale_payment_intents SET authorized_minor=MAX(authorized_minor,?),captured_minor=?,provider_synced_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP,version=version+1 WHERE id=?',[$captured,$captured,$intentId]);
                $order = $this->orders->updatePaidTotal((int)$fresh['order_id'],$this->payments->allocatedTotal((int)$fresh['order_id']));
                if ($target === 'captured' && (string)$order['status'] === 'pending_payment') {
                    if ($this->inventory !== null && $order['source_cart_id'] !== null) $this->inventory->consumeCartReservations((int)$order['source_cart_id'],(int)$order['id']);
                    $order = $states->transition('order',(int)$order['id'],'confirmed',$iamUserId,'payment confirmed',$correlationId);
                    $this->payments->rawDatabase()->run('UPDATE sale_orders SET placed_at=COALESCE(placed_at,CURRENT_TIMESTAMP) WHERE id=?',[(int)$order['id']]);
                }
                $this->payments->rawDatabase()->run("UPDATE sale_payment_attempts SET status=?,finished_at=CASE WHEN ?='succeeded' THEN CURRENT_TIMESTAMP ELSE finished_at END WHERE payment_intent_id=?",[$target==='captured'?'succeeded':'pending',$target==='captured'?'succeeded':'pending',$intentId]);
                $this->events->emit((int)$fresh['site_id'],'sale.payment.confirmed','order',(int)$fresh['order_id'],['site_id'=>(int)$fresh['site_id'],'order_id'=>(int)$fresh['order_id'],'payment_intent_id'=>$intentId,'transaction_id'=>(int)$transaction['id'],'amount_minor'=>$amountMinor,'currency'=>(string)$fresh['currency'],'provider_key'=>(string)$fresh['provider_key'],'operator_reference'=>$options['operator_reference']??null,'proof_asset_id'=>$options['proof_asset_id']??null,'iam_user_id'=>$iamUserId],$iamUserId,$correlationId);
                return ['order'=>$order,'intent'=>$this->payments->requireIntentWithOrder($intentId),'transaction'=>$transaction,'remaining_minor'=>max(0,(int)$fresh['amount_minor']-$captured),'replayed'=>false];
            });
        };
        if ($this->idempotency === null) return $callback();
        return $this->idempotency->run((int)$intent['site_id'],'payment.confirm',$options['idempotency_key']??null,$request,$callback);
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function captureIntent(int $intentId, int $amountMinor, ?int $iamUserId = null, array $options = []): array
    {
        $key = trim((string) ($options['idempotency_key'] ?? ''));
        if ($key === '') throw new SalePaymentException('sale.payment_capture_idempotency_key_required');
        $reasonCode = strtolower(trim((string) ($options['reason_code'] ?? 'order_ready')));
        if (!in_array($reasonCode, ['order_ready', 'partial_fulfillment', 'service_delivered', 'manual_review', 'other'], true)) {
            throw new SalePaymentException('sale.payment_capture_reason_invalid');
        }
        $reasonNote = trim((string) ($options['reason_note'] ?? $options['comment'] ?? ''));
        if ($reasonCode === 'other' && $reasonNote === '') throw new SalePaymentException('sale.payment_capture_reason_note_required');

        $transaction = $this->payments->rawDatabase()->transaction(function () use ($intentId, $amountMinor, $iamUserId, $options, $key, $reasonCode, $reasonNote): array {
            $existing = $this->payments->transactionByOperationKey($intentId, 'capture', $key);
            if ($existing !== null) {
                if ((int) $existing['amount_minor'] !== $amountMinor) throw new SalePaymentException('sale.payment_capture_idempotency_conflict');
                return $existing + ['_replayed' => true];
            }
            $intent = $this->payments->requireIntentWithOrder($intentId);
            if (!in_array((string) $intent['status'], ['authorized', 'partially_captured'], true)) {
                throw new SalePaymentException('sale.payment_intent_not_capturable');
            }
            $contract = $this->providerRegistry()->contract((string) $intent['provider_key']);
            if (!($contract->capabilities()['capture'] ?? false)) throw new SalePaymentException('sale.payment_provider_unsupported');
            if ((int) $intent['captured_minor'] > 0 && !($contract->capabilities()['multiple_capture'] ?? false)) {
                throw new SalePaymentException('sale.payment_multiple_capture_unsupported');
            }
            $reserved = (int) (($this->payments->rawDatabase()->one(
                "SELECT COALESCE(SUM(amount_minor),0) AS total FROM sale_payment_transactions WHERE payment_intent_id=? AND transaction_type='capture' AND status='pending'",
                [$intentId]
            )['total'] ?? 0));
            $remaining = (int) $intent['authorized_minor'] - (int) $intent['captured_minor'] - $reserved;
            if ($amountMinor < 1 || $amountMinor > $remaining) throw new SalePaymentException('sale.payment_capture_amount_invalid');
            $correlationId = SaleStateMachineService::correlationId($options['correlation_id'] ?? null);
            $created = $this->payments->recordTransaction((int) $intent['order_id'], $amountMinor, (string) $intent['currency'], 'capture', [
                'payment_intent_id' => $intentId, 'status' => 'pending', 'operation_key' => $key, 'allocate' => false,
                'provider_payload' => ['reason_code' => $reasonCode, 'reason_note' => $reasonNote],
                'correlation_id' => $correlationId, 'created_by_iam_user_id' => $iamUserId,
            ]);
            $this->events->emit((int) $intent['site_id'], 'sale.payment.capture.requested', 'order', (int) $intent['order_id'], [
                'site_id' => (int) $intent['site_id'], 'order_id' => (int) $intent['order_id'], 'payment_intent_id' => $intentId,
                'transaction_id' => (int) $created['id'], 'amount_minor' => $amountMinor, 'currency' => (string) $intent['currency'],
                'reason_code' => $reasonCode, 'iam_user_id' => $iamUserId,
            ], $iamUserId, $correlationId);
            return $created;
        });
        if (($transaction['_replayed'] ?? false) && (string) $transaction['status'] !== 'pending') {
            return $this->captureResult((int) $transaction['id'], true);
        }
        return $this->executeCapture((int) $transaction['id'], $iamUserId, (bool) ($transaction['_replayed'] ?? false));
    }

    /** @return array<string,mixed> */
    private function executeCapture(int $transactionId, ?int $iamUserId, bool $replayed): array
    {
        $row = $this->payments->rawDatabase()->one(
            'SELECT t.*,i.provider_key,i.intent_reference,i.site_id,i.status AS intent_status,i.amount_minor AS intent_amount_minor,
                    i.authorized_minor,i.captured_minor,o.status AS order_status,o.source_cart_id
             FROM sale_payment_transactions t JOIN sale_payment_intents i ON i.id=t.payment_intent_id JOIN sale_orders o ON o.id=t.order_id WHERE t.id=?',
            [$transactionId]
        ) ?? throw new SalePaymentException('sale.payment_transaction_not_found');
        if ((string) $row['status'] !== 'pending') return $this->captureResult($transactionId, true);
        $provider = $this->providerRegistry()->contract((string) $row['provider_key']);
        try {
            $result = $provider->capture([
                'order_id' => (int) $row['order_id'], 'intent_id' => (int) $row['payment_intent_id'],
                'provider_reference' => $row['intent_reference'], 'amount_minor' => (int) $row['amount_minor'],
                'currency' => (string) $row['currency'], 'idempotency_key' => (string) $row['operation_key'],
            ]);
        } catch (\Throwable $e) {
            return $this->deferCapture($row, $e->getMessage(), $iamUserId, $replayed);
        }
        $providerStatus = strtolower((string) ($result['status'] ?? 'pending'));
        if (!in_array($providerStatus, ['succeeded', 'captured'], true)) {
            if (in_array($providerStatus, ['failed', 'cancelled'], true)) {
                $this->payments->rawDatabase()->run(
                    "UPDATE sale_payment_transactions SET status='failed',attempt_count=attempt_count+1,error_code=?,error_message=?,last_error=?,processed_at=CURRENT_TIMESTAMP WHERE id=? AND status='pending'",
                    [(string) ($result['error_code'] ?? 'provider_failed'), (string) ($result['error_message'] ?? 'Capture refused by provider.'), 'provider status: ' . $providerStatus, $transactionId]
                );
                return $this->captureResult($transactionId, $replayed);
            }
            return $this->deferCapture($row, 'provider status: ' . $providerStatus, $iamUserId, $replayed, $result);
        }
        $this->payments->rawDatabase()->transaction(function () use ($row, $result, $iamUserId): void {
            $fresh = $this->payments->rawDatabase()->one('SELECT * FROM sale_payment_transactions WHERE id=?', [(int) $row['id']]);
            if ($fresh === null || (string) $fresh['status'] !== 'pending') return;
            $this->payments->rawDatabase()->run(
                "UPDATE sale_payment_transactions SET status='succeeded',provider_transaction_id=?,provider_payload_json=?,attempt_count=attempt_count+1,last_error=NULL,processed_at=CURRENT_TIMESTAMP WHERE id=? AND status='pending'",
                [$result['provider_transaction_id'] ?? null, json_encode($result['payload'] ?? [], JSON_UNESCAPED_SLASHES) ?: '{}', (int) $row['id']]
            );
            $this->payments->rawDatabase()->run(
                'INSERT INTO sale_payment_allocations(order_id,payment_transaction_id,amount_minor,currency) SELECT ?,?,?,? WHERE NOT EXISTS(SELECT 1 FROM sale_payment_allocations WHERE payment_transaction_id=?)',
                [(int) $row['order_id'], (int) $row['id'], (int) $row['amount_minor'], (string) $row['currency'], (int) $row['id']]
            );
            $this->payments->rawDatabase()->run(
                'UPDATE sale_payment_intents SET captured_minor=MIN(authorized_minor,captured_minor+?),provider_synced_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP,version=version+1 WHERE id=?',
                [(int) $row['amount_minor'], (int) $row['payment_intent_id']]
            );
            $intent = $this->payments->requireIntentWithOrder((int) $row['payment_intent_id']);
            $target = (int) $intent['captured_minor'] >= (int) $intent['authorized_minor'] ? 'captured' : 'partially_captured';
            if ((string) $intent['status'] !== $target) {
                ($this->states ?? new SaleStateMachineService($this->payments->rawDatabase(), $this->events))->transition(
                    'payment_intent', (int) $row['payment_intent_id'], $target, $iamUserId, 'provider capture', (string) $row['correlation_id']
                );
            }
            $order = $this->orders->updatePaidTotal((int) $row['order_id'], $this->payments->allocatedTotal((int) $row['order_id']));
            if ($target === 'captured' && (string) $order['status'] === 'pending_payment') {
                if ($this->inventory !== null && $order['source_cart_id'] !== null) $this->inventory->consumeCartReservations((int) $order['source_cart_id'], (int) $order['id']);
                $order = ($this->states ?? new SaleStateMachineService($this->payments->rawDatabase(), $this->events))->transition(
                    'order', (int) $order['id'], 'confirmed', $iamUserId, 'payment captured', (string) $row['correlation_id']
                );
                $this->payments->rawDatabase()->run('UPDATE sale_orders SET placed_at=COALESCE(placed_at,CURRENT_TIMESTAMP) WHERE id=?', [(int) $order['id']]);
            }
            $this->events->emit((int) $intent['site_id'], 'sale.payment.capture.completed', 'order', (int) $row['order_id'], [
                'site_id' => (int) $intent['site_id'], 'order_id' => (int) $row['order_id'], 'payment_intent_id' => (int) $row['payment_intent_id'],
                'transaction_id' => (int) $row['id'], 'amount_minor' => (int) $row['amount_minor'], 'currency' => (string) $row['currency'],
                'iam_user_id' => $iamUserId,
            ], $iamUserId, (string) $row['correlation_id']);
        });
        return $this->captureResult($transactionId, $replayed);
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $providerResult @return array<string,mixed> */
    private function deferCapture(array $row, string $error, ?int $iamUserId, bool $replayed, array $providerResult = []): array
    {
        $attempt = (int) $row['attempt_count'] + 1;
        $dead = $attempt >= (int) $row['max_attempts'];
        $delay = min(3600, 30 * (2 ** min(6, max(0, $attempt - 1))));
        $this->payments->rawDatabase()->run(
            "UPDATE sale_payment_transactions SET status=?,attempt_count=?,available_at=datetime('now',?),last_error=?,provider_payload_json=?,dead_lettered_at=CASE WHEN ?='dead_letter' THEN CURRENT_TIMESTAMP ELSE NULL END WHERE id=? AND status='pending'",
            [$dead ? 'dead_letter' : 'pending', $attempt, '+' . $delay . ' seconds', substr($error, 0, 500), json_encode($providerResult['payload'] ?? [], JSON_UNESCAPED_SLASHES) ?: '{}', $dead ? 'dead_letter' : 'pending', (int) $row['id']]
        );
        $event = $dead ? 'sale.payment.capture.dead_lettered' : 'sale.payment.capture.retry_scheduled';
        $this->events->emit((int) $row['site_id'], $event, 'order', (int) $row['order_id'], [
            'site_id' => (int) $row['site_id'], 'order_id' => (int) $row['order_id'], 'payment_intent_id' => (int) $row['payment_intent_id'],
            'transaction_id' => (int) $row['id'], 'attempt_count' => $attempt, 'iam_user_id' => $iamUserId,
        ], $iamUserId, (string) $row['correlation_id']);
        return $this->captureResult((int) $row['id'], $replayed);
    }

    /** @return array<string,mixed> */
    private function captureResult(int $transactionId, bool $replayed): array
    {
        $transaction = $this->payments->rawDatabase()->one('SELECT * FROM sale_payment_transactions WHERE id=?', [$transactionId])
            ?? throw new SalePaymentException('sale.payment_transaction_not_found');
        $intent = $this->payments->requireIntentWithOrder((int) $transaction['payment_intent_id']);
        return ['order' => $this->orders->requireOrder((int) $transaction['order_id']), 'intent' => $intent, 'transaction' => $transaction,
            'remaining_minor' => max(0, (int) $intent['authorized_minor'] - (int) $intent['captured_minor']), 'replayed' => $replayed];
    }

    /** @return array<string,mixed> */
    private function executeRefund(int $refundId, ?int $iamUserId, bool $replayed): array
    {
        $row = $this->payments->rawDatabase()->one(
            'SELECT r.*,t.payment_intent_id,t.provider_transaction_id AS captured_provider_transaction_id,
                    i.provider_key,i.intent_reference,o.site_id,o.refunded_total_minor
             FROM sale_refunds r JOIN sale_payment_transactions t ON t.id=r.payment_transaction_id
             JOIN sale_orders o ON o.id=r.order_id LEFT JOIN sale_payment_intents i ON i.id=t.payment_intent_id WHERE r.id=?',
            [$refundId]
        ) ?? throw new SalePaymentException('sale.refund_not_found');
        if ((string) $row['status'] !== 'pending') return $this->refundResult($refundId, true);
        try {
            $result = $this->providerRegistry()->contract((string) ($row['provider_key'] ?? 'manual_card'))->refund([
                'order_id' => (int) $row['order_id'], 'transaction_id' => (int) $row['payment_transaction_id'],
                'intent_id' => (int) ($row['payment_intent_id'] ?? 0), 'provider_reference' => $row['intent_reference'] ?? null,
                'provider_transaction_id' => $row['captured_provider_transaction_id'] ?? null, 'amount_minor' => (int) $row['amount_minor'],
                'currency' => (string) $row['currency'], 'idempotency_key' => (string) $row['idempotency_key'],
                'reason_code' => (string) ($row['reason_code'] ?? 'customer_request'),
            ]);
        } catch (\Throwable $e) {
            return $this->deferRefund($row, $e->getMessage(), $iamUserId, $replayed);
        }
        $providerStatus = strtolower((string) ($result['status'] ?? 'pending'));
        if (!in_array($providerStatus, ['succeeded', 'refunded'], true)) {
            if (in_array($providerStatus, ['failed', 'cancelled'], true)) {
                $this->payments->rawDatabase()->transaction(function () use ($row, $result, $providerStatus, $iamUserId): void {
                    ($this->states ?? new SaleStateMachineService($this->payments->rawDatabase(), $this->events))->transition(
                        'refund', (int) $row['id'], 'failed', $iamUserId, 'provider refused refund'
                    );
                    $this->payments->rawDatabase()->run(
                        'UPDATE sale_refunds SET provider_status=?,provider_reference=?,attempt_count=attempt_count+1,last_error=?,processed_at=CURRENT_TIMESTAMP WHERE id=?',
                        [$providerStatus, $result['provider_transaction_id'] ?? null, (string) ($result['error_message'] ?? 'Provider refused refund.'), (int) $row['id']]
                    );
                });
                return $this->refundResult((int) $row['id'], $replayed);
            }
            return $this->deferRefund($row, 'provider status: ' . $providerStatus, $iamUserId, $replayed, $result);
        }
        $this->payments->rawDatabase()->transaction(function () use ($row, $result, $iamUserId): void {
            $fresh = $this->payments->rawDatabase()->one('SELECT * FROM sale_refunds WHERE id=?', [(int) $row['id']]);
            if ($fresh === null || (string) $fresh['status'] !== 'pending') return;
            $correlationId = SaleStateMachineService::correlationId();
            $refund = ($this->states ?? new SaleStateMachineService($this->payments->rawDatabase(), $this->events))->transition(
                'refund', (int) $row['id'], 'succeeded', $iamUserId, (string) ($row['reason_note'] ?? ''), $correlationId
            );
            $this->payments->rawDatabase()->run(
                'UPDATE sale_refunds SET provider_reference=?,provider_status=?,attempt_count=attempt_count+1,last_error=NULL,processed_at=CURRENT_TIMESTAMP WHERE id=?',
                [$result['provider_transaction_id'] ?? $result['provider_reference'] ?? null, (string) ($result['status'] ?? 'succeeded'), (int) $row['id']]
            );
            $refundTransaction = $this->payments->recordTransaction((int) $row['order_id'], (int) $row['amount_minor'], (string) $row['currency'], 'refund', [
                'payment_intent_id' => $row['payment_intent_id'] ?? null, 'status' => 'succeeded',
                'provider_transaction_id' => $result['provider_transaction_id'] ?? null, 'provider_payload' => $result['payload'] ?? [],
                'allocate' => false, 'operation_key' => (string) $row['idempotency_key'], 'correlation_id' => $correlationId,
                'created_by_iam_user_id' => $iamUserId,
            ]);
            $order = $this->orders->updateRefundedTotal((int) $row['order_id'], (int) $row['refunded_total_minor'] + (int) $row['amount_minor']);
            if ((int) ($row['payment_intent_id'] ?? 0) > 0) {
                $this->payments->rawDatabase()->run(
                    'UPDATE sale_payment_intents SET refunded_minor=MIN(captured_minor,refunded_minor+?),provider_synced_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP,version=version+1 WHERE id=?',
                    [(int) $row['amount_minor'], (int) $row['payment_intent_id']]
                );
            }
            foreach (['sale.refund.created', 'sale.refund.completed'] as $event) {
                $this->events->emit((int) $row['site_id'], $event, 'order', (int) $row['order_id'], [
                    'site_id' => (int) $row['site_id'], 'order_id' => (int) $row['order_id'], 'refund_id' => (int) $refund['id'],
                    'transaction_id' => (int) $refundTransaction['id'], 'payment_transaction_id' => (int) $row['payment_transaction_id'],
                    'amount_minor' => (int) $row['amount_minor'], 'currency' => (string) $row['currency'],
                    'reason' => $row['reason_note'] ?? null, 'reason_code' => $row['reason_code'] ?? null, 'iam_user_id' => $iamUserId,
                ], $iamUserId, $correlationId);
            }
            unset($order);
        });
        return $this->refundResult($refundId, $replayed);
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $providerResult @return array<string,mixed> */
    private function deferRefund(array $row, string $error, ?int $iamUserId, bool $replayed, array $providerResult = []): array
    {
        $attempt = (int) $row['attempt_count'] + 1;
        $dead = $attempt >= (int) $row['max_attempts'];
        $delay = min(3600, 30 * (2 ** min(6, max(0, $attempt - 1))));
        $this->payments->rawDatabase()->run(
            "UPDATE sale_refunds SET attempt_count=?,available_at=datetime('now',?),last_error=?,provider_status=?,dead_lettered_at=CASE WHEN ?=1 THEN CURRENT_TIMESTAMP ELSE NULL END WHERE id=? AND status='pending'",
            [$attempt, '+' . $delay . ' seconds', substr($error, 0, 500), $providerResult['status'] ?? 'unknown', $dead ? 1 : 0, (int) $row['id']]
        );
        // A refund remains pending because a late provider success is still possible; dead-lettering stops automatic retries without claiming financial failure.
        $this->events->emit((int) $row['site_id'], $dead ? 'sale.refund.dead_lettered' : 'sale.refund.retry_scheduled', 'order', (int) $row['order_id'], [
            'site_id' => (int) $row['site_id'], 'order_id' => (int) $row['order_id'], 'refund_id' => (int) $row['id'],
            'amount_minor' => (int) $row['amount_minor'], 'currency' => (string) $row['currency'], 'attempt_count' => $attempt, 'iam_user_id' => $iamUserId,
        ], $iamUserId);
        return $this->refundResult((int) $row['id'], $replayed);
    }

    /** @return array<string,mixed> */
    private function refundResult(int $refundId, bool $replayed): array
    {
        $refund = $this->payments->rawDatabase()->one('SELECT * FROM sale_refunds WHERE id=?', [$refundId])
            ?? throw new SalePaymentException('sale.refund_not_found');
        $transaction = $this->payments->rawDatabase()->one(
            "SELECT * FROM sale_payment_transactions WHERE transaction_type='refund' AND operation_key=? ORDER BY id DESC LIMIT 1",
            [(string) $refund['idempotency_key']]
        );
        return ['order' => $this->orders->requireOrder((int) $refund['order_id']), 'refund' => $refund, 'transaction' => $transaction, 'replayed' => $replayed];
    }

    /** @return array{captures:int,refunds:int,dead_letters:int} */
    public function processDueOperations(?int $siteId = null, int $limit = 50, ?int $iamUserId = null): array
    {
        $limit = max(1, min(200, $limit));
        $siteCapture = $siteId === null ? '' : ' AND i.site_id=' . (int) $siteId;
        $captures = $this->payments->rawDatabase()->all(
            "SELECT t.id FROM sale_payment_transactions t JOIN sale_payment_intents i ON i.id=t.payment_intent_id
             WHERE t.transaction_type='capture' AND t.status='pending' AND t.available_at<=CURRENT_TIMESTAMP" . $siteCapture . ' ORDER BY t.available_at,t.id LIMIT ' . $limit
        );
        foreach ($captures as $capture) $this->executeCapture((int) $capture['id'], $iamUserId, true);
        $remaining = max(0, $limit - count($captures));
        $siteRefund = $siteId === null ? '' : ' AND o.site_id=' . (int) $siteId;
        $refunds = $remaining > 0 ? $this->payments->rawDatabase()->all(
            "SELECT r.id FROM sale_refunds r JOIN sale_orders o ON o.id=r.order_id
             WHERE r.status='pending' AND r.dead_lettered_at IS NULL AND r.attempt_count<r.max_attempts AND r.available_at<=CURRENT_TIMESTAMP" . $siteRefund . ' ORDER BY r.available_at,r.id LIMIT ' . $remaining
        ) : [];
        foreach ($refunds as $refund) $this->executeRefund((int) $refund['id'], $iamUserId, true);
        $dead = (int) (($this->payments->rawDatabase()->one(
            "SELECT (SELECT COUNT(*) FROM sale_payment_transactions t JOIN sale_payment_intents i ON i.id=t.payment_intent_id WHERE t.status='dead_letter'" . $siteCapture . ") +
                    (SELECT COUNT(*) FROM sale_refunds r JOIN sale_orders o ON o.id=r.order_id WHERE r.status='pending' AND r.dead_lettered_at IS NOT NULL" . $siteRefund . ') AS total'
        )['total'] ?? 0));
        return ['captures' => count($captures), 'refunds' => count($refunds), 'dead_letters' => $dead];
    }

    /** @return array<string,mixed> */
    public function recordCorrection(int $orderId, int $amountDeltaMinor, string $reason, string $idempotencyKey, ?int $iamUserId = null, ?int $transactionId = null): array
    {
        $reason = trim($reason);
        $idempotencyKey = trim($idempotencyKey);
        if ($amountDeltaMinor === 0 || $reason === '' || $idempotencyKey === '') {
            throw new SalePaymentException('sale.payment_correction_invalid');
        }
        $db = $this->payments->rawDatabase();
        return $db->transaction(function () use ($orderId, $amountDeltaMinor, $reason, $idempotencyKey, $iamUserId, $transactionId, $db): array {
            $existing = $db->one('SELECT * FROM sale_financial_corrections WHERE order_id=? AND idempotency_key=?', [$orderId, $idempotencyKey]);
            if ($existing !== null) {
                if ((int) $existing['amount_delta_minor'] !== $amountDeltaMinor
                    || (string) $existing['reason'] !== $reason
                    || (int) ($existing['payment_transaction_id'] ?? 0) !== (int) ($transactionId ?? 0)) {
                    throw new SalePaymentException('sale.payment_correction_idempotency_conflict');
                }
                return ['order' => $this->orders->requireOrder($orderId), 'correction' => $existing, 'replayed' => true];
            }
            $order = $this->orders->requireOrder($orderId);
            $newTotal = $this->payments->allocatedTotal($orderId) + $amountDeltaMinor;
            if ($newTotal < 0 || $newTotal > (int) $order['grand_total_minor']) {
                throw new SalePaymentException('sale.payment_correction_total_invalid');
            }
            $correlationId = SaleStateMachineService::correlationId();
            $db->run(
                'INSERT INTO sale_financial_corrections(order_id,payment_transaction_id,amount_delta_minor,currency,reason,idempotency_key,correlation_id,created_by_iam_user_id)
                 VALUES(?,?,?,?,?,?,?,?)',
                [$orderId, $transactionId, $amountDeltaMinor, (string) $order['currency'], $reason, $idempotencyKey, $correlationId, $iamUserId]
            );
            $correction = $db->one('SELECT * FROM sale_financial_corrections WHERE id=?', [(int) $db->lastInsertId()]) ?? [];
            return ['order' => $this->orders->updatePaidTotal($orderId, $newTotal), 'correction' => $correction, 'replayed' => false];
        });
    }

    /** @return array<string,mixed> */
    public function voidIntent(int $intentId, ?int $iamUserId = null): array
    {
        $intent = $this->payments->requireIntentWithOrder($intentId);
        if ((string) $intent['status'] === 'cancelled') {
            return ['intent' => $intent, 'replayed' => true];
        }
        if ((string) $intent['status'] === 'captured') {
            throw new SalePaymentException('sale.payment_intent_already_captured');
        }
        $provider = $this->providerRegistry()->contract((string) $intent['provider_key']);
        if (!($provider->capabilities()['cancel'] ?? false)) {
            throw new SalePaymentException('sale.payment_provider_unsupported');
        }
        $correlationId = SaleStateMachineService::correlationId();
        return $this->payments->rawDatabase()->transaction(function () use ($intent, $intentId, $iamUserId, $provider, $correlationId): array {
            $result = $provider->cancel(['order_id' => (int) $intent['order_id'], 'intent_id' => $intentId, 'provider_reference'=>$intent['intent_reference'], 'amount_minor' => (int) $intent['amount_minor'], 'currency' => (string) $intent['currency']]);
            $cancelled = ($this->states ?? new SaleStateMachineService($this->payments->rawDatabase()))->transition('payment_intent', $intentId, 'cancelled', $iamUserId, 'void before capture', $correlationId);
            $transaction = $this->payments->recordTransaction((int) $intent['order_id'], (int) $intent['amount_minor'], (string) $intent['currency'], 'void', [
                'payment_intent_id' => $intentId,
                'status' => 'cancelled',
                'provider_transaction_id' => $result['provider_transaction_id'] ?? null,
                'provider_payload' => $result['payload'] ?? [],
                'allocate' => false,
                'correlation_id' => $correlationId,
                'created_by_iam_user_id' => $iamUserId,
            ]);
            return ['intent' => $cancelled, 'transaction' => $transaction, 'replayed' => false];
        });
    }
}
