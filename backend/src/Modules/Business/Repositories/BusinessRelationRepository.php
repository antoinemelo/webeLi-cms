<?php

declare(strict_types=1);

namespace App\Modules\Business\Repositories;

use InvalidArgumentException;

final class BusinessRelationRepository extends BusinessRepositoryBase
{
    /** @return array{items:list<array<string,mixed>>,limit:int,offset:int,total:int} */
    public function list(int $siteId, array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $siteId = $this->requireSiteId($siteId);
        $limit = $this->limit($limit, 10000);
        $offset = $this->offset($offset);
        $type = $this->type(($filters['type'] ?? '') !== '' ? $filters['type'] : ($filters['kind'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $status = $this->crmStatus($status);
        }
        $q = strtolower(trim((string) ($filters['q'] ?? '')));
        $rows = [];
        if ($type === '' || $type === 'contact') {
            $rows = array_merge($rows, $this->contacts($siteId, $q, $status, null, $filters));
        }
        if ($type === '' || $type === 'company') {
            $rows = array_merge($rows, $this->companies($siteId, $q, $status, null, $filters));
        }

        $sort = (string) ($filters['sort'] ?? 'activity_desc');
        usort($rows, static function (array $a, array $b) use ($sort): int {
            return match ($sort) {
                'name_asc' => strcasecmp((string) $a['display_name'], (string) $b['display_name']) ?: ((int) $a['id'] <=> (int) $b['id']),
                'created_desc' => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')) ?: strcasecmp((string) $a['display_name'], (string) $b['display_name']),
                'status_asc' => strcasecmp((string) $a['status'], (string) $b['status']) ?: strcasecmp((string) $a['display_name'], (string) $b['display_name']),
                default => strcmp((string) ($b['last_activity_at'] ?? ''), (string) ($a['last_activity_at'] ?? '')) ?: strcasecmp((string) $a['display_name'], (string) $b['display_name']),
            };
        });

        $total = count($rows);
        return [
            'items' => array_slice($rows, $offset, $limit),
            'limit' => $limit,
            'offset' => $offset,
            'total' => $total,
        ];
    }

    public function find(int $siteId, string $type, int $id, bool $includeArchived = false): ?array
    {
        $type = $this->type($type);
        if ($type === '') {
            throw new InvalidArgumentException('business.relation_type_invalid');
        }
        $filters = $includeArchived ? ['archived' => 'all'] : [];
        $items = $type === 'contact'
            ? $this->contacts($this->requireSiteId($siteId), '', '', $id, $filters)
            : $this->companies($this->requireSiteId($siteId), '', '', $id, $filters);
        return $items[0] ?? null;
    }

    /** @return list<array<string,mixed>> */
    private function contacts(int $siteId, string $q, string $status, ?int $id = null, array $filters = []): array
    {
        $includeArchived = (string) ($filters['archived'] ?? '') === 'all';
        $onlyArchived = (string) ($filters['archived'] ?? '') === 'archived';
        $where = ['c.site_id = :site_id'];
        $params = ['site_id' => $siteId];
        if ($onlyArchived) {
            $where[] = 'c.archived_at IS NOT NULL';
        } elseif (!$includeArchived) {
            $where[] = 'c.archived_at IS NULL';
        }
        if ($id !== null) {
            $where[] = 'c.id = :id';
            $params['id'] = $id;
        }
        if ($status !== '') {
            $where[] = 'c.status = :status';
            $params['status'] = $status;
        }
        if ($q !== '') {
            $where[] = '(c.normalized_name LIKE :q OR c.first_name LIKE :q OR c.last_name LIKE :q OR c.email LIKE :q OR c.mobile LIKE :q OR c.phone LIKE :q OR c.job_title LIKE :q OR c.status LIKE :q OR c.notes LIKE :q OR co.normalized_name LIKE :q OR co.email LIKE :q OR co.phone LIKE :q OR co.address_json LIKE :q OR EXISTS (SELECT 1 FROM business_tag_links tl JOIN business_tags t ON t.id = tl.tag_id WHERE tl.contact_id = c.id AND t.archived_at IS NULL AND lower(t.label) LIKE :q) OR EXISTS (SELECT 1 FROM crm_memos m WHERE m.site_id = c.site_id AND m.contact_id = c.id AND m.archived_at IS NULL AND (lower(m.title) LIKE :q OR lower(m.body) LIKE :q)))';
            $params['q'] = '%' . $q . '%';
        }
        if (trim((string) ($filters['tag'] ?? '')) !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM business_tag_links tl JOIN business_tags t ON t.id = tl.tag_id WHERE tl.contact_id = c.id AND t.archived_at IS NULL AND lower(t.label) = :tag)';
            $params['tag'] = strtolower(trim((string) $filters['tag']));
        }
        if (trim((string) ($filters['updated_after'] ?? '')) !== '') {
            $where[] = 'COALESCE(c.updated_at, c.created_at) >= :updated_after';
            $params['updated_after'] = trim((string) $filters['updated_after']);
        }
        if (($filters['has_memos'] ?? null) === true) {
            $where[] = 'EXISTS (SELECT 1 FROM crm_memos m WHERE m.site_id = c.site_id AND m.contact_id = c.id AND m.archived_at IS NULL)';
        }
        if (($filters['has_shared_memos'] ?? null) === true) {
            $where[] = 'EXISTS (SELECT 1 FROM crm_memos m JOIN crm_memo_shares s ON s.memo_id = m.id AND s.revoked_at IS NULL WHERE m.site_id = c.site_id AND m.contact_id = c.id AND m.archived_at IS NULL)';
        }
        if (($filters['has_email'] ?? null) === true) {
            $where[] = "c.email IS NOT NULL AND trim(c.email) <> ''";
        }
        if (($filters['has_phone'] ?? null) === true) {
            $where[] = "((c.phone IS NOT NULL AND trim(c.phone) <> '') OR (c.mobile IS NOT NULL AND trim(c.mobile) <> ''))";
        }
        if (($filters['missing_email_consent'] ?? null) === true) {
            $where[] = "c.email IS NOT NULL AND trim(c.email) <> '' AND NOT EXISTS (SELECT 1 FROM crm_consents cs WHERE cs.contact_id = c.id AND cs.channel = 'email' AND cs.consent_status = 'opt_in')";
        }
        if (($filters['linked_iam'] ?? null) === true) {
            $where[] = 'c.iam_user_id IS NOT NULL';
        }
        $rows = $this->database()->all(
            "SELECT
                'contact' AS type,
                c.id,
                c.created_at,
                c.updated_at,
                c.archived_at,
                c.created_by_iam_user_id,
                c.updated_by_iam_user_id,
                c.iam_user_id,
                c.display_name,
                c.email AS primary_email,
                c.phone,
                c.mobile,
                c.status,
                (SELECT group_concat(t.label, ', ') FROM business_tag_links tl JOIN business_tags t ON t.id = tl.tag_id WHERE tl.target_type = 'contact' AND tl.contact_id = c.id AND t.archived_at IS NULL) AS tag_labels,
                COALESCE((SELECT cs.consent_status FROM crm_consents cs WHERE cs.contact_id = c.id AND cs.channel = 'email' LIMIT 1), 'unknown') AS email_consent_status,
                co.id AS company_id,
                co.name AS company_name,
                CASE WHEN co.company_kind = 'system_individuals' THEN 1 ELSE 0 END AS company_is_system_individuals,
                0 AS linked_contacts_count,
                (SELECT COUNT(*) FROM crm_memos m WHERE m.site_id = c.site_id AND m.contact_id = c.id AND m.archived_at IS NULL) AS memo_count,
                (SELECT COUNT(DISTINCT m.id) FROM crm_memos m JOIN crm_memo_shares s ON s.memo_id = m.id AND s.revoked_at IS NULL WHERE m.site_id = c.site_id AND m.contact_id = c.id AND m.archived_at IS NULL) AS shared_memo_count,
                COALESCE(
                    (SELECT MAX(COALESCE(m.updated_at, m.created_at)) FROM crm_memos m WHERE m.site_id = c.site_id AND m.contact_id = c.id AND m.archived_at IS NULL),
                    COALESCE(c.updated_at, c.created_at)
                ) AS last_activity_at,
                (SELECT substr(m.body, 1, 160) FROM crm_memos m WHERE m.site_id = c.site_id AND m.contact_id = c.id AND m.archived_at IS NULL ORDER BY COALESCE(m.updated_at, m.created_at) DESC, m.id DESC LIMIT 1) AS last_memo_excerpt
             FROM business_contacts c
             JOIN business_companies co ON co.id = c.company_id
             WHERE " . implode(' AND ', $where),
            $params
        );
        return array_map(fn(array $row): array => $this->relation($row), $rows);
    }

    /** @return list<array<string,mixed>> */
    private function companies(int $siteId, string $q, string $status, ?int $id = null, array $filters = []): array
    {
        $includeArchived = (string) ($filters['archived'] ?? '') === 'all';
        $onlyArchived = (string) ($filters['archived'] ?? '') === 'archived';
        $where = ['co.site_id = :site_id'];
        $params = ['site_id' => $siteId];
        if ($onlyArchived) {
            $where[] = 'co.archived_at IS NOT NULL';
        } elseif (!$includeArchived) {
            $where[] = 'co.archived_at IS NULL';
        }
        if ($id !== null) {
            $where[] = 'co.id = :id';
            $params['id'] = $id;
        } else {
            $where[] = "co.company_kind <> 'system_individuals'";
        }
        if ($status !== '') {
            $where[] = 'co.status = :status';
            $params['status'] = $status;
        }
        if ($q !== '') {
            $where[] = '(co.normalized_name LIKE :q OR co.email LIKE :q OR co.phone LIKE :q OR co.website_url LIKE :q OR co.status LIKE :q OR co.notes LIKE :q OR co.address_json LIKE :q OR EXISTS (SELECT 1 FROM business_tag_links tl JOIN business_tags t ON t.id = tl.tag_id WHERE tl.company_id = co.id AND t.archived_at IS NULL AND lower(t.label) LIKE :q) OR EXISTS (SELECT 1 FROM crm_memos m WHERE m.site_id = co.site_id AND m.company_id = co.id AND m.archived_at IS NULL AND (lower(m.title) LIKE :q OR lower(m.body) LIKE :q)))';
            $params['q'] = '%' . $q . '%';
        }
        if (trim((string) ($filters['tag'] ?? '')) !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM business_tag_links tl JOIN business_tags t ON t.id = tl.tag_id WHERE tl.company_id = co.id AND t.archived_at IS NULL AND lower(t.label) = :tag)';
            $params['tag'] = strtolower(trim((string) $filters['tag']));
        }
        if (trim((string) ($filters['updated_after'] ?? '')) !== '') {
            $where[] = 'COALESCE(co.updated_at, co.created_at) >= :updated_after';
            $params['updated_after'] = trim((string) $filters['updated_after']);
        }
        if (($filters['has_memos'] ?? null) === true) {
            $where[] = 'EXISTS (SELECT 1 FROM crm_memos m WHERE m.site_id = co.site_id AND m.company_id = co.id AND m.archived_at IS NULL)';
        }
        if (($filters['has_shared_memos'] ?? null) === true) {
            $where[] = 'EXISTS (SELECT 1 FROM crm_memos m JOIN crm_memo_shares s ON s.memo_id = m.id AND s.revoked_at IS NULL WHERE m.site_id = co.site_id AND m.company_id = co.id AND m.archived_at IS NULL)';
        }
        if (($filters['has_email'] ?? null) === true) {
            $where[] = "co.email IS NOT NULL AND trim(co.email) <> ''";
        }
        if (($filters['has_phone'] ?? null) === true) {
            $where[] = "co.phone IS NOT NULL AND trim(co.phone) <> ''";
        }
        if (($filters['missing_email_consent'] ?? null) === true) {
            $where[] = '0 = 1';
        }
        if (($filters['linked_iam'] ?? null) === true) {
            $where[] = 'EXISTS (SELECT 1 FROM business_contacts c WHERE c.site_id = co.site_id AND c.company_id = co.id AND c.iam_user_id IS NOT NULL AND c.archived_at IS NULL)';
        }
        $rows = $this->database()->all(
            "SELECT
                'company' AS type,
                co.id,
                co.created_at,
                co.updated_at,
                co.archived_at,
                co.created_by_iam_user_id,
                co.updated_by_iam_user_id,
                NULL AS iam_user_id,
                co.name AS display_name,
                co.email AS primary_email,
                co.phone,
                NULL AS mobile,
                co.website_url,
                co.status,
                (SELECT group_concat(t.label, ', ') FROM business_tag_links tl JOIN business_tags t ON t.id = tl.tag_id WHERE tl.target_type = 'company' AND tl.company_id = co.id AND t.archived_at IS NULL) AS tag_labels,
                NULL AS email_consent_status,
                co.id AS company_id,
                co.name AS company_name,
                CASE WHEN co.company_kind = 'system_individuals' THEN 1 ELSE 0 END AS company_is_system_individuals,
                (SELECT COUNT(*) FROM business_contacts c WHERE c.site_id = co.site_id AND c.company_id = co.id AND c.archived_at IS NULL) AS linked_contacts_count,
                (SELECT COUNT(*) FROM crm_memos m WHERE m.site_id = co.site_id AND m.company_id = co.id AND m.archived_at IS NULL) AS memo_count,
                (SELECT COUNT(DISTINCT m.id) FROM crm_memos m JOIN crm_memo_shares s ON s.memo_id = m.id AND s.revoked_at IS NULL WHERE m.site_id = co.site_id AND m.company_id = co.id AND m.archived_at IS NULL) AS shared_memo_count,
                COALESCE(
                    (SELECT MAX(COALESCE(m.updated_at, m.created_at)) FROM crm_memos m WHERE m.site_id = co.site_id AND m.company_id = co.id AND m.archived_at IS NULL),
                    COALESCE(co.updated_at, co.created_at)
                ) AS last_activity_at,
                (SELECT substr(m.body, 1, 160) FROM crm_memos m WHERE m.site_id = co.site_id AND m.company_id = co.id AND m.archived_at IS NULL ORDER BY COALESCE(m.updated_at, m.created_at) DESC, m.id DESC LIMIT 1) AS last_memo_excerpt
             FROM business_companies co
             WHERE " . implode(' AND ', $where),
            $params
        );
        return array_map(fn(array $row): array => $this->relation($row), $rows);
    }

    private function type(mixed $value): string
    {
        $type = trim((string) $value);
        if ($type === '') {
            return '';
        }
        if ($type === 'individual') {
            return 'contact';
        }
        if (!in_array($type, ['contact', 'company'], true)) {
            throw new InvalidArgumentException('business.relation_type_invalid');
        }
        return $type;
    }

    /** @return array<string,mixed> */
    private function relation(array $row): array
    {
        return [
            'type' => (string) $row['type'],
            'id' => (int) $row['id'],
            'display_name' => (string) $row['display_name'],
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'archived_at' => $row['archived_at'] ?? null,
            'created_by_iam_user_id' => isset($row['created_by_iam_user_id']) ? (int) $row['created_by_iam_user_id'] : null,
            'updated_by_iam_user_id' => isset($row['updated_by_iam_user_id']) ? (int) $row['updated_by_iam_user_id'] : null,
            'iam_user_id' => $row['iam_user_id'] ?? null,
            'primary_email' => $row['primary_email'] ?? null,
            'website_url' => $row['website_url'] ?? null,
            'company' => [
                'id' => (int) $row['company_id'],
                'name' => (string) $row['company_name'],
                'is_system_individuals' => (bool) ((int) ($row['company_is_system_individuals'] ?? 0)),
            ],
            'phone' => $row['phone'] ?? null,
            'mobile' => $row['mobile'] ?? null,
            'status' => (string) $row['status'],
            'tags' => $this->tagLabels($row['tag_labels'] ?? null),
            'email_consent_status' => $row['email_consent_status'] ?? null,
            'memo_count' => (int) ($row['memo_count'] ?? 0),
            'shared_memo_count' => (int) ($row['shared_memo_count'] ?? 0),
            'linked_contacts_count' => (int) ($row['linked_contacts_count'] ?? 0),
            'last_activity_at' => $row['last_activity_at'] ?? null,
            'last_memo_excerpt' => $row['last_memo_excerpt'] ?? null,
        ];
    }

    /** @return list<string> */
    private function tagLabels(mixed $value): array
    {
        $labels = array_map('trim', explode(',', (string) ($value ?? '')));
        return array_values(array_filter($labels, static fn(string $label): bool => $label !== ''));
    }
}
