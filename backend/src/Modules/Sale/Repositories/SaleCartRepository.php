<?php

declare(strict_types=1);

namespace App\Modules\Sale\Repositories;

use App\Modules\Sale\Exceptions\SaleValidationException;
use App\Modules\Sale\Pricing\SalePricingService;
use App\Modules\Sale\Services\SaleDatabaseConnection;

final class SaleCartRepository extends SaleRepositoryBase
{
    private SalePricingService $pricing;

    public function __construct(SaleDatabaseConnection $connection, ?SalePricingService $pricing = null)
    {
        parent::__construct($connection);
        $this->pricing = $pricing ?? new SalePricingService();
    }

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
                site_id, channel_id, cart_kind, locale, customer_ref_id, register_session_id,
                status, currency, customer_company_id, customer_contact_id,
                customer_snapshot_json, billing_address_json, shipping_address_json, created_by_iam_user_id, expires_at
             ) VALUES(?, ?, ?, ?, ?, ?, \'active\', ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $siteId,
                $channelId,
                (string) ($payload['cart_kind'] ?? 'admin'),
                strtolower((string) ($payload['locale'] ?? 'fr')),
                $payload['customer_ref_id'] ?? null,
                $payload['register_session_id'] ?? null,
                strtoupper((string) ($payload['currency'] ?? 'CHF')),
                $payload['customer_company_id'] ?? null,
                $payload['customer_contact_id'] ?? null,
                $this->json($payload['customer_snapshot'] ?? []),
                $this->json($payload['billing_address'] ?? []),
                $this->json($payload['shipping_address'] ?? []),
                $payload['iam_user_id'] ?? null,
                $payload['expires_at'] ?? null,
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
        $row['cart_id'] = (int) $row['id'];
        $row['public_token_hash'] = $row['public_token_hash'] ?? $row['cart_token_hash'] ?? null;
        $row['customer_ref'] = $row['customer_ref_id'] ?? $row['customer_contact_id'] ?? $row['customer_company_id'] ?? null;
        return $row;
    }

    public function attachPublicToken(int $cartId, string $tokenHash, string $expiresAt): void
    {
        $cart = $this->requireCart($cartId);
        if (($cart['cart_kind'] ?? null) !== 'web') throw new SaleValidationException('sale.cart_public_token_forbidden');
        $this->rawDatabase()->run('UPDATE sale_carts SET cart_token_hash=?,public_token_hash=?,expires_at=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$tokenHash,$tokenHash,$expiresAt,$cartId]);
    }

    /** @template T @param callable(array<string,mixed>):T $mutation @return T */
    public function withOptimisticLock(int $cartId, ?int $expectedVersion, callable $mutation): mixed
    {
        return $this->rawDatabase()->transaction(function () use ($cartId,$expectedVersion,$mutation): mixed {
            $cart=$this->requireCart($cartId); $current=(int)$cart['version'];
            if ($expectedVersion!==null && $expectedVersion!==$current) throw new SaleValidationException('sale.cart_version_conflict');
            $result=$mutation($cart);
            $stmt=$this->rawDatabase()->pdo()->prepare('UPDATE sale_carts SET version=version+1,updated_at=CURRENT_TIMESTAMP WHERE id=? AND version=?');
            $stmt->execute([$cartId,$current]);
            if ($stmt->rowCount()!==1) throw new SaleValidationException('sale.cart_version_conflict');
            return $result;
        });
    }

    public function claimVersion(int $cartId, ?int $expectedVersion): int
    {
        $cart=$this->requireCart($cartId); $current=(int)$cart['version'];
        if ($expectedVersion!==null && $expectedVersion!==$current) throw new SaleValidationException('sale.cart_version_conflict');
        $stmt=$this->rawDatabase()->pdo()->prepare('UPDATE sale_carts SET version=version+1,updated_at=CURRENT_TIMESTAMP WHERE id=? AND version=?');
        $stmt->execute([$cartId,$current]);
        if ($stmt->rowCount()!==1) throw new SaleValidationException('sale.cart_version_conflict');
        return $current+1;
    }

    /** @return array<string,mixed> */
    public function cartWithLines(int $cartId): array
    {
        $cart = $this->requireCart($cartId);
        $cart['lines'] = $this->lines($cartId);
        $cart['adjustments'] = $this->adjustments($cartId);
        return $cart;
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    public function linkCustomer(int $cartId, ?int $companyId, ?int $contactId, array $snapshot): array
    {
        $cart = $this->requireCart($cartId);
        if ((string) $cart['status'] !== 'active') {
            throw new SaleValidationException('sale.cart_not_convertible');
        }
        if (($companyId ?? 0) < 1 && ($contactId ?? 0) < 1) {
            throw new SaleValidationException('sale.customer_reconciliation_target_required');
        }
        $displayName = trim((string) ($snapshot['display_name'] ?? $snapshot['name'] ?? ''));
        if ($displayName === '') {
            throw new SaleValidationException('sale.customer_display_name_required');
        }
        $snapshot = [
            'display_name' => $displayName,
            'email' => trim((string) ($snapshot['email'] ?? '')) ?: null,
            'phone' => trim((string) ($snapshot['phone'] ?? '')) ?: null,
            'company_name' => trim((string) ($snapshot['company_name'] ?? '')) ?: null,
            'company_id' => ($companyId ?? 0) > 0 ? $companyId : null,
            'contact_id' => ($contactId ?? 0) > 0 ? $contactId : null,
        ];
        $customerRef = $this->rawDatabase()->one(
            'SELECT id FROM sale_customer_refs WHERE site_id=? AND company_id IS ? AND contact_id IS ? ORDER BY id LIMIT 1',
            [(int) $cart['site_id'], $snapshot['company_id'], $snapshot['contact_id']]
        );
        if ($customerRef === null) {
            $this->rawDatabase()->run(
                'INSERT INTO sale_customer_refs(site_id,company_id,contact_id,display_name,email,phone) VALUES(?,?,?,?,?,?)',
                [(int) $cart['site_id'], $snapshot['company_id'], $snapshot['contact_id'], $displayName, $snapshot['email'], $snapshot['phone']]
            );
            $customerRefId = (int) $this->rawDatabase()->lastInsertId();
        } else {
            $customerRefId = (int) $customerRef['id'];
            $this->rawDatabase()->run(
                'UPDATE sale_customer_refs SET display_name=?,email=?,phone=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [$displayName, $snapshot['email'], $snapshot['phone'], $customerRefId]
            );
        }
        $this->rawDatabase()->run(
            'UPDATE sale_carts SET customer_ref_id=?,customer_company_id=?,customer_contact_id=?,customer_snapshot_json=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [$customerRefId, $snapshot['company_id'], $snapshot['contact_id'], $this->json($snapshot), $cartId]
        );
        return $this->requireCart($cartId);
    }

    /** @param array<string,mixed> $identity @param array<string,mixed> $billing @param array<string,mixed> $shipping @param array<string,mixed> $shippingMethod @param array<string,mixed> $paymentMethod */
    public function saveGuestCheckout(
        int $cartId,
        array $identity,
        array $billing,
        array $shipping,
        array $shippingMethod,
        array $paymentMethod,
        bool $termsAccepted,
        ?bool $marketingConsent,
        string $step,
        bool $validated,
        ?int $expectedVersion = null
    ): array {
        $current = $this->requireCart($cartId);
        $version=(int)$current['version'];
        if ($expectedVersion!==null && $expectedVersion!==$version) throw new SaleValidationException('sale.cart_version_conflict');
        $termsAcceptedAt = $termsAccepted ? ((string) ($current['terms_accepted_at'] ?? '') ?: gmdate('Y-m-d H:i:s')) : null;
        $marketingConsentAt = $marketingConsent === null ? null : ((string) ($current['marketing_consent_at'] ?? '') ?: gmdate('Y-m-d H:i:s'));
        $stmt=$this->rawDatabase()->pdo()->prepare(
            'UPDATE sale_carts SET
                customer_snapshot_json=?, billing_address_json=?, shipping_address_json=?,
                shipping_method_snapshot_json=?, payment_method_snapshot_json=?, checkout_step=?,
                terms_accepted=?, terms_accepted_at=?, marketing_consent=?, marketing_consent_at=?,
                checkout_validated_at=?,
                updated_at=CURRENT_TIMESTAMP, version=version+1
             WHERE id=? AND status=\'active\' AND version=?');
        $stmt->execute([
                $this->json($identity), $this->json($billing), $this->json($shipping),
                $this->json($shippingMethod), $this->json($paymentMethod), $step,
                $termsAccepted ? 1 : 0, $termsAcceptedAt,
                $marketingConsent === null ? null : ($marketingConsent ? 1 : 0),
                $marketingConsentAt,
                $validated ? gmdate('Y-m-d H:i:s') : null, $cartId,$version,
            ]);
        if ($stmt->rowCount()!==1) throw new SaleValidationException('sale.cart_version_conflict');
        return $this->requireCart($cartId);
    }

    public function markCheckoutDirty(int $cartId): void
    {
        $this->rawDatabase()->run(
            "UPDATE sale_carts SET checkout_step='cart',checkout_validated_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='active'",
            [$cartId]
        );
    }

    /** @param array<string,mixed> $snapshot @param array<string,mixed> $amounts */
    public function refreshLineSnapshot(int $lineId, array $snapshot, array $amounts): array
    {
        $line = $this->requireLine($lineId);
        $priceChanged=(int)$line['unit_price_minor']!==(int)$amounts['unit_price_minor'];
        $totals = $this->pricing->lineTotals($amounts, (int) $line['quantity']);
        $this->rawDatabase()->run(
            'UPDATE sale_cart_lines SET sku=?,barcode=?,product_name=?,variant_name=?,product_type=?,
                unit_price_minor=?,regular_unit_price_minor=?,unit_purchase_price_minor=?,currency=?,tax_class_id=?,tax_class_code=?,
                tax_rate_basis_points=?,tax_included=?,line_subtotal_minor=?,line_discount_minor=?,line_tax_minor=?,line_total_minor=?,
                availability_state=?,previous_unit_price_minor=?,price_changed_at=?,
                calculation_version=calculation_version+1,metadata_json=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [
                $snapshot['sku'] ?? null, $snapshot['barcode'] ?? null, (string) $snapshot['product_name'], $snapshot['variant_name'] ?? null,
                (string) $snapshot['product_type'], (int) $amounts['unit_price_minor'], (int) $amounts['regular_unit_price_minor'],
                $snapshot['unit_purchase_price_minor'] ?? null, (string) $snapshot['currency'], $snapshot['tax_class_id'] ?? null,
                (string) ($amounts['tax_class_code'] ?? 'standard'),
                (int) $amounts['tax_rate_basis_points'], (int) (bool) $amounts['tax_included'],
                $totals['line_subtotal_minor'], $totals['line_discount_minor'], $totals['line_tax_minor'], $totals['line_total_minor'],
                (string) ($snapshot['availability_state'] ?? 'available'),
                $priceChanged ? (int) $line['unit_price_minor'] : ($line['previous_unit_price_minor'] ?? null),
                $priceChanged ? gmdate('Y-m-d H:i:s') : ($line['price_changed_at'] ?? null),
                $this->lineMetadata($snapshot, $totals), $lineId,
            ]
        );
        return $this->requireLine($lineId);
    }

    /** @return list<array<string,mixed>> */
    public function lines(int $cartId): array
    {
        return $this->rawDatabase()->all('SELECT * FROM sale_cart_lines WHERE cart_id = ? ORDER BY id ASC', [$cartId]);
    }

    /** @return list<array<string,mixed>> */
    public function adjustments(int $cartId): array
    {
        return $this->rawDatabase()->all('SELECT * FROM sale_cart_adjustments WHERE cart_id = ? ORDER BY id ASC', [$cartId]);
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    public function addOrIncrementLine(int $cartId, array $snapshot, int $quantity, array $amounts): array
    {
        $options=$snapshot['line_options']??[]; $personalization=$snapshot['personalization']??[];
        $lineKey = 'sellable:' . (int) ($snapshot['sellable_id']??$snapshot['business_variant_id']) . ':' . substr(hash('sha256',$this->json([$options,$personalization])),0,12);
        $existing = $this->rawDatabase()->one(
            'SELECT * FROM sale_cart_lines WHERE cart_id = ? AND line_key = ? LIMIT 1',
            [$cartId, $lineKey]
        );
        if ($existing !== null) {
            $newQuantity = (int) $existing['quantity'] + $quantity;
            $totals = $this->pricing->lineTotals($amounts, $newQuantity);
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
                    $this->lineMetadata($snapshot, $totals),
                    (int) $existing['id'],
                ]
            );
            return $this->requireLine((int) $existing['id']);
        }

        $totals = $this->pricing->lineTotals($amounts, $quantity);
        $this->rawDatabase()->run(
            'INSERT INTO sale_cart_lines(
                cart_id, line_key, business_product_id, business_variant_id, sellable_id, sku, barcode,
                product_name, variant_name, product_type, quantity, unit_price_minor,
                regular_unit_price_minor, unit_purchase_price_minor, currency, tax_class_id, tax_class_code,
                tax_rate_basis_points, tax_included, line_subtotal_minor, line_discount_minor,
                line_tax_minor, line_total_minor, metadata_json, options_json, personalization_json,
                fulfillment_class, availability_state, calculation_version
             ) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $cartId,
                $lineKey,
                (int) $snapshot['business_product_id'],
                (int) $snapshot['business_variant_id'],
                (int) ($snapshot['sellable_id'] ?? $snapshot['business_variant_id']),
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
                (string) ($amounts['tax_class_code'] ?? 'standard'),
                $amounts['tax_rate_basis_points'],
                $amounts['tax_included'] ? 1 : 0,
                $totals['line_subtotal_minor'],
                $totals['line_discount_minor'],
                $totals['line_tax_minor'],
                $totals['line_total_minor'],
                $this->lineMetadata($snapshot, $totals),
                $this->json(is_array($options)?$options:[]),
                $this->json(is_array($personalization)?$personalization:[]),
                (string) ($snapshot['fulfillment_class']??'shipping'),
                (string) ($snapshot['availability_state']??'available'),
                (int) ($snapshot['calculation_version']??1),
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

    /** @return array{subtotal_minor:int,discount_total_minor:int,tax_total_minor:int,shipping_total_minor:int,grand_total_minor:int,surcharge_total_minor:int,tax_lines:list<array<string,mixed>>,adjustments:list<array<string,mixed>>} */
    public function recalculateTotals(int $cartId, ?int $shippingTotalMinor = null): array
    {
        $lines = $this->lines($cartId);
        if ($shippingTotalMinor === null) {
            $cart = $this->requireCart($cartId);
            $snapshot = json_decode((string) ($cart['shipping_method_snapshot_json'] ?? '{}'), true);
            $shippingTotalMinor = is_array($snapshot) ? max(0, (int) ($snapshot['amount_minor'] ?? 0)) : 0;
        }
        $payload = $this->pricing->cartTotals($lines, max(0, $shippingTotalMinor), $this->normalizedCartAdjustments($cartId, $lines));
        $this->rawDatabase()->run(
            'UPDATE sale_carts
             SET subtotal_minor = ?, discount_total_minor = ?, tax_total_minor = ?, shipping_total_minor = ?,
                 grand_total_minor = ?, calculation_version=calculation_version+1, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?',
            [$payload['subtotal_minor'], $payload['discount_total_minor'], $payload['tax_total_minor'], $payload['shipping_total_minor'], $payload['grand_total_minor'], $cartId]
        );
        return $payload;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function setManualCartAdjustment(int $cartId, array $payload): array
    {
        $cart = $this->requireCart($cartId);
        if ((string) $cart['status'] !== 'active') {
            throw new SaleValidationException('sale.cart_not_active');
        }
        $rawValue = (float) ($payload['value'] ?? 0);
        $kind = (string) ($payload['kind'] ?? $payload['adjustment_type'] ?? ($rawValue > 0.0 ? 'surcharge' : 'manual_discount'));
        $kind = in_array($kind, ['surcharge', 'add', 'addition'], true) ? 'surcharge' : 'manual_discount';
        $mode = (string) ($payload['mode'] ?? $payload['value_type'] ?? 'amount');
        $mode = $mode === 'percent' ? 'percent' : 'amount';
        $value = abs($rawValue);

        $this->rawDatabase()->run(
            'DELETE FROM sale_cart_adjustments WHERE cart_id = ? AND cart_line_id IS NULL AND source_type = \'manual\'',
            [$cartId]
        );

        if ($value > 0.0) {
            $baseMinor = $this->cartAdjustmentBaseMinor($this->lines($cartId));
            $amountMinor = $mode === 'percent'
                ? $this->divideRounded($baseMinor * (int) round($value * 100), 10000)
                : (int) round($value * 100);
            if ($amountMinor > 0) {
                $this->rawDatabase()->run(
                    'INSERT INTO sale_cart_adjustments(cart_id, cart_line_id, adjustment_type, source_type, source_id, label, amount_minor, currency, metadata_json)
                     VALUES(?, NULL, ?, \'manual\', NULL, ?, ?, ?, ?)',
                    [
                        $cartId,
                        $kind,
                        $kind === 'surcharge' ? 'Ajout POS' : 'Remise POS',
                        $amountMinor,
                        (string) $cart['currency'],
                        $this->json([
                            'scope' => 'pos_total',
                            'mode' => $mode,
                            'value' => $value,
                            'basis_points' => $mode === 'percent' ? (int) round($value * 100) : null,
                        ]),
                    ]
                );
            }
        }

        $this->recalculateTotals($cartId);
        return $this->cartWithLines($cartId);
    }

    public function markConverted(int $cartId, int $orderId): void
    {
        $this->rawDatabase()->run(
            'UPDATE sale_carts SET status = \'converted\', converted_order_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
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
        $totals = $this->pricing->lineTotals([
            'regular_unit_price_minor' => (int) $line['regular_unit_price_minor'],
            'unit_price_minor' => (int) $line['unit_price_minor'],
            'tax_rate_basis_points' => (int) $line['tax_rate_basis_points'],
            'tax_class_code' => (string) ($line['tax_class_code'] ?? 'standard'),
            'tax_included' => (bool) $line['tax_included'],
        ], $quantity);
        $this->rawDatabase()->run(
            'UPDATE sale_cart_lines
             SET quantity = ?, line_subtotal_minor = ?, line_discount_minor = ?,
                 line_tax_minor = ?, line_total_minor = ?, metadata_json = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?',
            [
                $quantity,
                $totals['line_subtotal_minor'],
                $totals['line_discount_minor'],
                $totals['line_tax_minor'],
                $totals['line_total_minor'],
                $this->lineMetadataFromExisting($line, $totals),
                $lineId,
            ]
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

    /** @param array<string,mixed> $snapshot @param array<string,mixed> $totals */
    private function lineMetadata(array $snapshot, array $totals): string
    {
        return $this->json([
            'snapshot' => $snapshot,
            'pricing' => $this->pricingMetadata($totals),
        ]);
    }

    /** @param array<string,mixed> $line @param array<string,mixed> $totals */
    private function lineMetadataFromExisting(array $line, array $totals): string
    {
        $metadata = json_decode((string) ($line['metadata_json'] ?? '{}'), true);
        if (!is_array($metadata)) {
            $metadata = [];
        }
        $metadata['pricing'] = $this->pricingMetadata($totals);
        return $this->json($metadata);
    }

    /** @param array<string,mixed> $totals @return array<string,mixed> */
    private function pricingMetadata(array $totals): array
    {
        return [
            'taxable_amount_minor' => (int) ($totals['taxable_amount_minor'] ?? 0),
            'tax_lines' => $totals['tax_lines'] ?? [],
            'adjustments' => $totals['adjustments'] ?? [],
            'rounding' => 'integer_half_up',
        ];
    }

    /** @param list<array<string,mixed>> $lines @return list<array<string,mixed>> */
    private function normalizedCartAdjustments(int $cartId, array $lines): array
    {
        $adjustments = $this->adjustments($cartId);
        $baseMinor = $this->cartAdjustmentBaseMinor($lines);
        foreach ($adjustments as &$adjustment) {
            $metadata = json_decode((string) ($adjustment['metadata_json'] ?? '{}'), true);
            if (!is_array($metadata) || ($metadata['mode'] ?? null) !== 'percent') {
                continue;
            }
            $basisPoints = max(0, (int) ($metadata['basis_points'] ?? 0));
            $amountMinor = $this->divideRounded($baseMinor * $basisPoints, 10000);
            if ($amountMinor !== (int) ($adjustment['amount_minor'] ?? 0)) {
                $this->rawDatabase()->run('UPDATE sale_cart_adjustments SET amount_minor = ? WHERE id = ?', [$amountMinor, (int) $adjustment['id']]);
                $adjustment['amount_minor'] = $amountMinor;
            }
        }
        unset($adjustment);
        return $adjustments;
    }

    /** @param list<array<string,mixed>> $lines */
    private function cartAdjustmentBaseMinor(array $lines): int
    {
        $baseMinor = 0;
        foreach ($lines as $line) {
            $baseMinor += max(0, (int) ($line['line_total_minor'] ?? 0));
        }
        return $baseMinor;
    }

    private function divideRounded(int $numerator, int $denominator): int
    {
        if ($denominator <= 0 || $numerator <= 0) {
            return 0;
        }
        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }
}
