<?php

declare(strict_types=1);

namespace App\Modules\Business\Services;

use App\Core\Database;
use InvalidArgumentException;

final class BusinessProductBundleService
{
    private const PRICING_MODES = ['fixed', 'sum_components', 'discount_components'];
    private const STOCK_MODES = ['components', 'virtual', 'none'];
    private const STOCK_STRATEGIES = ['OWN_STOCK', 'COMPONENT_DERIVED', 'NON_STOCKED'];
    private const PARTIAL_POLICIES = ['REQUIRE_ALL', 'ALLOW_PARTIAL'];
    private const RETURN_POLICIES = ['BUNDLE_ONLY', 'COMPONENTS_ALLOWED'];
    private const COMPOSITION_TYPES = ['bundle', 'kit'];
    private const UNAVAILABLE_STRATEGIES = ['reject', 'backorder', 'contact'];

    public function __construct(private readonly Database $db) {}

    /** @return array<string,mixed>|null */
    public function bundleForProduct(int $siteId, int $productId): ?array
    {
        $this->product($siteId, $productId);
        return $this->bundlePayload($this->bundleRowForProduct($siteId, $productId));
    }

    /** @return array<string,mixed>|null */
    public function bundleForVariant(int $siteId, int $variantId): ?array
    {
        $variant = $this->variant($siteId, $variantId);
        $row = $this->bundleRowForVariant($siteId, $variantId) ?? $this->bundleRowForProduct($siteId, (int) $variant['product_id']);
        return $this->bundlePayload($row);
    }

    /** @return array<string,mixed> */
    public function inventoryPlanForVariant(int $siteId, int $variantId): array
    {
        $variant = $this->variant($siteId, $variantId);
        $row = $this->bundleRowForVariant($siteId, $variantId) ?? $this->bundleRowForProduct($siteId, (int) $variant['product_id']);
        if ($row === null || !((bool) ($row['is_active'] ?? false))) {
            return ['is_bundle' => false, 'stock_strategy' => null, 'leaves' => [], 'missing' => [], 'availability' => ['status' => 'unavailable', 'available_quantity' => 0, 'limiting_factor' => null]];
        }
        $bundle = $this->cast($row);
        $bundle['components'] = $this->componentsRows((int) $bundle['id']);
        return $this->buildInventoryPlan($siteId, $bundle, $variantId, []);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function replaceProductBundle(int $siteId, int $productId, array $payload, int $actorId): array
    {
        $product = $this->product($siteId, $productId);
        if ((string) ($product['type'] ?? '') !== 'bundle') {
            throw new InvalidArgumentException('business.bundle_product_type_required');
        }
        $variantId = $this->nullableId($payload['bundle_variant_id'] ?? null, 'bundle_variant_id');
        if ($variantId !== null) {
            $variant = $this->variant($siteId, $variantId);
            if ((int) $variant['product_id'] !== (int) $product['id']) {
                throw new InvalidArgumentException('business.bundle_variant_product_mismatch');
            }
        }

        $current = $variantId === null ? $this->bundleRowForProduct($siteId, $productId) : $this->bundleRowForVariant($siteId, $variantId);
        $pricingMode = $this->choice((string) ($payload['pricing_mode'] ?? $current['pricing_mode'] ?? 'fixed'), self::PRICING_MODES, 'pricing_mode');
        $stockMode = $this->choice((string) ($payload['stock_mode'] ?? $current['stock_mode'] ?? 'components'), self::STOCK_MODES, 'stock_mode');
        $stockStrategy = $this->stockStrategy($payload['stock_strategy'] ?? $current['stock_strategy'] ?? null, $stockMode);
        $stockMode = $this->legacyStockMode($stockStrategy);
        $partialPolicy = $this->choice((string) ($payload['partial_availability_policy'] ?? $current['partial_availability_policy'] ?? 'REQUIRE_ALL'), self::PARTIAL_POLICIES, 'partial_availability_policy');
        $partialSupported = (bool) ($payload['partial_fulfillment_supported'] ?? $current['partial_fulfillment_supported'] ?? false);
        if ($partialPolicy === 'ALLOW_PARTIAL' && !$partialSupported) {
            throw new InvalidArgumentException('business.bundle_partial_fulfillment_not_supported');
        }
        $returnPolicy = $this->choice((string) ($payload['component_return_policy'] ?? $current['component_return_policy'] ?? 'BUNDLE_ONLY'), self::RETURN_POLICIES, 'component_return_policy');
        $componentsPublic = (int) (bool) ($payload['components_public'] ?? $current['components_public'] ?? true);
        $compositionType = $this->choice((string) ($payload['composition_type'] ?? $current['composition_type'] ?? 'bundle'), self::COMPOSITION_TYPES, 'composition_type');
        $unavailableStrategy = $this->choice((string) ($payload['unavailable_strategy'] ?? $current['unavailable_strategy'] ?? 'reject'), self::UNAVAILABLE_STRATEGIES, 'unavailable_strategy');
        $isActive = (int) (bool) ($payload['is_active'] ?? $current['is_active'] ?? true);

        $canonicalStrategies = $this->hasColumn('business_product_bundles', 'stock_strategy');
        if ($current === null && $canonicalStrategies) {
            $this->db->run(
                'INSERT INTO business_product_bundles(site_id, bundle_product_id, bundle_variant_id, pricing_mode, stock_mode, stock_strategy, partial_availability_policy, partial_fulfillment_supported, component_return_policy, components_public, composition_type, unavailable_strategy, is_active, created_by_iam_user_id, updated_by_iam_user_id)
                 VALUES(:site_id, :product_id, :variant_id, :pricing_mode, :stock_mode, :stock_strategy, :partial_policy, :partial_supported, :return_policy, :components_public, :composition_type, :unavailable_strategy, :is_active, :actor, :actor)',
                [
                    'site_id' => $siteId, 'product_id' => $productId, 'variant_id' => $variantId,
                    'pricing_mode' => $pricingMode, 'stock_mode' => $stockMode, 'stock_strategy' => $stockStrategy,
                    'partial_policy' => $partialPolicy, 'partial_supported' => (int) $partialSupported,
                    'return_policy' => $returnPolicy, 'components_public' => $componentsPublic,
                    'composition_type' => $compositionType, 'unavailable_strategy' => $unavailableStrategy,
                    'is_active' => $isActive, 'actor' => $actorId > 0 ? $actorId : null,
                ]
            );
            $bundleId = (int) $this->db->lastInsertId();
        } elseif ($current === null) {
            $this->db->run(
                'INSERT INTO business_product_bundles(site_id, bundle_product_id, bundle_variant_id, pricing_mode, stock_mode, composition_type, unavailable_strategy, is_active, created_by_iam_user_id, updated_by_iam_user_id)
                 VALUES(:site_id, :product_id, :variant_id, :pricing_mode, :stock_mode, :composition_type, :unavailable_strategy, :is_active, :actor, :actor)',
                [
                    'site_id' => $siteId,
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'pricing_mode' => $pricingMode,
                    'stock_mode' => $stockMode,
                    'composition_type' => $compositionType,
                    'unavailable_strategy' => $unavailableStrategy,
                    'is_active' => $isActive,
                    'actor' => $actorId > 0 ? $actorId : null,
                ]
            );
            $bundleId = (int) $this->db->lastInsertId();
        } elseif ($canonicalStrategies) {
            $bundleId = (int) $current['id'];
            $this->db->run(
                'UPDATE business_product_bundles
                 SET bundle_variant_id=:variant_id,pricing_mode=:pricing_mode,stock_mode=:stock_mode,stock_strategy=:stock_strategy,
                     partial_availability_policy=:partial_policy,partial_fulfillment_supported=:partial_supported,
                     component_return_policy=:return_policy,components_public=:components_public,is_active=:is_active,
                     composition_type=:composition_type,unavailable_strategy=:unavailable_strategy,
                     updated_by_iam_user_id=:actor,updated_at=CURRENT_TIMESTAMP,archived_at=NULL
                 WHERE id=:id AND site_id=:site_id',
                [
                    'id' => $bundleId, 'site_id' => $siteId, 'variant_id' => $variantId,
                    'pricing_mode' => $pricingMode, 'stock_mode' => $stockMode, 'stock_strategy' => $stockStrategy,
                    'partial_policy' => $partialPolicy, 'partial_supported' => (int) $partialSupported,
                    'return_policy' => $returnPolicy, 'components_public' => $componentsPublic,
                    'is_active' => $isActive, 'composition_type' => $compositionType,
                    'unavailable_strategy' => $unavailableStrategy, 'actor' => $actorId > 0 ? $actorId : null,
                ]
            );
        } else {
            $bundleId = (int) $current['id'];
            $this->db->run(
                'UPDATE business_product_bundles
                 SET bundle_variant_id = :variant_id, pricing_mode = :pricing_mode, stock_mode = :stock_mode, is_active = :is_active,
                     composition_type = :composition_type, unavailable_strategy = :unavailable_strategy,
                     updated_by_iam_user_id = :actor, updated_at = CURRENT_TIMESTAMP, archived_at = NULL
                 WHERE id = :id AND site_id = :site_id',
                [
                    'id' => $bundleId,
                    'site_id' => $siteId,
                    'variant_id' => $variantId,
                    'pricing_mode' => $pricingMode,
                    'stock_mode' => $stockMode,
                    'composition_type' => $compositionType,
                    'unavailable_strategy' => $unavailableStrategy,
                    'is_active' => $isActive,
                    'actor' => $actorId > 0 ? $actorId : null,
                ]
            );
        }

        if (array_key_exists('components', $payload) && is_array($payload['components'])) {
            $this->replaceComponents($siteId, $bundleId, $payload['components']);
        }

        return $this->requireBundle($siteId, $bundleId);
    }

    public function archiveProductBundle(int $siteId, int $productId, int $actorId): bool
    {
        $this->product($siteId, $productId);
        $row = $this->bundleRowForProduct($siteId, $productId);
        if ($row === null) {
            return false;
        }
        $this->db->run(
            'UPDATE business_product_bundles
             SET is_active = 0, archived_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP, updated_by_iam_user_id = ?
             WHERE id = ? AND site_id = ?',
            [$actorId > 0 ? $actorId : null, (int) $row['id'], $siteId]
        );
        return true;
    }

    /** @return list<array<string,mixed>> */
    public function components(int $siteId, int $bundleId): array
    {
        return $this->requireBundle($siteId, $bundleId)['components'];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function addComponent(int $siteId, int $bundleId, array $payload): array
    {
        $bundle = $this->requireBundle($siteId, $bundleId);
        $component = $this->componentPayload($siteId, $bundle, $payload);
        $this->db->run(
            'INSERT INTO business_bundle_components(bundle_id, component_product_id, component_variant_id, quantity, is_required, sort_order, metadata_json)
             VALUES(:bundle_id, :product_id, :variant_id, :quantity, :is_required, :sort_order, :metadata_json)',
            [
                'bundle_id' => $bundleId,
                'product_id' => $component['component_product_id'],
                'variant_id' => $component['component_variant_id'],
                'quantity' => $component['quantity'],
                'is_required' => (int) $component['is_required'],
                'sort_order' => $component['sort_order'],
                'metadata_json' => $component['metadata_json'],
            ]
        );
        return $this->component((int) $this->db->lastInsertId());
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function updateComponent(int $siteId, int $componentId, array $payload): array
    {
        $current = $this->component($componentId);
        $bundle = $this->requireBundle($siteId, (int) $current['bundle_id']);
        $component = $this->componentPayload($siteId, $bundle, $payload + $current);
        $this->db->run(
            'UPDATE business_bundle_components
             SET component_product_id = :product_id, component_variant_id = :variant_id, quantity = :quantity,
                 is_required = :is_required, sort_order = :sort_order, metadata_json = :metadata_json,
                 updated_at = CURRENT_TIMESTAMP, archived_at = NULL
             WHERE id = :id',
            [
                'id' => $componentId,
                'product_id' => $component['component_product_id'],
                'variant_id' => $component['component_variant_id'],
                'quantity' => $component['quantity'],
                'is_required' => (int) $component['is_required'],
                'sort_order' => $component['sort_order'],
                'metadata_json' => $component['metadata_json'],
            ]
        );
        return $this->component($componentId);
    }

    public function deleteComponent(int $siteId, int $componentId): bool
    {
        $current = $this->component($componentId);
        $this->requireBundle($siteId, (int) $current['bundle_id']);
        $this->db->run('UPDATE business_bundle_components SET archived_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?', [$componentId]);
        return true;
    }

    /** @return array<string,mixed> */
    public function bundleSummaryForVariant(int $siteId, int $variantId): array
    {
        $bundle = $this->bundleForVariant($siteId, $variantId);
        if ($bundle === null || !((bool) ($bundle['is_active'] ?? false))) {
            return [
                'is_bundle' => false,
                'bundle_components' => [],
                'bundle_pricing_mode' => null,
                'bundle_stock_mode' => null,
                'bundle_composition_type' => null,
                'bundle_unavailable_strategy' => null,
                'bundle_available_quantity' => null,
                'bundle_missing_requirements' => [],
            ];
        }

        $plan = $this->inventoryPlanForVariant($siteId, $variantId);
        $missing = $plan['missing'];
        $availability = $plan['availability'];
        $strategy = (string) ($bundle['unavailable_strategy'] ?? 'reject');
        foreach ($bundle['components'] as $component) {
            if (($component['component_status'] ?? '') !== 'active' || ($component['component_variant_status'] ?? 'active') !== 'active') {
                $missing[] = 'bundle_component_not_sellable';
            }
            if (($component['component_archived_at'] ?? null) !== null || ($component['component_variant_archived_at'] ?? null) !== null) {
                $missing[] = 'bundle_component_archived';
            }
        }
        if ($availability['status'] === 'unavailable' && $strategy === 'backorder' && $missing === []) {
            $availability = array_replace($availability, ['status' => 'backorder', 'delivery_lead_time_days' => 7]);
        } elseif ($availability['status'] === 'unavailable') {
            $missing[] = 'bundle_stock_unavailable';
        }

        return [
            'is_bundle' => true,
            'bundle_id' => (int) $bundle['id'],
            'bundle_components' => $bundle['components'],
            'bundle_pricing_mode' => (string) $bundle['pricing_mode'],
            'bundle_stock_mode' => (string) $bundle['stock_mode'],
            'bundle_stock_strategy' => (string) $plan['stock_strategy'],
            'bundle_partial_availability_policy' => (string) ($bundle['partial_availability_policy'] ?? 'REQUIRE_ALL'),
            'bundle_component_return_policy' => (string) ($bundle['component_return_policy'] ?? 'BUNDLE_ONLY'),
            'bundle_components_public' => (bool) ($bundle['components_public'] ?? true),
            'bundle_composition_type' => (string) ($bundle['composition_type'] ?? 'bundle'),
            'bundle_unavailable_strategy' => (string) ($bundle['unavailable_strategy'] ?? 'reject'),
            'bundle_available_quantity' => $availability['available_quantity'] ?? null,
            'bundle_availability_status' => $availability['status'],
            'bundle_backorder_delivery_days' => $availability['delivery_lead_time_days'] ?? null,
            'bundle_limiting_factor' => $availability['limiting_factor'] ?? null,
            'bundle_inventory_plan' => $plan['leaves'],
            'bundle_missing_requirements' => array_values(array_unique($missing)),
        ];
    }

    /** @param list<array<string,mixed>> $components */
    private function replaceComponents(int $siteId, int $bundleId, array $components): void
    {
        $bundle = $this->requireBundle($siteId, $bundleId);
        $this->db->run('UPDATE business_bundle_components SET archived_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE bundle_id = ?', [$bundleId]);
        foreach ($components as $index => $component) {
            if (!is_array($component)) {
                continue;
            }
            $this->addComponent($siteId, $bundleId, $component + ['sort_order' => ($index + 1) * 10]);
        }
        $this->assertBundleAcyclic($siteId, (int) $bundle['bundle_product_id']);
    }

    /** @param array<string,mixed> $bundle @param array<string,mixed> $payload @return array<string,mixed> */
    private function componentPayload(int $siteId, array $bundle, array $payload): array
    {
        $productId = $this->id($payload['component_product_id'] ?? $payload['product_id'] ?? 0, 'component_product_id');
        $product = $this->product($siteId, $productId);
        $variantId = $this->nullableId($payload['component_variant_id'] ?? $payload['variant_id'] ?? null, 'component_variant_id');
        if ($variantId !== null) {
            $variant = $this->variant($siteId, $variantId);
            if ((int) $variant['product_id'] !== $productId) {
                throw new InvalidArgumentException('business.bundle_component_variant_product_mismatch');
            }
        }
        if ($productId === (int) $bundle['bundle_product_id'] || ($variantId !== null && $variantId === ($bundle['bundle_variant_id'] === null ? null : (int) $bundle['bundle_variant_id']))) {
            throw new InvalidArgumentException('business.bundle_loop_detected');
        }
        if (($product['status'] ?? '') !== 'active' || ($variantId !== null && ($variant['status'] ?? '') !== 'active')) {
            throw new InvalidArgumentException('business.bundle_component_not_active');
        }
        $this->assertNoCycleForComponent($siteId, (int) $bundle['bundle_product_id'], $productId);

        $quantity = (float) ($payload['quantity'] ?? 1);
        if ($quantity <= 0) {
            throw new InvalidArgumentException('business.bundle_quantity_positive_required');
        }
        $metadata = $payload['metadata'] ?? $payload['metadata_json'] ?? [];
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        }

        return [
            'component_product_id' => $productId,
            'component_variant_id' => $variantId,
            'quantity' => round($quantity, 4),
            'is_required' => (bool) ($payload['is_required'] ?? true),
            'sort_order' => max(0, (int) ($payload['sort_order'] ?? 0)),
            'metadata_json' => json_encode(is_array($metadata) ? $metadata : [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'component_status' => $product['status'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    private function requireBundle(int $siteId, int $bundleId): array
    {
        $bundle = $this->bundlePayload($this->db->one('SELECT * FROM business_product_bundles WHERE site_id = ? AND id = ? AND archived_at IS NULL', [$siteId, $bundleId]));
        if ($bundle === null) {
            throw new InvalidArgumentException('business.bundle_not_found');
        }
        return $bundle;
    }

    /** @return array<string,mixed>|null */
    private function bundleRowForProduct(int $siteId, int $productId): ?array
    {
        return $this->db->one(
            'SELECT * FROM business_product_bundles WHERE site_id = ? AND bundle_product_id = ? AND bundle_variant_id IS NULL AND archived_at IS NULL LIMIT 1',
            [$siteId, $productId]
        );
    }

    /** @return array<string,mixed>|null */
    private function bundleRowForVariant(int $siteId, int $variantId): ?array
    {
        return $this->db->one(
            'SELECT * FROM business_product_bundles WHERE site_id = ? AND bundle_variant_id = ? AND archived_at IS NULL LIMIT 1',
            [$siteId, $variantId]
        );
    }

    /** @param array<string,mixed>|null $row @return array<string,mixed>|null */
    private function bundlePayload(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $row = $this->cast($row);
        $row['components'] = $this->componentsRows((int) $row['id']);
        $variantId = (int) ($row['bundle_variant_id'] ?? 0);
        if ($variantId < 1) {
            $variantId = (int) ($this->db->one('SELECT id FROM business_product_variants WHERE product_id=? AND archived_at IS NULL ORDER BY status=\'active\' DESC,sort_order,id LIMIT 1', [(int) $row['bundle_product_id']])['id'] ?? 0);
        }
        if ($variantId > 0) {
            $plan = $this->buildInventoryPlan((int) $row['site_id'], $row, $variantId, []);
            $row['stock_estimate'] = $plan['availability'];
            $row['inventory_plan'] = $plan['leaves'];
            $row['configuration_errors'] = $plan['missing'];
        } else {
            $row['stock_estimate'] = ['status' => 'unavailable', 'available_quantity' => 0, 'limiting_factor' => null];
            $row['inventory_plan'] = [];
            $row['configuration_errors'] = ['bundle_variant_required'];
        }
        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function componentsRows(int $bundleId): array
    {
        return array_map(fn(array $row): array => $this->castComponent($row), $this->db->all(
            'SELECT c.*,
                    p.name AS component_name,
                    p.slug AS component_slug,
                    p.status AS component_status,
                    p.archived_at AS component_archived_at,
                    v.sku AS component_sku,
                    v.name AS component_variant_name,
                    v.status AS component_variant_status,
                    v.archived_at AS component_variant_archived_at,
                    v.stock_quantity,
                    v.stock_reserved,
                    COALESCE(v.track_stock, p.track_stock) AS effective_track_stock,
                    COALESCE(v.allow_backorder, p.allow_backorder) AS effective_allow_backorder,
                    COALESCE(v.backorder_delivery_days, p.backorder_delivery_days) AS effective_backorder_delivery_days
             FROM business_bundle_components c
             INNER JOIN business_products p ON p.id = c.component_product_id
             LEFT JOIN business_product_variants v ON v.id = c.component_variant_id
             WHERE c.bundle_id = ? AND c.archived_at IS NULL
             ORDER BY c.sort_order ASC, c.id ASC',
            [$bundleId]
        ));
    }

    /** @return array<string,mixed> */
    private function component(int $componentId): array
    {
        $row = $this->db->one('SELECT * FROM business_bundle_components WHERE id = ? AND archived_at IS NULL', [$this->id($componentId, 'bundle_component_id')]);
        if ($row === null) {
            throw new InvalidArgumentException('business.bundle_component_not_found');
        }
        return $this->castComponent($row);
    }

    /** @param array<string,mixed> $bundle @param array<int,bool> $visited @return array<string,mixed> */
    private function buildInventoryPlan(int $siteId, array $bundle, int $bundleVariantId, array $visited): array
    {
        $productId = (int) $bundle['bundle_product_id'];
        if (isset($visited[$productId])) {
            return ['is_bundle' => true, 'stock_strategy' => $this->strategyForBundle($bundle), 'leaves' => [], 'missing' => ['bundle_cycle_detected'], 'availability' => ['status' => 'unavailable', 'available_quantity' => 0, 'limiting_factor' => null, 'delivery_lead_time_days' => null]];
        }
        if (count($visited) >= 8) {
            return ['is_bundle' => true, 'stock_strategy' => $this->strategyForBundle($bundle), 'leaves' => [], 'missing' => ['bundle_depth_exceeded'], 'availability' => ['status' => 'unavailable', 'available_quantity' => 0, 'limiting_factor' => null, 'delivery_lead_time_days' => null]];
        }
        $visited[$productId] = true;
        $strategy = $this->strategyForBundle($bundle);
        if ($strategy === 'NON_STOCKED') {
            return ['is_bundle' => true, 'stock_strategy' => $strategy, 'leaves' => [], 'missing' => [], 'availability' => ['status' => 'deliverable', 'available_quantity' => null, 'limiting_factor' => null, 'delivery_lead_time_days' => null]];
        }
        if ($strategy === 'OWN_STOCK') {
            $leaf = $this->leafForVariant($siteId, $bundleVariantId, 1.0, (string) ($this->variant($siteId, $bundleVariantId)['sku'] ?? ''), (string) ($this->product($siteId, $productId)['name'] ?? ''));
            return ['is_bundle' => true, 'stock_strategy' => $strategy, 'leaves' => [$leaf], 'missing' => [], 'availability' => $this->planAvailability([$leaf])];
        }

        $leaves = [];
        $missing = [];
        if (($bundle['components'] ?? []) === []) $missing[] = 'bundle_components_required';
        foreach ((array) ($bundle['components'] ?? []) as $component) {
            if (!is_array($component) || !((bool) ($component['is_required'] ?? true))) continue;
            $ratio = (float) ($component['quantity'] ?? 0);
            if ($ratio <= 0) { $missing[] = 'bundle_component_ratio_invalid'; continue; }
            $componentProductId = (int) ($component['component_product_id'] ?? 0);
            $componentVariantId = (int) ($component['component_variant_id'] ?? 0);
            if ($componentVariantId < 1) $componentVariantId = $this->defaultActiveVariantId($siteId, $componentProductId);
            if ($componentProductId < 1 || $componentVariantId < 1) { $missing[] = 'bundle_component_variant_required'; continue; }
            if (($component['component_status'] ?? '') !== 'active' || ($component['component_variant_status'] ?? 'active') !== 'active') { $missing[] = 'bundle_component_not_sellable'; continue; }
            $nestedRow = $this->bundleRowForVariant($siteId, $componentVariantId) ?? $this->bundleRowForProduct($siteId, $componentProductId);
            if ($nestedRow !== null && (bool) ($nestedRow['is_active'] ?? false)) {
                $nested = $this->cast($nestedRow);
                $nested['components'] = $this->componentsRows((int) $nested['id']);
                $nestedPlan = $this->buildInventoryPlan($siteId, $nested, $componentVariantId, $visited);
                foreach ($nestedPlan['leaves'] as $leaf) {
                    $leaf['quantity_per_bundle'] = round((float) $leaf['quantity_per_bundle'] * $ratio, 4);
                    $leaf['path'] = array_merge([(string) ($component['component_name'] ?? $componentProductId)], (array) ($leaf['path'] ?? []));
                    $leaves[] = $leaf;
                }
                $missing = array_merge($missing, (array) $nestedPlan['missing']);
                continue;
            }
            $leaves[] = $this->leafForVariant($siteId, $componentVariantId, $ratio, (string) ($component['component_sku'] ?? ''), (string) ($component['component_name'] ?? ''));
        }
        $leaves = $this->mergeLeaves($leaves);
        $availability = $missing === [] ? $this->planAvailability($leaves) : ['status' => 'unavailable', 'available_quantity' => 0, 'limiting_factor' => null, 'delivery_lead_time_days' => null];
        return ['is_bundle' => true, 'stock_strategy' => $strategy, 'leaves' => $leaves, 'missing' => array_values(array_unique($missing)), 'availability' => $availability];
    }

    /** @return array<string,mixed> */
    private function leafForVariant(int $siteId, int $variantId, float $ratio, string $sku, string $name): array
    {
        $variant = $this->variant($siteId, $variantId);
        $stock = $this->variantAvailabilityRow($siteId, $variantId, $variant);
        return [
            'business_variant_id' => $variantId,
            'sellable_id' => (int) ($stock['sellable_id'] ?? $variantId),
            'sku' => $sku !== '' ? $sku : (string) ($variant['sku'] ?? ''),
            'name' => $name,
            'quantity_per_bundle' => round($ratio, 4),
            'track_stock' => (bool) ($stock['tracked'] ?? $variant['track_stock'] ?? $variant['product_track_stock'] ?? false),
            'allow_backorder' => (bool) ($stock['allow_backorder'] ?? $variant['allow_backorder'] ?? $variant['product_allow_backorder'] ?? false),
            'backorder_delivery_days' => max(1, (int) ($variant['backorder_delivery_days'] ?? $variant['product_backorder_delivery_days'] ?? 7)),
            'available_quantity' => (int) ($stock['available_quantity'] ?? max(0, (int) ($variant['stock_quantity'] ?? 0) - (int) ($variant['stock_reserved'] ?? 0))),
            'path' => [],
        ];
    }

    /** @param list<array<string,mixed>> $leaves @return list<array<string,mixed>> */
    private function mergeLeaves(array $leaves): array
    {
        $merged = [];
        foreach ($leaves as $leaf) {
            $key = (int) $leaf['sellable_id'];
            if (!isset($merged[$key])) { $merged[$key] = $leaf; continue; }
            $merged[$key]['quantity_per_bundle'] = round((float) $merged[$key]['quantity_per_bundle'] + (float) $leaf['quantity_per_bundle'], 4);
        }
        return array_values($merged);
    }

    /** @param list<array<string,mixed>> $leaves @return array<string,mixed> */
    private function planAvailability(array $leaves): array
    {
        if ($leaves === []) return ['status' => 'unavailable', 'available_quantity' => 0, 'limiting_factor' => null, 'delivery_lead_time_days' => null];
        $units = null; $limiting = null; $backorder = false; $leadDays = 0;
        foreach ($leaves as $leaf) {
            if (!((bool) ($leaf['track_stock'] ?? false))) continue;
            $ratio = max(0.0001, (float) ($leaf['quantity_per_bundle'] ?? 1));
            $possible = (int) floor(max(0, (int) ($leaf['available_quantity'] ?? 0)) / $ratio);
            if ($units === null || $possible < $units) {
                $units = $possible;
                $limiting = ['sellable_id' => (int) $leaf['sellable_id'], 'sku' => (string) ($leaf['sku'] ?? ''), 'name' => (string) ($leaf['name'] ?? ''), 'required_quantity' => $ratio, 'available_quantity' => (int) ($leaf['available_quantity'] ?? 0)];
            }
            if ($possible < 1) {
                if (!((bool) ($leaf['allow_backorder'] ?? false))) return ['status' => 'unavailable', 'available_quantity' => 0, 'limiting_factor' => $limiting, 'delivery_lead_time_days' => null];
                $backorder = true;
                $leadDays = max($leadDays, max(1, (int) ($leaf['backorder_delivery_days'] ?? 7)));
            }
        }
        if ($units === null) return ['status' => 'deliverable', 'available_quantity' => null, 'limiting_factor' => null, 'delivery_lead_time_days' => null];
        return ['status' => $units > 0 ? 'in_stock' : ($backorder ? 'backorder' : 'unavailable'), 'available_quantity' => $units, 'limiting_factor' => $limiting, 'delivery_lead_time_days' => $backorder ? $leadDays : null];
    }

    /** @param array<string,mixed> $fallback @return array<string,mixed> */
    private function variantAvailabilityRow(int $siteId, int $variantId, array $fallback): array
    {
        if ($this->hasTable('business_inventory_availability_projections')) {
            $row = $this->db->one('SELECT s.sellable_id,ip.tracked,ip.available_quantity FROM business_sellables s LEFT JOIN business_inventory_availability_projections ip ON ip.site_id=s.site_id AND ip.sellable_id=s.sellable_id WHERE s.site_id=? AND s.variant_id=? AND s.status=\'active\' LIMIT 1', [$siteId, $variantId]);
            if ($row !== null && $row['tracked'] !== null) return $row + ['allow_backorder' => $fallback['allow_backorder'] ?? false];
        }
        return ['sellable_id' => $variantId, 'tracked' => $fallback['track_stock'] ?? $fallback['product_track_stock'] ?? false, 'allow_backorder' => $fallback['allow_backorder'] ?? $fallback['product_allow_backorder'] ?? false, 'available_quantity' => max(0, (int) ($fallback['stock_quantity'] ?? 0) - (int) ($fallback['stock_reserved'] ?? 0))];
    }

    private function defaultActiveVariantId(int $siteId, int $productId): int
    {
        $row = $this->db->one('SELECT v.id FROM business_product_variants v INNER JOIN business_products p ON p.id=v.product_id WHERE p.site_id=? AND p.id=? AND p.status=\'active\' AND v.status=\'active\' AND p.archived_at IS NULL AND v.archived_at IS NULL ORDER BY v.sort_order,v.id LIMIT 1', [$siteId, $productId]);
        return (int) ($row['id'] ?? 0);
    }

    /** @param array<string,mixed> $bundle */
    private function availableQuantity(array $bundle): ?float
    {
        if (($bundle['stock_mode'] ?? 'components') !== 'components') {
            return null;
        }
        $available = null;
        foreach ($bundle['components'] as $component) {
            if (!((bool) ($component['is_required'] ?? true))) {
                continue;
            }
            if (!((bool) ($component['effective_track_stock'] ?? false)) || ((bool) ($component['effective_allow_backorder'] ?? false))) {
                continue;
            }
            $componentAvailable = max(0.0, (float) ($component['stock_quantity'] ?? 0) - (float) ($component['stock_reserved'] ?? 0));
            $quantity = max(0.0001, (float) ($component['quantity'] ?? 1));
            $possible = floor($componentAvailable / $quantity);
            $available = $available === null ? $possible : min($available, $possible);
        }
        return $available === null ? null : (float) $available;
    }

    /** @param array<string,mixed> $bundle @return array{status:string,delivery_lead_time_days:int|null} */
    private function availabilitySummary(array $bundle): array
    {
        if (($bundle['stock_mode'] ?? 'components') !== 'components') {
            return ['status' => 'in_stock', 'delivery_lead_time_days' => null];
        }
        $hasBackorder = false;
        $leadTimeDays = 0;
        foreach ($bundle['components'] as $component) {
            if (!((bool) ($component['is_required'] ?? true)) || !((bool) ($component['effective_track_stock'] ?? false))) {
                continue;
            }
            $available = max(0.0, (float) ($component['stock_quantity'] ?? 0) - (float) ($component['stock_reserved'] ?? 0));
            if ($available > 0.0) {
                continue;
            }
            if (!((bool) ($component['effective_allow_backorder'] ?? false))) {
                return ['status' => 'contact_us', 'delivery_lead_time_days' => null];
            }
            $hasBackorder = true;
            $leadTimeDays = max($leadTimeDays, max(1, (int) ($component['effective_backorder_delivery_days'] ?? 7)));
        }
        return $hasBackorder
            ? ['status' => 'backorder', 'delivery_lead_time_days' => $leadTimeDays]
            : ['status' => 'in_stock', 'delivery_lead_time_days' => null];
    }

    /** @param array<string,mixed> $bundle */
    private function assertNoCycleForComponent(int $siteId, int $bundleProductId, int $componentProductId): void
    {
        if ($componentProductId === $bundleProductId || $this->productGraphReaches($siteId, $componentProductId, $bundleProductId, [])) {
            throw new InvalidArgumentException('business.bundle_loop_detected');
        }
    }

    private function assertBundleAcyclic(int $siteId, int $bundleProductId): void
    {
        $bundle = $this->bundleRowForProduct($siteId, $bundleProductId);
        if ($bundle === null) {
            return;
        }
        foreach ($this->componentsRows((int) $bundle['id']) as $component) {
            $this->assertNoCycleForComponent($siteId, $bundleProductId, (int) $component['component_product_id']);
        }
    }

    /** @param array<int,bool> $visited */
    private function productGraphReaches(int $siteId, int $fromProductId, int $targetProductId, array $visited): bool
    {
        if ($fromProductId === $targetProductId) {
            return true;
        }
        if (isset($visited[$fromProductId])) {
            return false;
        }
        $visited[$fromProductId] = true;
        $bundles = $this->db->all(
            'SELECT id FROM business_product_bundles WHERE site_id=? AND bundle_product_id=? AND is_active=1 AND archived_at IS NULL',
            [$siteId, $fromProductId]
        );
        foreach ($bundles as $bundle) {
            foreach ($this->componentsRows((int) $bundle['id']) as $component) {
                if ($this->productGraphReaches($siteId, (int) $component['component_product_id'], $targetProductId, $visited)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return array<string,mixed> */
    private function product(int $siteId, int $productId, bool $allowArchived = false): array
    {
        $sql = 'SELECT * FROM business_products WHERE site_id = ? AND id = ?' . ($allowArchived ? '' : ' AND archived_at IS NULL') . ' LIMIT 1';
        $row = $this->db->one($sql, [$this->id($siteId, 'site_id'), $this->id($productId, 'product_id')]);
        if ($row === null) {
            throw new InvalidArgumentException('business.catalog.product_not_found');
        }
        return $this->cast($row);
    }

    /** @return array<string,mixed> */
    private function variant(int $siteId, int $variantId, bool $allowArchived = false): array
    {
        $sql = 'SELECT v.*,p.site_id,p.track_stock AS product_track_stock,p.allow_backorder AS product_allow_backorder,p.backorder_delivery_days AS product_backorder_delivery_days FROM business_product_variants v INNER JOIN business_products p ON p.id = v.product_id WHERE p.site_id = ? AND v.id = ?'
            . ($allowArchived ? '' : ' AND v.archived_at IS NULL AND p.archived_at IS NULL') . ' LIMIT 1';
        $row = $this->db->one($sql, [$this->id($siteId, 'site_id'), $this->id($variantId, 'variant_id')]);
        if ($row === null) {
            throw new InvalidArgumentException('business.catalog.variant_not_found');
        }
        return $this->cast($row);
    }

    private function id(int|string|null $id, string $field): int
    {
        $value = (int) $id;
        if ($value < 1) {
            throw new InvalidArgumentException('business.' . $field . '_invalid');
        }
        return $value;
    }

    private function nullableId(mixed $id, string $field): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }
        return $this->id((int) $id, $field);
    }

    /** @param list<string> $allowed */
    private function choice(string $value, array $allowed, string $field): string
    {
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException('business.bundle_' . $field . '_invalid');
        }
        return $value;
    }

    private function stockStrategy(mixed $strategy, string $legacyMode): string
    {
        $value = strtoupper(trim((string) ($strategy ?? '')));
        if ($value === '') {
            $value = match ($legacyMode) { 'virtual' => 'OWN_STOCK', 'none' => 'NON_STOCKED', default => 'COMPONENT_DERIVED' };
        }
        return $this->choice($value, self::STOCK_STRATEGIES, 'stock_strategy');
    }

    /** @param array<string,mixed> $bundle */
    private function strategyForBundle(array $bundle): string
    {
        return $this->stockStrategy($bundle['stock_strategy'] ?? null, (string) ($bundle['stock_mode'] ?? 'components'));
    }

    private function legacyStockMode(string $strategy): string
    {
        return match ($strategy) { 'OWN_STOCK' => 'virtual', 'NON_STOCKED' => 'none', default => 'components' };
    }

    private function hasTable(string $table): bool
    {
        return $this->db->one("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?", [$table]) !== null;
    }

    private function hasColumn(string $table, string $column): bool
    {
        foreach ($this->db->all('PRAGMA table_info(' . $table . ')') as $row) {
            if ((string) ($row['name'] ?? '') === $column) return true;
        }
        return false;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function cast(array $row): array
    {
        foreach (['id', 'site_id', 'bundle_product_id', 'bundle_variant_id', 'created_by_iam_user_id', 'updated_by_iam_user_id', 'product_id'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (int) $row[$key];
            }
        }
        foreach (['is_active', 'partial_fulfillment_supported', 'components_public'] as $key) {
            if (array_key_exists($key, $row)) $row[$key] = (bool) $row[$key];
        }
        if (array_key_exists('stock_mode', $row)) $row['stock_strategy'] = $this->strategyForBundle($row);
        $row['partial_availability_policy'] ??= 'REQUIRE_ALL';
        $row['component_return_policy'] ??= 'BUNDLE_ONLY';
        $row['components_public'] ??= true;
        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function castComponent(array $row): array
    {
        foreach (['id', 'bundle_id', 'component_product_id', 'component_variant_id', 'sort_order'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (int) $row[$key];
            }
        }
        foreach (['quantity', 'stock_quantity', 'stock_reserved'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (float) $row[$key];
            }
        }
        foreach (['is_required', 'effective_track_stock', 'effective_allow_backorder'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (bool) $row[$key];
            }
        }
        $metadata = json_decode((string) ($row['metadata_json'] ?? '{}'), true);
        $row['metadata'] = is_array($metadata) ? $metadata : [];
        return $row;
    }
}
