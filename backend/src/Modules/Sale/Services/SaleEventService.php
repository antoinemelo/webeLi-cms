<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Modules\Sale\Repositories\SaleEventRepository;

final class SaleEventService
{
    public function __construct(private readonly SaleEventRepository $events) {}

    /** @param array<string,mixed> $payload */
    public function emit(int $siteId, string $eventType, string $aggregateType, int $aggregateId, array $payload = [], ?int $iamUserId = null, ?string $correlationId = null): array
    {
        return $this->events->emit($siteId, $eventType, $aggregateType, $aggregateId, $payload, $iamUserId, $correlationId);
    }
}
