<?php

declare(strict_types=1);

namespace App\Modules\Business\Repositories;

final class PublicCatalogRepository extends BusinessRepositoryBase
{
    /** @return list<array<string,mixed>> */
    public function brands(int $siteId): array
    {
        return array_map(fn(array $row): array => $this->castRow($row), $this->database()->all(
            'SELECT id, name, slug, description, website_url, logo_media_id
             FROM business_product_brands
             WHERE site_id = ? AND status = "active" AND is_public = 1 AND archived_at IS NULL
             ORDER BY sort_order ASC, name ASC',
            [$this->requireSiteId($siteId)]
        ));
    }

    /** @return list<array<string,mixed>> */
    public function categories(int $siteId): array
    {
        return array_map(fn(array $row): array => $this->castRow($row), $this->database()->all(
            'SELECT id, parent_id, name, slug, description
             FROM business_product_categories
             WHERE site_id = ? AND is_public = 1 AND archived_at IS NULL
             ORDER BY sort_order ASC, name ASC',
            [$this->requireSiteId($siteId)]
        ));
    }

    /** @param array<string,mixed> $filters @return array{items:list<array<string,mixed>>,limit:int,offset:int,total:int,has_more:bool} */
    public function products(int $siteId, array $filters = [], int $limit = 24, int $offset = 0): array
    {
        [$where, $params] = $this->productWhere($siteId, $filters);
        $sqlWhere = implode(' AND ', $where);
        $total = (int) (($this->database()->one('SELECT COUNT(*) AS c FROM business_products p WHERE ' . $sqlWhere, $params)['c'] ?? 0));
        $limit = $this->limit($limit, 100);
        $offset = $this->offset($offset);
        $rows = $this->database()->all(
            'SELECT p.id, p.site_id, p.brand_id, p.category_id, p.type, p.name, p.slug, p.short_description, p.description, p.unit, p.track_stock, p.allow_backorder, p.updated_at
             FROM business_products p
             WHERE ' . $sqlWhere . '
             ORDER BY p.updated_at DESC, p.id DESC
             LIMIT :limit OFFSET :offset',
            $params + ['limit' => $limit, 'offset' => $offset]
        );
        return [
            'items' => array_map(fn(array $row): array => $this->castRow($row), $rows),
            'limit' => $limit,
            'offset' => $offset,
            'total' => $total,
            'has_more' => ($offset + $limit) < $total,
        ];
    }

    public function productBySlug(int $siteId, string $slug): ?array
    {
        [$where, $params] = $this->productWhere($siteId, ['slug' => $slug]);
        $row = $this->database()->one(
            'SELECT p.id, p.site_id, p.brand_id, p.category_id, p.type, p.name, p.slug, p.short_description, p.description, p.unit, p.track_stock, p.allow_backorder, p.updated_at
             FROM business_products p
             WHERE ' . implode(' AND ', $where) . '
             LIMIT 1',
            $params
        );
        return $row ? $this->castRow($row) : null;
    }

    public function productById(int $siteId, int $id): ?array
    {
        [$where, $params] = $this->productWhere($siteId, []);
        $where[] = 'p.id = :id';
        $params['id'] = $id;
        $row = $this->database()->one(
            'SELECT p.id, p.site_id, p.brand_id, p.category_id, p.type, p.name, p.slug, p.short_description, p.description, p.unit, p.track_stock, p.allow_backorder, p.updated_at
             FROM business_products p
             WHERE ' . implode(' AND ', $where) . '
             LIMIT 1',
            $params
        );
        return $row ? $this->castRow($row) : null;
    }

    public function variantById(int $siteId, int $variantId): ?array
    {
        $row = $this->database()->one(
            'SELECT v.id, v.product_id, v.sku, v.name, v.stock_quantity, v.stock_reserved, v.track_stock, v.allow_backorder, v.updated_at, p.site_id
             FROM business_product_variants v
             INNER JOIN business_products p ON p.id = v.product_id
             WHERE v.id = ? AND v.status = "active" AND v.archived_at IS NULL
               AND p.site_id = ? AND p.status = "active" AND p.is_public = 1 AND p.is_ecommerce_enabled = 1 AND p.archived_at IS NULL
             LIMIT 1',
            [$variantId, $this->requireSiteId($siteId)]
        );
        return $row ? $this->castRow($row) : null;
    }

    /** @return list<array<string,mixed>> */
    public function activeVariants(int $siteId, int $productId): array
    {
        return array_map(fn(array $row): array => $this->castRow($row), $this->database()->all(
            'SELECT v.id, v.product_id, v.sku, v.name, v.stock_quantity, v.stock_reserved, v.track_stock, v.allow_backorder, v.updated_at
             FROM business_product_variants v
             INNER JOIN business_products p ON p.id = v.product_id
             WHERE v.product_id = ? AND v.status = "active" AND v.archived_at IS NULL
               AND p.site_id = ? AND p.status = "active" AND p.is_public = 1 AND p.is_ecommerce_enabled = 1 AND p.archived_at IS NULL
             ORDER BY v.sort_order ASC, v.id ASC',
            [$productId, $this->requireSiteId($siteId)]
        ));
    }

    public function publicBrand(int $siteId, ?int $brandId): ?array
    {
        if (!$brandId) {
            return null;
        }
        $row = $this->database()->one(
            'SELECT id, name, slug, description, website_url, logo_media_id
             FROM business_product_brands
             WHERE site_id = ? AND id = ? AND status = "active" AND is_public = 1 AND archived_at IS NULL
             LIMIT 1',
            [$this->requireSiteId($siteId), $brandId]
        );
        return $row ? $this->castRow($row) : null;
    }

    public function publicCategory(int $siteId, ?int $categoryId): ?array
    {
        if (!$categoryId) {
            return null;
        }
        $row = $this->database()->one(
            'SELECT id, parent_id, name, slug, description
             FROM business_product_categories
             WHERE site_id = ? AND id = ? AND is_public = 1 AND archived_at IS NULL
             LIMIT 1',
            [$this->requireSiteId($siteId), $categoryId]
        );
        return $row ? $this->castRow($row) : null;
    }

    /** @return list<array<string,mixed>> */
    public function productOptions(int $productId): array
    {
        return array_map(fn(array $row): array => $this->castRow($row), $this->database()->all(
            'SELECT o.id, o.code, o.name, o.type, l.is_required, l.sort_order
             FROM business_product_option_links l
             INNER JOIN business_product_options o ON o.id = l.option_id
             WHERE l.product_id = ? AND o.archived_at IS NULL
             ORDER BY l.sort_order ASC, o.sort_order ASC, o.name ASC',
            [$productId]
        ));
    }

    /** @return list<array<string,mixed>> */
    public function variantOptions(int $variantId): array
    {
        return array_map(fn(array $row): array => $this->castRow($row), $this->database()->all(
            'SELECT o.code AS option_code, o.name AS option_name, o.type AS option_type, ov.code AS value_code, ov.label, ov.value, ov.color_hex
             FROM business_product_variant_option_values vv
             INNER JOIN business_product_options o ON o.id = vv.option_id
             INNER JOIN business_product_option_values ov ON ov.id = vv.option_value_id
             WHERE vv.variant_id = ? AND o.archived_at IS NULL AND ov.archived_at IS NULL
             ORDER BY o.sort_order ASC, ov.sort_order ASC',
            [$variantId]
        ));
    }

    /** @return list<array<string,mixed>> */
    public function productMedia(int $productId, ?int $variantId = null): array
    {
        $params = [$productId];
        $variantClause = 'variant_id IS NULL';
        if ($variantId !== null) {
            $variantClause = '(variant_id IS NULL OR variant_id = ?)';
            $params[] = $variantId;
        }
        return array_map(fn(array $row): array => $this->castRow($row), $this->database()->all(
            'SELECT media_id, variant_id, role, alt_text, sort_order
             FROM business_product_media
             WHERE product_id = ? AND ' . $variantClause . '
             ORDER BY sort_order ASC, media_id ASC',
            $params
        ));
    }

    /** @return list<array<string,mixed>> */
    public function productTags(int $siteId, int $productId): array
    {
        return array_map(fn(array $row): array => $this->castRow($row), $this->database()->all(
            'SELECT t.id, t.name, t.slug
             FROM business_product_tag_links l
             INNER JOIN business_product_tags t ON t.id = l.tag_id
             WHERE l.product_id = ? AND t.site_id = ? AND t.archived_at IS NULL
             ORDER BY t.name ASC',
            [$productId, $this->requireSiteId($siteId)]
        ));
    }

    /** @param array<string,mixed> $filters @return array{0:list<string>,1:array<string,mixed>} */
    private function productWhere(int $siteId, array $filters): array
    {
        $where = [
            'p.site_id = :site_id',
            'p.status = "active"',
            'p.is_public = 1',
            'p.is_ecommerce_enabled = 1',
            'p.archived_at IS NULL',
        ];
        $params = ['site_id' => $this->requireSiteId($siteId)];

        if (trim((string) ($filters['slug'] ?? '')) !== '') {
            $where[] = 'p.slug = :slug';
            $params['slug'] = trim((string) $filters['slug']);
        }
        if (trim((string) ($filters['q'] ?? '')) !== '') {
            $where[] = '(p.name LIKE :q OR p.slug LIKE :q OR p.short_description LIKE :q)';
            $params['q'] = '%' . trim((string) $filters['q']) . '%';
        }
        if (trim((string) ($filters['brand'] ?? '')) !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM business_product_brands b WHERE b.id = p.brand_id AND b.site_id = p.site_id AND b.slug = :brand AND b.status = "active" AND b.is_public = 1 AND b.archived_at IS NULL)';
            $params['brand'] = trim((string) $filters['brand']);
        } elseif (isset($filters['brand_id']) && (int) $filters['brand_id'] > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM business_product_brands b WHERE b.id = p.brand_id AND b.site_id = p.site_id AND b.id = :brand_id AND b.status = "active" AND b.is_public = 1 AND b.archived_at IS NULL)';
            $params['brand_id'] = (int) $filters['brand_id'];
        }
        if (trim((string) ($filters['category'] ?? '')) !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM business_product_categories c WHERE c.id = p.category_id AND c.site_id = p.site_id AND c.slug = :category AND c.is_public = 1 AND c.archived_at IS NULL)';
            $params['category'] = trim((string) $filters['category']);
        } elseif (isset($filters['category_id']) && (int) $filters['category_id'] > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM business_product_categories c WHERE c.id = p.category_id AND c.site_id = p.site_id AND c.id = :category_id AND c.is_public = 1 AND c.archived_at IS NULL)';
            $params['category_id'] = (int) $filters['category_id'];
        }
        return [$where, $params];
    }
}
