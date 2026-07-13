<?php

declare(strict_types=1);

namespace App\Service;

use App\Application\Outbox\OutboxEventRepository;
use App\Core\Response;

final class OutboxService
{
    public function __construct(private readonly OutboxEventRepository $events) {}

    /** @param array<string,mixed> $payload @param array<string,mixed> $options @return array<string,mixed> */
    public function push(string $topic, array $payload, array $options = []): array
    {
        return $this->events->push($this->envelope($topic, $payload, $options));
    }

    /** @return list<array<string,mixed>> */
    public function claimBatch(int $limit = 25, int $maxAttempts = 5): array
    {
        return $this->events->claimBatch($limit, $maxAttempts);
    }

    public function markProcessed(int $id): void
    {
        $this->events->markProcessed($id);
    }

    public function markFailed(int $id, string $message, int $attempts, int $maxAttempts = 5, string $errorType = 'consumer_error'): void
    {
        $this->events->markFailed($id, $message, $attempts, $maxAttempts, $errorType);
    }

    public function retry(int $id, bool $resetAttempts = false): void
    {
        $this->events->retry($id, $resetAttempts);
    }

    public function moveToDeadLetter(int $id, string $message = 'manual dead-letter'): void
    {
        $this->events->moveToDeadLetter($id, $message);
    }

    public function restoreDeadLetter(int $id): void
    {
        $this->events->restoreDeadLetter($id);
    }

    public function archiveProcessed(int $olderThanDays = 30): int
    {
        return $this->events->archiveProcessed($olderThanDays);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->events->find($id);
    }

    public function wasConsumed(string $eventId, string $consumerKey): bool
    {
        return $this->events->wasConsumed($eventId, $consumerKey);
    }

    /** @param array<string,mixed> $result */
    public function recordConsumption(string $eventId, string $consumerKey, array $result = []): void
    {
        $this->events->recordConsumption($eventId, $consumerKey, $result);
    }

    /** @return array<string,mixed> */
    public function stats(): array
    {
        return $this->events->stats();
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $options @return array<string,mixed> */
    private function envelope(string $topic, array $payload, array $options): array
    {
        $eventId = trim((string) ($options['event_id'] ?? ''));
        if ($eventId === '') {
            $eventId = self::newEventId();
        }
        $occurredAt = trim((string) ($options['occurred_at'] ?? '')) ?: now_utc();
        $correlationId = trim((string) ($options['correlation_id'] ?? ($payload['correlation_id'] ?? '')));
        if ($correlationId === '') {
            $correlationId = Response::requestId();
        }

        return [
            'event_id' => $eventId,
            'event_type' => trim((string) ($options['event_type'] ?? $topic)),
            'schema_version' => max(1, (int) ($options['schema_version'] ?? 1)),
            'occurred_at' => $occurredAt,
            'site_id' => isset($options['site_id']) ? (int) $options['site_id'] : (isset($payload['site_id']) ? (int) $payload['site_id'] : null),
            'correlation_id' => $correlationId,
            'causation_id' => isset($options['causation_id']) ? (string) $options['causation_id'] : ($payload['causation_id'] ?? null),
            'aggregate_type' => trim((string) ($options['aggregate_type'] ?? ($payload['aggregate_type'] ?? 'system'))) ?: 'system',
            'aggregate_id' => isset($options['aggregate_id']) ? (string) $options['aggregate_id'] : (isset($payload['aggregate_id']) ? (string) $payload['aggregate_id'] : null),
            'topic' => $topic,
            'payload' => $payload,
            'metadata' => $this->sanitizeMetadata(is_array($options['metadata'] ?? null) ? $options['metadata'] : []),
            'max_attempts' => max(1, min(100, (int) ($options['max_attempts'] ?? 5))),
        ];
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    private function sanitizeMetadata(array $metadata): array
    {
        foreach (['secret', 'token', 'password', 'authorization', 'cookie'] as $key) {
            unset($metadata[$key]);
        }
        $metadata['producer'] = (string) ($metadata['producer'] ?? 'cms');
        return $metadata;
    }

    private static function newEventId(): string
    {
        return 'evt_' . bin2hex(random_bytes(16));
    }
}
