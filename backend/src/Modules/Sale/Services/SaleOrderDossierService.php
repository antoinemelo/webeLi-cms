<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Core\Database;
use App\Modules\Sale\Exceptions\SaleValidationException;

/**
 * Read model for the operator-facing order dossier.
 *
 * This service deliberately derives its presentation state from the existing
 * order, payment and fulfillment state machines. It is not a second workflow.
 */
final class SaleOrderDossierService
{
    public function __construct(
        private readonly SaleDatabaseConnection $connection,
        private readonly SaleOrderTimelineService $timeline,
    ) {}

    /** @return array<string,mixed> */
    public function dossier(int $orderId, string $language = 'fr'): array
    {
        $language = strtolower($language) === 'en' ? 'en' : 'fr';
        $order = $this->db()->one(
            'SELECT o.*,c.code AS channel_code,c.name AS channel_name,c.channel_type
             FROM sale_orders o INNER JOIN sale_channels c ON c.id=o.channel_id WHERE o.id=?',
            [$orderId]
        );
        if ($order === null) throw new SaleValidationException('sale.order_not_found');

        $order['lines'] = $this->db()->all('SELECT * FROM sale_order_lines WHERE order_id=? ORDER BY line_number', [$orderId]);
        $order['customer'] = $this->decode((string) $order['customer_snapshot_json']);
        $order['billing_address'] = $this->decode((string) $order['billing_address_json']);
        $order['shipping_address'] = $this->decode((string) $order['shipping_address_json']);
        $order['shipping_method'] = $this->decode((string) $order['shipping_method_snapshot_json']);
        $order['payment_method'] = $this->decode((string) $order['payment_method_snapshot_json']);
        $order['metadata'] = $this->decode((string) $order['metadata_json']);
        foreach (['customer_snapshot_json','billing_address_json','shipping_address_json','shipping_method_snapshot_json','payment_method_snapshot_json','metadata_json'] as $field) unset($order[$field]);

        $payments = $this->db()->all(
            'SELECT t.id,t.payment_intent_id,t.transaction_type,t.status,t.amount_minor,t.currency,t.processed_at,t.created_at,
                    i.provider_key,i.status AS intent_status,i.checkout_url,i.expires_at
             FROM sale_payment_transactions t LEFT JOIN sale_payment_intents i ON i.id=t.payment_intent_id
             WHERE t.order_id=? ORDER BY t.id', [$orderId]
        );
        $intents = $this->db()->all(
            'SELECT id,provider_key,status,amount_minor,currency,authorized_minor,captured_minor,refunded_minor,checkout_url,expires_at,created_at,updated_at
             FROM sale_payment_intents WHERE order_id=? ORDER BY id', [$orderId]
        );
        $fulfillments = $this->db()->all(
            'SELECT f.*,l.code AS location_code,l.name AS location_name FROM sale_fulfillments f
             LEFT JOIN sale_stock_locations l ON l.id=f.stock_location_id WHERE f.order_id=? ORDER BY f.id', [$orderId]
        );
        foreach ($fulfillments as &$fulfillment) {
            $fulfillment['lines'] = $this->db()->all(
                'SELECT fl.*,ol.sku,ol.product_name,ol.variant_name FROM sale_fulfillment_lines fl
                 INNER JOIN sale_order_lines ol ON ol.id=fl.order_line_id WHERE fl.fulfillment_id=? ORDER BY fl.id',
                [(int) $fulfillment['id']]
            );
            $fulfillment['next_action'] = $this->fulfillmentNextAction((string) $fulfillment['status'], (string) $fulfillment['fulfillment_type'], $fulfillment['lines']);
            $fulfillment['shipping_address'] = $this->decode((string) $fulfillment['shipping_address_snapshot_json']);
            $fulfillment['shipping_method'] = $this->decode((string) $fulfillment['shipping_method_snapshot_json']);
            $fulfillment['problem'] = $this->decode((string) $fulfillment['problem_json']);
            $fulfillment['operator_proof'] = $this->decode((string) $fulfillment['operator_proof_json']);
            foreach (['shipping_address_snapshot_json','shipping_method_snapshot_json','problem_json','operator_proof_json'] as $field) unset($fulfillment[$field]);
        }
        unset($fulfillment);

        $backorders = $this->db()->all(
            "SELECT b.*,i.sku,r.product_name,r.variant_name FROM sale_stock_backorders b
             INNER JOIN sale_inventory_items i ON i.id=b.inventory_item_id
             LEFT JOIN sale_catalog_variant_refs r ON r.site_id=i.site_id AND r.sellable_id=i.sellable_id
             WHERE b.order_id=? AND b.status NOT IN ('fulfilled','cancelled') ORDER BY b.id",
            [$orderId]
        );
        $returns = $this->db()->all('SELECT * FROM sale_returns WHERE order_id=? ORDER BY id', [$orderId]);
        $refunds = $this->db()->all('SELECT * FROM sale_refunds WHERE order_id=? ORDER BY id', [$orderId]);
        $documents = $this->documents($orderId);
        $paymentPlan = $this->tableExists('sale_order_payment_plans')
            ? $this->db()->one('SELECT * FROM sale_order_payment_plans WHERE order_id=?', [$orderId])
            : null;
        if ($paymentPlan !== null) {
            $paymentPlan['terms'] = $this->decode((string) $paymentPlan['terms_snapshot_json']);
            unset($paymentPlan['terms_snapshot_json'], $paymentPlan['metadata_json']);
        }
        $state = $this->presentationState($order, $intents, $fulfillments, $backorders, $paymentPlan, $language);

        return [
            'order' => $order,
            'state' => $state,
            'payments' => ['summary' => $this->paymentSummary($order, $intents), 'plan' => $paymentPlan, 'intents' => $intents, 'transactions' => $payments, 'refunds' => $refunds],
            'fulfillment' => ['summary' => $this->fulfillmentSummary($order, $fulfillments, $backorders), 'operations' => $fulfillments, 'backorders' => $backorders],
            'documents' => $documents,
            'returns' => $returns,
            'messages' => [],
            'timeline' => $this->timeline->timeline($orderId, $language),
            'relation' => [
                'contact_id' => isset($order['customer_contact_id']) ? (int) $order['customer_contact_id'] : null,
                'company_id' => isset($order['customer_company_id']) ? (int) $order['customer_company_id'] : null,
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function actionableDashboard(int $siteId, string $language = 'fr'): array
    {
        $rows = $this->db()->all(
            "SELECT id FROM sale_orders WHERE site_id=? AND status NOT IN ('completed','cancelled') ORDER BY created_at,id LIMIT 200",
            [$siteId]
        );
        $items = [];
        foreach ($rows as $row) {
            $dossier = $this->dossier((int) $row['id'], $language);
            if (($dossier['state']['next_action'] ?? 'none') === 'none') continue;
            $items[] = [
                'order_id' => (int) $dossier['order']['id'],
                'order_number' => (string) $dossier['order']['order_number'],
                'customer' => $this->customerLabel((array) $dossier['order']['customer']),
                'channel' => (string) ($dossier['order']['channel_name'] ?? $dossier['order']['source']),
                'total_minor' => (int) $dossier['order']['grand_total_minor'],
                'currency' => (string) $dossier['order']['currency'],
                'state' => $dossier['state'],
                'placed_at' => $dossier['order']['placed_at'] ?? $dossier['order']['created_at'],
            ];
        }
        usort($items, static fn(array $a, array $b): int => ((int) $b['state']['priority'] <=> (int) $a['state']['priority']) ?: strcmp((string) $a['placed_at'], (string) $b['placed_at']));
        $groups = [];
        foreach ($items as $item) $groups[(string) $item['state']['next_action']] = ($groups[(string) $item['state']['next_action']] ?? 0) + 1;
        return ['tasks' => $items, 'groups' => $groups, 'total' => count($items)];
    }

    /** @param array<string,mixed> $order @param list<array<string,mixed>> $intents @param list<array<string,mixed>> $fulfillments @param list<array<string,mixed>> $backorders @return array<string,mixed> */
    private function presentationState(array $order, array $intents, array $fulfillments, array $backorders, ?array $paymentPlan, string $language): array
    {
        $status = (string) $order['status'];
        $payment = (string) $order['payment_status'];
        $fulfillment = (string) $order['fulfillment_status'];
        $blockers = [];
        $next = 'none'; $priority = 0;

        if ($status === 'cancelled') $key = 'cancelled';
        elseif ($status === 'completed') $key = 'completed';
        elseif ($payment === 'failed' || array_filter($intents, static fn(array $i): bool => in_array((string) $i['status'], ['failed','expired'], true))) { $key = 'payment_failed'; $next = 'request_payment'; $priority = 90; }
        elseif (($paymentPlan['status'] ?? null) === 'waiting_availability' || $backorders !== []) { $key = 'waiting_availability'; $next = 'monitor_availability'; $priority = 55; $blockers[] = 'stock_unavailable'; }
        elseif (in_array($payment, ['unpaid','pending'], true)) { $key = 'payment_due'; $next = 'request_payment'; $priority = 80; }
        elseif ($payment === 'authorized') { $key = 'payment_authorized'; $next = 'capture_when_ready'; $priority = 70; }
        elseif ($payment === 'partially_paid') { $key = 'balance_due'; $next = 'request_balance'; $priority = 85; }
        elseif ($payment === 'refunded') { $key = 'refunded'; }
        elseif ($fulfillment === 'unfulfilled') { $key = 'ready_to_prepare'; $next = 'prepare_order'; $priority = 75; }
        elseif ($fulfillment === 'partially_fulfilled') { $key = 'fulfillment_in_progress'; $next = 'continue_fulfillment'; $priority = 65; }
        elseif ($fulfillment === 'fulfilled' && $status !== 'completed') { $key = 'ready_to_close'; $next = 'close_order'; $priority = 35; }
        else { $key = 'in_progress'; $next = $fulfillments === [] && $fulfillment !== 'not_required' ? 'prepare_order' : 'none'; $priority = $next === 'none' ? 0 : 60; }

        if (!in_array($payment, ['paid','authorized','refunded'], true) && $fulfillment !== 'not_required') $blockers[] = 'payment_required_before_delivery';
        $labels = $this->labels($language);
        return [
            'key' => $key,
            'label' => $labels['state'][$key] ?? $key,
            'internal' => ['order' => $status, 'payment' => $payment, 'fulfillment' => $fulfillment],
            'next_action' => $next,
            'next_action_label' => $labels['action'][$next] ?? $next,
            'priority' => $priority,
            'blockers' => array_values(array_unique($blockers)),
            'actions' => [
                ['key' => 'request_payment', 'allowed' => in_array($next, ['request_payment','request_balance'], true), 'permission' => 'sale.payments.manage'],
                ['key' => 'record_payment', 'allowed' => !in_array($status, ['completed','cancelled'], true) && !in_array($payment, ['paid','refunded'], true), 'permission' => 'sale.payments.manage'],
                ['key' => 'prepare_order', 'allowed' => in_array($payment, ['paid','authorized'], true) && $fulfillment === 'unfulfilled', 'permission' => 'sale.fulfillment.manage'],
                ['key' => 'close_order', 'allowed' => $next === 'close_order' && $status === 'confirmed' && in_array($payment, ['paid','refunded'], true) && in_array($fulfillment, ['fulfilled','returned','not_required'], true), 'permission' => 'sale.orders.manage'],
                ['key' => 'cancel_order', 'allowed' => in_array($status, ['placed','confirmed'], true) && !in_array($payment, ['paid','partially_refunded','refunded'], true), 'permission' => 'sale.orders.manage'],
            ],
        ];
    }

    /** @param array<string,mixed> $order @param list<array<string,mixed>> $intents @return array<string,mixed> */
    private function paymentSummary(array $order, array $intents): array
    {
        return [
            'status' => (string) $order['payment_status'],
            'total_minor' => (int) $order['grand_total_minor'],
            'paid_minor' => (int) $order['paid_total_minor'],
            'refunded_minor' => (int) $order['refunded_total_minor'],
            'due_minor' => max(0, (int) $order['grand_total_minor'] - (int) $order['paid_total_minor']),
            'active_payment_url' => $this->activePaymentUrl($intents),
            'payment_proof' => (string) $order['payment_status'] === 'paid',
        ];
    }

    /** @param array<string,mixed> $order @param list<array<string,mixed>> $fulfillments @param list<array<string,mixed>> $backorders @return array<string,mixed> */
    private function fulfillmentSummary(array $order, array $fulfillments, array $backorders): array
    {
        $location = $this->db()->one(
            "SELECT i.stock_location_id FROM sale_stock_reservations r INNER JOIN sale_inventory_items i ON i.id=r.inventory_item_id
             WHERE r.order_id=? AND r.status IN ('active','confirmed','consumed') ORDER BY r.id LIMIT 1",
            [(int) $order['id']]
        );
        return [
            'status' => (string) $order['fulfillment_status'],
            'operations' => count($fulfillments),
            'open_backorders' => count($backorders),
            'suggested_stock_location_id' => isset($location['stock_location_id']) ? (int) $location['stock_location_id'] : ($order['stock_location_id'] ?? null),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function documents(int $orderId): array
    {
        $documents = [];
        if ($this->tableExists('sale_order_documents')) {
            $documents = $this->db()->all('SELECT * FROM sale_order_documents WHERE order_id=? ORDER BY issued_at,id', [$orderId]);
            foreach ($documents as &$document) {
                $document['printable_text'] = (string) ($document['text_snapshot'] ?? '');
                unset($document['snapshot_json'], $document['html_snapshot'], $document['text_snapshot']);
            }
            unset($document);
        }
        foreach ($this->db()->all('SELECT id,receipt_number,receipt_type,status,language,issued_at FROM sale_receipts WHERE order_id=? ORDER BY id', [$orderId]) as $receipt) {
            $receipt['document_number'] = $receipt['receipt_number'];
            $receipt['document_type'] = (string) $receipt['receipt_type'] === 'credit_receipt' ? 'credit_note' : $receipt['receipt_type'];
            $receipt['legacy_receipt'] = true;
            $documents[] = $receipt;
        }
        return $documents;
    }

    /** @param list<array<string,mixed>> $intents */
    private function activePaymentUrl(array $intents): ?string
    {
        foreach (array_reverse($intents) as $intent) if (!empty($intent['checkout_url']) && in_array((string) $intent['status'], ['requires_payment','requires_action'], true)) return (string) $intent['checkout_url'];
        return null;
    }

    /** @param list<array<string,mixed>> $lines */
    private function fulfillmentNextAction(string $status, string $type, array $lines = []): string
    {
        $preparationComplete = $lines !== [];
        foreach ($lines as $line) {
            if ((int) ($line['prepared_quantity'] ?? 0) < (int) ($line['quantity'] ?? 0)) {
                $preparationComplete = false;
                break;
            }
        }
        return match ($status) {
            'pending' => 'allocate',
            'allocated','partially_prepared' => 'prepare',
            'preparing' => $preparationComplete ? ($type === 'pickup' ? 'mark_ready' : 'ship') : 'prepare',
            'ready_for_pickup' => 'hand_over',
            'shipped' => 'confirm_delivery',
            'blocked' => 'resolve_problem',
            default => 'none'
        };
    }

    /** @return array<string,array<string,string>> */
    private function labels(string $language): array
    {
        if ($language === 'en') return [
            'state' => ['cancelled'=>'Cancelled','completed'=>'Completed','payment_failed'=>'Payment needs attention','waiting_availability'=>'Waiting for availability','payment_due'=>'Payment due','payment_authorized'=>'Payment authorized','balance_due'=>'Balance due','refunded'=>'Refunded','ready_to_prepare'=>'Ready to prepare','fulfillment_in_progress'=>'Fulfillment in progress','ready_to_close'=>'Ready to close','in_progress'=>'In progress'],
            'action' => ['none'=>'No action','request_payment'=>'Send payment request','monitor_availability'=>'Monitor availability','capture_when_ready'=>'Capture when ready','request_balance'=>'Request balance','prepare_order'=>'Prepare order','continue_fulfillment'=>'Continue fulfillment','close_order'=>'Close order'],
        ];
        return [
            'state' => ['cancelled'=>'Annulée','completed'=>'Terminée','payment_failed'=>'Paiement à reprendre','waiting_availability'=>'En attente de disponibilité','payment_due'=>'Paiement attendu','payment_authorized'=>'Paiement autorisé','balance_due'=>'Solde à encaisser','refunded'=>'Remboursée','ready_to_prepare'=>'À préparer','fulfillment_in_progress'=>'Préparation en cours','ready_to_close'=>'À clôturer','in_progress'=>'En cours'],
            'action' => ['none'=>'Aucune action','request_payment'=>'Envoyer une demande de paiement','monitor_availability'=>'Surveiller la disponibilité','capture_when_ready'=>'Capturer quand la commande est prête','request_balance'=>'Demander le solde','prepare_order'=>'Préparer la commande','continue_fulfillment'=>'Poursuivre la préparation','close_order'=>'Clôturer la commande'],
        ];
    }

    /** @param array<string,mixed> $customer */
    private function customerLabel(array $customer): string
    {
        $name = trim((string) ($customer['display_name'] ?? $customer['name'] ?? (($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''))));
        return $name !== '' ? $name : (string) ($customer['email'] ?? '—');
    }

    /** @return array<string,mixed> */
    private function decode(string $json): array { $value = json_decode($json, true); return is_array($value) ? $value : []; }

    private function tableExists(string $table): bool { return $this->db()->one("SELECT name FROM sqlite_master WHERE type='table' AND name=?", [$table]) !== null; }

    private function db(): Database { return $this->connection->database() ?? throw new SaleValidationException('sale.database_unavailable'); }
}
