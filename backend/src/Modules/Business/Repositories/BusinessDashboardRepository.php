<?php

declare(strict_types=1);

namespace App\Modules\Business\Repositories;

final class BusinessDashboardRepository extends BusinessRepositoryBase
{
    /** @return array<string,mixed> */
    public function dashboard(int $siteId, array $options = []): array
    {
        $siteId = $this->requireSiteId($siteId);
        $includeMemos = (bool) ($options['include_memos'] ?? false);
        $includeMessages = (bool) ($options['include_messages'] ?? false);

        return [
            'counters' => $this->counters($siteId),
            'recent_relations' => $this->recentRelations($siteId),
            'latest_memos' => $includeMemos ? $this->latestMemos($siteId) : [],
            'latest_messages' => $includeMessages ? $this->latestMessages($siteId) : [],
            'alerts' => $this->alerts($siteId, $includeMessages),
        ];
    }

    /** @return array<string,int> */
    private function counters(int $siteId): array
    {
        $counts = [
            'relations' => 0,
            'companies' => 0,
            'contacts' => 0,
            'prospect' => 0,
            'client' => 0,
            'supplier' => 0,
            'former_client' => 0,
            'other' => 0,
        ];

        $rows = $this->database()->all(
            "SELECT status, COUNT(*) AS count FROM (
                SELECT status FROM business_contacts WHERE site_id = :site_id AND archived_at IS NULL
                UNION ALL
                SELECT status FROM business_companies WHERE site_id = :site_id AND archived_at IS NULL AND company_kind <> 'system_individuals'
             ) grouped
             GROUP BY status",
            ['site_id' => $siteId]
        );
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int) ($row['count'] ?? 0);
            }
            $counts['relations'] += (int) ($row['count'] ?? 0);
        }

        $counts['companies'] = (int) ($this->database()->one(
            "SELECT COUNT(*) AS count FROM business_companies WHERE site_id = :site_id AND archived_at IS NULL AND company_kind <> 'system_individuals'",
            ['site_id' => $siteId]
        )['count'] ?? 0);
        $counts['contacts'] = (int) ($this->database()->one(
            'SELECT COUNT(*) AS count FROM business_contacts WHERE site_id = :site_id AND archived_at IS NULL',
            ['site_id' => $siteId]
        )['count'] ?? 0);

        return $counts;
    }

    /** @return list<array<string,mixed>> */
    private function recentRelations(int $siteId): array
    {
        $rows = $this->database()->all(
            "SELECT * FROM (
                SELECT 'contact' AS type, c.id, c.display_name, c.status, c.email AS primary_email, c.phone, c.mobile, co.id AS company_id, co.name AS company_name, COALESCE(c.updated_at, c.created_at) AS activity_at
                FROM business_contacts c
                JOIN business_companies co ON co.id = c.company_id
                WHERE c.site_id = :site_id AND c.archived_at IS NULL
                UNION ALL
                SELECT 'company' AS type, co.id, co.name AS display_name, co.status, co.email AS primary_email, co.phone, NULL AS mobile, co.id AS company_id, co.name AS company_name, COALESCE(co.updated_at, co.created_at) AS activity_at
                FROM business_companies co
                WHERE co.site_id = :site_id AND co.archived_at IS NULL AND co.company_kind <> 'system_individuals'
             ) relations
             ORDER BY activity_at DESC, lower(display_name)
             LIMIT 8",
            ['site_id' => $siteId]
        );

        return array_map(fn(array $row): array => $this->relationRow($row), $rows);
    }

    /** @return list<array<string,mixed>> */
    private function latestMemos(int $siteId): array
    {
        $rows = $this->database()->all(
            "SELECT m.id, m.title, substr(m.body, 1, 180) AS excerpt, m.company_id, m.contact_id, COALESCE(c.display_name, co.name, '') AS relation_name, COALESCE(m.updated_at, m.created_at) AS activity_at
             FROM crm_memos m
             LEFT JOIN business_contacts c ON c.id = m.contact_id AND c.site_id = m.site_id
             LEFT JOIN business_companies co ON co.id = m.company_id AND co.site_id = m.site_id
             WHERE m.site_id = :site_id AND m.archived_at IS NULL
             ORDER BY activity_at DESC, m.id DESC
             LIMIT 6",
            ['site_id' => $siteId]
        );

        return array_map(fn(array $row): array => $this->castRow($row), $rows);
    }

    /** @return list<array<string,mixed>> */
    private function latestMessages(int $siteId): array
    {
        $rows = $this->database()->all(
            "SELECT mo.id, mo.channel, mo.status, COALESCE(mo.subject, 'Message ' || mo.channel) AS subject, substr(mo.body_text, 1, 180) AS excerpt, mo.contact_id, c.company_id, c.display_name AS relation_name, COALESCE(mo.updated_at, mo.created_at) AS activity_at, mo.last_error
             FROM crm_message_outbox mo
             LEFT JOIN business_contacts c ON c.id = mo.contact_id AND c.site_id = mo.site_id
             WHERE mo.site_id = :site_id
             ORDER BY activity_at DESC, mo.id DESC
             LIMIT 6",
            ['site_id' => $siteId]
        );

        return array_map(fn(array $row): array => $this->castRow($row), $rows);
    }

    /** @return list<array<string,mixed>> */
    private function alerts(int $siteId, bool $includeMessages): array
    {
        $alerts = [];
        if ($includeMessages) {
            foreach (['email', 'whatsapp', 'telegram'] as $channel) {
                $providerCount = (int) ($this->database()->one(
                    "SELECT COUNT(*) AS count FROM crm_messaging_providers
                     WHERE (site_id = :site_id OR site_id IS NULL) AND channel = :channel AND is_enabled = 1 AND is_default = 1",
                    ['site_id' => $siteId, 'channel' => $channel]
                )['count'] ?? 0);
                if ($providerCount === 0) {
                    $alerts[] = [
                        'level' => $channel === 'email' ? 'info' : 'warning',
                        'code' => 'business.messaging_provider_missing',
                        'message' => 'Provider ' . $channel . ' non configuré par défaut.',
                        'channel' => $channel,
                    ];
                }
            }
        }

        $missingConsent = (int) ($this->database()->one(
            "SELECT COUNT(*) AS count
             FROM crm_contact_channels ch
             JOIN business_contacts c ON c.id = ch.contact_id
             LEFT JOIN crm_consents cs ON cs.contact_id = ch.contact_id AND cs.channel = ch.channel
             WHERE c.site_id = :site_id AND c.archived_at IS NULL AND ch.archived_at IS NULL
               AND COALESCE(cs.consent_status, 'unknown') <> 'opt_in'",
            ['site_id' => $siteId]
        )['count'] ?? 0);
        if ($missingConsent > 0) {
            $alerts[] = [
                'level' => 'warning',
                'code' => 'business.messaging_consent_missing',
                'message' => $missingConsent . ' canal(aux) de contact sans consentement opt-in.',
                'count' => $missingConsent,
            ];
        }

        return $alerts;
    }

    /** @return array<string,mixed> */
    private function relationRow(array $row): array
    {
        $row = $this->castRow($row);
        $row['company'] = [
            'id' => (int) ($row['company_id'] ?? 0),
            'name' => (string) ($row['company_name'] ?? ''),
        ];
        return $row;
    }
}
