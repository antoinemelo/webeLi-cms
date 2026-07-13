<?php

declare(strict_types=1);

namespace App\Modules\Sale\Payments;

final class ManualPaymentProvider implements PaymentProvider
{
    public function key(): string { return 'manual_card'; }
    public function supports(string $operation): bool { return in_array($operation, ['create_intent','record_payment','capture','multiple_capture','refund','void'], true); }

    public function createIntent(array $payload): array
    {
        return $this->result('session', $payload, 'requires_payment');
    }

    public function recordPayment(array $payload): array { return $this->result('confirmation', $payload, 'succeeded'); }
    public function capture(array $payload): array { return $this->result('capture', $payload, 'succeeded'); }
    public function refund(array $payload): array { return $this->result('refund', $payload, 'succeeded'); }
    public function void(array $payload): array { return $this->result('cancellation', $payload, 'cancelled'); }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function result(string $operation, array $payload, string $status): array
    {
        $reference = trim((string) ($payload['operator_reference'] ?? '')) ?: sprintf('MAN-%d-%s', (int) ($payload['order_id'] ?? 0), substr(hash('sha256', json_encode($payload) ?: '{}'), 0, 10));
        return [
            'provider_key' => $this->key(), 'status' => $status, 'provider_reference' => $reference,
            'provider_transaction_id' => $reference . '-' . strtoupper(substr($operation, 0, 3)) . '-' . substr(hash('sha256',(string)($payload['idempotency_key']??$payload['amount_minor']??0)),0,10),
            'payload' => [
                'provider' => $this->key(), 'operation' => $operation, 'external_call' => false,
                'operator_reference' => trim((string) ($payload['operator_reference'] ?? '')) ?: null,
                'comment' => trim((string) ($payload['comment'] ?? '')) ?: null,
                'proof_asset_id' => isset($payload['proof_asset_id']) ? (int) $payload['proof_asset_id'] : null,
            ],
        ];
    }
}
