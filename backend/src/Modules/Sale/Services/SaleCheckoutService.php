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
        private readonly SaleIdempotencyService $idempotency,
        private readonly ?SaleStateMachineService $states = null
    ) {}

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function placeOrder(int $cartId, array $payload = []): array
    {
        $cart = $this->carts->requireCart($cartId);
        $request = ['cart_id' => $cartId, 'source' => $payload['source'] ?? 'admin'];
        if (isset($payload['request_fingerprint'])) {
            $request['request_fingerprint'] = (string) $payload['request_fingerprint'];
        }
        $correlationId = SaleStateMachineService::correlationId($payload['correlation_id'] ?? null);
        return $this->idempotency->run((int) $cart['site_id'], 'checkout.place_order', $payload['idempotency_key'] ?? null, $request, function () use ($cartId, $payload, $correlationId): array {
            $db = $this->connection->database();
            if ($db === null) {
                throw new SaleValidationException('sale.database_unavailable');
            }
            return $db->transaction(function () use ($cartId, $payload, $correlationId, $db): array {
                $cart = $this->carts->requireCart($cartId);
                if ((string) $cart['status'] !== 'active') {
                    throw new SaleValidationException('sale.cart_not_convertible');
                }
                if ((string) ($payload['source'] ?? 'admin') === 'ecommerce'
                    && ((string) ($cart['checkout_step'] ?? 'cart') !== 'validated' || empty($cart['checkout_validated_at']))) {
                    throw new SaleValidationException('sale.checkout.validation_required');
                }
                $lines = $this->carts->lines($cartId);
                if ($lines === []) {
                    throw new SaleValidationException('sale.cart_empty');
                }
                if ((int) $cart['grand_total_minor'] < 0) {
                    throw new SaleValidationException('sale.total_negative');
                }
                if (array_key_exists('shipping_method_snapshot', $payload) || array_key_exists('shipping_method', $payload)) {
                    $shippingMethod = $payload['shipping_method_snapshot'] ?? $payload['shipping_method'];
                    $cart['shipping_method_snapshot_json'] = json_encode(is_array($shippingMethod) ? $shippingMethod : ['label' => (string) $shippingMethod], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
                }
                $deferInventory = ($payload['defer_inventory_until_payment'] ?? false) === true;
                $this->inventory->prepareCartForCheckout($cart, $lines, true, 1800, $deferInventory ? 'order_placement' : 'payment_capture');
                $initialStatus = $deferInventory ? 'pending_payment' : 'placed';
                $order = $this->orders->createFromCart(
                    $cart,
                    $lines,
                    (string) ($payload['source'] ?? 'admin'),
                    $this->carts->adjustments($cartId),
                    $correlationId,
                    $initialStatus
                );
                $fulfillmentSnapshot = json_decode((string) ($cart['shipping_method_snapshot_json'] ?? '{}'), true);
                $fulfillmentStatus = is_array($fulfillmentSnapshot) && ($fulfillmentSnapshot['type'] ?? 'none') !== 'none' ? 'unfulfilled' : 'not_required';
                $db->run('UPDATE sale_orders SET fulfillment_status=? WHERE id=?', [$fulfillmentStatus, (int) $order['id']]);
                $order['fulfillment_status'] = $fulfillmentStatus;
                $states = $this->states ?? new SaleStateMachineService($this->connection->database() ?? throw new SaleValidationException('sale.database_unavailable'));
                $states->recordInitial((int) $cart['site_id'], 'order', (int) $order['id'], $initialStatus, $correlationId, $payload['iam_user_id'] ?? null, 'checkout');
                if ($deferInventory) {
                    $expiresAt = gmdate('Y-m-d H:i:s', time() + max(300, (int) ($payload['payment_reservation_ttl_seconds'] ?? 1800)));
                    $this->inventory->holdCartReservationsForPayment($cartId, (int) $order['id'], $expiresAt);
                } else {
                    $this->inventory->consumeCartReservations($cartId, (int) $order['id']);
                }
                $states->convertCart($cartId, (int) $order['id'], $payload['iam_user_id'] ?? null, $correlationId);
                $this->events->emit((int) $cart['site_id'], 'sale.order.placed', 'order', (int) $order['id'], [
                    'site_id' => (int) $cart['site_id'],
                    'order_id' => (int) $order['id'],
                    'order_number' => (string) $order['order_number'],
                    'grand_total_minor' => (int) $order['grand_total_minor'],
                    'currency' => (string) $order['currency'],
                    'cart_id' => $cartId,
                    'channel_id' => (int) $cart['channel_id'],
                    'language_code' => (string) ($cart['locale'] ?? ''),
                    'customer_ref_id' => $order['customer_contact_id'] ?? $order['customer_company_id'] ?? null,
                    'customer_contact_id' => $order['customer_contact_id'] ?? null,
                    'customer_company_id' => $order['customer_company_id'] ?? null,
                    'payment_status' => (string) $order['payment_status'],
                    'source' => (string) $order['source'],
                    'iam_user_id' => $payload['iam_user_id'] ?? null,
                    'product_ids' => array_values(array_unique(array_map(static fn(array $line): int => (int) ($line['business_product_id'] ?? 0), $lines))),
                    'category_ids' => $this->categoryIds($lines),
                ], $payload['iam_user_id'] ?? null, $correlationId);
                return $order;
            });
        });
    }

    /** @param list<array<string,mixed>> $lines @return list<int> */
    private function categoryIds(array $lines): array
    {
        $ids = [];
        foreach ($lines as $line) {
            $snapshot = json_decode((string) ($line['metadata_json'] ?? $line['snapshot_json'] ?? '{}'), true);
            if (!is_array($snapshot)) continue;
            $candidates = array_merge((array) ($snapshot['category_ids'] ?? []), isset($snapshot['category_id']) ? [$snapshot['category_id']] : []);
            foreach ($candidates as $id) if ((int) $id > 0) $ids[(int) $id] = true;
        }
        return array_map('intval', array_keys($ids));
    }
}
