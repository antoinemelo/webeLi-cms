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
        private readonly ?PaymentProviderRegistry $providers = null
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
        $callback = fn(): array => $this->recordPaymentNow($order, $amountMinor, $providerKey, $iamUserId, $options);
        if ($this->idempotency === null) {
            return $callback();
        }
        return $this->idempotency->run((int) $order['site_id'], 'payment.capture', $options['idempotency_key'] ?? null, $request, $callback);
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    private function recordPaymentNow(array $order, int $amountMinor, string $providerKey, ?int $iamUserId, array $options): array
    {
        $orderId = (int) $order['id'];
        $newTotal = $this->payments->allocatedTotal($orderId) + $amountMinor;
        if ($newTotal > (int) $order['grand_total_minor']) {
            throw new SalePaymentException('sale.payment_exceeds_order_total');
        }
        $provider = $this->providerRegistry()->get($providerKey);
        if (!$provider->supports('record_payment')) {
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
        $providerResult = $provider->recordPayment([
            'order_id' => $orderId,
            'intent_id' => (int) $intent['id'],
            'amount_minor' => $amountMinor,
            'currency' => (string) $order['currency'],
        ]);
        $status = (string) ($providerResult['status'] ?? 'succeeded');
        $this->payments->updateIntent((int) $intent['id'], $status === 'succeeded' ? 'captured' : 'failed', $providerResult['provider_reference'] ?? null);
        $transaction = $this->payments->recordTransaction($orderId, $amountMinor, (string) $order['currency'], 'payment', [
            'payment_intent_id' => (int) $intent['id'],
            'status' => $status,
            'provider_transaction_id' => $providerResult['provider_transaction_id'] ?? null,
            'provider_payload' => $providerResult['payload'] ?? [],
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
            ], $iamUserId);
            throw new SalePaymentException('sale.payment_provider_failed');
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
        ], $iamUserId);
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
        ];
        $callback = fn(): array => $this->refundPaymentNow($tx, $amountMinor, $providerKey, $reason, $iamUserId);
        if ($this->idempotency === null) {
            return $callback();
        }
        return $this->idempotency->run((int) $tx['site_id'], 'refund.create', $idempotencyKey, $request, $callback);
    }

    /** @param array<string,mixed> $tx @return array<string,mixed> */
    private function refundPaymentNow(array $tx, int $amountMinor, string $providerKey, ?string $reason, ?int $iamUserId): array
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
        $provider = $this->providerRegistry()->get($providerKey);
        if (!$provider->supports('refund')) {
            throw new SalePaymentException('sale.payment_provider_unsupported');
        }
        $providerResult = $provider->refund([
            'order_id' => (int) $tx['order_id'],
            'transaction_id' => (int) $tx['id'],
            'amount_minor' => $amountMinor,
            'currency' => (string) $tx['currency'],
        ]);
        $status = (string) ($providerResult['status'] ?? 'succeeded');
        $refund = $this->payments->createRefund((int) $tx['order_id'], (int) $tx['id'], $amountMinor, (string) $tx['currency'], $reason, $iamUserId, $status);
        $refundTransaction = $this->payments->recordTransaction((int) $tx['order_id'], $amountMinor, (string) $tx['currency'], 'refund', [
            'payment_intent_id' => $tx['payment_intent_id'] ?? null,
            'status' => $status,
            'provider_transaction_id' => $providerResult['provider_transaction_id'] ?? null,
            'provider_payload' => $providerResult['payload'] ?? [],
            'allocate' => false,
        ]);
        if ($status !== 'succeeded') {
            throw new SalePaymentException('sale.refund_provider_failed');
        }
        $refundedTotal = (int) $tx['refunded_total_minor'] + $amountMinor;
        $order = $this->orders->updateRefundedTotal((int) $tx['order_id'], $refundedTotal);
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
        ], $iamUserId);
        return ['order' => $order, 'refund' => $refund, 'transaction' => $refundTransaction];
    }

    private function providerRegistry(): PaymentProviderRegistry
    {
        return $this->providers ?? new PaymentProviderRegistry();
    }
}
