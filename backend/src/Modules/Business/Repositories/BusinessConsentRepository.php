<?php

declare(strict_types=1);

namespace App\Modules\Business\Repositories;

use InvalidArgumentException;

final class BusinessConsentRepository extends BusinessRepositoryBase
{
    /** @return array{items:list<array<string,mixed>>,limit:int,offset:int} */
    public function list(int $siteId, int $limit = 50, int $offset = 0, string $channel = '', string $status = ''): array
    {
        $siteId = $this->requireSiteId($siteId);
        $limit = $this->limit($limit);
        $offset = $this->offset($offset);
        $where = ['c.site_id = :site_id'];
        $params = ['site_id' => $siteId];
        if (trim($channel) !== '') {
            $where[] = 'co.channel = :channel';
            $params['channel'] = $this->channel($channel);
        }
        if (trim($status) !== '') {
            $where[] = 'co.consent_status = :status';
            $params['status'] = $this->status($status);
        }
        $rows = $this->database()->all(
            'SELECT co.*, c.display_name AS contact_name, c.email AS contact_email, c.company_id
             FROM crm_consents co
             JOIN business_contacts c ON c.id = co.contact_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY co.updated_at DESC, co.created_at DESC, co.id DESC
             LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );
        return ['items' => array_map(fn(array $row): array => $this->castRow($row), $rows), 'limit' => $limit, 'offset' => $offset];
    }

    /** @return list<array<string,mixed>> */
    public function channels(int $contactId, bool $includeArchived = false): array
    {
        $rows = $this->database()->all(
            'SELECT * FROM crm_contact_channels WHERE contact_id = :contact_id' . ($includeArchived ? '' : ' AND archived_at IS NULL') . ' ORDER BY is_primary DESC, id',
            ['contact_id' => $contactId]
        );
        return array_map(fn(array $row): array => $this->castRow($row), $rows);
    }

    public function upsertChannel(int $contactId, string $channel, string $value, string $normalizedValue, bool $isPrimary = true, bool $isVerified = false, ?int $actorId = null): array
    {
        if ($contactId < 1) {
            throw new InvalidArgumentException('business.contact_id_invalid');
        }
        $channel = $this->channel($channel);
        $value = $this->text($value, 'channel_value', 255);
        $normalizedValue = $this->text($normalizedValue, 'normalized_value', 255);
        if ($isPrimary) {
            $this->database()->run(
                'UPDATE crm_contact_channels SET is_primary = 0, updated_by_iam_user_id = :actor, updated_at = CURRENT_TIMESTAMP WHERE contact_id = :contact_id AND channel = :channel AND archived_at IS NULL',
                ['contact_id' => $contactId, 'channel' => $channel, 'actor' => $actorId]
            );
        }
        $this->database()->run(
            'INSERT INTO crm_contact_channels(contact_id, channel, channel_value, normalized_value, is_primary, is_verified, created_by_iam_user_id, updated_by_iam_user_id)
             VALUES(:contact_id, :channel, :value, :normalized, :is_primary, :is_verified, :actor, :actor)
             ON CONFLICT(contact_id, channel, normalized_value) DO UPDATE SET channel_value = excluded.channel_value, is_primary = excluded.is_primary, is_verified = excluded.is_verified, updated_by_iam_user_id = excluded.updated_by_iam_user_id, updated_at = CURRENT_TIMESTAMP, archived_at = NULL',
            [
                'contact_id' => $contactId,
                'channel' => $channel,
                'value' => $value,
                'normalized' => $normalizedValue,
                'is_primary' => $this->boolInt($isPrimary),
                'is_verified' => $this->boolInt($isVerified),
                'actor' => $actorId,
            ]
        );
        $row = $this->database()->one(
            'SELECT * FROM crm_contact_channels WHERE contact_id = :contact_id AND channel = :channel AND normalized_value = :normalized LIMIT 1',
            ['contact_id' => $contactId, 'channel' => $channel, 'normalized' => $normalizedValue]
        );
        return $row ? $this->castRow($row) : [];
    }

    public function primaryChannel(int $contactId, string $channel): ?array
    {
        $row = $this->database()->one(
            'SELECT * FROM crm_contact_channels WHERE contact_id = :contact_id AND channel = :channel AND is_primary = 1 AND archived_at IS NULL LIMIT 1',
            ['contact_id' => $contactId, 'channel' => $this->channel($channel)]
        );
        return $row ? $this->castRow($row) : null;
    }

    /**
     * Returns a CRM contact only when one verified channel matches inside the site.
     * Zero or several matches deliberately remain unresolved for operator review.
     *
     * @return array<string,mixed>|null
     */
    public function uniqueVerifiedContactByChannel(int $siteId, string $channel, string $normalizedValue): ?array
    {
        $siteId = $this->requireSiteId($siteId);
        $channel = $this->channel($channel);
        $normalizedValue = $this->text(strtolower(trim($normalizedValue)), 'normalized_value', 255);
        $rows = $this->database()->all(
            'SELECT c.* FROM crm_contact_channels cc
             JOIN business_contacts c ON c.id = cc.contact_id
             WHERE c.site_id = :site_id AND c.archived_at IS NULL
               AND cc.channel = :channel AND cc.normalized_value = :normalized
               AND cc.is_verified = 1 AND cc.archived_at IS NULL
             ORDER BY c.id LIMIT 2',
            ['site_id' => $siteId, 'channel' => $channel, 'normalized' => $normalizedValue]
        );
        return count($rows) === 1 ? $this->castRow($rows[0]) : null;
    }

    public function upsertConsent(int $contactId, string $channel, string $status, string $source = 'manual', ?string $evidence = null, ?int $actorId = null, string $purpose = 'marketing', int $retentionDays = 2190): array
    {
        if ($contactId < 1) {
            throw new InvalidArgumentException('business.contact_id_invalid');
        }
        $channel = $this->channel($channel);
        $status = $this->status($status);
        if (!in_array($source, ['manual', 'form', 'import', 'unsubscribe', 'api'], true)) {
            throw new InvalidArgumentException('business.consent_source_invalid');
        }
        if ($purpose !== 'marketing') {
            throw new InvalidArgumentException('business.consent_purpose_invalid');
        }
        $retentionDays = max(365, min(3650, $retentionDays));
        return $this->database()->transaction(function () use ($contactId, $channel, $status, $source, $evidence, $actorId, $purpose, $retentionDays): array {
        $grantedAt = $status === 'opt_in' ? date('Y-m-d H:i:s') : null;
        $revokedAt = $status === 'opt_out' ? date('Y-m-d H:i:s') : null;
        $this->database()->run(
            'INSERT INTO crm_consents(contact_id, channel, consent_status, source, evidence, granted_at, revoked_at, created_by_iam_user_id, updated_by_iam_user_id)
             VALUES(:contact_id, :channel, :status, :source, :evidence, :granted_at, :revoked_at, :actor, :actor)
             ON CONFLICT(contact_id, channel) DO UPDATE SET consent_status = excluded.consent_status, source = excluded.source, evidence = excluded.evidence, granted_at = excluded.granted_at, revoked_at = excluded.revoked_at, updated_by_iam_user_id = excluded.updated_by_iam_user_id, updated_at = CURRENT_TIMESTAMP',
            [
                'contact_id' => $contactId,
                'channel' => $channel,
                'status' => $status,
                'source' => $source,
                'evidence' => $this->nullableText($evidence, 'evidence', 2000),
                'granted_at' => $grantedAt,
                'revoked_at' => $revokedAt,
                'actor' => $actorId,
            ]
        );
        $consent = $this->consentForContact($contactId, $channel) ?? [];
        if ($this->database()->tableExists('crm_consent_events')) {
            $contact = $this->database()->one('SELECT site_id FROM business_contacts WHERE id=:id', ['id' => $contactId]);
            if ($contact === null) {
                throw new InvalidArgumentException('business.contact_not_found');
            }
            $siteId = (int) $contact['site_id'];
            $eventType = match ($status) { 'opt_in' => 'granted', 'opt_out' => 'withdrawn', default => 'recorded' };
            $this->database()->run(
                'INSERT INTO crm_consent_events(consent_id,site_id,contact_id,channel,purpose,scope_type,scope_id,consent_status,event_type,source,evidence,proof_json,retention_until,actor_iam_user_id)
                 VALUES(:consent_id,:site_id,:contact_id,:channel,:purpose,\'site\',:scope_id,:status,:event_type,:source,:evidence,:proof,:retention,:actor)',
                [
                    'consent_id' => $consent['id'] ?? null,
                    'site_id' => $siteId,
                    'contact_id' => $contactId,
                    'channel' => $channel,
                    'purpose' => $purpose,
                    'scope_id' => $siteId,
                    'status' => $status,
                    'event_type' => $eventType,
                    'source' => $source,
                    'evidence' => $this->nullableText($evidence, 'evidence', 2000),
                    'proof' => json_encode(['evidence' => $evidence, 'actor_iam_user_id' => $actorId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                    'retention' => gmdate('Y-m-d H:i:s', time() + $retentionDays * 86400),
                    'actor' => $actorId,
                ]
            );
        }
        return $consent + ['purpose' => $purpose, 'scope' => 'site'];
        });
    }

    public function find(int $siteId, int $id): ?array
    {
        $row = $this->database()->one(
            'SELECT co.*, c.site_id, c.display_name AS contact_name, c.email AS contact_email, c.company_id
             FROM crm_consents co
             JOIN business_contacts c ON c.id = co.contact_id
             WHERE c.site_id = :site_id AND co.id = :id
             LIMIT 1',
            ['site_id' => $this->requireSiteId($siteId), 'id' => $id]
        );
        return $row ? $this->castRow($row) : null;
    }

    public function updateById(int $siteId, int $id, string $status, string $source = 'manual', ?string $evidence = null, ?int $actorId = null, string $purpose = 'marketing', int $retentionDays = 2190): ?array
    {
        $current = $this->find($siteId, $id);
        if ($current === null) {
            return null;
        }
        return $this->upsertConsent((int) $current['contact_id'], (string) $current['channel'], $status, $source, $evidence, $actorId, $purpose, $retentionDays);
    }

    public function consentForContact(int $contactId, string $channel): ?array
    {
        $row = $this->database()->one(
            'SELECT * FROM crm_consents WHERE contact_id = :contact_id AND channel = :channel LIMIT 1',
            ['contact_id' => $contactId, 'channel' => $this->channel($channel)]
        );
        return $row ? $this->castRow($row) : null;
    }

    public function hasOptIn(int $contactId, string $channel): bool
    {
        $row = $this->consentForContact($contactId, $channel);
        return $row !== null && ($row['consent_status'] ?? '') === 'opt_in';
    }

    /** @return list<array<string,mixed>> */
    public function consentsForContact(int $contactId): array
    {
        $rows = $this->database()->all('SELECT * FROM crm_consents WHERE contact_id = :contact_id ORDER BY channel', ['contact_id' => $contactId]);
        return array_map(fn(array $row): array => $this->castRow($row), $rows);
    }

    /** @return list<array<string,mixed>> */
    public function historyForContact(int $contactId): array
    {
        if (!$this->database()->tableExists('crm_consent_events')) {
            return [];
        }
        $rows = $this->database()->all(
            'SELECT * FROM crm_consent_events WHERE contact_id=:contact_id ORDER BY occurred_at DESC,id DESC',
            ['contact_id' => $contactId]
        );
        return array_map(fn(array $row): array => $this->castRow($row), $rows);
    }

    /** @return array<string,mixed>|null */
    public function preferenceForContact(int $siteId, int $contactId): ?array
    {
        if (!$this->database()->tableExists('crm_contact_preferences')) {
            return null;
        }
        $row = $this->database()->one('SELECT * FROM crm_contact_preferences WHERE site_id=:site_id AND contact_id=:contact_id', ['site_id' => $siteId, 'contact_id' => $contactId]);
        if ($row === null) return null;
        $row = $this->castRow($row);
        $row['do_not_contact'] = (bool) ($row['do_not_contact'] ?? false);
        return $row;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function upsertPreference(int $siteId, int $contactId, array $payload, ?int $actorId = null): array
    {
        if (!$this->database()->tableExists('crm_contact_preferences')) {
            throw new InvalidArgumentException('business.contact_preferences_unavailable');
        }
        $contact = $this->database()->one('SELECT id FROM business_contacts WHERE site_id=:site_id AND id=:id AND archived_at IS NULL', ['site_id' => $siteId, 'id' => $contactId]);
        if ($contact === null) throw new InvalidArgumentException('business.contact_not_found');
        $preferred = trim((string) ($payload['preferred_channel'] ?? ''));
        $preferred = $preferred === '' ? null : $this->channel($preferred);
        $source = strtolower(trim((string) ($payload['source'] ?? 'manual')));
        if (!in_array($source, ['manual','form','import','api'], true)) throw new InvalidArgumentException('business.preference_source_invalid');
        $this->database()->run(
            'INSERT INTO crm_contact_preferences(site_id,contact_id,preferred_channel,contact_window,do_not_contact,source,note,created_by_iam_user_id,updated_by_iam_user_id)
             VALUES(:site_id,:contact_id,:channel,:window,:do_not_contact,:source,:note,:actor,:actor)
             ON CONFLICT(site_id,contact_id) DO UPDATE SET preferred_channel=excluded.preferred_channel,contact_window=excluded.contact_window,do_not_contact=excluded.do_not_contact,source=excluded.source,note=excluded.note,updated_by_iam_user_id=excluded.updated_by_iam_user_id,updated_at=CURRENT_TIMESTAMP',
            [
                'site_id' => $siteId,
                'contact_id' => $contactId,
                'channel' => $preferred,
                'window' => $this->nullableText($payload['contact_window'] ?? null, 'contact_window', 120),
                'do_not_contact' => $this->boolInt($payload['do_not_contact'] ?? false),
                'source' => $source,
                'note' => $this->nullableText($payload['note'] ?? null, 'preference_note', 500),
                'actor' => $actorId,
            ]
        );
        return $this->preferenceForContact($siteId, $contactId) ?? [];
    }

    private function status(string $status): string
    {
        $status = strtolower(trim($status));
        $status = match ($status) {
            'opted_in' => 'opt_in',
            'opted_out' => 'opt_out',
            default => $status,
        };
        if (!in_array($status, ['unknown', 'opt_in', 'opt_out'], true)) {
            throw new InvalidArgumentException('business.consent_status_invalid');
        }
        return $status;
    }
}
