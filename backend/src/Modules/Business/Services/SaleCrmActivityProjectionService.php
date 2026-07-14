<?php

declare(strict_types=1);

namespace App\Modules\Business\Services;

use App\Core\Database;
use App\Modules\Business\Contracts\CrmActivityV2;
use App\Modules\Sale\Contracts\CrmActivitySink;
use App\Modules\Sale\Pricing\CustomerPricingContext;
use App\Modules\Sale\Pricing\CustomerPricingContextProvider;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use InvalidArgumentException;

final class SaleCrmActivityProjectionService implements CustomerPricingContextProvider, CrmActivitySink
{
    private const EVENT_MAP = [
        'sale.cart.abandoned' => ['cart.abandoned', 'abandoned'],
        'sale.order.placed' => ['order.placed', 'placed'],
        'sale.order.confirmed' => ['order.confirmed', 'confirmed'],
        'sale.payment.recorded' => ['payment.captured', 'captured'],
        'sale.payment.capture.completed' => ['payment.captured', 'captured'],
        'sale.payment.failed' => ['payment.failed', 'failed'],
        'sale.fulfillment.completed' => ['fulfillment.completed', 'completed'],
        'sale.order.cancelled' => ['order.cancelled', 'cancelled'],
        'sale.return.created' => ['return.created', 'requested'],
        'sale.refund.completed' => ['refund.completed', 'completed'],
        'sale.gift_card.issued' => ['gift_card.issued', 'issued'],
        'sale.gift_card.redeemed' => ['gift_card.redeemed', 'redeemed'],
        'sale.pos.order.completed' => ['pos.order.completed', 'completed'],
        'customer.account.created' => ['customer.account.created', 'created'],
    ];

    public function __construct(
        private readonly Database $business,
        private readonly SaleDatabaseConnection $sale,
    ) {}

    public function recordSaleEvent(array $event): void
    {
        $siteId = isset($event['site_id']) ? (int) $event['site_id'] : null;
        try {
            $this->consume($siteId !== null && $siteId > 0 ? $siteId : null, 200);
        } catch (\Throwable) {
            // The outbox remains the source of replay; CRM availability never blocks Sale.
        }
    }

    /** @return array{projected:int,replayed:int,failed:int} */
    public function consume(?int $siteId = null, int $limit = 200, bool $refresh = false): array
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
        $target = max(1, min(1000, $limit));
        $pageSize = min(250, $target);
        $offset = 0;
        $result = ['projected' => 0, 'replayed' => 0, 'failed' => 0];
        do {
            $rows = $sale->all(
                'SELECT o.id AS outbox_id, o.payload_json AS envelope_json, e.*
                 FROM sale_outbox o JOIN sale_events e ON e.id = o.event_id
                 WHERE ' . $where . ' ORDER BY e.id ASC LIMIT ' . $pageSize . ' OFFSET ' . $offset,
                $params
            );
            foreach ($rows as $row) {
                try {
                    $existing = $this->business->one(
                        'SELECT id FROM crm_sale_activities WHERE source_type=? AND source_id=? AND contract_version=?',
                        ['sale_event', (string) $row['id'], CrmActivityV2::CONTRACT_VERSION]
                    ) !== null;
                    if ($existing && !$refresh) {
                        ++$result['replayed'];
                        continue;
                    }
                    $this->store($this->dto($row));
                    $existing ? ++$result['replayed'] : ++$result['projected'];
                } catch (\Throwable) {
                    ++$result['failed'];
                }
                if ($result['projected'] + $result['failed'] >= $target) break 2;
            }
            $offset += count($rows);
        } while (count($rows) === $pageSize);
        return $result;
    }

    /** @return array{items:list<array<string,mixed>>,limit:int,offset:int,total:int} */
    public function unlinked(int $siteId, int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $total = (int) ($this->business->one(
            "SELECT COUNT(*) AS count FROM crm_sale_activities WHERE site_id=? AND resolution_strategy='pending'",
            [$siteId]
        )['count'] ?? 0);
        $items = $this->business->all(
            "SELECT * FROM crm_sale_activities WHERE site_id=? AND resolution_strategy='pending' ORDER BY occurred_at DESC,id DESC LIMIT {$limit} OFFSET {$offset}",
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
        $events = $sale->all(
            'SELECT id FROM sale_events WHERE site_id=? AND event_type IN (' . implode(',', array_fill(0, count($types), '?')) . ') ORDER BY id',
            $params
        );
        $sourceIds = array_map(static fn(array $row): string => (string) $row['id'], $events);
        $projectedIds = array_map(
            static fn(array $row): string => (string) $row['source_id'],
            $this->business->all('SELECT source_id FROM crm_sale_activities WHERE site_id=? AND source_type=? AND contract_version=?', [$siteId, 'sale_event', CrmActivityV2::CONTRACT_VERSION])
        );
        $supported = count($sourceIds);
        $missingIds = array_values(array_diff($sourceIds, $projectedIds));
        $missing = count($missingIds);
        $consume = $repair ? $this->consume($siteId, 1000) : ['projected' => 0, 'replayed' => 0, 'failed' => 0];
        $afterRows = $this->business->all('SELECT source_id FROM crm_sale_activities WHERE site_id=? AND source_type=? AND contract_version=?', [$siteId, 'sale_event', CrmActivityV2::CONTRACT_VERSION]);
        $afterIds = array_map(static fn(array $row): string => (string) $row['source_id'], $afterRows);
        $after = count($afterIds);
        $duplicates = (int) ($this->business->one(
            'SELECT COUNT(*) AS count FROM (SELECT source_type,source_id,contract_version FROM crm_sale_activities WHERE site_id=? GROUP BY source_type,source_id,contract_version HAVING COUNT(*)>1)',
            [$siteId]
        )['count'] ?? 0);
        $report = [
            'supported_events' => $supported,
            'projected_events' => $after,
            'missing_events' => count(array_diff($sourceIds, $afterIds)),
            'duplicate_events' => $duplicates,
            'repaired_events' => (int) $consume['projected'],
            'failed_events' => (int) $consume['failed'],
            'initial_missing_events' => $missing,
            'initial_missing_source_ids' => array_slice($missingIds, 0, 100),
            'pending_identity_events' => (int) ($this->business->one("SELECT COUNT(*) AS count FROM crm_sale_activities WHERE site_id=? AND resolution_strategy='pending'", [$siteId])['count'] ?? 0),
        ];
        $this->business->run(
            'INSERT INTO crm_sale_activity_reconciliation_runs(site_id,supported_events,projected_events,missing_events,duplicate_events,repaired_events,report_json,run_by_iam_user_id) VALUES(?,?,?,?,?,?,?,?)',
            [$siteId, $supported, $after, $report['missing_events'], $duplicates, $report['repaired_events'], $this->json($report), $actorId]
        );
        return $report;
    }

    /** Replays every available source event without deleting manual identity decisions. */
    public function rebuild(int $siteId, ?int $actorId = null): array
    {
        $total = ['projected' => 0, 'replayed' => 0, 'failed' => 0];
        do {
            $result = $this->consume($siteId, 1000, true);
            foreach ($total as $key => $_) $total[$key] += $result[$key];
        } while ($result['projected'] === 1000 && $result['failed'] === 0);
        return ['mode' => 'rebuild', 'consume' => $total, 'reconciliation' => $this->reconcile($siteId, $actorId, true)];
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
    private function dto(array $event): CrmActivityV2
    {
        $payload = json_decode((string) ($event['payload_json'] ?? '{}'), true);
        $payload = is_array($payload) ? $payload : [];
        $orderId = (int) ($payload['order_id'] ?? ((string) $event['aggregate_type'] === 'order' ? $event['aggregate_id'] : 0));
        $context = $orderId > 0 ? $this->projectedOrderContext((int) $event['site_id'], $orderId) : null;
        $contactId = (int) ($payload['customer_contact_id'] ?? 0) ?: ($context['related_contact_id'] ?? null);
        $companyId = (int) ($payload['customer_company_id'] ?? 0) ?: ($context['related_company_id'] ?? null);
        $strategy = ($contactId !== null || $companyId !== null)
            ? (((int) ($payload['customer_contact_id'] ?? $payload['customer_company_id'] ?? 0)) > 0 ? 'explicit_event' : 'event_correlation')
            : 'pending';
        [$contactId, $companyId, $strategy] = $this->validatedRelation((int) $event['site_id'], $contactId, $companyId, $strategy);
        [$type, $status] = self::EVENT_MAP[(string) $event['event_type']];
        $source = (string) ($payload['source'] ?? $context['channel'] ?? 'unknown');
        $channel = match ($source) { 'ecommerce', 'web' => 'web', 'pos' => 'pos', 'admin' => 'admin', default => (string) ($context['channel'] ?? 'unknown') };
        $channelId = (int) ($payload['channel_id'] ?? 0) ?: (isset($context['channel_id']) ? (int) $context['channel_id'] : null);
        $languageCode = trim((string) ($payload['language_code'] ?? $payload['locale'] ?? $context['language_code'] ?? '')) ?: null;
        $reference = trim((string) ($payload['order_number'] ?? $payload['return_number'] ?? $context['source_reference'] ?? '')) ?: null;
        $amount = isset($payload['amount_minor']) ? (int) $payload['amount_minor'] : (isset($payload['grand_total_minor']) ? (int) $payload['grand_total_minor'] : null);
        $currency = (string) ($payload['currency'] ?? $context['currency'] ?? '');
        $retentionUntil = isset($payload['retention_until']) ? (string) $payload['retention_until'] : null;
        if ((string) $event['event_type'] === 'sale.cart.abandoned') {
            $this->assertEligibleAbandonedCart($event, $payload, $contactId, $companyId, $retentionUntil);
        }
        $summary = $this->summary($type, $reference, $amount, $currency);
        $metadata = array_filter([
            'order_id' => $orderId ?: null,
            'cart_id' => isset($payload['cart_id']) ? (int) $payload['cart_id'] : null,
            'transaction_id' => isset($payload['transaction_id']) ? (int) $payload['transaction_id'] : null,
            'payment_intent_id' => isset($payload['payment_intent_id']) ? (int) $payload['payment_intent_id'] : null,
            'return_id' => isset($payload['return_id']) ? (int) $payload['return_id'] : null,
            'refund_id' => isset($payload['refund_id']) ? (int) $payload['refund_id'] : null,
            'gift_card_id' => isset($payload['gift_card_id']) ? (int) $payload['gift_card_id'] : null,
            'iam_user_id' => isset($payload['iam_user_id']) ? (int) $payload['iam_user_id'] : null,
            'amount_minor' => $amount,
            'currency' => $currency ?: null,
            'payment_status' => $payload['payment_status'] ?? null,
            'marketing_communication_allowed' => ($payload['marketing_consent'] ?? false) === true,
        ], static fn(mixed $value): bool => $value !== null);
        return new CrmActivityV2(
            (int) $event['site_id'], $type, (string) $event['created_at'], $channel, $channelId, $languageCode,
            $contactId, $companyId, (int) $event['id'], (int) $event['outbox_id'],
            'sale_event', (string) $event['id'],
            (string) $event['event_type'], (string) $event['aggregate_type'], (int) $event['aggregate_id'],
            $reference, $summary, $status, $strategy,
            ['event_type' => (string) $event['event_type'], 'event_id' => (int) $event['id'], 'outbox_id' => (int) $event['outbox_id'], 'correlation_id' => $event['correlation_id'] ?? null],
            $metadata,
            $retentionUntil,
        );
    }

    /** @return array{0:?int,1:?int,2:string} */
    private function validatedRelation(int $siteId, ?int $contactId, ?int $companyId, string $strategy): array
    {
        if ($contactId !== null) {
            $contact = $this->business->one('SELECT id,company_id FROM business_contacts WHERE site_id=? AND id=? AND archived_at IS NULL', [$siteId, $contactId]);
            if ($contact === null) {
                return [null, null, 'pending'];
            }
            $companyId = (int) $contact['company_id'];
        } elseif ($companyId !== null && $this->business->one('SELECT id FROM business_companies WHERE site_id=? AND id=? AND archived_at IS NULL', [$siteId, $companyId]) === null) {
            return [null, null, 'pending'];
        }
        return [$contactId, $companyId, $strategy];
    }

    /** @return array<string,mixed>|null */
    private function projectedOrderContext(int $siteId, int $orderId): ?array
    {
        if ($orderId < 1) {
            return null;
        }
        $row = $this->business->one(
            "SELECT related_company_id,related_contact_id,channel,channel_id,language_code,source_reference,metadata_json
             FROM crm_sale_activities
             WHERE site_id=? AND resolution_strategy<>'pending' AND CAST(json_extract(metadata_json,'$.order_id') AS INTEGER)=?
             ORDER BY CASE activity_type WHEN 'order.placed' THEN 0 ELSE 1 END,id LIMIT 1",
            [$siteId, $orderId]
        );
        if ($row === null) {
            return null;
        }
        $metadata = json_decode((string) $row['metadata_json'], true);
        $row['currency'] = is_array($metadata) ? ($metadata['currency'] ?? null) : null;
        return $row;
    }

    /** @param array<string,mixed> $event @param array<string,mixed> $payload */
    private function assertEligibleAbandonedCart(array $event, array $payload, ?int $contactId, ?int $companyId, ?string $retentionUntil): void
    {
        $minimumAge = (int) ($payload['abandoned_after_seconds'] ?? 0);
        $lawfulBasis = trim((string) ($payload['lawful_basis'] ?? ''));
        $iamUserId = (int) ($payload['iam_user_id'] ?? 0);
        $occurred = strtotime((string) ($event['created_at'] ?? '')) ?: time();
        $retention = $retentionUntil === null ? false : strtotime($retentionUntil);
        if ($minimumAge < 3600 || $lawfulBasis === '' || ($contactId === null && $companyId === null && $iamUserId < 1)
            || $retention === false || $retention <= $occurred || $retention > $occurred + 86400 * 180) {
            throw new InvalidArgumentException('business.abandoned_cart_activity_ineligible');
        }
    }

    private function resolvePendingForOrder(CrmActivityV2 $dto): void
    {
        $orderId = (int) ($dto->metadata['order_id'] ?? 0);
        if ($orderId < 1 || ($dto->contactId === null && $dto->companyId === null)) {
            return;
        }
        $this->business->run(
            "UPDATE crm_sale_activities SET related_company_id=?,related_contact_id=?,resolution_strategy='event_correlation',
                    channel=CASE WHEN channel='unknown' THEN ? ELSE channel END,
                    channel_id=COALESCE(channel_id,?),language_code=COALESCE(language_code,?),
                    source_reference=COALESCE(source_reference,?),updated_at=CURRENT_TIMESTAMP
             WHERE site_id=? AND resolution_strategy='pending' AND CAST(json_extract(metadata_json,'$.order_id') AS INTEGER)=?",
            [$dto->companyId, $dto->contactId, $dto->channel, $dto->channelId, $dto->languageCode, $dto->sourceReference, $dto->siteId, $orderId]
        );
    }

    private function store(CrmActivityV2 $dto): void
    {
        $this->business->run(
            'INSERT INTO crm_sale_activities(dto_version,contract_version,site_id,activity_type,occurred_at,channel,channel_id,language_code,related_company_id,related_contact_id,source_event_id,source_outbox_id,source_type,source_id,source_event_type,source_aggregate_type,source_aggregate_id,source_reference,summary,status,resolution_strategy,provenance_json,metadata_json,retention_until)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON CONFLICT(source_type,source_id,contract_version) DO UPDATE SET
                activity_type=excluded.activity_type,occurred_at=excluded.occurred_at,
                channel=CASE WHEN crm_sale_activities.channel=\'unknown\' THEN excluded.channel ELSE crm_sale_activities.channel END,
                channel_id=COALESCE(crm_sale_activities.channel_id,excluded.channel_id),
                language_code=COALESCE(crm_sale_activities.language_code,excluded.language_code),
                related_company_id=CASE WHEN crm_sale_activities.resolution_strategy=\'manual\' THEN crm_sale_activities.related_company_id ELSE COALESCE(excluded.related_company_id,crm_sale_activities.related_company_id) END,
                related_contact_id=CASE WHEN crm_sale_activities.resolution_strategy=\'manual\' THEN crm_sale_activities.related_contact_id ELSE COALESCE(excluded.related_contact_id,crm_sale_activities.related_contact_id) END,
                source_reference=COALESCE(crm_sale_activities.source_reference,excluded.source_reference),
                summary=excluded.summary,status=excluded.status,
                resolution_strategy=CASE WHEN crm_sale_activities.resolution_strategy=\'manual\' THEN \'manual\' WHEN excluded.resolution_strategy<>\'pending\' THEN excluded.resolution_strategy ELSE crm_sale_activities.resolution_strategy END,
                provenance_json=excluded.provenance_json,metadata_json=excluded.metadata_json,
                retention_until=COALESCE(excluded.retention_until,crm_sale_activities.retention_until),updated_at=CURRENT_TIMESTAMP',
            [CrmActivityV2::VERSION, CrmActivityV2::CONTRACT_VERSION, $dto->siteId, $dto->type, $dto->occurredAt, $dto->channel, $dto->channelId, $dto->languageCode, $dto->companyId, $dto->contactId, $dto->sourceEventId, $dto->sourceOutboxId, $dto->sourceType, $dto->sourceId, $dto->sourceEventType, $dto->sourceAggregateType, $dto->sourceAggregateId, $dto->sourceReference, substr($dto->summary, 0, 500), $dto->status, $dto->resolutionStrategy, $this->json($dto->provenance), $this->json($dto->metadata), $dto->retentionUntil]
        );
        if ($dto->resolutionStrategy !== 'pending') {
            $this->resolvePendingForOrder($dto);
        }
    }

    private function summary(string $type, ?string $reference, ?int $amount, string $currency): string
    {
        $label = match ($type) {
            'cart.abandoned' => 'Panier abandonné',
            'order.placed' => 'Commande passée', 'order.confirmed' => 'Commande confirmée',
            'payment.captured' => 'Paiement capturé', 'payment.failed' => 'Paiement échoué',
            'fulfillment.completed' => 'Livraison terminée', 'order.cancelled' => 'Commande annulée',
            'return.created' => 'Retour créé', 'refund.completed' => 'Remboursement terminé',
            'gift_card.issued' => 'Carte cadeau émise', 'gift_card.redeemed' => 'Carte cadeau utilisée',
            'customer.account.created' => 'Compte client créé',
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
        $provenance = json_decode((string) ($row['provenance_json'] ?? '{}'), true);
        $row['id'] = (int) $row['id'];
        $row['dto_version'] = (int) $row['dto_version'];
        foreach (['site_id','channel_id','related_company_id','related_contact_id','source_event_id','source_outbox_id','source_aggregate_id','linked_by_iam_user_id'] as $key) {
            $row[$key] = isset($row[$key]) ? (int) $row[$key] : null;
        }
        $row['metadata'] = is_array($metadata) ? $metadata : [];
        $row['provenance'] = is_array($provenance) ? $provenance : [];
        unset($row['metadata_json'], $row['provenance_json']);
        return $row;
    }

    /** @param array<string,mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
