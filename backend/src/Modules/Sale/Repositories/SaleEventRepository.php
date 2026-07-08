<?php

declare(strict_types=1);

namespace App\Modules\Sale\Repositories;

final class SaleEventRepository extends SaleRepositoryBase
{
    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function emit(int $siteId, string $eventType, string $aggregateType, int $aggregateId, array $payload = [], ?int $iamUserId = null): array
    {
        $this->rawDatabase()->run(
            'INSERT INTO sale_events(site_id, event_type, aggregate_type, aggregate_id, payload_json, created_by_iam_user_id)
             VALUES(?, ?, ?, ?, ?, ?)',
            [$siteId, $eventType, $aggregateType, $aggregateId, $this->json($payload), $iamUserId]
        );
        return $this->rawDatabase()->one('SELECT * FROM sale_events WHERE id = ?', [(int) $this->rawDatabase()->lastInsertId()]) ?? [];
    }
}
