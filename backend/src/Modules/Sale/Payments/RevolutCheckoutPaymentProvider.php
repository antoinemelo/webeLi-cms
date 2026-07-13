<?php

declare(strict_types=1);

namespace App\Modules\Sale\Payments;

use App\Modules\Sale\Exceptions\SalePaymentException;

/** Hosted Revolut Checkout, sans donnée de paiement dans le CMS. */
final class RevolutCheckoutPaymentProvider implements OnlinePaymentProvider
{
    private ?array $verifiedEvent = null;

    /** @param list<string> $webhookSecrets */
    public function __construct(
        private readonly RevolutGateway $gateway,
        private readonly array $webhookSecrets,
        private readonly string $publicBaseUrl,
        private readonly string $environment = 'sandbox',
        private readonly int $signatureTolerance = 300,
    ) {}

    public function key(): string { return 'revolut_checkout'; }
    public function contractVersion(): string { return PaymentProviderContractV1::VERSION; }
    public function supports(string $operation): bool
    {
        return in_array($operation, ['create_intent','void','read_state','webhook'], true);
    }

    public function createIntent(array $payload): array
    {
        $orderId = (int) ($payload['order_id'] ?? 0);
        $intentId = (int) ($payload['intent_id'] ?? 0);
        $amount = (int) ($payload['amount_minor'] ?? 0);
        $currency = strtoupper((string) ($payload['currency'] ?? ''));
        if ($orderId < 1 || $intentId < 1 || $amount < 1 || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new SalePaymentException('sale.payment_provider_payload_invalid');
        }
        $base = rtrim($this->publicBaseUrl, '/');
        if ($base === '') throw new SalePaymentException('sale.payment_provider_public_url_missing');
        $order = $this->gateway->createOrder([
            'amount' => $amount,
            'currency' => $currency,
            'capture_mode' => 'automatic',
            'description' => 'Commande ' . $orderId,
            'expire_pending_after' => 'PT30M',
            'merchant_order_data' => ['reference' => 'cms-payment-intent-' . $intentId],
        ], (string) ($payload['idempotency_key'] ?? 'cms-payment-' . $intentId));
        $reference = (string) ($order['id'] ?? '');
        $checkoutUrl = (string) ($order['checkout_url'] ?? '');
        if (preg_match('/^[a-f0-9-]{32,40}$/i', $reference) !== 1 || !str_starts_with($checkoutUrl, 'https://')) {
            throw new SalePaymentException('sale.payment_provider_payload_invalid');
        }
        $redirect = $base . '/checkout/confirmation?provider=revolut_checkout&reference=' . rawurlencode($reference);
        $this->gateway->updateOrder($reference, ['redirect_url' => $redirect]);
        return [
            'provider_key' => $this->key(),
            'status' => $this->status($order),
            'provider_reference' => $reference,
            'checkout_url' => $checkoutUrl,
            'payload' => ['provider' => $this->key(), 'environment' => $this->environment],
        ];
    }

    public function recordPayment(array $payload): array
    {
        throw new SalePaymentException('sale.payment_provider_operation_unsupported');
    }

    public function capture(array $payload): array
    {
        throw new SalePaymentException('sale.payment_provider_operation_unsupported');
    }

    public function refund(array $payload): array
    {
        throw new SalePaymentException('sale.payment_provider_operation_unsupported');
    }

    public function void(array $payload): array
    {
        $reference = $this->reference($payload);
        $result = $this->gateway->cancelOrder($reference);
        return $this->operationResult('cancel', $reference, $result);
    }

    public function readState(array $payload): array
    {
        $order = $this->gateway->retrieveOrder($this->reference($payload));
        $amount = (int) ($order['amount'] ?? 0);
        $status = $this->status($order);
        return [
            'provider_key' => $this->key(), 'contract_version' => $this->contractVersion(),
            'provider_reference' => (string) ($order['id'] ?? ''), 'status' => $status,
            'amount_minor' => $amount, 'authorized_minor' => in_array($status, ['authorized','captured'], true) ? $amount : 0,
            'captured_minor' => $status === 'captured' ? $amount : 0,
            'refunded_minor' => (int) ($order['refunded_amount'] ?? 0),
            'currency' => strtoupper((string) ($order['currency'] ?? '')),
            'provider_synced_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    public function verifyWebhookSignature(string $rawBody, array $headers): void
    {
        $signatureHeader = trim((string) ($headers['revolut-signature'] ?? ''));
        $timestamp = trim((string) ($headers['revolut-request-timestamp'] ?? ''));
        if ($signatureHeader === '' || preg_match('/^\d{10,16}$/', $timestamp) !== 1) {
            throw new SalePaymentException('sale.payment_webhook_signature_invalid');
        }
        $timestampMs = (int) $timestamp;
        if ($timestampMs < 100000000000) $timestampMs *= 1000;
        if (abs((int) floor(microtime(true) * 1000) - $timestampMs) > $this->signatureTolerance * 1000) {
            throw new SalePaymentException('sale.payment_webhook_signature_invalid');
        }
        $provided = array_map('trim', explode(',', $signatureHeader));
        $message = 'v1.' . $timestamp . '.' . $rawBody;
        foreach ($this->webhookSecrets as $secret) {
            $expected = 'v1=' . hash_hmac('sha256', $message, $secret);
            foreach ($provided as $signature) {
                if (hash_equals($expected, $signature)) {
                    $event = json_decode($rawBody, true);
                    if (!is_array($event)) throw new SalePaymentException('sale.payment_webhook_payload_invalid');
                    $this->verifiedEvent = $event;
                    return;
                }
            }
        }
        throw new SalePaymentException('sale.payment_webhook_signature_invalid');
    }

    public function parseWebhook(string $rawBody, array $headers): array
    {
        $event = $this->verifiedEvent;
        $this->verifiedEvent = null;
        if (!is_array($event)) throw new SalePaymentException('sale.payment_webhook_signature_invalid');
        $eventType = strtoupper((string) ($event['event'] ?? ''));
        $reference = (string) ($event['order_id'] ?? '');
        if (preg_match('/^[a-f0-9-]{32,40}$/i', $reference) !== 1) {
            throw new SalePaymentException('sale.payment_webhook_payload_invalid');
        }
        $mapped = match ($eventType) {
            'ORDER_COMPLETED' => 'payment.captured',
            'ORDER_AUTHORISED' => 'payment.authorized',
            'ORDER_CANCELLED' => 'payment.cancelled',
            'ORDER_FAILED' => 'payment.failed',
            default => throw new SalePaymentException('sale.payment_webhook_event_unsupported'),
        };
        $order = $this->gateway->retrieveOrder($reference);
        $orderStatus = $this->status($order);
        $mapped = match ($orderStatus) {
            'captured' => 'payment.captured', 'authorized' => 'payment.authorized',
            'cancelled' => 'payment.cancelled', 'failed' => 'payment.failed', default => $mapped,
        };
        $amount = (int) ($order['amount'] ?? 0);
        $payment = is_array($order['payments'][0] ?? null) ? $order['payments'][0] : [];
        $occurredAt = (string) ($event['updated_at'] ?? $order['updated_at'] ?? gmdate('c'));
        return [
            'event_id' => (string) ($event['id'] ?? hash('sha256', $rawBody)),
            'type' => $mapped, 'provider_reference' => $reference,
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($occurredAt) ?: time()),
            'amount_minor' => in_array($mapped, ['payment.captured','payment.authorized'], true) ? $amount : 0,
            'currency' => strtoupper((string) ($order['currency'] ?? '')),
            'provider_transaction_id' => (string) ($payment['id'] ?? $reference),
            'data' => ['status' => $orderStatus, 'captured_minor' => $mapped === 'payment.captured' ? $amount : 0, 'revolut_event_type' => $eventType],
        ];
    }

    /** @param array<string,mixed> $payload */
    private function reference(array $payload): string
    {
        $reference = trim((string) ($payload['provider_reference'] ?? ''));
        if (preg_match('/^[a-f0-9-]{32,40}$/i', $reference) !== 1) {
            throw new SalePaymentException('sale.payment_provider_state_not_found');
        }
        return $reference;
    }

    /** @param array<string,mixed> $order */
    private function status(array $order): string
    {
        return match (strtolower((string) ($order['state'] ?? ''))) {
            'authorised' => 'authorized', 'completed' => 'captured', 'cancelled' => 'cancelled',
            'failed' => 'failed', 'pending', 'processing' => 'requires_action', default => 'requires_action',
        };
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function operationResult(string $operation, string $reference, array $result): array
    {
        $status = $operation === 'cancel' ? 'cancelled' : $this->status($result);
        return ['provider_key' => $this->key(), 'status' => $status, 'provider_reference' => $reference,
            'provider_transaction_id' => (string) ($result['id'] ?? $reference),
            'payload' => ['provider' => $this->key(), 'operation' => $operation, 'state' => $result['state'] ?? null]];
    }
}
