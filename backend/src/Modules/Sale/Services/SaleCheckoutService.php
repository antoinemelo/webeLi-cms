<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Modules\Sale\Exceptions\SaleValidationException;
use App\Modules\Sale\Repositories\SaleCartRepository;
use App\Modules\Sale\Repositories\SaleOrderRepository;

final class SaleCheckoutService
{
    public function __construct(
        private readonly SaleDatabaseConnection $connection,
        private readonly SaleCartRepository $carts,
        private readonly SaleOrderRepository $orders,
        private readonly SaleInventoryService $inventory,
        private readonly SaleEventService $events,
        private readonly SaleIdempotencyService $idempotency
    ) {}

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function placeOrder(int $cartId, array $payload = []): array
    {
        $cart = $this->carts->requireCart($cartId);
        $request = ['cart_id' => $cartId, 'source' => $payload['source'] ?? 'admin'];
        return $this->idempotency->run((int) $cart['site_id'], 'checkout.place_order', $payload['idempotency_key'] ?? null, $request, function () use ($cartId, $payload): array {
            $db = $this->connection->database();
            if ($db === null) {
                throw new SaleValidationException('sale.database_unavailable');
            }
            return $db->transaction(function () use ($cartId, $payload): array {
                $cart = $this->carts->requireCart($cartId);
                if ((string) $cart['status'] !== 'active') {
                    throw new SaleValidationException('sale.cart_not_convertible');
                }
                $lines = $this->carts->lines($cartId);
                if ($lines === []) {
                    throw new SaleValidationException('sale.cart_empty');
                }
                if ((int) $cart['grand_total_minor'] < 0) {
                    throw new SaleValidationException('sale.total_negative');
                }
                $order = $this->orders->createFromCart($cart, $lines, (string) ($payload['source'] ?? 'admin'), $this->carts->adjustments($cartId));
                $this->inventory->consumeCartReservations($cartId, (int) $order['id']);
                $this->carts->markConverted($cartId, (int) $order['id']);
                $this->events->emit((int) $cart['site_id'], 'sale.order.placed', 'order', (int) $order['id'], [
                    'site_id' => (int) $cart['site_id'],
                    'order_id' => (int) $order['id'],
                    'order_number' => (string) $order['order_number'],
                    'grand_total_minor' => (int) $order['grand_total_minor'],
                    'currency' => (string) $order['currency'],
                    'cart_id' => $cartId,
                    'customer_ref_id' => $order['customer_contact_id'] ?? $order['customer_company_id'] ?? null,
                    'payment_status' => (string) $order['payment_status'],
                    'source' => (string) $order['source'],
                    'iam_user_id' => $payload['iam_user_id'] ?? null,
                ], $payload['iam_user_id'] ?? null);
                return $order;
            });
        });
    }
}
