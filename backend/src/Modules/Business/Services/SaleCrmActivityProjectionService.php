<?php

declare(strict_types=1);

namespace App\Modules\Business\Services;

use App\Core\Database;
use App\Modules\Business\Contracts\CrmSaleActivityV1;
use App\Modules\Sale\Contracts\CrmActivitySink;
use App\Modules\Sale\Pricing\CustomerPricingContext;
use App\Modules\Sale\Pricing\CustomerPricingContextProvider;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use InvalidArgumentException;

final class SaleCrmActivityProjectionService implements CustomerPricingContextProvider, CrmActivitySink
{
    private const EVENT_MAP = [
        'sale.order.placed' => ['order.placed', 'placed'],
        'sale.order.confirmed' => ['order.confirmed', 'confirmed'],
        'sale.payment.recorded' => ['payment.captured', 'captured'],
        'sale.payment.failed' => ['payment.failed', 'failed'],
        'sale.fulfillment.completed' => ['fulfillment.completed', 'completed'],
        'sale.order.cancelled' => ['order.cancelled', 'cancelled'],
        'sale.return.created' => ['return.created', 'requested'],
        'sale.refund.completed' => ['refund.completed', 'completed'],
        'sale.gift_card.issued' => ['gift_card.issued', 'issued'],
        'sale.gift_card.redeemed' => ['gift_card.redeemed', 'redeemed'],
        'sale.pos.order.completed' => ['pos.order.completed', 'completed'],
    ];

    public function __construct(
        private readonly Database $business,
        private readonly SaleDatabaseConnection $sale,
    ) {}

    public function recordSaleEvent(array $event): void
    {
        $siteId = isset($event['site_id']) ? (int) $event['site_id'] : null;
        $this->consume($siteId !== null && $siteId > 0 ? $siteId : null, 200);
    }

    /** @return array{projected:int,replayed:int,failed:int} */
    public function consume(?int $siteId = null, int $limit = 200): array
    {
        $sale = $this->sale->database();
        if ($sale === null) {
            throw new InvalidArgumentException('sale.database_unavailable');
        }
        $types = array_keys(self::EVENT_MAP);
        $params = $types;
        $where = 'e.event_type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
        if ($siteId !== null) {
            if ($siteId < 1) {
                throw new InvalidArgumentException('business.site_id_invalid');
            }
            $where .= ' AND e.site_id = ?';
            $params[] = $siteId;
        }
        $rows = $sale->all(
            'SELECT o.id AS outbox_id, o.payload_json AS envelope_json, e.*
             FROM sale_outbox o JOIN sale_events e ON e.id = o.event_id
             WHERE ' . $where . ' ORDER BY e.id ASC LIMIT ' . max(1, min(1000, $limit)),
            $params
        );
        $result = ['projected' => 0, 'replayed' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            if ($this->business->one('SELECT id FROM crm_sale_activities WHERE source_event_id = ?', [(int) $row['id']]) !== null) {
                ++$result['replayed'];
                continue;
            }
            try {
                $this->store($this->dto($row));
                ++$result['projected'];
            } catch (\Throwable) {
                ++$result['failed'];
            }
        }
        return $result;
    }

    /** @return array{items:list<array<string,mixed>>,limit:int,offset:int,total:int} */
    public function unlinked(int $siteId, int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $total = (int) ($this->business->one(
            "SELECT COUNT(*) AS count FROM crm_sale_activities WHERE site_id=? AND resolution_strategy='anonymous'",
            [$siteId]
        )['count'] ?? 0);
        $items = $this->business->all(
            "SELECT * FROM crm_sale_activities WHERE site_id=? AND resolution_strategy='anonymous' ORDER BY occurred_at DESC,id DESC LIMIT {$limit} OFFSET {$offset}",
            [$siteId]
        );
        return ['items' => array_map($this->cast(...), $items), 'limit' => $limit, 'offset' => $offset, 'total' => $total];
    }

    /** @return array<string,mixed> */
    public function link(int $siteId, int $activityId, ?int $contactId, ?int $companyId, int $actorId, string $reason): array
    {
        $reason = trim($reason);
        if ($activityId < 1 || $actorId < 1 || $reason === '' || (($contactId ?? 0) < 1 && ($companyId ?? 0) < 1)) {
            throw new InvalidArgumentException('business.sale_activity_link_invalid');
        }
        $activity = $this->business->one('SELECT * FROM crm_sale_activities WHERE site_id=? AND id=?', [$siteId, $activityId]);
        if ($activity === null) {
            throw new InvalidArgumentException('business.sale_activity_not_found');
        }
        $resolvedCompanyId = $companyId;
        if (($contactId ?? 0) > 0) {
            $contact = $this->business->one('SELECT id,company_id FROM business_contacts WHERE site_id=? AND id=? AND archived_at IS NULL', [$siteId, $contactId]);
            if ($contact === null) {
                throw new InvalidArgumentException('business.sale_activity_contact_invalid');
            }
            $contactCompanyId = (int) $contact['company_id'];
            if (($companyId ?? 0) > 0 && $companyId !== $contactCompanyId) {
                throw new InvalidArgumentException('business.sale_activity_company_mismatch');
            }
            $resolvedCompanyId = $contactCompanyId;
        }
        if (($resolvedCompanyId ?? 0) > 0 && $this->business->one('SELECT id FROM business_companies WHERE site_id=? AND id=? AND archived_at IS NULL', [$siteId, $resolvedCompanyId]) === null) {
            throw new InvalidArgumentException('business.sale_activity_company_invalid');
        }
        return $this->business->transaction(function () use ($activity, $activityId, $contactId, $resolvedCompanyId, $actorId, $reason): array {
            $this->business->run(
                'INSERT INTO crm_sale_activity_link_audit(activity_id,previous_company_id,previous_contact_id,company_id,contact_id,reason,linked_by_iam_user_id) VALUES(?,?,?,?,?,?,?)',
                [$activityId, $activity['related_company_id'], $activity['related_contact_id'], $resolvedCompanyId, $contactId, substr($reason, 0, 500), $actorId]
            );
            $this->business->run(
                "UPDATE crm_sale_activities SET related_company_id=?,related_contact_id=?,resolution_strategy='manual',linked_by_iam_user_id=?,linked_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?",
                [$resolvedCompanyId, $contactId, $actorId, $activityId]
            );
            return $this->cast($this->business->one('SELECT * FROM crm_sale_activities WHERE id=?', [$activityId]) ?? []);
        });
    }

    /** @return array<string,int|array<string,mixed>> */
    public function reconcile(int $siteId, ?int $actorId = null, bool $repair = true): array
    {
        $sale = $this->sale->database() ?? throw new InvalidArgumentException('sale.database_unavailable');
        $types = array_keys(self::EVENT_MAP);
        $params = array_merge([$siteId], $types);
        $supported = (int) ($sale->one(
            'SELECT COUNT(*) AS count FROM sale_events WHERE site_id=? AND event_type IN (' . implode(',', array_fill(0, count($types), '?')) . ')',
            $params
        )['count'] ?? 0);
        $before = (int) ($this->business->one('SELECT COUNT(*) AS count FROM crm_sale_activities WHERE site_id=?', [$siteId])['count'] ?? 0);
        $missing = max(0, $supported - $before);
        $consume = $repair ? $this->consume($siteId, 1000) : ['projected' => 0, 'replayed' => 0, 'failed' => 0];
        $after = (int) ($this->business->one('SELECT COUNT(*) AS count FROM crm_sale_activities WHERE site_id=?', [$siteId])['count'] ?? 0);
        $duplicates = (int) ($this->business->one(
            'SELECT COUNT(*) AS count FROM (SELECT source_event_id FROM crm_sale_activities WHERE site_id=? GROUP BY source_event_id HAVING COUNT(*)>1)',
            [$siteId]
        )['count'] ?? 0);
        $report = [
            'supported_events' => $supported,
            'projected_events' => $after,
            'missing_events' => max(0, $supported - $after),
            'duplicate_events' => $duplicates,
            'repaired_events' => (int) $consume['projected'],
            'failed_events' => (int) $consume['failed'],
            'initial_missing_events' => $missing,
        ];
        $this->business->run(
            'INSERT INTO crm_sale_activity_reconciliation_runs(site_id,supported_events,projected_events,missing_events,duplicate_events,repaired_events,report_json,run_by_iam_user_id) VALUES(?,?,?,?,?,?,?,?)',
            [$siteId, $supported, $after, $report['missing_events'], $duplicates, $report['repaired_events'], $this->json($report), $actorId]
        );
        return $report;
    }

    public function context(int $siteId, ?int $contactId, ?int $companyId, ?int $iamUserId = null): CustomerPricingContext
    {
        if (($contactId ?? 0) < 1 && ($iamUserId ?? 0) > 0) {
            $contact = $this->business->one('SELECT id,company_id FROM business_contacts WHERE site_id=? AND iam_user_id=? AND archived_at IS NULL', [$siteId, $iamUserId]);
            if ($contact !== null) {
                $contactId = (int) $contact['id'];
                $companyId = (int) $contact['company_id'];
            }
        }
        if (($contactId ?? 0) > 0) {
            $contact = $this->business->one('SELECT id,company_id FROM business_contacts WHERE site_id=? AND id=? AND archived_at IS NULL', [$siteId, $contactId]);
            if ($contact === null) {
                throw new InvalidArgumentException('business.pricing_contact_invalid');
            }
            $companyId = (int) $contact['company_id'];
        }
        $segments = [];
        if (($contactId ?? 0) > 0 || ($companyId ?? 0) > 0) {
            $rows = $this->business->all(
                'SELECT DISTINCT t.tag_key FROM business_tags t JOIN business_tag_links l ON l.tag_id=t.id
                 WHERE t.site_id=? AND t.archived_at IS NULL AND ((l.target_type=\'contact\' AND l.contact_id=?) OR (l.target_type=\'company\' AND l.company_id=?)) ORDER BY t.tag_key',
                [$siteId, $contactId, $companyId]
            );
            $segments = array_values(array_map(static fn(array $row): string => (string) $row['tag_key'], $rows));
        }
        $marketingAllowed = ($contactId ?? 0) > 0 && $this->business->one(
            "SELECT id FROM crm_consents WHERE contact_id=? AND channel='email' AND consent_status='opt_in' LIMIT 1",
            [$contactId]
        ) !== null;
        return new CustomerPricingContext($siteId, $contactId, $companyId, $segments, $marketingAllowed);
    }

    /** @param array<string,mixed> $event */
    private function dto(array $event): CrmSaleActivityV1
    {
        $payload = json_decode((string) ($event['payload_json'] ?? '{}'), true);
        $payload = is_array($payload) ? $payload : [];
        $orderId = (int) ($payload['order_id'] ?? ((string) $event['aggregate_type'] === 'order' ? $event['aggregate_id'] : 0));
        $sale = $this->sale->database() ?? throw new InvalidArgumentException('sale.database_unavailable');
        $order = $orderId > 0 ? $sale->one('SELECT * FROM sale_orders WHERE id=? AND site_id=?', [$orderId, (int) $event['site_id']]) : null;
        $contactId = $order !== null && (int) ($order['customer_contact_id'] ?? 0) > 0 ? (int) $order['customer_contact_id'] : null;
        $companyId = $order !== null && (int) ($order['customer_company_id'] ?? 0) > 0 ? (int) $order['customer_company_id'] : null;
        $strategy = ($contactId !== null || $companyId !== null) ? 'explicit_order' : 'anonymous';
        if ($contactId === null && $companyId === null) {
            $iamUserId = (int) ($payload['iam_user_id'] ?? $event['created_by_iam_user_id'] ?? 0);
            if ($iamUserId > 0) {
                $link = $sale->one("SELECT crm_company_id,crm_contact_id FROM sale_customer_account_links WHERE site_id=? AND iam_user_id=? AND status='active'", [(int) $event['site_id'], $iamUserId]);
                if ($link !== null) {
                    $contactId = (int) ($link['crm_contact_id'] ?? 0) ?: null;
                    $companyId = (int) ($link['crm_company_id'] ?? 0) ?: null;
                    $strategy = ($contactId !== null || $companyId !== null) ? 'iam_account_link' : 'anonymous';
                }
            }
        }
        [$contactId, $companyId, $strategy] = $this->validatedRelation((int) $event['site_id'], $contactId, $companyId, $strategy);
        [$type, $status] = self::EVENT_MAP[(string) $event['event_type']];
        $source = (string) ($order['source'] ?? $payload['source'] ?? 'unknown');
        $channel = match ($source) { 'ecommerce' => 'web', 'pos' => 'pos', 'admin' => 'admin', default => 'unknown' };
        $reference = $order !== null ? (string) $order['order_number'] : null;
        $amount = isset($payload['amount_minor']) ? (int) $payload['amount_minor'] : ($order !== null ? (int) $order['grand_total_minor'] : null);
        $currency = (string) ($payload['currency'] ?? $order['currency'] ?? '');
        $summary = $this->summary($type, $reference, $amount, $currency);
        return new CrmSaleActivityV1(
            (int) $event['site_id'], $type, (string) $event['created_at'], $channel,
            $contactId, $companyId, (int) $event['id'], (int) $event['outbox_id'],
            (string) $event['event_type'], (string) $event['aggregate_type'], (int) $event['aggregate_id'],
            $reference, $summary, $status, $strategy,
            array_filter(['order_id' => $orderId ?: null, 'amount_minor' => $amount, 'currency' => $currency ?: null], static fn(mixed $value): bool => $value !== null)
        );
    }

    /** @return array{0:?int,1:?int,2:string} */
    private function validatedRelation(int $siteId, ?int $contactId, ?int $companyId, string $strategy): array
    {
        if ($contactId !== null) {
            $contact = $this->business->one('SELECT id,company_id FROM business_contacts WHERE site_id=? AND id=? AND archived_at IS NULL', [$siteId, $contactId]);
            if ($contact === null) {
                return [null, null, 'anonymous'];
            }
            $companyId = (int) $contact['company_id'];
        } elseif ($companyId !== null && $this->business->one('SELECT id FROM business_companies WHERE site_id=? AND id=? AND archived_at IS NULL', [$siteId, $companyId]) === null) {
            return [null, null, 'anonymous'];
        }
        return [$contactId, $companyId, $strategy];
    }

    private function store(CrmSaleActivityV1 $dto): void
    {
        $this->business->run(
            'INSERT OR IGNORE INTO crm_sale_activities(dto_version,site_id,activity_type,occurred_at,channel,related_company_id,related_contact_id,source_event_id,source_outbox_id,source_event_type,source_aggregate_type,source_aggregate_id,source_reference,summary,status,resolution_strategy,metadata_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [CrmSaleActivityV1::VERSION, $dto->siteId, $dto->type, $dto->occurredAt, $dto->channel, $dto->companyId, $dto->contactId, $dto->sourceEventId, $dto->sourceOutboxId, $dto->sourceEventType, $dto->sourceAggregateType, $dto->sourceAggregateId, $dto->sourceReference, substr($dto->summary, 0, 500), $dto->status, $dto->resolutionStrategy, $this->json($dto->metadata)]
        );
    }

    private function summary(string $type, ?string $reference, ?int $amount, string $currency): string
    {
        $label = match ($type) {
            'order.placed' => 'Commande passée', 'order.confirmed' => 'Commande confirmée',
            'payment.captured' => 'Paiement capturé', 'payment.failed' => 'Paiement échoué',
            'fulfillment.completed' => 'Livraison terminée', 'order.cancelled' => 'Commande annulée',
            'return.created' => 'Retour créé', 'refund.completed' => 'Remboursement terminé',
            'gift_card.issued' => 'Carte cadeau émise', 'gift_card.redeemed' => 'Carte cadeau utilisée',
            'pos.order.completed' => 'Vente POS terminée', default => 'Activité de vente',
        };
        $parts = [$label, $reference !== null && $reference !== '' ? $reference : null];
        if ($amount !== null && $currency !== '') {
            $parts[] = number_format($amount / 100, 2, '.', '') . ' ' . $currency;
        }
        return implode(' · ', array_filter($parts, static fn(?string $part): bool => $part !== null && $part !== ''));
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function cast(array $row): array
    {
        if ($row === []) return [];
        $metadata = json_decode((string) ($row['metadata_json'] ?? '{}'), true);
        $row['id'] = (int) $row['id'];
        $row['dto_version'] = (int) $row['dto_version'];
        foreach (['site_id','related_company_id','related_contact_id','source_event_id','source_outbox_id','source_aggregate_id','linked_by_iam_user_id'] as $key) {
            $row[$key] = isset($row[$key]) ? (int) $row[$key] : null;
        }
        $row['metadata'] = is_array($metadata) ? $metadata : [];
        unset($row['metadata_json']);
        return $row;
    }

    /** @param array<string,mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
