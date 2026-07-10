<?php

declare(strict_types=1);

namespace App\Modules\Sale\Repositories;

final class SaleEventRepository extends SaleRepositoryBase
{
    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function emit(int $siteId, string $eventType, string $aggregateType, int $aggregateId, array $payload = [], ?int $iamUserId = null): array
    {
        $db = $this->rawDatabase();
        $db->run(
            'INSERT INTO sale_events(site_id, event_type, aggregate_type, aggregate_id, payload_json, created_by_iam_user_id)
             VALUES(?, ?, ?, ?, ?, ?)',
            [$siteId, $eventType, $aggregateType, $aggregateId, $this->json($payload), $iamUserId]
        );
        $event = $db->one('SELECT * FROM sale_events WHERE id = ?', [(int) $db->lastInsertId()]) ?? [];
        if ($event !== []) {
            $this->enqueueOutbox($event, $payload);
        }
        return $event;
    }

    /** @param array<string,mixed> $event @param array<string,mixed> $payload */
    private function enqueueOutbox(array $event, array $payload): void
    {
        $envelope = [
            'schema_version' => 1,
            'event_id' => (int) $event['id'],
            'event_type' => (string) $event['event_type'],
            'site_id' => (int) $event['site_id'],
            'aggregate' => [
                'type' => (string) $event['aggregate_type'],
                'id' => (int) $event['aggregate_id'],
            ],
            'payload' => $payload,
            'created_by_iam_user_id' => $event['created_by_iam_user_id'] === null ? null : (int) $event['created_by_iam_user_id'],
            'created_at' => (string) $event['created_at'],
        ];
        $this->rawDatabase()->run(
            'INSERT INTO sale_outbox(event_id, topic, payload_json)
             VALUES(?, ?, ?)',
            [(int) $event['id'], (string) $event['event_type'], $this->json($envelope)]
        );
    }
}
