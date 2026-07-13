<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Core\Database;
use App\Modules\Sale\Exceptions\SaleValidationException;

final class SaleStateMachineService
{
    private const TRANSITIONS = [
        'cart' => [
            'draft' => ['active', 'cancelled'],
            'active' => ['abandoned', 'converted', 'expired', 'cancelled'],
            'abandoned' => ['active', 'cancelled'],
            'expired' => [], 'converted' => [], 'cancelled' => [],
        ],
        'order' => [
            'draft' => ['pending_payment', 'placed', 'cancelled'],
            'pending_payment' => ['placed', 'confirmed', 'cancelled'],
            'placed' => ['confirmed', 'cancelled'],
            'confirmed' => ['completed', 'cancelled'],
            'completed' => [], 'cancelled' => [],
        ],
        'payment_intent' => [
            'requires_payment' => ['requires_action', 'authorized', 'partially_captured', 'captured', 'cancelled', 'failed', 'expired'],
            'requires_action' => ['authorized', 'partially_captured', 'captured', 'cancelled', 'failed', 'expired'],
            'authorized' => ['partially_captured', 'captured', 'cancelled', 'failed', 'expired'],
            'partially_captured' => ['captured', 'cancelled', 'failed'],
            'captured' => [], 'cancelled' => [], 'failed' => [], 'expired' => [],
        ],
        'fulfillment' => [
            'pending' => ['preparing', 'cancelled'],
            'preparing' => ['partially_shipped', 'shipped', 'cancelled'],
            'partially_shipped' => ['shipped', 'cancelled'],
            'shipped' => ['delivered', 'returned'],
            'delivered' => ['returned'],
            'cancelled' => [], 'returned' => [],
        ],
        'return' => [
            'requested' => ['approved', 'rejected', 'cancelled'],
            'approved' => ['received', 'cancelled'],
            'received' => ['completed'],
            'rejected' => [], 'completed' => [], 'cancelled' => [],
        ],
        'refund' => [
            'draft' => ['pending', 'cancelled'],
            'pending' => ['succeeded', 'failed', 'cancelled'],
            'succeeded' => [], 'failed' => [], 'cancelled' => [],
        ],
    ];

    private const TABLES = [
        'cart' => ['table' => 'sale_carts', 'site' => 'site_id'],
        'order' => ['table' => 'sale_orders', 'site' => 'site_id'],
        'payment_intent' => ['table' => 'sale_payment_intents', 'site' => 'site_id'],
        'fulfillment' => ['table' => 'sale_fulfillments', 'site' => null],
        'return' => ['table' => 'sale_returns', 'site' => null],
        'refund' => ['table' => 'sale_refunds', 'site' => null],
    ];

    public function __construct(private readonly Database $db, private readonly ?SaleEventService $events = null) {}

    public static function correlationId(?string $value = null): string
    {
        $value = strtolower(trim((string) $value));
        if ($value !== '' && preg_match('/^[a-z0-9][a-z0-9._:-]{7,119}$/', $value) === 1) {
            return $value;
        }
        return bin2hex(random_bytes(16));
    }

    /** @return list<string> */
    public function allowedTransitions(string $type, string $status): array
    {
        if (!isset(self::TRANSITIONS[$type][$status])) {
            throw new SaleValidationException('sale.state.status_invalid');
        }
        return self::TRANSITIONS[$type][$status];
    }

    /** @return array<string,mixed> */
    public function transition(string $type, int $id, string $toStatus, ?int $actorId = null, ?string $reason = null, ?string $correlationId = null, ?int $expectedVersion = null): array
    {
        if (!isset(self::TABLES[$type])) {
            throw new SaleValidationException('sale.state.aggregate_type_invalid');
        }
        $correlationId = self::correlationId($correlationId);
        $result = $this->db->transaction(function () use ($type, $id, $toStatus, $actorId, $reason, $correlationId, $expectedVersion): array {
            $row = $this->requireAggregate($type, $id);
            $from = (string) $row['status'];
            if (!in_array($toStatus, self::TRANSITIONS[$type][$from] ?? [], true)) {
                throw new SaleValidationException('sale.state.transition_not_allowed.' . $type . '.' . $from . '.' . $toStatus);
            }
            if ($expectedVersion !== null && (int) $row['version'] !== $expectedVersion) {
                throw new SaleValidationException('sale.state.concurrent_modification');
            }
            $this->assertPreconditions($type, $row, $toStatus);
            $version = (int) ($row['version'] ?? 0);
            $table = self::TABLES[$type]['table'];
            $timestampSql = $this->timestampSql($type, $toStatus);
            $this->db->run(
                'UPDATE ' . $table . ' SET status = :status, version = version + 1, updated_at = CURRENT_TIMESTAMP' . $timestampSql . ' WHERE id = :id AND status = :from_status AND version = :version',
                ['status' => $toStatus, 'id' => $id, 'from_status' => $from, 'version' => $version]
            );
            $affected = (int) ($this->db->one('SELECT changes() AS count')['count'] ?? 0);
            if ($affected !== 1) {
                throw new SaleValidationException('sale.state.concurrent_modification');
            }
            $siteId = $this->siteId($type, $row);
            $this->record($siteId, $type, $id, $from, $toStatus, $correlationId, $actorId, $reason);
            if ($type === 'order') {
                $this->db->run(
                    'INSERT INTO sale_order_status_history(order_id, from_status, to_status, changed_by_iam_user_id, reason, correlation_id) VALUES(?, ?, ?, ?, ?, ?)',
                    [$id, $from, $toStatus, $actorId, $reason, $correlationId]
                );
            }
            if ($type === 'fulfillment') {
                $this->syncOrderFulfillmentStatus((int) $row['order_id'], $id, $toStatus);
            }
            $result = $this->requireAggregate($type, $id) + ['correlation_id' => $correlationId];
            if ($type === 'fulfillment' && $toStatus === 'delivered' && $this->events !== null) {
                $order = $this->db->one('SELECT site_id,order_number FROM sale_orders WHERE id=?', [(int) $result['order_id']]) ?? [];
                $this->events->emit((int) ($order['site_id'] ?? 0), 'sale.fulfillment.completed', 'fulfillment', $id, [
                    'site_id' => (int) ($order['site_id'] ?? 0), 'order_id' => (int) $result['order_id'],
                'order_number' => (string) ($order['order_number'] ?? ''), 'fulfillment_id' => $id, 'iam_user_id' => $actorId,
                ], $actorId, $correlationId);
            }
            return $result;
        });
        return $result;
    }

    public function recordInitial(int $siteId, string $type, int $id, string $status, string $correlationId, ?int $actorId = null, ?string $reason = null): void
    {
        $this->record($siteId, $type, $id, null, $status, self::correlationId($correlationId), $actorId, $reason);
    }

    /** @return array<string,mixed> */
    public function convertCart(int $cartId, int $orderId, ?int $actorId, string $correlationId): array
    {
        return $this->db->transaction(function () use ($cartId, $orderId, $actorId, $correlationId): array {
            $cart = $this->requireAggregate('cart', $cartId);
            if ((string) $cart['status'] !== 'active') {
                throw new SaleValidationException('sale.state.transition_not_allowed.cart.' . $cart['status'] . '.converted');
            }
            $this->db->run(
                'UPDATE sale_carts SET status=\'converted\', converted_order_id=?, version=version+1, updated_at=CURRENT_TIMESTAMP WHERE id=? AND status=\'active\' AND version=?',
                [$orderId, $cartId, (int) $cart['version']]
            );
            if ((int) ($this->db->one('SELECT changes() AS count')['count'] ?? 0) !== 1) {
                throw new SaleValidationException('sale.state.concurrent_modification');
            }
            $this->record((int) $cart['site_id'], 'cart', $cartId, 'active', 'converted', self::correlationId($correlationId), $actorId, 'checkout', ['order_id' => $orderId]);
            return $this->requireAggregate('cart', $cartId);
        });
    }

    /** @param list<array{order_line_id:int,quantity:int}> $lines @return array<string,mixed> */
    public function createFulfillment(int $orderId, array $lines, ?int $actorId = null, ?string $correlationId = null, ?string $trackingReference = null): array
    {
        $correlationId = self::correlationId($correlationId);
        return $this->db->transaction(function () use ($orderId, $lines, $actorId, $correlationId, $trackingReference): array {
            $order = $this->db->one('SELECT * FROM sale_orders WHERE id = ?', [$orderId]);
            if ($order === null || !in_array((string) $order['status'], ['placed', 'confirmed'], true)) {
                throw new SaleValidationException('sale.fulfillment.order_not_fulfillable');
            }
            if ($lines === []) {
                throw new SaleValidationException('sale.fulfillment.lines_required');
            }
            $number = 'FUL-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
            $this->db->run(
                'INSERT INTO sale_fulfillments(order_id, fulfillment_number, status, shipping_address_snapshot_json, shipping_method_snapshot_json, tracking_reference, correlation_id, created_by_iam_user_id)
                 VALUES(?, ?, \'pending\', ?, ?, ?, ?, ?)',
                [$orderId, $number, (string) $order['shipping_address_json'], (string) $order['shipping_method_snapshot_json'], $trackingReference, $correlationId, $actorId]
            );
            $id = (int) $this->db->lastInsertId();
            foreach ($lines as $line) {
                $orderLine = $this->db->one('SELECT id, quantity, fulfilled_quantity FROM sale_order_lines WHERE id = ? AND order_id = ?', [(int) $line['order_line_id'], $orderId]);
                $quantity = (int) $line['quantity'];
                if ($orderLine === null || $quantity < 1 || (int) $orderLine['fulfilled_quantity'] + $quantity > (int) $orderLine['quantity']) {
                    throw new SaleValidationException('sale.fulfillment.quantity_invalid');
                }
                $this->db->run('INSERT INTO sale_fulfillment_lines(fulfillment_id, order_line_id, quantity) VALUES(?, ?, ?)', [$id, (int) $orderLine['id'], $quantity]);
            }
            $this->db->run('UPDATE sale_orders SET fulfillment_status = \'unfulfilled\', updated_at = CURRENT_TIMESTAMP WHERE id = ?', [$orderId]);
            $this->record((int) $order['site_id'], 'fulfillment', $id, null, 'pending', $correlationId, $actorId, 'created');
            return $this->requireAggregate('fulfillment', $id);
        });
    }

    /** @return list<array<string,mixed>> */
    public function history(string $type, int $id): array
    {
        return $this->db->all('SELECT * FROM sale_state_transitions WHERE aggregate_type = ? AND aggregate_id = ? ORDER BY id ASC', [$type, $id]);
    }

    /** @return array<string,mixed> */
    private function requireAggregate(string $type, int $id): array
    {
        $row = $this->db->one('SELECT * FROM ' . self::TABLES[$type]['table'] . ' WHERE id = ? LIMIT 1', [$id]);
        if ($row === null) {
            throw new SaleValidationException('sale.state.aggregate_not_found');
        }
        return $row;
    }

    /** @param array<string,mixed> $row */
    private function siteId(string $type, array $row): int
    {
        if (self::TABLES[$type]['site'] !== null) {
            return (int) $row['site_id'];
        }
        $orderId = (int) $row['order_id'];
        return (int) ($this->db->one('SELECT site_id FROM sale_orders WHERE id = ?', [$orderId])['site_id'] ?? 0);
    }

    /** @param array<string,mixed> $row */
    private function assertPreconditions(string $type, array $row, string $toStatus): void
    {
        if ($type === 'order' && $toStatus === 'completed') {
            if (!in_array((string) $row['payment_status'], ['paid', 'refunded'], true)) {
                throw new SaleValidationException('sale.order_completion_requires_payment');
            }
            if (!in_array((string) $row['fulfillment_status'], ['not_required', 'fulfilled', 'returned'], true)) {
                throw new SaleValidationException('sale.order_completion_requires_fulfillment');
            }
        }
        if ($type === 'order' && $toStatus === 'cancelled' && (int) $row['paid_total_minor'] > (int) $row['refunded_total_minor']) {
            throw new SaleValidationException('sale.order_paid_requires_refund');
        }
    }

    private function timestampSql(string $type, string $status): string
    {
        return match ([$type, $status]) {
            ['order', 'completed'] => ', completed_at = CURRENT_TIMESTAMP',
            ['order', 'cancelled'] => ', cancelled_at = CURRENT_TIMESTAMP',
            ['fulfillment', 'shipped'] => ', shipped_at = CURRENT_TIMESTAMP',
            ['fulfillment', 'delivered'] => ', delivered_at = CURRENT_TIMESTAMP',
            ['fulfillment', 'cancelled'] => ', cancelled_at = CURRENT_TIMESTAMP',
            ['return', 'completed'] => ', completed_at = CURRENT_TIMESTAMP',
            ['refund', 'succeeded'], ['refund', 'failed'], ['refund', 'cancelled'] => ', processed_at = CURRENT_TIMESTAMP',
            default => '',
        };
    }

    private function syncOrderFulfillmentStatus(int $orderId, int $fulfillmentId, string $status): void
    {
        if ($status === 'shipped') {
            foreach ($this->db->all('SELECT order_line_id, quantity FROM sale_fulfillment_lines WHERE fulfillment_id = ?', [$fulfillmentId]) as $line) {
                $this->db->run(
                    'UPDATE sale_order_lines SET fulfilled_quantity = fulfilled_quantity + ? WHERE id = ? AND fulfilled_quantity + ? <= quantity',
                    [(int) $line['quantity'], (int) $line['order_line_id'], (int) $line['quantity']]
                );
                if ((int) ($this->db->one('SELECT changes() AS count')['count'] ?? 0) !== 1) {
                    throw new SaleValidationException('sale.fulfillment.concurrent_quantity_conflict');
                }
            }
        }
        $orderStatus = match ($status) {
            'partially_shipped' => 'partially_fulfilled',
            'shipped', 'delivered' => 'fulfilled',
            'returned' => 'returned',
            default => 'unfulfilled',
        };
        $this->db->run('UPDATE sale_orders SET fulfillment_status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?', [$orderStatus, $orderId]);
    }

    /** @param array<string,mixed> $metadata */
    private function record(int $siteId, string $type, int $id, ?string $from, string $to, string $correlationId, ?int $actorId, ?string $reason, array $metadata = []): void
    {
        $this->db->run(
            'INSERT INTO sale_state_transitions(site_id, aggregate_type, aggregate_id, from_status, to_status, correlation_id, changed_by_iam_user_id, reason, metadata_json)
             VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$siteId, $type, $id, $from, $to, $correlationId, $actorId, $reason, json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}']
        );
    }
}
