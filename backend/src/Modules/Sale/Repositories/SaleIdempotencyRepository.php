<?php

declare(strict_types=1);

namespace App\Modules\Sale\Repositories;

final class SaleIdempotencyRepository extends SaleRepositoryBase
{
    /** @return array<string,mixed>|null */
    public function completed(int $siteId, string $scope, string $key, string $requestHash): ?array
    {
        $row = $this->rawDatabase()->one(
            'SELECT * FROM sale_idempotency_keys WHERE site_id = ? AND scope = ? AND key_hash = ? LIMIT 1',
            [$siteId, $scope, $this->hash($key)]
        );
        if ($row === null || (string) $row['status'] !== 'completed' || (string) $row['request_hash'] !== $requestHash) {
            return null;
        }
        $response = json_decode((string) $row['response_json'], true);
        return is_array($response) ? $response : null;
    }

    public function complete(int $siteId, string $scope, string $key, string $requestHash, array $response): void
    {
        $hash = $this->hash($key);
        $existing = $this->rawDatabase()->one(
            'SELECT id FROM sale_idempotency_keys WHERE site_id = ? AND scope = ? AND key_hash = ? LIMIT 1',
            [$siteId, $scope, $hash]
        );
        if ($existing === null) {
            $this->rawDatabase()->run(
                'INSERT INTO sale_idempotency_keys(site_id, key_hash, scope, request_hash, response_json, status, updated_at)
                 VALUES(?, ?, ?, ?, ?, "completed", CURRENT_TIMESTAMP)',
                [$siteId, $hash, $scope, $requestHash, $this->json($response)]
            );
            return;
        }
        $this->rawDatabase()->run(
            'UPDATE sale_idempotency_keys
             SET request_hash = ?, response_json = ?, status = "completed", updated_at = CURRENT_TIMESTAMP
             WHERE id = ?',
            [$requestHash, $this->json($response), (int) $existing['id']]
        );
    }

    public function requestHash(array $payload): string
    {
        ksort($payload);
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    private function hash(string $key): string
    {
        return hash('sha256', $key);
    }
}
