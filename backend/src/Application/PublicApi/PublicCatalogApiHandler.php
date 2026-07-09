<?php

declare(strict_types=1);

namespace App\Application\PublicApi;

use App\Core\ErrorCode;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Business\Catalog\CatalogPricingService;
use App\Modules\Business\Repositories\PublicCatalogRepository;
use App\Repository\SiteRepository;
use Throwable;

final class PublicCatalogApiHandler
{
    private PublicApiResponder $responder;

    public function __construct(
        private readonly Request $request,
        private readonly SiteRepository $sites,
        private readonly PublicCatalogRepository $catalog,
        private readonly CatalogPricingService $pricing,
    ) {
        $this->responder = new PublicApiResponder();
    }

    public function brands(): Response
    {
        [$site, $languageCode] = $this->context();
        return $this->json(['items' => $this->catalog->brands((int) $site['id'])], 'public.catalog.brands.index.v1', $site, $languageCode);
    }

    public function categories(): Response
    {
        [$site, $languageCode] = $this->context();
        return $this->json(['items' => $this->catalog->categories((int) $site['id'])], 'public.catalog.categories.index.v1', $site, $languageCode);
    }

    public function products(): Response
    {
        [$site, $languageCode] = $this->context();
        $page = $this->catalog->products((int) $site['id'], $this->filters(), $this->limit(), $this->offset());
        return $this->json([
            'items' => array_map(fn(array $product): array => $this->productPayload((int) $site['id'], $product, false, $languageCode), $page['items']),
            'pagination' => [
                'total' => $page['total'],
                'limit' => $page['limit'],
                'offset' => $page['offset'],
                'has_more' => $page['has_more'],
            ],
        ], 'public.catalog.products.index.v1', $site, $languageCode);
    }

    public function product(string $slug): Response
    {
        [$site, $languageCode] = $this->context();
        $slug = $this->slug($slug);
        if ($slug === '') {
            return Response::validation(['slug' => ['Slug invalide.']]);
        }
        $product = $this->catalog->productBySlug((int) $site['id'], $slug);
        if (!$product) {
            return $this->notFound('Produit introuvable.', ['slug' => $slug]);
        }
        return $this->json(['product' => $this->productPayload((int) $site['id'], $product, true, $languageCode)], 'public.catalog.products.show.v1', $site, $languageCode);
    }

    public function variant(string|int $id): Response
    {
        [$site, $languageCode] = $this->context();
        $variantId = max(0, (int) $id);
        $variant = $this->catalog->variantById((int) $site['id'], $variantId);
        if (!$variant) {
            return $this->notFound('Variante introuvable.', ['id' => (string) $id]);
        }
        $product = $this->catalog->productById((int) $site['id'], (int) $variant['product_id']);
        if (!$product) {
            return $this->notFound('Produit introuvable.', ['id' => (string) ($variant['product_id'] ?? '')]);
        }
        return $this->json(['variant' => $this->variantPayload($product, $variant, true, $languageCode)], 'public.catalog.variants.show.v1', $site, $languageCode);
    }

    /** @return array{0:array<string,mixed>,1:string} */
    private function context(): array
    {
        $site = $this->sites->resolveCurrentSite((string) ($this->request->server['HTTP_HOST'] ?? ''), $this->request->path);
        $languageCode = strtolower(trim((string) ($this->request->query['lang'] ?? $site['default_language_code'] ?? 'fr')));
        if (!preg_match('/^[a-z]{2}(?:-[a-z0-9]{2,8})?$/i', $languageCode)) {
            $languageCode = (string) ($site['default_language_code'] ?? 'fr');
        }
        return [$site, $languageCode];
    }

    /** @param array<string,mixed> $site */
    private function json(array $data, string $contract, array $site, string $languageCode): Response
    {
        return $this->responder->success($data, $contract, [
            'site_id' => (int) $site['id'],
            'language_code' => $languageCode,
            'source' => 'business_catalog_public',
        ], 200, ['Cache-Control' => 'public, max-age=300, stale-while-revalidate=60']);
    }

    /** @param array<string,string> $details */
    private function notFound(string $message, array $details): Response
    {
        return Response::error(ErrorCode::PUBLIC_CONTENT_NOT_FOUND, $message, 404, $details, ['Cache-Control' => 'public, max-age=60']);
    }

    /** @param array<string,mixed> $product */
    private function productPayload(int $siteId, array $product, bool $detailed, string $languageCode): array
    {
        $variants = array_map(fn(array $variant): array => $this->variantPayload($product, $variant, $detailed, $languageCode), $this->catalog->activeVariants($siteId, (int) $product['id']));
        $media = $this->publicMedia($this->catalog->productMedia((int) $product['id']));
        $availability = $this->productAvailability($product, $variants);
        $payload = [
            'id' => (int) $product['id'],
            'name' => (string) $product['name'],
            'slug' => (string) $product['slug'],
            'type' => (string) $product['type'],
            'summary' => (string) ($product['short_description'] ?? ''),
            'brand' => $this->catalog->publicBrand($siteId, isset($product['brand_id']) ? (int) $product['brand_id'] : null),
            'category' => $this->catalog->publicCategory($siteId, isset($product['category_id']) ? (int) $product['category_id'] : null),
            'media' => $media,
            'main_asset' => $this->mainAsset($media),
            'gallery_assets' => $this->galleryAssets($media),
            'public_attributes' => $this->catalog->productAttributes((int) $product['id'], $languageCode),
            'variants' => $variants,
            'availability' => $availability,
            'is_sellable_public' => (bool) ($availability['available'] ?? false) && $this->hasSellableVariant($variants),
            'updated_at' => (string) ($product['updated_at'] ?? ''),
        ];
        if ($detailed) {
            $payload['description'] = (string) ($product['description'] ?? '');
            $payload['options'] = $this->catalog->productOptions((int) $product['id']);
            $payload['tags'] = $this->catalog->productTags($siteId, (int) $product['id']);
        }
        return $payload;
    }

    /** @param array<string,mixed> $product @param array<string,mixed> $variant */
    private function variantPayload(array $product, array $variant, bool $detailed, string $languageCode): array
    {
        $pricing = $this->publicPricing((int) $variant['id']);
        $availability = $this->variantAvailability($product, $variant);
        $media = $detailed ? $this->publicMedia($this->catalog->productMedia((int) $product['id'], (int) $variant['id'])) : [];
        $payload = [
            'id' => (int) $variant['id'],
            'product_id' => (int) $variant['product_id'],
            'sku' => (string) $variant['sku'],
            'name' => (string) $variant['name'],
            'options' => $this->catalog->variantOptions((int) $variant['id']),
            'public_attributes' => $this->catalog->variantAttributes((int) $variant['id'], $languageCode),
            'pricing' => $pricing,
            'availability' => $availability,
            'is_sellable_public' => $this->isVariantSellablePublic($pricing, $availability),
        ];
        if ($detailed) {
            $payload['media'] = $media;
            $payload['main_asset'] = $this->mainAsset($media);
            $payload['gallery_assets'] = $this->galleryAssets($media);
        }
        return $payload;
    }

    private function publicPricing(int $variantId): ?array
    {
        try {
            $summary = $this->pricing->publicPricingPayload($this->pricing->pricingSummary($variantId, $this->channel()));
        } catch (Throwable) {
            return null;
        }
        $discount = null;
        if (is_array($summary['active_discount'] ?? null)) {
            $discount = [
                'label' => (string) ($summary['active_discount']['name'] ?? ''),
                'type' => (string) ($summary['active_discount']['type'] ?? ''),
                'value' => number_format((float) ($summary['active_discount']['value'] ?? 0), 2, '.', ''),
            ];
        }
        return [
            'regular_sale_price' => (string) ($summary['regular_sale_price'] ?? '0.00'),
            'final_sale_price' => (string) ($summary['final_sale_price'] ?? $summary['regular_sale_price'] ?? '0.00'),
            'currency' => (string) ($summary['currency'] ?? 'CHF'),
            'discount' => $discount,
        ];
    }

    /** @param list<array<string,mixed>> $media */
    private function publicMedia(array $media): array
    {
        return array_values(array_map(static fn(array $row): array => [
            'asset_id' => (int) ($row['asset_id'] ?? $row['id'] ?? 0),
            'media_id' => (int) ($row['media_id'] ?? 0),
            'variant_id' => isset($row['variant_id']) ? (int) $row['variant_id'] : null,
            'role' => (string) ($row['role'] ?? 'gallery'),
            'title' => (string) ($row['title'] ?? ''),
            'alt_text' => (string) ($row['alt_text'] ?? ''),
            'caption' => (string) ($row['caption'] ?? ''),
            'url' => (string) ($row['url'] ?? ('/media/' . (int) ($row['media_id'] ?? 0))),
        ], array_values(array_filter($media, static function (array $row): bool {
            $role = (string) ($row['role'] ?? 'gallery');
            return $role !== 'internal' && (bool) ($row['is_public'] ?? true);
        }))));
    }

    /** @param list<array<string,mixed>> $media */
    private function mainAsset(array $media): ?array
    {
        foreach (['main', 'variant', 'thumbnail'] as $role) {
            foreach ($media as $asset) {
                if (($asset['role'] ?? '') === $role) {
                    return $asset;
                }
            }
        }
        return $media[0] ?? null;
    }

    /** @param list<array<string,mixed>> $media @return list<array<string,mixed>> */
    private function galleryAssets(array $media): array
    {
        $mainId = $this->mainAsset($media)['asset_id'] ?? null;
        return array_values(array_filter($media, static fn(array $asset): bool => ($asset['asset_id'] ?? null) !== $mainId));
    }

    /** @param array<string,mixed>|null $pricing @param array<string,mixed> $availability */
    private function isVariantSellablePublic(?array $pricing, array $availability): bool
    {
        if ($pricing === null || !((bool) ($availability['available'] ?? false))) {
            return false;
        }
        return (float) ($pricing['final_sale_price'] ?? $pricing['regular_sale_price'] ?? 0) > 0.0;
    }

    /** @param list<array<string,mixed>> $variants */
    private function hasSellableVariant(array $variants): bool
    {
        foreach ($variants as $variant) {
            if ((bool) ($variant['is_sellable_public'] ?? false)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $product @param array<string,mixed> $variant */
    private function variantAvailability(array $product, array $variant): array
    {
        $trackStock = array_key_exists('track_stock', $variant) && $variant['track_stock'] !== null ? (bool) $variant['track_stock'] : (bool) ($product['track_stock'] ?? false);
        $allowBackorder = array_key_exists('allow_backorder', $variant) && $variant['allow_backorder'] !== null ? (bool) $variant['allow_backorder'] : (bool) ($product['allow_backorder'] ?? false);
        $available = true;
        if ($trackStock && !$allowBackorder) {
            $available = ((float) ($variant['stock_quantity'] ?? 0) - (float) ($variant['stock_reserved'] ?? 0)) > 0;
        }
        return ['available' => $available, 'backorder_allowed' => $allowBackorder];
    }

    /** @param array<string,mixed> $product @param list<array<string,mixed>> $variants */
    private function productAvailability(array $product, array $variants): array
    {
        foreach ($variants as $variant) {
            if (!empty($variant['availability']['available'])) {
                return ['available' => true];
            }
        }
        return ['available' => !$product['track_stock'] && $variants !== []];
    }

    /** @return array<string,mixed> */
    private function filters(): array
    {
        $filters = [];
        foreach (['q', 'brand', 'brand_id', 'category', 'category_id'] as $key) {
            if (array_key_exists($key, $this->request->query)) {
                $filters[$key] = $key === 'brand_id' || $key === 'category_id' ? (int) $this->request->query[$key] : $this->slug((string) $this->request->query[$key]);
            }
        }
        return $filters;
    }

    private function limit(): int
    {
        return max(1, min(100, (int) ($this->request->query['limit'] ?? 24)));
    }

    private function offset(): int
    {
        if (isset($this->request->query['page'])) {
            return max(0, ((int) $this->request->query['page'] - 1) * $this->limit());
        }
        return max(0, (int) ($this->request->query['offset'] ?? 0));
    }

    private function channel(): string
    {
        $channel = $this->slug((string) ($this->request->query['channel'] ?? 'ecommerce'));
        return in_array($channel, ['ecommerce', 'pos', 'admin', 'all'], true) ? $channel : 'ecommerce';
    }

    private function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_-]+/', '-', $value) ?: '';
        return trim($value, '-_');
    }
}
