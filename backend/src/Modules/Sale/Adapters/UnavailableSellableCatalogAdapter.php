<?php

declare(strict_types=1);

namespace App\Modules\Sale\Adapters;

use App\Modules\Sale\Contracts\SellableCatalogPort;
use InvalidArgumentException;

final class UnavailableSellableCatalogAdapter implements SellableCatalogPort
{
    /** @param array<string,mixed> $context @return array<string,mixed> */
    public function getSellableVariantSnapshot(int $siteId, int $variantId, array $context = []): array
    {
        throw new InvalidArgumentException('sale.catalog.integration_unavailable');
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    public function publicPayload(array $snapshot): array
    {
        unset(
            $snapshot['purchase_price_minor'],
            $snapshot['unit_purchase_price_minor'],
            $snapshot['margin_minor'],
            $snapshot['margin_percent_basis_points'],
            $snapshot['snapshot_json']
        );
        return $snapshot;
    }

    /** @param array<string,mixed> $filters @return array{items:list<array<string,mixed>>,limit:int,offset:int,total:int,has_more:bool} */
    public function searchSellableVariants(int $siteId, array $filters = []): array
    {
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        return ['items' => [], 'limit' => $limit, 'offset' => $offset, 'total' => 0, 'has_more' => false];
    }
}
