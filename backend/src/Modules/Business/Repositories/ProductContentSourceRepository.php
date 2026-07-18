<?php

declare(strict_types=1);

namespace App\Modules\Business\Repositories;

use App\Modules\Business\Contracts\ProductContentSourcePort;

final class ProductContentSourceRepository extends BusinessRepositoryBase implements ProductContentSourcePort
{
    public function productSnapshot(int $siteId, int $productId, string $locale): ?array
    {
        $product = $this->database()->one(
            'SELECT p.id, p.site_id, p.type, p.status, p.visibility, p.sku_base, p.name, p.slug,
                    p.short_description, p.description, p.unit, p.is_public, p.is_ecommerce_enabled, p.updated_at,
                    b.id AS brand_id, b.name AS brand_name, b.slug AS brand_slug,
                    c.id AS category_id, c.name AS category_name, c.slug AS category_slug
             FROM business_products p
             LEFT JOIN business_product_brands b ON b.id = p.brand_id AND b.site_id = p.site_id AND b.is_public = 1 AND b.status = \'active\' AND b.archived_at IS NULL
             LEFT JOIN business_product_categories c ON c.id = p.category_id AND c.site_id = p.site_id AND c.is_public = 1 AND c.archived_at IS NULL
             WHERE p.id = ? AND p.site_id = ? AND p.archived_at IS NULL LIMIT 1',
            [$productId, $this->requireSiteId($siteId)]
        );
        if ($product === null) {
            return null;
        }
        $variants = $this->database()->all(
            'SELECT id, sku, name, status, stock_quantity, stock_reserved, track_stock, allow_backorder, updated_at
             FROM business_product_variants
             WHERE product_id = ? AND archived_at IS NULL ORDER BY sort_order, id',
            [$productId]
        );
        $assets = $this->database()->all(
            'SELECT media_id, variant_id, role, title, alt_text, caption, channel_scope, sort_order
             FROM business_product_assets
             WHERE product_id = ? AND archived_at IS NULL AND is_public = 1 AND role <> \'internal\'
               AND channel_scope IN (\'all\', \'public\', \'ecommerce\')
             ORDER BY CASE role WHEN \'main\' THEN 0 ELSE 1 END, sort_order, id',
            [$productId]
        );
        $groups = $this->database()->all(
            'SELECT g.id, g.code, g.name, g.description, l.sort_order
             FROM business_product_attribute_group_links l
             INNER JOIN business_attribute_groups g ON g.id = l.group_id
             WHERE l.product_id = ? AND g.site_id = ? AND g.archived_at IS NULL
             ORDER BY l.sort_order, g.sort_order, g.name, g.id',
            [$productId, $this->requireSiteId($siteId)]
        );
        $attributes = $this->database()->all(
            'SELECT a.id, a.group_id, g.code AS group_code, g.name AS group_name,
                    a.code, a.name, a.data_type, a.unit, a.is_filterable, a.is_searchable, a.is_public, a.sort_order,
                    v.language, v.value_text, v.value_number, v.value_json
             FROM (
                SELECT pv.product_id, pv.attribute_id, pv.language, pv.value_text, pv.value_number, pv.value_json
                FROM business_product_attribute_values pv
                UNION ALL
                SELECT pv.product_id, vv.attribute_id, vv.language, vv.value_text, vv.value_number, vv.value_json
                FROM business_variant_attribute_values vv
                INNER JOIN business_product_variants pv ON pv.id = vv.variant_id AND pv.archived_at IS NULL
             ) v
             INNER JOIN business_attributes a ON a.id = v.attribute_id
             INNER JOIN business_attribute_groups g ON g.id = a.group_id AND g.archived_at IS NULL
             INNER JOIN business_product_attribute_group_links l ON l.product_id = v.product_id AND l.group_id = a.group_id
             WHERE v.product_id = ? AND a.is_public = 1 AND a.archived_at IS NULL
               AND v.language IN (?, \'und\')
             ORDER BY l.sort_order, a.sort_order, CASE v.language WHEN ? THEN 0 ELSE 1 END, a.id, v.value_text, v.value_number',
            [$productId, $locale, $locale]
        );
        $options = $this->database()->all(
            'SELECT o.attribute_id, o.code, o.label, o.value, o.color_hex, o.sort_order
             FROM business_attribute_options o
             INNER JOIN business_attributes a ON a.id = o.attribute_id
             INNER JOIN business_product_attribute_group_links l ON l.product_id = ? AND l.group_id = a.group_id
             WHERE a.site_id = ? AND a.archived_at IS NULL AND o.archived_at IS NULL
             ORDER BY a.sort_order, o.sort_order, o.label, o.id',
            [$productId, $this->requireSiteId($siteId)]
        );
        $relationService = new \App\Modules\Business\Services\CatalogCommercialRelationService($this->database());
        return [
            'product' => $this->castRow($product),
            'variants' => array_map(fn(array $row): array => $this->castRow($row), $variants),
            'assets' => array_map(fn(array $row): array => $this->castRow($row), $assets),
            'attribute_groups' => array_map(fn(array $row): array => $this->castRow($row), $groups),
            'attributes' => array_map(fn(array $row): array => $this->castRow($row), $attributes),
            'attribute_options' => array_map(fn(array $row): array => $this->castRow($row), $options),
            'relations' => $relationService->relations($siteId,$productId),
            'relation_rules' => $relationService->rules($siteId,$productId),
        ];
    }
}
