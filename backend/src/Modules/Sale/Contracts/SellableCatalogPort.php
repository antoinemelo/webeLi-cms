<?php

declare(strict_types=1);

namespace App\Modules\Sale\Contracts;

interface SellableCatalogPort
{
    /** @param array<string,mixed> $context @return array<string,mixed> */
    public function getSellableVariantSnapshot(int $siteId, int $variantId, array $context = []): array;

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    public function publicPayload(array $snapshot): array;

    /** @param array<string,mixed> $filters @return array{items:list<array<string,mixed>>,limit:int,offset:int,total:int,has_more:bool} */
    public function searchSellableVariants(int $siteId, array $filters = []): array;
}
