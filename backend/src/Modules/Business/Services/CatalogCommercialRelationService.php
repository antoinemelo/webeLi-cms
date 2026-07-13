<?php

declare(strict_types=1);

namespace App\Modules\Business\Services;

use App\Core\Database;
use InvalidArgumentException;

final class CatalogCommercialRelationService
{
    public function __construct(private readonly Database $db) {}

    /** @return list<array<string,mixed>> */
    public function relations(int $siteId, int $productId, ?string $type = null): array
    {
        $params = [$siteId, $productId];
        $where = '';
        if ($type !== null) {
            $where = ' AND r.relation_type=?';
            $params[] = $this->relationType($type);
        }
        return $this->db->all(
            'SELECT r.*, p.name AS target_name, p.slug AS target_slug, p.status AS target_status
             FROM business_product_relations r
             INNER JOIN business_products p ON p.id=r.related_product_id AND p.site_id=r.site_id
             WHERE r.site_id=? AND r.product_id=?' . $where . '
             ORDER BY r.sort_order, r.id',
            $params
        );
    }

    /** @return array<string,mixed> */
    public function link(int $siteId, int $sourceProductId, int $targetProductId, string $type, int $sortOrder = 0): array
    {
        if ($sourceProductId === $targetProductId) {
            throw new InvalidArgumentException('business.catalog.product_relation_self');
        }
        $this->requireProduct($siteId, $sourceProductId);
        $this->requireProduct($siteId, $targetProductId);
        $type = $this->relationType($type);
        $this->db->run(
            'INSERT INTO business_product_relations(site_id,product_id,related_product_id,relation_type,sort_order)
             VALUES(?,?,?,?,?)
             ON CONFLICT(site_id,product_id,related_product_id,relation_type)
             DO UPDATE SET sort_order=excluded.sort_order',
            [$siteId, $sourceProductId, $targetProductId, $type, max(0, $sortOrder)]
        );
        return $this->db->one(
            'SELECT * FROM business_product_relations WHERE site_id=? AND product_id=? AND related_product_id=? AND relation_type=?',
            [$siteId, $sourceProductId, $targetProductId, $type]
        ) ?? [];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function configureGiftCard(int $siteId, int $productId, array $payload): array
    {
        $product = $this->requireProduct($siteId, $productId);
        if (($product['type'] ?? '') !== 'gift_card') {
            throw new InvalidArgumentException('business.gift_card.product_type_required');
        }
        $currency = strtoupper(trim((string) ($payload['currency'] ?? 'CHF')));
        $mode = strtolower(trim((string) ($payload['value_mode'] ?? 'fixed')));
        if (!in_array($currency, ['CHF', 'EUR', 'USD'], true) || !in_array($mode, ['fixed', 'open'], true)) {
            throw new InvalidArgumentException('business.gift_card.policy_invalid');
        }
        $minimum = isset($payload['minimum_amount']) ? (float) $payload['minimum_amount'] : null;
        $maximum = isset($payload['maximum_amount']) ? (float) $payload['maximum_amount'] : null;
        if (($minimum !== null && $minimum < 0) || ($maximum !== null && $maximum < 0) || ($minimum !== null && $maximum !== null && $maximum < $minimum)) {
            throw new InvalidArgumentException('business.gift_card.amount_range_invalid');
        }
        $expires = isset($payload['expires_after_days']) ? (int) $payload['expires_after_days'] : null;
        if ($expires !== null && $expires < 1) {
            throw new InvalidArgumentException('business.gift_card.expiry_invalid');
        }
        $this->db->run(
            'INSERT INTO business_gift_card_policies(site_id,product_id,currency,value_mode,minimum_amount,maximum_amount,expires_after_days,is_active)
             VALUES(?,?,?,?,?,?,?,1)
             ON CONFLICT(product_id) DO UPDATE SET site_id=excluded.site_id,currency=excluded.currency,value_mode=excluded.value_mode,
                minimum_amount=excluded.minimum_amount,maximum_amount=excluded.maximum_amount,expires_after_days=excluded.expires_after_days,
                is_active=1,updated_at=CURRENT_TIMESTAMP',
            [$siteId, $productId, $currency, $mode, $minimum, $maximum, $expires]
        );
        return $this->db->one('SELECT * FROM business_gift_card_policies WHERE product_id=?', [$productId]) ?? [];
    }

    /** @return array<string,mixed> */
    private function requireProduct(int $siteId, int $productId): array
    {
        $row = $this->db->one('SELECT * FROM business_products WHERE site_id=? AND id=? AND archived_at IS NULL', [$siteId, $productId]);
        if ($row === null) {
            throw new InvalidArgumentException('business.catalog.product_not_found');
        }
        return $row;
    }

    private function relationType(string $type): string
    {
        $type = strtolower(trim($type));
        if (!in_array($type, ['upsell', 'cross_sell'], true)) {
            throw new InvalidArgumentException('business.catalog.product_relation_type_invalid');
        }
        return $type;
    }
}
