<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Modules\Sale\Repositories\SaleIdempotencyRepository;

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
}
