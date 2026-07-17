<?php

declare(strict_types=1);

namespace App\Modules\Business\Services;

use App\Modules\Business\Catalog\BusinessCatalogValidator;
use App\Modules\Business\Catalog\CatalogDiscountServiceContract;
use App\Modules\Business\Repositories\CatalogDiscountRepository;
use DateTimeImmutable;

final class CatalogDiscountService implements CatalogDiscountServiceContract
{
    public function __construct(
        private readonly CatalogDiscountRepository $discounts,
        private readonly BusinessCatalogValidator $validator = new BusinessCatalogValidator()
    ) {}

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function create(int $siteId, array $payload, ?int $actorId = null): array
    {
        $validated = $this->validator->offer([
            'name' => $payload['name'] ?? null,
            'type' => $payload['type'] ?? $payload['discount_type'] ?? 'percent',
            'value' => $payload['value'] ?? $payload['discount_value'] ?? null,
            'scope' => $payload['scope'] ?? $payload['scope_type'] ?? 'product',
            'channel' => $payload['channel'] ?? 'all',
        ]);
        return $this->discounts->create($siteId, $validated + [
            'scope_id' => $payload['scope_id'] ?? null,
            'currency' => $payload['currency'] ?? 'CHF',
            'priority' => $payload['priority'] ?? 100,
            'starts_at' => $payload['starts_at'] ?? null,
            'ends_at' => $payload['ends_at'] ?? null,
            'status' => $payload['status'] ?? 'active',
            'customer_segment' => $payload['customer_segment'] ?? null,
        ], $actorId);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function preview(int $siteId, array $payload): array
    {
        $this->validator->offer([
            'name' => $payload['name'] ?? null,
            'type' => $payload['type'] ?? $payload['discount_type'] ?? 'percent',
            'value' => $payload['value'] ?? $payload['discount_value'] ?? null,
            'scope' => $payload['scope'] ?? $payload['scope_type'] ?? 'product',
            'channel' => $payload['channel'] ?? 'all',
        ]);
        return $this->discounts->preview($siteId, $payload);
    }

    /** @return list<array<string,mixed>> */
    public function activeForVariant(int $siteId, int $productId, int $variantId, ?string $channel = null, ?DateTimeImmutable $at = null): array
    {
        return $this->discounts->activeForVariant($siteId, $productId, $variantId, $channel, $at);
    }

    public function archive(int $siteId, int $id, ?int $actorId = null): bool
    {
        return $this->discounts->archive($siteId, $id, $actorId);
    }
}
