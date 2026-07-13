<?php

declare(strict_types=1);

namespace App\Application\Outbox;

interface OutboxEventRepository
{
    /** @param array<string,mixed> $envelope @return array<string,mixed> */
    public function push(array $envelope): array;

    /** @return list<array<string,mixed>> */
    public function claimBatch(int $limit = 25, int $maxAttempts = 5): array;

    public function markProcessed(int $id): void;

    public function markFailed(int $id, string $message, int $attempts, int $maxAttempts = 5, string $errorType = 'consumer_error'): void;

    public function retry(int $id, bool $resetAttempts = false): void;

    public function moveToDeadLetter(int $id, string $message = 'manual dead-letter'): void;

    public function restoreDeadLetter(int $id): void;

    public function archiveProcessed(int $olderThanDays = 30): int;

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array;

    public function wasConsumed(string $eventId, string $consumerKey): bool;

    /** @param array<string,mixed> $result */
    public function recordConsumption(string $eventId, string $consumerKey, array $result = []): void;

    /** @return array<string,mixed> */
    public function stats(): array;
}
