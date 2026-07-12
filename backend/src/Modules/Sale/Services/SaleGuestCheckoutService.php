<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Modules\Sale\Exceptions\SaleInventoryException;
use App\Modules\Sale\Exceptions\SaleValidationException;
use App\Modules\Sale\Pricing\SalePricingService;
use App\Modules\Sale\Repositories\SaleCartRepository;
use App\Modules\Sale\Repositories\SaleChannelRepository;

final class SaleGuestCheckoutService
{
    private const STEPS = ['identity', 'addresses', 'delivery', 'review', 'validated'];

    public function __construct(
        private readonly SaleDatabaseConnection $connection,
        private readonly SaleCartRepository $carts,
        private readonly SaleChannelRepository $channels,
        private readonly SaleCatalogSnapshotService $catalog,
        private readonly SalePricingService $pricing,
        private readonly SaleInventoryService $inventory,
        private readonly SaleStateMachineService $states,
    ) {}

    /** @param array<string,mixed> $payload @return array{cart:array<string,mixed>,price_changed:bool} */
    public function update(int $cartId, array $payload, bool $forPlacement = false): array
    {
        $cart = $this->carts->requireCart($cartId);
        if ((string) $cart['status'] !== 'active') {
            throw new SaleValidationException('sale.checkout.cart_not_active');
        }
        $step = $forPlacement ? 'validated' : strtolower(trim((string) ($payload['step'] ?? 'review')));
        if (!in_array($step, self::STEPS, true)) {
            throw new SaleValidationException('sale.checkout.step_invalid');
        }

        $identity = $this->identity($this->object($payload['identity'] ?? null, $cart['customer_snapshot_json']));
        $billing = $this->address($this->object($payload['billing_address'] ?? null, $cart['billing_address_json']));
        $shipping = $this->address($this->object($payload['shipping_address'] ?? null, $cart['shipping_address_json']));
        if (($payload['shipping_same_as_billing'] ?? false) === true) {
            $shipping = $billing;
        }
        $shippingMethod = $this->shippingMethod($payload['shipping_method'] ?? null, $cart['shipping_method_snapshot_json']);
        $paymentMethod = $this->paymentMethod($payload['payment'] ?? $payload['payment_method'] ?? null, $cart['payment_method_snapshot_json']);
        $terms = array_key_exists('terms_accepted', $payload) ? $payload['terms_accepted'] === true : (bool) ($cart['terms_accepted'] ?? false);
        if (array_key_exists('marketing_consent', $payload) && !is_bool($payload['marketing_consent'])) {
            throw new SaleValidationException('sale.checkout.marketing_consent_invalid');
        }
        $marketing = array_key_exists('marketing_consent', $payload)
            ? $payload['marketing_consent']
            : (($cart['marketing_consent'] ?? null) === null ? null : (bool) $cart['marketing_consent']);

        if (in_array($step, ['identity','addresses','delivery','review','validated'], true)) {
            $this->validateIdentity($identity);
        }
        if (in_array($step, ['addresses','delivery','review','validated'], true)) {
            $this->validateAddress($billing, 'billing');
            $this->validateAddress($shipping, 'shipping');
        }
        if (in_array($step, ['delivery','review','validated'], true)) {
            $this->requireMethod($shippingMethod, 'sale.checkout.shipping_method_required');
        }

        $priceChanged = false;
        if (in_array($step, ['review','validated'], true)) {
            $priceChanged = $this->revalidateLines($cart);
            $this->requireMethod($paymentMethod, 'sale.checkout.payment_method_required');
        }
        if ($step === 'validated' && !$terms) {
            throw new SaleValidationException('sale.checkout.terms_required');
        }

        $saved = $this->carts->saveGuestCheckout(
            $cartId, $identity, $billing, $shipping, $shippingMethod, $paymentMethod,
            $terms, $marketing, $step, $step === 'validated'
        );
        return ['cart' => $this->carts->cartWithLines((int) $saved['id']), 'price_changed' => $priceChanged];
    }

    /** @return array<string,mixed> */
    public function abandon(int $cartId): array
    {
        return $this->db()->transaction(function () use ($cartId): array {
            $cart = $this->carts->requireCart($cartId);
            if ((string) $cart['status'] === 'abandoned') {
                return $cart;
            }
            $this->inventory->releaseCartReservations($cartId, 'guest checkout abandoned');
            $updated = $this->states->transition('cart', $cartId, 'abandoned', null, 'guest checkout abandoned');
            $this->db()->run('UPDATE sale_carts SET abandoned_at=CURRENT_TIMESTAMP WHERE id=?', [$cartId]);
            return $updated;
        });
    }

    public function expire(int $cartId): void
    {
        $this->db()->transaction(function () use ($cartId): void {
            $cart = $this->carts->requireCart($cartId);
            if ((string) $cart['status'] !== 'active') {
                return;
            }
            $this->inventory->releaseCartReservations($cartId, 'guest cart expired');
            $this->states->transition('cart', $cartId, 'expired', null, 'guest cart expired');
        });
    }

    /** @param array<string,mixed> $cart */
    private function revalidateLines(array $cart): bool
    {
        $lines = $this->carts->lines((int) $cart['id']);
        if ($lines === []) {
            throw new SaleValidationException('sale.cart_empty');
        }
        $channel = $this->channels->requireChannel((int) $cart['site_id'], (int) $cart['channel_id']);
        $changed = false;
        foreach ($lines as $line) {
            try {
                $snapshot = $this->catalog->snapshotForVariant(
                    (int) $cart['site_id'], (int) $line['business_variant_id'],
                    $this->channels->channelCodeForCatalog($channel), true, ['currency' => (string) $cart['currency']]
                );
            } catch (\InvalidArgumentException) {
                throw new SaleValidationException('sale.checkout.product_unavailable');
            }
            $amounts = $this->pricing->lineAmounts($snapshot);
            $changed = $changed || (int) $amounts['unit_price_minor'] !== (int) $line['unit_price_minor']
                || (int) $amounts['tax_rate_basis_points'] !== (int) $line['tax_rate_basis_points'];
            if ((bool) ($snapshot['track_stock'] ?? false)) {
                $reserved = (int) ($this->db()->one(
                    'SELECT COALESCE(SUM(r.quantity),0) AS total FROM sale_stock_reservations r
                     INNER JOIN sale_inventory_items i ON i.id=r.inventory_item_id
                     WHERE r.cart_id=? AND i.business_variant_id=? AND r.status=\'active\'',
                    [(int) $cart['id'], (int) $line['business_variant_id']]
                )['total'] ?? 0);
                if ($reserved < (int) $line['quantity']) {
                    throw new SaleInventoryException('sale.checkout.product_unavailable');
                }
            }
            $this->carts->refreshLineSnapshot((int) $line['id'], $snapshot, $amounts);
        }
        $this->carts->recalculateTotals((int) $cart['id']);
        return $changed;
    }

    /** @param array<string,mixed> $identity */
    private function validateIdentity(array $identity): void
    {
        $email = strtolower(trim((string) ($identity['email'] ?? '')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            throw new SaleValidationException('sale.checkout.email_invalid');
        }
        foreach (['first_name', 'last_name'] as $field) {
            $value = trim((string) ($identity[$field] ?? ''));
            if ($value === '' || mb_strlen($value) > 100) {
                throw new SaleValidationException('sale.checkout.' . $field . '_invalid');
            }
        }
        $phone = trim((string) ($identity['phone'] ?? ''));
        if ($phone !== '' && (mb_strlen($phone) > 40 || preg_match('/^[0-9+().\s-]+$/', $phone) !== 1)) {
            throw new SaleValidationException('sale.checkout.phone_invalid');
        }
    }

    /** @param array<string,mixed> $address */
    private function validateAddress(array $address, string $kind): void
    {
        foreach (['line1', 'postal_code', 'city', 'country_code'] as $field) {
            if (trim((string) ($address[$field] ?? '')) === '') {
                throw new SaleValidationException('sale.checkout.' . $kind . '_address_invalid');
            }
        }
        if (preg_match('/^[A-Z]{2}$/', strtoupper((string) $address['country_code'])) !== 1) {
            throw new SaleValidationException('sale.checkout.' . $kind . '_address_invalid');
        }
    }

    /** @return array<string,mixed> */
    private function shippingMethod(mixed $input, string $stored): array
    {
        $current = $this->object($input, $stored);
        $code = strtolower(trim((string) ($current['code'] ?? '')));
        return match ($code) {
            'standard' => ['code' => 'standard', 'label' => 'Livraison standard', 'amount_minor' => 0],
            'pickup' => ['code' => 'pickup', 'label' => 'Retrait', 'amount_minor' => 0],
            default => [],
        };
    }

    /** @return array<string,mixed> */
    private function paymentMethod(mixed $input, string $stored): array
    {
        $current = $this->object($input, $stored);
        if (is_string($input)) {
            $current = ['code' => $input];
        }
        $code = strtolower(trim((string) ($current['code'] ?? '')));
        return match ($code) {
            'bank_transfer' => ['code' => 'bank_transfer', 'label' => 'Virement bancaire', 'requires_online_secret' => false],
            'manual' => ['code' => 'manual', 'label' => 'Paiement à confirmer', 'requires_online_secret' => false],
            default => [],
        };
    }

    /** @return array<string,mixed> */
    private function object(mixed $input, string $stored): array
    {
        if (is_array($input)) {
            return $input;
        }
        $decoded = json_decode($stored, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function identity(array $value): array
    {
        return [
            'email' => strtolower(trim((string) ($value['email'] ?? ''))),
            'first_name' => trim((string) ($value['first_name'] ?? '')),
            'last_name' => trim((string) ($value['last_name'] ?? '')),
            'phone' => trim((string) ($value['phone'] ?? '')) ?: null,
            'guest' => true,
        ];
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function address(array $value): array
    {
        return [
            'line1' => trim((string) ($value['line1'] ?? '')),
            'line2' => trim((string) ($value['line2'] ?? '')) ?: null,
            'postal_code' => trim((string) ($value['postal_code'] ?? '')),
            'city' => trim((string) ($value['city'] ?? '')),
            'region' => trim((string) ($value['region'] ?? '')) ?: null,
            'country_code' => strtoupper(trim((string) ($value['country_code'] ?? ''))),
        ];
    }

    /** @param array<string,mixed> $value */
    private function requireMethod(array $value, string $error): void
    {
        if ($value === []) {
            throw new SaleValidationException($error);
        }
    }

    private function db(): \App\Core\Database
    {
        return $this->connection->database() ?? throw new SaleValidationException('sale.database_unavailable');
    }
}
