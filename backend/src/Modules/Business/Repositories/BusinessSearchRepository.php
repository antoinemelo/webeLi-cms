<?php

declare(strict_types=1);

namespace App\Modules\Business\Repositories;

final class BusinessSearchRepository extends BusinessRepositoryBase
{
    /** @return array{relations:list<array<string,mixed>>,memos:list<array<string,mixed>>,messages:list<array<string,mixed>>,future_documents:list<array<string,mixed>>} */
    public function search(int $siteId, string $query, array $options = []): array
    {
        $siteId = $this->requireSiteId($siteId);
        $query = trim($query);
        $limit = $this->limit((int) ($options['limit'] ?? 8), 25);
        $includeMemos = (bool) ($options['include_memos'] ?? false);
        $includeMessages = (bool) ($options['include_messages'] ?? false);

        if ($query === '') {
            return ['relations' => [], 'memos' => [], 'messages' => [], 'future_documents' => []];
        }

        return [
            'relations' => $this->searchRelations($siteId, $query, $limit),
            'memos' => $includeMemos ? $this->searchMemos($siteId, $query, $limit) : [],
            'messages' => $includeMessages ? $this->searchMessages($siteId, $query, $limit) : [],
            'future_documents' => [],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function searchRelations(int $siteId, string $query, int $limit): array
    {
        $q = '%' . strtolower($query) . '%';
        $rows = $this->database()->all(
            "SELECT * FROM (
                SELECT
                    'contact' AS type,
                    c.id,
                    c.display_name AS title,
                    COALESCE(c.email, c.mobile, c.phone, co.name, '') AS subtitle,
                    c.status,
                    c.email,
                    c.phone,
                    c.mobile,
                    co.id AS company_id,
                    co.name AS company_name,
                    COALESCE(c.updated_at, c.created_at) AS sort_date,
                    (SELECT group_concat(t.label, ', ')
                       FROM business_tag_links tl
                       JOIN business_tags t ON t.id = tl.tag_id
                      WHERE tl.contact_id = c.id AND t.archived_at IS NULL) AS tags,
                    (SELECT substr(m.body, 1, 180)
                       FROM crm_memos m
                      WHERE m.site_id = c.site_id AND m.contact_id = c.id AND m.archived_at IS NULL
                      ORDER BY COALESCE(m.updated_at, m.created_at) DESC, m.id DESC LIMIT 1) AS excerpt
                FROM business_contacts c
                JOIN business_companies co ON co.id = c.company_id
                WHERE c.site_id = :site_id AND c.archived_at IS NULL AND (
                    lower(c.first_name) LIKE :q OR lower(c.last_name) LIKE :q OR lower(c.display_name) LIKE :q
                    OR lower(c.email) LIKE :q OR lower(c.phone) LIKE :q OR lower(c.mobile) LIKE :q
                    OR lower(c.job_title) LIKE :q OR lower(c.status) LIKE :q OR lower(c.notes) LIKE :q
                    OR lower(co.name) LIKE :q OR lower(co.email) LIKE :q OR lower(co.phone) LIKE :q OR lower(co.address_json) LIKE :q
                    OR EXISTS (
                        SELECT 1 FROM business_tag_links tl
                        JOIN business_tags t ON t.id = tl.tag_id
                        WHERE tl.contact_id = c.id AND t.archived_at IS NULL AND lower(t.label) LIKE :q
                    )
                    OR EXISTS (
                        SELECT 1 FROM crm_memos m
                        WHERE m.site_id = c.site_id AND m.contact_id = c.id AND m.archived_at IS NULL
                          AND (lower(m.title) LIKE :q OR lower(m.body) LIKE :q)
                    )
                )
                UNION ALL
                SELECT
                    'company' AS type,
                    co.id,
                    co.name AS title,
                    COALESCE(co.email, co.phone, json_extract(co.address_json, '$.city'), '') AS subtitle,
                    co.status,
                    co.email,
                    co.phone,
                    NULL AS mobile,
                    co.id AS company_id,
                    co.name AS company_name,
                    COALESCE(co.updated_at, co.created_at) AS sort_date,
                    (SELECT group_concat(t.label, ', ')
                       FROM business_tag_links tl
                       JOIN business_tags t ON t.id = tl.tag_id
                      WHERE tl.company_id = co.id AND t.archived_at IS NULL) AS tags,
                    (SELECT substr(m.body, 1, 180)
                       FROM crm_memos m
                      WHERE m.site_id = co.site_id AND m.company_id = co.id AND m.archived_at IS NULL
                      ORDER BY COALESCE(m.updated_at, m.created_at) DESC, m.id DESC LIMIT 1) AS excerpt
                FROM business_companies co
                WHERE co.site_id = :site_id AND co.archived_at IS NULL AND co.company_kind <> 'system_individuals' AND (
                    lower(co.name) LIKE :q OR lower(co.email) LIKE :q OR lower(co.phone) LIKE :q
                    OR lower(co.website_url) LIKE :q OR lower(co.status) LIKE :q OR lower(co.notes) LIKE :q
                    OR lower(co.address_json) LIKE :q
                    OR EXISTS (
                        SELECT 1 FROM business_tag_links tl
                        JOIN business_tags t ON t.id = tl.tag_id
                        WHERE tl.company_id = co.id AND t.archived_at IS NULL AND lower(t.label) LIKE :q
                    )
                    OR EXISTS (
                        SELECT 1 FROM crm_memos m
                        WHERE m.site_id = co.site_id AND m.company_id = co.id AND m.archived_at IS NULL
                          AND (lower(m.title) LIKE :q OR lower(m.body) LIKE :q)
                    )
                )
             ) results
             ORDER BY sort_date DESC, lower(title)
             LIMIT " . $limit,
            ['site_id' => $siteId, 'q' => $q]
        );

        return array_map(fn(array $row): array => $this->resultRow($row, 'relation'), $rows);
    }

    /** @return list<array<string,mixed>> */
    private function searchMemos(int $siteId, string $query, int $limit): array
    {
        $q = '%' . strtolower($query) . '%';
        $rows = $this->database()->all(
            "SELECT
                'memo' AS type,
                m.id,
                m.title,
                substr(m.body, 1, 220) AS excerpt,
                m.company_id,
                m.contact_id,
                COALESCE(c.display_name, co.name, '') AS subtitle,
                COALESCE(m.updated_at, m.created_at) AS sort_date
             FROM crm_memos m
             LEFT JOIN business_contacts c ON c.id = m.contact_id AND c.site_id = m.site_id
             LEFT JOIN business_companies co ON co.id = m.company_id AND co.site_id = m.site_id
             WHERE m.site_id = :site_id AND m.archived_at IS NULL
               AND (lower(m.title) LIKE :q OR lower(m.body) LIKE :q OR lower(c.display_name) LIKE :q OR lower(co.name) LIKE :q)
             ORDER BY sort_date DESC, m.id DESC
             LIMIT " . $limit,
            ['site_id' => $siteId, 'q' => $q]
        );

        return array_map(fn(array $row): array => $this->resultRow($row, 'memo'), $rows);
    }

    /** @return list<array<string,mixed>> */
    private function searchMessages(int $siteId, string $query, int $limit): array
    {
        $q = '%' . strtolower($query) . '%';
        $rows = $this->database()->all(
            "SELECT
                'message' AS type,
                mo.id,
                COALESCE(mo.subject, 'Message ' || mo.channel) AS title,
                substr(mo.body_text, 1, 220) AS excerpt,
                mo.channel,
                mo.status,
                mo.contact_id,
                c.company_id,
                c.display_name AS subtitle,
                COALESCE(mo.updated_at, mo.created_at) AS sort_date
             FROM crm_message_outbox mo
             LEFT JOIN business_contacts c ON c.id = mo.contact_id AND c.site_id = mo.site_id
             WHERE mo.site_id = :site_id AND (
                lower(mo.subject) LIKE :q OR lower(mo.body_text) LIKE :q OR lower(mo.recipient_value) LIKE :q
                OR lower(mo.channel) LIKE :q OR lower(mo.status) LIKE :q OR lower(c.display_name) LIKE :q
             )
             ORDER BY sort_date DESC, mo.id DESC
             LIMIT " . $limit,
            ['site_id' => $siteId, 'q' => $q]
        );

        return array_map(fn(array $row): array => $this->resultRow($row, 'message'), $rows);
    }

    /** @return array<string,mixed> */
    private function resultRow(array $row, string $group): array
    {
        $row = $this->castRow($row);
        $row['group'] = $group;
        return $row;
    }
}
