<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Core\ErrorCode;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Business\Catalog\CatalogPricingService;
use App\Modules\Business\Repositories\CatalogBrandRepository;
use App\Modules\Business\Repositories\CatalogCategoryRepository;
use App\Modules\Business\Repositories\CatalogDiscountRepository;
use App\Modules\Business\Repositories\CatalogOptionRepository;
use App\Modules\Business\Repositories\CatalogProductRepository;
use App\Modules\Business\Repositories\CatalogVariantRepository;
use App\Modules\Business\Services\CatalogCsvService;
use App\Modules\Business\Services\CatalogDiscountService;
use App\Modules\Business\Services\CatalogProductService;
use App\Modules\Business\Services\CatalogStockService;
use App\Modules\Business\Services\CatalogVariantService;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;
use InvalidArgumentException;
use Throwable;

final class BusinessCatalogApiController
{
    public function __construct(
        private readonly Request $request,
        private readonly SiteRepository $sites,
        private readonly AuthRepository $auth,
        private readonly Authorization $authorization,
        private readonly CatalogBrandRepository $brands,
        private readonly CatalogCategoryRepository $categories,
        private readonly CatalogProductRepository $products,
        private readonly CatalogVariantRepository $variants,
        private readonly CatalogOptionRepository $options,
        private readonly CatalogDiscountRepository $discounts,
        private readonly CatalogProductService $productService,
        private readonly CatalogVariantService $variantService,
        private readonly CatalogDiscountService $discountService,
        private readonly CatalogStockService $stockService,
        private readonly CatalogCsvService $csv,
        private readonly CatalogPricingService $pricing,
    ) {}

    public function exportCatalogCsv(): Response
    {
        [$site] = $this->authorize('business.catalog.read');
        return new Response(200, $this->csv->exportProductsCsv((int) $site['id'], $this->canReadPurchasePrices((int) $site['id'])), [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="business-catalog-products.csv"',
        ]);
    }

    public function previewCatalogImport(): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            $payload = $this->payload();
            $payload['dry_run'] = true;
            $report = $this->csv->importProductsCsv((int) $site['id'], $this->csvInput(), $payload, $this->actorId());
            return Response::success(['import' => $report], 'admin.business.catalog.import.preview.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function applyCatalogImport(): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            $payload = $this->payload();
            $payload['dry_run'] = false;
            $report = $this->csv->importProductsCsv((int) $site['id'], $this->csvInput(), $payload, $this->actorId());
            return Response::success(['import' => $report, 'message' => 'Import catalogue appliqué.'], 'admin.business.catalog.import.apply.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function brands(): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.read');
        $result = $this->brands->list((int) $site['id'], $this->q(), $this->limit(), $this->offset(), $this->includeArchived());
        return Response::success(['brands' => $result['items'], 'pagination' => $this->pagination($result)], 'admin.business.catalog.brands.index.v1', $this->meta($site, $languageCode));
    }

    public function storeBrand(): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            $brand = $this->brands->create((int) $site['id'], $this->payload(), $this->actorId());
            return Response::success(['brand' => $brand, 'message' => 'Marque créée.'], 'admin.business.catalog.brands.show.v1', $this->meta($site, $languageCode), 201);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function showBrand(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.read');
        $brand = $this->brands->find((int) $site['id'], $this->id($id), true);
        return $brand ? Response::success(['brand' => $brand], 'admin.business.catalog.brands.show.v1', $this->meta($site, $languageCode)) : $this->notFound('Marque introuvable.', $id);
    }

    public function updateBrand(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            $brand = $this->brands->update((int) $site['id'], $this->id($id), $this->payload(), $this->actorId());
            return $brand ? Response::success(['brand' => $brand, 'message' => 'Marque mise à jour.'], 'admin.business.catalog.brands.show.v1', $this->meta($site, $languageCode)) : $this->notFound('Marque introuvable.', $id);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function deleteBrand(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        $this->brands->archive((int) $site['id'], $this->id($id), $this->actorId());
        return Response::success(['deleted' => true, 'archived' => true, 'id' => $this->id($id)], 'admin.business.catalog.brands.delete.v1', $this->meta($site, $languageCode));
    }

    public function categories(): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.read');
        $result = $this->categories->list((int) $site['id'], $this->q(), $this->limit(), $this->offset(), $this->includeArchived());
        return Response::success(['categories' => $result['items'], 'pagination' => $this->pagination($result)], 'admin.business.catalog.categories.index.v1', $this->meta($site, $languageCode));
    }

    public function storeCategory(): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            $category = $this->categories->create((int) $site['id'], $this->payload(), $this->actorId());
            return Response::success(['category' => $category, 'message' => 'Catégorie créée.'], 'admin.business.catalog.categories.show.v1', $this->meta($site, $languageCode), 201);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function showCategory(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.read');
        $category = $this->categories->find((int) $site['id'], $this->id($id), true);
        return $category ? Response::success(['category' => $category], 'admin.business.catalog.categories.show.v1', $this->meta($site, $languageCode)) : $this->notFound('Catégorie introuvable.', $id);
    }

    public function updateCategory(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            $category = $this->categories->update((int) $site['id'], $this->id($id), $this->payload(), $this->actorId());
            return $category ? Response::success(['category' => $category, 'message' => 'Catégorie mise à jour.'], 'admin.business.catalog.categories.show.v1', $this->meta($site, $languageCode)) : $this->notFound('Catégorie introuvable.', $id);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function deleteCategory(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        $this->categories->archive((int) $site['id'], $this->id($id), $this->actorId());
        return Response::success(['deleted' => true, 'archived' => true, 'id' => $this->id($id)], 'admin.business.catalog.categories.delete.v1', $this->meta($site, $languageCode));
    }

    public function products(): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.read');
        $result = $this->products->list((int) $site['id'], $this->productFilters(), $this->limit(), $this->offset(), $this->includeArchived());
        return Response::success(['products' => array_map(fn(array $product): array => $this->safeProduct($product, (int) $site['id']), $result['items']), 'pagination' => $this->pagination($result)], 'admin.business.catalog.products.index.v1', $this->meta($site, $languageCode));
    }

    public function storeProduct(): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            $product = $this->productService->create((int) $site['id'], $this->payload(), $this->actorId());
            $this->syncProductOptions((int) $site['id'], (int) $product['id'], $this->payload());
            return Response::success(['product' => $this->productPayload((int) $site['id'], (int) $product['id']), 'message' => 'Produit créé.'], 'admin.business.catalog.products.show.v1', $this->meta($site, $languageCode), 201);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function showProduct(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.read');
        $payload = $this->productPayload((int) $site['id'], $this->id($id), true);
        return $payload ? Response::success(['product' => $payload], 'admin.business.catalog.products.show.v1', $this->meta($site, $languageCode)) : $this->notFound('Produit introuvable.', $id);
    }

    public function updateProduct(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            $product = $this->productService->update((int) $site['id'], $this->id($id), $this->payload(), $this->actorId());
            if (!$product) {
                return $this->notFound('Produit introuvable.', $id);
            }
            $this->syncProductOptions((int) $site['id'], $this->id($id), $this->payload());
            return Response::success(['product' => $this->productPayload((int) $site['id'], $this->id($id)), 'message' => 'Produit mis à jour.'], 'admin.business.catalog.products.show.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function deleteProduct(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        $this->productService->archive((int) $site['id'], $this->id($id), $this->actorId());
        return Response::success(['deleted' => true, 'archived' => true, 'id' => $this->id($id)], 'admin.business.catalog.products.delete.v1', $this->meta($site, $languageCode));
    }

    public function productVariants(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.read');
        try {
            $variants = array_map(fn(array $variant): array => $this->variantPayload((int) $site['id'], $variant), $this->variants->listForProduct((int) $site['id'], $this->id($id), $this->includeArchived()));
            return Response::success(['variants' => $variants], 'admin.business.catalog.variants.index.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function storeVariant(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            $variant = $this->variantService->create((int) $site['id'], $this->id($id), $this->payload(), $this->actorId());
            return Response::success(['variant' => $this->variantPayload((int) $site['id'], $variant), 'message' => 'Variante créée.'], 'admin.business.catalog.variants.show.v1', $this->meta($site, $languageCode), 201);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function showVariant(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.read');
        $variant = $this->variants->findById($this->id($id), true);
        if (!$variant || (int) $variant['site_id'] !== (int) $site['id']) {
            return $this->notFound('Variante introuvable.', $id);
        }
        return Response::success(['variant' => $this->variantPayload((int) $site['id'], $variant)], 'admin.business.catalog.variants.show.v1', $this->meta($site, $languageCode));
    }

    public function updateVariant(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            $variant = $this->variants->update((int) $site['id'], $this->id($id), $this->payload(), $this->actorId());
            return $variant ? Response::success(['variant' => $this->variantPayload((int) $site['id'], $variant), 'message' => 'Variante mise à jour.'], 'admin.business.catalog.variants.show.v1', $this->meta($site, $languageCode)) : $this->notFound('Variante introuvable.', $id);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function deleteVariant(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        $this->variantService->archive((int) $site['id'], $this->id($id), $this->actorId());
        return Response::success(['deleted' => true, 'archived' => true, 'id' => $this->id($id)], 'admin.business.catalog.variants.delete.v1', $this->meta($site, $languageCode));
    }

    public function options(): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.read');
        $result = $this->options->list((int) $site['id'], $this->limit(), $this->offset(), $this->includeArchived());
        return Response::success(['options' => $result['items'], 'pagination' => $this->pagination($result)], 'admin.business.catalog.options.index.v1', $this->meta($site, $languageCode));
    }

    public function storeOption(): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            $option = $this->options->create((int) $site['id'], $this->payload(), $this->actorId());
            return Response::success(['option' => $option, 'message' => 'Option créée.'], 'admin.business.catalog.options.show.v1', $this->meta($site, $languageCode), 201);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function updateOption(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            $option = $this->options->update((int) $site['id'], $this->id($id), $this->payload(), $this->actorId());
            return $option ? Response::success(['option' => $option, 'message' => 'Option mise à jour.'], 'admin.business.catalog.options.show.v1', $this->meta($site, $languageCode)) : $this->notFound('Option introuvable.', $id);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function deleteOption(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        $this->options->archive((int) $site['id'], $this->id($id), $this->actorId());
        return Response::success(['deleted' => true, 'archived' => true, 'id' => $this->id($id)], 'admin.business.catalog.options.delete.v1', $this->meta($site, $languageCode));
    }

    public function storeOptionValue(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            $value = $this->options->addValue((int) $site['id'], $this->id($id), $this->payload(), $this->actorId());
            return Response::success(['option_value' => $value, 'message' => 'Valeur créée.'], 'admin.business.catalog.option_values.show.v1', $this->meta($site, $languageCode), 201);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function updateOptionValue(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            $value = $this->options->updateValue((int) $site['id'], $this->id($id), $this->payload(), $this->actorId());
            return $value ? Response::success(['option_value' => $value, 'message' => 'Valeur mise à jour.'], 'admin.business.catalog.option_values.show.v1', $this->meta($site, $languageCode)) : $this->notFound('Valeur introuvable.', $id);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function deleteOptionValue(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        $this->options->archiveValue((int) $site['id'], $this->id($id), $this->actorId());
        return Response::success(['deleted' => true, 'archived' => true, 'id' => $this->id($id)], 'admin.business.catalog.option_values.delete.v1', $this->meta($site, $languageCode));
    }

    public function productPrices(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.prices.read');
        $product = $this->products->find((int) $site['id'], $this->id($id), true);
        if (!$product) {
            return $this->notFound('Produit introuvable.', $id);
        }
        return Response::success(['prices' => $this->safePrices($this->products->prices((int) $site['id'], $this->id($id)))], 'admin.business.catalog.prices.index.v1', $this->meta($site, $languageCode));
    }

    public function setProductBasePrices(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.prices.write');
        if (!$this->products->find((int) $site['id'], $this->id($id), true)) {
            return $this->notFound('Produit introuvable.', $id);
        }
        try {
            foreach ($this->basePricePayload($this->payload()) as $price) {
                if (($price['price_kind'] ?? '') === 'purchase') {
                    $this->authorization->require('business.catalog.purchase_prices.read', (int) $site['id']);
                }
                $this->products->setBasePrice($this->id($id), (string) $price['price_kind'], $price['amount'], (string) ($price['currency'] ?? 'CHF'), (bool) ($price['tax_included'] ?? true), $this->actorId());
            }
            return Response::success(['prices' => $this->safePrices($this->products->prices((int) $site['id'], $this->id($id))), 'message' => 'Prix de base mis à jour.'], 'admin.business.catalog.prices.index.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function setVariantPriceAdjustments(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.prices.write');
        $variant = $this->variants->findById($this->id($id), true);
        if (!$variant || (int) $variant['site_id'] !== (int) $site['id']) {
            return $this->notFound('Variante introuvable.', $id);
        }
        try {
            foreach ($this->adjustmentPayload($this->payload()) as $priceKind => $adjustment) {
                if ($priceKind === 'purchase') {
                    $this->authorization->require('business.catalog.purchase_prices.read', (int) $site['id']);
                }
                $this->variants->setAdjustment($this->id($id), $priceKind, (string) $adjustment['type'], $adjustment['value'] ?? null, $this->actorId());
            }
            return Response::success(['variant' => $this->variantPayload((int) $site['id'], $variant), 'message' => 'Ajustements mis à jour.'], 'admin.business.catalog.variants.prices.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function computedPrices(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.prices.read');
        $variant = $this->variants->findById($this->id($id), true);
        if (!$variant || (int) $variant['site_id'] !== (int) $site['id']) {
            return $this->notFound('Variante introuvable.', $id);
        }
        return Response::success(['computed_prices' => $this->safeComputed($this->pricing->pricingSummary($this->id($id), $this->queryString('channel') ?: null))], 'admin.business.catalog.variants.computed_prices.v1', $this->meta($site, $languageCode));
    }

    public function variantStock(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.read');
        try {
            return Response::success([
                'stock' => $this->stockService->stock((int) $site['id'], $this->id($id)),
                'movements' => $this->stockService->movements((int) $site['id'], ['variant_id' => $this->id($id)], 20, 0)['items'],
            ], 'admin.business.catalog.variants.stock.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function storeStockMovement(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.stock.write');
        $payload = $this->payload();
        try {
            $result = $this->stockService->createMovement(
                (int) $site['id'],
                $this->id($id),
                (string) ($payload['movement_type'] ?? ''),
                (float) ($payload['quantity'] ?? 0),
                isset($payload['reason']) ? (string) $payload['reason'] : null,
                isset($payload['reference_type']) ? (string) $payload['reference_type'] : null,
                isset($payload['reference_id']) && $payload['reference_id'] !== '' ? (int) $payload['reference_id'] : null,
                $this->actorId()
            );
            return Response::success([
                'stock' => $this->stockService->stock((int) $site['id'], $this->id($id)),
                'movement' => $result['movement'],
                'variant' => $result['variant'],
                'message' => 'Mouvement de stock enregistré.',
            ], 'admin.business.catalog.stock_movements.store.v1', $this->meta($site, $languageCode), 201);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function stockMovements(): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.read');
        $result = $this->stockService->movements((int) $site['id'], $this->stockMovementFilters(), $this->limit(), $this->offset());
        return Response::success(['movements' => $result['items'], 'pagination' => $this->pagination($result)], 'admin.business.catalog.stock_movements.index.v1', $this->meta($site, $languageCode));
    }

    public function discounts(): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.read');
        $result = $this->discounts->list((int) $site['id'], $this->limit(), $this->offset(), $this->includeArchived());
        return Response::success(['discounts' => $result['items'], 'pagination' => $this->pagination($result)], 'admin.business.catalog.discounts.index.v1', $this->meta($site, $languageCode));
    }

    public function storeDiscount(): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.discounts.write');
        try {
            $discount = $this->discountService->create((int) $site['id'], $this->payload(), $this->actorId());
            return Response::success(['discount' => $discount, 'message' => 'Réduction créée.'], 'admin.business.catalog.discounts.show.v1', $this->meta($site, $languageCode), 201);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function showDiscount(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.read');
        $discount = $this->discounts->find((int) $site['id'], $this->id($id), true);
        return $discount ? Response::success(['discount' => $discount], 'admin.business.catalog.discounts.show.v1', $this->meta($site, $languageCode)) : $this->notFound('Réduction introuvable.', $id);
    }

    public function updateDiscount(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.discounts.write');
        try {
            $discount = $this->discounts->update((int) $site['id'], $this->id($id), $this->payload(), $this->actorId());
            return $discount ? Response::success(['discount' => $discount, 'message' => 'Réduction mise à jour.'], 'admin.business.catalog.discounts.show.v1', $this->meta($site, $languageCode)) : $this->notFound('Réduction introuvable.', $id);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function deleteDiscount(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.discounts.write');
        $this->discountService->archive((int) $site['id'], $this->id($id), $this->actorId());
        return Response::success(['deleted' => true, 'archived' => true, 'id' => $this->id($id)], 'admin.business.catalog.discounts.delete.v1', $this->meta($site, $languageCode));
    }

    /** @return array{0:array<string,mixed>,1:string} */
    private function authorize(string $permission): array
    {
        $this->auth->requireAuth();
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null, $this->auth);
        $this->authorization->require($permission, (int) $site['id']);
        return [$site, AdminApiContract::language($this->request, $this->sites, $site)];
    }

    private function canReadPurchasePrices(int $siteId): bool
    {
        return $this->auth->hasPermission('business.catalog.purchase_prices.read', $siteId);
    }

    private function actorId(): int
    {
        return (int) ($this->auth->user()['id'] ?? 0);
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        $json = $this->request->json();
        if ($json === [] && $this->request->post !== []) {
            $json = $this->request->post;
        }
        $data = $json['data'] ?? $json;
        return is_array($data) ? $data : [];
    }

    private function csvInput(): string
    {
        $payload = $this->payload();
        foreach (['csv', 'content'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key]) && trim($payload[$key]) !== '') {
                return $payload[$key];
            }
        }
        $file = $this->request->files['file'] ?? $this->request->files['csv'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
            throw new InvalidArgumentException('business.catalog.csv_file_required');
        }
        $content = file_get_contents((string) $file['tmp_name']);
        if ($content === false) {
            throw new InvalidArgumentException('business.catalog.csv_file_unreadable');
        }
        return $content;
    }

    private function q(): string
    {
        return trim((string) ($this->request->query['q'] ?? ''));
    }

    private function queryString(string $key): string
    {
        return trim((string) ($this->request->query[$key] ?? ''));
    }

    private function includeArchived(): bool
    {
        return in_array((string) ($this->request->query['archived'] ?? '0'), ['1', 'all'], true);
    }

    private function limit(): int
    {
        return max(1, min(200, (int) ($this->request->query['limit'] ?? 50)));
    }

    private function offset(): int
    {
        return max(0, (int) ($this->request->query['offset'] ?? 0));
    }

    private function id(string|int $id): int
    {
        return max(0, (int) $id);
    }

    /** @param array{limit:int,offset:int,total?:int} $result */
    private function pagination(array $result): array
    {
        return ['limit' => (int) $result['limit'], 'offset' => (int) $result['offset'], 'total' => (int) ($result['total'] ?? 0)];
    }

    /** @param array<string,mixed> $site */
    private function meta(array $site, string $languageCode): array
    {
        return AdminApiContract::meta($site, $languageCode);
    }

    private function validation(InvalidArgumentException $e): Response
    {
        return Response::validation(['business_catalog' => [$e->getMessage()]], 'Donnée catalogue invalide.');
    }

    private function notFound(string $message, string|int $id): Response
    {
        return Response::error(ErrorCode::ROUTE_NOT_FOUND, $message, 404, ['id' => (string) $id]);
    }

    /** @return array<string,mixed> */
    private function productFilters(): array
    {
        $filters = [];
        foreach (['q', 'status', 'type', 'brand_id', 'category_id', 'tag', 'channel', 'is_public', 'is_ecommerce_enabled', 'is_pos_enabled', 'low_stock'] as $key) {
            if (array_key_exists($key, $this->request->query)) {
                $filters[$key] = $this->request->query[$key];
            }
        }
        return $filters;
    }

    /** @return array<string,mixed> */
    private function stockMovementFilters(): array
    {
        $filters = [];
        foreach (['variant_id', 'movement_type', 'reference_type', 'reference_id'] as $key) {
            if (array_key_exists($key, $this->request->query)) {
                $filters[$key] = $this->request->query[$key];
            }
        }
        return $filters;
    }

    /** @param array<string,mixed> $payload */
    private function syncProductOptions(int $siteId, int $productId, array $payload): void
    {
        if (!array_key_exists('option_ids', $payload)) {
            return;
        }
        $ids = is_array($payload['option_ids']) ? $payload['option_ids'] : [];
        $this->products->replaceOptionLinks($siteId, $productId, $ids);
    }

    private function productPayload(int $siteId, int $productId, bool $includeArchived = false): ?array
    {
        $product = $this->products->find($siteId, $productId, $includeArchived);
        if (!$product) {
            return null;
        }
        $brand = !empty($product['brand_id']) ? $this->brands->find($siteId, (int) $product['brand_id'], true) : null;
        $category = !empty($product['category_id']) ? $this->categories->find($siteId, (int) $product['category_id'], true) : null;
        $variants = array_map(fn(array $variant): array => $this->variantPayload($siteId, $variant), $this->variants->listForProduct($siteId, $productId, true));
        return $this->safeProduct([
            'data' => $product,
            'brand' => $brand,
            'category' => $category,
            'options' => $this->products->linkedOptions($siteId, $productId),
            'variants' => $variants,
            'prices' => $this->products->prices($siteId, $productId),
            'computed' => array_values(array_filter(array_map(static fn(array $variant): mixed => $variant['computed_prices'] ?? null, $variants))),
            'margin' => array_values(array_filter(array_map(static fn(array $variant): mixed => $variant['computed_prices']['gross_margin_percent'] ?? null, $variants))),
            'offers' => $this->discounts->list($siteId, 200, 0, false)['items'],
            'stock' => array_map(static fn(array $variant): array => ['variant_id' => $variant['id'], 'sku' => $variant['sku'], 'stock_quantity' => $variant['stock_quantity'] ?? 0, 'stock_reserved' => $variant['stock_reserved'] ?? 0], $variants),
            'media' => [],
            'tags' => [],
        ], $siteId);
    }

    /** @param array<string,mixed> $product */
    private function safeProduct(array $product, int $siteId): array
    {
        if ($this->canReadPurchasePrices($siteId)) {
            return $product;
        }
        return $this->stripPurchaseData($product);
    }

    /** @param array<string,mixed> $variant */
    private function variantPayload(int $siteId, array $variant): array
    {
        $variant['option_values'] = $this->variants->optionValues((int) $variant['id']);
        $variant['adjustments'] = $this->safeAdjustments($this->variants->adjustments((int) $variant['id']), $siteId);
        try {
            $variant['computed_prices'] = $this->safeComputed($this->pricing->pricingSummary((int) $variant['id']));
        } catch (Throwable) {
            $variant['computed_prices'] = null;
        }
        return $this->canReadPurchasePrices($siteId) ? $variant : $this->stripPurchaseData($variant);
    }

    /** @param list<array<string,mixed>> $prices @return list<array<string,mixed>> */
    private function safePrices(array $prices): array
    {
        return $this->canReadPurchasePrices($this->currentSiteId())
            ? $prices
            : array_values(array_filter($prices, static fn(array $price): bool => ($price['price_kind'] ?? '') !== 'purchase'));
    }

    /** @param list<array<string,mixed>> $adjustments @return list<array<string,mixed>> */
    private function safeAdjustments(array $adjustments, int $siteId): array
    {
        return $this->canReadPurchasePrices($siteId)
            ? $adjustments
            : array_values(array_filter($adjustments, static fn(array $row): bool => ($row['price_kind'] ?? '') !== 'purchase'));
    }

    /** @param array<string,mixed> $summary @return array<string,mixed> */
    private function safeComputed(array $summary): array
    {
        return $this->canReadPurchasePrices($this->currentSiteId()) ? $summary : $this->pricing->publicPricingPayload($summary);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function stripPurchaseData(array $payload): array
    {
        if (($payload['price_kind'] ?? null) === 'purchase') {
            return [];
        }
        foreach (array_keys($payload) as $key) {
            if (str_contains((string) $key, 'purchase') || str_contains((string) $key, 'margin')) {
                unset($payload[$key]);
                continue;
            }
            if (is_array($payload[$key])) {
                $payload[$key] = $this->stripPurchaseData($payload[$key]);
            }
        }
        if (array_is_list($payload)) {
            $payload = array_values(array_filter($payload, static fn(mixed $item): bool => $item !== []));
        }
        return $payload;
    }

    private function currentSiteId(): int
    {
        try {
            $site = AdminApiContract::siteContext($this->request, $this->sites, isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null, $this->auth);
            return (int) $site['id'];
        } catch (Throwable) {
            return 0;
        }
    }

    /** @param array<string,mixed> $payload @return list<array<string,mixed>> */
    private function basePricePayload(array $payload): array
    {
        if (isset($payload['prices']) && is_array($payload['prices'])) {
            return array_values(array_filter($payload['prices'], 'is_array'));
        }
        $prices = [];
        if (array_key_exists('base_purchase_price', $payload)) {
            $prices[] = ['price_kind' => 'purchase', 'amount' => $payload['base_purchase_price'], 'currency' => $payload['currency'] ?? 'CHF', 'tax_included' => false];
        }
        if (array_key_exists('base_sale_price', $payload)) {
            $prices[] = ['price_kind' => 'sale', 'amount' => $payload['base_sale_price'], 'currency' => $payload['currency'] ?? 'CHF', 'tax_included' => true];
        }
        return $prices;
    }

    /** @param array<string,mixed> $payload @return array<string,array{type:string,value:mixed}> */
    private function adjustmentPayload(array $payload): array
    {
        if (isset($payload['adjustments']) && is_array($payload['adjustments'])) {
            $result = [];
            foreach ($payload['adjustments'] as $adjustment) {
                if (is_array($adjustment) && isset($adjustment['price_kind'])) {
                    $result[(string) $adjustment['price_kind']] = ['type' => (string) ($adjustment['adjustment_type'] ?? $adjustment['type'] ?? 'none'), 'value' => $adjustment['adjustment_value'] ?? $adjustment['value'] ?? null];
                }
            }
            return $result;
        }
        $result = [];
        foreach (['purchase', 'sale'] as $kind) {
            if (array_key_exists($kind . '_adjustment_type', $payload) || array_key_exists($kind . '_adjustment_value', $payload)) {
                $result[$kind] = ['type' => (string) ($payload[$kind . '_adjustment_type'] ?? 'none'), 'value' => $payload[$kind . '_adjustment_value'] ?? null];
            }
        }
        return $result;
    }
}
