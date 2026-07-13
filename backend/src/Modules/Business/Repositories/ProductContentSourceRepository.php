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
                    p.short_description, p.description, p.category_id, p.unit, p.is_public, p.is_ecommerce_enabled, p.updated_at,
                    b.name AS brand_name, b.slug AS brand_slug,
                    c.name AS category_name, c.slug AS category_slug
             FROM business_products p
             LEFT JOIN business_product_brands b ON b.id = p.brand_id AND b.site_id = p.site_id
             LEFT JOIN business_product_categories c ON c.id = p.category_id AND c.site_id = p.site_id
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
        $attributes = $this->database()->all(
            'SELECT a.code, a.name, a.data_type, a.unit, v.language, v.value_text, v.value_number, v.value_json
             FROM business_product_attribute_values v
             INNER JOIN business_attributes a ON a.id = v.attribute_id
             WHERE v.product_id = ? AND a.is_public = 1 AND a.archived_at IS NULL
               AND v.language IN (?, \'und\')
             ORDER BY a.sort_order, CASE v.language WHEN ? THEN 0 ELSE 1 END',
            [$productId, $locale, $locale]
        );
        return [
            'product' => $this->castRow($product),
            'variants' => array_map(fn(array $row): array => $this->castRow($row), $variants),
            'assets' => array_map(fn(array $row): array => $this->castRow($row), $assets),
            'attributes' => array_map(fn(array $row): array => $this->castRow($row), $attributes),
        ];
    }
}
