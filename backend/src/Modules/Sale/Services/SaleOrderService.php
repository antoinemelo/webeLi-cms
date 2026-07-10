<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Modules\Sale\Repositories\SaleOrderRepository;

final class SaleOrderService
{
    public function __construct(
        private readonly SaleOrderRepository $orders,
        private readonly SaleEventService $events,
        private readonly ?SaleInventoryService $inventory = null
    ) {}

    /** @return array<string,mixed> */
    public function cancelOrder(int $orderId, ?int $iamUserId = null, ?string $reason = null): array
    {
        $order = $this->orders->cancel($orderId, $iamUserId, $reason);
        if ($this->inventory !== null) {
            $metadata = json_decode((string) ($order['metadata_json'] ?? '{}'), true);
            $cartId = (int) ($metadata['source_cart_id'] ?? 0);
            if ($cartId > 0) {
                $this->inventory->releaseCartReservations($cartId, 'order cancelled');
            }
        }
        $this->events->emit((int) $order['site_id'], 'sale.order.cancelled', 'order', $orderId, [
            'site_id' => (int) $order['site_id'],
            'order_id' => $orderId,
            'reason' => $reason,
            'iam_user_id' => $iamUserId,
        ], $iamUserId);
        return $order;
    }
}
