<?php

declare(strict_types=1);

namespace App\Modules\Business\Repositories;

use DateTimeImmutable;

final class CatalogDiscountRepository extends BusinessRepositoryBase
{
    /** @return array{items:list<array<string,mixed>>,limit:int,offset:int,total:int} */
    public function list(int $siteId, int $limit = 50, int $offset = 0, bool $includeArchived = false): array
    {
        $where = ['site_id = :site_id'];
        $params = ['site_id' => $this->requireSiteId($siteId)];
        if (!$includeArchived) {
            $where[] = 'archived_at IS NULL';
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int) (($this->database()->one('SELECT COUNT(*) AS c FROM business_catalog_discounts WHERE ' . $sqlWhere, $params)['c'] ?? 0));
        $limit = $this->limit($limit);
        $offset = $this->offset($offset);
        $rows = $this->database()->all(
            'SELECT * FROM business_catalog_discounts WHERE ' . $sqlWhere . ' ORDER BY priority ASC, id DESC LIMIT :limit OFFSET :offset',
            $params + ['limit' => $limit, 'offset' => $offset]
        );
        return ['items' => array_map(fn(array $row): array => $this->castRow($row), $rows), 'limit' => $limit, 'offset' => $offset, 'total' => $total];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function create(int $siteId, array $payload, ?int $actorId = null): array
    {
        $type = $this->choice($payload['type'] ?? $payload['discount_type'] ?? 'percent', ['percent', 'amount'], 'discount_type');
        $value = (float) ($payload['value'] ?? $payload['discount_value'] ?? 0);
        if ($type === 'percent' && ($value <= 0 || $value > 100)) {
            throw new \InvalidArgumentException('business.catalog.discount_percent_invalid');
        }
        if ($type === 'amount' && $value <= 0) {
            throw new \InvalidArgumentException('business.catalog.discount_amount_invalid');
        }
        $scopeId = (int) ($payload['scope_id'] ?? 0);
        if ($scopeId < 1) {
            throw new \InvalidArgumentException('business.catalog.discount_scope_id_required');
        }
        $scopeType = $this->choice($payload['scope'] ?? $payload['scope_type'] ?? 'product', ['product', 'variant', 'category', 'brand'], 'discount_scope');
        $this->assertScopeExists($this->requireSiteId($siteId), $scopeType, $scopeId);
        $this->assertDateRange($payload['starts_at'] ?? null, $payload['ends_at'] ?? null);
        $this->database()->run(
            'INSERT INTO business_catalog_discounts(site_id, name, status, discount_type, discount_value, currency, scope_type, scope_id, channel, customer_segment, starts_at, ends_at, priority, created_by_iam_user_id, updated_by_iam_user_id)
             VALUES(:site_id, :name, :status, :discount_type, :discount_value, :currency, :scope_type, :scope_id, :channel, :customer_segment, :starts_at, :ends_at, :priority, :actor, :actor)',
            [
                'site_id' => $this->requireSiteId($siteId),
                'name' => $this->text($payload['name'] ?? null, 'name', 180),
                'status' => $this->choice($payload['status'] ?? 'active', ['draft', 'active', 'archived'], 'discount_status'),
                'discount_type' => $type,
                'discount_value' => round($value, 2),
                'currency' => $type === 'amount' ? strtoupper((string) ($payload['currency'] ?? 'CHF')) : null,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'channel' => $this->choice($payload['channel'] ?? 'all', ['all', 'ecommerce', 'pos', 'catalogue', 'admin'], 'discount_channel'),
                'customer_segment' => $this->segment($payload['customer_segment'] ?? null),
                'starts_at' => $payload['starts_at'] ?? null,
                'ends_at' => $payload['ends_at'] ?? null,
                'priority' => (int) ($payload['priority'] ?? 100),
                'actor' => $actorId,
            ]
        );
        return $this->find($siteId, $this->database()->lastInsertId()) ?? [];
    }

    public function find(int $siteId, int $id, bool $includeArchived = false): ?array
    {
        $row = $this->database()->one(
            'SELECT * FROM business_catalog_discounts WHERE site_id = ? AND id = ?' . ($includeArchived ? '' : ' AND archived_at IS NULL') . ' LIMIT 1',
            [$this->requireSiteId($siteId), $id]
        );
        return $row ? $this->castRow($row) : null;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed>|null */
    public function update(int $siteId, int $id, array $payload, ?int $actorId = null): ?array
    {
        $current = $this->find($siteId, $id, true);
        if ($current === null) {
            return null;
        }
        $type = $this->choice($payload['type'] ?? $payload['discount_type'] ?? $current['discount_type'], ['percent', 'amount'], 'discount_type');
        $value = (float) ($payload['value'] ?? $payload['discount_value'] ?? $current['discount_value']);
        if ($type === 'percent' && ($value <= 0 || $value > 100)) {
            throw new \InvalidArgumentException('business.catalog.discount_percent_invalid');
        }
        if ($type === 'amount' && $value <= 0) {
            throw new \InvalidArgumentException('business.catalog.discount_amount_invalid');
        }
        $scopeType = $this->choice($payload['scope'] ?? $payload['scope_type'] ?? $current['scope_type'], ['product', 'variant', 'category', 'brand'], 'discount_scope');
        $scopeId = (int) ($payload['scope_id'] ?? $current['scope_id']);
        if ($scopeId < 1) {
            throw new \InvalidArgumentException('business.catalog.discount_scope_id_required');
        }
        $this->assertScopeExists($this->requireSiteId($siteId), $scopeType, $scopeId);
        $this->assertDateRange($payload['starts_at'] ?? $current['starts_at'] ?? null, $payload['ends_at'] ?? $current['ends_at'] ?? null);
        $this->database()->run(
            'UPDATE business_catalog_discounts
             SET name = :name, status = :status, discount_type = :discount_type, discount_value = :discount_value, currency = :currency,
                 scope_type = :scope_type, scope_id = :scope_id, channel = :channel, customer_segment = :customer_segment, starts_at = :starts_at, ends_at = :ends_at,
                 priority = :priority, updated_by_iam_user_id = :actor, updated_at = CURRENT_TIMESTAMP
             WHERE site_id = :site_id AND id = :id',
            [
                'site_id' => $this->requireSiteId($siteId),
                'id' => $id,
                'name' => $this->text($payload['name'] ?? $current['name'], 'name', 180),
                'status' => $this->choice($payload['status'] ?? $current['status'], ['draft', 'active', 'archived'], 'discount_status'),
                'discount_type' => $type,
                'discount_value' => round($value, 2),
                'currency' => $type === 'amount' ? strtoupper((string) ($payload['currency'] ?? $current['currency'] ?? 'CHF')) : null,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'channel' => $this->choice($payload['channel'] ?? $current['channel'], ['all', 'ecommerce', 'pos', 'catalogue', 'admin'], 'discount_channel'),
                'customer_segment' => $this->segment($payload['customer_segment'] ?? $current['customer_segment'] ?? null),
                'starts_at' => $payload['starts_at'] ?? $current['starts_at'] ?? null,
                'ends_at' => $payload['ends_at'] ?? $current['ends_at'] ?? null,
                'priority' => (int) ($payload['priority'] ?? $current['priority'] ?? 100),
                'actor' => $actorId,
            ]
        );
        return $this->find($siteId, $id, true);
    }

    /** @return list<array<string,mixed>> */
    public function activeForVariant(int $siteId, int $productId, int $variantId, ?string $channel = null, ?DateTimeImmutable $at = null, ?string $currency = 'CHF', ?string $customerSegment = null): array
    {
        $product = $this->database()->one('SELECT brand_id, category_id FROM business_products WHERE site_id = ? AND id = ? AND archived_at IS NULL LIMIT 1', [$this->requireSiteId($siteId), $productId]);
        if ($product === null) {
            return [];
        }
        $now = ($at ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        return array_map(fn(array $row): array => $this->castRow($row), $this->database()->all(
            'SELECT * FROM business_catalog_discounts
             WHERE site_id = ?
               AND status = \'active\'
               AND archived_at IS NULL
               AND (channel = \'all\' OR channel = ?)
               AND (currency IS NULL OR currency = ?)
               AND (customer_segment IS NULL OR customer_segment = ?)
               AND (starts_at IS NULL OR starts_at <= ?)
               AND (ends_at IS NULL OR ends_at >= ?)
               AND (
                    (scope_type = \'variant\' AND scope_id = ?)
                 OR (scope_type = \'product\' AND scope_id = ?)
                 OR (scope_type = \'category\' AND scope_id = ?)
                 OR (scope_type = \'brand\' AND scope_id = ?)
               )
             ORDER BY CASE scope_type WHEN \'variant\' THEN 0 WHEN \'product\' THEN 1 WHEN \'category\' THEN 2 ELSE 3 END ASC,
                      CASE WHEN customer_segment IS NULL THEN 1 ELSE 0 END,
                      CASE WHEN channel = \'all\' THEN 1 ELSE 0 END,
                      priority ASC, id ASC',
            [$this->requireSiteId($siteId), $channel ?? 'all', strtoupper((string) ($currency ?? 'CHF')), $this->segment($customerSegment), $now, $now, $variantId, $productId, (int) ($product['category_id'] ?? 0), (int) ($product['brand_id'] ?? 0)]
        ));
    }

    public function archive(int $siteId, int $id, ?int $actorId = null): bool
    {
        $this->database()->run(
            'UPDATE business_catalog_discounts SET status = \'archived\', archived_at = COALESCE(archived_at, CURRENT_TIMESTAMP), updated_by_iam_user_id = ?, updated_at = CURRENT_TIMESTAMP WHERE site_id = ? AND id = ?',
            [$actorId, $this->requireSiteId($siteId), $id]
        );
        return true;
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

    private function assertScopeExists(int $siteId, string $scopeType, int $scopeId): void
    {
        $sql = match ($scopeType) {
            'brand' => 'SELECT 1 FROM business_product_brands WHERE site_id = ? AND id = ? AND archived_at IS NULL LIMIT 1',
            'category' => 'SELECT 1 FROM business_product_categories WHERE site_id = ? AND id = ? AND archived_at IS NULL LIMIT 1',
            'product' => 'SELECT 1 FROM business_products WHERE site_id = ? AND id = ? AND archived_at IS NULL LIMIT 1',
            'variant' => 'SELECT 1 FROM business_product_variants v INNER JOIN business_products p ON p.id = v.product_id WHERE p.site_id = ? AND v.id = ? AND v.archived_at IS NULL AND p.archived_at IS NULL LIMIT 1',
            default => null,
        };
        if ($sql === null || $this->database()->one($sql, [$siteId, $scopeId]) === null) {
            throw new \InvalidArgumentException('business.catalog.discount_scope_not_found');
        }
    }

    private function assertDateRange(mixed $startsAt, mixed $endsAt): void
    {
        $start = trim((string) ($startsAt ?? ''));
        $end = trim((string) ($endsAt ?? ''));
        if ($start !== '' && strtotime($start) === false) {
            throw new \InvalidArgumentException('business.catalog.discount_starts_at_invalid');
        }
        if ($end !== '' && strtotime($end) === false) {
            throw new \InvalidArgumentException('business.catalog.discount_ends_at_invalid');
        }
        if ($start !== '' && $end !== '' && strtotime($end) <= strtotime($start)) {
            throw new \InvalidArgumentException('business.catalog.discount_date_range_invalid');
        }
    }

    private function segment(mixed $value): ?string
    {
        $segment = strtolower(trim((string) ($value ?? '')));
        if ($segment === '') {
            return null;
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/', $segment)) {
            throw new \InvalidArgumentException('business.catalog.customer_segment_invalid');
        }
        return $segment;
    }
}
