<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sql;

use App\Application\Outbox\OutboxEventRepository;
use App\Core\Database;

final class SqlOutboxEventRepository implements OutboxEventRepository
{
    public function __construct(private readonly Database $db) {}

    /** @param array<string,mixed> $envelope @return array<string,mixed> */
    public function push(array $envelope): array
    {
        $this->validateEnvelope($envelope);
        $now = now_utc();
        $this->db->run(
            "INSERT INTO outbox_events(
                event_id, event_type, schema_version, occurred_at, site_id, correlation_id, causation_id,
                aggregate_type, aggregate_id, topic, payload_json, metadata_json, status, attempts,
                max_attempts, created_at, available_at, updated_at
            ) VALUES(
                :event_id, :event_type, :schema_version, :occurred_at, :site_id, :correlation_id, :causation_id,
                :aggregate_type, :aggregate_id, :topic, :payload_json, :metadata_json, 'pending', 0,
                :max_attempts, :created_at, :available_at, :updated_at
            )",
            [
                'event_id' => (string) $envelope['event_id'],
                'event_type' => (string) $envelope['event_type'],
                'schema_version' => (int) $envelope['schema_version'],
                'occurred_at' => (string) $envelope['occurred_at'],
                'site_id' => $envelope['site_id'] ?? null,
                'correlation_id' => (string) $envelope['correlation_id'],
                'causation_id' => $envelope['causation_id'] === null ? null : (string) $envelope['causation_id'],
                'aggregate_type' => (string) $envelope['aggregate_type'],
                'aggregate_id' => $envelope['aggregate_id'] === null ? null : (string) $envelope['aggregate_id'],
                'topic' => (string) $envelope['topic'],
                'payload_json' => $this->json((array) $envelope['payload']),
                'metadata_json' => $this->json((array) $envelope['metadata']),
                'max_attempts' => (int) $envelope['max_attempts'],
                'created_at' => $now,
                'available_at' => $now,
                'updated_at' => $now,
            ]
        );
        return $this->find($this->db->lastInsertId()) ?? [];
    }

    /** @return list<array<string,mixed>> */
    public function claimBatch(int $limit = 25, int $maxAttempts = 5): array
    {
        $limit = max(1, min(250, $limit));
        $maxAttempts = max(1, min(100, $maxAttempts));
        $now = now_utc();
        $lockToken = 'lock_' . bin2hex(random_bytes(12));
        $lockedUntil = gmdate('Y-m-d H:i:s', time() + 300);

        return $this->db->transaction(function () use ($limit, $maxAttempts, $now, $lockedUntil, $lockToken): array {
            $candidates = $this->db->all(
                "SELECT id FROM outbox_events
                 WHERE status IN ('pending', 'failed', 'processing')
                   AND available_at <= :now
                   AND attempts < MIN(max_attempts, :max_attempts)
                   AND (locked_until IS NULL OR locked_until <= :now)
                 ORDER BY id
                 LIMIT {$limit}",
                ['now' => $now, 'max_attempts' => $maxAttempts]
            );

            $claimed = [];
            $pdo = $this->db->pdo();
            $statement = $pdo->prepare(
                "UPDATE outbox_events
                 SET status = 'processing',
                     attempts = attempts + 1,
                     claimed_at = :claimed_at,
                     locked_until = :locked_until,
                     lock_token = :lock_token,
                     updated_at = :updated_at
                 WHERE id = :id
                   AND status IN ('pending', 'failed', 'processing')
                   AND available_at <= :now
                   AND attempts < MIN(max_attempts, :max_attempts)
                   AND (locked_until IS NULL OR locked_until <= :now)"
            );
            foreach ($candidates as $candidate) {
                $id = (int) $candidate['id'];
                $statement->execute([
                    'claimed_at' => $now,
                    'locked_until' => $lockedUntil,
                    'lock_token' => $lockToken,
                    'updated_at' => $now,
                    'id' => $id,
                    'now' => $now,
                    'max_attempts' => $maxAttempts,
                ]);
                if ($statement->rowCount() !== 1) {
                    continue;
                }
                $row = $this->find($id);
                if ($row !== null) {
                    $claimed[] = $row;
                }
            }
            return $claimed;
        });
    }

    public function markProcessed(int $id): void
    {
        $this->db->run(
            "UPDATE outbox_events
             SET status = 'processed', processed_at = :processed_at, last_error = NULL, error_type = NULL,
                 locked_until = NULL, lock_token = NULL, updated_at = :updated_at
             WHERE id = :id",
            ['processed_at' => now_utc(), 'updated_at' => now_utc(), 'id' => $id]
        );
    }

    public function markFailed(int $id, string $message, int $attempts, int $maxAttempts = 5, string $errorType = 'consumer_error'): void
    {
        $maxAttempts = max(1, min(100, $maxAttempts));
        $attempts = max(1, $attempts);
        $now = now_utc();
        if ($attempts >= $maxAttempts) {
            $this->db->run(
                "UPDATE outbox_events
                 SET status = 'dead_letter', last_error = :last_error, error_type = :error_type,
                     locked_until = NULL, lock_token = NULL, dead_lettered_at = :dead_lettered_at,
                     available_at = :available_at, updated_at = :updated_at
                 WHERE id = :id",
                [
                    'last_error' => $this->cleanError($message),
                    'error_type' => $this->cleanErrorType($errorType),
                    'dead_lettered_at' => $now,
                    'available_at' => $now,
                    'updated_at' => $now,
                    'id' => $id,
                ]
            );
            return;
        }

        $retryAt = gmdate('Y-m-d H:i:s', time() + $this->backoffSeconds($attempts));
        $this->db->run(
            "UPDATE outbox_events
             SET status = 'failed', last_error = :last_error, error_type = :error_type,
                 locked_until = NULL, lock_token = NULL, available_at = :available_at, updated_at = :updated_at
             WHERE id = :id",
            [
                'last_error' => $this->cleanError($message),
                'error_type' => $this->cleanErrorType($errorType),
                'available_at' => $retryAt,
                'updated_at' => $now,
                'id' => $id,
            ]
        );
    }

    public function retry(int $id, bool $resetAttempts = false): void
    {
        $attemptSql = $resetAttempts ? ', attempts = 0' : '';
        $this->db->run(
            "UPDATE outbox_events
             SET status = 'pending', available_at = :available_at, locked_until = NULL, lock_token = NULL,
                 last_error = NULL, error_type = NULL, dead_lettered_at = NULL, updated_at = :updated_at {$attemptSql}
             WHERE id = :id AND status IN ('failed', 'dead_letter', 'processing', 'processed')",
            ['available_at' => now_utc(), 'updated_at' => now_utc(), 'id' => $id]
        );
    }

    public function moveToDeadLetter(int $id, string $message = 'manual dead-letter'): void
    {
        $this->db->run(
            "UPDATE outbox_events
             SET status = 'dead_letter', last_error = :last_error, error_type = 'manual',
                 locked_until = NULL, lock_token = NULL, dead_lettered_at = :dead_lettered_at,
                 updated_at = :updated_at
             WHERE id = :id",
            ['last_error' => $this->cleanError($message), 'dead_lettered_at' => now_utc(), 'updated_at' => now_utc(), 'id' => $id]
        );
    }

    public function restoreDeadLetter(int $id): void
    {
        $this->db->run(
            "UPDATE outbox_events
             SET status = 'pending', available_at = :available_at, locked_until = NULL, lock_token = NULL,
                 last_error = NULL, error_type = NULL, dead_lettered_at = NULL, updated_at = :updated_at
             WHERE id = :id AND status = 'dead_letter'",
            ['available_at' => now_utc(), 'updated_at' => now_utc(), 'id' => $id]
        );
    }

    public function archiveProcessed(int $olderThanDays = 30): int
    {
        $threshold = gmdate('Y-m-d H:i:s', time() - max(1, $olderThanDays) * 86400);
        $stmt = $this->db->pdo()->prepare(
            "UPDATE outbox_events
             SET status = 'archived', archived_at = :archived_at, updated_at = :updated_at
             WHERE status = 'processed' AND processed_at IS NOT NULL AND processed_at < :threshold"
        );
        $now = now_utc();
        $stmt->execute(['archived_at' => $now, 'updated_at' => $now, 'threshold' => $threshold]);
        return $stmt->rowCount();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM outbox_events WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    public function wasConsumed(string $eventId, string $consumerKey): bool
    {
        $row = $this->db->one(
            'SELECT id FROM outbox_consumptions WHERE event_id = :event_id AND consumer_key = :consumer_key LIMIT 1',
            ['event_id' => $eventId, 'consumer_key' => $consumerKey]
        );
        return $row !== null;
    }

    /** @param array<string,mixed> $result */
    public function recordConsumption(string $eventId, string $consumerKey, array $result = []): void
    {
        $this->db->run(
            'INSERT OR IGNORE INTO outbox_consumptions(event_id, consumer_key, processed_at, result_json)
             VALUES(:event_id, :consumer_key, :processed_at, :result_json)',
            [
                'event_id' => $eventId,
                'consumer_key' => $consumerKey,
                'processed_at' => now_utc(),
                'result_json' => $this->json($result),
            ]
        );
    }

    /** @return array<string,mixed> */
    public function stats(): array
    {
        $counts = [];
        foreach (['pending', 'processing', 'failed', 'dead_letter', 'processed', 'archived'] as $status) {
            $counts[$status] = (int) ($this->db->one('SELECT COUNT(*) AS c FROM outbox_events WHERE status = ?', [$status])['c'] ?? 0);
        }
        $oldest = $this->db->one(
            "SELECT id, event_id, event_type, status, available_at, occurred_at
             FROM outbox_events
             WHERE status IN ('pending','failed','dead_letter')
             ORDER BY occurred_at ASC, id ASC
             LIMIT 1"
        );
        $worker = $this->db->one("SELECT * FROM system_jobs WHERE job_key = 'outbox.worker' LIMIT 1");
        $lastProviderError = $this->db->one(
            "SELECT id, event_topic, status, http_status, last_error, last_attempt_at
             FROM webhook_deliveries
             WHERE last_error IS NOT NULL AND last_error != ''
             ORDER BY last_attempt_at DESC, id DESC
             LIMIT 1"
        );
        return [
            'counts' => $counts,
            'depth' => $counts['pending'] + $counts['failed'],
            'failed' => $counts['failed'],
            'dead_letter' => $counts['dead_letter'],
            'oldest_open_event' => $oldest ?: null,
                'last_worker' => $worker ? [
                'last_run_at' => $worker['last_run_at'] ?? null,
                'last_heartbeat_at' => $worker['last_heartbeat_at'] ?? null,
                'last_status' => $worker['last_status'] ?? null,
                'last_message' => $worker['last_message'] ?? null,
                'updated_at' => $worker['updated_at'] ?? null,
            ] : null,
            'last_provider_error' => $lastProviderError ?: null,
        ];
    }

    /** @param array<string,mixed> $envelope */
    private function validateEnvelope(array $envelope): void
    {
        foreach (['event_id', 'event_type', 'schema_version', 'occurred_at', 'correlation_id', 'aggregate_type', 'topic', 'payload', 'metadata'] as $key) {
            if (!array_key_exists($key, $envelope)) {
                throw new \InvalidArgumentException('outbox.envelope_missing_' . $key);
            }
        }
        if (!is_array($envelope['payload']) || !is_array($envelope['metadata'])) {
            throw new \InvalidArgumentException('outbox.envelope_payload_invalid');
        }
        foreach (['event_id', 'event_type', 'correlation_id', 'aggregate_type', 'topic'] as $key) {
            if (trim((string) $envelope[$key]) === '') {
                throw new \InvalidArgumentException('outbox.envelope_empty_' . $key);
            }
        }
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function backoffSeconds(int $attempts): int
    {
        return min(3600, max(30, (2 ** max(0, $attempts - 1)) * 30));
    }

    private function cleanError(string $message): string
    {
        $message = preg_replace('/(?:Bearer|Token|Authorization|Cookie|password|secret)[^\s,;]*/i', '[redacted]', $message) ?? $message;
        return mb_substr(trim($message), 0, 500);
    }

    private function cleanErrorType(string $type): string
    {
        $type = strtolower(trim($type));
        return preg_match('/^[a-z0-9_.-]{1,80}$/', $type) ? $type : 'consumer_error';
    }
}
