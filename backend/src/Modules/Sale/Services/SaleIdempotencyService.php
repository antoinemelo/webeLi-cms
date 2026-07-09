<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Modules\Sale\Exceptions\SaleValidationException;
use App\Modules\Sale\Repositories\SaleIdempotencyRepository;
use Throwable;

final class SaleIdempotencyService
{
    public function __construct(private readonly SaleIdempotencyRepository $idempotency) {}

    /** @param array<string,mixed> $request @return array<string,mixed>|null */
    public function completed(int $siteId, string $scope, ?string $key, array $request): ?array
    {
        if ($key === null || trim($key) === '') {
            return null;
        }
        return $this->idempotency->completed($siteId, $scope, $key, $this->idempotency->requestHash($request));
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $response */
    public function complete(int $siteId, string $scope, ?string $key, array $request, array $response): void
    {
        if ($key === null || trim($key) === '') {
            return;
        }
        $this->idempotency->complete($siteId, $scope, $key, $this->idempotency->requestHash($request), $response);
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function run(int $siteId, string $scope, ?string $key, array $request, callable $callback): array
    {
        if ($key === null || trim($key) === '') {
            $result = $callback();
            return is_array($result) ? $result : [];
        }

        $requestHash = $this->idempotency->requestHash($request);
        $begin = $this->idempotency->begin($siteId, $scope, $key, $requestHash);
        if ($begin['status'] === 'completed') {
            return $begin['response'] ?? [];
        }
        if ($begin['status'] === 'conflict') {
            throw new SaleValidationException('sale.idempotency_key_conflict');
        }
        if ($begin['status'] === 'locked') {
            throw new SaleValidationException('sale.idempotency_key_locked');
        }

        try {
            $result = $callback();
            $response = is_array($result) ? $result : [];
            $this->idempotency->complete($siteId, $scope, $key, $requestHash, $response);
            return $response;
        } catch (Throwable $e) {
            $this->idempotency->fail($siteId, $scope, $key, $requestHash, $e->getMessage());
            throw $e;
        }
    }
}
