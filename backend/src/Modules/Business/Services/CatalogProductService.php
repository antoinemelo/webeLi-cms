<?php

declare(strict_types=1);

namespace App\Modules\Business\Services;

use App\Modules\Business\Catalog\BusinessCatalogValidator;
use App\Modules\Business\Catalog\CatalogProductServiceContract;
use App\Modules\Business\Repositories\CatalogProductRepository;
use App\Modules\Business\Contracts\ProductContentProjectionPort;

final class CatalogProductService implements CatalogProductServiceContract
{
    public function __construct(
        private readonly CatalogProductRepository $products,
        private readonly BusinessCatalogValidator $validator = new BusinessCatalogValidator(),
        private readonly ?ProductContentProjectionPort $contentProjections = null,
    ) {}

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function create(int $siteId, array $payload, ?int $actorId = null): array
    {
        $validated = $this->validator->product($payload) + [
            'brand_id' => $payload['brand_id'] ?? null,
            'category_id' => $payload['category_id'] ?? null,
            'tax_class_id' => $payload['tax_class_id'] ?? null,
            'sku_base' => $payload['sku_base'] ?? null,
        ];
        if (($validated['status'] ?? 'draft') === 'active') {
            throw new \InvalidArgumentException('business.catalog.active_product_variant_required');
        }
        return $this->products->create($siteId, $validated, $actorId);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed>|null */
    public function update(int $siteId, int $id, array $payload, ?int $actorId = null): ?array
    {
        if (($payload['status'] ?? null) === 'active') {
            $this->assertCanBeActive($siteId, $id);
        }
        $product = $this->products->update($siteId, $id, $payload, $actorId);
        if ($product !== null) {
            $this->contentProjections?->refreshProduct($siteId, $id);
        }
        return $product;
    }

    public function archive(int $siteId, int $id, ?int $actorId = null): bool
    {
        $archived = $this->products->archive($siteId, $id, $actorId);
        if ($archived) {
            $this->contentProjections?->deactivateProduct($siteId, $id);
        }
        return $archived;
    }

    public function restore(int $siteId, int $id, ?int $actorId = null): bool
    {
        $restored = $this->products->restore($siteId, $id, $actorId);
        if ($restored) {
            $this->contentProjections?->refreshProduct($siteId, $id);
        }
        return $restored;
    }

    public function deletePermanently(int $siteId, int $id): bool
    {
        $this->contentProjections?->assertProductCanBeDeleted($siteId, $id);
        return $this->products->deletePermanently($siteId, $id);
    }

    private function assertCanBeActive(int $siteId, int $productId): void
    {
        $product = $this->products->find($siteId, $productId, true);
        $isBundle = ($product['type'] ?? null) === 'bundle';
        if (!$isBundle && !$this->products->hasSaleBasePrice($productId)) {
            throw new \InvalidArgumentException('business.catalog.active_product_sale_price_required');
        }
        if ($this->products->activeVariantCount($productId) < 1) {
            throw new \InvalidArgumentException('business.catalog.active_product_variant_required');
        }
    }
}
