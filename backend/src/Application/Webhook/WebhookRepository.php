<?php

declare(strict_types=1);

namespace App\Application\Webhook;

interface WebhookRepository
{
    /** @return list<array<string,mixed>> */
    public function matchingEndpoints(string $topic, ?int $siteId): array;

    public function createDelivery(int $webhookId, int $outboxEventId, string $topic, array $payload, string $deliveryId): int;

    /** @return list<array<string,mixed>> */
    public function claimDueDeliveries(int $limit = 25): array;

    public function markSucceeded(int $deliveryPk, int $httpStatus, string $responseBody): void;

    public function markFailed(int $deliveryPk, string $message, ?int $httpStatus, string $responseBody, int $maxAttempts): void;

    /** @return array{pending:int,failed:int,succeeded:int} */
    public function stats(): array;
}
