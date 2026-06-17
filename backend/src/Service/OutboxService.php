<?php

declare(strict_types=1);

namespace App\Service;

use App\Application\Outbox\OutboxEventRepository;

final class OutboxService
{
    public function __construct(private readonly OutboxEventRepository $events) {}

    /** @param array<string,mixed> $payload */
    public function push(string $topic, array $payload): void
    {
        $this->events->push($topic, $payload);
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

    public function markFailed(int $id, string $message, int $attempts): void
    {
        $this->events->markFailed($id, $message, $attempts);
    }

    /** @return array{pending:int,failed:int,processed:int} */
    public function stats(): array
    {
        return $this->events->stats();
    }
}
