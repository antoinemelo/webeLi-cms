<?php

declare(strict_types=1);

namespace App\Modules\Sale\Adapters;

use App\Modules\Business\Services\BusinessCatalogSellableReadService;
use App\Modules\Sale\Contracts\SellableCatalogPort;

final class BusinessSellableCatalogAdapter implements SellableCatalogPort
{
    public function __construct(private readonly BusinessCatalogSellableReadService $sellables) {}

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public function getSellableVariantSnapshot(int $siteId, int $variantId, array $context = []): array
    {
        return $this->sellables->getSellableVariantSnapshot($siteId, $variantId, $context);
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    public function publicPayload(array $snapshot): array
    {
        return $this->sellables->publicPayload($snapshot);
    }

    /** @param array<string,mixed> $filters @return array{items:list<array<string,mixed>>,limit:int,offset:int,total:int,has_more:bool} */
    public function searchSellableVariants(int $siteId, array $filters = []): array
    {
        return $this->sellables->searchSellableVariants($siteId, $filters);
    }
}
