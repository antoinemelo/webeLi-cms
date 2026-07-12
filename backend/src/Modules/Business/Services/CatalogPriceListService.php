<?php

declare(strict_types=1);

namespace App\Modules\Business\Services;

use App\Core\Database;
use DateTimeImmutable;
use InvalidArgumentException;

final class CatalogPriceListService
{
    private const CURRENCIES = ['CHF', 'EUR', 'USD'];
    private const CHANNELS = ['all', 'ecommerce', 'pos', 'catalogue', 'admin'];
    private const ADJUSTMENTS = ['fixed', 'amount_delta', 'percent_delta'];

    public function __construct(private readonly Database $db) {}

    /** @return list<array<string,mixed>> */
    public function lists(int $siteId): array
    {
        return $this->db->all(
            'SELECT pl.*, COUNT(pli.id) AS item_count
             FROM business_price_lists pl
             LEFT JOIN business_price_list_items pli ON pli.price_list_id=pl.id AND pli.archived_at IS NULL
             WHERE pl.site_id=? AND pl.archived_at IS NULL
             GROUP BY pl.id ORDER BY pl.priority, pl.id',
            [$this->positive($siteId, 'site_id')]
        );
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function createList(int $siteId, array $payload, ?int $actorId = null): array
    {
        $currency = strtoupper(trim((string) ($payload['currency'] ?? 'CHF')));
        $channel = strtolower(trim((string) ($payload['channel'] ?? 'all')));
        if (!in_array($currency, self::CURRENCIES, true)) {
            throw new InvalidArgumentException('business.pricing.currency_invalid');
        }
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException('business.pricing.channel_invalid');
        }
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('business.pricing.price_list_name_required');
        }
        [$startsAt, $endsAt] = $this->dateRange($payload['starts_at'] ?? null, $payload['ends_at'] ?? null);
        $segment = $this->segment($payload['customer_segment'] ?? null);
        $this->db->run(
            'INSERT INTO business_price_lists(site_id,name,currency,channel,customer_segment,priority,status,starts_at,ends_at,created_by_iam_user_id,updated_by_iam_user_id)
             VALUES(?,?,?,?,?,?,?,?,?,?,?)',
            [
                $this->positive($siteId, 'site_id'), $name, $currency, $channel, $segment,
                (int) ($payload['priority'] ?? 100), $this->status($payload['status'] ?? 'active'),
                $startsAt, $endsAt, $actorId, $actorId,
            ]
        );
        return $this->requireList($siteId, $this->db->lastInsertId());
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function addItem(int $siteId, int $priceListId, array $payload): array
    {
        $list = $this->requireList($siteId, $priceListId);
        $productId = $this->positive($payload['product_id'] ?? 0, 'product_id');
        $variantId = isset($payload['variant_id']) && $payload['variant_id'] !== '' ? $this->positive($payload['variant_id'], 'variant_id') : null;
        $this->assertTarget($siteId, $productId, $variantId);
        $type = strtolower(trim((string) ($payload['adjustment_type'] ?? 'fixed')));
        if (!in_array($type, self::ADJUSTMENTS, true)) {
            throw new InvalidArgumentException('business.pricing.adjustment_type_invalid');
        }
        $value = round((float) ($payload['adjustment_value'] ?? 0), 2);
        if (($type === 'fixed' && $value < 0) || ($type === 'percent_delta' && ($value < -100 || $value > 1000))) {
            throw new InvalidArgumentException('business.pricing.adjustment_value_invalid');
        }
        $compareAt = array_key_exists('compare_at_amount', $payload) && $payload['compare_at_amount'] !== null
            ? round((float) $payload['compare_at_amount'], 2)
            : null;
        if ($compareAt !== null && $compareAt < 0) {
            throw new InvalidArgumentException('business.pricing.compare_at_invalid');
        }
        [$startsAt, $endsAt] = $this->dateRange($payload['starts_at'] ?? null, $payload['ends_at'] ?? null);
        try {
            $this->db->run(
                'INSERT INTO business_price_list_items(price_list_id,product_id,variant_id,adjustment_type,adjustment_value,compare_at_amount,priority,starts_at,ends_at)
                 VALUES(?,?,?,?,?,?,?,?,?)',
                [(int) $list['id'], $productId, $variantId, $type, $value, $compareAt, (int) ($payload['priority'] ?? 100), $startsAt, $endsAt]
            );
        } catch (\PDOException $e) {
            throw new InvalidArgumentException('business.pricing.price_list_item_conflict', 0, $e);
        }
        return $this->requireItem($this->db->lastInsertId());
    }

    /** @return array<string,mixed> */
    private function requireList(int $siteId, int $id): array
    {
        $row = $this->db->one('SELECT * FROM business_price_lists WHERE site_id=? AND id=? AND archived_at IS NULL', [$siteId, $id]);
        if ($row === null) {
            throw new InvalidArgumentException('business.pricing.price_list_not_found');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function requireItem(int $id): array
    {
        $row = $this->db->one('SELECT * FROM business_price_list_items WHERE id=? AND archived_at IS NULL', [$id]);
        if ($row === null) {
            throw new InvalidArgumentException('business.pricing.price_list_item_not_found');
        }
        return $row;
    }

    private function assertTarget(int $siteId, int $productId, ?int $variantId): void
    {
        $product = $this->db->one('SELECT id FROM business_products WHERE site_id=? AND id=? AND archived_at IS NULL', [$siteId, $productId]);
        if ($product === null) {
            throw new InvalidArgumentException('business.catalog.product_not_found');
        }
        if ($variantId !== null && $this->db->one('SELECT id FROM business_product_variants WHERE id=? AND product_id=? AND archived_at IS NULL', [$variantId, $productId]) === null) {
            throw new InvalidArgumentException('business.catalog.variant_not_found');
        }
    }

    /** @return array{0:?string,1:?string} */
    private function dateRange(mixed $start, mixed $end): array
    {
        $start = trim((string) ($start ?? '')) ?: null;
        $end = trim((string) ($end ?? '')) ?: null;
        try {
            $startDate = $start === null ? null : new DateTimeImmutable($start);
            $endDate = $end === null ? null : new DateTimeImmutable($end);
        } catch (\Throwable) {
            throw new InvalidArgumentException('business.pricing.date_invalid');
        }
        if ($startDate !== null && $endDate !== null && $endDate <= $startDate) {
            throw new InvalidArgumentException('business.pricing.date_range_invalid');
        }
        return [$start, $end];
    }

    private function segment(mixed $value): ?string
    {
        $segment = strtolower(trim((string) ($value ?? '')));
        if ($segment === '') {
            return null;
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/', $segment)) {
            throw new InvalidArgumentException('business.pricing.customer_segment_invalid');
        }
        return $segment;
    }

    private function status(mixed $value): string
    {
        $status = strtolower(trim((string) $value));
        if (!in_array($status, ['draft', 'active', 'archived'], true)) {
            throw new InvalidArgumentException('business.pricing.status_invalid');
        }
        return $status;
    }

    private function positive(mixed $value, string $field): int
    {
        $id = (int) $value;
        if ($id < 1) {
            throw new InvalidArgumentException('business.pricing.' . $field . '_invalid');
        }
        return $id;
    }
}
