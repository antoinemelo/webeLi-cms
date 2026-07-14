<?php

declare(strict_types=1);

namespace App\Modules\Business\Repositories;

use InvalidArgumentException;

final class BusinessActivityRepository extends BusinessRepositoryBase
{
    /** @return array<string,mixed> */
    public function log(int $siteId, ?int $actorId, string $entityType, int $entityId, ?int $companyId, ?int $contactId, string $action, string $summary, array $metadata = []): array
    {
        $siteId = $this->requireSiteId($siteId);
        if ($entityId < 1 || trim($entityType) === '' || trim($action) === '' || trim($summary) === '') {
            throw new InvalidArgumentException('business.activity_invalid');
        }
        $this->database()->run(
            'INSERT INTO business_activity_log(site_id, actor_iam_user_id, entity_type, entity_id, related_company_id, related_contact_id, action, summary, metadata_json)
             VALUES(:site_id, :actor, :entity_type, :entity_id, :company_id, :contact_id, :action, :summary, :metadata_json)',
            [
                'site_id' => $siteId,
                'actor' => $actorId && $actorId > 0 ? $actorId : null,
                'entity_type' => substr(trim($entityType), 0, 80),
                'entity_id' => $entityId,
                'company_id' => $companyId && $companyId > 0 ? $companyId : null,
                'contact_id' => $contactId && $contactId > 0 ? $contactId : null,
                'action' => substr(trim($action), 0, 120),
                'summary' => substr(trim($summary), 0, 500),
                'metadata_json' => $this->json($metadata),
            ]
        );
        $row = $this->database()->one('SELECT * FROM business_activity_log WHERE id = :id', ['id' => $this->database()->lastInsertId()]);
        return $row ? $this->activityRow($row, 'activity') : [];
    }

    /** @return array{items:list<array<string,mixed>>,limit:int,offset:int,total:int} */
    public function relationActivity(int $siteId, string $type, int $id, int $limit = 50, int $offset = 0, array $filters = []): array
    {
        $siteId = $this->requireSiteId($siteId);
        if (!in_array($type, ['contact', 'company'], true) || $id < 1) {
            throw new InvalidArgumentException('business.relation_activity_target_invalid');
        }
        $limit = $this->limit($limit, 200);
        $offset = $this->offset($offset);
        $companyId = $type === 'company' ? $id : $this->companyIdForContact($siteId, $id);
        $contactId = $type === 'contact' ? $id : null;
        if ($type === 'contact' && $companyId === null) {
            throw new InvalidArgumentException('business.relation_not_found');
        }
        if ($type === 'company' && !$this->companyExists($siteId, $id)) {
            throw new InvalidArgumentException('business.relation_not_found');
        }

        $items = array_merge(
            $this->loggedActivity($siteId, $type, $id),
            $this->memoActivity($siteId, $type, $id),
            $this->commentActivity($siteId, $type, $id),
            $this->shareActivity($siteId, $type, $id),
            $this->messageActivity($siteId, $type, $id),
            $this->consentActivity($siteId, $type, $id),
            $this->saleActivity($siteId, $type, $id)
        );
        usort($items, static fn(array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')) ?: strcmp((string) $b['id'], (string) $a['id']));
        $q = strtolower(trim((string) ($filters['q'] ?? '')));
        $kind = trim((string) ($filters['kind'] ?? ''));
        $channel = trim((string) ($filters['channel'] ?? ''));
        $items = array_values(array_filter($items, static function (array $item) use ($q, $kind, $channel): bool {
            if ($kind !== '' && (string) ($item['kind'] ?? '') !== $kind) return false;
            if ($channel !== '' && (string) ($item['metadata']['channel'] ?? '') !== $channel) return false;
            if ($q !== '' && !str_contains(strtolower((string) ($item['summary'] ?? '') . ' ' . (string) ($item['action'] ?? '') . ' ' . (string) ($item['metadata']['source_reference'] ?? '')), $q)) return false;
            return true;
        }));
        $total = count($items);
        return ['items' => array_slice($items, $offset, $limit), 'limit' => $limit, 'offset' => $offset, 'total' => $total];
    }

    /** @return list<array<string,mixed>> */
    private function loggedActivity(int $siteId, string $type, int $id): array
    {
        $where = $type === 'company' ? 'related_company_id = :id' : 'related_contact_id = :id';
        $rows = $this->database()->all(
            'SELECT * FROM business_activity_log WHERE site_id = :site_id AND ' . $where . ' ORDER BY created_at DESC, id DESC LIMIT 200',
            ['site_id' => $siteId, 'id' => $id]
        );
        return array_map(fn(array $row): array => $this->activityRow($row, 'activity'), $rows);
    }

    /** @return list<array<string,mixed>> */
    private function memoActivity(int $siteId, string $type, int $id): array
    {
        $where = $type === 'company'
            ? '(m.company_id = :id OR m.contact_id IN (SELECT c.id FROM business_contacts c WHERE c.site_id = m.site_id AND c.company_id = :id AND c.archived_at IS NULL))'
            : 'm.contact_id = :id';
        $rows = $this->database()->all(
            "SELECT m.id, m.site_id, m.company_id AS related_company_id, m.contact_id AS related_contact_id, m.author_iam_user_id AS actor_iam_user_id,
                    'crm_memo' AS entity_type, m.id AS entity_id, 'business.memo.created' AS action, m.title AS summary,
                    json_object('visibility', m.visibility, 'body_excerpt', substr(m.body, 1, 180)) AS metadata_json,
                    m.created_at
             FROM crm_memos m
             WHERE m.site_id = :site_id AND m.archived_at IS NULL AND {$where}
             ORDER BY m.created_at DESC, m.id DESC LIMIT 200",
            ['site_id' => $siteId, 'id' => $id]
        );
        return array_map(fn(array $row): array => $this->activityRow($row, 'memo'), $rows);
    }

    /** @return list<array<string,mixed>> */
    private function commentActivity(int $siteId, string $type, int $id): array
    {
        $where = $type === 'company'
            ? '(m.company_id = :id OR m.contact_id IN (SELECT c.id FROM business_contacts c WHERE c.site_id = m.site_id AND c.company_id = :id AND c.archived_at IS NULL))'
            : 'm.contact_id = :id';
        $rows = $this->database()->all(
            "SELECT cc.id, m.site_id, m.company_id AS related_company_id, m.contact_id AS related_contact_id, cc.author_iam_user_id AS actor_iam_user_id,
                    'crm_memo_comment' AS entity_type, cc.id AS entity_id, 'business.memo.commented' AS action, 'Commentaire sur ' || m.title AS summary,
                    json_object('memo_id', m.id, 'comment_excerpt', substr(cc.body, 1, 180)) AS metadata_json,
                    cc.created_at
             FROM crm_memo_comments cc
             JOIN crm_memos m ON m.id = cc.memo_id
             WHERE m.site_id = :site_id AND m.archived_at IS NULL AND cc.archived_at IS NULL AND {$where}
             ORDER BY cc.created_at DESC, cc.id DESC LIMIT 200",
            ['site_id' => $siteId, 'id' => $id]
        );
        return array_map(fn(array $row): array => $this->activityRow($row, 'comment'), $rows);
    }

    /** @return list<array<string,mixed>> */
    private function shareActivity(int $siteId, string $type, int $id): array
    {
        $where = $type === 'company'
            ? '(m.company_id = :id OR m.contact_id IN (SELECT c.id FROM business_contacts c WHERE c.site_id = m.site_id AND c.company_id = :id AND c.archived_at IS NULL))'
            : 'm.contact_id = :id';
        $rows = $this->database()->all(
            "SELECT s.id, m.site_id, m.company_id AS related_company_id, m.contact_id AS related_contact_id, s.created_by_iam_user_id AS actor_iam_user_id,
                    'crm_memo_share' AS entity_type, s.id AS entity_id, 'business.memo.shared' AS action, 'Mémo partagé : ' || m.title AS summary,
                    json_object('memo_id', m.id, 'share_type', s.share_type, 'revoked_at', s.revoked_at) AS metadata_json,
                    s.created_at
             FROM crm_memo_shares s
             JOIN crm_memos m ON m.id = s.memo_id
             WHERE m.site_id = :site_id AND m.archived_at IS NULL AND {$where}
             ORDER BY s.created_at DESC, s.id DESC LIMIT 200",
            ['site_id' => $siteId, 'id' => $id]
        );
        return array_map(fn(array $row): array => $this->activityRow($row, 'share'), $rows);
    }

    /** @return list<array<string,mixed>> */
    private function messageActivity(int $siteId, string $type, int $id): array
    {
        $where = $type === 'company'
            ? 'mo.contact_id IN (SELECT c.id FROM business_contacts c WHERE c.site_id = mo.site_id AND c.company_id = :id AND c.archived_at IS NULL)'
            : 'mo.contact_id = :id';
        $rows = $this->database()->all(
            "SELECT mo.id, mo.site_id, c.company_id AS related_company_id, mo.contact_id AS related_contact_id, mo.created_by_iam_user_id AS actor_iam_user_id,
                    'crm_message_outbox' AS entity_type, mo.id AS entity_id, 'business.message.sent' AS action,
                    upper(mo.channel) || ' · ' || COALESCE(mo.subject, mo.recipient_value) AS summary,
                    json_object('channel', mo.channel, 'status', mo.status, 'recipient_value', mo.recipient_value, 'last_error', mo.last_error) AS metadata_json,
                    COALESCE(mo.sent_at, mo.created_at) AS created_at
             FROM crm_message_outbox mo
             LEFT JOIN business_contacts c ON c.id = mo.contact_id
             WHERE mo.site_id = :site_id AND {$where}
             ORDER BY COALESCE(mo.sent_at, mo.created_at) DESC, mo.id DESC LIMIT 200",
            ['site_id' => $siteId, 'id' => $id]
        );
        return array_map(fn(array $row): array => $this->activityRow($row, 'message'), $rows);
    }

    /** @return list<array<string,mixed>> */
    private function consentActivity(int $siteId, string $type, int $id): array
    {
        if (!$this->database()->tableExists('crm_consent_events')) return [];
        $where = $type === 'company'
            ? 'ce.contact_id IN (SELECT c.id FROM business_contacts c WHERE c.site_id=ce.site_id AND c.company_id=:id AND c.archived_at IS NULL)'
            : 'ce.contact_id=:id';
        $rows = $this->database()->all(
            "SELECT ce.id,ce.site_id,ce.actor_iam_user_id,
                    'crm_consent_event' AS entity_type,ce.id AS entity_id,
                    c.company_id AS related_company_id,ce.contact_id AS related_contact_id,
                    'business.consent.' || ce.event_type AS action,
                    CASE ce.event_type WHEN 'granted' THEN 'Consentement marketing accordé' WHEN 'withdrawn' THEN 'Consentement marketing retiré' ELSE 'Consentement marketing enregistré' END || ' · ' || upper(ce.channel) AS summary,
                    json_object('channel',ce.channel,'purpose',ce.purpose,'scope',ce.scope_type || ':' || ce.scope_id,'status',ce.consent_status,'source',ce.source,'evidence',ce.evidence,'retention_until',ce.retention_until) AS metadata_json,
                    ce.occurred_at AS created_at
             FROM crm_consent_events ce JOIN business_contacts c ON c.id=ce.contact_id
             WHERE ce.site_id=:site_id AND {$where}
             ORDER BY ce.occurred_at DESC,ce.id DESC LIMIT 200",
            ['site_id' => $siteId, 'id' => $id]
        );
        return array_map(fn(array $row): array => $this->activityRow($row, 'consent'), $rows);
    }

    /** @return list<array<string,mixed>> */
    private function saleActivity(int $siteId, string $type, int $id): array
    {
        if (!$this->database()->tableExists('crm_sale_activities')) {
            return [];
        }
        $where = $type === 'company' ? 'related_company_id = :id' : 'related_contact_id = :id';
        $rows = $this->database()->all(
            "SELECT id,site_id,linked_by_iam_user_id AS actor_iam_user_id,
                    'crm_sale_activity' AS entity_type,source_aggregate_id AS entity_id,
                    related_company_id,related_contact_id,'business.sale.' || activity_type AS action,summary,
                    json_patch(metadata_json,json_object('channel',channel,'channel_id',channel_id,'language_code',language_code,'status',status,'source_reference',source_reference,'source_event_type',source_event_type,'source_type',source_type,'source_id',source_id,'contract_version',contract_version,'resolution_strategy',resolution_strategy)) AS metadata_json,
                    occurred_at AS created_at
             FROM crm_sale_activities WHERE site_id=:site_id AND {$where}
             ORDER BY occurred_at DESC,id DESC LIMIT 200",
            ['site_id' => $siteId, 'id' => $id]
        );
        return array_map(fn(array $row): array => $this->activityRow($row, 'sale'), $rows);
    }

    private function companyIdForContact(int $siteId, int $contactId): ?int
    {
        $row = $this->database()->one('SELECT company_id FROM business_contacts WHERE site_id = :site_id AND id = :id AND archived_at IS NULL LIMIT 1', ['site_id' => $siteId, 'id' => $contactId]);
        return $row ? (int) $row['company_id'] : null;
    }

    private function companyExists(int $siteId, int $companyId): bool
    {
        return $this->database()->one('SELECT id FROM business_companies WHERE site_id = :site_id AND id = :id AND archived_at IS NULL LIMIT 1', ['site_id' => $siteId, 'id' => $companyId]) !== null;
    }

    /** @return array<string,mixed> */
    private function activityRow(array $row, string $kind): array
    {
        $metadata = json_decode((string) ($row['metadata_json'] ?? '{}'), true);
        return [
            'id' => (int) $row['id'],
            'kind' => $kind,
            'site_id' => (int) $row['site_id'],
            'actor_iam_user_id' => isset($row['actor_iam_user_id']) ? (int) $row['actor_iam_user_id'] : null,
            'entity_type' => (string) $row['entity_type'],
            'entity_id' => (int) $row['entity_id'],
            'related_company_id' => isset($row['related_company_id']) ? (int) $row['related_company_id'] : null,
            'related_contact_id' => isset($row['related_contact_id']) ? (int) $row['related_contact_id'] : null,
            'action' => (string) $row['action'],
            'summary' => (string) $row['summary'],
            'metadata' => is_array($metadata) ? $metadata : [],
            'created_at' => (string) $row['created_at'],
        ];
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
