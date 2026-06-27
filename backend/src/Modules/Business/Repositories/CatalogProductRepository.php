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
        foreach (['status', 'type', 'brand_id', 'category_id', 'is_public', 'is_ecommerce_enabled', 'is_pos_enabled'] as $key) {
            if (array_key_exists($key, $filters) && $filters[$key] !== '' && $filters[$key] !== null) {
                $where[] = $key . ' = :' . $key;
                $params[$key] = in_array($key, ['brand_id', 'category_id'], true) ? (int) $filters[$key] : $filters[$key];
            }
        }
        if (($filters['channel'] ?? '') === 'public') {
            $where[] = 'is_public = 1';
        } elseif (($filters['channel'] ?? '') === 'ecommerce') {
            $where[] = 'is_ecommerce_enabled = 1';
        } elseif (($filters['channel'] ?? '') === 'pos') {
            $where[] = 'is_pos_enabled = 1';
        }
        if (!empty($filters['low_stock'])) {
            $where[] = 'EXISTS (SELECT 1 FROM business_product_variants v WHERE v.product_id = business_products.id AND v.archived_at IS NULL AND v.stock_quantity <= 5 AND COALESCE(v.track_stock, business_products.track_stock) = 1)';
        }
        if (trim((string) ($filters['q'] ?? '')) !== '') {
            $where[] = '(name LIKE :q OR slug LIKE :q OR sku_base LIKE :q)';
            $params['q'] = '%' . trim((string) $filters['q']) . '%';
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int) (($this->database()->one('SELECT COUNT(*) AS c FROM business_products WHERE ' . $sqlWhere, $params)['c'] ?? 0));
        $limit = $this->limit($limit);
        $offset = $this->offset($offset);
        $rows = $this->database()->all(
            'SELECT * FROM business_products WHERE ' . $sqlWhere . ' ORDER BY updated_at DESC, id DESC LIMIT :limit OFFSET :offset',
            $params + ['limit' => $limit, 'offset' => $offset]
        );
        return ['items' => array_map(fn(array $row): array => $this->castRow($row), $rows), 'limit' => $limit, 'offset' => $offset, 'total' => $total];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function create(int $siteId, array $payload, ?int $actorId = null): array
    {
        $name = $this->text($payload['name'] ?? null, 'name', 180);
        $slug = $this->slug($payload['slug'] ?? $name);
        $channels = $payload['channels'] ?? [];
        $this->database()->run(
            'INSERT INTO business_products(site_id, brand_id, category_id, type, status, visibility, sku_base, name, slug, short_description, description, unit, tax_class_id, track_stock, allow_backorder, is_public, is_ecommerce_enabled, is_pos_enabled, created_by_iam_user_id, updated_by_iam_user_id)
             VALUES(:site_id, :brand_id, :category_id, :type, :status, :visibility, :sku_base, :name, :slug, :short_description, :description, :unit, :tax_class_id, :track_stock, :allow_backorder, :is_public, :is_ecommerce_enabled, :is_pos_enabled, :actor, :actor)',
            [
                'site_id' => $this->requireSiteId($siteId),
                'brand_id' => $payload['brand_id'] ?? null,
                'category_id' => $payload['category_id'] ?? null,
                'type' => $this->choice($payload['type'] ?? 'physical', ['physical', 'service', 'gift_card'], 'type'),
                'status' => $this->choice($payload['status'] ?? 'draft', ['draft', 'active', 'archived'], 'status'),
                'visibility' => $payload['visibility'] ?? (in_array('public', $channels, true) ? 'public' : 'internal'),
                'sku_base' => $this->nullableText($payload['sku_base'] ?? null, 'sku_base', 80),
                'name' => $name,
                'slug' => $slug,
                'short_description' => $this->nullableText($payload['short_description'] ?? null, 'short_description', 500),
                'description' => $this->nullableText($payload['description'] ?? null, 'description', 10000),
                'unit' => $this->key($payload['unit'] ?? 'unit', 'unit', 32),
                'tax_class_id' => $payload['tax_class_id'] ?? null,
                'track_stock' => $this->boolInt($payload['track_stock'] ?? $payload['stock_enabled'] ?? false),
                'allow_backorder' => $this->boolInt($payload['allow_backorder'] ?? false),
                'is_public' => $this->boolInt($payload['is_public'] ?? in_array('public', $channels, true)),
                'is_ecommerce_enabled' => $this->boolInt($payload['is_ecommerce_enabled'] ?? in_array('ecommerce', $channels, true)),
                'is_pos_enabled' => $this->boolInt($payload['is_pos_enabled'] ?? in_array('pos', $channels, true)),
                'actor' => $actorId,
            ]
        );
        $productId = $this->database()->lastInsertId();
        if (array_key_exists('base_purchase_price', $payload)) {
            $this->setBasePrice($productId, 'purchase', $payload['base_purchase_price'], $payload['currency'] ?? 'CHF', false, $actorId);
        }
        if (array_key_exists('base_sale_price', $payload)) {
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
        $this->database()->run(
            'UPDATE business_products SET name = :name, slug = :slug, status = :status, visibility = :visibility, short_description = :short_description, description = :description,
                updated_by_iam_user_id = :actor, updated_at = CURRENT_TIMESTAMP
             WHERE site_id = :site_id AND id = :id',
            [
                'site_id' => $this->requireSiteId($siteId),
                'id' => $id,
                'name' => $name,
                'slug' => $slug,
                'status' => $this->choice($payload['status'] ?? $current['status'], ['draft', 'active', 'archived'], 'status'),
                'visibility' => $payload['visibility'] ?? $current['visibility'],
                'short_description' => $this->nullableText($payload['short_description'] ?? $current['short_description'] ?? null, 'short_description', 500),
                'description' => $this->nullableText($payload['description'] ?? $current['description'] ?? null, 'description', 10000),
                'actor' => $actorId,
            ]
        );
        return $this->find($siteId, $id);
    }

    public function find(int $siteId, int $id, bool $includeArchived = false): ?array
    {
        $row = $this->database()->one(
            'SELECT * FROM business_products WHERE site_id = ? AND id = ?' . ($includeArchived ? '' : ' AND archived_at IS NULL') . ' LIMIT 1',
            [$this->requireSiteId($siteId), $id]
        );
        return $row ? $this->castRow($row) : null;
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
            'UPDATE business_products SET status = "archived", archived_at = COALESCE(archived_at, CURRENT_TIMESTAMP), updated_by_iam_user_id = ?, updated_at = CURRENT_TIMESTAMP WHERE site_id = ? AND id = ?',
            [$actorId, $this->requireSiteId($siteId), $id]
        );
        return true;
    }

    public function activeVariantCount(int $productId): int
    {
        $row = $this->database()->one('SELECT COUNT(*) AS c FROM business_product_variants WHERE product_id = ? AND status = "active" AND archived_at IS NULL', [$productId]);
        return (int) ($row['c'] ?? 0);
    }

    public function hasSaleBasePrice(int $productId): bool
    {
        return $this->database()->one('SELECT 1 FROM business_product_base_prices WHERE product_id = ? AND price_kind = "sale" LIMIT 1', [$productId]) !== null;
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
