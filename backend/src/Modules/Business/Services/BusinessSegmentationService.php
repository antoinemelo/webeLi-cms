<?php

declare(strict_types=1);

namespace App\Modules\Business\Services;

use App\Core\Database;
use InvalidArgumentException;

/**
 * Explainable CRM segmentation built exclusively from the Business read model.
 * It never reads Sale transaction tables and never derives marketing consent.
 */
final class BusinessSegmentationService
{
    private const CRITERIA = [
        'customer_type' => ['eq'],
        'last_purchase_at' => ['within_days', 'before', 'after'],
        'order_count' => ['eq', 'gt', 'gte', 'lt', 'lte'],
        'total_spent_minor' => ['eq', 'gt', 'gte', 'lt', 'lte'],
        'primary_channel' => ['eq', 'neq'],
        'product_id' => ['contains', 'not_contains'],
        'category_id' => ['contains', 'not_contains'],
        'has_return' => ['eq'],
        'gift_card_status' => ['eq'],
    ];

    public function __construct(private readonly Database $business) {}

    /** @return list<array<string,mixed>> */
    public function criteria(): array
    {
        return [
            ['key' => 'customer_type', 'label' => 'Type de client', 'operators' => self::CRITERIA['customer_type'], 'values' => ['new', 'recurring']],
            ['key' => 'last_purchase_at', 'label' => 'Dernier achat', 'operators' => self::CRITERIA['last_purchase_at']],
            ['key' => 'order_count', 'label' => 'Nombre de commandes', 'operators' => self::CRITERIA['order_count']],
            ['key' => 'total_spent_minor', 'label' => 'Montant cumulé', 'operators' => self::CRITERIA['total_spent_minor']],
            ['key' => 'primary_channel', 'label' => 'Canal principal', 'operators' => self::CRITERIA['primary_channel'], 'values' => ['web', 'pos', 'admin', 'unknown']],
            ['key' => 'product_id', 'label' => 'Produit acheté', 'operators' => self::CRITERIA['product_id']],
            ['key' => 'category_id', 'label' => 'Catégorie achetée', 'operators' => self::CRITERIA['category_id']],
            ['key' => 'has_return', 'label' => 'Retour ou remboursement', 'operators' => self::CRITERIA['has_return'], 'values' => [true, false]],
            ['key' => 'gift_card_status', 'label' => 'Bon cadeau', 'operators' => self::CRITERIA['gift_card_status'], 'values' => ['issued', 'redeemed', 'any']],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function list(int $siteId): array
    {
        $this->site($siteId);
        $rows = $this->business->all(
            "SELECT * FROM crm_segments WHERE site_id=? AND status='active' ORDER BY name,id",
            [$siteId]
        );
        return array_map($this->castSegment(...), $rows);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function create(int $siteId, array $payload, int $actorId): array
    {
        $this->site($siteId);
        $name = $this->requiredText($payload['name'] ?? null, 'segment_name', 120);
        $kind = strtolower(trim((string) ($payload['segment_kind'] ?? $payload['kind'] ?? 'calculated')));
        if (!in_array($kind, ['calculated', 'manual'], true)) {
            throw new InvalidArgumentException('business.segment_kind_invalid');
        }
        [$criterion, $operator, $value, $explanation] = $kind === 'calculated'
            ? $this->rule($payload)
            : [null, null, null, 'Segment manuel : les membres sont ajoutés explicitement.'];
        $retentionDays = max(30, min(3650, (int) ($payload['retention_days'] ?? 730)));
        $advanced = is_array($payload['advanced'] ?? null) ? $payload['advanced'] : [];
        try {
            $this->business->run(
                'INSERT INTO crm_segments(site_id,name,segment_kind,criterion,operator,value_json,rule_version,explanation,advanced_json,retention_days,created_by_iam_user_id,updated_by_iam_user_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',
                [$siteId, $name, $kind, $criterion, $operator, $this->json($value), 1, $explanation, $this->json($advanced), $retentionDays, $actorId, $actorId]
            );
        } catch (\Throwable $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'unique')) {
                throw new InvalidArgumentException('business.segment_name_exists');
            }
            throw $exception;
        }
        return $this->requireSegment($siteId, $this->business->lastInsertId());
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function update(int $siteId, int $segmentId, array $payload, int $actorId): array
    {
        $segment = $this->requireSegment($siteId, $segmentId);
        $name = array_key_exists('name', $payload) ? $this->requiredText($payload['name'], 'segment_name', 120) : (string) $segment['name'];
        $criterion = $segment['criterion'];
        $operator = $segment['operator'];
        $value = $segment['value'];
        $explanation = (string) $segment['explanation'];
        $ruleVersion = (int) $segment['rule_version'];
        if ((string) $segment['segment_kind'] === 'calculated' && (isset($payload['criterion']) || isset($payload['operator']) || array_key_exists('value', $payload))) {
            [$criterion, $operator, $value, $explanation] = $this->rule($payload + [
                'criterion' => $segment['criterion'],
                'operator' => $segment['operator'],
                'value' => $segment['value'],
            ]);
            ++$ruleVersion;
        }
        $retentionDays = max(30, min(3650, (int) ($payload['retention_days'] ?? $segment['retention_days'])));
        $this->business->run(
            'UPDATE crm_segments SET name=?,criterion=?,operator=?,value_json=?,rule_version=?,explanation=?,retention_days=?,updated_by_iam_user_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [$name, $criterion, $operator, $this->json($value), $ruleVersion, $explanation, $retentionDays, $actorId, $segmentId]
        );
        return $this->requireSegment($siteId, $segmentId);
    }

    /** @param array<string,mixed> $rule @return array<string,mixed> */
    public function preview(int $siteId, array $rule): array
    {
        $this->site($siteId);
        [$criterion, $operator, $value, $explanation] = $this->rule($rule);
        $profiles = $this->profiles($siteId);
        $matches = array_values(array_filter($profiles, fn(array $profile): bool => $this->matches($profile, $criterion, $operator, $value)));
        return [
            'count' => count($matches),
            'examples' => array_map(static fn(array $profile): array => [
                'reference' => 'Contact ' . substr(hash('sha256', (string) $profile['contact_id']), 0, 8),
                'explanation' => $profile['summary'],
            ], array_slice($matches, 0, 5)),
            'explanation' => $explanation,
            'data_source' => 'crm_sale_activities',
        ];
    }

    /** @return array<string,mixed> */
    public function recalculate(int $siteId, int $segmentId, bool $full = true): array
    {
        $segment = $this->requireSegment($siteId, $segmentId);
        if ((string) $segment['segment_kind'] !== 'calculated') {
            throw new InvalidArgumentException('business.segment_manual_not_recalculable');
        }
        $cursor = $full ? 0 : (int) $segment['last_source_activity_id'];
        $contactIds = $full ? null : $this->changedContactIds($siteId, $cursor);
        $profiles = $this->profiles($siteId, $contactIds);
        $matched = [];
        foreach ($profiles as $profile) {
            if ($this->matches($profile, (string) $segment['criterion'], (string) $segment['operator'], $segment['value'])) {
                $matched[(int) $profile['contact_id']] = $profile;
            }
        }
        $expiresAt = gmdate('Y-m-d H:i:s', time() + ((int) $segment['retention_days'] * 86400));
        $maxSourceId = (int) ($this->business->one('SELECT COALESCE(MAX(id),0) AS id FROM crm_sale_activities WHERE site_id=?', [$siteId])['id'] ?? 0);
        $this->business->transaction(function () use ($segmentId, $segment, $full, $contactIds, $matched, $expiresAt, $maxSourceId): void {
            if ($full) {
                $this->business->run("DELETE FROM crm_segment_members WHERE segment_id=? AND membership_kind='calculated'", [$segmentId]);
            } elseif ($contactIds !== []) {
                $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
                $this->business->run("DELETE FROM crm_segment_members WHERE segment_id=? AND membership_kind='calculated' AND contact_id IN ({$placeholders})", array_merge([$segmentId], $contactIds));
            }
            foreach ($matched as $contactId => $profile) {
                $this->business->run(
                    "INSERT INTO crm_segment_members(segment_id,contact_id,membership_kind,rule_version,explanation_json,expires_at) VALUES(?,?,'calculated',?,?,?)
                     ON CONFLICT(segment_id,contact_id) DO UPDATE SET membership_kind='calculated',rule_version=excluded.rule_version,explanation_json=excluded.explanation_json,matched_at=CURRENT_TIMESTAMP,expires_at=excluded.expires_at,updated_at=CURRENT_TIMESTAMP",
                    [$segmentId, $contactId, (int) $segment['rule_version'], $this->json(['summary' => $profile['summary'], 'source' => 'crm_sale_activities']), $expiresAt]
                );
            }
            $count = (int) ($this->business->one('SELECT COUNT(*) AS count FROM crm_segment_members WHERE segment_id=? AND (expires_at IS NULL OR expires_at>CURRENT_TIMESTAMP)', [$segmentId])['count'] ?? 0);
            $this->business->run('UPDATE crm_segments SET result_count=?,last_source_activity_id=?,last_calculated_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?', [$count, $maxSourceId, $segmentId]);
        });
        return [
            'mode' => $full ? 'full' : 'incremental',
            'evaluated_contacts' => count($profiles),
            'matched_contacts' => count($matched),
            'segment' => $this->requireSegment($siteId, $segmentId),
        ];
    }

    /** @return array<string,mixed> */
    public function addManualMember(int $siteId, int $segmentId, int $contactId, int $actorId): array
    {
        $segment = $this->requireSegment($siteId, $segmentId);
        if ((string) $segment['segment_kind'] !== 'manual') {
            throw new InvalidArgumentException('business.segment_calculated_members_immutable');
        }
        $this->requireContact($siteId, $contactId);
        $this->business->run(
            "INSERT INTO crm_segment_members(segment_id,contact_id,membership_kind,rule_version,explanation_json,created_by_iam_user_id) VALUES(?,?,'manual',?,'{\"source\":\"manual\"}',?) ON CONFLICT(segment_id,contact_id) DO NOTHING",
            [$segmentId, $contactId, (int) $segment['rule_version'], $actorId]
        );
        $this->refreshCount($segmentId);
        return $this->requireSegment($siteId, $segmentId);
    }

    public function removeManualMember(int $siteId, int $segmentId, int $contactId): void
    {
        $segment = $this->requireSegment($siteId, $segmentId);
        if ((string) $segment['segment_kind'] !== 'manual') {
            throw new InvalidArgumentException('business.segment_calculated_members_immutable');
        }
        $this->business->run("DELETE FROM crm_segment_members WHERE segment_id=? AND contact_id=? AND membership_kind='manual'", [$segmentId, $contactId]);
        $this->refreshCount($segmentId);
    }

    /** @return list<array<string,mixed>> */
    public function members(int $siteId, int $segmentId): array
    {
        $this->requireSegment($siteId, $segmentId);
        return $this->business->all(
            "SELECT m.contact_id,c.display_name,m.membership_kind,m.rule_version,m.explanation_json,m.matched_at,m.expires_at
             FROM crm_segment_members m JOIN business_contacts c ON c.id=m.contact_id
             WHERE m.segment_id=? AND c.site_id=? AND c.archived_at IS NULL AND (m.expires_at IS NULL OR m.expires_at>CURRENT_TIMESTAMP)
             ORDER BY c.display_name,c.id",
            [$segmentId, $siteId]
        );
    }

    /** @param array<string,mixed> $payload @return array{0:string,1:string,2:mixed,3:string} */
    private function rule(array $payload): array
    {
        $criterion = strtolower(trim((string) ($payload['criterion'] ?? '')));
        $operator = strtolower(trim((string) ($payload['operator'] ?? '')));
        if ($criterion === '' || $operator === '') {
            throw new InvalidArgumentException('business.segment_rule_required');
        }
        if (!isset(self::CRITERIA[$criterion]) || !in_array($operator, self::CRITERIA[$criterion], true)) {
            throw new InvalidArgumentException('business.segment_rule_invalid');
        }
        if (!array_key_exists('value', $payload) || $payload['value'] === '') {
            throw new InvalidArgumentException('business.segment_value_required');
        }
        $value = $payload['value'];
        if (in_array($criterion, ['order_count', 'total_spent_minor', 'product_id', 'category_id', 'last_purchase_at'], true) && in_array($operator, ['eq','gt','gte','lt','lte','contains','not_contains','within_days'], true)) {
            if (!is_numeric($value) || (int) $value < 0) throw new InvalidArgumentException('business.segment_value_invalid');
            $value = (int) $value;
        }
        if ($criterion === 'has_return') {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($value === null) throw new InvalidArgumentException('business.segment_value_invalid');
        }
        return [$criterion, $operator, $value, $this->explanation($criterion, $operator, $value)];
    }

    /** @return list<array<string,mixed>> */
    private function profiles(int $siteId, ?array $contactIds = null): array
    {
        if ($contactIds === []) return [];
        $params = [$siteId];
        $where = 'c.site_id=? AND c.archived_at IS NULL';
        if ($contactIds !== null) {
            $where .= ' AND c.id IN (' . implode(',', array_fill(0, count($contactIds), '?')) . ')';
            $params = array_merge($params, $contactIds);
        }
        $rows = $this->business->all(
            "SELECT c.id AS contact_id,a.id AS activity_id,a.activity_type,a.occurred_at,a.channel,a.metadata_json
             FROM business_contacts c JOIN crm_sale_activities a ON a.related_contact_id=c.id AND a.site_id=c.site_id
             WHERE {$where} ORDER BY c.id,a.id",
            $params
        );
        $profiles = [];
        foreach ($rows as $row) {
            $contactId = (int) $row['contact_id'];
            $profile = $profiles[$contactId] ?? [
                'contact_id' => $contactId, 'order_ids' => [], 'order_count' => 0, 'total_spent_minor' => 0,
                'last_purchase_at' => null, 'channels' => [], 'primary_channel' => 'unknown',
                'product_ids' => [], 'category_ids' => [], 'return_count' => 0,
                'gift_issued_count' => 0, 'gift_redeemed_count' => 0, 'summary' => '',
            ];
            $metadata = json_decode((string) ($row['metadata_json'] ?? '{}'), true);
            $metadata = is_array($metadata) ? $metadata : [];
            $type = (string) $row['activity_type'];
            $channel = (string) ($row['channel'] ?? 'unknown');
            $profile['channels'][$channel] = (int) ($profile['channels'][$channel] ?? 0) + 1;
            if ($type === 'order.placed') {
                $orderId = (int) ($metadata['order_id'] ?? $row['activity_id']);
                if (!isset($profile['order_ids'][$orderId])) {
                    $profile['order_ids'][$orderId] = true;
                    $profile['order_count']++;
                    $profile['total_spent_minor'] += (int) ($metadata['amount_minor'] ?? 0);
                }
                if ($profile['last_purchase_at'] === null || strcmp((string) $row['occurred_at'], (string) $profile['last_purchase_at']) > 0) {
                    $profile['last_purchase_at'] = (string) $row['occurred_at'];
                }
            }
            foreach (['product_ids', 'category_ids'] as $key) {
                foreach ((array) ($metadata[$key] ?? []) as $id) if ((int) $id > 0) $profile[$key][(int) $id] = true;
            }
            if (in_array($type, ['return.created', 'refund.completed'], true)) $profile['return_count']++;
            if ($type === 'gift_card.issued') $profile['gift_issued_count']++;
            if ($type === 'gift_card.redeemed') $profile['gift_redeemed_count']++;
            $profiles[$contactId] = $profile;
        }
        foreach ($profiles as &$profile) {
            arsort($profile['channels']);
            $profile['primary_channel'] = (string) (array_key_first($profile['channels']) ?? 'unknown');
            $profile['product_ids'] = array_map('intval', array_keys($profile['product_ids']));
            $profile['category_ids'] = array_map('intval', array_keys($profile['category_ids']));
            $profile['summary'] = sprintf('%d commande(s), %d en unité mineure, canal %s', $profile['order_count'], $profile['total_spent_minor'], $profile['primary_channel']);
        }
        unset($profile);
        return array_values($profiles);
    }

    /** @return list<int> */
    private function changedContactIds(int $siteId, int $cursor): array
    {
        return array_map('intval', array_column($this->business->all(
            'SELECT DISTINCT related_contact_id FROM crm_sale_activities WHERE site_id=? AND id>? AND related_contact_id IS NOT NULL ORDER BY related_contact_id',
            [$siteId, $cursor]
        ), 'related_contact_id'));
    }

    private function matches(array $profile, string $criterion, string $operator, mixed $value): bool
    {
        $actual = match ($criterion) {
            'customer_type' => (int) $profile['order_count'] > 1 ? 'recurring' : 'new',
            'last_purchase_at' => $profile['last_purchase_at'],
            'order_count' => (int) $profile['order_count'],
            'total_spent_minor' => (int) $profile['total_spent_minor'],
            'primary_channel' => (string) $profile['primary_channel'],
            'product_id' => $profile['product_ids'],
            'category_id' => $profile['category_ids'],
            'has_return' => (int) $profile['return_count'] > 0,
            'gift_card_status' => (int) $profile['gift_redeemed_count'] > 0 ? 'redeemed' : ((int) $profile['gift_issued_count'] > 0 ? 'issued' : 'none'),
            default => null,
        };
        if ($criterion === 'gift_card_status' && $value === 'any') return $actual !== 'none';
        if ($criterion === 'last_purchase_at') {
            if (!is_string($actual) || strtotime($actual) === false) return false;
            if ($operator === 'within_days') return strtotime($actual) >= time() - ((int) $value * 86400);
            $target = strtotime((string) $value);
            if ($target === false) return false;
            return $operator === 'before' ? strtotime($actual) < $target : strtotime($actual) > $target;
        }
        if ($operator === 'contains' || $operator === 'not_contains') {
            $contains = in_array((int) $value, (array) $actual, true);
            return $operator === 'contains' ? $contains : !$contains;
        }
        return match ($operator) {
            'eq' => $actual === $value || (string) $actual === (string) $value,
            'neq' => !($actual === $value || (string) $actual === (string) $value),
            'gt' => $actual > $value, 'gte' => $actual >= $value,
            'lt' => $actual < $value, 'lte' => $actual <= $value,
            default => false,
        };
    }

    private function explanation(string $criterion, string $operator, mixed $value): string
    {
        $criteria = array_column($this->criteria(), 'label', 'key');
        $operators = ['eq' => 'est égal à', 'neq' => 'est différent de', 'gt' => 'est supérieur à', 'gte' => 'est au moins', 'lt' => 'est inférieur à', 'lte' => 'est au plus', 'contains' => 'contient', 'not_contains' => 'ne contient pas', 'within_days' => 'date de moins de (jours)', 'before' => 'est avant', 'after' => 'est après'];
        return sprintf('%s %s %s. Calculé depuis les activités CRM projetées.', $criteria[$criterion] ?? $criterion, $operators[$operator] ?? $operator, is_bool($value) ? ($value ? 'oui' : 'non') : (string) $value);
    }

    /** @return array<string,mixed> */
    private function requireSegment(int $siteId, int $segmentId): array
    {
        $this->site($siteId);
        $row = $this->business->one("SELECT * FROM crm_segments WHERE site_id=? AND id=? AND status='active'", [$siteId, $segmentId]);
        if ($row === null) throw new InvalidArgumentException('business.segment_not_found');
        return $this->castSegment($row);
    }

    private function requireContact(int $siteId, int $contactId): void
    {
        if ($contactId < 1 || $this->business->one('SELECT id FROM business_contacts WHERE site_id=? AND id=? AND archived_at IS NULL', [$siteId, $contactId]) === null) {
            throw new InvalidArgumentException('business.contact_not_found');
        }
    }

    private function refreshCount(int $segmentId): void
    {
        $count = (int) ($this->business->one('SELECT COUNT(*) AS count FROM crm_segment_members WHERE segment_id=? AND (expires_at IS NULL OR expires_at>CURRENT_TIMESTAMP)', [$segmentId])['count'] ?? 0);
        $this->business->run('UPDATE crm_segments SET result_count=?,updated_at=CURRENT_TIMESTAMP WHERE id=?', [$count, $segmentId]);
    }

    /** @return array<string,mixed> */
    private function castSegment(array $row): array
    {
        foreach (['id','site_id','rule_version','retention_days','result_count','last_source_activity_id'] as $key) $row[$key] = (int) $row[$key];
        $value = json_decode((string) ($row['value_json'] ?? 'null'), true);
        $advanced = json_decode((string) ($row['advanced_json'] ?? '{}'), true);
        $row['value'] = $value;
        $row['advanced'] = is_array($advanced) ? $advanced : [];
        unset($row['value_json'], $row['advanced_json']);
        return $row;
    }

    private function requiredText(mixed $value, string $field, int $max): string
    {
        $text = trim((string) $value);
        if ($text === '') throw new InvalidArgumentException('business.' . $field . '_required');
        if (strlen($text) > $max) throw new InvalidArgumentException('business.' . $field . '_too_long');
        return $text;
    }

    private function site(int $siteId): void
    {
        if ($siteId < 1) throw new InvalidArgumentException('business.site_id_invalid');
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'null';
    }
}
