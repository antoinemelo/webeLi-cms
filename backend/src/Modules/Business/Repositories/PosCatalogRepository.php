<?php

declare(strict_types=1);

namespace App\Modules\Business\Repositories;

final class PosCatalogRepository extends BusinessRepositoryBase
{
    /** @param array<string,mixed> $filters @return array{items:list<array<string,mixed>>,limit:int,offset:int,total:int,has_more:bool} */
    public function products(int $siteId, array $filters = [], int $limit = 100, int $offset = 0): array
    {
        [$where, $params] = $this->productWhere($siteId, $filters);
        $sqlWhere = implode(' AND ', $where);
        $total = (int) (($this->database()->one('SELECT COUNT(*) AS c FROM business_products p WHERE ' . $sqlWhere, $params)['c'] ?? 0));
        $limit = $this->limit($limit, 500);
        $offset = $this->offset($offset);
        $rows = $this->database()->all(
            'SELECT p.id, p.site_id, p.brand_id, p.category_id, p.tax_class_id, p.name, p.slug, p.short_description, p.track_stock, p.allow_backorder, p.updated_at
             FROM business_products p
             WHERE ' . $sqlWhere . '
             ORDER BY p.name ASC, p.id ASC
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

    /** @param array<string,mixed> $filters @return array{items:list<array<string,mixed>>,limit:int,offset:int,total:int,has_more:bool} */
    public function variants(int $siteId, array $filters = [], int $limit = 250, int $offset = 0): array
    {
        [$where, $params] = $this->variantWhere($siteId, $filters);
        $sqlWhere = implode(' AND ', $where);
        $total = (int) (($this->database()->one('SELECT COUNT(*) AS c FROM business_product_variants v INNER JOIN business_products p ON p.id = v.product_id WHERE ' . $sqlWhere, $params)['c'] ?? 0));
        $limit = $this->limit($limit, 1000);
        $offset = $this->offset($offset);
        $rows = $this->database()->all(
            'SELECT v.id, v.product_id, v.sku, v.barcode, v.name, v.track_stock, v.stock_quantity, v.stock_reserved, v.allow_backorder, v.updated_at,
                    p.site_id, p.brand_id, p.category_id, p.tax_class_id, p.name AS product_name, p.slug AS product_slug, p.track_stock AS product_track_stock, p.allow_backorder AS product_allow_backorder
             FROM business_product_variants v
             INNER JOIN business_products p ON p.id = v.product_id
             WHERE ' . $sqlWhere . '
             ORDER BY p.name ASC, v.sort_order ASC, v.id ASC
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

    /** @return list<array<string,mixed>> */
    public function brands(int $siteId): array
    {
        return array_map(fn(array $row): array => $this->castRow($row), $this->database()->all(
            'SELECT DISTINCT b.id, b.name, b.slug
             FROM business_product_brands b
             INNER JOIN business_products p ON p.brand_id = b.id
             WHERE p.site_id = ? AND p.status = "active" AND p.is_pos_enabled = 1 AND p.archived_at IS NULL
               AND b.site_id = p.site_id AND b.status = "active" AND b.archived_at IS NULL
             ORDER BY b.name ASC',
            [$this->requireSiteId($siteId)]
        ));
    }

    /** @return list<array<string,mixed>> */
    public function categories(int $siteId): array
    {
        return array_map(fn(array $row): array => $this->castRow($row), $this->database()->all(
            'SELECT DISTINCT c.id, c.parent_id, c.name, c.slug
             FROM business_product_categories c
             INNER JOIN business_products p ON p.category_id = c.id
             WHERE p.site_id = ? AND p.status = "active" AND p.is_pos_enabled = 1 AND p.archived_at IS NULL
               AND c.site_id = p.site_id AND c.archived_at IS NULL
             ORDER BY c.name ASC',
            [$this->requireSiteId($siteId)]
        ));
    }

    /** @return list<array<string,mixed>> */
    public function variantsForProduct(int $siteId, int $productId): array
    {
        return $this->variants($siteId, ['product_id' => $productId], 1000, 0)['items'];
    }

    public function brand(int $siteId, ?int $brandId): ?array
    {
        if (!$brandId) {
            return null;
        }
        $row = $this->database()->one(
            'SELECT id, name, slug FROM business_product_brands WHERE site_id = ? AND id = ? AND status = "active" AND archived_at IS NULL LIMIT 1',
            [$this->requireSiteId($siteId), $brandId]
        );
        return $row ? $this->castRow($row) : null;
    }

    public function category(int $siteId, ?int $categoryId): ?array
    {
        if (!$categoryId) {
            return null;
        }
        $row = $this->database()->one(
            'SELECT id, parent_id, name, slug FROM business_product_categories WHERE site_id = ? AND id = ? AND archived_at IS NULL LIMIT 1',
            [$this->requireSiteId($siteId), $categoryId]
        );
        return $row ? $this->castRow($row) : null;
    }

    public function taxClass(?int $taxClassId): ?array
    {
        if (!$taxClassId) {
            return null;
        }
        $row = $this->database()->one('SELECT id, code, name, rate, country FROM business_tax_classes WHERE id = ? AND archived_at IS NULL LIMIT 1', [$taxClassId]);
        return $row ? $this->castRow($row) : null;
    }

    /** @return list<array<string,mixed>> */
    public function variantOptions(int $variantId): array
    {
        return array_map(fn(array $row): array => $this->castRow($row), $this->database()->all(
            'SELECT o.code AS option_code, o.name AS option_name, ov.code AS value_code, ov.label, ov.value
             FROM business_product_variant_option_values vv
             INNER JOIN business_product_options o ON o.id = vv.option_id
             INNER JOIN business_product_option_values ov ON ov.id = vv.option_value_id
             WHERE vv.variant_id = ? AND o.archived_at IS NULL AND ov.archived_at IS NULL
             ORDER BY o.sort_order ASC, ov.sort_order ASC',
            [$variantId]
        ));
    }

    public function thumbnail(int $productId, ?int $variantId = null): ?array
    {
        $row = null;
        if ($variantId !== null) {
            $row = $this->database()->one(
                'SELECT media_id, alt_text FROM business_product_media WHERE product_id = ? AND variant_id = ? AND role = "thumbnail" ORDER BY sort_order ASC, media_id ASC LIMIT 1',
                [$productId, $variantId]
            );
        }
        $row ??= $this->database()->one(
            'SELECT media_id, alt_text FROM business_product_media WHERE product_id = ? AND variant_id IS NULL AND role = "thumbnail" ORDER BY sort_order ASC, media_id ASC LIMIT 1',
            [$productId]
        );
        return $row ? $this->castRow($row) : null;
    }

    /** @param array<string,mixed> $filters @return array{0:list<string>,1:array<string,mixed>} */
    private function productWhere(int $siteId, array $filters): array
    {
        $where = ['p.site_id = :site_id', 'p.status = "active"', 'p.is_pos_enabled = 1', 'p.archived_at IS NULL'];
        $params = ['site_id' => $this->requireSiteId($siteId)];
        if (trim((string) ($filters['updated_since'] ?? '')) !== '') {
            $where[] = 'p.updated_at >= :updated_since';
            $params['updated_since'] = trim((string) $filters['updated_since']);
        }
        if (trim((string) ($filters['q'] ?? '')) !== '') {
            $where[] = '(p.name LIKE :q OR p.slug LIKE :q OR p.sku_base LIKE :q)';
            $params['q'] = '%' . trim((string) $filters['q']) . '%';
        }
        return [$where, $params];
    }

    /** @param array<string,mixed> $filters @return array{0:list<string>,1:array<string,mixed>} */
    private function variantWhere(int $siteId, array $filters): array
    {
        $where = ['p.site_id = :site_id', 'p.status = "active"', 'p.is_pos_enabled = 1', 'p.archived_at IS NULL', 'v.status = "active"', 'v.archived_at IS NULL'];
        $params = ['site_id' => $this->requireSiteId($siteId)];
        if (isset($filters['product_id']) && (int) $filters['product_id'] > 0) {
            $where[] = 'v.product_id = :product_id';
            $params['product_id'] = (int) $filters['product_id'];
        }
        if (trim((string) ($filters['barcode'] ?? '')) !== '') {
            $where[] = 'v.barcode = :barcode';
            $params['barcode'] = trim((string) $filters['barcode']);
        }
        if (trim((string) ($filters['updated_since'] ?? '')) !== '') {
            $where[] = '(v.updated_at >= :updated_since OR p.updated_at >= :updated_since)';
            $params['updated_since'] = trim((string) $filters['updated_since']);
        }
        if (trim((string) ($filters['q'] ?? '')) !== '') {
            $where[] = '(v.sku LIKE :q OR v.barcode LIKE :q OR v.name LIKE :q OR p.name LIKE :q)';
            $params['q'] = '%' . trim((string) $filters['q']) . '%';
        }
        return [$where, $params];
    }
}
