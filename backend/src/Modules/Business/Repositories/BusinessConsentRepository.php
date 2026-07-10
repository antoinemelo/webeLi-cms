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

    public function upsertConsent(int $contactId, string $channel, string $status, string $source = 'manual', ?string $evidence = null, ?int $actorId = null): array
    {
        if ($contactId < 1) {
            throw new InvalidArgumentException('business.contact_id_invalid');
        }
        $channel = $this->channel($channel);
        $status = $this->status($status);
        if (!in_array($source, ['manual', 'form', 'import', 'unsubscribe', 'api'], true)) {
            throw new InvalidArgumentException('business.consent_source_invalid');
        }
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
        return $this->consentForContact($contactId, $channel) ?? [];
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

    public function updateById(int $siteId, int $id, string $status, string $source = 'manual', ?string $evidence = null, ?int $actorId = null): ?array
    {
        $current = $this->find($siteId, $id);
        if ($current === null) {
            return null;
        }
        return $this->upsertConsent((int) $current['contact_id'], (string) $current['channel'], $status, $source, $evidence, $actorId);
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
