<?php

declare(strict_types=1);

namespace App\Application\PublicApi;

use App\Core\Request;
use App\Core\Response;
use App\Modules\Business\Catalog\CatalogPricingService;
use App\Modules\Business\Repositories\PosCatalogRepository;
use App\Modules\Business\Services\BusinessProductBundleService;
use App\Repository\SiteRepository;
use Throwable;

final class PosCatalogApiHandler
{
    private PublicApiResponder $responder;

    public function __construct(
        private readonly Request $request,
        private readonly SiteRepository $sites,
        private readonly PosCatalogRepository $catalog,
        private readonly CatalogPricingService $pricing,
        private readonly ?BusinessProductBundleService $bundles = null,
    ) {
        $this->responder = new PublicApiResponder();
    }

    public function bootstrap(): Response
    {
        [$site, $languageCode] = $this->context();
        $siteId = (int) $site['id'];
        $products = $this->catalog->products($siteId, $this->filters(), $this->limit(100), $this->offset());

        return $this->json([
            'brands' => $this->catalog->brands($siteId),
            'categories' => $this->catalog->categories($siteId),
            'products' => array_map(fn(array $product): array => $this->productPayload($siteId, $product, true), $products['items']),
            'pagination' => $this->pagination($products),
            'sync' => [
                'updated_since' => $this->updatedSince(),
                'generated_at' => now_utc(),
            ],
        ], 'pos.catalog.bootstrap.v1', $site, $languageCode);
    }

    public function products(): Response
    {
        [$site, $languageCode] = $this->context();
        $page = $this->catalog->products((int) $site['id'], $this->filters(), $this->limit(100), $this->offset());

        return $this->json([
            'items' => array_map(fn(array $product): array => $this->productPayload((int) $site['id'], $product, false), $page['items']),
            'pagination' => $this->pagination($page),
        ], 'pos.catalog.products.index.v1', $site, $languageCode);
    }

    public function variants(): Response
    {
        [$site, $languageCode] = $this->context();
        $page = $this->catalog->variants((int) $site['id'], $this->filters(['barcode', 'product_id', 'q', 'updated_since']), $this->limit(250), $this->offset());

        return $this->json([
            'items' => array_map(fn(array $variant): array => $this->variantPayload($variant), $page['items']),
            'pagination' => $this->pagination($page),
        ], 'pos.catalog.variants.index.v1', $site, $languageCode);
    }

    public function brands(): Response
    {
        [$site, $languageCode] = $this->context();
        return $this->json(['items' => $this->catalog->brands((int) $site['id'])], 'pos.catalog.brands.index.v1', $site, $languageCode);
    }

    public function categories(): Response
    {
        [$site, $languageCode] = $this->context();
        return $this->json(['items' => $this->catalog->categories((int) $site['id'])], 'pos.catalog.categories.index.v1', $site, $languageCode);
    }

    /** @return array{0:array<string,mixed>,1:string} */
    private function context(): array
    {
        $site = $this->sites->resolveCurrentSite((string) ($this->request->server['HTTP_HOST'] ?? ''));
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
            'source' => 'business_catalog_pos',
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    /** @param array<string,mixed> $product */
    private function productPayload(int $siteId, array $product, bool $includeVariants): array
    {
        $payload = [
            'product_id' => (int) $product['id'],
            'name' => (string) $product['name'],
            'short_name' => $this->shortName((string) $product['name']),
            'slug' => (string) $product['slug'],
            'type' => (string) ($product['type'] ?? 'physical'),
            'summary' => (string) ($product['short_description'] ?? ''),
            'brand' => $this->catalog->brand($siteId, isset($product['brand_id']) ? (int) $product['brand_id'] : null),
            'category' => $this->catalog->category($siteId, isset($product['category_id']) ? (int) $product['category_id'] : null),
            'tax' => $this->taxPayload($this->catalog->taxClass(isset($product['tax_class_id']) ? (int) $product['tax_class_id'] : null)),
            'thumbnail' => $this->catalog->thumbnail((int) $product['id']),
            'updated_at' => (string) ($product['updated_at'] ?? ''),
        ];
        if ($includeVariants) {
            $payload['variants'] = array_map(fn(array $variant): array => $this->variantPayload($variant), $this->catalog->variantsForProduct($siteId, (int) $product['id']));
        }
        return $payload;
    }

    /** @param array<string,mixed> $variant */
    private function variantPayload(array $variant): array
    {
        return [
            'variant_id' => (int) $variant['id'],
            'product_id' => (int) $variant['product_id'],
            'name' => (string) ($variant['name'] ?: $variant['product_name'] ?? ''),
            'short_name' => $this->shortName((string) (($variant['name'] ?? '') ?: ($variant['product_name'] ?? ''))),
            'sku' => (string) ($variant['sku'] ?? ''),
            'barcode' => (string) ($variant['barcode'] ?? ''),
            'brand' => $this->catalog->brand((int) $variant['site_id'], isset($variant['brand_id']) ? (int) $variant['brand_id'] : null),
            'category' => $this->catalog->category((int) $variant['site_id'], isset($variant['category_id']) ? (int) $variant['category_id'] : null),
            'options' => $this->catalog->variantOptions((int) $variant['id']),
            'pricing' => $this->pricingPayload((int) $variant['id']),
            'tax' => $this->taxPayload($this->catalog->taxClass(isset($variant['tax_class_id']) ? (int) $variant['tax_class_id'] : null)),
            'availability' => $this->variantAvailability($variant),
            'thumbnail' => $this->catalog->thumbnail((int) $variant['product_id'], (int) $variant['id']),
            'updated_at' => (string) ($variant['updated_at'] ?? ''),
        ];
    }

    private function pricingPayload(int $variantId): ?array
    {
        try {
            $summary = $this->pricing->publicPricingPayload($this->pricing->pricingSummary($variantId, 'pos'));
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

    /** @param array<string,mixed>|null $tax */
    private function taxPayload(?array $tax): ?array
    {
        if ($tax === null) {
            return null;
        }
        return [
            'id' => (int) ($tax['id'] ?? 0),
            'code' => (string) ($tax['code'] ?? ''),
            'name' => (string) ($tax['name'] ?? ''),
            'rate' => number_format((float) ($tax['rate'] ?? 0), 4, '.', ''),
            'country' => (string) ($tax['country'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $variant */
    private function variantAvailability(array $variant): array
    {
        $trackStock = array_key_exists('track_stock', $variant) && $variant['track_stock'] !== null ? (bool) $variant['track_stock'] : (bool) ($variant['product_track_stock'] ?? false);
        $allowBackorder = array_key_exists('allow_backorder', $variant) && $variant['allow_backorder'] !== null ? (bool) $variant['allow_backorder'] : (bool) ($variant['product_allow_backorder'] ?? false);
        $backorderDeliveryDays = array_key_exists('backorder_delivery_days', $variant) && $variant['backorder_delivery_days'] !== null ? (int) $variant['backorder_delivery_days'] : (int) ($variant['product_backorder_delivery_days'] ?? 7);
        $availableQuantity = (float) ($variant['stock_quantity'] ?? 0) - (float) ($variant['stock_reserved'] ?? 0);
        $availability = $this->availabilityPayload($trackStock, $allowBackorder, $availableQuantity, $backorderDeliveryDays);
        if ($this->bundles !== null && (string) ($variant['product_type'] ?? '') === 'bundle') {
            $summary = $this->bundles->bundleSummaryForVariant((int) $variant['site_id'], (int) $variant['id']);
            $availability = $this->bundleAvailabilityPayload($summary, $availability);
        }
        return $availability;
    }

    /** @return array<string,mixed> */
    private function availabilityPayload(bool $trackStock, bool $allowBackorder, float $availableQuantity, int $backorderDeliveryDays): array
    {
        if (!$trackStock || $availableQuantity > 0.0) {
            return ['available' => true, 'backorder_allowed' => false, 'status' => 'in_stock', 'label' => 'Livrable immediatement', 'is_orderable' => true, 'delivery_lead_time_days' => null];
        }
        if ($allowBackorder) {
            $days = max(1, $backorderDeliveryDays);
            return ['available' => true, 'backorder_allowed' => true, 'status' => 'backorder', 'label' => 'Livraison sous ' . $days . ' jours', 'is_orderable' => true, 'delivery_lead_time_days' => $days];
        }
        return ['available' => false, 'backorder_allowed' => false, 'status' => 'contact_us', 'label' => 'Nous contacter pour commander ce produit', 'is_orderable' => false, 'delivery_lead_time_days' => null];
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

    /** @param array{limit:int,offset:int,total:int,has_more?:bool} $page */
    private function pagination(array $page): array
    {
        return [
            'total' => (int) $page['total'],
            'limit' => (int) $page['limit'],
            'offset' => (int) $page['offset'],
            'has_more' => (bool) ($page['has_more'] ?? (((int) $page['offset'] + (int) $page['limit']) < (int) $page['total'])),
        ];
    }

    /** @param list<string>|null $allowed */
    private function filters(?array $allowed = null): array
    {
        $allowed ??= ['q', 'updated_since'];
        $filters = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $this->request->query)) {
                continue;
            }
            $value = trim((string) $this->request->query[$key]);
            if ($value === '') {
                continue;
            }
            $filters[$key] = $key === 'product_id' ? max(0, (int) $value) : $value;
        }
        return $filters;
    }

    private function limit(int $default): int
    {
        return max(1, min(1000, (int) ($this->request->query['limit'] ?? $default)));
    }

    private function offset(): int
    {
        return max(0, (int) ($this->request->query['offset'] ?? 0));
    }

    private function updatedSince(): ?string
    {
        $value = trim((string) ($this->request->query['updated_since'] ?? ''));
        return $value !== '' ? $value : null;
    }

    private function shortName(string $name): string
    {
        $name = trim($name);
        return substr($name, 0, 80);
    }
}
