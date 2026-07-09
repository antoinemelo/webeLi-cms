<?php

declare(strict_types=1);

namespace App\Modules\Sale\Payments;

final class LocalPaymentProvider implements PaymentProvider
{
    /** @param list<string> $operations */
    public function __construct(private readonly string $key, private readonly array $operations = ['create_intent', 'record_payment', 'capture', 'refund', 'void']) {}

    public function key(): string
    {
        return $this->key;
    }

    public function supports(string $operation): bool
    {
        return in_array($operation, $this->operations, true);
    }

    public function createIntent(array $payload): array
    {
        return $this->result('intent', $payload, 'requires_payment');
    }

    public function recordPayment(array $payload): array
    {
        return $this->result('payment', $payload, 'succeeded');
    }

    public function capture(array $payload): array
    {
        return $this->result('capture', $payload, 'succeeded');
    }

    public function refund(array $payload): array
    {
        return $this->result('refund', $payload, 'succeeded');
    }

    public function void(array $payload): array
    {
        return $this->result('void', $payload, 'cancelled');
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function result(string $operation, array $payload, string $status): array
    {
        $reference = sprintf(
            '%s-%s-%s-%s',
            $this->key,
            $operation,
            (string) ($payload['order_id'] ?? $payload['transaction_id'] ?? '0'),
            substr(hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'), 0, 12)
        );

        return [
            'provider_key' => $this->key,
            'status' => $status,
            'provider_reference' => $reference,
            'provider_transaction_id' => $reference,
            'payload' => [
                'provider' => $this->key,
                'operation' => $operation,
                'external_call' => false,
                'test_mode' => $this->key === 'test',
            ],
        ];
    }
}
