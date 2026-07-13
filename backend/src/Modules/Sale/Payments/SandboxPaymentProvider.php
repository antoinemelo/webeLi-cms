<?php

declare(strict_types=1);

namespace App\Modules\Sale\Payments;

use App\Core\Database;
use App\Modules\Sale\Exceptions\SalePaymentException;

/** Provider local persistant destiné aux parcours sandbox et aux tests E2E. */
final class SandboxPaymentProvider implements OnlinePaymentProvider
{
    private const CONTRACT = 'sale.payment_provider.v1';

    public function __construct(
        private readonly Database $db,
        private readonly string $secret,
        private readonly int $toleranceSeconds = 300,
    ) {}

    public function contractVersion(): string { return self::CONTRACT; }
    public function key(): string { return 'sandbox'; }

    public function supports(string $operation): bool
    {
        return in_array($operation, ['create_intent','capture','refund','void','read_state','webhook'], true);
    }

    public function createIntent(array $payload): array
    {
        $intentId = (int) ($payload['intent_id'] ?? 0);
        $amount = (int) ($payload['amount_minor'] ?? 0);
        $currency = strtoupper((string) ($payload['currency'] ?? ''));
        if ($intentId < 1 || $amount < 1 || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new SalePaymentException('sale.payment_intent_invalid');
        }
        $reference = 'sbx_pi_' . bin2hex(random_bytes(12));
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->db->run(
            'INSERT INTO sale_sandbox_payment_states(provider_reference,payment_intent_id,status,amount_minor,currency,sandbox_token_hash)
             VALUES(?,?,\'requires_action\',?,?,?)',
            [$reference, $intentId, $amount, $currency, hash('sha256', $token)]
        );
        return [
            'provider_key' => $this->key(),
            'contract_version' => self::CONTRACT,
            'status' => 'requires_action',
            'provider_reference' => $reference,
            'checkout_url' => '/api/v1/sale/payments/sandbox/' . rawurlencode($reference),
            'sandbox_token' => $token,
            'payload' => ['provider' => $this->key(), 'test_mode' => true],
        ];
    }

    public function recordPayment(array $payload): array
    {
        throw new SalePaymentException('sale.payment_provider_operation_unsupported');
    }

    public function capture(array $payload): array
    {
        $state = $this->requireState((string) ($payload['provider_reference'] ?? ''));
        $amount = (int) ($payload['amount_minor'] ?? ((int) $state['amount_minor'] - (int) $state['captured_minor']));
        if ($amount < 1 || (int) $state['captured_minor'] + $amount > (int) $state['amount_minor']) {
            throw new SalePaymentException('sale.payment_capture_amount_invalid');
        }
        $captured = (int) $state['captured_minor'] + $amount;
        $status = $captured === (int) $state['amount_minor'] ? 'captured' : 'partially_captured';
        $this->db->run(
            'UPDATE sale_sandbox_payment_states SET status=?,authorized_minor=MAX(authorized_minor,?),captured_minor=?,updated_at=CURRENT_TIMESTAMP WHERE provider_reference=?',
            [$status, $captured, $captured, (string) $state['provider_reference']]
        );
        return $this->operationResult($state, $status, $amount, 'capture');
    }

    public function refund(array $payload): array
    {
        $state = $this->stateFromPayload($payload);
        $amount = (int) ($payload['amount_minor'] ?? 0);
        if ($amount < 1 || (int) $state['refunded_minor'] + $amount > (int) $state['captured_minor']) {
            throw new SalePaymentException('sale.refund_exceeds_payment');
        }
        $this->db->run(
            'UPDATE sale_sandbox_payment_states SET refunded_minor=refunded_minor+?,updated_at=CURRENT_TIMESTAMP WHERE provider_reference=?',
            [$amount, (string) $state['provider_reference']]
        );
        return $this->operationResult($state, 'succeeded', $amount, 'refund');
    }

    public function void(array $payload): array
    {
        $state = $this->stateFromPayload($payload);
        if ((int) $state['captured_minor'] > 0) {
            throw new SalePaymentException('sale.payment_intent_already_captured');
        }
        $this->db->run('UPDATE sale_sandbox_payment_states SET status=\'cancelled\',updated_at=CURRENT_TIMESTAMP WHERE provider_reference=?', [(string) $state['provider_reference']]);
        return $this->operationResult($state, 'cancelled', 0, 'void');
    }

    public function readState(array $payload): array
    {
        $state = $this->stateFromPayload($payload);
        return [
            'provider_key' => $this->key(),
            'contract_version' => self::CONTRACT,
            'provider_reference' => (string) $state['provider_reference'],
            'status' => (string) $state['status'],
            'amount_minor' => (int) $state['amount_minor'],
            'authorized_minor' => (int) $state['authorized_minor'],
            'captured_minor' => (int) $state['captured_minor'],
            'refunded_minor' => (int) $state['refunded_minor'],
            'currency' => (string) $state['currency'],
            'provider_synced_at' => (string) $state['updated_at'],
        ];
    }

    public function parseWebhook(string $rawBody, array $headers): array
    {
        $signatureHeader = trim((string) ($headers['x-sale-signature'] ?? $headers['X-Sale-Signature'] ?? ''));
        $parts = [];
        foreach (explode(',', $signatureHeader) as $part) {
            if (str_contains($part, '=')) {
                [$name, $value] = explode('=', trim($part), 2);
                $parts[$name] = $value;
            }
        }
        $timestamp = (int) ($parts['t'] ?? 0);
        $signature = strtolower((string) ($parts['v1'] ?? ''));
        if ($timestamp < 1 || abs(time() - $timestamp) > $this->toleranceSeconds || preg_match('/^[a-f0-9]{64}$/', $signature) !== 1) {
            throw new SalePaymentException('sale.payment_webhook_signature_invalid');
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $this->secret);
        if (!hash_equals($expected, $signature)) {
            throw new SalePaymentException('sale.payment_webhook_signature_invalid');
        }
        $event = json_decode($rawBody, true);
        if (!is_array($event)) {
            throw new SalePaymentException('sale.payment_webhook_payload_invalid');
        }
        foreach (['id','type','provider_reference','occurred_at'] as $required) {
            if (trim((string) ($event[$required] ?? '')) === '') {
                throw new SalePaymentException('sale.payment_webhook_payload_invalid');
            }
        }
        $occurredTimestamp = strtotime((string) $event['occurred_at']);
        if ($occurredTimestamp === false || $occurredTimestamp > time() + $this->toleranceSeconds) {
            throw new SalePaymentException('sale.payment_webhook_timestamp_invalid');
        }
        return [
            'event_id' => (string) $event['id'],
            'type' => (string) $event['type'],
            'provider_reference' => (string) $event['provider_reference'],
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', $occurredTimestamp),
            'amount_minor' => (int) ($event['amount_minor'] ?? 0),
            'currency' => strtoupper((string) ($event['currency'] ?? '')),
            'provider_transaction_id' => (string) ($event['provider_transaction_id'] ?? $event['id']),
            'data' => is_array($event['data'] ?? null) ? $event['data'] : [],
        ];
    }

    /** @return array{body:string,signature:string,event:array<string,mixed>} */
    public function simulate(string $reference, string $token, string $outcome, ?int $amountMinor = null): array
    {
        $state = $this->requireState($reference);
        if (!hash_equals((string) $state['sandbox_token_hash'], hash('sha256', trim($token)))) {
            throw new SalePaymentException('sale.payment_sandbox_token_invalid');
        }
        $outcome = strtolower(trim($outcome));
        $amount = $amountMinor ?? (int) $state['amount_minor'];
        $status = match ($outcome) {
            'success' => $amount < (int) $state['amount_minor'] ? 'partially_captured' : 'captured',
            'authorize' => 'authorized',
            'decline' => 'failed',
            'abandon' => 'cancelled',
            'timeout' => 'expired',
            default => throw new SalePaymentException('sale.payment_sandbox_outcome_invalid'),
        };
        if ($amount < 0 || $amount > (int) $state['amount_minor']) {
            throw new SalePaymentException('sale.payment_capture_amount_invalid');
        }
        $authorized = in_array($status, ['authorized','partially_captured','captured'], true) ? max((int) $state['authorized_minor'], $amount) : (int) $state['authorized_minor'];
        $captured = in_array($status, ['partially_captured','captured'], true) ? $amount : (int) $state['captured_minor'];
        $this->db->run(
            'UPDATE sale_sandbox_payment_states SET status=?,authorized_minor=?,captured_minor=?,updated_at=CURRENT_TIMESTAMP WHERE provider_reference=?',
            [$status, $authorized, $captured, $reference]
        );
        $type = match ($status) {
            'authorized' => 'payment.authorized',
            'partially_captured', 'captured' => 'payment.captured',
            'failed' => 'payment.failed',
            'cancelled' => 'payment.cancelled',
            default => 'payment.expired',
        };
        $occurredAt = gmdate('Y-m-d\TH:i:s\Z');
        $event = [
            'id' => 'sbx_evt_' . bin2hex(random_bytes(12)),
            'type' => $type,
            'provider_reference' => $reference,
            'provider_transaction_id' => 'sbx_tx_' . bin2hex(random_bytes(10)),
            'occurred_at' => $occurredAt,
            'amount_minor' => in_array($status, ['authorized','partially_captured','captured'], true) ? $amount : 0,
            'currency' => (string) $state['currency'],
            'data' => ['status' => $status, 'captured_minor' => $captured, 'authorized_minor' => $authorized, 'test_mode' => true],
        ];
        $body = self::canonicalJson($event);
        $timestamp = time();
        return ['body' => $body, 'signature' => 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $this->secret), 'event' => $event];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function stateFromPayload(array $payload): array
    {
        $reference = trim((string) ($payload['provider_reference'] ?? ''));
        if ($reference !== '') {
            return $this->requireState($reference);
        }
        $intentId = (int) ($payload['intent_id'] ?? 0);
        $state = $this->db->one('SELECT * FROM sale_sandbox_payment_states WHERE payment_intent_id=?', [$intentId]);
        if ($state === null) {
            throw new SalePaymentException('sale.payment_provider_state_not_found');
        }
        return $state;
    }

    /** @return array<string,mixed> */
    private function requireState(string $reference): array
    {
        $state = $this->db->one('SELECT * FROM sale_sandbox_payment_states WHERE provider_reference=?', [trim($reference)]);
        if ($state === null) {
            throw new SalePaymentException('sale.payment_provider_state_not_found');
        }
        return $state;
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function operationResult(array $state, string $status, int $amount, string $operation): array
    {
        $reference = (string) $state['provider_reference'];
        return [
            'provider_key' => $this->key(),
            'contract_version' => self::CONTRACT,
            'status' => $status,
            'provider_reference' => $reference,
            'provider_transaction_id' => $reference . '_' . $operation . '_' . bin2hex(random_bytes(6)),
            'amount_minor' => $amount,
            'payload' => ['provider' => $this->key(), 'operation' => $operation, 'test_mode' => true],
        ];
    }

    /** @param array<string,mixed> $payload */
    private static function canonicalJson(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
