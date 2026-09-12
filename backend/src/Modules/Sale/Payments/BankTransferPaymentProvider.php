<?php

declare(strict_types=1);

namespace App\Modules\Sale\Payments;

final class BankTransferPaymentProvider implements PaymentProvider
{
    public function key(): string { return 'bank_transfer'; }
    public function supports(string $operation): bool { return in_array($operation, ['create_intent','record_payment','capture','partial_capture','multiple_capture','refund','partial_refund','void'], true); }

    public function createIntent(array $payload): array
    {
        $config = is_array($payload['provider_config'] ?? null) ? $payload['provider_config'] : [];
        $reference = 'VIR-' . (int) ($payload['order_id'] ?? 0) . '-' . strtoupper(substr(hash('sha256', (string) ($payload['idempotency_key'] ?? $payload['intent_id'] ?? '')), 0, 8));
        $beneficiary = trim((string) ($config['beneficiary'] ?? 'Marchand de démonstration'));
        $iban = trim((string) ($config['iban'] ?? 'CH00 0000 0000 0000 0000 0'));
        $delay = trim((string) ($config['expected_delay'] ?? '1–2 jours ouvrés'));
        $amount = (int) ($payload['amount_minor'] ?? 0);
        $currency = strtoupper((string) ($payload['currency'] ?? 'CHF'));
        return [
            'provider_key' => $this->key(), 'status' => 'requires_payment', 'provider_reference' => $reference,
            'instructions' => [
                'status' => 'awaiting_receipt', 'beneficiary' => $beneficiary, 'iban' => $iban,
                'amount_minor' => $amount, 'currency' => $currency, 'reference' => $reference,
                'expected_delay' => $delay,
                'printable_text' => implode("\n", [$beneficiary, $iban, number_format($amount / 100, 2, '.', '') . ' ' . $currency, $reference, $delay]),
            ],
            'payload' => ['provider' => $this->key(), 'external_call' => false, 'operation' => 'instructions'],
        ];
    }

    public function recordPayment(array $payload): array { return $this->result('reconciliation', $payload, 'succeeded'); }
    public function capture(array $payload): array { return $this->result('capture', $payload, 'succeeded'); }
    public function refund(array $payload): array { return $this->result('refund', $payload, 'succeeded'); }
    public function void(array $payload): array { return $this->result('cancellation', $payload, 'cancelled'); }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function result(string $operation, array $payload, string $status): array
    {
        $reference = trim((string) ($payload['provider_reference'] ?? $payload['operator_reference'] ?? '')) ?: 'VIR-' . (int) ($payload['order_id'] ?? 0);
        return [
            'provider_key' => $this->key(), 'status' => $status, 'provider_reference' => $reference,
            'provider_transaction_id' => $reference . '-' . strtoupper(substr(hash('sha256', $operation . '|' . (string) ($payload['idempotency_key'] ?? $payload['amount_minor'] ?? 0)), 0, 10)),
            'payload' => ['provider' => $this->key(), 'operation' => $operation, 'external_call' => false, 'operator_reference' => $payload['operator_reference'] ?? null, 'comment' => $payload['comment'] ?? null, 'proof_asset_id' => $payload['proof_asset_id'] ?? null],
        ];
    }
}
