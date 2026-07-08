<?php

declare(strict_types=1);

namespace App\Modules\Sale\Repositories;

final class SalePaymentRepository extends SaleRepositoryBase
{
    /** @return list<array<string,mixed>> */
    public function methods(int $siteId): array
    {
        return $this->rawDatabase()->all(
            'SELECT * FROM sale_payment_methods WHERE site_id = ? AND archived_at IS NULL ORDER BY code ASC',
            [$siteId]
        );
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function createMethod(int $siteId, array $payload): array
    {
        $this->rawDatabase()->run(
            'INSERT INTO sale_payment_methods(site_id, channel_id, code, name, provider_key, method_type, status, config_json)
             VALUES(?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $siteId,
                $payload['channel_id'] ?? null,
                strtolower(trim((string) ($payload['code'] ?? ''))),
                trim((string) ($payload['name'] ?? '')),
                isset($payload['provider_key']) ? strtolower(trim((string) $payload['provider_key'])) : null,
                $payload['method_type'] ?? 'cash',
                $payload['status'] ?? 'active',
                $this->json($payload['config'] ?? []),
            ]
        );
        return $this->rawDatabase()->one('SELECT * FROM sale_payment_methods WHERE id = ?', [(int) $this->rawDatabase()->lastInsertId()]) ?? [];
    }

    /** @return array<string,mixed> */
    public function recordTransaction(int $orderId, int $amountMinor, string $currency, string $type = 'payment'): array
    {
        $this->rawDatabase()->run(
            'INSERT INTO sale_payment_transactions(
                order_id, transaction_type, status, amount_minor, currency, provider_payload_json, processed_at
             ) VALUES(?, ?, "succeeded", ?, ?, "{}", CURRENT_TIMESTAMP)',
            [$orderId, $type, $amountMinor, strtoupper($currency)]
        );
        $transactionId = (int) $this->rawDatabase()->lastInsertId();
        $this->rawDatabase()->run(
            'INSERT INTO sale_payment_allocations(order_id, payment_transaction_id, amount_minor, currency)
             VALUES(?, ?, ?, ?)',
            [$orderId, $transactionId, $amountMinor, strtoupper($currency)]
        );
        return $this->rawDatabase()->one('SELECT * FROM sale_payment_transactions WHERE id = ?', [$transactionId]) ?? [];
    }

    public function allocatedTotal(int $orderId): int
    {
        $row = $this->rawDatabase()->one(
            'SELECT COALESCE(SUM(amount_minor), 0) AS total FROM sale_payment_allocations WHERE order_id = ?',
            [$orderId]
        );
        return (int) ($row['total'] ?? 0);
    }

    /** @return list<array<string,mixed>> */
    public function orderPayments(int $orderId): array
    {
        return $this->rawDatabase()->all('SELECT * FROM sale_payment_transactions WHERE order_id = ? ORDER BY id ASC', [$orderId]);
    }
}
