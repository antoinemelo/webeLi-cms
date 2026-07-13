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
        private readonly ?SaleStateMachineService $states = null
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

    /** @return array<string,mixed> */
    public function refundPayment(int $transactionId, int $amountMinor, ?string $reason = null, ?int $iamUserId = null, ?string $idempotencyKey = null): array
    {
        $tx = $this->payments->requireTransactionWithOrder($transactionId);
        $amountMinor = $amountMinor > 0 ? $amountMinor : (int) $tx['amount_minor'];
        $providerPayload = json_decode((string) ($tx['provider_payload_json'] ?? '{}'), true);
        $providerKey = $this->providerRegistry()->normalize((string) ($providerPayload['provider'] ?? 'manual_card'));
        $request = [
            'transaction_id' => $transactionId,
            'amount_minor' => $amountMinor,
            'provider_key' => $providerKey,
            'reason' => trim((string) $reason),
        ];
        $correlationId = SaleStateMachineService::correlationId();
        $callback = function () use ($tx, $amountMinor, $providerKey, $reason, $iamUserId, $correlationId): array {
            $result = $this->payments->rawDatabase()->transaction(
                fn(): array => $this->refundPaymentNow($tx, $amountMinor, $providerKey, $reason, $iamUserId, $correlationId)
            );
            if (!empty($result['_provider_failed'])) {
                throw new SalePaymentException('sale.refund_provider_failed');
            }
            return $result;
        };
        if ($this->idempotency === null) {
            return $callback();
        }
        return $this->idempotency->run((int) $tx['site_id'], 'refund.create', $idempotencyKey, $request, $callback);
    }

    /** @param array<string,mixed> $tx @return array<string,mixed> */
    private function refundPaymentNow(array $tx, int $amountMinor, string $providerKey, ?string $reason, ?int $iamUserId, string $correlationId): array
    {
        if ($amountMinor < 1) {
            throw new SalePaymentException('sale.refund_amount_invalid');
        }
        if (!in_array((string) $tx['transaction_type'], ['payment', 'capture'], true) || (string) $tx['status'] !== 'succeeded') {
            throw new SalePaymentException('sale.payment_transaction_not_refundable');
        }
        $alreadyRefunded = $this->payments->refundedForTransaction((int) $tx['id']);
        if ($alreadyRefunded + $amountMinor > (int) $tx['amount_minor']) {
            throw new SalePaymentException('sale.refund_exceeds_payment');
        }
        $provider = $this->providerRegistry()->contract($providerKey);
        if (!($provider->capabilities()['refund'] ?? false)) {
            throw new SalePaymentException('sale.payment_provider_unsupported');
        }
        $providerResult = $provider->refund([
            'order_id' => (int) $tx['order_id'],
            'transaction_id' => (int) $tx['id'],
            'intent_id' => isset($tx['payment_intent_id']) ? (int) $tx['payment_intent_id'] : 0,
            'amount_minor' => $amountMinor,
            'currency' => (string) $tx['currency'],
        ]);
        $status = (string) ($providerResult['status'] ?? 'succeeded');
        $refund = $this->payments->createRefund((int) $tx['order_id'], (int) $tx['id'], $amountMinor, (string) $tx['currency'], $reason, $iamUserId, 'draft');
        $states = $this->states ?? new SaleStateMachineService($this->payments->rawDatabase());
        $states->recordInitial((int) $tx['site_id'], 'refund', (int) $refund['id'], 'draft', $correlationId, $iamUserId, 'refund requested');
        $states->transition('refund', (int) $refund['id'], 'pending', $iamUserId, $reason, $correlationId);
        $refund = $states->transition('refund', (int) $refund['id'], $status === 'succeeded' ? 'succeeded' : 'failed', $iamUserId, $reason, $correlationId);
        $refundTransaction = $this->payments->recordTransaction((int) $tx['order_id'], $amountMinor, (string) $tx['currency'], 'refund', [
            'payment_intent_id' => $tx['payment_intent_id'] ?? null,
            'status' => $status,
            'provider_transaction_id' => $providerResult['provider_transaction_id'] ?? null,
            'provider_payload' => $providerResult['payload'] ?? [],
            'allocate' => false,
            'correlation_id' => $correlationId,
            'created_by_iam_user_id' => $iamUserId,
        ]);
        if ($status !== 'succeeded') {
            return ['order' => $this->orders->requireOrder((int) $tx['order_id']), 'refund' => $refund, 'transaction' => $refundTransaction, '_provider_failed' => true];
        }
        $refundedTotal = (int) $tx['refunded_total_minor'] + $amountMinor;
        $order = $this->orders->updateRefundedTotal((int) $tx['order_id'], $refundedTotal);
        if (isset($tx['payment_intent_id']) && (int) $tx['payment_intent_id'] > 0) {
            $this->payments->rawDatabase()->run(
                'UPDATE sale_payment_intents SET refunded_minor=MIN(captured_minor,refunded_minor+?),provider_synced_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP,version=version+1 WHERE id=?',
                [$amountMinor, (int) $tx['payment_intent_id']]
            );
        }
        $this->events->emit((int) $tx['site_id'], 'sale.refund.created', 'order', (int) $tx['order_id'], [
            'site_id' => (int) $tx['site_id'],
            'order_id' => (int) $tx['order_id'],
            'refund_id' => (int) $refund['id'],
            'transaction_id' => (int) $refundTransaction['id'],
            'payment_transaction_id' => (int) $tx['id'],
            'amount_minor' => $amountMinor,
            'currency' => (string) $tx['currency'],
            'provider_key' => $providerKey,
            'reason' => $reason,
            'iam_user_id' => $iamUserId,
        ], $iamUserId, $correlationId);
        $this->events->emit((int) $tx['site_id'], 'sale.refund.completed', 'order', (int) $tx['order_id'], [
            'site_id' => (int) $tx['site_id'],
            'order_id' => (int) $tx['order_id'],
            'refund_id' => (int) $refund['id'],
            'transaction_id' => (int) $refundTransaction['id'],
            'amount_minor' => $amountMinor,
            'currency' => (string) $tx['currency'],
            'reason' => $reason,
            'iam_user_id' => $iamUserId,
        ], $iamUserId, $correlationId);
        return ['order' => $order, 'refund' => $refund, 'transaction' => $refundTransaction];
    }

    private function providerRegistry(): PaymentProviderRegistry
    {
        return $this->providers ?? new PaymentProviderRegistry();
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
        $provider = $this->providerRegistry()->get((string) $intent['provider_key']);
        if (!$provider->supports('void')) {
            throw new SalePaymentException('sale.payment_provider_unsupported');
        }
        $correlationId = SaleStateMachineService::correlationId();
        return $this->payments->rawDatabase()->transaction(function () use ($intent, $intentId, $iamUserId, $provider, $correlationId): array {
            $result = $provider->void(['order_id' => (int) $intent['order_id'], 'intent_id' => $intentId, 'amount_minor' => (int) $intent['amount_minor'], 'currency' => (string) $intent['currency']]);
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
