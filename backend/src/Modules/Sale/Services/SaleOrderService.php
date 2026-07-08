<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Modules\Sale\Repositories\SaleOrderRepository;

final class SaleOrderService
{
    public function __construct(
        private readonly SaleOrderRepository $orders,
        private readonly SaleEventService $events
    ) {}

    /** @return array<string,mixed> */
    public function cancelOrder(int $orderId, ?int $iamUserId = null, ?string $reason = null): array
    {
        $order = $this->orders->cancel($orderId, $iamUserId, $reason);
        $this->events->emit((int) $order['site_id'], 'sale.order.cancelled', 'order', $orderId, ['reason' => $reason], $iamUserId);
        return $order;
    }
}
