<?php

declare(strict_types=1);

namespace App\Application\Business;

use App\Core\Database;
use App\Modules\Business\Contracts\ProductContentSourcePort;
use App\Modules\Business\Repositories\PublicCatalogRepository;
use App\Modules\Business\Services\BusinessCatalogSellableReadService;
use InvalidArgumentException;

/** Builds immutable public DTOs; storefront rendering subsequently reads core.sqlite only. */
final class StorefrontProjectionService
{
    public function __construct(
        private readonly Database $core,
        private readonly Database $business,
        private readonly ProductContentSourcePort $products,
        private readonly PublicCatalogRepository $catalog,
        private readonly BusinessCatalogSellableReadService $sellables,
    ) {}

    /** @return array{products:int,collections:int,channel_id:int,locale:string} */
    public function rebuild(int $siteId, ?int $channelId = null, string $locale = 'fr'): array
    {
        $locale = strtolower(trim($locale));
        if (!preg_match('/^[a-z]{2}(?:-[a-z0-9]{2,8})?$/', $locale)) {
            throw new InvalidArgumentException('storefront.locale_invalid');
        }
        $channelId ??= (int) ($this->core->one(
            "SELECT channel_id FROM cms_sales_channel_storefronts WHERE site_id=? AND is_default=1 AND status='active' LIMIT 1",
            [$siteId]
        )['channel_id'] ?? 0);
        if ($channelId < 1) {
            throw new InvalidArgumentException('storefront.channel_missing');
        }

        $productDtos = [];
        $offset = 0;
        do {
            $page = $this->catalog->products($siteId, [], 100, $offset);
            foreach ($page['items'] as $product) {
                $dto = $this->productDto($siteId, $channelId, $locale, (int) $product['id']);
                if ($dto !== null) {
                    $productDtos[] = $dto;
                }
            }
            $offset += 100;
        } while ($page['has_more']);

        $collections = [];
        foreach ($this->catalog->categories($siteId) as $category) {
            $count = count(array_filter($productDtos, static fn(array $dto): bool => (int) ($dto['collection']['collection_id'] ?? 0) === (int) $category['id']));
            $collections[] = [
                'contract' => 'storefront.collection.v1', 'version' => 1,
                'collection_id' => (int) $category['id'], 'site_id' => $siteId, 'channel_id' => $channelId, 'locale' => $locale,
                'slug' => (string) $category['slug'], 'name' => (string) $category['name'], 'description' => (string) ($category['description'] ?? ''),
                'product_count' => $count, 'url' => '/shop/collections/' . $category['slug'],
                'seo' => ['canonical' => '/shop/collections/' . $category['slug'], 'robots' => $count > 0 ? 'index,follow' : 'noindex,follow'],
            ];
        }

        $this->core->transaction(function () use ($siteId, $channelId, $locale, $productDtos, $collections): void {
            $this->core->run('DELETE FROM storefront_product_projections WHERE site_id=? AND channel_id=? AND locale=?', [$siteId,$channelId,$locale]);
            $this->core->run('DELETE FROM storefront_collection_projections WHERE site_id=? AND channel_id=? AND locale=?', [$siteId,$channelId,$locale]);
            foreach ($productDtos as $dto) {
                $json = $this->json($dto);
                $this->core->run(
                    'INSERT INTO storefront_product_projections(site_id,channel_id,locale,product_id,slug,collection_id,dto_version,is_indexable,dto_json,source_hash)
                     VALUES(?,?,?,?,?,?,1,1,?,?)',
                    [$siteId,$channelId,$locale,$dto['product_id'],$dto['slug'],$dto['collection']['collection_id'] ?? null,$json,hash('sha256',$json)]
                );
            }
            foreach ($collections as $dto) {
                $json = $this->json($dto);
                $this->core->run(
                    'INSERT INTO storefront_collection_projections(site_id,channel_id,locale,collection_id,slug,dto_version,dto_json,source_hash)
                     VALUES(?,?,?,?,?,1,?,?)',
                    [$siteId,$channelId,$locale,$dto['collection_id'],$dto['slug'],$json,hash('sha256',$json)]
                );
            }
        });
        if ($this->business->tableExists('business_storefront_projection_invalidations')) {
            $this->business->run('UPDATE business_storefront_projection_invalidations SET processed_at=CURRENT_TIMESTAMP WHERE site_id=? AND processed_at IS NULL', [$siteId]);
        }
        return ['products' => count($productDtos), 'collections' => count($collections), 'channel_id' => $channelId, 'locale' => $locale];
    }

    /** @return array<string,mixed>|null */
    private function productDto(int $siteId, int $channelId, string $locale, int $productId): ?array
    {
        $source = $this->products->productSnapshot($siteId, $productId, $locale);
        $product = $source['product'] ?? null;
        if (!is_array($product) || ($product['status'] ?? null) !== 'active' || empty($product['is_public']) || empty($product['is_ecommerce_enabled'])) {
            return null;
        }
        $result = $this->sellables->listSellableVariants($siteId, [
            'product_id' => $productId, 'channel' => 'ecommerce', 'language' => $locale,
            'currency' => 'CHF', 'limit' => 100, 'include_internal_fields' => false,
        ]);
        $sellables = [];
        foreach ($result['items'] as $item) {
            if (empty($item['is_sellable'])) {
                continue;
            }
            $sellables[] = [
                'contract' => 'storefront.sellable.v1', 'sellable_id' => (int) $item['sellable_id'],
                'variant_id' => (int) $item['business_variant_id'], 'sku' => (string) $item['sku'], 'name' => (string) $item['variant_name'],
                'options' => $item['variant_options'] ?? [],
                'price' => ['regular_minor' => (int) $item['regular_sale_price_minor'], 'final_minor' => (int) $item['sale_price_minor'], 'currency' => (string) $item['currency'], 'discounts' => $item['discounts_applied'] ?? []],
                'availability' => $item['availability'] ?? ['status' => 'contact_us', 'is_orderable' => false],
                'media' => $this->media($item['main_asset'] ?? null),
                'cta' => ['action' => 'add_to_cart', 'sellable_id' => (int) $item['sellable_id'], 'method' => 'POST', 'endpoint_template' => '/api/v1/sale/channels/{channel}/cart/{token}/lines'],
            ];
        }
        if ($sellables === []) {
            return null;
        }
        $assets = array_values(array_filter(array_map(fn(array $asset): ?array => $this->media($asset), (array) ($source['assets'] ?? []))));
        $slug = (string) $product['slug'];
        $canonical = '/shop/products/' . $slug;
        $primary = $sellables[0];
        $jsonLd = ['@context' => 'https://schema.org', '@type' => ($product['type'] ?? '') === 'service' ? 'Service' : 'Product', 'name' => $product['name'], 'sku' => $primary['sku'], 'url' => $canonical,
            'offers' => ['@type' => 'Offer', 'priceCurrency' => $primary['price']['currency'], 'price' => number_format($primary['price']['final_minor'] / 100, 2, '.', ''), 'availability' => !empty($primary['availability']['is_orderable']) ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock']];
        return [
            'contract' => 'storefront.product.v1', 'version' => 1, 'product_id' => $productId, 'site_id' => $siteId, 'channel_id' => $channelId, 'locale' => $locale,
            'slug' => $slug, 'type' => (string) $product['type'], 'name' => (string) $product['name'], 'summary' => (string) ($product['short_description'] ?? ''),
            'description' => (string) ($product['description'] ?? ''), 'brand' => ['name' => $product['brand_name'] ?? null, 'slug' => $product['brand_slug'] ?? null],
            'collection' => ['collection_id' => isset($product['category_id']) ? (int) $product['category_id'] : null, 'name' => $product['category_name'] ?? null, 'slug' => $product['category_slug'] ?? null],
            'media' => $assets, 'sellables' => $sellables, 'default_sellable_id' => $primary['sellable_id'], 'price' => $primary['price'], 'availability' => $primary['availability'],
            'cta' => $primary['cta'] + ['label' => 'Ajouter au panier'], 'url' => $canonical,
            'seo' => ['canonical' => $canonical, 'robots' => 'index,follow', 'hreflang' => [['locale' => $locale, 'url' => $canonical]], 'json_ld' => $jsonLd],
        ];
    }

    /** @param array<string,mixed>|null $asset @return array<string,mixed>|null */
    private function media(?array $asset): ?array
    {
        if (!$asset || (int) ($asset['media_id'] ?? 0) < 1) return null;
        return ['media_id' => (int) $asset['media_id'], 'role' => (string) ($asset['role'] ?? 'gallery'), 'url' => (string) ($asset['url'] ?? '/media/' . $asset['media_id']), 'alt' => (string) ($asset['alt_text'] ?? '')];
    }

    private function json(array $value): string { return json_encode($value, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); }
}
