<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Modules\Sale\Repositories\SaleOrderRepository;

final class SaleOrderService
{
    public function __construct(
        private readonly SaleOrderRepository $orders,
        private readonly SaleEventService $events,
        private readonly ?SaleInventoryService $inventory = null,
        private readonly ?SaleStateMachineService $states = null
    ) {}

    /** @return array<string,mixed> */
    public function cancelOrder(int $orderId, ?int $iamUserId = null, ?string $reason = null): array
    {
        $correlationId = SaleStateMachineService::correlationId();
        return $this->orders->rawDatabase()->transaction(function () use ($orderId, $iamUserId, $reason, $correlationId): array {
            $states = $this->states ?? new SaleStateMachineService($this->orders->rawDatabase());
            $order = $states->transition('order', $orderId, 'cancelled', $iamUserId, $reason, $correlationId);
            if ($this->inventory !== null) {
                $cartId = (int) ($order['source_cart_id'] ?? 0);
                if ($cartId > 0) {
                    $this->inventory->releaseCartReservations($cartId, 'order cancelled');
                }
            }
            $this->events->emit((int) $order['site_id'], 'sale.order.cancelled', 'order', $orderId, [
                'site_id' => (int) $order['site_id'],
                'order_id' => $orderId,
                'reason' => $reason,
                'iam_user_id' => $iamUserId,
            ], $iamUserId, $correlationId);
            return $order;
        });
    }

    /** @return array<string,mixed> */
    public function confirmOrder(int $orderId, ?int $iamUserId = null, ?string $reason = null): array
    {
        return ($this->states ?? new SaleStateMachineService($this->orders->rawDatabase()))->transition('order', $orderId, 'confirmed', $iamUserId, $reason);
    }

    /** @return array<string,mixed> */
    public function completeOrder(int $orderId, ?int $iamUserId = null, ?string $reason = null): array
    {
        return ($this->states ?? new SaleStateMachineService($this->orders->rawDatabase()))->transition('order', $orderId, 'completed', $iamUserId, $reason);
    }
}
