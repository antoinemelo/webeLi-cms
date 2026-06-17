<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sql;

use App\Application\Webhook\WebhookRepository;
use App\Core\Database;

final class SqlWebhookRepository implements WebhookRepository
{
    public function __construct(private readonly Database $db) {}

    public function matchingEndpoints(string $topic, ?int $siteId): array
    {
        $rows = $this->db->all(
            "SELECT * FROM webhook_endpoints
             WHERE is_active = 1
               AND (site_id IS NULL OR site_id = :site_id)
             ORDER BY id",
            ['site_id' => $siteId]
        );

        $matches = [];
        foreach ($rows as $row) {
            $events = json_decode((string) ($row['events_json'] ?? '[]'), true);
            if (!is_array($events)) {
                continue;
            }
            $events = array_map('strval', $events);
            if (in_array('*', $events, true) || in_array($topic, $events, true)) {
                $matches[] = $row;
            }
        }
        return $matches;
    }

    public function createDelivery(int $webhookId, int $outboxEventId, string $topic, array $payload, string $deliveryId): int
    {
        $now = now_utc();
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->db->run(
            "INSERT OR IGNORE INTO webhook_deliveries(webhook_id, outbox_event_id, delivery_id, event_topic, payload_json, status, attempts, created_at, next_attempt_at)
             VALUES(:webhook_id, :outbox_event_id, :delivery_id, :event_topic, :payload_json, 'pending', 0, :created_at, :next_attempt_at)",
            [
                'webhook_id' => $webhookId,
                'outbox_event_id' => $outboxEventId,
                'delivery_id' => $deliveryId,
                'event_topic' => $topic,
                'payload_json' => $body,
                'created_at' => $now,
                'next_attempt_at' => $now,
            ]
        );
        $row = $this->db->one(
            "SELECT id FROM webhook_deliveries WHERE webhook_id = :webhook_id AND outbox_event_id = :outbox_event_id LIMIT 1",
            ['webhook_id' => $webhookId, 'outbox_event_id' => $outboxEventId]
        );
        return (int) ($row['id'] ?? 0);
    }

    public function claimDueDeliveries(int $limit = 25): array
    {
        $limit = max(1, $limit);
        return $this->db->transaction(function () use ($limit): array {
            $rows = $this->db->all(
                "SELECT d.*, w.url, w.secret, w.max_attempts, w.site_id AS endpoint_site_id
                 FROM webhook_deliveries d
                 INNER JOIN webhook_endpoints w ON w.id = d.webhook_id
                 WHERE w.is_active = 1
                   AND d.status IN ('pending','failed')
                   AND d.next_attempt_at <= :now
                   AND d.attempts < w.max_attempts
                 ORDER BY d.id
                 LIMIT {$limit}",
                ['now' => now_utc()]
            );
            foreach ($rows as $row) {
                $this->db->run(
                    "UPDATE webhook_deliveries
                     SET status = 'processing', attempts = attempts + 1, last_attempt_at = :now
                     WHERE id = :id",
                    ['now' => now_utc(), 'id' => $row['id']]
                );
                $this->db->run(
                    "UPDATE webhook_endpoints
                     SET last_attempt_at = :now
                     WHERE id = :id",
                    ['now' => now_utc(), 'id' => $row['webhook_id']]
                );
            }
            return $rows;
        });
    }

    public function markSucceeded(int $deliveryPk, int $httpStatus, string $responseBody): void
    {
        $now = now_utc();
        $this->db->run(
            "UPDATE webhook_deliveries
             SET status = 'succeeded', http_status = :http_status, response_body = :response_body, last_error = NULL, delivered_at = :delivered_at
             WHERE id = :id",
            [
                'http_status' => $httpStatus,
                'response_body' => mb_substr($responseBody, 0, 2000),
                'delivered_at' => $now,
                'id' => $deliveryPk,
            ]
        );
    }

    public function markFailed(int $deliveryPk, string $message, ?int $httpStatus, string $responseBody, int $maxAttempts): void
    {
        $row = $this->db->one("SELECT attempts, webhook_id FROM webhook_deliveries WHERE id = :id", ['id' => $deliveryPk]);
        $attempts = (int) ($row['attempts'] ?? 1);
        $terminal = $attempts >= max(1, $maxAttempts);
        $delay = min(3600, max(60, $attempts * 120));
        $nextAttemptAt = $terminal ? null : gmdate('Y-m-d H:i:s', time() + $delay);
        $status = $terminal ? 'failed' : 'pending';
        $this->db->run(
            "UPDATE webhook_deliveries
             SET status = :status,
                 http_status = :http_status,
                 response_body = :response_body,
                 last_error = :last_error,
                 next_attempt_at = COALESCE(:next_attempt_at, next_attempt_at)
             WHERE id = :id",
            [
                'status' => $status,
                'http_status' => $httpStatus,
                'response_body' => mb_substr($responseBody, 0, 2000),
                'last_error' => mb_substr($message, 0, 500),
                'next_attempt_at' => $nextAttemptAt,
                'id' => $deliveryPk,
            ]
        );
        if ($row && isset($row['webhook_id'])) {
            $this->db->run("UPDATE webhook_endpoints SET next_attempt_at = :next_attempt_at WHERE id = :id", [
                'next_attempt_at' => $nextAttemptAt,
                'id' => $row['webhook_id'],
            ]);
        }
    }

    public function stats(): array
    {
        return [
            'pending' => (int) ($this->db->one("SELECT COUNT(*) AS c FROM webhook_deliveries WHERE status IN ('pending','processing')")['c'] ?? 0),
            'failed' => (int) ($this->db->one("SELECT COUNT(*) AS c FROM webhook_deliveries WHERE status = 'failed'")['c'] ?? 0),
            'succeeded' => (int) ($this->db->one("SELECT COUNT(*) AS c FROM webhook_deliveries WHERE status = 'succeeded'")['c'] ?? 0),
        ];
    }
}
