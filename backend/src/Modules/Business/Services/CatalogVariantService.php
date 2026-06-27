<?php

declare(strict_types=1);

namespace App\Modules\Business\Services;

use App\Modules\Business\Catalog\BusinessCatalogValidator;
use App\Modules\Business\Catalog\CatalogVariantServiceContract;
use App\Modules\Business\Repositories\CatalogVariantRepository;

final class CatalogVariantService implements CatalogVariantServiceContract
{
    public function __construct(
        private readonly CatalogVariantRepository $variants,
        private readonly BusinessCatalogValidator $validator = new BusinessCatalogValidator()
    ) {}

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function create(int $siteId, int $productId, array $payload, ?int $actorId = null): array
    {
        return $this->variants->create($siteId, $productId, $this->validator->variant($payload) + ['option_values' => $payload['option_values'] ?? []], $actorId);
    }

    public function archive(int $siteId, int $id, ?int $actorId = null): bool
    {
        return $this->variants->archive($siteId, $id, $actorId);
    }
}
