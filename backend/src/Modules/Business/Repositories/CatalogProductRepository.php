<?php

declare(strict_types=1);

namespace App\Modules\Business\Repositories;

final class CatalogProductRepository extends BusinessRepositoryBase
{
    /** @param array<string,mixed> $filters @return array{items:list<array<string,mixed>>,limit:int,offset:int,total:int} */
    public function list(int $siteId, array $filters = [], int $limit = 50, int $offset = 0, bool $includeArchived = false): array
    {
        $where = ['site_id = :site_id'];
        $params = ['site_id' => $this->requireSiteId($siteId)];
        if (!$includeArchived) {
            $where[] = 'archived_at IS NULL';
        }
        foreach (['status', 'type', 'brand_id', 'category_id', 'is_public', 'is_ecommerce_enabled', 'is_pos_enabled', 'is_catalogue_enabled'] as $key) {
            if (array_key_exists($key, $filters) && $filters[$key] !== '' && $filters[$key] !== null) {
                $where[] = $key . ' = :' . $key;
                $params[$key] = in_array($key, ['brand_id', 'category_id'], true) ? (int) $filters[$key] : $filters[$key];
            }
        }
        $view = (string) ($filters['view'] ?? '');
        $imageFilter = (string) ($filters['image'] ?? '');
        $priceFilter = (string) ($filters['price'] ?? '');
        $purchasePriceFilter = (string) ($filters['purchase_price'] ?? '');
        $taxFilter = (string) ($filters['tax'] ?? '');
        $completenessFilter = (string) ($filters['completeness'] ?? '');
        $sellableFilter = (string) ($filters['sellable'] ?? '');
        if ($view === 'to_complete') {
            $completenessFilter = 'incomplete';
        } elseif ($view === 'ready_pos') {
            $sellableFilter = 'pos';
        } elseif ($view === 'ready_ecommerce') {
            $sellableFilter = 'ecommerce';
        } elseif ($view === 'ready_catalogue') {
            $sellableFilter = 'catalogue';
        } elseif ($view === 'without_image') {
            $imageFilter = 'without';
        } elseif ($view === 'without_price') {
            $priceFilter = 'without';
        } elseif ($view === 'low_stock') {
            $filters['low_stock'] = 1;
        }
        if (($filters['channel'] ?? '') === 'public') {
            $where[] = 'is_public = 1';
        } elseif (($filters['channel'] ?? '') === 'ecommerce') {
            $where[] = 'is_ecommerce_enabled = 1';
        } elseif (($filters['channel'] ?? '') === 'pos') {
            $where[] = 'is_pos_enabled = 1';
        } elseif (($filters['channel'] ?? '') === 'catalogue') {
            $where[] = 'is_catalogue_enabled = 1';
        }
        if (!empty($filters['low_stock'])) {
            $where[] = 'EXISTS (SELECT 1 FROM business_product_variants v WHERE v.product_id = business_products.id AND v.archived_at IS NULL AND v.stock_quantity <= 5 AND COALESCE(v.track_stock, business_products.track_stock) = 1)';
        }
        if ($imageFilter === 'with') {
            $where[] = 'EXISTS (SELECT 1 FROM business_product_assets a WHERE a.product_id = business_products.id AND a.archived_at IS NULL AND a.role <> \'internal\')';
        } elseif ($imageFilter === 'without') {
            $where[] = 'NOT EXISTS (SELECT 1 FROM business_product_assets a WHERE a.product_id = business_products.id AND a.archived_at IS NULL AND a.role <> \'internal\')';
        }
        if ($priceFilter === 'with') {
            $where[] = 'EXISTS (SELECT 1 FROM business_product_base_prices p WHERE p.product_id = business_products.id AND p.price_kind = \'sale\' AND p.valid_from IS NULL AND p.amount IS NOT NULL)';
        } elseif ($priceFilter === 'without') {
            $where[] = 'NOT EXISTS (SELECT 1 FROM business_product_base_prices p WHERE p.product_id = business_products.id AND p.price_kind = \'sale\' AND p.valid_from IS NULL AND p.amount IS NOT NULL)';
        }
        if ($purchasePriceFilter === 'missing') {
            $where[] = 'NOT EXISTS (SELECT 1 FROM business_product_base_prices p WHERE p.product_id = business_products.id AND p.price_kind = \'purchase\' AND p.valid_from IS NULL AND p.amount IS NOT NULL)';
        }
        if ($taxFilter === 'missing') {
            $where[] = 'tax_class_id IS NULL';
        }
        if ($completenessFilter === 'complete') {
            $where[] = 'EXISTS (SELECT 1 FROM business_product_completeness_scores s WHERE s.product_id = business_products.id AND s.variant_id IS NULL AND s.channel = \'all\' AND s.score >= 100)';
        } elseif ($completenessFilter === 'incomplete') {
            $where[] = 'NOT EXISTS (SELECT 1 FROM business_product_completeness_scores s WHERE s.product_id = business_products.id AND s.variant_id IS NULL AND s.channel = \'all\' AND s.score >= 100)';
        }
        if (in_array($sellableFilter, ['pos', 'ecommerce', 'catalogue'], true)) {
            $where[] = match ($sellableFilter) {
                'pos' => 'is_pos_enabled = 1',
                'ecommerce' => 'is_ecommerce_enabled = 1',
                default => 'is_catalogue_enabled = 1',
            };
            $where[] = 'status = \'active\'';
            $where[] = 'EXISTS (SELECT 1 FROM business_product_variants v WHERE v.product_id = business_products.id AND v.status = \'active\' AND v.archived_at IS NULL)';
            $where[] = 'EXISTS (SELECT 1 FROM business_product_base_prices p WHERE p.product_id = business_products.id AND p.price_kind = \'sale\' AND p.valid_from IS NULL AND p.amount IS NOT NULL)';
            $where[] = 'EXISTS (SELECT 1 FROM business_product_completeness_scores s WHERE s.product_id = business_products.id AND s.variant_id IS NULL AND s.channel = :sellable_channel AND s.is_sellable = 1 AND s.score >= 100)';
            $params['sellable_channel'] = $sellableFilter;
        }
        if (trim((string) ($filters['q'] ?? '')) !== '') {
            $where[] = '(name LIKE :q OR slug LIKE :q OR sku_base LIKE :q
                OR EXISTS (SELECT 1 FROM business_product_brands b WHERE b.id = business_products.brand_id AND b.name LIKE :q)
                OR EXISTS (SELECT 1 FROM business_product_categories c WHERE c.id = business_products.category_id AND c.name LIKE :q)
                OR EXISTS (
                    SELECT 1
                    FROM business_product_attribute_values av
                    INNER JOIN business_attributes a ON a.id = av.attribute_id
                    WHERE av.product_id = business_products.id
                      AND (a.name LIKE :q OR a.code LIKE :q OR av.value_text LIKE :q OR CAST(av.value_number AS TEXT) LIKE :q)
                ))';
            $params['q'] = '%' . trim((string) $filters['q']) . '%';
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int) (($this->database()->one('SELECT COUNT(*) AS c FROM business_products WHERE ' . $sqlWhere, $params)['c'] ?? 0));
        $limit = $this->limit($limit, 10000);
        $offset = $this->offset($offset);
        $rows = $this->database()->all(
            'SELECT business_products.*,
                    (SELECT COUNT(*) FROM business_product_variants v WHERE v.product_id = business_products.id AND v.archived_at IS NULL) AS variant_count,
                    (SELECT COUNT(*) FROM business_product_variants v WHERE v.product_id = business_products.id AND v.status = \'active\' AND v.archived_at IS NULL) AS active_variant_count,
                    (SELECT MIN(p.amount) FROM business_product_base_prices p WHERE p.product_id = business_products.id AND p.price_kind = \'sale\' AND p.valid_from IS NULL) AS sale_price_min,
                    (SELECT MIN(p.amount) FROM business_product_base_prices p WHERE p.product_id = business_products.id AND p.price_kind = \'purchase\' AND p.valid_from IS NULL) AS purchase_price_min,
                    (SELECT COALESCE(SUM(v.stock_quantity), 0) FROM business_product_variants v WHERE v.product_id = business_products.id AND v.archived_at IS NULL) AS stock_quantity_total,
                    (SELECT COALESCE(SUM(v.stock_reserved), 0) FROM business_product_variants v WHERE v.product_id = business_products.id AND v.archived_at IS NULL) AS stock_reserved_total,
                    (SELECT COUNT(*) FROM business_product_variants v WHERE v.product_id = business_products.id AND v.archived_at IS NULL AND COALESCE(v.track_stock, business_products.track_stock) = 1) AS stock_tracked_variant_count,
                    (SELECT COUNT(*) FROM business_product_variants v WHERE v.product_id = business_products.id AND v.archived_at IS NULL AND v.stock_quantity <= 5 AND COALESCE(v.track_stock, business_products.track_stock) = 1) AS low_stock_variant_count,
                    (SELECT COUNT(*) FROM business_product_assets a WHERE a.product_id = business_products.id AND a.archived_at IS NULL AND a.role <> \'internal\') AS image_count,
                    (SELECT s.score FROM business_product_completeness_scores s WHERE s.product_id = business_products.id AND s.variant_id IS NULL AND s.channel = \'all\' LIMIT 1) AS completeness_score,
                    (SELECT s.is_sellable FROM business_product_completeness_scores s WHERE s.product_id = business_products.id AND s.variant_id IS NULL AND s.channel = \'all\' LIMIT 1) AS is_sellable_summary,
                    (SELECT s.missing_json FROM business_product_completeness_scores s WHERE s.product_id = business_products.id AND s.variant_id IS NULL AND s.channel = \'all\' LIMIT 1) AS missing_summary_json
             FROM business_products WHERE ' . $sqlWhere . ' ORDER BY updated_at DESC, id DESC LIMIT :limit OFFSET :offset',
            $params + ['limit' => $limit, 'offset' => $offset]
        );
        return ['items' => array_map(fn(array $row): array => $this->castProductRow($row), $rows), 'limit' => $limit, 'offset' => $offset, 'total' => $total];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function create(int $siteId, array $payload, ?int $actorId = null): array
    {
        $name = $this->text($payload['name'] ?? null, 'name', 180);
        $slug = $this->slug($payload['slug'] ?? $name);
        $channels = $payload['channels'] ?? [];
        $defaultCatalogueEnabled = in_array('catalogue', $channels, true) || !in_array('internal', $channels, true);
        $this->database()->run(
            'INSERT INTO business_products(site_id, brand_id, category_id, type, status, visibility, external_id, sku_base, name, slug, short_description, description, unit, tax_class_id, track_stock, allow_backorder, backorder_delivery_days, is_public, is_ecommerce_enabled, is_pos_enabled, is_catalogue_enabled, created_by_iam_user_id, updated_by_iam_user_id)
             VALUES(:site_id, :brand_id, :category_id, :type, :status, :visibility, :external_id, :sku_base, :name, :slug, :short_description, :description, :unit, :tax_class_id, :track_stock, :allow_backorder, :backorder_delivery_days, :is_public, :is_ecommerce_enabled, :is_pos_enabled, :is_catalogue_enabled, :actor, :actor)',
            [
                'site_id' => $this->requireSiteId($siteId),
                'brand_id' => $payload['brand_id'] ?? null,
                'category_id' => $payload['category_id'] ?? null,
                'type' => $this->choice($payload['type'] ?? 'physical', ['physical', 'service', 'gift_card', 'bundle'], 'type'),
                'status' => $this->choice($payload['status'] ?? 'draft', ['draft', 'active', 'archived'], 'status'),
                'visibility' => $payload['visibility'] ?? (in_array('public', $channels, true) ? 'public' : 'internal'),
                'external_id' => trim((string) ($payload['external_id'] ?? '')) ?: null,
                'sku_base' => $this->skuBase($payload['sku_base'] ?? null),
                'name' => $name,
                'slug' => $slug,
                'short_description' => $this->nullableText($payload['short_description'] ?? null, 'short_description', 500),
                'description' => $this->nullableText($payload['description'] ?? null, 'description', 10000),
                'unit' => $this->key($payload['unit'] ?? 'unit', 'unit', 32),
                'tax_class_id' => $payload['tax_class_id'] ?? null,
                'track_stock' => $this->boolInt($payload['track_stock'] ?? $payload['stock_enabled'] ?? false),
                'allow_backorder' => $this->boolInt($payload['allow_backorder'] ?? true),
                'backorder_delivery_days' => max(0, (int) ($payload['backorder_delivery_days'] ?? $payload['delivery_lead_time_days'] ?? 7)),
                'is_public' => $this->boolInt($payload['is_public'] ?? in_array('public', $channels, true)),
                'is_ecommerce_enabled' => $this->boolInt($payload['is_ecommerce_enabled'] ?? in_array('ecommerce', $channels, true)),
                'is_pos_enabled' => $this->boolInt($payload['is_pos_enabled'] ?? in_array('pos', $channels, true)),
                'is_catalogue_enabled' => $this->boolInt($payload['is_catalogue_enabled'] ?? $defaultCatalogueEnabled),
                'actor' => $actorId,
            ]
        );
        $productId = $this->database()->lastInsertId();
        if ($this->hasPriceAmount($payload, 'base_purchase_price')) {
            $this->setBasePrice($productId, 'purchase', $payload['base_purchase_price'], $payload['currency'] ?? 'CHF', false, $actorId);
        }
        if ($this->hasPriceAmount($payload, 'base_sale_price')) {
            $this->setBasePrice($productId, 'sale', $payload['base_sale_price'], $payload['currency'] ?? 'CHF', true, $actorId);
        }
        return $this->find($siteId, $productId) ?? [];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed>|null */
    public function update(int $siteId, int $id, array $payload, ?int $actorId = null): ?array
    {
        $current = $this->find($siteId, $id, true);
        if ($current === null) {
            return null;
        }
        $name = $this->text($payload['name'] ?? $current['name'], 'name', 180);
        $slug = $this->slug($payload['slug'] ?? $current['slug']);
        $hasChannels = array_key_exists('channels', $payload);
        $channels = $hasChannels ? (array) $payload['channels'] : [];
        $isPublic = array_key_exists('is_public', $payload) ? $payload['is_public'] : ($hasChannels ? in_array('public', $channels, true) : $current['is_public']);
        $isEcommerceEnabled = array_key_exists('is_ecommerce_enabled', $payload) ? $payload['is_ecommerce_enabled'] : ($hasChannels ? in_array('ecommerce', $channels, true) : $current['is_ecommerce_enabled']);
        $isPosEnabled = array_key_exists('is_pos_enabled', $payload) ? $payload['is_pos_enabled'] : ($hasChannels ? in_array('pos', $channels, true) : $current['is_pos_enabled']);
        $isCatalogueEnabled = array_key_exists('is_catalogue_enabled', $payload) ? $payload['is_catalogue_enabled'] : ($hasChannels ? in_array('catalogue', $channels, true) : ($current['is_catalogue_enabled'] ?? true));
        $this->database()->run(
            'UPDATE business_products SET
                brand_id = :brand_id,
                category_id = :category_id,
                type = :type,
                sku_base = :sku_base,
                name = :name,
                slug = :slug,
                status = :status,
                visibility = :visibility,
                short_description = :short_description,
                description = :description,
                unit = :unit,
                tax_class_id = :tax_class_id,
                track_stock = :track_stock,
                allow_backorder = :allow_backorder,
                backorder_delivery_days = :backorder_delivery_days,
                is_public = :is_public,
                is_ecommerce_enabled = :is_ecommerce_enabled,
                is_pos_enabled = :is_pos_enabled,
                is_catalogue_enabled = :is_catalogue_enabled,
                updated_by_iam_user_id = :actor, updated_at = CURRENT_TIMESTAMP
             WHERE site_id = :site_id AND id = :id',
            [
                'site_id' => $this->requireSiteId($siteId),
                'id' => $id,
                'brand_id' => array_key_exists('brand_id', $payload) ? $payload['brand_id'] : $current['brand_id'],
                'category_id' => array_key_exists('category_id', $payload) ? $payload['category_id'] : $current['category_id'],
                'type' => $this->choice($payload['type'] ?? $current['type'], ['physical', 'service', 'gift_card', 'bundle'], 'type'),
                'sku_base' => array_key_exists('sku_base', $payload) ? $this->skuBase($payload['sku_base']) : $current['sku_base'],
                'name' => $name,
                'slug' => $slug,
                'status' => $this->choice($payload['status'] ?? $current['status'], ['draft', 'active', 'archived'], 'status'),
                'visibility' => $payload['visibility'] ?? ($this->boolInt($isPublic) === 1 ? 'public' : 'internal'),
                'short_description' => $this->nullableText($payload['short_description'] ?? $current['short_description'] ?? null, 'short_description', 500),
                'description' => $this->nullableText($payload['description'] ?? $current['description'] ?? null, 'description', 10000),
                'unit' => $this->key($payload['unit'] ?? $current['unit'] ?? 'unit', 'unit', 32),
                'tax_class_id' => array_key_exists('tax_class_id', $payload) ? $payload['tax_class_id'] : ($current['tax_class_id'] ?? null),
                'track_stock' => $this->boolInt($payload['track_stock'] ?? $current['track_stock'] ?? false),
                'allow_backorder' => $this->boolInt($payload['allow_backorder'] ?? $current['allow_backorder'] ?? true),
                'backorder_delivery_days' => max(0, (int) ($payload['backorder_delivery_days'] ?? $payload['delivery_lead_time_days'] ?? $current['backorder_delivery_days'] ?? 7)),
                'is_public' => $this->boolInt($isPublic),
                'is_ecommerce_enabled' => $this->boolInt($isEcommerceEnabled),
                'is_pos_enabled' => $this->boolInt($isPosEnabled),
                'is_catalogue_enabled' => $this->boolInt($isCatalogueEnabled),
                'actor' => $actorId,
            ]
        );
        return $this->find($siteId, $id);
    }

    public function find(int $siteId, int $id, bool $includeArchived = false): ?array
    {
        $row = $this->database()->one(
            'SELECT business_products.*,
                    (SELECT COUNT(*) FROM business_product_variants v WHERE v.product_id = business_products.id AND v.archived_at IS NULL) AS variant_count,
                    (SELECT COUNT(*) FROM business_product_variants v WHERE v.product_id = business_products.id AND v.status = \'active\' AND v.archived_at IS NULL) AS active_variant_count,
                    (SELECT MIN(p.amount) FROM business_product_base_prices p WHERE p.product_id = business_products.id AND p.price_kind = \'sale\' AND p.valid_from IS NULL) AS sale_price_min,
                    (SELECT MIN(p.amount) FROM business_product_base_prices p WHERE p.product_id = business_products.id AND p.price_kind = \'purchase\' AND p.valid_from IS NULL) AS purchase_price_min,
                    (SELECT COALESCE(SUM(v.stock_quantity), 0) FROM business_product_variants v WHERE v.product_id = business_products.id AND v.archived_at IS NULL) AS stock_quantity_total,
                    (SELECT COALESCE(SUM(v.stock_reserved), 0) FROM business_product_variants v WHERE v.product_id = business_products.id AND v.archived_at IS NULL) AS stock_reserved_total,
                    (SELECT COUNT(*) FROM business_product_variants v WHERE v.product_id = business_products.id AND v.archived_at IS NULL AND COALESCE(v.track_stock, business_products.track_stock) = 1) AS stock_tracked_variant_count,
                    (SELECT COUNT(*) FROM business_product_variants v WHERE v.product_id = business_products.id AND v.archived_at IS NULL AND v.stock_quantity <= 5 AND COALESCE(v.track_stock, business_products.track_stock) = 1) AS low_stock_variant_count,
                    (SELECT COUNT(*) FROM business_product_assets a WHERE a.product_id = business_products.id AND a.archived_at IS NULL AND a.role <> \'internal\') AS image_count,
                    (SELECT s.score FROM business_product_completeness_scores s WHERE s.product_id = business_products.id AND s.variant_id IS NULL AND s.channel = \'all\' LIMIT 1) AS completeness_score,
                    (SELECT s.is_sellable FROM business_product_completeness_scores s WHERE s.product_id = business_products.id AND s.variant_id IS NULL AND s.channel = \'all\' LIMIT 1) AS is_sellable_summary,
                    (SELECT s.missing_json FROM business_product_completeness_scores s WHERE s.product_id = business_products.id AND s.variant_id IS NULL AND s.channel = \'all\' LIMIT 1) AS missing_summary_json
             FROM business_products WHERE site_id = ? AND id = ?' . ($includeArchived ? '' : ' AND archived_at IS NULL') . ' LIMIT 1',
            [$this->requireSiteId($siteId), $id]
        );
        return $row ? $this->castProductRow($row) : null;
    }

    /** @return list<array<string,mixed>> */
    public function prices(int $siteId, int $productId): array
    {
        if ($this->find($siteId, $productId, true) === null) {
            return [];
        }
        return array_map(fn(array $row): array => $this->castRow($row), $this->database()->all(
            'SELECT * FROM business_product_base_prices WHERE product_id = ? ORDER BY price_kind ASC, currency ASC, id ASC',
            [$productId]
        ));
    }

    /** @return list<array<string,mixed>> */
    public function linkedOptions(int $siteId, int $productId): array
    {
        if ($this->find($siteId, $productId, true) === null) {
            return [];
        }
        return array_map(fn(array $row): array => $this->castRow($row), $this->database()->all(
            'SELECT o.*, l.is_required, l.sort_order AS link_sort_order
             FROM business_product_option_links l
             INNER JOIN business_product_options o ON o.id = l.option_id
             WHERE l.product_id = ? AND o.archived_at IS NULL
             ORDER BY l.sort_order ASC, o.sort_order ASC, o.name ASC',
            [$productId]
        ));
    }

    /** @return list<array<string,mixed>> */
    public function linkedAttributeGroups(int $siteId, int $productId): array
    {
        if ($this->find($siteId, $productId, true) === null) {
            return [];
        }
        $this->ensureAttributeGroupLinkTable();
        return array_map(fn(array $row): array => $this->castRow($row), $this->database()->all(
            'SELECT g.*, l.sort_order AS link_sort_order
             FROM business_product_attribute_group_links l
             INNER JOIN business_attribute_groups g ON g.id = l.group_id
             WHERE l.product_id = ? AND g.site_id = ? AND g.archived_at IS NULL
             ORDER BY l.sort_order ASC, g.sort_order ASC, g.name ASC',
            [$productId, $this->requireSiteId($siteId)]
        ));
    }

    /** @param list<int> $groupIds */
    public function replaceAttributeGroupLinks(int $siteId, int $productId, array $groupIds): void
    {
        if ($this->find($siteId, $productId, true) === null) {
            throw new \InvalidArgumentException('business.catalog.product_not_found');
        }
        $this->ensureAttributeGroupLinkTable();
        $this->database()->run('DELETE FROM business_product_attribute_group_links WHERE product_id = ?', [$productId]);
        $sort = 0;
        foreach (array_values(array_unique(array_map('intval', $groupIds))) as $groupId) {
            if ($groupId < 1) {
                continue;
            }
            $row = $this->database()->one('SELECT id FROM business_attribute_groups WHERE site_id = ? AND id = ? AND archived_at IS NULL', [$this->requireSiteId($siteId), $groupId]);
            if ($row === null) {
                throw new \InvalidArgumentException('business.catalog.attribute_group_not_found');
            }
            $this->database()->run(
                'INSERT OR REPLACE INTO business_product_attribute_group_links(product_id, group_id, sort_order) VALUES(?, ?, ?)',
                [$productId, $groupId, $sort += 10]
            );
        }
        $this->database()->run(
            'DELETE FROM business_product_attribute_values
             WHERE product_id = ?
               AND attribute_id NOT IN (
                    SELECT a.id
                    FROM business_product_attribute_group_links l
                    INNER JOIN business_attributes a ON a.group_id = l.group_id
                    WHERE l.product_id = ?
               )',
            [$productId, $productId]
        );
        $this->database()->run(
            'DELETE FROM business_variant_attribute_values
             WHERE variant_id IN (SELECT id FROM business_product_variants WHERE product_id = ?)
               AND attribute_id NOT IN (
                    SELECT a.id
                    FROM business_product_attribute_group_links l
                    INNER JOIN business_attributes a ON a.group_id = l.group_id
                    WHERE l.product_id = ?
               )',
            [$productId, $productId]
        );
    }

    /** @param list<int> $optionIds */
    public function replaceOptionLinks(int $siteId, int $productId, array $optionIds): void
    {
        if ($this->find($siteId, $productId, true) === null) {
            throw new \InvalidArgumentException('business.catalog.product_not_found');
        }
        $this->database()->run('DELETE FROM business_product_option_links WHERE product_id = ?', [$productId]);
        $sort = 0;
        foreach (array_values(array_unique(array_map('intval', $optionIds))) as $optionId) {
            if ($optionId < 1) {
                continue;
            }
            $row = $this->database()->one('SELECT id FROM business_product_options WHERE site_id = ? AND id = ? AND archived_at IS NULL', [$this->requireSiteId($siteId), $optionId]);
            if ($row === null) {
                throw new \InvalidArgumentException('business.catalog.option_not_found');
            }
            $this->database()->run(
                'INSERT OR REPLACE INTO business_product_option_links(product_id, option_id, is_required, sort_order) VALUES(?, ?, 1, ?)',
                [$productId, $optionId, $sort += 10]
            );
        }
    }

    public function archive(int $siteId, int $id, ?int $actorId = null): bool
    {
        $this->database()->run(
            'UPDATE business_products SET status = \'archived\', archived_at = COALESCE(archived_at, CURRENT_TIMESTAMP), updated_by_iam_user_id = ?, updated_at = CURRENT_TIMESTAMP WHERE site_id = ? AND id = ?',
            [$actorId, $this->requireSiteId($siteId), $id]
        );
        return true;
    }

    public function restore(int $siteId, int $id, ?int $actorId = null): bool
    {
        $this->database()->run(
            'UPDATE business_products SET status = \'draft\', archived_at = NULL, updated_by_iam_user_id = ?, updated_at = CURRENT_TIMESTAMP WHERE site_id = ? AND id = ? AND archived_at IS NOT NULL',
            [$actorId, $this->requireSiteId($siteId), $id]
        );
        return true;
    }

    public function deletePermanently(int $siteId, int $id): bool
    {
        $product = $this->find($siteId, $id, true);
        if ($product === null) {
            return false;
        }
        if (empty($product['archived_at']) && ($product['status'] ?? '') !== 'archived') {
            throw new \InvalidArgumentException('business.catalog.product_must_be_archived');
        }
        $this->database()->run(
            'DELETE FROM business_stock_movements WHERE variant_id IN (SELECT id FROM business_product_variants WHERE product_id = ?)',
            [$id]
        );
        $this->database()->run('DELETE FROM business_products WHERE site_id = ? AND id = ?', [$this->requireSiteId($siteId), $id]);
        return true;
    }

    public function activeVariantCount(int $productId): int
    {
        $row = $this->database()->one('SELECT COUNT(*) AS c FROM business_product_variants WHERE product_id = ? AND status = \'active\' AND archived_at IS NULL', [$productId]);
        return (int) ($row['c'] ?? 0);
    }

    public function hasSaleBasePrice(int $productId): bool
    {
        return $this->database()->one('SELECT 1 FROM business_product_base_prices WHERE product_id = ? AND price_kind = \'sale\' LIMIT 1', [$productId]) !== null;
    }

    public function setBasePrice(int $productId, string $priceKind, mixed $amount, string $currency = 'CHF', bool $taxIncluded = true, ?int $actorId = null): void
    {
        if (!in_array($priceKind, ['purchase', 'sale'], true) || !is_numeric($amount) || (float) $amount < 0) {
            throw new \InvalidArgumentException('business.catalog.base_price_invalid');
        }
        $currency = strtoupper(trim($currency) ?: 'CHF');
        $this->database()->run('DELETE FROM business_product_base_prices WHERE product_id = ? AND price_kind = ? AND currency = ? AND valid_from IS NULL', [$productId, $priceKind, $currency]);
        $this->database()->run(
            'INSERT INTO business_product_base_prices(product_id, price_kind, currency, amount, tax_included, created_by_iam_user_id, updated_by_iam_user_id)
             VALUES(?, ?, ?, ?, ?, ?, ?)',
            [$productId, $priceKind, $currency, round((float) $amount, 2), $this->boolInt($taxIncluded), $actorId, $actorId]
        );
    }

    /** @param array<string,mixed> $payload */
    private function hasPriceAmount(array $payload, string $key): bool
    {
        return array_key_exists($key, $payload)
            && $payload[$key] !== null
            && trim((string) $payload[$key]) !== '';
    }

    private function slug(mixed $value): string
    {
        $slug = strtolower(trim((string) $value));
        $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-_');
        if ($slug === '') {
            throw new \InvalidArgumentException('business.catalog.slug_invalid');
        }
        return substr($slug, 0, 120);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function castProductRow(array $row): array
    {
        $row = $this->castRow($row);
        foreach (['variant_count', 'active_variant_count', 'image_count', 'completeness_score'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (int) $row[$key];
            }
        }
        if (array_key_exists('is_sellable_summary', $row) && $row['is_sellable_summary'] !== null) {
            $row['is_sellable_summary'] = (bool) $row['is_sellable_summary'];
        }
        if (array_key_exists('sale_price_min', $row) && $row['sale_price_min'] !== null) {
            $row['sale_price_min'] = (float) $row['sale_price_min'];
        }
        if (array_key_exists('purchase_price_min', $row) && $row['purchase_price_min'] !== null) {
            $row['purchase_price_min'] = (float) $row['purchase_price_min'];
        }
        return $row;
    }

    private function ensureAttributeGroupLinkTable(): void
    {
        $this->database()->run(
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
        $this->database()->run('CREATE INDEX IF NOT EXISTS idx_business_product_attribute_group_links_group ON business_product_attribute_group_links(group_id, sort_order)');
    }

    private function skuBase(mixed $value): ?string
    {
        $sku = strtoupper(trim((string) ($value ?? '')));
        if ($sku === '') {
            return null;
        }
        $sku = preg_replace('/[^A-Z0-9]+/', '', $sku) ?? '';
        if ($sku === '') {
            throw new \InvalidArgumentException('business.catalog.sku_base_invalid');
        }
        return substr($sku, 0, 80);
    }

    private function key(mixed $value, string $field, int $max): string
    {
        $key = strtolower(trim((string) $value));
        $key = preg_replace('/[^a-z0-9_-]+/', '_', $key) ?? '';
        $key = trim($key, '_-');
        if ($key === '') {
            throw new \InvalidArgumentException('business.catalog.' . $field . '_invalid');
        }
        return substr($key, 0, $max);
    }

    /** @param list<string> $allowed */
    private function choice(mixed $value, array $allowed, string $field): string
    {
        $choice = trim((string) $value);
        if (!in_array($choice, $allowed, true)) {
            throw new \InvalidArgumentException('business.catalog.' . $field . '_invalid');
        }
        return $choice;
    }
}
