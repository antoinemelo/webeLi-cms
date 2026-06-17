<?php

declare(strict_types=1);

namespace App\Application\Outbox;

interface OutboxEventRepository
{
    /** @param array<string,mixed> $payload */
    public function push(string $topic, array $payload): void;

    /** @return list<array<string,mixed>> */
    public function claimBatch(int $limit = 25, int $maxAttempts = 5): array;

    public function markProcessed(int $id): void;

    public function markFailed(int $id, string $message, int $attempts): void;

    /** @return array{pending:int,failed:int,processed:int} */
    public function stats(): array;
}
