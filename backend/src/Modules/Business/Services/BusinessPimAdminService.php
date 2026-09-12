<?php

declare(strict_types=1);

namespace App\Modules\Business\Services;

use App\Core\Database;
use InvalidArgumentException;

final class BusinessPimAdminService
{
    private const ATTRIBUTE_TYPES = ['text','textarea','rich_text','number','decimal','boolean','select','multi_select','date','url','file','dimension','weight','color'];
    private const CHANNELS = ['all','public','ecommerce','pos','catalogue','admin','pdf'];
    private const OFFER_CSV_HEADERS = [
        'offer_type', 'offer_id', 'name', 'status', 'channel', 'sku', 'slug',
        'is_public', 'is_ecommerce_enabled', 'is_pos_enabled', 'is_catalogue_enabled',
        'pricing_mode', 'stock_mode', 'bundle_active',
        'discount_type', 'discount_value', 'currency', 'scope_type', 'scope_id',
        'priority', 'starts_at', 'ends_at',
    ];
    private const CSV_DELIMITER = ';';
    private const MAX_CSV_BYTES = 1048576;

    private readonly BusinessProductCompletenessService $completeness;

    public function __construct(private readonly Database $db, ?BusinessProductCompletenessService $completeness = null)
    {
        $this->completeness = $completeness ?? new BusinessProductCompletenessService($db);
    }

    /** @return list<array<string,mixed>> */
    public function attributeGroups(int $siteId): array
    {
        return array_map(fn(array $row): array => $this->cast($row), $this->db->all(
            'SELECT * FROM business_attribute_groups WHERE site_id = ? AND archived_at IS NULL ORDER BY sort_order ASC, name ASC, id ASC',
            [$this->siteId($siteId)]
        ));
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function createAttributeGroup(int $siteId, array $payload, int $actorId): array
    {
        $this->db->run(
            'INSERT INTO business_attribute_groups(site_id, code, name, description, sort_order, created_by_iam_user_id, updated_by_iam_user_id)
             VALUES(:site_id, :code, :name, :description, :sort_order, :actor, :actor)',
            [
                'site_id' => $this->siteId($siteId),
                'code' => $this->key($payload['code'] ?? $payload['name'] ?? '', 'attribute_group_code'),
                'name' => $this->text($payload['name'] ?? null, 'attribute_group_name', 180),
                'description' => $this->nullableText($payload['description'] ?? null, 1000),
                'sort_order' => max(0, (int) ($payload['sort_order'] ?? 0)),
                'actor' => $actorId > 0 ? $actorId : null,
            ]
        );
        return $this->attributeGroup((int) $this->db->lastInsertId());
    }

    /** @param array<string,mixed> $payload @return array<string,mixed>|null */
    public function updateAttributeGroup(int $siteId, int $id, array $payload, int $actorId): ?array
    {
        $current = $this->attributeGroupForSite($siteId, $id);
        if ($current === null) {
            return null;
        }
        $this->db->run(
            'UPDATE business_attribute_groups
             SET code = :code, name = :name, description = :description, sort_order = :sort_order,
                 updated_by_iam_user_id = :actor, updated_at = CURRENT_TIMESTAMP
             WHERE site_id = :site_id AND id = :id',
            [
                'site_id' => $this->siteId($siteId),
                'id' => $this->id($id, 'attribute_group_id'),
                'code' => $this->key($payload['code'] ?? $current['code'], 'attribute_group_code'),
                'name' => $this->text($payload['name'] ?? $current['name'], 'attribute_group_name', 180),
                'description' => $this->nullableText($payload['description'] ?? $current['description'] ?? null, 1000),
                'sort_order' => max(0, (int) ($payload['sort_order'] ?? $current['sort_order'] ?? 0)),
                'actor' => $actorId > 0 ? $actorId : null,
            ]
        );
        return $this->attributeGroup($id);
    }

    public function archiveAttributeGroup(int $siteId, int $id, int $actorId): void
    {
        $id=$this->id($id,'attribute_group_id');
        if ($this->db->one('SELECT 1 FROM business_product_attribute_group_links WHERE group_id = ? LIMIT 1',[$id])!==null) {
            throw new InvalidArgumentException('business.attribute_group_in_use');
        }
        $this->db->run(
            'UPDATE business_attribute_groups SET archived_at = CURRENT_TIMESTAMP, updated_by_iam_user_id = ?, updated_at = CURRENT_TIMESTAMP WHERE site_id = ? AND id = ?',
            [$actorId > 0 ? $actorId : null, $this->siteId($siteId), $id]
        );
    }

    /** @return list<array<string,mixed>> */
    public function attributes(int $siteId): array
    {
        return array_map(fn(array $row): array => $this->attributePayload($row), $this->db->all(
            'SELECT a.*, g.code AS group_code, g.name AS group_name
             FROM business_attributes a
             INNER JOIN business_attribute_groups g ON g.id = a.group_id
             WHERE a.site_id = ? AND a.archived_at IS NULL
             ORDER BY COALESCE(g.sort_order, 9999) ASC, a.sort_order ASC, a.name ASC, a.id ASC',
            [$this->siteId($siteId)]
        ));
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function createAttribute(int $siteId, array $payload, int $actorId): array
    {
        $groupId = $this->requiredGroupId($payload['group_id'] ?? null);
        $this->assertGroupBelongsToSite($siteId, $groupId);
        $this->assertPublicAttributeFlags($payload['is_public']??false,$payload['is_filterable']??false,$payload['is_searchable']??false);
        $this->db->run(
            'INSERT INTO business_attributes(site_id, group_id, code, name, data_type, unit, is_required, is_filterable, is_searchable, is_public, sort_order, validation_json, created_by_iam_user_id, updated_by_iam_user_id)
             VALUES(:site_id, :group_id, :code, :name, :data_type, :unit, :is_required, :is_filterable, :is_searchable, :is_public, :sort_order, :validation_json, :actor, :actor)',
            [
                'site_id' => $this->siteId($siteId),
                'group_id' => $groupId,
                'code' => $this->key($payload['code'] ?? $payload['name'] ?? '', 'attribute_code'),
                'name' => $this->text($payload['name'] ?? null, 'attribute_name', 180),
                'data_type' => $this->choice((string) ($payload['data_type'] ?? 'text'), self::ATTRIBUTE_TYPES, 'attribute_type'),
                'unit' => $this->nullableText($payload['unit'] ?? null, 32),
                'is_required' => $this->bool($payload['is_required'] ?? false),
                'is_filterable' => $this->bool($payload['is_filterable'] ?? false),
                'is_searchable' => $this->bool($payload['is_searchable'] ?? false),
                'is_public' => $this->bool($payload['is_public'] ?? false),
                'sort_order' => max(0, (int) ($payload['sort_order'] ?? 0)),
                'validation_json' => $this->jsonObject($payload['validation'] ?? $payload['validation_json'] ?? []),
                'actor' => $actorId > 0 ? $actorId : null,
            ]
        );
        return $this->attribute((int) $this->db->lastInsertId());
    }

    /** @param array<string,mixed> $payload @return array<string,mixed>|null */
    public function updateAttribute(int $siteId, int $id, array $payload, int $actorId): ?array
    {
        $current = $this->attributeForSite($siteId, $id);
        if ($current === null) {
            return null;
        }
        $groupId = array_key_exists('group_id', $payload)
            ? $this->requiredGroupId($payload['group_id'])
            : $this->requiredGroupId($current['group_id'] ?? null);
        $this->assertGroupBelongsToSite($siteId, $groupId);
        $dataType = $this->choice((string) ($payload['data_type'] ?? $current['data_type']), self::ATTRIBUTE_TYPES, 'attribute_type');
        $this->assertPublicAttributeFlags($payload['is_public']??$current['is_public'],$payload['is_filterable']??$current['is_filterable'],$payload['is_searchable']??$current['is_searchable']);
        if ($dataType !== (string) $current['data_type'] && $this->attributeHasValues($id)) {
            throw new InvalidArgumentException('business.attribute_type_locked');
        }
        $this->db->run(
            'UPDATE business_attributes
             SET group_id = :group_id, code = :code, name = :name, data_type = :data_type, unit = :unit,
                 is_required = :is_required, is_filterable = :is_filterable, is_searchable = :is_searchable,
                 is_public = :is_public, sort_order = :sort_order, validation_json = :validation_json,
                 updated_by_iam_user_id = :actor, updated_at = CURRENT_TIMESTAMP
             WHERE site_id = :site_id AND id = :id',
            [
                'site_id' => $this->siteId($siteId),
                'id' => $this->id($id, 'attribute_id'),
                'group_id' => $groupId,
                'code' => $this->key($payload['code'] ?? $current['code'], 'attribute_code'),
                'name' => $this->text($payload['name'] ?? $current['name'], 'attribute_name', 180),
                'data_type' => $dataType,
                'unit' => $this->nullableText($payload['unit'] ?? $current['unit'] ?? null, 32),
                'is_required' => $this->bool($payload['is_required'] ?? $current['is_required']),
                'is_filterable' => $this->bool($payload['is_filterable'] ?? $current['is_filterable']),
                'is_searchable' => $this->bool($payload['is_searchable'] ?? $current['is_searchable']),
                'is_public' => $this->bool($payload['is_public'] ?? $current['is_public']),
                'sort_order' => max(0, (int) ($payload['sort_order'] ?? $current['sort_order'] ?? 0)),
                'validation_json' => $this->jsonObject($payload['validation'] ?? $payload['validation_json'] ?? $this->jsonDecode((string) $current['validation_json'])),
                'actor' => $actorId > 0 ? $actorId : null,
            ]
        );
        return $this->attribute($id);
    }

    public function archiveAttribute(int $siteId, int $id, int $actorId): void
    {
        if ($this->attributeHasValues($id)) throw new InvalidArgumentException('business.attribute_in_use');
        $this->db->run(
            'UPDATE business_attributes SET archived_at = CURRENT_TIMESTAMP, updated_by_iam_user_id = ?, updated_at = CURRENT_TIMESTAMP WHERE site_id = ? AND id = ?',
            [$actorId > 0 ? $actorId : null, $this->siteId($siteId), $this->id($id, 'attribute_id')]
        );
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function createOption(int $siteId, int $attributeId, array $payload): array
    {
        $this->requireAttributeForSite($siteId, $attributeId);
        $this->db->run(
            'INSERT INTO business_attribute_options(attribute_id, code, label, value, color_hex, sort_order)
             VALUES(:attribute_id, :code, :label, :value, :color_hex, :sort_order)',
            [
                'attribute_id' => $this->id($attributeId, 'attribute_id'),
                'code' => $this->key($payload['code'] ?? $payload['label'] ?? $payload['value'] ?? '', 'attribute_option_code'),
                'label' => $this->text($payload['label'] ?? null, 'attribute_option_label', 180),
                'value' => $this->text($payload['value'] ?? $payload['label'] ?? null, 'attribute_option_value', 180),
                'color_hex' => $this->color($payload['color_hex'] ?? null),
                'sort_order' => max(0, (int) ($payload['sort_order'] ?? 0)),
            ]
        );
        return $this->option((int) $this->db->lastInsertId());
    }

    /** @param array<string,mixed> $payload @return array<string,mixed>|null */
    public function updateOption(int $siteId, int $id, array $payload): ?array
    {
        $current = $this->optionForSite($siteId, $id);
        if ($current === null) {
            return null;
        }
        $nextValue=$this->text($payload['value'] ?? $current['value'],'attribute_option_value',180);
        if ($nextValue!==(string)$current['value'] && $this->optionHasValues($current)) {
            throw new InvalidArgumentException('business.attribute_option_value_locked');
        }
        $this->db->run(
            'UPDATE business_attribute_options
             SET code = :code, label = :label, value = :value, color_hex = :color_hex, sort_order = :sort_order, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            [
                'id' => $this->id($id, 'attribute_option_id'),
                'code' => $this->key($payload['code'] ?? $current['code'], 'attribute_option_code'),
                'label' => $this->text($payload['label'] ?? $current['label'], 'attribute_option_label', 180),
                'value' => $nextValue,
                'color_hex' => $this->color($payload['color_hex'] ?? $current['color_hex'] ?? null),
                'sort_order' => max(0, (int) ($payload['sort_order'] ?? $current['sort_order'] ?? 0)),
            ]
        );
        return $this->option($id);
    }

    public function archiveOption(int $siteId, int $id): void
    {
        $option=$this->optionForSite($siteId,$id);
        if ($option===null) return;
        if ($this->optionHasValues($option)) throw new InvalidArgumentException('business.attribute_option_in_use');
        $this->db->run('UPDATE business_attribute_options SET archived_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?', [$this->id($id, 'attribute_option_id')]);
    }

    /** @return list<array<string,mixed>> */
    public function productAttributeValues(int $siteId, int $productId): array
    {
        $this->requireProduct($siteId, $productId);
        return $this->attributeValues('product', $productId);
    }

    /** @param array<string,mixed> $payload @return list<array<string,mixed>> */
    public function replaceProductAttributeValues(int $siteId, int $productId, array $payload, int $actorId): array
    {
        $product = $this->requireProduct($siteId, $productId);
        $this->replaceValues('product', $productId, $this->valuesPayload($payload), $actorId, (int) $product['site_id'], (int) $product['id']);
        $this->recalculateProductCompleteness($siteId, (int) $product['id']);
        return $this->productAttributeValues($siteId, $productId);
    }

    /** @return list<array<string,mixed>> */
    public function variantAttributeValues(int $siteId, int $variantId): array
    {
        $this->requireVariant($siteId, $variantId);
        return $this->attributeValues('variant', $variantId);
    }

    /** @param array<string,mixed> $payload @return list<array<string,mixed>> */
    public function replaceVariantAttributeValues(int $siteId, int $variantId, array $payload, int $actorId): array
    {
        $variant = $this->requireVariant($siteId, $variantId);
        $this->replaceValues('variant', $variantId, $this->valuesPayload($payload), $actorId, (int) $variant['site_id'], (int) $variant['product_id']);
        $this->recalculateProductCompleteness($siteId, (int) $variant['product_id']);
        return $this->variantAttributeValues($siteId, $variantId);
    }

    /** @return array<string,mixed> */
    public function productCompleteness(int $siteId, int $productId): array
    {
        $this->requireProduct($siteId, $productId);
        $rules = array_map(fn(array $row): array => $this->cast($row), $this->db->all(
            'SELECT * FROM business_product_completeness_rules WHERE site_id = ? AND is_active = 1 ORDER BY channel ASC, weight DESC, code ASC',
            [$this->siteId($siteId)]
        ));
        $scores = $this->completeness->storedProductCompleteness($this->id($productId, 'product_id'))['scores'];
        return ['product_id' => $productId, 'rules' => $rules, 'scores' => $scores];
    }

    /** @return array<string,mixed> */
    public function recalculateProductCompleteness(int $siteId, int $productId): array
    {
        $this->requireProduct($siteId, $productId);
        $this->completeness->recalculateProduct($this->id($productId, 'product_id'));
        return $this->productCompleteness($siteId, $productId) + ['recalculated' => true];
    }

    /** @return list<array<string,mixed>> */
    public function taxClasses(int $siteId): array
    {
        return array_map(fn(array $row): array => $this->cast($row), $this->db->all(
            'SELECT t.id, t.site_id, t.code, t.name, t.rate, t.country, t.is_default,
                    (SELECT COUNT(*) FROM business_products p WHERE p.site_id = t.site_id AND p.tax_class_id = t.id AND p.archived_at IS NULL) AS usage_count
             FROM business_tax_classes t
             WHERE t.site_id = ? AND t.archived_at IS NULL
             ORDER BY is_default DESC, rate DESC, name ASC',
            [$this->siteId($siteId)]
        ));
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function createTaxClass(int $siteId, array $payload, int $actorId): array
    {
        $data = $this->taxClassPayload($payload, null);
        $siteId = $this->siteId($siteId);
        $this->db->transaction(function () use ($siteId, $data, $actorId): void {
            if ((int) $data['is_default'] === 1) {
                $this->db->run('UPDATE business_tax_classes SET is_default = 0, updated_by_iam_user_id = ?, updated_at = CURRENT_TIMESTAMP WHERE site_id = ? AND archived_at IS NULL', [$actorId > 0 ? $actorId : null, $siteId]);
            }
            $this->db->run(
                'INSERT INTO business_tax_classes(site_id, code, name, rate, country, is_default, created_by_iam_user_id, updated_by_iam_user_id)
                 VALUES(:site_id, :code, :name, :rate, :country, :is_default, :actor, :actor)',
                [
                    'site_id' => $siteId,
                    'code' => $data['code'],
                    'name' => $data['name'],
                    'rate' => $data['rate'],
                    'country' => $data['country'],
                    'is_default' => $data['is_default'],
                    'actor' => $actorId > 0 ? $actorId : null,
                ]
            );
        });
        return $this->requireTaxClass($siteId, (int) $this->db->lastInsertId());
    }

    /** @param array<string,mixed> $payload @return array<string,mixed>|null */
    public function updateTaxClass(int $siteId, int $taxClassId, array $payload, int $actorId): ?array
    {
        $current = $this->requireTaxClass($siteId, $taxClassId);
        $data = $this->taxClassPayload($payload, $current);
        $siteId = $this->siteId($siteId);
        $taxClassId = $this->id($taxClassId, 'tax_class_id');
        $this->db->transaction(function () use ($siteId, $taxClassId, $data, $actorId): void {
            if ((int) $data['is_default'] === 1) {
                $this->db->run('UPDATE business_tax_classes SET is_default = 0, updated_by_iam_user_id = ?, updated_at = CURRENT_TIMESTAMP WHERE site_id = ? AND id <> ? AND archived_at IS NULL', [$actorId > 0 ? $actorId : null, $siteId, $taxClassId]);
            }
            $this->db->run(
                'UPDATE business_tax_classes
                 SET code = :code, name = :name, rate = :rate, country = :country, is_default = :is_default,
                     updated_by_iam_user_id = :actor, updated_at = CURRENT_TIMESTAMP
                 WHERE site_id = :site_id AND id = :id AND archived_at IS NULL',
                [
                    'code' => $data['code'],
                    'name' => $data['name'],
                    'rate' => $data['rate'],
                    'country' => $data['country'],
                    'is_default' => $data['is_default'],
                    'actor' => $actorId > 0 ? $actorId : null,
                    'site_id' => $siteId,
                    'id' => $taxClassId,
                ]
            );
        });
        return $this->requireTaxClass($siteId, $taxClassId);
    }

    public function deleteTaxClassIfUnused(int $siteId, int $taxClassId): bool
    {
        $taxClass = $this->requireTaxClass($siteId, $taxClassId);
        if ((int) ($taxClass['usage_count'] ?? 0) > 0) {
            throw new InvalidArgumentException('business.tax_class_in_use');
        }
        $this->db->run('DELETE FROM business_tax_classes WHERE site_id = ? AND id = ? AND archived_at IS NULL', [$this->siteId($siteId), $this->id($taxClassId, 'tax_class_id')]);
        return true;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function bulkUpdateProducts(int $siteId, array $payload, int $actorId): array
    {
        $ids = $this->ids($payload['product_ids'] ?? $payload['ids'] ?? []);
        $changes = is_array($payload['changes'] ?? null) ? $payload['changes'] : [];
        $dryRun = (bool) ($payload['dry_run'] ?? true);
        $allowed = $this->normalizeBulkProductChanges($siteId, $changes);
        $recalculated = 0;
        if (!$dryRun && $allowed !== []) {
            foreach ($ids as $id) {
                $this->requireProduct($siteId, $id);
                foreach ($allowed as $field => $value) {
                    if ($field === 'archive') {
                        $this->db->run(
                            'UPDATE business_products SET status = ?, archived_at = CURRENT_TIMESTAMP, updated_by_iam_user_id = ?, updated_at = CURRENT_TIMESTAMP WHERE site_id = ? AND id = ?',
                            ['archived', $actorId > 0 ? $actorId : null, $siteId, $id]
                        );
                        continue;
                    }
                    $this->db->run('UPDATE business_products SET ' . $field . ' = ?, updated_by_iam_user_id = ?, updated_at = CURRENT_TIMESTAMP WHERE site_id = ? AND id = ?', [$value, $actorId > 0 ? $actorId : null, $siteId, $id]);
                }
                if (!isset($allowed['archive'])) {
                    $this->recalculateProductCompleteness($siteId, $id);
                    $recalculated++;
                }
            }
        }
        return ['dry_run' => $dryRun, 'product_ids' => $ids, 'changes' => $allowed, 'updated' => $dryRun ? 0 : count($ids), 'recalculated' => $recalculated];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function bulkAssetAssign(int $siteId, array $payload, int $actorId, BusinessProductAssetService $assets): array
    {
        $ids = $this->ids($payload['product_ids'] ?? []);
        $dryRun = (bool) ($payload['dry_run'] ?? true);
        $assigned = [];
        foreach ($ids as $productId) {
            $this->requireProduct($siteId, $productId);
            if (!$dryRun) {
                $assigned[] = $assets->assignAsset([
                    'site_id' => $siteId,
                    'product_id' => $productId,
                    'media_id' => $payload['media_id'] ?? null,
                    'role' => $payload['role'] ?? 'gallery',
                    'channel_scope' => $payload['channel_scope'] ?? 'all',
                    'is_public' => $payload['is_public'] ?? false,
                    'actor_iam_user_id' => $actorId,
                ]);
            }
        }
        return ['dry_run' => $dryRun, 'product_ids' => $ids, 'assigned' => $assigned, 'assigned_count' => count($assigned)];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function bulkRecalculate(int $siteId, array $payload): array
    {
        $ids = $this->ids($payload['product_ids'] ?? $payload['ids'] ?? []);
        $items = [];
        foreach ($ids as $productId) {
            $items[] = $this->recalculateProductCompleteness($siteId, $productId);
        }
        return ['product_ids' => $ids, 'items' => $items, 'recalculated' => count($items)];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function bulkUpdateOffers(int $siteId, array $payload, int $actorId): array
    {
        $offers = is_array($payload['offers'] ?? null) ? $payload['offers'] : [];
        if ($offers === []) {
            throw new InvalidArgumentException('business.offers_invalid');
        }
        $changes = is_array($payload['changes'] ?? null) ? $payload['changes'] : [];
        $dryRun = (bool) ($payload['dry_run'] ?? true);
        $summary = ['bundle' => 0, 'discount' => 0];
        $normalizedOffers = [];

        foreach ($offers as $offer) {
            if (!is_array($offer)) {
                throw new InvalidArgumentException('business.offer_invalid');
            }
            $kind = (string) ($offer['kind'] ?? '');
            $id = $this->id((int) ($offer['id'] ?? 0), 'offer_id');
            if ($kind === 'bundle') {
                $this->requireBundleProduct($siteId, $id);
                $summary['bundle']++;
            } elseif ($kind === 'discount') {
                $this->requireDiscount($siteId, $id);
                $summary['discount']++;
            } else {
                throw new InvalidArgumentException('business.offer_kind_invalid');
            }
            $normalizedOffers[] = ['kind' => $kind, 'id' => $id];
        }

        $bundleChanges = $this->normalizeBulkBundleOfferChanges($siteId, $changes);
        $discountChanges = $this->normalizeBulkDiscountChanges($changes);

        if (!$dryRun) {
            foreach ($normalizedOffers as $offer) {
                if ($offer['kind'] === 'bundle' && $bundleChanges !== []) {
                    $this->applyBulkBundleChanges($siteId, (int) $offer['id'], $bundleChanges, $actorId);
                }
                if ($offer['kind'] === 'discount' && $discountChanges !== []) {
                    $this->applyBulkDiscountChanges($siteId, (int) $offer['id'], $discountChanges, $actorId);
                }
            }
        }

        return [
            'dry_run' => $dryRun,
            'offers' => $normalizedOffers,
            'changes' => ['bundle' => $bundleChanges, 'discount' => $discountChanges],
            'summary' => $summary,
            'updated' => $dryRun ? 0 : count($normalizedOffers),
        ];
    }

    /** @param array<string,mixed> $filters */
    public function exportOffersCsv(int $siteId, array $filters = []): string
    {
        $rows = [self::OFFER_CSV_HEADERS];
        $type = trim((string) ($filters['type'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));
        $channel = trim((string) ($filters['channel'] ?? ''));
        foreach ([$type, $status, $channel] as $filterValue) {
            if (str_contains($filterValue, "\0")) {
                throw new InvalidArgumentException('business.offers_export_filter_invalid');
            }
        }

        if ($type === '' || $type === 'bundle') {
            $bundleWhere = ['p.site_id = ?', 'p.type = \'bundle\'', 'p.archived_at IS NULL'];
            $bundleParams = [$this->siteId($siteId)];
            if ($status !== '') {
                $bundleWhere[] = 'p.status = ?';
                $bundleParams[] = $this->choice($status, ['draft','active','archived'], 'product_status');
            }
            if ($channel !== '') {
                $this->appendBundleChannelFilter($bundleWhere, $bundleParams, $channel);
            }
            $bundles = $this->db->all(
                'SELECT p.*, b.pricing_mode, b.stock_mode, b.is_active AS bundle_active
                 FROM business_products p
                 LEFT JOIN business_product_bundles b ON b.bundle_product_id = p.id AND b.archived_at IS NULL
                 WHERE ' . implode(' AND ', $bundleWhere) . '
                 ORDER BY p.name ASC, p.id ASC',
                $bundleParams
            );
            foreach ($bundles as $bundle) {
                $rows[] = [
                    'bundle',
                    $bundle['id'] ?? '',
                    $bundle['name'] ?? '',
                    $bundle['status'] ?? '',
                    implode('|', $this->bundleChannels($bundle)),
                    $bundle['sku_base'] ?? '',
                    $bundle['slug'] ?? '',
                    $this->csvBool($bundle['is_public'] ?? false),
                    $this->csvBool($bundle['is_ecommerce_enabled'] ?? false),
                    $this->csvBool($bundle['is_pos_enabled'] ?? false),
                    $this->csvBool($bundle['is_catalogue_enabled'] ?? true),
                    $bundle['pricing_mode'] ?? 'fixed',
                    $bundle['stock_mode'] ?? 'components',
                    $this->csvBool($bundle['bundle_active'] ?? true),
                    '', '', '', '', '', '', '', '',
                ];
            }
        }

        if ($type === '' || $type === 'discount') {
            $discountWhere = ['site_id = ?', 'archived_at IS NULL'];
            $discountParams = [$this->siteId($siteId)];
            if ($status !== '') {
                $discountWhere[] = 'status = ?';
                $discountParams[] = $this->choice($status, ['draft','active','archived'], 'discount_status');
            }
            if ($channel !== '') {
                $discountWhere[] = 'channel = ?';
                $discountParams[] = $this->choice($channel, ['all','ecommerce','pos','catalogue','admin'], 'discount_channel');
            }
            $discounts = $this->db->all(
                'SELECT * FROM business_catalog_discounts WHERE ' . implode(' AND ', $discountWhere) . ' ORDER BY priority ASC, name ASC, id ASC',
                $discountParams
            );
            foreach ($discounts as $discount) {
                $rows[] = [
                    'discount',
                    $discount['id'] ?? '',
                    $discount['name'] ?? '',
                    $discount['status'] ?? '',
                    $discount['channel'] ?? 'all',
                    '', '',
                    '', '', '', '',
                    '', '', '',
                    $discount['discount_type'] ?? '',
                    $discount['discount_value'] ?? '',
                    $discount['currency'] ?? '',
                    $discount['scope_type'] ?? '',
                    $discount['scope_id'] ?? '',
                    $discount['priority'] ?? '',
                    $discount['starts_at'] ?? '',
                    $discount['ends_at'] ?? '',
                ];
            }
        }

        return $this->csv($rows);
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function importOffersCsv(int $siteId, string $csv, array $options, int $actorId): array
    {
        if (strlen($csv) > self::MAX_CSV_BYTES) {
            throw new InvalidArgumentException('business.offers_csv_file_too_large');
        }
        if (preg_match('//u', $csv) !== 1) {
            throw new InvalidArgumentException('business.offers_csv_utf8_required');
        }
        $dryRun = filter_var($options['dry_run'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $dryRun = $dryRun !== false;
        [$headers, $rows] = $this->parseCsv($csv);
        $this->assertOfferCsvHeaders($headers);

        $report = [
            'dry_run' => $dryRun,
            'rows_total' => count($rows),
            'valid_rows' => 0,
            'created_bundles' => 0,
            'updated_bundles' => 0,
            'created_discounts' => 0,
            'updated_discounts' => 0,
            'skipped' => 0,
            'errors' => [],
            'rows' => [],
            'writes_performed' => false,
        ];
        $plans = [];
        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $data = $this->normalizeCsvRow($headers, $row);
            $rowReport = ['line' => $line, 'status' => 'valid', 'action' => '', 'errors' => []];
            try {
                $plan = $this->planOfferImportRow($siteId, $data);
                $rowReport['action'] = $plan['action'];
                $rowReport['offer_type'] = $plan['offer_type'];
                $plans[] = $plan;
                $report['valid_rows']++;
            } catch (InvalidArgumentException $e) {
                $rowReport['status'] = 'error';
                $rowReport['errors'][] = $e->getMessage();
                $report['errors'][] = ['line' => $line, 'message' => $e->getMessage()];
                $report['skipped']++;
            }
            $report['rows'][] = $rowReport;
        }

        if ($dryRun || $report['errors'] !== []) {
            return $report;
        }

        return $this->db->transaction(function () use ($siteId, $actorId, $plans, $report): array {
            $result = $report;
            foreach ($plans as $plan) {
                $write = $this->applyOfferImportPlan($siteId, $plan, $actorId);
                $result[$write['counter']]++;
                $result['writes_performed'] = true;
            }
            return $result;
        });
    }

    public function setMainAsset(int $siteId, int $assetId, int $actorId): ?array
    {
        $asset = $this->db->one('SELECT * FROM business_product_assets WHERE site_id = ? AND id = ? AND archived_at IS NULL LIMIT 1', [$this->siteId($siteId), $this->id($assetId, 'asset_id')]);
        if ($asset === null) {
            return null;
        }
        $params = [
            'asset_id' => $assetId,
            'actor' => $actorId > 0 ? $actorId : null,
            'site_id' => $siteId,
            'product_id' => (int) $asset['product_id'],
            'variant_id' => $asset['variant_id'] === null ? null : (int) $asset['variant_id'],
            'channel_scope' => (string) $asset['channel_scope'],
        ];
        $this->db->transaction(function () use ($params): void {
            $this->db->run(
                'UPDATE business_product_assets
                 SET role = \'gallery\', updated_by_iam_user_id = :actor, updated_at = CURRENT_TIMESTAMP
                 WHERE site_id = :site_id
                   AND product_id = :product_id
                   AND channel_scope = :channel_scope
                   AND COALESCE(variant_id, 0) = COALESCE(:variant_id, 0)
                   AND role = \'main\'
                   AND id <> :asset_id
                   AND archived_at IS NULL',
                $params
            );
            $this->db->run(
                'UPDATE business_product_assets
                 SET role = \'main\', updated_by_iam_user_id = :actor, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :asset_id',
                [
                    'asset_id' => $params['asset_id'],
                    'actor' => $params['actor'],
                ]
            );
        });
        return $this->db->one('SELECT * FROM business_product_assets WHERE id = ? LIMIT 1', [$assetId]);
    }

    /** @param list<array<mixed>> $rows */
    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new InvalidArgumentException('business.offers_csv_write_failed');
        }
        foreach ($rows as $row) {
            fputcsv($handle, array_map(static fn(mixed $value): string => (string) ($value ?? ''), $row), self::CSV_DELIMITER, '"', '');
        }
        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);
        return $content === false ? '' : $content;
    }

    /** @return array{0:list<string>,1:list<list<string>>} */
    private function parseCsv(string $csv): array
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new InvalidArgumentException('business.offers_csv_read_failed');
        }
        fwrite($handle, preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv);
        rewind($handle);
        $headers = fgetcsv($handle, 0, self::CSV_DELIMITER, '"', '');
        if (!is_array($headers) || $headers === []) {
            fclose($handle);
            throw new InvalidArgumentException('business.offers_csv_header_required');
        }
        $headers = array_map(static fn(mixed $value): string => strtolower(trim((string) $value)), $headers);
        $rows = [];
        while (($row = fgetcsv($handle, 0, self::CSV_DELIMITER, '"', '')) !== false) {
            if (!is_array($row) || implode('', array_map('trim', $row)) === '') {
                continue;
            }
            $rows[] = array_map(static fn(mixed $value): string => trim((string) $value), $row);
        }
        fclose($handle);
        return [$headers, $rows];
    }

    /** @param list<string> $headers */
    private function assertOfferCsvHeaders(array $headers): void
    {
        $known = array_fill_keys(self::OFFER_CSV_HEADERS, true);
        foreach ($headers as $header) {
            if (!isset($known[$header])) {
                throw new InvalidArgumentException('business.offers_csv_unknown_header_' . $header);
            }
        }
        foreach (['offer_type', 'name'] as $required) {
            if (!in_array($required, $headers, true)) {
                throw new InvalidArgumentException('business.offers_csv_header_' . $required . '_required');
            }
        }
    }

    /** @param list<string> $headers @param list<string> $row @return array<string,string> */
    private function normalizeCsvRow(array $headers, array $row): array
    {
        $data = [];
        foreach ($headers as $index => $header) {
            $data[$header] = trim((string) ($row[$index] ?? ''));
        }
        return $data;
    }

    /** @param array<string,string> $row @return array<string,mixed> */
    private function planOfferImportRow(int $siteId, array $row): array
    {
        $type = $this->choice($row['offer_type'] ?? '', ['bundle','discount'], 'offer_type');
        return $type === 'bundle'
            ? $this->planBundleImportRow($siteId, $row)
            : $this->planDiscountImportRow($siteId, $row);
    }

    /** @param array<string,string> $row @return array<string,mixed> */
    private function planBundleImportRow(int $siteId, array $row): array
    {
        $id = (int) ($row['offer_id'] ?? 0);
        $existing = $id > 0 ? $this->requireBundleProduct($siteId, $id) : null;
        $name = $this->text($row['name'] ?? '', 'offer_name', 180);
        $slug = trim($row['slug'] ?? '') !== '' ? $this->key($row['slug'], 'offer_slug') : $this->key($name, 'offer_slug');
        $sku = strtoupper(preg_replace('/[^A-Z0-9_-]+/', '', strtoupper(trim((string) ($row['sku'] ?? '')))) ?? '');
        $channel = trim((string) ($row['channel'] ?? ''));
        if ($existing === null && $sku === '') {
            throw new InvalidArgumentException('business.offers_bundle_sku_required');
        }
        return [
            'offer_type' => 'bundle',
            'action' => $existing === null ? 'create_bundle' : 'update_bundle',
            'id' => $existing['id'] ?? null,
            'product' => [
                'name' => $name,
                'status' => $this->choice(($row['status'] ?? '') ?: 'draft', ['draft','active','archived'], 'product_status'),
                'sku_base' => $sku !== '' ? $sku : ($existing['sku_base'] ?? null),
                'slug' => $slug,
                'is_public' => $this->csvBoolValue($row['is_public'] ?? '') || in_array($channel, ['public','all'], true),
                'is_ecommerce_enabled' => $this->csvBoolValue($row['is_ecommerce_enabled'] ?? '') || in_array($channel, ['ecommerce','all'], true),
                'is_pos_enabled' => $this->csvBoolValue($row['is_pos_enabled'] ?? '') || in_array($channel, ['pos','all'], true),
                'is_catalogue_enabled' => $this->csvBoolValue($row['is_catalogue_enabled'] ?? '1') || in_array($channel, ['catalogue','all'], true),
            ],
            'bundle' => [
                'pricing_mode' => $this->choice($row['pricing_mode'] ?: 'fixed', ['fixed','sum_components','discount_components'], 'pricing_mode'),
                'stock_mode' => $this->choice($row['stock_mode'] ?: 'components', ['components','virtual','none'], 'stock_mode'),
                'is_active' => $this->csvBoolValue($row['bundle_active'] ?? '1'),
            ],
        ];
    }

    /** @param array<string,string> $row @return array<string,mixed> */
    private function planDiscountImportRow(int $siteId, array $row): array
    {
        $id = (int) ($row['offer_id'] ?? 0);
        $existing = $id > 0 ? $this->requireDiscount($siteId, $id) : null;
        $discountType = $this->choice($row['discount_type'] ?: (string) ($existing['discount_type'] ?? ''), ['percent','amount'], 'discount_type');
        $value = (float) ($row['discount_value'] !== '' ? $row['discount_value'] : ($existing['discount_value'] ?? 0));
        if ($discountType === 'percent' && ($value <= 0 || $value > 100)) {
            throw new InvalidArgumentException('business.offers_discount_percent_invalid');
        }
        if ($discountType === 'amount' && $value <= 0) {
            throw new InvalidArgumentException('business.offers_discount_amount_invalid');
        }
        $scopeType = $this->choice($row['scope_type'] ?: (string) ($existing['scope_type'] ?? ''), ['product','variant','category','brand'], 'discount_scope');
        $scopeId = (int) ($row['scope_id'] !== '' ? $row['scope_id'] : ($existing['scope_id'] ?? 0));
        $this->assertDiscountScopeExists($siteId, $scopeType, $scopeId);
        $startsAt = trim($row['starts_at'] ?? '') ?: ($existing['starts_at'] ?? null);
        $endsAt = trim($row['ends_at'] ?? '') ?: ($existing['ends_at'] ?? null);
        if ($startsAt !== null && $endsAt !== null && strcmp((string) $endsAt, (string) $startsAt) <= 0) {
            throw new InvalidArgumentException('business.offers_discount_date_range_invalid');
        }
        return [
            'offer_type' => 'discount',
            'action' => $existing === null ? 'create_discount' : 'update_discount',
            'id' => $existing['id'] ?? null,
            'discount' => [
                'name' => $this->text($row['name'] ?? ($existing['name'] ?? ''), 'offer_name', 180),
                'status' => $this->choice(($row['status'] ?? '') ?: (string) ($existing['status'] ?? 'draft'), ['draft','active','archived'], 'discount_status'),
                'channel' => $this->choice($row['channel'] ?: (string) ($existing['channel'] ?? 'all'), ['all','ecommerce','pos','catalogue','admin'], 'discount_channel'),
                'discount_type' => $discountType,
                'discount_value' => round($value, 2),
                'currency' => $discountType === 'amount' ? strtoupper(trim($row['currency'] ?: (string) ($existing['currency'] ?? 'CHF'))) : null,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'priority' => (int) ($row['priority'] !== '' ? $row['priority'] : ($existing['priority'] ?? 100)),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ],
        ];
    }

    /** @param array<string,mixed> $plan @return array{counter:string} */
    private function applyOfferImportPlan(int $siteId, array $plan, int $actorId): array
    {
        if ($plan['offer_type'] === 'bundle') {
            $id = $this->applyBundleImportPlan($siteId, $plan, $actorId);
            $this->applyBundleImportBundleRow($siteId, $id, $plan['bundle'], $actorId);
            return ['counter' => $plan['action'] === 'create_bundle' ? 'created_bundles' : 'updated_bundles'];
        }
        $this->applyDiscountImportPlan($siteId, $plan, $actorId);
        return ['counter' => $plan['action'] === 'create_discount' ? 'created_discounts' : 'updated_discounts'];
    }

    /** @param array<string,mixed> $plan */
    private function applyBundleImportPlan(int $siteId, array $plan, int $actorId): int
    {
        $product = $plan['product'];
        $status = (string) $product['status'];
        $archivedAt = $status === 'archived' ? 'CURRENT_TIMESTAMP' : 'NULL';
        if ($plan['action'] === 'create_bundle') {
            $this->db->run(
                'INSERT INTO business_products(site_id, type, status, visibility, sku_base, name, slug, unit, is_public, is_ecommerce_enabled, is_pos_enabled, is_catalogue_enabled, created_by_iam_user_id, updated_by_iam_user_id, archived_at)
                 VALUES(:site_id, \'bundle\', :status, :visibility, :sku, :name, :slug, \'unit\', :is_public, :is_ecommerce, :is_pos, :is_catalogue, :actor, :actor, ' . $archivedAt . ')',
                [
                    'site_id' => $siteId,
                    'status' => $status,
                    'visibility' => !empty($product['is_public']) ? 'public' : 'internal',
                    'sku' => $product['sku_base'],
                    'name' => $product['name'],
                    'slug' => $product['slug'],
                    'is_public' => !empty($product['is_public']) ? 1 : 0,
                    'is_ecommerce' => !empty($product['is_ecommerce_enabled']) ? 1 : 0,
                    'is_pos' => !empty($product['is_pos_enabled']) ? 1 : 0,
                    'is_catalogue' => !empty($product['is_catalogue_enabled']) ? 1 : 0,
                    'actor' => $actorId > 0 ? $actorId : null,
                ]
            );
            return (int) $this->db->lastInsertId();
        }
        $id = (int) $plan['id'];
        $this->db->run(
            'UPDATE business_products
             SET status = :status, visibility = :visibility, sku_base = :sku, name = :name, slug = :slug,
                 is_public = :is_public, is_ecommerce_enabled = :is_ecommerce, is_pos_enabled = :is_pos, is_catalogue_enabled = :is_catalogue,
                 archived_at = ' . $archivedAt . ', updated_by_iam_user_id = :actor, updated_at = CURRENT_TIMESTAMP
             WHERE site_id = :site_id AND id = :id AND type = \'bundle\'',
            [
                'site_id' => $siteId,
                'id' => $id,
                'status' => $status,
                'visibility' => !empty($product['is_public']) ? 'public' : 'internal',
                'sku' => $product['sku_base'],
                'name' => $product['name'],
                'slug' => $product['slug'],
                'is_public' => !empty($product['is_public']) ? 1 : 0,
                'is_ecommerce' => !empty($product['is_ecommerce_enabled']) ? 1 : 0,
                'is_pos' => !empty($product['is_pos_enabled']) ? 1 : 0,
                'is_catalogue' => !empty($product['is_catalogue_enabled']) ? 1 : 0,
                'actor' => $actorId > 0 ? $actorId : null,
            ]
        );
        return $id;
    }

    /** @param array<string,mixed> $bundle */
    private function applyBundleImportBundleRow(int $siteId, int $productId, array $bundle, int $actorId): void
    {
        $current = $this->db->one('SELECT id FROM business_product_bundles WHERE site_id = ? AND bundle_product_id = ? AND bundle_variant_id IS NULL LIMIT 1', [$siteId, $productId]);
        if ($current === null) {
            $this->db->run(
                'INSERT INTO business_product_bundles(site_id, bundle_product_id, pricing_mode, stock_mode, is_active, created_by_iam_user_id, updated_by_iam_user_id)
                 VALUES(:site_id, :product_id, :pricing_mode, :stock_mode, :is_active, :actor, :actor)',
                ['site_id' => $siteId, 'product_id' => $productId, 'pricing_mode' => $bundle['pricing_mode'], 'stock_mode' => $bundle['stock_mode'], 'is_active' => !empty($bundle['is_active']) ? 1 : 0, 'actor' => $actorId > 0 ? $actorId : null]
            );
            return;
        }
        $this->db->run(
            'UPDATE business_product_bundles SET pricing_mode = ?, stock_mode = ?, is_active = ?, archived_at = NULL, updated_by_iam_user_id = ?, updated_at = CURRENT_TIMESTAMP WHERE site_id = ? AND id = ?',
            [$bundle['pricing_mode'], $bundle['stock_mode'], !empty($bundle['is_active']) ? 1 : 0, $actorId > 0 ? $actorId : null, $siteId, (int) $current['id']]
        );
    }

    /** @param array<string,mixed> $plan */
    private function applyDiscountImportPlan(int $siteId, array $plan, int $actorId): void
    {
        $discount = $plan['discount'];
        $status = (string) $discount['status'];
        $archivedAt = $status === 'archived' ? 'CURRENT_TIMESTAMP' : 'NULL';
        if ($plan['action'] === 'create_discount') {
            $this->db->run(
                'INSERT INTO business_catalog_discounts(site_id, name, status, discount_type, discount_value, currency, scope_type, scope_id, channel, starts_at, ends_at, priority, created_by_iam_user_id, updated_by_iam_user_id, archived_at)
                 VALUES(:site_id, :name, :status, :discount_type, :discount_value, :currency, :scope_type, :scope_id, :channel, :starts_at, :ends_at, :priority, :actor, :actor, ' . $archivedAt . ')',
                ['site_id' => $siteId, 'actor' => $actorId > 0 ? $actorId : null] + $discount
            );
            return;
        }
        $this->db->run(
            'UPDATE business_catalog_discounts
             SET name = :name, status = :status, discount_type = :discount_type, discount_value = :discount_value,
                 currency = :currency, scope_type = :scope_type, scope_id = :scope_id, channel = :channel,
                 starts_at = :starts_at, ends_at = :ends_at, priority = :priority,
                 archived_at = ' . $archivedAt . ', updated_by_iam_user_id = :actor, updated_at = CURRENT_TIMESTAMP
             WHERE site_id = :site_id AND id = :id',
            ['site_id' => $siteId, 'id' => (int) $plan['id'], 'actor' => $actorId > 0 ? $actorId : null] + $discount
        );
    }

    /** @param array<int,string> $where @param list<mixed> $params */
    private function appendBundleChannelFilter(array &$where, array &$params, string $channel): void
    {
        if ($channel === 'public') {
            $where[] = 'p.is_public = 1';
        } elseif ($channel === 'ecommerce') {
            $where[] = 'p.is_ecommerce_enabled = 1';
        } elseif ($channel === 'pos') {
            $where[] = 'p.is_pos_enabled = 1';
        } elseif ($channel === 'catalogue') {
            $where[] = 'p.is_catalogue_enabled = 1';
        } elseif ($channel === 'all') {
            $where[] = '(p.is_public = 1 OR p.is_ecommerce_enabled = 1 OR p.is_pos_enabled = 1 OR p.is_catalogue_enabled = 1)';
        } elseif ($channel !== 'admin') {
            throw new InvalidArgumentException('business.offers_export_channel_invalid');
        }
    }

    /** @param array<string,mixed> $row @return list<string> */
    private function bundleChannels(array $row): array
    {
        return array_values(array_filter([
            !empty($row['is_public']) ? 'public' : null,
            !empty($row['is_ecommerce_enabled']) ? 'ecommerce' : null,
            !empty($row['is_pos_enabled']) ? 'pos' : null,
            !empty($row['is_catalogue_enabled']) ? 'catalogue' : null,
        ]));
    }

    private function csvBool(mixed $value): string
    {
        return !empty($value) ? '1' : '0';
    }

    private function csvBoolValue(mixed $value): bool
    {
        $text = strtolower(trim((string) $value));
        return in_array($text, ['1','true','yes','oui','on','public','all'], true);
    }

    private function assertDiscountScopeExists(int $siteId, string $scopeType, int $scopeId): void
    {
        $this->id($scopeId, 'scope_id');
        $sql = match ($scopeType) {
            'brand' => 'SELECT 1 FROM business_product_brands WHERE site_id = ? AND id = ? AND archived_at IS NULL LIMIT 1',
            'category' => 'SELECT 1 FROM business_product_categories WHERE site_id = ? AND id = ? AND archived_at IS NULL LIMIT 1',
            'product' => 'SELECT 1 FROM business_products WHERE site_id = ? AND id = ? AND archived_at IS NULL LIMIT 1',
            'variant' => 'SELECT 1 FROM business_product_variants v INNER JOIN business_products p ON p.id = v.product_id WHERE p.site_id = ? AND v.id = ? AND v.archived_at IS NULL AND p.archived_at IS NULL LIMIT 1',
            default => null,
        };
        if ($sql === null || $this->db->one($sql, [$this->siteId($siteId), $scopeId]) === null) {
            throw new InvalidArgumentException('business.offers_discount_scope_not_found');
        }
    }

    /** @return array<string,mixed> */
    private function attributeGroup(int $id): array
    {
        $row = $this->db->one('SELECT * FROM business_attribute_groups WHERE id = ? LIMIT 1', [$id]);
        if ($row === null) {
            throw new InvalidArgumentException('business.attribute_group_not_found');
        }
        return $this->cast($row);
    }

    /** @return array<string,mixed>|null */
    private function attributeGroupForSite(int $siteId, int $id): ?array
    {
        $row = $this->db->one('SELECT * FROM business_attribute_groups WHERE site_id = ? AND id = ? AND archived_at IS NULL LIMIT 1', [$this->siteId($siteId), $this->id($id, 'attribute_group_id')]);
        return $row === null ? null : $this->cast($row);
    }

    /** @return array<string,mixed> */
    private function attribute(int $id): array
    {
        $row = $this->db->one(
            'SELECT a.*, g.code AS group_code, g.name AS group_name
             FROM business_attributes a INNER JOIN business_attribute_groups g ON g.id = a.group_id
             WHERE a.id = ? LIMIT 1',
            [$id]
        );
        if ($row === null) {
            throw new InvalidArgumentException('business.attribute_not_found');
        }
        return $this->attributePayload($row);
    }

    /** @return array<string,mixed>|null */
    private function attributeForSite(int $siteId, int $id): ?array
    {
        $row = $this->db->one('SELECT * FROM business_attributes WHERE site_id = ? AND id = ? AND archived_at IS NULL LIMIT 1', [$this->siteId($siteId), $this->id($id, 'attribute_id')]);
        return $row === null ? null : $this->cast($row);
    }

    /** @return array<string,mixed> */
    private function option(int $id): array
    {
        $row = $this->db->one('SELECT * FROM business_attribute_options WHERE id = ? LIMIT 1', [$id]);
        if ($row === null) {
            throw new InvalidArgumentException('business.attribute_option_not_found');
        }
        return $this->cast($row);
    }

    /** @return array<string,mixed>|null */
    private function optionForSite(int $siteId, int $id): ?array
    {
        $row = $this->db->one(
            'SELECT o.*
             FROM business_attribute_options o
             INNER JOIN business_attributes a ON a.id = o.attribute_id
             WHERE a.site_id = ? AND o.id = ? AND o.archived_at IS NULL AND a.archived_at IS NULL
             LIMIT 1',
            [$this->siteId($siteId), $this->id($id, 'attribute_option_id')]
        );
        return $row === null ? null : $this->cast($row);
    }

    private function requireAttributeForSite(int $siteId, int $attributeId): void
    {
        if ($this->attributeForSite($siteId, $attributeId) === null) {
            throw new InvalidArgumentException('business.attribute_not_found');
        }
    }

    private function assertGroupBelongsToSite(int $siteId, ?int $groupId): void
    {
        if ($groupId !== null && $this->attributeGroupForSite($siteId, $groupId) === null) {
            throw new InvalidArgumentException('business.attribute_group_not_found');
        }
    }

    private function requiredGroupId(mixed $groupId): int
    {
        $id = (int) $groupId;
        if ($id < 1) {
            throw new InvalidArgumentException('business.attribute_group_required');
        }
        return $id;
    }

    /** @return array<string,mixed> */
    private function requireProduct(int $siteId, int $productId): array
    {
        $row = $this->db->one('SELECT * FROM business_products WHERE site_id = ? AND id = ? AND archived_at IS NULL LIMIT 1', [$this->siteId($siteId), $this->id($productId, 'product_id')]);
        if ($row === null) {
            throw new InvalidArgumentException('business.product_not_found');
        }
        return $this->cast($row);
    }

    /** @return array<string,mixed> */
    private function requireVariant(int $siteId, int $variantId): array
    {
        $row = $this->db->one(
            'SELECT v.*, p.site_id
             FROM business_product_variants v
             INNER JOIN business_products p ON p.id = v.product_id
             WHERE p.site_id = ? AND v.id = ? AND p.archived_at IS NULL AND v.archived_at IS NULL
             LIMIT 1',
            [$this->siteId($siteId), $this->id($variantId, 'variant_id')]
        );
        if ($row === null) {
            throw new InvalidArgumentException('business.variant_not_found');
        }
        return $this->cast($row);
    }

    /** @return list<array<string,mixed>> */
    private function attributeValues(string $scope, int $ownerId): array
    {
        $table = $scope === 'variant' ? 'business_variant_attribute_values' : 'business_product_attribute_values';
        $owner = $scope === 'variant' ? 'variant_id' : 'product_id';
        return array_map(fn(array $row): array => $this->valuePayload($row), $this->db->all(
            'SELECT v.*, a.code AS attribute_code, a.name AS attribute_name, a.data_type
             FROM ' . $table . ' v
             INNER JOIN business_attributes a ON a.id = v.attribute_id
             WHERE v.' . $owner . ' = ?
             ORDER BY a.sort_order ASC, a.name ASC, v.language ASC',
            [$this->id($ownerId, $owner)]
        ));
    }

    private function attributeHasValues(int $attributeId): bool
    {
        $id = $this->id($attributeId, 'attribute_id');
        $product = $this->db->one('SELECT 1 FROM business_product_attribute_values WHERE attribute_id = ? LIMIT 1', [$id]);
        if ($product !== null) {
            return true;
        }
        return $this->db->one('SELECT 1 FROM business_variant_attribute_values WHERE attribute_id = ? LIMIT 1', [$id]) !== null;
    }

    private function assertPublicAttributeFlags(mixed $public,mixed $filterable,mixed $searchable): void
    {
        if (!$this->bool($public) && ($this->bool($filterable) || $this->bool($searchable))) {
            throw new InvalidArgumentException('business.attribute_public_required');
        }
    }

    /** @param array<string,mixed> $option */
    private function optionHasValues(array $option): bool
    {
        $attributeId=$this->id((int)($option['attribute_id']??0),'attribute_id');
        $value=(string)($option['value']??''); $code=(string)($option['code']??'');
        foreach (['business_product_attribute_values','business_variant_attribute_values'] as $table) {
            $row=$this->db->one(
                'SELECT 1 FROM '.$table.' v WHERE v.attribute_id=? AND
                 (v.value_text IN (?,?) OR EXISTS (SELECT 1 FROM json_each(v.value_json) j WHERE CAST(j.value AS TEXT) IN (?,?))) LIMIT 1',
                [$attributeId,$value,$code,$value,$code]
            );
            if ($row!==null) return true;
        }
        return false;
    }

    /** @param list<array<string,mixed>> $values */
    private function replaceValues(string $scope, int $ownerId, array $values, int $actorId, int $siteId, int $productId): void
    {
        $table = $scope === 'variant' ? 'business_variant_attribute_values' : 'business_product_attribute_values';
        $owner = $scope === 'variant' ? 'variant_id' : 'product_id';
        $this->assertValuesAllowedForProduct($siteId, $productId, $values);
        $this->db->transaction(function () use ($table, $owner, $ownerId, $values, $actorId): void {
            $this->db->run('DELETE FROM ' . $table . ' WHERE ' . $owner . ' = ?', [$ownerId]);
            foreach ($values as $value) {
                $attributeId = $this->id((int) ($value['attribute_id'] ?? 0), 'attribute_id');
                $language = $this->language((string) ($value['language'] ?? 'und'));
                [$text, $number, $json] = $this->valueColumns($value);
                $this->db->run(
                    'INSERT INTO ' . $table . '(' . $owner . ', attribute_id, language, value_text, value_number, value_json, updated_by_iam_user_id)
                     VALUES(:owner_id, :attribute_id, :language, :value_text, :value_number, :value_json, :actor)',
                    [
                        'owner_id' => $ownerId,
                        'attribute_id' => $attributeId,
                        'language' => $language,
                        'value_text' => $text,
                        'value_number' => $number,
                        'value_json' => $json,
                        'actor' => $actorId > 0 ? $actorId : null,
                    ]
                );
            }
        });
    }

    /** @param list<array<string,mixed>> $values */
    private function assertValuesAllowedForProduct(int $siteId, int $productId, array $values): void
    {
        $allowedAttributeIds = $this->allowedProductAttributeIds($siteId, $productId);
        foreach ($values as $value) {
            $attributeId = $this->id((int) ($value['attribute_id'] ?? 0), 'attribute_id');
            if (!in_array($attributeId, $allowedAttributeIds, true)) {
                throw new InvalidArgumentException('business.attribute_not_in_product_group');
            }
            $attribute = $this->attributeForSite($siteId, $attributeId);
            if ($attribute === null) {
                throw new InvalidArgumentException('business.attribute_not_found');
            }
            $this->assertAttributeValueMatchesOptions($attribute, $value);
        }
    }

    /** @return list<int> */
    private function allowedProductAttributeIds(int $siteId, int $productId): array
    {
        $this->ensureProductAttributeGroupLinkTable();
        return array_map(
            static fn(array $row): int => (int) $row['id'],
            $this->db->all(
                'SELECT DISTINCT a.id
                 FROM business_product_attribute_group_links l
                 INNER JOIN business_attribute_groups g ON g.id = l.group_id
                 INNER JOIN business_attributes a ON a.group_id = g.id
                 WHERE l.product_id = ? AND g.site_id = ? AND g.archived_at IS NULL AND a.archived_at IS NULL
                 ORDER BY a.sort_order ASC, a.name ASC',
                [$this->id($productId, 'product_id'), $this->siteId($siteId)]
            )
        );
    }

    /** @param array<string,mixed> $attribute @param array<string,mixed> $value */
    private function assertAttributeValueMatchesOptions(array $attribute, array $value): void
    {
        $type = (string) ($attribute['data_type'] ?? 'text');
        if (!in_array($type, ['select', 'multi_select', 'color'], true)) {
            return;
        }
        $options = $this->attributeOptionValues((int) $attribute['id']);
        $selected = $this->submittedAttributeValueStrings($value);
        if ($selected === []) {
            return;
        }
        if ($options === []) {
            throw new InvalidArgumentException('business.attribute_options_required');
        }
        foreach ($selected as $item) {
            if (!in_array($item, $options, true)) {
                throw new InvalidArgumentException('business.attribute_option_invalid');
            }
        }
    }

    /** @return list<string> */
    private function attributeOptionValues(int $attributeId): array
    {
        return array_values(array_unique(array_map(
            static fn(array $row): string => (string) $row['value'],
            $this->db->all('SELECT value FROM business_attribute_options WHERE attribute_id = ? AND archived_at IS NULL ORDER BY sort_order ASC, label ASC', [$this->id($attributeId, 'attribute_id')])
        )));
    }

    /** @param array<string,mixed> $value @return list<string> */
    private function submittedAttributeValueStrings(array $value): array
    {
        $raw = $value['value_json'] ?? $value['json'] ?? null;
        if (is_array($raw)) {
            return array_values(array_filter(array_map(static fn(mixed $item): string => trim((string) $item), $raw), static fn(string $item): bool => $item !== ''));
        }
        $text = $value['value_text'] ?? $value['text'] ?? $value['value'] ?? null;
        if ($text === null || trim((string) $text) === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', (string) $text)), static fn(string $item): bool => $item !== ''));
    }

    private function ensureProductAttributeGroupLinkTable(): void
    {
        $this->db->run(
            'CREATE TABLE IF NOT EXISTS business_product_attribute_group_links (
                product_id INTEGER NOT NULL,
                group_id INTEGER NOT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(product_id, group_id),
                FOREIGN KEY(product_id) REFERENCES business_products(id) ON DELETE CASCADE ON UPDATE CASCADE,
                FOREIGN KEY(group_id) REFERENCES business_attribute_groups(id) ON DELETE CASCADE ON UPDATE CASCADE,
                CHECK(sort_order >= 0)
            )'
        );
        $this->db->run('CREATE INDEX IF NOT EXISTS idx_business_product_attribute_group_links_group ON business_product_attribute_group_links(group_id, sort_order)');
    }

    /** @param array<string,mixed> $payload @return list<array<string,mixed>> */
    private function valuesPayload(array $payload): array
    {
        $values = $payload['values'] ?? $payload['attributes'] ?? [];
        if (!is_array($values)) {
            throw new InvalidArgumentException('business.attribute_values_invalid');
        }
        return array_values(array_filter($values, 'is_array'));
    }

    /** @param array<string,mixed> $value @return array{0:?string,1:?float,2:?string} */
    private function valueColumns(array $value): array
    {
        if (array_key_exists('value_number', $value) || array_key_exists('number', $value)) {
            return [null, (float) ($value['value_number'] ?? $value['number']), null];
        }
        if (array_key_exists('value_json', $value) || array_key_exists('json', $value)) {
            $jsonValue = $value['value_json'] ?? $value['json'];
            return [null, null, $this->jsonAny($jsonValue)];
        }
        $text = $value['value_text'] ?? $value['text'] ?? $value['value'] ?? null;
        return [$this->text($text, 'attribute_value', 5000), null, null];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function attributePayload(array $row): array
    {
        $payload = $this->cast($row);
        $payload['validation'] = $this->jsonDecode((string) ($row['validation_json'] ?? '{}'));
        $payload['options'] = array_map(fn(array $option): array => $this->cast($option), $this->db->all(
            'SELECT * FROM business_attribute_options WHERE attribute_id = ? AND archived_at IS NULL ORDER BY sort_order ASC, label ASC',
            [(int) $row['id']]
        ));
        return $payload;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function valuePayload(array $row): array
    {
        $payload = $this->cast($row);
        $payload['value'] = $row['value_json'] !== null ? $this->jsonDecode((string) $row['value_json']) : ($row['value_number'] ?? $row['value_text']);
        return $payload;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function castScore(array $row): array
    {
        $payload = $this->cast($row);
        $payload['missing'] = $this->jsonDecode((string) ($row['missing_json'] ?? '[]'));
        return $payload;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function cast(array $row): array
    {
        foreach (['id','site_id','group_id','attribute_id','product_id','variant_id','media_id','sort_order','score','weight','usage_count','created_by_iam_user_id','updated_by_iam_user_id'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (int) $row[$key];
            }
        }
        foreach (['is_required','is_filterable','is_searchable','is_public','is_active','is_sellable','is_default'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (bool) $row[$key];
            }
        }
        if (array_key_exists('rate', $row) && $row['rate'] !== null) {
            $row['rate'] = (float) $row['rate'];
        }
        return $row;
    }

    private function siteId(int $siteId): int
    {
        if ($siteId < 1) {
            throw new InvalidArgumentException('business.site_id_invalid');
        }
        return $siteId;
    }

    private function id(int $id, string $field): int
    {
        if ($id < 1) {
            throw new InvalidArgumentException('business.' . $field . '_invalid');
        }
        return $id;
    }

    /** @param mixed $values @return list<int> */
    private function ids(mixed $values): array
    {
        if (!is_array($values)) {
            throw new InvalidArgumentException('business.ids_invalid');
        }
        $ids = array_values(array_unique(array_map('intval', $values)));
        $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            throw new InvalidArgumentException('business.ids_required');
        }
        return $ids;
    }

    private function key(mixed $value, string $field): string
    {
        $key = strtolower(trim((string) $value));
        $key = preg_replace('/[^a-z0-9_-]+/', '_', $key) ?? '';
        $key = trim($key, '_-');
        if ($key === '') {
            throw new InvalidArgumentException('business.' . $field . '_invalid');
        }
        return substr($key, 0, 80);
    }

    private function text(mixed $value, string $field, int $max): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            throw new InvalidArgumentException('business.' . $field . '_required');
        }
        if (strlen($text) > $max) {
            throw new InvalidArgumentException('business.' . $field . '_too_long');
        }
        return $text;
    }

    private function nullableText(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (strlen($text) > $max) {
            throw new InvalidArgumentException('business.text_too_long');
        }
        return $text;
    }

    /** @param list<string> $allowed */
    private function choice(string $value, array $allowed, string $field): string
    {
        $value = trim($value);
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException('business.' . $field . '_invalid');
        }
        return $value;
    }

    private function bool(mixed $value): int
    {
        return (int) (bool) $value;
    }

    private function color(mixed $value): ?string
    {
        $color = $this->nullableText($value, 7);
        if ($color !== null && preg_match('/^#[0-9A-Fa-f]{6}$/', $color) !== 1) {
            throw new InvalidArgumentException('business.color_invalid');
        }
        return $color;
    }

    private function language(string $value): string
    {
        $value = strtolower(trim($value) ?: 'und');
        if (preg_match('/^[a-z]{2,12}$/', $value) !== 1) {
            throw new InvalidArgumentException('business.language_invalid');
        }
        return $value;
    }

    private function jsonObject(mixed $value): string
    {
        $value = is_string($value) ? $this->jsonDecode($value) : $value;
        if ($value === []) {
            return '{}';
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('business.json_object_invalid');
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function jsonAny(mixed $value): string
    {
        $value = is_string($value) ? $this->jsonDecode($value) : $value;
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'null';
    }

    /** @return array<mixed> */
    private function jsonDecode(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeProductUpdate(int $siteId, string $field, mixed $value): mixed
    {
        return match ($field) {
            'status' => $this->choice((string) $value, ['draft','active','archived'], 'product_status'),
            'visibility' => $this->choice((string) $value, ['internal','public','hidden'], 'product_visibility'),
            'is_public', 'is_ecommerce_enabled', 'is_pos_enabled', 'is_catalogue_enabled' => $this->bool($value),
            'brand_id' => $this->optionalSiteEntityId($siteId, 'business_product_brands', $value, 'brand_id'),
            'category_id' => $this->optionalSiteEntityId($siteId, 'business_product_categories', $value, 'category_id'),
            'tax_class_id' => $this->optionalSiteEntityId($siteId, 'business_tax_classes', $value, 'tax_class_id'),
            'archive' => $this->bool($value),
            default => $value,
        };
    }

    /** @param array<string,mixed> $changes @return array<string,mixed> */
    private function normalizeBulkProductChanges(int $siteId, array $changes): array
    {
        $allowed = array_intersect_key($changes, array_flip(['status','visibility','brand_id','category_id','tax_class_id','is_public','is_ecommerce_enabled','is_pos_enabled','is_catalogue_enabled','archive']));
        $normalized = [];
        foreach ($allowed as $field => $value) {
            if ($field === 'archive' && !$this->bool($value)) {
                continue;
            }
            $normalized[$field] = $this->normalizeProductUpdate($siteId, $field, $value);
        }
        return $normalized;
    }

    /** @param array<string,mixed> $changes @return array<string,mixed> */
    private function normalizeBulkDiscountChanges(array $changes): array
    {
        $allowed = array_intersect_key($changes, array_flip(['status','channel','archive']));
        $normalized = [];
        foreach ($allowed as $field => $value) {
            if ($field === 'archive') {
                if ($this->bool($value)) {
                    $normalized[$field] = true;
                }
                continue;
            }
            $normalized[$field] = match ($field) {
                'status' => $this->choice((string) $value, ['draft','active','archived'], 'discount_status'),
                'channel' => $this->choice((string) $value, ['all','ecommerce','pos','catalogue','admin'], 'discount_channel'),
                default => $value,
            };
        }
        return $normalized;
    }

    /** @param array<string,mixed> $changes @return array<string,mixed> */
    private function normalizeBulkBundleOfferChanges(int $siteId, array $changes): array
    {
        $bundleChanges = $changes;
        $channel = trim((string) ($bundleChanges['channel'] ?? ''));
        unset($bundleChanges['channel']);
        if ($channel !== '') {
            if ($channel === 'all') {
                $bundleChanges['is_public'] = true;
                $bundleChanges['is_ecommerce_enabled'] = true;
                $bundleChanges['is_pos_enabled'] = true;
                $bundleChanges['is_catalogue_enabled'] = true;
            } elseif ($channel === 'public') {
                $bundleChanges['is_public'] = true;
            } elseif ($channel === 'ecommerce') {
                $bundleChanges['is_ecommerce_enabled'] = true;
            } elseif ($channel === 'pos') {
                $bundleChanges['is_pos_enabled'] = true;
            } elseif ($channel === 'catalogue') {
                $bundleChanges['is_catalogue_enabled'] = true;
            } elseif ($channel !== 'admin') {
                throw new InvalidArgumentException('business.catalog.offer_channel_invalid');
            }
        }
        return $this->normalizeBulkProductChanges($siteId, $bundleChanges);
    }

    /** @return array<string,mixed> */
    private function requireBundleProduct(int $siteId, int $productId): array
    {
        $row = $this->db->one(
            'SELECT * FROM business_products WHERE site_id = ? AND id = ? AND type = \'bundle\' AND archived_at IS NULL LIMIT 1',
            [$this->siteId($siteId), $this->id($productId, 'product_id')]
        );
        if ($row === null) {
            throw new InvalidArgumentException('business.bundle_product_not_found');
        }
        return $this->cast($row);
    }

    /** @return array<string,mixed> */
    private function requireDiscount(int $siteId, int $discountId): array
    {
        $row = $this->db->one(
            'SELECT * FROM business_catalog_discounts WHERE site_id = ? AND id = ? AND archived_at IS NULL LIMIT 1',
            [$this->siteId($siteId), $this->id($discountId, 'discount_id')]
        );
        if ($row === null) {
            throw new InvalidArgumentException('business.discount_not_found');
        }
        return $this->cast($row);
    }

    /** @param array<string,mixed> $changes */
    private function applyBulkBundleChanges(int $siteId, int $productId, array $changes, int $actorId): void
    {
        foreach ($changes as $field => $value) {
            if ($field === 'archive') {
                $this->db->run(
                    'UPDATE business_products SET status = ?, archived_at = CURRENT_TIMESTAMP, updated_by_iam_user_id = ?, updated_at = CURRENT_TIMESTAMP WHERE site_id = ? AND id = ? AND type = \'bundle\'',
                    ['archived', $actorId > 0 ? $actorId : null, $siteId, $productId]
                );
                continue;
            }
            $this->db->run(
                'UPDATE business_products SET ' . $field . ' = ?, updated_by_iam_user_id = ?, updated_at = CURRENT_TIMESTAMP WHERE site_id = ? AND id = ? AND type = \'bundle\'',
                [$value, $actorId > 0 ? $actorId : null, $siteId, $productId]
            );
        }
    }

    /** @param array<string,mixed> $changes */
    private function applyBulkDiscountChanges(int $siteId, int $discountId, array $changes, int $actorId): void
    {
        foreach ($changes as $field => $value) {
            if ($field === 'archive') {
                $this->db->run(
                    'UPDATE business_catalog_discounts SET status = \'archived\', archived_at = CURRENT_TIMESTAMP, updated_by_iam_user_id = ?, updated_at = CURRENT_TIMESTAMP WHERE site_id = ? AND id = ?',
                    [$actorId > 0 ? $actorId : null, $siteId, $discountId]
                );
                continue;
            }
            $this->db->run(
                'UPDATE business_catalog_discounts SET ' . $field . ' = ?, updated_by_iam_user_id = ?, updated_at = CURRENT_TIMESTAMP WHERE site_id = ? AND id = ?',
                [$value, $actorId > 0 ? $actorId : null, $siteId, $discountId]
            );
        }
    }

    /** @param array<string,mixed> $payload @param array<string,mixed>|null $current @return array<string,mixed> */
    private function taxClassPayload(array $payload, ?array $current): array
    {
        $name = array_key_exists('name', $payload)
            ? $this->text($payload['name'], 'tax_class_name', 120)
            : $this->text($current['name'] ?? null, 'tax_class_name', 120);
        $code = array_key_exists('code', $payload)
            ? $this->key($payload['code'] ?: $name, 'tax_class_code')
            : $this->key($current['code'] ?? $name, 'tax_class_code');
        $rate = array_key_exists('rate', $payload) ? (float) $payload['rate'] : (float) ($current['rate'] ?? 0);
        if ($rate < 0) {
            throw new InvalidArgumentException('business.tax_class_rate_invalid');
        }
        $country = strtoupper(trim((string) (array_key_exists('country', $payload) ? $payload['country'] : ($current['country'] ?? 'CH'))));
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw new InvalidArgumentException('business.tax_class_country_invalid');
        }
        return [
            'code' => $code,
            'name' => $name,
            'rate' => round($rate, 4),
            'country' => $country,
            'is_default' => array_key_exists('is_default', $payload) ? $this->bool($payload['is_default']) : (int) (bool) ($current['is_default'] ?? false),
        ];
    }

    /** @return array<string,mixed> */
    private function requireTaxClass(int $siteId, int $taxClassId): array
    {
        $row = $this->db->one(
            'SELECT t.id, t.site_id, t.code, t.name, t.rate, t.country, t.is_default,
                    (SELECT COUNT(*) FROM business_products p WHERE p.site_id = t.site_id AND p.tax_class_id = t.id AND p.archived_at IS NULL) AS usage_count
             FROM business_tax_classes t
             WHERE t.site_id = ? AND t.id = ? AND t.archived_at IS NULL
             LIMIT 1',
            [$this->siteId($siteId), $this->id($taxClassId, 'tax_class_id')]
        );
        if ($row === null) {
            throw new InvalidArgumentException('business.tax_class_not_found');
        }
        return $this->cast($row);
    }

    private function optionalSiteEntityId(int $siteId, string $table, mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = $this->id((int) $value, $field);
        $row = $this->db->one('SELECT id FROM ' . $table . ' WHERE site_id = ? AND id = ? AND archived_at IS NULL LIMIT 1', [$this->siteId($siteId), $id]);
        if ($row === null) {
            throw new InvalidArgumentException('business.' . $field . '_not_found');
        }
        return $id;
    }
}
