<?php

declare(strict_types=1);

namespace App\Application\PublicApi;

use App\Application\Business\StorefrontProjectionRepository;
use App\Core\ErrorCode;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Business\Catalog\CatalogPricingService;
use App\Modules\Business\Repositories\PublicCatalogRepository;
use App\Modules\Business\Services\BusinessProductBundleService;
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
        private readonly ?BusinessProductBundleService $bundles = null,
        private readonly ?StorefrontProjectionRepository $storefront = null,
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
        return $this->json(['variant' => $this->variantPayload((int) $site['id'], $product, $variant, true, $languageCode)], 'public.catalog.variants.show.v1', $site, $languageCode);
    }

    public function storefrontProducts(): Response
    {
        [$site,$languageCode]=$this->context(); $channel=$this->storefront?->defaultChannelId((int)$site['id'])??0;
        if ($channel<1 || $this->storefront===null) return $this->notFound('Projection Storefront indisponible.',[]);
        $page=$this->storefront->products((int)$site['id'],$channel,$languageCode,$this->filters()+['limit'=>$this->limit(),'offset'=>$this->offset(),'sort'=>$this->request->query['sort']??'name']);
        return $this->json($page,'public.storefront.products.index.v1',$site,$languageCode);
    }

    public function storefrontProduct(string $slug): Response
    {
        [$site,$languageCode]=$this->context(); $channel=$this->storefront?->defaultChannelId((int)$site['id'])??0;
        $product=$this->storefront?->product((int)$site['id'],$channel,$languageCode,$this->slug($slug));
        return $product ? $this->json(['product'=>$product],'public.storefront.products.show.v1',$site,$languageCode) : $this->notFound('Produit projeté introuvable.',['slug'=>$slug]);
    }

    public function storefrontCollections(): Response
    {
        [$site,$languageCode]=$this->context(); $channel=$this->storefront?->defaultChannelId((int)$site['id'])??0;
        return $this->json(['items'=>$this->storefront?->collections((int)$site['id'],$channel,$languageCode)??[]],'public.storefront.collections.index.v1',$site,$languageCode);
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
            'source' => str_starts_with($contract, 'public.storefront.') ? 'core_storefront_projections' : 'business_catalog_public',
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
        $variants = array_map(fn(array $variant): array => $this->variantPayload($siteId, $product, $variant, $detailed, $languageCode), $this->catalog->activeVariants($siteId, (int) $product['id']));
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
    private function variantPayload(int $siteId, array $product, array $variant, bool $detailed, string $languageCode): array
    {
        $pricing = $this->publicPricing((int) $variant['id']);
        $availability = $this->variantAvailability($siteId, $product, $variant);
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
        if ((string) ($product['type'] ?? '') === 'bundle') {
            $summary = $this->bundles->bundleSummaryForVariant($siteId, (int) $variant['id']);
            if ((bool) ($summary['is_bundle'] ?? false)) $payload['bundle'] = $this->publicBundlePayload($summary, $detailed);
        }
        return $payload;
    }

    /** @param array<string,mixed> $summary @return array<string,mixed> */
    private function publicBundlePayload(array $summary, bool $detailed): array
    {
        $status = (string) ($summary['bundle_availability_status'] ?? 'unavailable');
        $limiting = is_array($summary['bundle_limiting_factor'] ?? null) ? $summary['bundle_limiting_factor'] : null;
        $payload = [
            'contract' => 'business.bundle.stock-strategy.v1',
            'stock_strategy' => (string) ($summary['bundle_stock_strategy'] ?? 'COMPONENT_DERIVED'),
            'availability_status' => $status,
            'availability_explanation' => match ($status) { 'in_stock' => 'Bundle disponible', 'deliverable' => 'Bundle disponible sans suivi physique', 'backorder' => 'Bundle disponible sur commande', default => 'Bundle momentanément indisponible' },
            'limiting_component' => $limiting === null ? null : ['name' => (string) ($limiting['name'] ?? ''), 'sku' => (string) ($limiting['sku'] ?? '')],
            'return_policy' => (string) ($summary['bundle_component_return_policy'] ?? 'BUNDLE_ONLY'),
            'partial_availability_policy' => (string) ($summary['bundle_partial_availability_policy'] ?? 'REQUIRE_ALL'),
        ];
        if ($detailed && (bool) ($summary['bundle_components_public'] ?? true)) {
            $payload['included_components'] = array_values(array_map(static fn(array $component): array => [
                'name' => (string) ($component['component_name'] ?? ''),
                'variant_name' => (string) ($component['component_variant_name'] ?? ''),
                'quantity' => (float) ($component['quantity'] ?? 1),
                'required' => (bool) ($component['is_required'] ?? true),
            ], array_values(array_filter((array) ($summary['bundle_components'] ?? []), 'is_array'))));
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
    private function variantAvailability(int $siteId, array $product, array $variant): array
    {
        $trackStock = array_key_exists('track_stock', $variant) && $variant['track_stock'] !== null ? (bool) $variant['track_stock'] : (bool) ($product['track_stock'] ?? false);
        $allowBackorder = array_key_exists('allow_backorder', $variant) && $variant['allow_backorder'] !== null ? (bool) $variant['allow_backorder'] : (bool) ($product['allow_backorder'] ?? false);
        $backorderDeliveryDays = array_key_exists('backorder_delivery_days', $variant) && $variant['backorder_delivery_days'] !== null ? (int) $variant['backorder_delivery_days'] : (int) ($product['backorder_delivery_days'] ?? 7);
        $availableQuantity = (float) ($variant['stock_quantity'] ?? 0) - (float) ($variant['stock_reserved'] ?? 0);
        $availability = $this->availabilityPayload($trackStock, $allowBackorder, $availableQuantity, $backorderDeliveryDays);
        if ($this->bundles !== null && (string) ($product['type'] ?? '') === 'bundle') {
            $summary = $this->bundles->bundleSummaryForVariant($siteId, (int) $variant['id']);
            $availability = $this->bundleAvailabilityPayload($summary, $availability);
        }
        return $availability;
    }

    /** @param array<string,mixed> $product @param list<array<string,mixed>> $variants */
    private function productAvailability(array $product, array $variants): array
    {
        $backorder = null;
        foreach ($variants as $variant) {
            $availability = $variant['availability'] ?? [];
            if (($availability['status'] ?? '') === 'in_stock') {
                return $availability;
            }
            if (!empty($availability['available']) && $backorder === null) {
                $backorder = $availability;
            }
        }
        if ($backorder !== null) {
            return $backorder;
        }
        return $variants === []
            ? $this->availabilityPayload(true, false, 0.0, (int) ($product['backorder_delivery_days'] ?? 7))
            : $this->availabilityPayload(true, false, 0.0, (int) ($product['backorder_delivery_days'] ?? 7));
    }

    /** @return array<string,mixed> */
    private function availabilityPayload(bool $trackStock, bool $allowBackorder, float $availableQuantity, int $backorderDeliveryDays): array
    {
        if (!$trackStock) {
            return ['contract' => 'sale.inventory.availability.v1', 'available' => true, 'backorder_allowed' => false, 'status' => 'deliverable', 'label' => 'Livrable', 'is_orderable' => true, 'delivery_lead_time_days' => null, 'last_available' => false];
        }
        if ($availableQuantity > 0.0) {
            return ['contract' => 'sale.inventory.availability.v1', 'available' => true, 'backorder_allowed' => false, 'status' => 'in_stock', 'label' => 'Livrable immediatement', 'is_orderable' => true, 'delivery_lead_time_days' => null, 'last_available' => $availableQuantity === 1.0];
        }
        if ($allowBackorder) {
            $days = max(1, $backorderDeliveryDays);
            return ['contract' => 'sale.inventory.availability.v1', 'available' => true, 'backorder_allowed' => true, 'status' => 'backorder', 'label' => 'Livraison sous ' . $days . ' jours', 'is_orderable' => true, 'delivery_lead_time_days' => $days, 'last_available' => false];
        }
        return ['contract' => 'sale.inventory.availability.v1', 'available' => false, 'backorder_allowed' => false, 'status' => 'unavailable', 'label' => 'Indisponible', 'is_orderable' => false, 'delivery_lead_time_days' => null, 'last_available' => false];
    }

    /** @param array<string,mixed> $summary @param array<string,mixed> $fallback @return array<string,mixed> */
    private function bundleAvailabilityPayload(array $summary, array $fallback): array
    {
        if (!((bool) ($summary['is_bundle'] ?? false))) {
            return $fallback;
        }
        return match ((string) ($summary['bundle_availability_status'] ?? 'in_stock')) {
            'backorder' => $this->availabilityPayload(true, true, 0.0, max(1, (int) ($summary['bundle_backorder_delivery_days'] ?? $fallback['delivery_lead_time_days'] ?? 7))),
            'contact_us', 'unavailable' => $this->availabilityPayload(true, false, 0.0, 7),
            'in_stock' => $this->availabilityPayload(true, false, max(1.0, (float) ($summary['bundle_available_quantity'] ?? 1)), 7),
            default => $this->availabilityPayload(false, false, 1.0, 7),
        };
    }

    /** @return array<string,mixed> */
    private function filters(): array
    {
        $filters = [];
        foreach (['q', 'brand', 'brand_id', 'category', 'category_id', 'collection_id'] as $key) {
            if (array_key_exists($key, $this->request->query)) {
                $filters[$key] = in_array($key, ['brand_id','category_id','collection_id'], true) ? (int) $this->request->query[$key] : $this->slug((string) $this->request->query[$key]);
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
