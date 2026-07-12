<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Modules\Sale\Exceptions\SaleValidationException;

final class SaleOrderTimelineService
{
    private int $sequence = 0;

    public function __construct(private readonly SaleDatabaseConnection $connection) {}

    /** @return list<array<string,mixed>> */
    public function timeline(int $orderId, string $language = 'fr'): array
    {
        $this->sequence = 0;
        $db = $this->connection->database() ?? throw new SaleValidationException('sale.database_unavailable');
        $order = $db->one('SELECT * FROM sale_orders WHERE id=?', [$orderId]);
        if ($order === null) {
            throw new SaleValidationException('sale.order_not_found');
        }
        $language = strtolower($language) === 'en' ? 'en' : 'fr';
        $items = [];
        foreach ($db->all('SELECT * FROM sale_state_transitions WHERE (aggregate_type=\'order\' AND aggregate_id=?) OR (aggregate_type=\'cart\' AND aggregate_id=?) OR (aggregate_type IN (\'return\',\'refund\') AND aggregate_id IN (SELECT id FROM sale_returns WHERE order_id=? UNION SELECT id FROM sale_refunds WHERE order_id=?)) ORDER BY id', [$orderId, (int) ($order['source_cart_id'] ?? 0), $orderId, $orderId]) as $row) {
            $items[] = $this->item('transition', $this->label($language, 'transition') . ' ' . ($row['from_status'] ?? '∅') . ' → ' . $row['to_status'], $row['created_at'], $row['correlation_id'] ?? null, $row);
        }
        foreach ($db->all('SELECT id,transaction_type,status,amount_minor,currency,correlation_id,processed_at,created_at FROM sale_payment_transactions WHERE order_id=? ORDER BY id', [$orderId]) as $row) {
            $items[] = $this->item('payment', $this->label($language, 'payment') . ' ' . $row['transaction_type'] . ' ' . $row['status'], $row['processed_at'] ?? $row['created_at'], $row['correlation_id'] ?? null, $row);
        }
        foreach ($db->all('SELECT * FROM sale_financial_corrections WHERE order_id=? ORDER BY id', [$orderId]) as $row) {
            $items[] = $this->item('correction', $this->label($language, 'correction') . ' ' . $row['amount_delta_minor'] . ' ' . $row['currency'], $row['created_at'], $row['correlation_id'], $row);
        }
        foreach ($db->all('SELECT * FROM sale_returns WHERE order_id=? ORDER BY id', [$orderId]) as $row) {
            $items[] = $this->item('return', $this->label($language, 'return') . ' ' . $row['return_number'] . ' ' . $row['status'], $row['created_at'], null, $row);
        }
        foreach ($db->all('SELECT id,refund_number,status,amount_minor,currency,reason,processed_at,created_at FROM sale_refunds WHERE order_id=? ORDER BY id', [$orderId]) as $row) {
            $items[] = $this->item('refund', $this->label($language, 'refund') . ' ' . $row['refund_number'] . ' ' . $row['status'], $row['processed_at'] ?? $row['created_at'], null, $row);
        }
        foreach ($db->all(
            'SELECT m.* FROM sale_stock_movements m
             WHERE (m.reference_type=\'order\' AND m.reference_id=?)
                OR (m.reference_type=\'return\' AND m.reference_id IN (SELECT id FROM sale_returns WHERE order_id=?))
             ORDER BY m.id', [$orderId, $orderId]
        ) as $row) {
            $items[] = $this->item('stock', $this->label($language, 'stock') . ' ' . $row['movement_type'] . ' ' . $row['quantity'], $row['created_at'], null, $row);
        }
        foreach ($db->all('SELECT id,event_type,aggregate_type,aggregate_id,payload_json,correlation_id,created_at FROM sale_events WHERE (aggregate_type=\'order\' AND aggregate_id=?) OR CAST(json_extract(payload_json,\'$.order_id\') AS INTEGER)=? ORDER BY id', [$orderId, $orderId]) as $row) {
            unset($row['payload_json']);
            $items[] = $this->item('integration_event', $this->label($language, 'event') . ' ' . $row['event_type'], $row['created_at'], $row['correlation_id'] ?? null, $row);
        }
        foreach ($db->all('SELECT * FROM sale_order_customer_reconciliations WHERE order_id=? ORDER BY id', [$orderId]) as $row) {
            $items[] = $this->item('customer_reconciliation', $this->label($language, 'customer') . ' #' . ($row['contact_id'] ?? $row['company_id']), $row['created_at'], $row['correlation_id'], $row);
        }
        usort($items, static fn(array $a, array $b): int => [$a['occurred_at'], $a['sequence']] <=> [$b['occurred_at'], $b['sequence']]);
        return array_values($items);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function item(string $kind, string $label, mixed $date, ?string $correlationId, array $data): array
    {
        return ['sequence' => ++$this->sequence, 'kind' => $kind, 'label' => $label, 'occurred_at' => (string) $date, 'correlation_id' => $correlationId, 'data' => $data];
    }

    private function label(string $language, string $key): string
    {
        $labels = [
            'fr' => ['transition' => 'Transition', 'payment' => 'Paiement', 'correction' => 'Correction', 'return' => 'Retour', 'refund' => 'Remboursement', 'stock' => 'Stock', 'event' => 'Événement', 'customer' => 'Rapprochement client'],
            'en' => ['transition' => 'Transition', 'payment' => 'Payment', 'correction' => 'Correction', 'return' => 'Return', 'refund' => 'Refund', 'stock' => 'Stock', 'event' => 'Event', 'customer' => 'Customer reconciliation'],
        ];
        return $labels[$language][$key];
    }
}
