<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Modules\Sale\Exceptions\SaleValidationException;
use App\Modules\Sale\Pricing\SalePricingService;
use App\Modules\Sale\Repositories\SaleCartRepository;
use App\Modules\Sale\Repositories\SaleChannelRepository;

final class SaleCartService
{
    public function __construct(
        private readonly SaleCartRepository $carts,
        private readonly SaleChannelRepository $channels,
        private readonly SaleCatalogSnapshotService $catalog,
        private readonly SalePricingService $pricing,
        private readonly SaleInventoryService $inventory,
        private readonly SaleEventService $events,
        private readonly SaleIdempotencyService $idempotency
    ) {}

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function createCart(int $siteId, int $channelId, array $payload = []): array
    {
        $channel = $this->channels->requireChannel($siteId, $channelId);
        $cart = $this->carts->create($siteId, $channelId, ['currency' => $channel['currency'] ?? 'CHF'] + $payload);
        $this->events->emit($siteId, 'sale.cart.created', 'cart', (int) $cart['id'], [
            'site_id' => $siteId,
            'cart_id' => (int) $cart['id'],
            'channel_id' => $channelId,
            'source' => $payload['source'] ?? 'admin',
            'iam_user_id' => $payload['iam_user_id'] ?? null,
        ], $payload['iam_user_id'] ?? null);
        return $cart;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function addLine(int $cartId, int $businessVariantId, int $quantity, array $payload = []): array
    {
        if ($quantity < 1) {
            throw new SaleValidationException('sale.quantity_invalid');
        }
        $cart = $this->carts->requireCart($cartId);
        if ((string) $cart['status'] !== 'active') {
            throw new SaleValidationException('sale.cart_not_active');
        }
        $request = ['cart_id' => $cartId, 'business_variant_id' => $businessVariantId, 'quantity' => $quantity];
        $replayed = $this->idempotency->completed((int) $cart['site_id'], 'cart.add_line', $payload['idempotency_key'] ?? null, $request);
        if ($replayed !== null) {
            return $replayed;
        }

        $channel = $this->channels->requireChannel((int) $cart['site_id'], (int) $cart['channel_id']);
        $snapshot = $this->catalog->snapshotForVariant(
            (int) $cart['site_id'],
            $businessVariantId,
            $this->channels->channelCodeForCatalog($channel),
            true,
            [
                'currency' => (string) ($cart['currency'] ?? $channel['currency'] ?? 'CHF'),
                'customer_segment' => $payload['customer_segment'] ?? null,
            ]
        );
        $amounts = $this->pricing->lineAmounts($snapshot);
        $this->inventory->reserveForCart((int) $cart['site_id'], $cartId, $snapshot, $quantity);
        $line = $this->carts->addOrIncrementLine($cartId, $snapshot, $quantity, $amounts);
        $totals = $this->carts->recalculateTotals($cartId);
        $response = ['cart' => $this->carts->cartWithLines($cartId), 'line' => $line, 'totals' => $totals];
        $this->events->emit((int) $cart['site_id'], 'sale.cart.line_added', 'cart', $cartId, [
            'site_id' => (int) $cart['site_id'],
            'cart_id' => $cartId,
            'line_id' => (int) $line['id'],
            'business_variant_id' => $businessVariantId,
            'quantity' => $quantity,
            'sku' => $line['sku'] ?? $snapshot['sku'] ?? null,
            'unit_price_minor' => (int) $line['unit_price_minor'],
            'currency' => (string) $line['currency'],
            'iam_user_id' => $payload['iam_user_id'] ?? null,
        ], $payload['iam_user_id'] ?? null);
        $this->idempotency->complete((int) $cart['site_id'], 'cart.add_line', $payload['idempotency_key'] ?? null, $request, $response);
        return $response;
    }

    /** @return array<string,mixed> */
    public function updateLineQuantity(int $cartId, int $lineId, int $quantity): array
    {
        $cart = $this->carts->requireCart($cartId);
        if ((string) $cart['status'] !== 'active') {
            throw new SaleValidationException('sale.cart_not_active');
        }
        $existing = $this->carts->requireLine($lineId);
        if ((int) $existing['cart_id'] !== $cartId) {
            throw new SaleValidationException('sale.cart_line_not_found');
        }
        $metadata = json_decode((string) ($existing['metadata_json'] ?? '{}'), true);
        $snapshot = is_array($metadata) && is_array($metadata['snapshot'] ?? null) ? $metadata['snapshot'] : [];
        $allowBackorder = (bool) ($snapshot['allow_backorder'] ?? $snapshot['metadata']['allow_backorder'] ?? false);
        if ($quantity > (int) $existing['quantity']) {
            $this->inventory->syncCartLineReservation((int) $cart['site_id'], $cartId, (int) $existing['business_variant_id'], $quantity, $allowBackorder);
        }
        $line = $this->carts->updateLineQuantity($cartId, $lineId, $quantity);
        if ($quantity < (int) $existing['quantity']) {
            $this->inventory->syncCartLineReservation((int) $cart['site_id'], $cartId, (int) $line['business_variant_id'], $quantity, $allowBackorder);
        }
        return $line;
    }

    public function deleteLine(int $cartId, int $lineId): void
    {
        $cart = $this->carts->requireCart($cartId);
        if ((string) $cart['status'] !== 'active') {
            throw new SaleValidationException('sale.cart_not_active');
        }
        $line = $this->carts->requireLine($lineId);
        $this->carts->deleteLine($cartId, $lineId);
        $this->inventory->releaseCartVariantReservations($cartId, (int) $line['business_variant_id'], 'cart line deleted');
    }
}
