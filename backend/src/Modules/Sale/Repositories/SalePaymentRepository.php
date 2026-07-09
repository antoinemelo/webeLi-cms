<?php

declare(strict_types=1);

namespace App\Modules\Sale\Repositories;

use App\Modules\Sale\Exceptions\SalePaymentException;

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

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    public function createIntent(int $siteId, int $channelId, int $orderId, string $providerKey, int $amountMinor, string $currency, ?string $idempotencyKey = null, array $metadata = []): array
    {
        $this->rawDatabase()->run(
            'INSERT INTO sale_payment_intents(site_id, channel_id, order_id, provider_key, intent_reference, status, amount_minor, currency, idempotency_key, metadata_json)
             VALUES(?, ?, ?, ?, ?, "requires_payment", ?, ?, ?, ?)',
            [
                $siteId,
                $channelId,
                $orderId,
                strtolower($providerKey),
                null,
                $amountMinor,
                strtoupper($currency),
                $idempotencyKey,
                $this->json($metadata),
            ]
        );
        return $this->rawDatabase()->one('SELECT * FROM sale_payment_intents WHERE id = ?', [(int) $this->rawDatabase()->lastInsertId()]) ?? [];
    }

    public function updateIntent(int $intentId, string $status, ?string $reference = null): void
    {
        $this->rawDatabase()->run(
            'UPDATE sale_payment_intents SET status = ?, intent_reference = COALESCE(?, intent_reference), updated_at = CURRENT_TIMESTAMP WHERE id = ?',
            [$status, $reference, $intentId]
        );
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function recordTransaction(int $orderId, int $amountMinor, string $currency, string $type = 'payment', array $options = []): array
    {
        $status = (string) ($options['status'] ?? 'succeeded');
        $providerPayload = is_array($options['provider_payload'] ?? null) ? $options['provider_payload'] : [];
        $this->rawDatabase()->run(
            'INSERT INTO sale_payment_transactions(
                payment_intent_id, order_id, transaction_type, status, amount_minor, currency,
                provider_transaction_id, provider_payload_json, error_code, error_message, processed_at
             ) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $options['payment_intent_id'] ?? null,
                $orderId,
                $type,
                $status,
                $amountMinor,
                strtoupper($currency),
                $options['provider_transaction_id'] ?? null,
                $this->json($providerPayload),
                $options['error_code'] ?? null,
                $options['error_message'] ?? null,
                in_array($status, ['succeeded', 'failed', 'cancelled'], true) ? gmdate('Y-m-d H:i:s') : null,
            ]
        );
        $transactionId = (int) $this->rawDatabase()->lastInsertId();
        if ($status === 'succeeded' && in_array($type, ['payment', 'capture'], true) && (bool) ($options['allocate'] ?? true)) {
            $this->rawDatabase()->run(
                'INSERT INTO sale_payment_allocations(order_id, payment_transaction_id, amount_minor, currency)
                 VALUES(?, ?, ?, ?)',
                [$orderId, $transactionId, $amountMinor, strtoupper($currency)]
            );
        }
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

    /** @return array<string,mixed> */
    public function requireTransactionWithOrder(int $transactionId): array
    {
        $row = $this->rawDatabase()->one(
            'SELECT t.*, o.site_id, o.channel_id, o.grand_total_minor, o.paid_total_minor, o.refunded_total_minor
             FROM sale_payment_transactions t
             INNER JOIN sale_orders o ON o.id = t.order_id
             WHERE t.id = ? LIMIT 1',
            [$transactionId]
        );
        if ($row === null) {
            throw new SalePaymentException('sale.payment_transaction_not_found');
        }
        return $row;
    }

    public function refundedForTransaction(int $transactionId): int
    {
        $row = $this->rawDatabase()->one(
            'SELECT COALESCE(SUM(amount_minor), 0) AS total
             FROM sale_refunds
             WHERE payment_transaction_id = ? AND status IN ("pending","succeeded")',
            [$transactionId]
        );
        return (int) ($row['total'] ?? 0);
    }

    /** @return array<string,mixed> */
    public function createRefund(int $orderId, int $transactionId, int $amountMinor, string $currency, ?string $reason, ?int $iamUserId, string $status = 'succeeded'): array
    {
        $this->rawDatabase()->run(
            'INSERT INTO sale_refunds(order_id, payment_transaction_id, refund_number, status, amount_minor, currency, reason, created_by_iam_user_id, processed_at)
             VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $orderId,
                $transactionId,
                'REF-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3)),
                $status,
                $amountMinor,
                strtoupper($currency),
                $reason,
                $iamUserId,
                in_array($status, ['succeeded', 'failed', 'cancelled'], true) ? gmdate('Y-m-d H:i:s') : null,
            ]
        );
        return $this->rawDatabase()->one('SELECT * FROM sale_refunds WHERE id = ?', [(int) $this->rawDatabase()->lastInsertId()]) ?? [];
    }
}
