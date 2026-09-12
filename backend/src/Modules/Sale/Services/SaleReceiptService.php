<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Modules\Sale\Repositories\SaleOrderRepository;
use App\Modules\Sale\Repositories\SalePaymentRepository;
use App\Modules\Sale\Repositories\SaleReceiptRepository;

final class SaleReceiptService
{
    public function __construct(
        private readonly SaleOrderRepository $orders,
        private readonly SalePaymentRepository $payments,
        private readonly SaleReceiptRepository $receipts
    ) {}

    /** @return array<string,mixed> */
    public function issue(int $orderId, string $language = 'fr', ?int $actorId = null): array
    {
        $language = strtolower($language) === 'en' ? 'en' : 'fr';
        $order = $this->orders->orderWithLines($orderId);
        $existing = $this->receipts->issued($orderId, $language);
        if ($existing !== null) {
            $existingSnapshot = json_decode((string) ($existing['snapshot_json'] ?? '{}'), true) ?: [];
            if ((int) ($existingSnapshot['paid_total_minor'] ?? -1) === (int) $order['paid_total_minor']
                && (int) ($existingSnapshot['refunded_total_minor'] ?? -1) === (int) $order['refunded_total_minor']) {
                return $this->payload($existing);
            }
        }
        $payments = array_values(array_filter(
            $this->payments->orderPayments($orderId),
            static fn(array $row): bool => (string) $row['status'] === 'succeeded'
                && in_array((string) $row['transaction_type'], ['payment', 'capture'], true)
        ));
        $refunds = $this->payments->rawDatabase()->all('SELECT id,refund_number,status,amount_minor,currency,reason,processed_at,created_at FROM sale_refunds WHERE order_id=? ORDER BY id ASC', [$orderId]);
        $taxes = $this->payments->rawDatabase()->all('SELECT tax_class_code,tax_rate_basis_points,taxable_amount_minor,tax_amount_minor,currency FROM sale_order_tax_lines WHERE order_id=? ORDER BY id ASC', [$orderId]);
        $snapshot = [
            'order_number' => (string) $order['order_number'],
            'placed_at' => $order['placed_at'] ?? $order['created_at'],
            'operator_iam_user_id' => $actorId ?? $order['created_by_iam_user_id'] ?? null,
            'currency' => (string) $order['currency'],
            'lines' => array_map(static fn(array $line): array => [
                'sku' => $line['sku'] ?? null,
                'label' => trim((string) $line['product_name'] . ' ' . (string) ($line['variant_name'] ?? '')),
                'quantity' => (int) $line['quantity'],
                'unit_price_minor' => (int) $line['unit_price_minor'],
                'line_tax_minor' => (int) $line['line_tax_minor'],
                'line_total_minor' => (int) $line['line_total_minor'],
            ], $order['lines']),
            'taxes' => $taxes,
            'payments' => array_map(static fn(array $tx): array => [
                'transaction_id' => (int) $tx['id'],
                'type' => (string) $tx['transaction_type'],
                'amount_minor' => (int) $tx['amount_minor'],
                'currency' => (string) $tx['currency'],
                'processed_at' => $tx['processed_at'] ?? $tx['created_at'],
            ], $payments),
            'refunds' => $refunds,
            'subtotal_minor' => (int) $order['subtotal_minor'],
            'discount_total_minor' => (int) $order['discount_total_minor'],
            'tax_total_minor' => (int) $order['tax_total_minor'],
            'grand_total_minor' => (int) $order['grand_total_minor'],
            'paid_total_minor' => (int) $order['paid_total_minor'],
            'refunded_total_minor' => (int) $order['refunded_total_minor'],
        ];
        $text = $this->text($snapshot, $language);
        $html = '<pre>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
        $number = 'REC-' . preg_replace('/[^A-Z0-9-]+/i', '-', (string) $order['order_number']) . '-' . strtoupper($language)
            . '-P' . (int) $order['paid_total_minor'] . '-R' . (int) $order['refunded_total_minor'];
        $type = (int) $order['refunded_total_minor'] > 0 ? 'credit_receipt' : ((string) $order['source'] === 'pos' ? 'pos_receipt' : 'order_confirmation');
        return $this->payload($this->receipts->issue($orderId, $number, $type, $language, $text, $html, $snapshot, $actorId));
    }

    /** @param array<string,mixed> $snapshot */
    private function text(array $snapshot, string $language): string
    {
        $labels = $language === 'en'
            ? ['title' => 'Receipt', 'date' => 'Date', 'operator' => 'Operator', 'total' => 'Total', 'paid' => 'Paid', 'refunded' => 'Refunded', 'balance' => 'Balance', 'tax' => 'Taxes included', 'payments' => 'Payments']
            : ['title' => 'Ticket de caisse', 'date' => 'Date', 'operator' => 'Opérateur', 'total' => 'Total', 'paid' => 'Payé', 'refunded' => 'Remboursé', 'balance' => 'Solde', 'tax' => 'Taxes incluses', 'payments' => 'Paiements'];
        $lines = [$labels['title'] . ' ' . $snapshot['order_number'], $labels['date'] . ': ' . $snapshot['placed_at'], $labels['operator'] . ': #' . ($snapshot['operator_iam_user_id'] ?? '-')];
        foreach ($snapshot['lines'] as $line) {
            $lines[] = $line['quantity'] . ' x ' . $line['label'] . ' = ' . $this->money((int) $line['line_total_minor'], (string) $snapshot['currency']);
        }
        $lines[] = $labels['total'] . ': ' . $this->money((int) $snapshot['grand_total_minor'], (string) $snapshot['currency']);
        $lines[] = $labels['tax'] . ': ' . $this->money((int) $snapshot['tax_total_minor'], (string) $snapshot['currency']);
        $lines[] = $labels['payments'] . ': ' . count($snapshot['payments']);
        $lines[] = $labels['paid'] . ': ' . $this->money((int) $snapshot['paid_total_minor'], (string) $snapshot['currency']);
        $lines[] = $labels['refunded'] . ': ' . $this->money((int) $snapshot['refunded_total_minor'], (string) $snapshot['currency']);
        $lines[] = $labels['balance'] . ': ' . $this->money(max(0, (int) $snapshot['grand_total_minor'] - (int) $snapshot['paid_total_minor']), (string) $snapshot['currency']);
        return implode("\n", $lines);
    }

    private function money(int $minor, string $currency): string
    {
        return number_format($minor / 100, 2, '.', '') . ' ' . $currency;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function payload(array $row): array
    {
        $row['snapshot'] = json_decode((string) ($row['snapshot_json'] ?? '{}'), true) ?: [];
        $row['printable_text'] = (string) ($row['text_snapshot'] ?? '');
        unset($row['snapshot_json']);
        return $row;
    }
}
