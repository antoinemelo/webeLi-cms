<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Core\Database;
use App\Modules\Sale\Exceptions\SalePaymentException;
use App\Modules\Sale\Exceptions\SaleValidationException;

final class SaleDeferredPaymentService
{
    public function __construct(
        private readonly SaleDatabaseConnection $connection,
        private readonly SaleOnlinePaymentService $onlinePayments,
    ) {}

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function configure(int $orderId, array $options, ?int $actorId = null): array
    {
        $order = $this->requireOrder($orderId);
        if (!in_array((string) $order['status'], ['pending_payment','placed','confirmed'], true) || (string) $order['payment_status'] === 'refunded') {
            throw new SaleValidationException('sale.deferred_payment_order_not_configurable');
        }
        $mode = (string) ($options['mode'] ?? 'deferred_availability');
        if (!in_array($mode, ['deferred_availability','deposit_balance'], true)) throw new SaleValidationException('sale.deferred_payment_mode_invalid');
        $pricePolicy = (string) ($options['price_policy'] ?? 'frozen');
        if (!in_array($pricePolicy, ['frozen','recalculate_on_availability'], true)) throw new SaleValidationException('sale.deferred_payment_price_policy_invalid');
        $deposit = max(0, (int) ($options['deposit_minor'] ?? 0));
        if ($deposit >= (int) $order['grand_total_minor']) throw new SaleValidationException('sale.deferred_payment_deposit_invalid');
        if ($mode === 'deferred_availability' && $deposit > 0) throw new SaleValidationException('sale.deferred_payment_deposit_mode_required');
        $key = trim((string) ($options['idempotency_key'] ?? ''));
        if ($key === '') throw new SaleValidationException('sale.idempotency_key_required');
        $provider = strtolower(trim((string) ($options['provider_key'] ?? 'sandbox')));
        $balance = max(0, (int) $order['grand_total_minor'] - max($deposit, (int) $order['paid_total_minor']));
        $availableNow = ($options['available_now'] ?? false) === true;
        $initialStatus = !$availableNow ? 'waiting_availability'
            : ((int) $order['paid_total_minor'] >= (int) $order['grand_total_minor'] ? 'paid'
                : ((int) $order['paid_total_minor'] > 0 ? 'partially_paid' : 'payment_due'));
        $terms = [
            'mode' => $mode, 'price_policy' => $pricePolicy, 'deposit_minor' => $deposit,
            'balance_minor' => $balance,
            'expected_availability_at' => $options['expected_availability_at'] ?? null,
            'payment_window_seconds' => max(300, (int) ($options['payment_window_seconds'] ?? 604800)),
            'reminder_policy' => $options['reminder_policy'] ?? ['after_days' => [2,5]],
            'accepted_at' => $options['accepted_at'] ?? gmdate('Y-m-d H:i:s'),
        ];
        $existing = $this->db()->one('SELECT * FROM sale_order_payment_plans WHERE order_id=? OR idempotency_key=?', [$orderId, $key]);
        if ($existing !== null) {
            if ((int) $existing['order_id'] !== $orderId) throw new SaleValidationException('sale.idempotency_conflict');
            return $this->payload($existing, true);
        }
        $this->db()->run(
            "INSERT INTO sale_order_payment_plans(order_id,mode,status,price_policy,provider_key,deposit_minor,balance_minor,expected_availability_at,available_at,idempotency_key,terms_snapshot_json,metadata_json,created_by_iam_user_id) VALUES(?,?,?,?,?,?,?,?,CASE WHEN ? THEN CURRENT_TIMESTAMP ELSE NULL END,?,?,'{}',?)",
            [$orderId,$mode,$initialStatus,$pricePolicy,$provider,$deposit,$balance,$terms['expected_availability_at'],$availableNow?1:0,$key,$this->json($terms),$actorId]
        );
        return $this->payload($this->db()->one('SELECT * FROM sale_order_payment_plans WHERE order_id=?', [$orderId]) ?? [], false);
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function markAvailable(int $orderId, array $options = []): array
    {
        $plan = $this->db()->one('SELECT * FROM sale_order_payment_plans WHERE order_id=?', [$orderId]);
        if ($plan === null) throw new SaleValidationException('sale.deferred_payment_plan_not_found');
        if (in_array((string) $plan['status'], ['payment_due','partially_paid','paid'], true) && $plan['payment_intent_id'] !== null) return ['plan' => $this->payload($plan, true), 'payment' => $this->payment((int) $plan['payment_intent_id'])];
        if ((string) $plan['status'] !== 'waiting_availability') throw new SaleValidationException('sale.deferred_payment_not_waiting');
        $order = $this->requireOrder($orderId);
        if ((int) $order['paid_total_minor'] > 0) {
            // A deposit is represented by the existing immutable transaction.
            // The balance stays an explicit task until a partial-intent provider
            // contract is selected; it is never reported as fully paid.
            $status = (int) $order['paid_total_minor'] >= (int) $order['grand_total_minor'] ? 'paid' : 'partially_paid';
            $this->db()->run("UPDATE sale_order_payment_plans SET status=?,available_at=CURRENT_TIMESTAMP,payment_requested_at=CASE WHEN ?='partially_paid' THEN CURRENT_TIMESTAMP ELSE payment_requested_at END,updated_at=CURRENT_TIMESTAMP WHERE id=?", [$status,$status,(int) $plan['id']]);
            return ['plan' => $this->payload($this->db()->one('SELECT * FROM sale_order_payment_plans WHERE id=?', [(int) $plan['id']]) ?? [], false), 'payment' => null];
        }
        if ((string) $order['status'] !== 'pending_payment' || (string) $order['payment_status'] !== 'pending') throw new SalePaymentException('sale.online_payment_order_not_pending');
        $ttl = max(300, (int) ($options['ttl_seconds'] ?? 604800));
        $payment = $this->onlinePayments->createIntentForOrder($orderId, (string) $plan['provider_key'], [
            'idempotency_key' => (string) ($options['idempotency_key'] ?? ('availability-' . $orderId . '-' . $plan['id'])),
            'return_url' => $options['return_url'] ?? null, 'cancel_url' => $options['cancel_url'] ?? null,
            'language' => $options['language'] ?? 'fr', 'ttl_seconds' => $ttl,
        ]);
        $intentId = (int) ($payment['id'] ?? 0);
        $this->db()->run(
            "UPDATE sale_order_payment_plans SET status='payment_due',payment_intent_id=?,available_at=COALESCE(available_at,CURRENT_TIMESTAMP),payment_requested_at=CURRENT_TIMESTAMP,expires_at=datetime('now',?),updated_at=CURRENT_TIMESTAMP WHERE id=?",
            [$intentId, '+' . $ttl . ' seconds', (int) $plan['id']]
        );
        return ['plan' => $this->payload($this->db()->one('SELECT * FROM sale_order_payment_plans WHERE id=?', [(int) $plan['id']]) ?? [], false), 'payment' => $payment];
    }

    /** @return array<string,mixed>|null */
    public function plan(int $orderId): ?array
    {
        $row = $this->db()->one('SELECT * FROM sale_order_payment_plans WHERE order_id=?', [$orderId]);
        return $row === null ? null : $this->payload($row, false);
    }

    /** @return array<string,mixed> */
    private function requireOrder(int $id): array { return $this->db()->one('SELECT * FROM sale_orders WHERE id=?', [$id]) ?? throw new SaleValidationException('sale.order_not_found'); }
    /** @return array<string,mixed>|null */
    private function payment(int $id): ?array { return $this->db()->one('SELECT * FROM sale_payment_intents WHERE id=?', [$id]); }
    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function payload(array $row, bool $replayed): array { $row['terms'] = $this->decode((string)($row['terms_snapshot_json'] ?? '{}')); $row['metadata'] = $this->decode((string)($row['metadata_json'] ?? '{}')); $row['replayed']=$replayed; unset($row['terms_snapshot_json'],$row['metadata_json']); return $row; }
    /** @return array<string,mixed> */
    private function decode(string $json): array { $value=json_decode($json,true); return is_array($value)?$value:[]; }
    /** @param array<string,mixed> $value */
    private function json(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: '{}'; }
    private function db(): Database { return $this->connection->database() ?? throw new SaleValidationException('sale.database_unavailable'); }
}
