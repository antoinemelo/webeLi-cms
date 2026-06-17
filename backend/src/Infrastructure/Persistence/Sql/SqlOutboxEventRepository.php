<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sql;

use App\Application\Outbox\OutboxEventRepository;
use App\Core\Database;

final class SqlOutboxEventRepository implements OutboxEventRepository
{
    public function __construct(private readonly Database $db) {}

    public function push(string $topic, array $payload): void
    {
        $this->db->run("INSERT INTO outbox_events(topic, payload_json, status, created_at, available_at) VALUES(:topic, :payload_json, 'pending', :created_at, :available_at)", [
            'topic' => $topic,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now_utc(),
            'available_at' => now_utc(),
        ]);
    }

    public function claimBatch(int $limit = 25, int $maxAttempts = 5): array
    {
        $limit = max(1, $limit);
        return $this->db->transaction(function () use ($limit, $maxAttempts): array {
            $events = $this->db->all(
                "SELECT * FROM outbox_events
                 WHERE status IN ('pending', 'failed')
                   AND available_at <= :now
                   AND attempts < :max_attempts
                 ORDER BY id
                 LIMIT {$limit}",
                ['now' => now_utc(), 'max_attempts' => $maxAttempts]
            );
            foreach ($events as $event) {
                $this->db->run("UPDATE outbox_events SET status = 'processing', attempts = attempts + 1, claimed_at = :claimed_at WHERE id = :id", [
                    'claimed_at' => now_utc(),
                    'id' => $event['id'],
                ]);
            }
            return $events;
        });
    }

    public function markProcessed(int $id): void
    {
        $this->db->run("UPDATE outbox_events SET status = 'processed', processed_at = :processed_at, last_error = NULL WHERE id = :id", [
            'processed_at' => now_utc(),
            'id' => $id,
        ]);
    }

    public function markFailed(int $id, string $message, int $attempts): void
    {
        $retryAt = gmdate('Y-m-d H:i:s', time() + min(3600, max(60, $attempts * 120)));
        $this->db->run("UPDATE outbox_events SET status = 'failed', last_error = :last_error, available_at = :available_at WHERE id = :id", [
            'last_error' => mb_substr($message, 0, 500),
            'available_at' => $retryAt,
            'id' => $id,
        ]);
    }

    public function stats(): array
    {
        return [
            'pending' => (int) ($this->db->one("SELECT COUNT(*) AS c FROM outbox_events WHERE status = 'pending'")['c'] ?? 0),
            'failed' => (int) ($this->db->one("SELECT COUNT(*) AS c FROM outbox_events WHERE status = 'failed'")['c'] ?? 0),
            'processed' => (int) ($this->db->one("SELECT COUNT(*) AS c FROM outbox_events WHERE status = 'processed'")['c'] ?? 0),
        ];
    }
}
