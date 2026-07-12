<?php

declare(strict_types=1);

namespace App\Modules\Sale\Repositories;

use App\Modules\Sale\Exceptions\SaleValidationException;

final class SaleOrderRepository extends SaleRepositoryBase
{
    /** @return array{items:list<array<string,mixed>>,limit:int,offset:int,total:int,has_more:bool} */
    public function list(int $siteId, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = ['site_id = :site_id'];
        $params = ['site_id' => $siteId];
        if (trim((string) ($filters['status'] ?? '')) !== '') {
            $where[] = 'status = :status';
            $params['status'] = trim((string) $filters['status']);
        }
        if (trim((string) ($filters['payment_status'] ?? '')) !== '') {
            $where[] = 'payment_status = :payment_status';
            $params['payment_status'] = trim((string) $filters['payment_status']);
        }
        if (trim((string) ($filters['q'] ?? '')) !== '') {
            $where[] = 'order_number LIKE :q';
            $params['q'] = '%' . trim((string) $filters['q']) . '%';
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int) ($this->rawDatabase()->one('SELECT COUNT(*) AS count FROM sale_orders WHERE ' . $sqlWhere, $params)['count'] ?? 0);
        $items = $this->rawDatabase()->all(
            'SELECT * FROM sale_orders WHERE ' . $sqlWhere . ' ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset',
            $params + ['limit' => max(1, min(100, $limit)), 'offset' => max(0, $offset)]
        );
        return ['items' => $items, 'limit' => $limit, 'offset' => $offset, 'total' => $total, 'has_more' => ($offset + $limit) < $total];
    }

    /** @param array<string,mixed> $cart @param list<array<string,mixed>> $lines @param list<array<string,mixed>> $adjustments @return array<string,mixed> */
    public function createFromCart(array $cart, array $lines, string $source = 'admin', array $adjustments = [], ?string $correlationId = null): array
    {
        $existing = $this->rawDatabase()->one('SELECT id FROM sale_orders WHERE source_cart_id = ? LIMIT 1', [(int) $cart['id']]);
        if ($existing !== null) {
            throw new SaleValidationException('sale.cart_already_converted');
        }
        $correlationId = \App\Modules\Sale\Services\SaleStateMachineService::correlationId($correlationId);
        $prefix = $source === 'pos' ? 'POS' : 'SALE';
        $orderNumber = $prefix . '-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
        $this->rawDatabase()->run(
            'INSERT INTO sale_orders(
                site_id, channel_id, order_number, source, status, payment_status, currency, source_cart_id, correlation_id,
                customer_company_id, customer_contact_id, customer_snapshot_json,
                billing_address_json, shipping_address_json, shipping_method_snapshot_json, subtotal_minor, discount_total_minor,
                tax_total_minor, grand_total_minor, placed_at, created_by_iam_user_id, metadata_json
             ) VALUES(?, ?, ?, ?, \'placed\', \'unpaid\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?, ?)',
            [
                (int) $cart['site_id'],
                (int) $cart['channel_id'],
                $orderNumber,
                $source,
                (string) $cart['currency'],
                (int) $cart['id'],
                $correlationId,
                $cart['customer_company_id'] ?? null,
                $cart['customer_contact_id'] ?? null,
                (string) $cart['customer_snapshot_json'],
                (string) $cart['billing_address_json'],
                (string) $cart['shipping_address_json'],
                (string) ($cart['shipping_method_snapshot_json'] ?? '{}'),
                (int) $cart['subtotal_minor'],
                (int) $cart['discount_total_minor'],
                (int) $cart['tax_total_minor'],
                (int) $cart['grand_total_minor'],
                $cart['updated_by_iam_user_id'] ?? $cart['created_by_iam_user_id'] ?? null,
                $this->json(['source_cart_id' => (int) $cart['id'], 'correlation_id' => $correlationId]),
            ]
        );
        $orderId = (int) $this->rawDatabase()->lastInsertId();
        $lineNumber = 1;
        foreach ($lines as $line) {
            $this->rawDatabase()->run(
                'INSERT INTO sale_order_lines(
                    order_id, line_number, business_product_id, business_variant_id, sku, barcode,
                    product_name, variant_name, product_type, quantity, unit_price_minor,
                    regular_unit_price_minor, unit_purchase_price_minor, currency, tax_class_id,
                    tax_rate_basis_points, tax_included, line_subtotal_minor, line_discount_minor,
                    line_tax_minor, line_total_minor, snapshot_json
                 ) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $orderId,
                    $lineNumber++,
                    (int) $line['business_product_id'],
                    (int) $line['business_variant_id'],
                    $line['sku'] ?? null,
                    $line['barcode'] ?? null,
                    (string) $line['product_name'],
                    $line['variant_name'] ?? null,
                    (string) $line['product_type'],
                    (int) $line['quantity'],
                    (int) $line['unit_price_minor'],
                    (int) $line['regular_unit_price_minor'],
                    $line['unit_purchase_price_minor'] ?? null,
                    (string) $line['currency'],
                    $line['tax_class_id'] ?? null,
                    (int) $line['tax_rate_basis_points'],
                    (int) $line['tax_included'],
                    (int) $line['line_subtotal_minor'],
                    (int) $line['line_discount_minor'],
                    (int) $line['line_tax_minor'],
                    (int) $line['line_total_minor'],
                    (string) $line['metadata_json'],
                ]
            );
        }
        foreach ($adjustments as $adjustment) {
            $this->rawDatabase()->run(
                'INSERT INTO sale_order_adjustments(order_id, order_line_id, adjustment_type, source_type, source_id, label, amount_minor, currency, metadata_json)
                 VALUES(?, NULL, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $orderId,
                    (string) $adjustment['adjustment_type'],
                    (string) $adjustment['source_type'],
                    $adjustment['source_id'] ?? null,
                    (string) $adjustment['label'],
                    (int) $adjustment['amount_minor'],
                    (string) $adjustment['currency'],
                    (string) $adjustment['metadata_json'],
                ]
            );
        }
        $this->rawDatabase()->run(
            'INSERT INTO sale_order_status_history(order_id, from_status, to_status, changed_by_iam_user_id, reason, correlation_id)
             VALUES(?, NULL, \'placed\', ?, \'checkout\', ?)',
            [$orderId, $cart['updated_by_iam_user_id'] ?? $cart['created_by_iam_user_id'] ?? null, $correlationId]
        );
        return $this->requireOrder($orderId);
    }

    /** @return array<string,mixed> */
    public function requireOrder(int $orderId): array
    {
        $row = $this->rawDatabase()->one('SELECT * FROM sale_orders WHERE id = ? LIMIT 1', [$orderId]);
        if ($row === null) {
            throw new SaleValidationException('sale.order_not_found');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    public function orderWithLines(int $orderId): array
    {
        $order = $this->requireOrder($orderId);
        $order['lines'] = $this->rawDatabase()->all('SELECT * FROM sale_order_lines WHERE order_id = ? ORDER BY line_number ASC', [$orderId]);
        return $order;
    }

    public function events(int $orderId): array
    {
        return $this->rawDatabase()->all('SELECT * FROM sale_events WHERE aggregate_type = \'order\' AND aggregate_id = ? ORDER BY id ASC', [$orderId]);
    }

    public function updatePaidTotal(int $orderId, int $paidTotalMinor): array
    {
        $order = $this->requireOrder($orderId);
        $grandTotal = (int) $order['grand_total_minor'];
        $status = $paidTotalMinor <= 0 ? 'unpaid' : ($paidTotalMinor >= $grandTotal ? 'paid' : 'partially_paid');
        $this->rawDatabase()->run(
            'UPDATE sale_orders SET paid_total_minor = ?, payment_status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
            [$paidTotalMinor, $status, $orderId]
        );
        return $this->requireOrder($orderId);
    }

    public function updateRefundedTotal(int $orderId, int $refundedTotalMinor): array
    {
        $order = $this->requireOrder($orderId);
        $paidTotal = (int) $order['paid_total_minor'];
        $refundedTotalMinor = max(0, min($refundedTotalMinor, $paidTotal));
        $status = (string) $order['payment_status'];
        if ($refundedTotalMinor > 0) {
            $status = $refundedTotalMinor >= $paidTotal ? 'refunded' : 'partially_refunded';
        }
        $this->rawDatabase()->run(
            'UPDATE sale_orders SET refunded_total_minor = ?, payment_status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
            [$refundedTotalMinor, $status, $orderId]
        );
        return $this->requireOrder($orderId);
    }

    public function cancel(int $orderId, ?int $iamUserId = null, ?string $reason = null): array
    {
        $order = $this->requireOrder($orderId);
        if (!in_array((string) $order['status'], ['placed', 'confirmed'], true)) {
            throw new SaleValidationException('sale.order_status_not_cancellable');
        }
        $this->rawDatabase()->run(
            'UPDATE sale_orders SET status = \'cancelled\', cancelled_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
            [$orderId]
        );
        $this->rawDatabase()->run(
            'INSERT INTO sale_order_status_history(order_id, from_status, to_status, changed_by_iam_user_id, reason)
             VALUES(?, ?, \'cancelled\', ?, ?)',
            [$orderId, (string) $order['status'], $iamUserId, $reason]
        );
        return $this->requireOrder($orderId);
    }
}
