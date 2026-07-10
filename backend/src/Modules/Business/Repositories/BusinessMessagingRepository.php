<?php

declare(strict_types=1);

namespace App\Modules\Business\Repositories;

use InvalidArgumentException;

final class BusinessMessagingRepository extends BusinessRepositoryBase
{
    /** @return list<array<string,mixed>> */
    public function providers(int $siteId): array
    {
        $rows = $this->database()->all(
            'SELECT * FROM crm_messaging_providers
             WHERE (site_id = :site_id OR site_id IS NULL)
             ORDER BY site_id IS NULL DESC, channel, is_default DESC, provider_key',
            ['site_id' => $this->requireSiteId($siteId)]
        );
        return array_map(fn(array $row): array => $this->providerRow($row), $rows);
    }

    public function defaultProvider(int $siteId, string $channel): ?array
    {
        $row = $this->database()->one(
            'SELECT * FROM crm_messaging_providers
             WHERE (site_id = :site_id OR site_id IS NULL) AND channel = :channel AND is_enabled = 1
             ORDER BY is_default DESC, site_id IS NOT NULL DESC, id
             LIMIT 1',
            ['site_id' => $this->requireSiteId($siteId), 'channel' => $this->channel($channel)]
        );
        return $row ? $this->providerRow($row) : null;
    }

    public function providerByKey(int $siteId, string $providerKey): ?array
    {
        $key = $this->key($providerKey, 'provider_key');
        $row = $this->database()->one(
            'SELECT * FROM crm_messaging_providers WHERE (site_id = :site_id OR site_id IS NULL) AND provider_key = :provider_key ORDER BY site_id IS NOT NULL DESC LIMIT 1',
            ['site_id' => $this->requireSiteId($siteId), 'provider_key' => $key]
        );
        return $row ? $this->providerRow($row) : null;
    }

    public function findProvider(int $siteId, int $id): ?array
    {
        $row = $this->database()->one(
            'SELECT * FROM crm_messaging_providers WHERE site_id = :site_id AND id = :id LIMIT 1',
            ['site_id' => $this->requireSiteId($siteId), 'id' => $id]
        );
        return $row ? $this->providerRow($row) : null;
    }

    public function saveProvider(int $siteId, array $payload, ?int $actorId = null): array
    {
        $siteId = $this->requireSiteId($siteId);
        $id = max(0, (int) ($payload['id'] ?? 0));
        $current = $id > 0 ? $this->findProvider($siteId, $id) : null;
        if ($id > 0 && $current === null) {
            throw new InvalidArgumentException('business.messaging_provider_not_found');
        }
        $providerKey = $this->key($payload['provider_key'] ?? $current['provider_key'] ?? '', 'provider_key');
        $channel = $this->channel($payload['channel'] ?? $current['channel'] ?? '');
        $providerType = $this->providerType($payload['provider_type'] ?? $current['provider_type'] ?? 'null');
        $name = $this->text($payload['name'] ?? $current['name'] ?? $providerKey, 'provider_name', 120);
        $configJson = $this->json($payload['config'] ?? $payload['config_json'] ?? $current['config'] ?? []);
        $secretRef = $this->secretRef($payload['secret_ref'] ?? $current['secret_ref'] ?? null);
        $isEnabled = $this->boolInt($payload['is_enabled'] ?? $current['is_enabled'] ?? false);
        $isDefault = $this->boolInt($payload['is_default'] ?? $current['is_default'] ?? false);
        if ($isDefault === 1) {
            $this->database()->run(
                'UPDATE crm_messaging_providers SET is_default = 0, updated_by_iam_user_id = :actor, updated_at = CURRENT_TIMESTAMP WHERE site_id = :site_id AND channel = :channel',
                ['site_id' => $siteId, 'channel' => $channel, 'actor' => $actorId]
            );
        }
        if ($id > 0) {
            $this->database()->run(
                'UPDATE crm_messaging_providers
                 SET provider_key = :provider_key, name = :name, channel = :channel, provider_type = :provider_type, config_json = :config_json,
                     secret_ref = :secret_ref, is_enabled = :is_enabled, is_default = :is_default, updated_by_iam_user_id = :actor, updated_at = CURRENT_TIMESTAMP
                 WHERE site_id = :site_id AND id = :id',
                [
                    'site_id' => $siteId,
                    'id' => $id,
                    'provider_key' => $providerKey,
                    'name' => $name,
                    'channel' => $channel,
                    'provider_type' => $providerType,
                    'config_json' => $configJson,
                    'secret_ref' => $secretRef,
                    'is_enabled' => $isEnabled,
                    'is_default' => $isDefault,
                    'actor' => $actorId,
                ]
            );
            return $this->findProvider($siteId, $id) ?? [];
        }
        $this->database()->run(
            'INSERT INTO crm_messaging_providers(site_id, provider_key, name, channel, provider_type, config_json, secret_ref, is_enabled, is_default, created_by_iam_user_id, updated_by_iam_user_id)
             VALUES(:site_id, :provider_key, :name, :channel, :provider_type, :config_json, :secret_ref, :is_enabled, :is_default, :actor, :actor)',
            [
                'site_id' => $siteId,
                'provider_key' => $providerKey,
                'name' => $name,
                'channel' => $channel,
                'provider_type' => $providerType,
                'config_json' => $configJson,
                'secret_ref' => $secretRef,
                'is_enabled' => $isEnabled,
                'is_default' => $isDefault,
                'actor' => $actorId,
            ]
        );
        return $this->findProvider($siteId, $this->database()->lastInsertId()) ?? [];
    }

    public function deleteProvider(int $siteId, int $id): bool
    {
        $this->database()->run(
            'DELETE FROM crm_messaging_providers WHERE site_id = :site_id AND id = :id',
            ['site_id' => $this->requireSiteId($siteId), 'id' => $id]
        );
        return true;
    }

    public function createOutbox(int $siteId, array $payload, ?int $actorId = null): array
    {
        $siteId = $this->requireSiteId($siteId);
        $channel = $this->channel($payload['channel'] ?? '');
        $status = (string) ($payload['status'] ?? 'pending');
        if (!in_array($status, ['pending', 'queued', 'sent', 'failed', 'cancelled', 'skipped'], true)) {
            throw new InvalidArgumentException('business.message_status_invalid');
        }
        $this->database()->run(
            'INSERT INTO crm_message_outbox(site_id, provider_id, template_id, mailing_id, contact_id, channel, recipient_value, subject, body_text, body_html, payload_json, status, max_attempts, created_by_iam_user_id)
             VALUES(:site_id, :provider_id, :template_id, :mailing_id, :contact_id, :channel, :recipient, :subject, :body_text, :body_html, :payload_json, :status, :max_attempts, :actor)',
            [
                'site_id' => $siteId,
                'provider_id' => $this->nullableInt($payload['provider_id'] ?? null),
                'template_id' => $this->nullableInt($payload['template_id'] ?? null),
                'mailing_id' => $this->nullableInt($payload['mailing_id'] ?? null),
                'contact_id' => $this->nullableInt($payload['contact_id'] ?? null),
                'channel' => $channel,
                'recipient' => $this->text($payload['recipient_value'] ?? null, 'recipient_value', 255),
                'subject' => $this->nullableText($payload['subject'] ?? null, 'subject', 255),
                'body_text' => $this->nullableText($payload['body_text'] ?? '', 'body_text', 20000) ?? '',
                'body_html' => $this->nullableText($payload['body_html'] ?? null, 'body_html', 50000),
                'payload_json' => $this->json($payload['payload'] ?? $payload['payload_json'] ?? []),
                'status' => $status,
                'max_attempts' => max(1, min(10, (int) ($payload['max_attempts'] ?? 3))),
                'actor' => $actorId,
            ]
        );
        $row = $this->database()->one('SELECT * FROM crm_message_outbox WHERE id = :id', ['id' => $this->database()->lastInsertId()]);
        return $row ? $this->castRow($row) : [];
    }

    public function findOutbox(int $siteId, int $id): ?array
    {
        $row = $this->database()->one('SELECT * FROM crm_message_outbox WHERE site_id = :site_id AND id = :id LIMIT 1', [
            'site_id' => $this->requireSiteId($siteId),
            'id' => $id,
        ]);
        return $row ? $this->castRow($row) : null;
    }

    public function updateOutboxStatus(int $siteId, int $id, string $status, ?string $providerMessageId = null, ?string $error = null): ?array
    {
        if (!in_array($status, ['pending', 'queued', 'sent', 'failed', 'cancelled', 'skipped'], true)) {
            throw new InvalidArgumentException('business.message_status_invalid');
        }
        $fields = [
            'status = :status',
            'updated_at = CURRENT_TIMESTAMP',
            'last_error = :last_error',
        ];
        $params = [
            'site_id' => $this->requireSiteId($siteId),
            'id' => $id,
            'status' => $status,
            'last_error' => $error,
        ];
        if ($status === 'queued') {
            $fields[] = 'attempts = attempts + 1';
            $fields[] = 'locked_at = CURRENT_TIMESTAMP';
        }
        if ($status === 'sent') {
            $fields[] = 'sent_at = CURRENT_TIMESTAMP';
            $fields[] = 'locked_at = NULL';
        }
        if ($status === 'failed') {
            $fields[] = 'failed_at = CURRENT_TIMESTAMP';
            $fields[] = 'locked_at = NULL';
        }
        if ($providerMessageId !== null && trim($providerMessageId) !== '') {
            $payload = $this->findOutbox($siteId, $id);
            $data = $payload ? json_decode((string) ($payload['payload_json'] ?? '{}'), true) : [];
            $data = is_array($data) ? $data : [];
            $data['provider_message_id'] = $providerMessageId;
            $fields[] = 'payload_json = :payload_json';
            $params['payload_json'] = $this->json($data);
        }
        $this->database()->run(
            'UPDATE crm_message_outbox SET ' . implode(', ', $fields) . ' WHERE site_id = :site_id AND id = :id',
            $params
        );
        return $this->findOutbox($siteId, $id);
    }

    public function addDeliveryEvent(int $outboxId, string $eventType, array $payload = [], ?string $providerMessageId = null, ?string $error = null): array
    {
        if (!in_array($eventType, ['queued', 'sent', 'delivered', 'failed', 'bounced', 'opened', 'clicked', 'skipped', 'cancelled', 'provider_status'], true)) {
            throw new InvalidArgumentException('business.message_event_invalid');
        }
        $this->database()->run(
            'INSERT INTO crm_message_delivery_events(outbox_id, event_type, provider_message_id, event_payload_json, error_message)
             VALUES(:outbox_id, :event_type, :provider_message_id, :payload, :error)',
            [
                'outbox_id' => $outboxId,
                'event_type' => $eventType,
                'provider_message_id' => $providerMessageId,
                'payload' => $this->json($payload),
                'error' => $error === null ? null : $this->nullableText($error, 'error_message', 2000),
            ]
        );
        $row = $this->database()->one('SELECT * FROM crm_message_delivery_events WHERE id = :id', ['id' => $this->database()->lastInsertId()]);
        return $row ? $this->castRow($row) : [];
    }

    /** @return list<array<string,mixed>> */
    public function deliveryEvents(int $outboxId): array
    {
        $rows = $this->database()->all('SELECT * FROM crm_message_delivery_events WHERE outbox_id = :outbox_id ORDER BY created_at, id', ['outbox_id' => $outboxId]);
        return array_map(fn(array $row): array => $this->castRow($row), $rows);
    }

    /** @param array<string,mixed> $filters @return array{items:list<array<string,mixed>>,limit:int,offset:int} */
    public function listOutbox(int $siteId, string $status = '', int $limit = 50, int $offset = 0, array $filters = []): array
    {
        $siteId = $this->requireSiteId($siteId);
        $limit = $this->limit($limit);
        $offset = $this->offset($offset);
        $where = ['mo.site_id = :site_id'];
        $params = ['site_id' => $siteId];
        if (trim($status) !== '') {
            $where[] = 'mo.status = :status';
            $params['status'] = $status;
        }
        $channel = trim((string) ($filters['channel'] ?? ''));
        if ($channel !== '') {
            $where[] = 'mo.channel = :channel';
            $params['channel'] = $this->channel($channel);
        }
        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $where[] = '(mo.recipient_value LIKE :q OR mo.subject LIKE :q OR mo.body_text LIKE :q OR c.display_name LIKE :q OR co.name LIKE :q)';
            $params['q'] = '%' . $query . '%';
        }
        $rows = $this->database()->all(
            'SELECT mo.*, c.display_name AS contact_name, co.name AS company_name,
                    (SELECT COUNT(*) FROM crm_message_delivery_events e WHERE e.outbox_id = mo.id) AS event_count
             FROM crm_message_outbox mo
             LEFT JOIN business_contacts c ON c.id = mo.contact_id AND c.site_id = mo.site_id
             LEFT JOIN business_companies co ON co.id = c.company_id AND co.site_id = mo.site_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY mo.created_at DESC, mo.id DESC
             LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );
        return ['items' => array_map(fn(array $row): array => $this->castRow($row), $rows), 'limit' => $limit, 'offset' => $offset];
    }

    /** @return array{items:list<array<string,mixed>>,limit:int,offset:int} */
    public function relationMessages(int $siteId, string $type, int $id, int $limit = 50, int $offset = 0): array
    {
        $siteId = $this->requireSiteId($siteId);
        $limit = $this->limit($limit);
        $offset = $this->offset($offset);
        $type = trim($type);
        if (!in_array($type, ['contact', 'company'], true)) {
            throw new InvalidArgumentException('business.relation_type_invalid');
        }
        if ($id < 1) {
            throw new InvalidArgumentException('business.relation_id_invalid');
        }

        $where = ['mo.site_id = :site_id'];
        $params = ['site_id' => $siteId, 'id' => $id];
        if ($type === 'contact') {
            $where[] = 'mo.contact_id = :id';
        } else {
            $where[] = 'c.company_id = :id';
        }

        $rows = $this->database()->all(
            'SELECT mo.*, c.display_name AS contact_name,
                    (SELECT COUNT(*) FROM crm_message_delivery_events e WHERE e.outbox_id = mo.id) AS event_count
             FROM crm_message_outbox mo
             LEFT JOIN business_contacts c ON c.id = mo.contact_id AND c.site_id = mo.site_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY mo.created_at DESC, mo.id DESC
             LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );

        return ['items' => array_map(fn(array $row): array => $this->castRow($row), $rows), 'limit' => $limit, 'offset' => $offset];
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

    private function providerType(mixed $value): string
    {
        $type = trim((string) ($value ?: 'null'));
        if (!in_array($type, ['null', 'smtp', 'webhook', 'whatsapp_cloud', 'telegram_bot', 'custom'], true)) {
            throw new InvalidArgumentException('business.messaging_provider_type_invalid');
        }
        return $type;
    }

    private function secretRef(mixed $value): ?string
    {
        $secretRef = $this->nullableText($value, 'secret_ref', 120);
        if ($secretRef === null) {
            return null;
        }
        if (!preg_match('/^env:[A-Z0-9_]+$/', $secretRef)) {
            throw new InvalidArgumentException('business.messaging_secret_ref_invalid');
        }
        return $secretRef;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function providerRow(array $row): array
    {
        $row = $this->castRow($row);
        $config = json_decode((string) ($row['config_json'] ?? '{}'), true);
        $row['config'] = is_array($config) ? $config : [];
        unset($row['config_json']);
        return $row;
    }

    private function json(mixed $value): string
    {
        if (is_string($value) && trim($value) !== '') {
            json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $value;
            }
        }
        return json_encode(is_array($value) ? $value : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
