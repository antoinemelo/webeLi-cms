<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Core\Database;
use App\Modules\Sale\Exceptions\SaleValidationException;

final class SaleOrderDocumentService
{
    private const TYPES = ['order_confirmation','invoice','credit_note','delivery_note','pos_receipt'];

    public function __construct(private readonly SaleDatabaseConnection $connection) {}

    /** @return array<string,mixed> */
    public function issue(int $orderId, string $type, string $language = 'fr', ?int $actorId = null): array
    {
        $type = strtolower(trim($type));
        $language = strtolower($language) === 'en' ? 'en' : 'fr';
        if (!in_array($type, self::TYPES, true)) throw new SaleValidationException('sale.document_type_invalid');
        $order = $this->db()->one('SELECT * FROM sale_orders WHERE id=?', [$orderId]);
        if ($order === null) throw new SaleValidationException('sale.order_not_found');
        $this->assertIssuable($order, $type);

        $lines = $this->db()->all('SELECT line_number,sku,product_name,variant_name,quantity,unit_price_minor,line_tax_minor,line_total_minor,currency FROM sale_order_lines WHERE order_id=? ORDER BY line_number', [$orderId]);
        $fulfillments = $this->db()->all("SELECT id,fulfillment_number,fulfillment_type,status,tracking_reference,pickup_code,shipped_at,handed_over_at,delivered_at FROM sale_fulfillments WHERE order_id=? AND status IN ('shipped','handed_over','delivered','returned') ORDER BY id", [$orderId]);
        $refunds = $this->db()->all("SELECT refund_number,status,amount_minor,currency,reason,processed_at FROM sale_refunds WHERE order_id=? AND status='succeeded' ORDER BY id", [$orderId]);
        $snapshot = [
            'contract' => 'sale.order_document.v1', 'type' => $type,
            'order_number' => (string) $order['order_number'], 'source' => (string) $order['source'],
            'currency' => (string) $order['currency'], 'customer' => $this->decode((string) $order['customer_snapshot_json']),
            'billing_address' => $this->decode((string) $order['billing_address_json']), 'shipping_address' => $this->decode((string) $order['shipping_address_json']),
            'lines' => $lines, 'fulfillments' => $fulfillments, 'refunds' => $refunds,
            'subtotal_minor' => (int) $order['subtotal_minor'], 'tax_total_minor' => (int) $order['tax_total_minor'],
            'shipping_total_minor' => (int) $order['shipping_total_minor'], 'grand_total_minor' => (int) $order['grand_total_minor'],
            'paid_total_minor' => (int) $order['paid_total_minor'], 'refunded_total_minor' => (int) $order['refunded_total_minor'],
            'payment_status' => (string) $order['payment_status'], 'fulfillment_status' => (string) $order['fulfillment_status'],
        ];
        $fingerprint = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        $snapshot['fingerprint'] = $fingerprint;
        $existing = $this->db()->one(
            "SELECT * FROM sale_order_documents WHERE order_id=? AND document_type=? AND language=? AND status='issued' ORDER BY version DESC LIMIT 1",
            [$orderId, $type, $language]
        );
        if ($existing !== null && hash_equals($fingerprint, (string) (($this->decode((string) $existing['snapshot_json']))['fingerprint'] ?? ''))) return $this->payload($existing, true);

        $version = (int) ($existing['version'] ?? 0) + 1;
        $number = $this->prefix($type) . '-' . preg_replace('/[^A-Z0-9-]+/i', '-', (string) $order['order_number']) . '-' . strtoupper($language) . '-V' . $version;
        $text = $this->text($snapshot, $type, $language);
        $html = '<pre>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
        $this->db()->run(
            "INSERT INTO sale_order_documents(order_id,document_number,document_type,status,language,version,snapshot_json,text_snapshot,html_snapshot,issued_by_iam_user_id,issued_at) VALUES(?,?,?,'issued',?,?,?,?,?,?,CURRENT_TIMESTAMP)",
            [$orderId,$number,$type,$language,$version,json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',$text,$html,$actorId]
        );
        return $this->payload($this->db()->one('SELECT * FROM sale_order_documents WHERE id=?', [$this->db()->lastInsertId()]) ?? [], false);
    }

    /** @param array<string,mixed> $order */
    private function assertIssuable(array $order, string $type): void
    {
        if ((string) $order['status'] === 'draft') throw new SaleValidationException('sale.document_order_not_placed');
        if ($type === 'invoice' && (string) $order['payment_status'] !== 'paid') throw new SaleValidationException('sale.document_invoice_payment_required');
        if ($type === 'credit_note' && (int) $order['refunded_total_minor'] < 1) throw new SaleValidationException('sale.document_refund_required');
        if ($type === 'pos_receipt' && ((string) $order['source'] !== 'pos' || (string) $order['payment_status'] !== 'paid')) throw new SaleValidationException('sale.document_paid_pos_required');
        if ($type === 'delivery_note') {
            $count = (int) ($this->db()->one("SELECT COUNT(*) AS count FROM sale_fulfillments WHERE order_id=? AND status IN ('shipped','handed_over','delivered','returned')", [(int) $order['id']])['count'] ?? 0);
            if ($count < 1) throw new SaleValidationException('sale.document_fulfillment_required');
        }
    }

    /** @param array<string,mixed> $snapshot */
    private function text(array $snapshot, string $type, string $language): string
    {
        $titles = $language === 'en'
            ? ['order_confirmation'=>'Order confirmation','invoice'=>'Invoice','credit_note'=>'Credit note','delivery_note'=>'Delivery note','pos_receipt'=>'POS receipt']
            : ['order_confirmation'=>'Confirmation de commande','invoice'=>'Facture','credit_note'=>'Note de crédit','delivery_note'=>'Bon de livraison','pos_receipt'=>'Ticket POS'];
        $rows = [$titles[$type] . ' ' . $snapshot['order_number']];
        foreach ($snapshot['lines'] as $line) $rows[] = $line['quantity'] . ' × ' . trim((string) $line['product_name'] . ' ' . (string) ($line['variant_name'] ?? '')) . ' = ' . $this->money((int) $line['line_total_minor'], (string) $snapshot['currency']);
        $rows[] = ($language === 'en' ? 'Total: ' : 'Total : ') . $this->money((int) $snapshot['grand_total_minor'], (string) $snapshot['currency']);
        $rows[] = ($language === 'en' ? 'Paid: ' : 'Payé : ') . $this->money((int) $snapshot['paid_total_minor'], (string) $snapshot['currency']);
        return implode("\n", $rows);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function payload(array $row, bool $replayed): array
    {
        $row['snapshot'] = $this->decode((string) ($row['snapshot_json'] ?? '{}'));
        $row['printable_text'] = (string) ($row['text_snapshot'] ?? '');
        $row['replayed'] = $replayed;
        unset($row['snapshot_json']);
        return $row;
    }

    private function prefix(string $type): string { return match ($type) { 'invoice'=>'INV','credit_note'=>'CRN','delivery_note'=>'DLV','pos_receipt'=>'POS','order_confirmation'=>'ORD' }; }
    private function money(int $minor, string $currency): string { return number_format($minor / 100, 2, '.', '') . ' ' . $currency; }
    /** @return array<string,mixed> */
    private function decode(string $json): array { $value = json_decode($json, true); return is_array($value) ? $value : []; }
    private function db(): Database { return $this->connection->database() ?? throw new SaleValidationException('sale.database_unavailable'); }
}
