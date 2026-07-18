<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Core\Database;
use App\Modules\Sale\Exceptions\SaleValidationException;

/**
 * Reliable, idempotent journal for customer-facing transactional messages.
 *
 * The outbox payload deliberately contains no email address or order snapshot:
 * the delivery worker resolves the recipient from the immutable order at send
 * time. Marketing consent is intentionally not consulted for these messages.
 */
final class SaleOrderNotificationService
{
    private const TYPES = [
        'order_confirmed', 'payment_expected', 'payment_received', 'pickup_ready',
        'shipment_sent', 'fulfillment_exception', 'delivery_completed',
    ];

    public function __construct(
        private readonly SaleDatabaseConnection $connection,
        private readonly SaleEventService $events,
    ) {}

    /** @return array<string,mixed> */
    public function queue(
        int $orderId,
        string $type,
        string $language = 'fr',
        ?int $fulfillmentId = null,
        ?int $documentId = null,
        ?int $actorId = null,
        ?string $idempotencyKey = null,
        ?int $resendOfId = null,
    ): array {
        $type = strtolower(trim($type));
        $language = str_starts_with(strtolower($language), 'en') ? 'en' : 'fr';
        if (!in_array($type, self::TYPES, true)) throw new SaleValidationException('sale.notification.type_invalid');
        $order = $this->db()->one('SELECT id,site_id,order_number,customer_snapshot_json FROM sale_orders WHERE id=?', [$orderId]);
        if ($order === null) throw new SaleValidationException('sale.order_not_found');
        if ($fulfillmentId !== null && $this->db()->one('SELECT id FROM sale_fulfillments WHERE id=? AND order_id=?', [$fulfillmentId,$orderId]) === null) {
            throw new SaleValidationException('sale.fulfillment.not_found');
        }
        if ($documentId !== null && $this->db()->one('SELECT id FROM sale_order_documents WHERE id=? AND order_id=?', [$documentId,$orderId]) === null) {
            throw new SaleValidationException('sale.document_not_found');
        }

        $customer = json_decode((string) $order['customer_snapshot_json'], true);
        $email = strtolower(trim((string) (is_array($customer) ? ($customer['email'] ?? '') : '')));
        $recipientHash = hash('sha256', filter_var($email, FILTER_VALIDATE_EMAIL) === false ? 'unavailable:'.$orderId : $email);
        $key = trim((string) $idempotencyKey);
        if ($key === '') $key = implode(':', [$type,$orderId,$fulfillmentId ?? 0,$documentId ?? 0]);
        if (strlen($key) > 190) $key = hash('sha256', $key);

        $existing = $this->db()->one('SELECT * FROM sale_order_notifications WHERE site_id=? AND idempotency_key=?', [(int)$order['site_id'],$key]);
        if ($existing !== null) return $this->payload($existing, true);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->db()->run(
                "INSERT INTO sale_order_notifications(site_id,order_id,fulfillment_id,document_id,notification_type,recipient_hash,language,status,idempotency_key,resend_of_id,last_error,queued_by_iam_user_id) VALUES(?,?,?,?,?,?,?,'cancelled',?,?, 'recipient_missing',?)",
                [(int)$order['site_id'],$orderId,$fulfillmentId,$documentId,$type,$recipientHash,$language,$key,$resendOfId,$actorId]
            );
            return $this->payload($this->db()->one('SELECT * FROM sale_order_notifications WHERE id=?', [$this->db()->lastInsertId()]) ?? [], false);
        }

        $event = $this->events->emit((int)$order['site_id'], 'sale.notification.requested', 'order', $orderId, [
            'site_id' => (int)$order['site_id'],
            'order_id' => $orderId,
            'order_number' => (string)$order['order_number'],
            'notification_type' => $type,
            'language_code' => $language,
            'recipient_hash' => $recipientHash,
            'fulfillment_id' => $fulfillmentId,
            'document_id' => $documentId,
        ], $actorId, 'notification:'.hash('sha256', $key));
        $outbox = $this->db()->one('SELECT id FROM sale_outbox WHERE event_id=? ORDER BY id DESC LIMIT 1', [(int)($event['id'] ?? 0)]);
        $this->db()->run(
            "INSERT INTO sale_order_notifications(site_id,order_id,fulfillment_id,document_id,outbox_id,notification_type,recipient_hash,language,status,idempotency_key,resend_of_id,queued_by_iam_user_id) VALUES(?,?,?,?,?,?,?,?, 'queued',?,?,?)",
            [(int)$order['site_id'],$orderId,$fulfillmentId,$documentId,$outbox['id']??null,$type,$recipientHash,$language,$key,$resendOfId,$actorId]
        );
        return $this->payload($this->db()->one('SELECT * FROM sale_order_notifications WHERE id=?', [$this->db()->lastInsertId()]) ?? [], false);
    }

    /** @return list<array<string,mixed>> */
    public function forOrder(int $orderId): array
    {
        $rows = $this->db()->all(
            'SELECT n.*,o.status AS outbox_status,o.attempt_count,o.last_error AS outbox_error,o.processed_at
             FROM sale_order_notifications n LEFT JOIN sale_outbox o ON o.id=n.outbox_id
             WHERE n.order_id=? ORDER BY n.id DESC', [$orderId]
        );
        return array_map(fn(array $row): array => $this->payload($row, false), $rows);
    }

    /** @return array<string,mixed> */
    public function resend(int $siteId, int $notificationId, ?int $actorId): array
    {
        $row = $this->db()->one('SELECT * FROM sale_order_notifications WHERE id=? AND site_id=?', [$notificationId,$siteId]);
        if ($row === null) throw new SaleValidationException('sale.notification.not_found');
        return $this->queue(
            (int)$row['order_id'], (string)$row['notification_type'], (string)$row['language'],
            $row['fulfillment_id'] === null ? null : (int)$row['fulfillment_id'],
            $row['document_id'] === null ? null : (int)$row['document_id'],
            $actorId, 'resend:'.$notificationId.':'.bin2hex(random_bytes(8)), $notificationId
        );
    }

    /** Met un document immuable en file sans exposer l'adresse du client. @return array<string,mixed> */
    public function queueDocument(int $siteId, int $orderId, int $documentId, string $language, ?int $actorId, ?int $resendOfId = null): array
    {
        $document = $this->db()->one('SELECT id,document_type,document_number FROM sale_order_documents WHERE id=? AND order_id=? AND site_id=? AND status=\'issued\'', [$documentId,$orderId,$siteId]);
        if ($document === null || !in_array((string)$document['document_type'], ['invoice','credit_note'], true)) throw new SaleValidationException('sale.document_not_sendable');
        $order = $this->db()->one('SELECT order_number,customer_snapshot_json FROM sale_orders WHERE id=? AND site_id=?', [$orderId,$siteId]);
        if ($order === null) throw new SaleValidationException('sale.order_not_found');
        $customer = json_decode((string)$order['customer_snapshot_json'], true);
        $email = strtolower(trim((string)(is_array($customer) ? ($customer['email'] ?? '') : '')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) throw new SaleValidationException('sale.notification.recipient_missing');
        $recipientHash = hash('sha256', $email);
        $language = str_starts_with(strtolower($language), 'en') ? 'en' : 'fr';
        $key = 'document:'.$documentId.':'.($resendOfId === null ? 'initial' : 'resend:'.$resendOfId.':'.bin2hex(random_bytes(6)));
        $existing = $this->db()->one('SELECT * FROM sale_document_deliveries WHERE site_id=? AND idempotency_key=?', [$siteId,$key]);
        if ($existing !== null) return $this->documentDeliveryPayload($existing, true);
        $event = $this->events->emit($siteId, 'sale.notification.requested', 'order', $orderId, [
            'site_id'=>$siteId,'order_id'=>$orderId,'order_number'=>(string)$order['order_number'],
            'notification_type'=>(string)$document['document_type'].'_document','language_code'=>$language,
            'recipient_hash'=>$recipientHash,'document_id'=>$documentId,'document_number'=>(string)$document['document_number'],
        ], $actorId, 'document-delivery:'.hash('sha256',$key));
        $outbox = $this->db()->one('SELECT id FROM sale_outbox WHERE event_id=? ORDER BY id DESC LIMIT 1', [(int)($event['id'] ?? 0)]);
        $this->db()->run("INSERT INTO sale_document_deliveries(site_id,order_id,document_id,outbox_id,recipient_hash,language,status,idempotency_key,resend_of_id,queued_by_iam_user_id) VALUES(?,?,?,?,?,?,'queued',?,?,?)", [$siteId,$orderId,$documentId,$outbox['id']??null,$recipientHash,$language,$key,$resendOfId,$actorId]);
        return $this->documentDeliveryPayload($this->db()->one('SELECT * FROM sale_document_deliveries WHERE id=?', [$this->db()->lastInsertId()]) ?? [], false);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function documentDeliveryPayload(array $row, bool $replayed): array
    {
        $row['replayed'] = $replayed;
        unset($row['recipient_hash']);
        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function payload(array $row, bool $replayed): array
    {
        if (($row['outbox_status'] ?? null) === 'processed') $row['delivery_status'] = 'sent';
        elseif (($row['outbox_status'] ?? null) === 'failed') $row['delivery_status'] = 'failed';
        else $row['delivery_status'] = (string)($row['status'] ?? 'queued');
        $row['replayed'] = $replayed;
        unset($row['recipient_hash']);
        return $row;
    }

    private function db(): Database
    {
        return $this->connection->database() ?? throw new SaleValidationException('sale.database_unavailable');
    }
}
