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

    /** @param array<string,mixed> $filters @return list<array<string,mixed>> */
    public function paymentSessions(int $siteId, array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $where = ['i.site_id=?'];
        $params = [$siteId];
        foreach (['status' => 'i.status', 'provider' => 'i.provider_key'] as $filter => $column) {
            $value = strtolower(trim((string) ($filters[$filter] ?? '')));
            if ($value !== '') { $where[] = $column . '=?'; $params[] = $value; }
        }
        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $where[] = '(o.order_number LIKE ? OR i.intent_reference LIKE ? OR o.customer_snapshot_json LIKE ?)';
            $needle = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';
            array_push($params, $needle, $needle, $needle);
        }
        $params[] = max(1, min(500, $limit));
        $params[] = max(0, $offset);
        $orderBy = (string) ($filters['sort'] ?? '') === 'oldest' ? 'i.created_at ASC,i.id ASC' : 'i.id DESC';
        return $this->rawDatabase()->all(
            'SELECT i.*,o.order_number,o.payment_status AS order_payment_status,o.status AS order_status,
                    o.customer_snapshot_json,o.grand_total_minor,
                    COALESCE((SELECT SUM(t.amount_minor) FROM sale_payment_transactions t WHERE t.payment_intent_id=i.id AND t.transaction_type IN (\'payment\',\'capture\') AND t.status=\'succeeded\'),0) AS settled_minor,
                    COALESCE((SELECT SUM(r.amount_minor) FROM sale_refunds r JOIN sale_payment_transactions rt ON rt.id=r.payment_transaction_id WHERE rt.payment_intent_id=i.id AND r.status IN (\'pending\',\'succeeded\')),0) AS refund_total_minor
             FROM sale_payment_intents i JOIN sale_orders o ON o.id=i.order_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $orderBy . ' LIMIT ? OFFSET ?',
            $params
        );
    }

    /** @return array<string,mixed> */
    public function paymentSessionDetail(int $siteId, int $intentId): array
    {
        $session = $this->rawDatabase()->one(
            'SELECT i.*,o.order_number,o.status AS order_status,o.payment_status AS order_payment_status,o.customer_snapshot_json,o.grand_total_minor
             FROM sale_payment_intents i JOIN sale_orders o ON o.id=i.order_id WHERE i.site_id=? AND i.id=?',
            [$siteId, $intentId]
        );
        if ($session === null) { throw new SalePaymentException('sale.payment_intent_not_found'); }
        $session['attempts'] = $this->rawDatabase()->all('SELECT * FROM sale_payment_attempts WHERE payment_intent_id=? ORDER BY attempt_number', [$intentId]);
        $session['transactions'] = $this->rawDatabase()->all('SELECT * FROM sale_payment_transactions WHERE payment_intent_id=? ORDER BY id', [$intentId]);
        $session['refunds'] = $this->rawDatabase()->all('SELECT r.* FROM sale_refunds r JOIN sale_payment_transactions t ON t.id=r.payment_transaction_id WHERE t.payment_intent_id=? ORDER BY r.id', [$intentId]);
        $session['provider_events'] = $this->rawDatabase()->all('SELECT * FROM sale_payment_webhook_events WHERE provider_key=? AND provider_reference=? ORDER BY provider_occurred_at,id', [(string) $session['provider_key'], (string) ($session['intent_reference'] ?? '')]);
        $session['reconciliations'] = $this->rawDatabase()->all('SELECT * FROM sale_payment_reconciliation_runs WHERE payment_intent_id=? ORDER BY id', [$intentId]);
        return $session;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function createMethod(int $siteId, array $payload): array
    {
        $this->rawDatabase()->run(
            'INSERT INTO sale_payment_methods(site_id,channel_id,code,name,label_fr,label_en,description_fr,description_en,provider_key,method_type,status,is_public,currency,min_amount_minor,max_amount_minor,sort_order,config_json)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $siteId,
                $payload['channel_id'] ?? null,
                strtolower(trim((string) ($payload['code'] ?? ''))),
                trim((string) ($payload['name'] ?? '')),
                isset($payload['label_fr']) ? trim((string) $payload['label_fr']) : null,
                isset($payload['label_en']) ? trim((string) $payload['label_en']) : null,
                isset($payload['description_fr']) ? trim((string) $payload['description_fr']) : null,
                isset($payload['description_en']) ? trim((string) $payload['description_en']) : null,
                isset($payload['provider_key']) ? strtolower(trim((string) $payload['provider_key'])) : null,
                $payload['method_type'] ?? 'cash',
                $payload['status'] ?? 'active',
                ($payload['is_public'] ?? false) === true ? 1 : 0,
                isset($payload['currency']) ? strtoupper(trim((string) $payload['currency'])) : null,
                isset($payload['min_amount_minor']) ? (int) $payload['min_amount_minor'] : null,
                isset($payload['max_amount_minor']) ? (int) $payload['max_amount_minor'] : null,
                max(0, (int) ($payload['sort_order'] ?? 100)),
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
             VALUES(?, ?, ?, ?, ?, \'requires_payment\', ?, ?, ?, ?)',
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

    public function setIntentReference(int $intentId, ?string $reference): void
    {
        if ($reference !== null && trim($reference) !== '') {
            $this->rawDatabase()->run('UPDATE sale_payment_intents SET intent_reference = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?', [$reference, $intentId]);
        }
    }

    /** @return array<string,mixed> */
    public function requireIntentWithOrder(int $intentId): array
    {
        $row = $this->rawDatabase()->one(
            'SELECT i.*,o.site_id AS order_site_id,o.currency AS order_currency FROM sale_payment_intents i INNER JOIN sale_orders o ON o.id=i.order_id WHERE i.id=?',
            [$intentId]
        );
        if ($row === null) {
            throw new SalePaymentException('sale.payment_intent_not_found');
        }
        return $row;
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function recordTransaction(int $orderId, int $amountMinor, string $currency, string $type = 'payment', array $options = []): array
    {
        $status = (string) ($options['status'] ?? 'succeeded');
        $providerPayload = is_array($options['provider_payload'] ?? null) ? $options['provider_payload'] : [];
        $this->rawDatabase()->run(
            'INSERT INTO sale_payment_transactions(
                payment_intent_id, order_id, transaction_type, status, amount_minor, currency,
                provider_transaction_id, provider_payload_json, error_code, error_message, correlation_id, created_by_iam_user_id, processed_at
             ) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
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
                $options['correlation_id'] ?? null,
                $options['created_by_iam_user_id'] ?? null,
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
            'SELECT
                COALESCE((SELECT SUM(amount_minor) FROM sale_payment_allocations WHERE order_id = ?), 0)
                + COALESCE((SELECT SUM(amount_delta_minor) FROM sale_financial_corrections WHERE order_id = ?), 0) AS total',
            [$orderId, $orderId]
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
             WHERE payment_transaction_id = ? AND status IN (\'pending\',\'succeeded\')',
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
