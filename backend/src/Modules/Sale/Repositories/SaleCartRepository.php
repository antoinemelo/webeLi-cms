<?php

declare(strict_types=1);

namespace App\Modules\Sale\Repositories;

use App\Modules\Sale\Exceptions\SaleValidationException;

final class SaleCartRepository extends SaleRepositoryBase
{
    /** @return array{items:list<array<string,mixed>>,limit:int,offset:int,total:int,has_more:bool} */
    public function list(int $siteId, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = ['site_id = :site_id'];
        $params = ['site_id' => $siteId];
        if (trim((string) ($filters['status'] ?? '')) !== '') {
            $where[] = 'status = :status';
            $params['status'] = trim((string) $filters['status']);
        }
        if (isset($filters['channel_id']) && (int) $filters['channel_id'] > 0) {
            $where[] = 'channel_id = :channel_id';
            $params['channel_id'] = (int) $filters['channel_id'];
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int) ($this->rawDatabase()->one('SELECT COUNT(*) AS count FROM sale_carts WHERE ' . $sqlWhere, $params)['count'] ?? 0);
        $items = $this->rawDatabase()->all(
            'SELECT * FROM sale_carts WHERE ' . $sqlWhere . ' ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset',
            $params + ['limit' => max(1, min(100, $limit)), 'offset' => max(0, $offset)]
        );
        return ['items' => $items, 'limit' => $limit, 'offset' => $offset, 'total' => $total, 'has_more' => ($offset + $limit) < $total];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function create(int $siteId, int $channelId, array $payload = []): array
    {
        $this->rawDatabase()->run(
            'INSERT INTO sale_carts(
                site_id, channel_id, status, currency, customer_company_id, customer_contact_id,
                customer_snapshot_json, billing_address_json, shipping_address_json, created_by_iam_user_id
             ) VALUES(?, ?, "active", ?, ?, ?, ?, ?, ?, ?)',
            [
                $siteId,
                $channelId,
                strtoupper((string) ($payload['currency'] ?? 'CHF')),
                $payload['customer_company_id'] ?? null,
                $payload['customer_contact_id'] ?? null,
                $this->json($payload['customer_snapshot'] ?? []),
                $this->json($payload['billing_address'] ?? []),
                $this->json($payload['shipping_address'] ?? []),
                $payload['iam_user_id'] ?? null,
            ]
        );
        return $this->requireCart((int) $this->rawDatabase()->lastInsertId());
    }

    /** @return array<string,mixed> */
    public function requireCart(int $cartId): array
    {
        $row = $this->rawDatabase()->one('SELECT * FROM sale_carts WHERE id = ? LIMIT 1', [$cartId]);
        if ($row === null) {
            throw new SaleValidationException('sale.cart_not_found');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    public function cartWithLines(int $cartId): array
    {
        $cart = $this->requireCart($cartId);
        $cart['lines'] = $this->lines($cartId);
        return $cart;
    }

    /** @return list<array<string,mixed>> */
    public function lines(int $cartId): array
    {
        return $this->rawDatabase()->all('SELECT * FROM sale_cart_lines WHERE cart_id = ? ORDER BY id ASC', [$cartId]);
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    public function addOrIncrementLine(int $cartId, array $snapshot, int $quantity, array $amounts): array
    {
        $lineKey = 'variant:' . (int) $snapshot['business_variant_id'];
        $existing = $this->rawDatabase()->one(
            'SELECT * FROM sale_cart_lines WHERE cart_id = ? AND line_key = ? LIMIT 1',
            [$cartId, $lineKey]
        );
        if ($existing !== null) {
            $newQuantity = (int) $existing['quantity'] + $quantity;
            $totals = $this->lineTotals($amounts, $newQuantity);
            $this->rawDatabase()->run(
                'UPDATE sale_cart_lines
                 SET quantity = ?, line_subtotal_minor = ?, line_discount_minor = ?,
                     line_tax_minor = ?, line_total_minor = ?, metadata_json = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?',
                [
                    $newQuantity,
                    $totals['line_subtotal_minor'],
                    $totals['line_discount_minor'],
                    $totals['line_tax_minor'],
                    $totals['line_total_minor'],
                    $this->json(['snapshot' => $snapshot]),
                    (int) $existing['id'],
                ]
            );
            return $this->requireLine((int) $existing['id']);
        }

        $totals = $this->lineTotals($amounts, $quantity);
        $this->rawDatabase()->run(
            'INSERT INTO sale_cart_lines(
                cart_id, line_key, business_product_id, business_variant_id, sku, barcode,
                product_name, variant_name, product_type, quantity, unit_price_minor,
                regular_unit_price_minor, unit_purchase_price_minor, currency, tax_class_id,
                tax_rate_basis_points, tax_included, line_subtotal_minor, line_discount_minor,
                line_tax_minor, line_total_minor, metadata_json
             ) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $cartId,
                $lineKey,
                (int) $snapshot['business_product_id'],
                (int) $snapshot['business_variant_id'],
                $snapshot['sku'] ?? null,
                $snapshot['barcode'] ?? null,
                (string) $snapshot['product_name'],
                $snapshot['variant_name'] ?? null,
                (string) $snapshot['product_type'],
                $quantity,
                $amounts['unit_price_minor'],
                $amounts['regular_unit_price_minor'],
                $snapshot['unit_purchase_price_minor'] ?? null,
                (string) $snapshot['currency'],
                $snapshot['tax_class_id'] ?? null,
                $amounts['tax_rate_basis_points'],
                $amounts['tax_included'] ? 1 : 0,
                $totals['line_subtotal_minor'],
                $totals['line_discount_minor'],
                $totals['line_tax_minor'],
                $totals['line_total_minor'],
                $this->json(['snapshot' => $snapshot]),
            ]
        );
        return $this->requireLine((int) $this->rawDatabase()->lastInsertId());
    }

    /** @return array<string,mixed> */
    public function requireLine(int $lineId): array
    {
        $row = $this->rawDatabase()->one('SELECT * FROM sale_cart_lines WHERE id = ? LIMIT 1', [$lineId]);
        if ($row === null) {
            throw new SaleValidationException('sale.cart_line_not_found');
        }
        return $row;
    }

    /** @return array{subtotal_minor:int,discount_total_minor:int,tax_total_minor:int,grand_total_minor:int} */
    public function recalculateTotals(int $cartId): array
    {
        $totals = $this->rawDatabase()->one(
            'SELECT
                COALESCE(SUM(line_subtotal_minor), 0) AS subtotal_minor,
                COALESCE(SUM(line_discount_minor), 0) AS discount_total_minor,
                COALESCE(SUM(line_tax_minor), 0) AS tax_total_minor,
                COALESCE(SUM(line_total_minor), 0) AS grand_total_minor
             FROM sale_cart_lines WHERE cart_id = ?',
            [$cartId]
        ) ?? [];
        $payload = [
            'subtotal_minor' => max(0, (int) ($totals['subtotal_minor'] ?? 0)),
            'discount_total_minor' => max(0, (int) ($totals['discount_total_minor'] ?? 0)),
            'tax_total_minor' => max(0, (int) ($totals['tax_total_minor'] ?? 0)),
            'grand_total_minor' => max(0, (int) ($totals['grand_total_minor'] ?? 0)),
        ];
        $this->rawDatabase()->run(
            'UPDATE sale_carts
             SET subtotal_minor = ?, discount_total_minor = ?, tax_total_minor = ?,
                 grand_total_minor = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?',
            [$payload['subtotal_minor'], $payload['discount_total_minor'], $payload['tax_total_minor'], $payload['grand_total_minor'], $cartId]
        );
        return $payload;
    }

    public function markConverted(int $cartId, int $orderId): void
    {
        $this->rawDatabase()->run(
            'UPDATE sale_carts SET status = "converted", converted_order_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
            [$orderId, $cartId]
        );
    }

    public function updateLineQuantity(int $cartId, int $lineId, int $quantity): array
    {
        if ($quantity < 1) {
            throw new SaleValidationException('sale.quantity_invalid');
        }
        $line = $this->requireLine($lineId);
        if ((int) $line['cart_id'] !== $cartId) {
            throw new SaleValidationException('sale.cart_line_not_found');
        }
        $totals = $this->lineTotals([
            'regular_unit_price_minor' => (int) $line['regular_unit_price_minor'],
            'unit_price_minor' => (int) $line['unit_price_minor'],
            'tax_rate_basis_points' => (int) $line['tax_rate_basis_points'],
            'tax_included' => (bool) $line['tax_included'],
        ], $quantity);
        $this->rawDatabase()->run(
            'UPDATE sale_cart_lines
             SET quantity = ?, line_subtotal_minor = ?, line_discount_minor = ?,
                 line_tax_minor = ?, line_total_minor = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?',
            [$quantity, $totals['line_subtotal_minor'], $totals['line_discount_minor'], $totals['line_tax_minor'], $totals['line_total_minor'], $lineId]
        );
        $this->recalculateTotals($cartId);
        return $this->requireLine($lineId);
    }

    public function deleteLine(int $cartId, int $lineId): void
    {
        $line = $this->requireLine($lineId);
        if ((int) $line['cart_id'] !== $cartId) {
            throw new SaleValidationException('sale.cart_line_not_found');
        }
        $this->rawDatabase()->run('DELETE FROM sale_cart_lines WHERE id = ?', [$lineId]);
        $this->recalculateTotals($cartId);
    }

    /** @param array<string,int|bool> $amounts @return array<string,int> */
    private function lineTotals(array $amounts, int $quantity): array
    {
        $regular = (int) $amounts['regular_unit_price_minor'];
        $unit = (int) $amounts['unit_price_minor'];
        $subtotal = $regular * $quantity;
        $lineTotal = $unit * $quantity;
        $discount = max(0, $subtotal - $lineTotal);
        $rate = (int) $amounts['tax_rate_basis_points'];
        $tax = (bool) $amounts['tax_included']
            ? (int) round($lineTotal * $rate / (10000 + $rate))
            : (int) round($lineTotal * $rate / 10000);
        if (!(bool) $amounts['tax_included']) {
            $lineTotal += $tax;
        }
        return [
            'line_subtotal_minor' => max(0, $subtotal),
            'line_discount_minor' => $discount,
            'line_tax_minor' => max(0, $tax),
            'line_total_minor' => max(0, $lineTotal),
        ];
    }
}
