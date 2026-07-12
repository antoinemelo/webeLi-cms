<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Application\Business\ProductContentLinkService;
use App\Core\ErrorCode;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Business\Services\BusinessCatalogSellableReadService;
use App\Modules\Business\Services\BusinessPimAdminService;
use App\Modules\Business\Services\BusinessProductAssetService;
use App\Modules\Business\Services\BusinessProductBundleService;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;
use InvalidArgumentException;

final class BusinessPimApiController
{
    public function __construct(
        private readonly Request $request,
        private readonly SiteRepository $sites,
        private readonly AuthRepository $auth,
        private readonly Authorization $authorization,
        private readonly BusinessPimAdminService $pim,
        private readonly BusinessProductAssetService $assets,
        private readonly BusinessProductBundleService $bundles,
        private readonly BusinessCatalogSellableReadService $sellables,
        private readonly ProductContentLinkService $contentLinks,
    ) {}

    public function productContentLinks(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.read');
        return Response::success([
            'links' => $this->contentLinks->listForProduct((int) $site['id'], $this->id($id)),
        ], 'admin.business.pim.product_content_links.index.v1', $this->meta($site, $languageCode));
    }

    public function contentCandidates(): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.read');
        return Response::success([
            'contents' => $this->contentLinks->contentCandidates((int) $site['id'], (string) ($this->request->query['q'] ?? '')),
        ], 'admin.business.pim.content_candidates.index.v1', $this->meta($site, $languageCode));
    }

    public function storeProductContentLink(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            return Response::success([
                'link' => $this->contentLinks->create((int) $site['id'], $this->id($id), $this->payload(), $this->actorId()),
                'message' => 'Contenu éditorial lié au produit.',
            ], 'admin.business.pim.product_content_links.show.v1', $this->meta($site, $languageCode), 201);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function updateProductContentLink(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            return Response::success([
                'link' => $this->contentLinks->update((int) $site['id'], $this->id($id), $this->payload(), $this->actorId()),
                'message' => 'Liaison éditoriale mise à jour.',
            ], 'admin.business.pim.product_content_links.show.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function deleteProductContentLink(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        return Response::success([
            'deleted' => $this->contentLinks->delete((int) $site['id'], $this->id($id)),
            'id' => $this->id($id),
        ], 'admin.business.pim.product_content_links.delete.v1', $this->meta($site, $languageCode));
    }

    public function productAssets(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.read');
        return Response::success([
            'assets' => $this->assets->listAssetsForProduct($this->id($id), $this->assetFilters()),
        ], 'admin.business.pim.product_assets.index.v1', $this->meta($site, $languageCode));
    }

    public function taxClasses(): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.read');
        return Response::success([
            'tax_classes' => $this->pim->taxClasses((int) $site['id']),
        ], 'admin.business.pim.tax_classes.index.v1', $this->meta($site, $languageCode));
    }

    public function storeTaxClass(): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            return Response::success([
                'tax_class' => $this->pim->createTaxClass((int) $site['id'], $this->payload(), $this->actorId()),
                'message' => 'Taux TVA créé.',
            ], 'admin.business.pim.tax_classes.show.v1', $this->meta($site, $languageCode), 201);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function updateTaxClass(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            return Response::success([
                'tax_class' => $this->pim->updateTaxClass((int) $site['id'], $this->id($id), $this->payload(), $this->actorId()),
                'message' => 'Taux TVA mis à jour.',
            ], 'admin.business.pim.tax_classes.show.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function deleteTaxClass(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            $deleted = $this->pim->deleteTaxClassIfUnused((int) $site['id'], $this->id($id));
            return Response::success(['deleted' => $deleted, 'archived' => false, 'id' => $this->id($id)], 'admin.business.pim.tax_classes.delete.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function storeProductAsset(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            $payload = $this->payload() + ['site_id' => (int) $site['id'], 'product_id' => $this->id($id), 'actor_iam_user_id' => $this->actorId()];
            $asset = $this->assets->assignAsset($payload);
            return Response::success(['asset' => $asset, 'message' => 'Actif produit ajouté.'], 'admin.business.pim.product_assets.show.v1', $this->meta($site, $languageCode), 201);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function updateAsset(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            $asset = $this->assets->updateAsset($this->id($id), $this->payload() + ['actor_iam_user_id' => $this->actorId()]);
            if ((int) ($asset['site_id'] ?? 0) !== (int) $site['id']) {
                return $this->notFound('Actif produit introuvable.', $id);
            }
            return Response::success(['asset' => $asset, 'message' => 'Actif produit mis à jour.'], 'admin.business.pim.product_assets.show.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function deleteAsset(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        $this->assets->archiveAsset($this->id($id));
        return Response::success(['deleted' => true, 'archived' => true, 'id' => $this->id($id)], 'admin.business.pim.product_assets.delete.v1', $this->meta($site, $languageCode));
    }

    public function setMainAsset(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        $asset = $this->pim->setMainAsset((int) $site['id'], $this->id($id), $this->actorId());
        return $asset ? Response::success(['asset' => $asset, 'message' => 'Actif principal défini.'], 'admin.business.pim.product_assets.show.v1', $this->meta($site, $languageCode)) : $this->notFound('Actif produit introuvable.', $id);
    }

    public function productBundle(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.read');
        try {
            return Response::success(['bundle' => $this->bundles->bundleForProduct((int) $site['id'], $this->id($id))], 'admin.business.pim.product_bundle.show.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function putProductBundle(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            return Response::success(['bundle' => $this->bundles->replaceProductBundle((int) $site['id'], $this->id($id), $this->payload(), $this->actorId()), 'message' => 'Offre composée mise à jour.'], 'admin.business.pim.product_bundle.show.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function deleteProductBundle(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            $archived = $this->bundles->archiveProductBundle((int) $site['id'], $this->id($id), $this->actorId());
            return Response::success(['deleted' => false, 'archived' => $archived, 'id' => $this->id($id)], 'admin.business.pim.product_bundle.delete.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function bundleComponents(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.read');
        try {
            return Response::success(['components' => $this->bundles->components((int) $site['id'], $this->id($id))], 'admin.business.pim.bundle_components.index.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function storeBundleComponent(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            return Response::success(['component' => $this->bundles->addComponent((int) $site['id'], $this->id($id), $this->payload()), 'message' => 'Composant ajouté.'], 'admin.business.pim.bundle_components.show.v1', $this->meta($site, $languageCode), 201);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function updateBundleComponent(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            return Response::success(['component' => $this->bundles->updateComponent((int) $site['id'], $this->id($id), $this->payload()), 'message' => 'Composant mis à jour.'], 'admin.business.pim.bundle_components.show.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function deleteBundleComponent(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            return Response::success(['deleted' => $this->bundles->deleteComponent((int) $site['id'], $this->id($id)), 'id' => $this->id($id)], 'admin.business.pim.bundle_components.delete.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function attributeGroups(): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.read');
        return Response::success(['attribute_groups' => $this->pim->attributeGroups((int) $site['id'])], 'admin.business.pim.attribute_groups.index.v1', $this->meta($site, $languageCode));
    }

    public function storeAttributeGroup(): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            return Response::success(['attribute_group' => $this->pim->createAttributeGroup((int) $site['id'], $this->payload(), $this->actorId()), 'message' => 'Groupe attribut créé.'], 'admin.business.pim.attribute_groups.show.v1', $this->meta($site, $languageCode), 201);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function updateAttributeGroup(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            $group = $this->pim->updateAttributeGroup((int) $site['id'], $this->id($id), $this->payload(), $this->actorId());
            return $group ? Response::success(['attribute_group' => $group, 'message' => 'Groupe attribut mis à jour.'], 'admin.business.pim.attribute_groups.show.v1', $this->meta($site, $languageCode)) : $this->notFound('Groupe attribut introuvable.', $id);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function deleteAttributeGroup(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        $this->pim->archiveAttributeGroup((int) $site['id'], $this->id($id), $this->actorId());
        return Response::success(['deleted' => true, 'archived' => true, 'id' => $this->id($id)], 'admin.business.pim.attribute_groups.delete.v1', $this->meta($site, $languageCode));
    }

    public function attributes(): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.read');
        return Response::success(['attributes' => $this->pim->attributes((int) $site['id'])], 'admin.business.pim.attributes.index.v1', $this->meta($site, $languageCode));
    }

    public function storeAttribute(): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            return Response::success(['attribute' => $this->pim->createAttribute((int) $site['id'], $this->payload(), $this->actorId()), 'message' => 'Attribut créé.'], 'admin.business.pim.attributes.show.v1', $this->meta($site, $languageCode), 201);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function updateAttribute(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            $attribute = $this->pim->updateAttribute((int) $site['id'], $this->id($id), $this->payload(), $this->actorId());
            return $attribute ? Response::success(['attribute' => $attribute, 'message' => 'Attribut mis à jour.'], 'admin.business.pim.attributes.show.v1', $this->meta($site, $languageCode)) : $this->notFound('Attribut introuvable.', $id);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function deleteAttribute(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        $this->pim->archiveAttribute((int) $site['id'], $this->id($id), $this->actorId());
        return Response::success(['deleted' => true, 'archived' => true, 'id' => $this->id($id)], 'admin.business.pim.attributes.delete.v1', $this->meta($site, $languageCode));
    }

    public function storeAttributeOption(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            return Response::success(['attribute_option' => $this->pim->createOption((int) $site['id'], $this->id($id), $this->payload()), 'message' => 'Option attribut créée.'], 'admin.business.pim.attribute_options.show.v1', $this->meta($site, $languageCode), 201);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function updateAttributeOption(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            $option = $this->pim->updateOption((int) $site['id'], $this->id($id), $this->payload());
            return $option ? Response::success(['attribute_option' => $option, 'message' => 'Option attribut mise à jour.'], 'admin.business.pim.attribute_options.show.v1', $this->meta($site, $languageCode)) : $this->notFound('Option attribut introuvable.', $id);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function deleteAttributeOption(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        $this->pim->archiveOption((int) $site['id'], $this->id($id));
        return Response::success(['deleted' => true, 'archived' => true, 'id' => $this->id($id)], 'admin.business.pim.attribute_options.delete.v1', $this->meta($site, $languageCode));
    }

    public function productAttributes(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.read');
        try {
            return Response::success(['attributes' => $this->pim->productAttributeValues((int) $site['id'], $this->id($id))], 'admin.business.pim.product_attributes.index.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function putProductAttributes(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            return Response::success(['attributes' => $this->pim->replaceProductAttributeValues((int) $site['id'], $this->id($id), $this->payload(), $this->actorId()), 'message' => 'Attributs produit mis à jour.'], 'admin.business.pim.product_attributes.index.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function variantAttributes(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.read');
        try {
            return Response::success(['attributes' => $this->pim->variantAttributeValues((int) $site['id'], $this->id($id))], 'admin.business.pim.variant_attributes.index.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function putVariantAttributes(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            return Response::success(['attributes' => $this->pim->replaceVariantAttributeValues((int) $site['id'], $this->id($id), $this->payload(), $this->actorId()), 'message' => 'Attributs variante mis à jour.'], 'admin.business.pim.variant_attributes.index.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function productCompleteness(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.read');
        try {
            return Response::success(['completeness' => $this->pim->productCompleteness((int) $site['id'], $this->id($id))], 'admin.business.pim.completeness.show.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function recalculateCompleteness(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            return Response::success(['completeness' => $this->pim->recalculateProductCompleteness((int) $site['id'], $this->id($id)), 'message' => 'Complétude recalculée.'], 'admin.business.pim.completeness.show.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function variantSellableSnapshot(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.read');
        try {
            $snapshot = $this->sellables->getSellableVariantSnapshot((int) $site['id'], $this->id($id), $this->snapshotContext((int) $site['id']));
            return Response::success(['snapshot' => $this->safeSnapshot($snapshot, (int) $site['id'])], 'admin.business.pim.sellable_snapshot.show.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function sellableVariants(): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.read');
        try {
            $result = $this->sellables->listSellableVariants((int) $site['id'], $this->sellableFilters((int) $site['id']));
            $result['items'] = array_map(fn(array $snapshot): array => $this->safeSnapshot($snapshot, (int) $site['id']), $result['items']);
            return Response::success(['variants' => $result['items'], 'pagination' => $this->pagination($result)], 'admin.business.pim.sellable_variants.index.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function bulkUpdateProducts(): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            return Response::success(['bulk' => $this->pim->bulkUpdateProducts((int) $site['id'], $this->payload(), $this->actorId())], 'admin.business.pim.bulk_update.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function bulkAssetAssign(): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            return Response::success(['bulk' => $this->pim->bulkAssetAssign((int) $site['id'], $this->payload(), $this->actorId(), $this->assets)], 'admin.business.pim.bulk_asset_assign.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function bulkRecalculate(): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            return Response::success(['bulk' => $this->pim->bulkRecalculate((int) $site['id'], $this->payload())], 'admin.business.pim.bulk_recalculate.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function bulkUpdateOffers(): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            return Response::success(['bulk' => $this->pim->bulkUpdateOffers((int) $site['id'], $this->payload(), $this->actorId())], 'admin.business.pim.offers.bulk_update.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function exportOffersCsv(): Response
    {
        [$site] = $this->authorize('business.catalog.read');
        try {
            return new Response(200, $this->pim->exportOffersCsv((int) $site['id'], $this->offerExportFilters()), [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="business-catalog-offers.csv"',
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function previewOffersImport(): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            $payload = $this->payload();
            $payload['dry_run'] = true;
            return Response::success(['import' => $this->pim->importOffersCsv((int) $site['id'], $this->csvInput(), $payload, $this->actorId())], 'admin.business.pim.offers.import.preview.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function applyOffersImport(): Response
    {
        [$site, $languageCode] = $this->authorize('business.catalog.write');
        try {
            $payload = $this->payload();
            $payload['dry_run'] = false;
            return Response::success(['import' => $this->pim->importOffersCsv((int) $site['id'], $this->csvInput(), $payload, $this->actorId()), 'message' => 'Import offres appliqué.'], 'admin.business.pim.offers.import.apply.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    /** @return array{0:array<string,mixed>,1:string} */
    private function authorize(string $permission): array
    {
        $this->auth->requireAuth();
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null, $this->auth);
        $this->authorization->require($permission, (int) $site['id']);
        return [$site, AdminApiContract::language($this->request, $this->sites, $site)];
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
        return (string) ($payload['csv'] ?? $payload['content'] ?? '');
    }

    /** @return array<string,mixed> */
    private function offerExportFilters(): array
    {
        $filters = [];
        foreach (['type', 'status', 'channel'] as $key) {
            if (array_key_exists($key, $this->request->query)) {
                $filters[$key] = $this->request->query[$key];
            }
        }
        return $filters;
    }

    private function id(string|int $id): int
    {
        return max(0, (int) $id);
    }

    /** @return array<string,mixed> */
    private function assetFilters(): array
    {
        $filters = [];
        foreach (['variant_id', 'include_product_assets', 'role', 'channel', 'public_only'] as $key) {
            if (array_key_exists($key, $this->request->query)) {
                $filters[$key] = $this->request->query[$key];
            }
        }
        return $filters;
    }

    /** @return array<string,mixed> */
    private function snapshotContext(int $siteId): array
    {
        return [
            'channel' => (string) ($this->request->query['channel'] ?? 'admin'),
            'currency' => (string) ($this->request->query['currency'] ?? 'CHF'),
            'include_purchase_price' => $this->auth->hasPermission('business.catalog.purchase_prices.read', $siteId),
            'include_internal_fields' => true,
        ];
    }

    /** @return array<string,mixed> */
    private function sellableFilters(int $siteId): array
    {
        $filters = $this->snapshotContext($siteId);
        foreach (['q', 'sku', 'barcode', 'product_type', 'include_not_sellable', 'include_inactive', 'limit', 'offset'] as $key) {
            if (array_key_exists($key, $this->request->query)) {
                $filters[$key] = $this->request->query[$key];
            }
        }
        return $filters;
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    private function safeSnapshot(array $snapshot, int $siteId): array
    {
        $snapshot += $this->bundles->bundleSummaryForVariant($siteId, (int) ($snapshot['variant_id'] ?? $snapshot['business_variant_id'] ?? 0));
        if ($this->auth->hasPermission('business.catalog.purchase_prices.read', $siteId)) {
            return $snapshot;
        }
        foreach (array_keys($snapshot) as $key) {
            if (str_contains((string) $key, 'purchase') || str_contains((string) $key, 'margin')) {
                unset($snapshot[$key]);
            }
        }
        return $snapshot;
    }

    /** @param array{limit:int,offset:int,total?:int,has_more?:bool} $result */
    private function pagination(array $result): array
    {
        return [
            'limit' => (int) $result['limit'],
            'offset' => (int) $result['offset'],
            'total' => (int) ($result['total'] ?? 0),
            'has_more' => (bool) ($result['has_more'] ?? false),
        ];
    }

    /** @param array<string,mixed> $site */
    private function meta(array $site, string $languageCode): array
    {
        return AdminApiContract::meta($site, $languageCode);
    }

    private function validation(InvalidArgumentException $e): Response
    {
        return Response::validation(['business_pim' => [$e->getMessage()]], 'Donnée PIM invalide.');
    }

    private function notFound(string $message, string|int $id): Response
    {
        return Response::error(ErrorCode::ROUTE_NOT_FOUND, $message, 404, ['id' => (string) $id]);
    }
}
