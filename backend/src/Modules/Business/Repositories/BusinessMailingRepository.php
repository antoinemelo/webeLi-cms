<?php

declare(strict_types=1);

namespace App\Modules\Business\Repositories;

use InvalidArgumentException;

final class BusinessMailingRepository extends BusinessRepositoryBase
{
    /** @return array{items:list<array<string,mixed>>,limit:int,offset:int} */
    public function lists(int $siteId, int $limit = 50, int $offset = 0, bool $includeArchived = false): array
    {
        $limit = $this->limit($limit);
        $offset = $this->offset($offset);
        $rows = $this->database()->all(
            'SELECT * FROM crm_mailing_lists WHERE site_id = :site_id' . ($includeArchived ? '' : ' AND archived_at IS NULL') . ' ORDER BY name LIMIT ' . $limit . ' OFFSET ' . $offset,
            ['site_id' => $this->requireSiteId($siteId)]
        );
        return ['items' => array_map(fn(array $row): array => $this->castRow($row), $rows), 'limit' => $limit, 'offset' => $offset];
    }

    public function findList(int $siteId, int $id): ?array
    {
        $row = $this->database()->one('SELECT * FROM crm_mailing_lists WHERE site_id = :site_id AND id = :id AND archived_at IS NULL LIMIT 1', [
            'site_id' => $this->requireSiteId($siteId),
            'id' => $id,
        ]);
        return $row ? $this->castRow($row) : null;
    }

    public function createList(int $siteId, array $payload, ?int $actorId = null): array
    {
        $name = $this->text($payload['name'] ?? null, 'name', 180);
        $key = $this->key($payload['list_key'] ?? $name, 'list_key');
        $this->database()->run(
            'INSERT INTO crm_mailing_lists(site_id, list_key, name, description, channel, created_by_iam_user_id, updated_by_iam_user_id)
             VALUES(:site_id, :list_key, :name, :description, :channel, :actor, :actor)',
            [
                'site_id' => $this->requireSiteId($siteId),
                'list_key' => $key,
                'name' => $name,
                'description' => $this->nullableText($payload['description'] ?? '', 'description', 2000) ?? '',
                'channel' => $this->channel($payload['channel'] ?? 'email'),
                'actor' => $actorId,
            ]
        );
        return $this->findList($siteId, $this->database()->lastInsertId()) ?? [];
    }

    public function updateList(int $siteId, int $id, array $payload, ?int $actorId = null): ?array
    {
        $current = $this->findList($siteId, $id);
        if ($current === null) {
            return null;
        }
        $this->database()->run(
            'UPDATE crm_mailing_lists SET name = :name, description = :description, channel = :channel, status = :status,
                updated_by_iam_user_id = :actor, updated_at = CURRENT_TIMESTAMP
             WHERE site_id = :site_id AND id = :id',
            [
                'site_id' => $this->requireSiteId($siteId),
                'id' => $id,
                'name' => $this->text($payload['name'] ?? $current['name'], 'name', 180),
                'description' => $this->nullableText($payload['description'] ?? $current['description'] ?? '', 'description', 2000) ?? '',
                'channel' => $this->channel($payload['channel'] ?? $current['channel']),
                'status' => in_array(($payload['status'] ?? $current['status']), ['active', 'archived'], true) ? (string) ($payload['status'] ?? $current['status']) : 'active',
                'actor' => $actorId,
            ]
        );
        return $this->findList($siteId, $id);
    }

    public function addMember(int $siteId, int $listId, int $contactId, ?int $actorId = null, ?string $tokenHash = null): array
    {
        $this->assertList($siteId, $listId);
        $this->database()->run(
            "INSERT INTO crm_mailing_list_members(list_id, contact_id, status, unsubscribe_token_hash, created_by_iam_user_id, updated_by_iam_user_id)
             VALUES(:list_id, :contact_id, 'subscribed', :token_hash, :actor, :actor)
             ON CONFLICT(list_id, contact_id) DO UPDATE SET status = 'subscribed', unsubscribed_at = NULL, unsubscribe_token_hash = COALESCE(crm_mailing_list_members.unsubscribe_token_hash, excluded.unsubscribe_token_hash), updated_by_iam_user_id = excluded.updated_by_iam_user_id, updated_at = CURRENT_TIMESTAMP",
            ['list_id' => $listId, 'contact_id' => $contactId, 'token_hash' => $tokenHash, 'actor' => $actorId]
        );
        $row = $this->database()->one('SELECT * FROM crm_mailing_list_members WHERE list_id = :list_id AND contact_id = :contact_id', ['list_id' => $listId, 'contact_id' => $contactId]);
        return $row ? $this->castRow($row) : [];
    }

    public function removeMember(int $siteId, int $listId, int $contactId, ?int $actorId = null): bool
    {
        $this->assertList($siteId, $listId);
        $this->database()->run(
            "UPDATE crm_mailing_list_members SET status = 'unsubscribed', unsubscribed_at = CURRENT_TIMESTAMP, updated_by_iam_user_id = :actor, updated_at = CURRENT_TIMESTAMP WHERE list_id = :list_id AND contact_id = :contact_id",
            ['list_id' => $listId, 'contact_id' => $contactId, 'actor' => $actorId]
        );
        return true;
    }

    /** @return list<array<string,mixed>> */
    public function members(int $siteId, int $listId, string $status = 'subscribed'): array
    {
        $this->assertList($siteId, $listId);
        $rows = $this->database()->all(
            'SELECT mlm.*, c.display_name, c.email, c.mobile, c.preferred_language
             FROM crm_mailing_list_members mlm
             JOIN business_contacts c ON c.id = mlm.contact_id
             WHERE mlm.list_id = :list_id AND mlm.status = :status AND c.archived_at IS NULL
             ORDER BY c.normalized_name, c.id',
            ['list_id' => $listId, 'status' => $status]
        );
        return array_map(fn(array $row): array => $this->castRow($row), $rows);
    }

    /** @return array{items:list<array<string,mixed>>,limit:int,offset:int} */
    public function campaigns(int $siteId, int $limit = 50, int $offset = 0): array
    {
        $limit = $this->limit($limit);
        $offset = $this->offset($offset);
        $rows = $this->database()->all('SELECT * FROM crm_mailings WHERE site_id = :site_id ORDER BY created_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset, ['site_id' => $this->requireSiteId($siteId)]);
        return ['items' => array_map(fn(array $row): array => $this->castRow($row), $rows), 'limit' => $limit, 'offset' => $offset];
    }

    public function findCampaign(int $siteId, int $id): ?array
    {
        $row = $this->database()->one('SELECT * FROM crm_mailings WHERE site_id = :site_id AND id = :id LIMIT 1', ['site_id' => $this->requireSiteId($siteId), 'id' => $id]);
        return $row ? $this->castRow($row) : null;
    }

    public function createCampaign(int $siteId, array $payload, ?int $actorId = null): array
    {
        $name = $this->text($payload['name'] ?? null, 'name', 180);
        $key = isset($payload['mailing_key']) && trim((string) $payload['mailing_key']) !== '' ? $this->key($payload['mailing_key'], 'mailing_key') : null;
        $this->database()->run(
            'INSERT INTO crm_mailings(site_id, list_id, mailing_key, name, channel, status, subject, body_text, body_html, template_key, scheduled_at, created_by_iam_user_id, updated_by_iam_user_id)
             VALUES(:site_id, :list_id, :mailing_key, :name, :channel, :status, :subject, :body_text, :body_html, :template_key, :scheduled_at, :actor, :actor)',
            [
                'site_id' => $this->requireSiteId($siteId),
                'list_id' => $this->nullableInt($payload['list_id'] ?? null),
                'mailing_key' => $key,
                'name' => $name,
                'channel' => $this->channel($payload['channel'] ?? 'email'),
                'status' => 'draft',
                'subject' => $this->nullableText($payload['subject'] ?? null, 'subject', 255),
                'body_text' => $this->nullableText($payload['body_text'] ?? '', 'body_text', 20000) ?? '',
                'body_html' => $this->nullableText($payload['body_html'] ?? null, 'body_html', 50000),
                'template_key' => $this->nullableText($payload['template_key'] ?? null, 'template_key', 120),
                'scheduled_at' => $this->nullableText($payload['scheduled_at'] ?? null, 'scheduled_at', 64),
                'actor' => $actorId,
            ]
        );
        return $this->findCampaign($siteId, $this->database()->lastInsertId()) ?? [];
    }

    public function updateCampaign(int $siteId, int $id, array $payload, ?int $actorId = null): ?array
    {
        $current = $this->findCampaign($siteId, $id);
        if ($current === null) {
            return null;
        }
        $this->database()->run(
            'UPDATE crm_mailings SET list_id = :list_id, name = :name, channel = :channel, status = :status, subject = :subject, body_text = :body_text,
                body_html = :body_html, template_key = :template_key, scheduled_at = :scheduled_at, updated_by_iam_user_id = :actor, updated_at = CURRENT_TIMESTAMP
             WHERE site_id = :site_id AND id = :id',
            [
                'site_id' => $this->requireSiteId($siteId),
                'id' => $id,
                'list_id' => $this->nullableInt($payload['list_id'] ?? $current['list_id'] ?? null),
                'name' => $this->text($payload['name'] ?? $current['name'], 'name', 180),
                'channel' => $this->channel($payload['channel'] ?? $current['channel']),
                'status' => $this->campaignStatus($payload['status'] ?? $current['status']),
                'subject' => $this->nullableText($payload['subject'] ?? $current['subject'] ?? null, 'subject', 255),
                'body_text' => $this->nullableText($payload['body_text'] ?? $current['body_text'] ?? '', 'body_text', 20000) ?? '',
                'body_html' => $this->nullableText($payload['body_html'] ?? $current['body_html'] ?? null, 'body_html', 50000),
                'template_key' => $this->nullableText($payload['template_key'] ?? $current['template_key'] ?? null, 'template_key', 120),
                'scheduled_at' => $this->nullableText($payload['scheduled_at'] ?? $current['scheduled_at'] ?? null, 'scheduled_at', 64),
                'actor' => $actorId,
            ]
        );
        return $this->findCampaign($siteId, $id);
    }

    /** @return list<array<string,mixed>> */
    public function eligibleRecipients(int $siteId, array $campaign): array
    {
        $listId = (int) ($campaign['list_id'] ?? 0);
        if ($listId < 1) {
            return [];
        }
        $rows = $this->database()->all(
            'SELECT c.id AS contact_id, c.display_name, c.email, c.mobile, cc.id AS channel_id, cc.normalized_value AS recipient_value, mlm.unsubscribe_token_hash
             FROM crm_mailing_list_members mlm
             JOIN crm_mailing_lists ml ON ml.id = mlm.list_id
             JOIN business_contacts c ON c.id = mlm.contact_id
             JOIN crm_consents consent ON consent.contact_id = c.id AND consent.channel = ml.channel AND consent.consent_status = :opt_in
             JOIN crm_contact_channels cc ON cc.contact_id = c.id AND cc.channel = ml.channel AND cc.is_primary = 1 AND cc.archived_at IS NULL
             WHERE ml.site_id = :site_id AND ml.id = :list_id AND mlm.status = :member_status AND c.archived_at IS NULL
             ORDER BY c.normalized_name, c.id',
            ['site_id' => $this->requireSiteId($siteId), 'list_id' => $listId, 'opt_in' => 'opt_in', 'member_status' => 'subscribed']
        );
        return array_map(fn(array $row): array => $this->castRow($row), $rows);
    }

    public function upsertRecipient(int $mailingId, int $contactId, ?int $channelId, string $tokenHash): array
    {
        $this->database()->run(
            "INSERT INTO crm_mailing_recipients(mailing_id, contact_id, channel_id, status, unsubscribe_token_hash)
             VALUES(:mailing_id, :contact_id, :channel_id, 'pending', :token_hash)
             ON CONFLICT(mailing_id, contact_id) DO UPDATE SET channel_id = excluded.channel_id, unsubscribe_token_hash = COALESCE(crm_mailing_recipients.unsubscribe_token_hash, excluded.unsubscribe_token_hash), updated_at = CURRENT_TIMESTAMP",
            ['mailing_id' => $mailingId, 'contact_id' => $contactId, 'channel_id' => $channelId, 'token_hash' => $tokenHash]
        );
        $row = $this->database()->one('SELECT * FROM crm_mailing_recipients WHERE mailing_id = :mailing_id AND contact_id = :contact_id', ['mailing_id' => $mailingId, 'contact_id' => $contactId]);
        return $row ? $this->castRow($row) : [];
    }

    public function updateRecipientQueued(int $recipientId, int $outboxId): void
    {
        $this->database()->run("UPDATE crm_mailing_recipients SET status = 'queued', queued_at = CURRENT_TIMESTAMP, message_outbox_id = :outbox_id, updated_at = CURRENT_TIMESTAMP WHERE id = :id", ['id' => $recipientId, 'outbox_id' => $outboxId]);
    }

    public function setCampaignStatus(int $siteId, int $id, string $status, ?int $actorId = null): ?array
    {
        $status = $this->campaignStatus($status);
        $fields = ['status = :status', 'updated_by_iam_user_id = :actor', 'updated_at = CURRENT_TIMESTAMP'];
        if ($status === 'sending') {
            $fields[] = 'sent_at = NULL';
        }
        if ($status === 'sent') {
            $fields[] = 'sent_at = CURRENT_TIMESTAMP';
        }
        if ($status === 'cancelled') {
            $fields[] = 'cancelled_at = CURRENT_TIMESTAMP';
        }
        $this->database()->run('UPDATE crm_mailings SET ' . implode(', ', $fields) . ' WHERE site_id = :site_id AND id = :id', ['site_id' => $this->requireSiteId($siteId), 'id' => $id, 'status' => $status, 'actor' => $actorId]);
        return $this->findCampaign($siteId, $id);
    }

    public function unsubscribeByToken(string $token): ?array
    {
        $hash = hash('sha256', trim($token));
        $row = $this->database()->one(
            'SELECT mr.*, m.channel, m.site_id, c.id AS contact_id
             FROM crm_mailing_recipients mr
             JOIN crm_mailings m ON m.id = mr.mailing_id
             JOIN business_contacts c ON c.id = mr.contact_id
             WHERE mr.unsubscribe_token_hash = :hash LIMIT 1',
            ['hash' => $hash]
        );
        if (!$row) {
            return null;
        }
        $contactId = (int) $row['contact_id'];
        $channel = (string) $row['channel'];
        $this->database()->run("UPDATE crm_mailing_recipients SET status = 'unsubscribed', updated_at = CURRENT_TIMESTAMP WHERE id = :id", ['id' => (int) $row['id']]);
        $this->database()->run("UPDATE crm_mailing_list_members SET status = 'unsubscribed', unsubscribed_at = COALESCE(unsubscribed_at, CURRENT_TIMESTAMP), updated_at = CURRENT_TIMESTAMP WHERE contact_id = :contact_id", ['contact_id' => $contactId]);
        $this->database()->run(
            "INSERT INTO crm_consents(contact_id, channel, consent_status, source, evidence, revoked_at)
             VALUES(:contact_id, :channel, 'opt_out', 'unsubscribe', 'mailing unsubscribe', CURRENT_TIMESTAMP)
             ON CONFLICT(contact_id, channel) DO UPDATE SET consent_status = 'opt_out', source = 'unsubscribe', evidence = 'mailing unsubscribe', revoked_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP",
            ['contact_id' => $contactId, 'channel' => $channel]
        );
        return $this->castRow($row);
    }

    private function assertList(int $siteId, int $listId): void
    {
        if ($this->findList($siteId, $listId) === null) {
            throw new InvalidArgumentException('business.mailing_list_not_found');
        }
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $int = (int) $value;
        return $int > 0 ? $int : null;
    }

    private function key(mixed $value, string $field): string
    {
        $key = strtolower(trim((string) $value));
        $key = preg_replace('/[^a-z0-9_-]+/', '_', $key) ?? '';
        $key = trim($key, '_-');
        if ($key === '') {
            throw new InvalidArgumentException('business.' . $field . '_invalid');
        }
        return substr($key, 0, 80);
    }

    private function campaignStatus(mixed $value): string
    {
        $status = (string) $value;
        if (!in_array($status, ['draft', 'ready', 'sending', 'sent', 'cancelled', 'failed'], true)) {
            throw new InvalidArgumentException('business.mailing_status_invalid');
        }
        return $status;
    }
}
