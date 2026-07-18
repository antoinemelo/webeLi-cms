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
    public function policy(int $siteId): array
    {
        $row = $this->db()->one('SELECT * FROM sale_document_policies WHERE site_id=?', [$siteId]);
        if ($row === null) {
            $this->db()->run("INSERT INTO sale_document_policies(site_id) VALUES(?)", [$siteId]);
            $row = $this->db()->one('SELECT * FROM sale_document_policies WHERE site_id=?', [$siteId]) ?? [];
        }
        $row['seller_snapshot'] = $this->decode((string)($row['seller_snapshot_json'] ?? '{}'));
        $row['seller_configured'] = trim((string)($row['seller_snapshot']['name'] ?? '')) !== '';
        $row['compliance_warning'] = $row['seller_configured']
            ? null
            : 'sale.document_policy_seller_unconfigured';
        unset($row['seller_snapshot_json']);
        return $row;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function updatePolicy(int $siteId, array $payload, ?int $actorId): array
    {
        $trigger = (string)($payload['invoice_trigger'] ?? 'paid');
        $giftCard = (string)($payload['gift_card_policy'] ?? 'unconfigured');
        if (!in_array($trigger, ['paid','validated'], true)) throw new SaleValidationException('sale.document_policy_trigger_invalid');
        if (!in_array($giftCard, ['unconfigured','sale','redemption'], true)) throw new SaleValidationException('sale.document_policy_gift_card_invalid');
        $series = static fn(mixed $value, string $fallback): string => preg_match('/^[A-Z0-9-]{1,16}$/', strtoupper(trim((string)$value))) === 1 ? strtoupper(trim((string)$value)) : $fallback;
        $seller = is_array($payload['seller_snapshot'] ?? null) ? $payload['seller_snapshot'] : [];
        $this->policy($siteId);
        $this->db()->run(
            'UPDATE sale_document_policies SET invoice_trigger=?,invoice_series=?,credit_note_series=?,seller_snapshot_json=?,gift_card_policy=?,legal_notice=?,updated_by_iam_user_id=?,updated_at=CURRENT_TIMESTAMP WHERE site_id=?',
            [$trigger,$series($payload['invoice_series'] ?? null,'INV'),$series($payload['credit_note_series'] ?? null,'CRN'),json_encode($seller, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: '{}',$giftCard,trim((string)($payload['legal_notice'] ?? '')) ?: null,$actorId,$siteId]
        );
        return $this->policy($siteId);
    }

    /** @return array<string,mixed> */
    public function issue(int $orderId, string $type, string $language = 'fr', ?int $actorId = null, array $context = []): array
    {
        $type = strtolower(trim($type));
        $language = str_starts_with(strtolower($language), 'en') ? 'en' : 'fr';
        if (!in_array($type, self::TYPES, true)) throw new SaleValidationException('sale.document_type_invalid');
        $order = $this->db()->one('SELECT * FROM sale_orders WHERE id=?', [$orderId]);
        if ($order === null) throw new SaleValidationException('sale.order_not_found');
        $policy = $this->policy((int)$order['site_id']);
        $this->assertIssuable($order, $type, $policy, $context);

        $lines = $this->db()->all('SELECT line_number,sku,product_name,variant_name,quantity,unit_price_minor,line_tax_minor,line_total_minor,currency FROM sale_order_lines WHERE order_id=? ORDER BY line_number', [$orderId]);
        $adjustments = $this->db()->all('SELECT adjustment_type,source_type,label,amount_minor,currency FROM sale_order_adjustments WHERE order_id=? ORDER BY id', [$orderId]);
        $fulfillments = $this->db()->all("SELECT id,fulfillment_number,fulfillment_type,status,tracking_reference,pickup_code,shipped_at,handed_over_at,delivered_at FROM sale_fulfillments WHERE order_id=? AND status IN ('shipped','handed_over','delivered','returned') ORDER BY id", [$orderId]);
        $refunds = $this->db()->all("SELECT refund_number,status,amount_minor,currency,reason,processed_at FROM sale_refunds WHERE order_id=? AND status='succeeded' ORDER BY id", [$orderId]);
        $taxLines = $this->db()->all('SELECT tax_class_code,tax_rate_basis_points,taxable_amount_minor,tax_amount_minor,currency FROM sale_order_tax_lines WHERE order_id=? ORDER BY id', [$orderId]);
        $snapshot = [
            'contract' => 'sale.order_document.v1', 'type' => $type,
            'order_number' => (string) $order['order_number'], 'source' => (string) $order['source'],
            'currency' => (string) $order['currency'], 'customer' => $this->decode((string) $order['customer_snapshot_json']),
            'billing_address' => $this->decode((string) $order['billing_address_json']), 'shipping_address' => $this->decode((string) $order['shipping_address_json']),
            'shipping_method' => $this->decode((string) $order['shipping_method_snapshot_json']),
            'payment_method' => $this->safePaymentMethod($this->decode((string) $order['payment_method_snapshot_json'])),
            'seller' => $policy['seller_snapshot'],
            'seller_configuration_validated' => (bool)$policy['seller_configured'],
            'gift_card_accounting_policy' => (string)$policy['gift_card_policy'],
            'legal_notice' => $policy['legal_notice'] ?? null,
            'lines' => $lines, 'tax_lines' => $taxLines, 'adjustments' => $adjustments, 'fulfillments' => $fulfillments, 'refunds' => $refunds,
            'subtotal_minor' => (int) $order['subtotal_minor'], 'tax_total_minor' => (int) $order['tax_total_minor'],
            'discount_total_minor' => (int) $order['discount_total_minor'],
            'shipping_total_minor' => (int) $order['shipping_total_minor'], 'grand_total_minor' => (int) $order['grand_total_minor'],
            'paid_total_minor' => (int) $order['paid_total_minor'], 'refunded_total_minor' => (int) $order['refunded_total_minor'],
            'payment_status' => (string) $order['payment_status'], 'fulfillment_status' => (string) $order['fulfillment_status'],
            'placed_at' => $order['placed_at'] ?? $order['created_at'],
        ];
        if ($type === 'order_confirmation') {
            $snapshot['terms'] = $this->confirmationTerms($context, $language);
            // Une confirmation décrit l'accord conclu au placement. Les états
            // financiers et logistiques ultérieurs ne doivent pas créer une V2.
            unset($snapshot['paid_total_minor'], $snapshot['refunded_total_minor'], $snapshot['payment_status'], $snapshot['fulfillment_status'], $snapshot['fulfillments'], $snapshot['refunds']);
        }
        $fingerprint = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        $snapshot['fingerprint'] = $fingerprint;
        $existing = $this->db()->one(
            "SELECT * FROM sale_order_documents WHERE order_id=? AND document_type=? AND language=? AND status='issued' ORDER BY version DESC LIMIT 1",
            [$orderId, $type, $language]
        );
        if ($existing !== null && hash_equals($fingerprint, (string) (($this->decode((string) $existing['snapshot_json']))['fingerprint'] ?? ''))) return $this->payload($existing, true);

        $version = (int) ($existing['version'] ?? 0) + 1;
        [$number,$seriesCode,$sequenceNumber] = $this->allocateNumber((int)$order['site_id'], $type, $policy);
        $snapshot['document_number'] = $number;
        $snapshot['series_code'] = $seriesCode;
        $snapshot['sequence_number'] = $sequenceNumber;
        $snapshot['issue_date'] = gmdate('Y-m-d');
        $snapshot['language'] = $language;
        $documentHash = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        $text = $this->text($snapshot, $type, $language);
        $html = '<article class="sale-order-document"><pre>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre></article>';
        $this->db()->run(
            "INSERT INTO sale_order_documents(order_id,site_id,document_number,document_type,status,language,version,series_code,sequence_number,document_hash,policy_snapshot_json,snapshot_json,text_snapshot,html_snapshot,issued_by_iam_user_id,issued_at) VALUES(?,?,?,?, 'issued',?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)",
            [$orderId,(int)$order['site_id'],$number,$type,$language,$version,$seriesCode,$sequenceNumber,$documentHash,json_encode($policy, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: '{}',json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',$text,$html,$actorId]
        );
        return $this->payload($this->db()->one('SELECT * FROM sale_order_documents WHERE id=?', [$this->db()->lastInsertId()]) ?? [], false);
    }

    /** @param array<string,mixed> $order */
    private function assertIssuable(array $order, string $type, array $policy, array $context): void
    {
        if ((string) $order['status'] === 'draft') throw new SaleValidationException('sale.document_order_not_placed');
        if ($type === 'invoice') {
            if ((string)$policy['invoice_trigger'] === 'paid' && (string) $order['payment_status'] !== 'paid') throw new SaleValidationException('sale.document_invoice_payment_required');
            if ((string)$policy['invoice_trigger'] === 'validated' && ($context['accounting_validated'] ?? false) !== true) throw new SaleValidationException('sale.document_invoice_validation_required');
            $metadata = $this->decode((string)($order['metadata_json'] ?? '{}'));
            if (($metadata['pos_payment_timing'] ?? '') === 'later' && (string)$order['payment_status'] !== 'paid') throw new SaleValidationException('sale.document_deferred_payment_pending');
        }
        if ($type === 'credit_note' && (int) $order['refunded_total_minor'] < 1) throw new SaleValidationException('sale.document_refund_required');
        if ($type === 'pos_receipt' && ((string) $order['source'] !== 'pos' || (string) $order['payment_status'] !== 'paid')) throw new SaleValidationException('sale.document_paid_pos_required');
        if ($type === 'delivery_note') {
            $count = (int) ($this->db()->one("SELECT COUNT(*) AS count FROM sale_fulfillments WHERE order_id=? AND status IN ('shipped','handed_over','delivered','returned')", [(int) $order['id']])['count'] ?? 0);
            if ($count < 1) throw new SaleValidationException('sale.document_fulfillment_required');
        }
    }

    /** @param array<string,mixed> $policy @return array{0:string,1:string,2:int} */
    private function allocateNumber(int $siteId, string $type, array $policy): array
    {
        $series = match ($type) {
            'invoice' => (string)$policy['invoice_series'],
            'credit_note' => (string)$policy['credit_note_series'],
            default => $this->prefix($type),
        };
        $period = gmdate('Y');
        return $this->db()->transaction(function () use ($siteId,$type,$series,$period): array {
            $this->db()->run('INSERT OR IGNORE INTO sale_document_sequences(site_id,document_type,series_code,period_key,next_number) VALUES(?,?,?,?,1)', [$siteId,$type,$series,$period]);
            $row = $this->db()->one('SELECT id,next_number FROM sale_document_sequences WHERE site_id=? AND document_type=? AND series_code=? AND period_key=?', [$siteId,$type,$series,$period]) ?? throw new SaleValidationException('sale.document_sequence_unavailable');
            $number = (int)$row['next_number'];
            $this->db()->run('UPDATE sale_document_sequences SET next_number=next_number+1,updated_at=CURRENT_TIMESTAMP WHERE id=? AND next_number=?', [(int)$row['id'],$number]);
            return [$series.'-'.$siteId.'-'.$period.'-'.str_pad((string)$number, 6, '0', STR_PAD_LEFT),$series,$number];
        });
    }

    /** @param array<string,mixed> $snapshot */
    private function text(array $snapshot, string $type, string $language): string
    {
        $titles = $language === 'en'
            ? ['order_confirmation'=>'Order confirmation','invoice'=>'Invoice','credit_note'=>'Credit note','delivery_note'=>'Delivery note','pos_receipt'=>'POS receipt']
            : ['order_confirmation'=>'Confirmation de commande','invoice'=>'Facture','credit_note'=>'Note de crédit','delivery_note'=>'Bon de livraison','pos_receipt'=>'Ticket POS'];
        $rows = [$titles[$type] . ' ' . ($snapshot['document_number'] ?? $snapshot['order_number'])];
        $rows[] = ($language === 'en' ? 'Order: ' : 'Commande : ') . $snapshot['order_number'];
        $rows[] = ($language === 'en' ? 'Issue date: ' : 'Date d’émission : ') . ($snapshot['issue_date'] ?? gmdate('Y-m-d'));
        if (trim((string)($snapshot['seller']['name'] ?? '')) !== '') $rows[] = ($language === 'en' ? 'Seller: ' : 'Vendeur : ') . trim((string)$snapshot['seller']['name']);
        $customerName = trim((string)($snapshot['customer']['name'] ?? $snapshot['customer']['display_name'] ?? ''));
        if ($customerName !== '') $rows[] = ($language === 'en' ? 'Customer: ' : 'Client : ') . $customerName;
        foreach ($snapshot['lines'] as $line) $rows[] = $line['quantity'] . ' × ' . trim((string) $line['product_name'] . ' ' . (string) ($line['variant_name'] ?? '')) . ' = ' . $this->money((int) $line['line_total_minor'], (string) $snapshot['currency']);
        if ((int)($snapshot['discount_total_minor'] ?? 0) > 0) $rows[] = ($language === 'en' ? 'Discounts: -' : 'Remises : -') . $this->money((int)$snapshot['discount_total_minor'], (string)$snapshot['currency']);
        $rows[] = ($language === 'en' ? 'Shipping: ' : 'Livraison : ') . $this->money((int)$snapshot['shipping_total_minor'], (string)$snapshot['currency']);
        $rows[] = ($language === 'en' ? 'Taxes included: ' : 'Taxes incluses : ') . $this->money((int)$snapshot['tax_total_minor'], (string)$snapshot['currency']);
        $rows[] = ($language === 'en' ? 'Total: ' : 'Total : ') . $this->money((int) $snapshot['grand_total_minor'], (string) $snapshot['currency']);
        if (array_key_exists('paid_total_minor', $snapshot)) $rows[] = ($language === 'en' ? 'Paid: ' : 'Payé : ') . $this->money((int) $snapshot['paid_total_minor'], (string) $snapshot['currency']);
        foreach ((array)($snapshot['terms']['messages'] ?? []) as $message) $rows[] = (string)$message;
        return implode("\n", $rows);
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private function confirmationTerms(array $context, string $language): array
    {
        $policy = is_array($context['order_policy'] ?? null) ? $context['order_policy'] : $context;
        $deferred = (string)($policy['payment_timing'] ?? '') === 'when_available' || ($policy['deferred_payment'] ?? false) === true;
        $messages = [];
        if ($deferred) {
            $messages[] = $language === 'en'
                ? 'No final invoice or definitive payment is issued before availability.'
                : 'Aucune facture finale ni paiement définitif n’est émis avant la disponibilité.';
            if (trim((string)($policy['expected_availability_at'] ?? '')) !== '') {
                $messages[] = ($language === 'en' ? 'Expected availability: ' : 'Disponibilité prévue : ') . trim((string)$policy['expected_availability_at']);
            }
            $messages[] = $language === 'en'
                ? 'A separate secure payment request will be sent when the order is available.'
                : 'Une demande de paiement sécurisée distincte sera envoyée quand la commande sera disponible.';
        }
        return ['payment_timing' => $deferred ? 'when_available' : 'prepaid', 'messages' => $messages];
    }

    /** @param array<string,mixed> $method @return array<string,mixed> */
    private function safePaymentMethod(array $method): array
    {
        return array_intersect_key($method, array_flip(['code','label','provider','payment_timing','type']));
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
