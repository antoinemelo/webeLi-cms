<?php

declare(strict_types=1);

namespace App\Modules\Sale\Repositories;

final class SaleIdempotencyRepository extends SaleRepositoryBase
{
    /** @return array{status:string,response:?array<string,mixed>} */
    public function begin(int $siteId, string $scope, string $key, string $requestHash, int $lockSeconds = 300): array
    {
        $hash = $this->hash($key);
        $lockedUntil = gmdate('Y-m-d H:i:s', time() + max(30, $lockSeconds));
        $row = $this->rawDatabase()->one(
            'SELECT * FROM sale_idempotency_keys WHERE site_id = ? AND scope = ? AND key_hash = ? LIMIT 1',
            [$siteId, $scope, $hash]
        );
        if ($row === null) {
            $this->rawDatabase()->run(
                'INSERT INTO sale_idempotency_keys(site_id, key_hash, scope, request_hash, response_json, status, locked_until, updated_at)
                 VALUES(?, ?, ?, ?, \'{}\', \'processing\', ?, CURRENT_TIMESTAMP)',
                [$siteId, $hash, $scope, $requestHash, $lockedUntil]
            );
            return ['status' => 'processing', 'response' => null];
        }

        if ((string) $row['request_hash'] !== $requestHash) {
            return ['status' => 'conflict', 'response' => null];
        }

        if ((string) $row['status'] === 'completed') {
            $response = json_decode((string) $row['response_json'], true);
            return ['status' => 'completed', 'response' => is_array($response) ? $response : null];
        }

        if ((string) $row['status'] === 'processing' && (string) ($row['locked_until'] ?? '') > gmdate('Y-m-d H:i:s')) {
            return ['status' => 'locked', 'response' => null];
        }

        $this->rawDatabase()->run(
            'UPDATE sale_idempotency_keys
             SET status = \'processing\', locked_until = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?',
            [$lockedUntil, (int) $row['id']]
        );
        return ['status' => 'processing', 'response' => null];
    }

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
                 VALUES(?, ?, ?, ?, ?, \'completed\', CURRENT_TIMESTAMP)',
                [$siteId, $hash, $scope, $requestHash, $this->json($response)]
            );
            return;
        }
        $this->rawDatabase()->run(
            'UPDATE sale_idempotency_keys
             SET request_hash = ?, response_json = ?, status = \'completed\', locked_until = NULL, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?',
            [$requestHash, $this->json($response), (int) $existing['id']]
        );
    }

    public function fail(int $siteId, string $scope, string $key, string $requestHash, string $error): void
    {
        $hash = $this->hash($key);
        $response = ['error' => $error];
        $existing = $this->rawDatabase()->one(
            'SELECT id FROM sale_idempotency_keys WHERE site_id = ? AND scope = ? AND key_hash = ? LIMIT 1',
            [$siteId, $scope, $hash]
        );
        if ($existing === null) {
            $this->rawDatabase()->run(
                'INSERT INTO sale_idempotency_keys(site_id, key_hash, scope, request_hash, response_json, status, locked_until, updated_at)
                 VALUES(?, ?, ?, ?, ?, \'failed\', NULL, CURRENT_TIMESTAMP)',
                [$siteId, $hash, $scope, $requestHash, $this->json($response)]
            );
            return;
        }
        $this->rawDatabase()->run(
            'UPDATE sale_idempotency_keys
             SET request_hash = ?, response_json = ?, status = \'failed\', locked_until = NULL, updated_at = CURRENT_TIMESTAMP
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
