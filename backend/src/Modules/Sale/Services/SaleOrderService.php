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

    /** @return array<string,mixed> */
    public function reconcileCustomer(int $orderId, ?int $companyId, ?int $contactId, ?int $iamUserId = null, ?string $reason = null): array
    {
        if (($companyId ?? 0) < 1 && ($contactId ?? 0) < 1) {
            throw new \App\Modules\Sale\Exceptions\SaleValidationException('sale.customer_reconciliation_target_required');
        }
        $db = $this->orders->rawDatabase();
        return $db->transaction(function () use ($orderId, $companyId, $contactId, $iamUserId, $reason, $db): array {
            $order = $this->orders->requireOrder($orderId);
            $snapshotBefore = (string) $order['customer_snapshot_json'];
            $correlationId = SaleStateMachineService::correlationId();
            $db->run('UPDATE sale_orders SET customer_company_id=?, customer_contact_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?', [$companyId, $contactId, $orderId]);
            $db->run(
                'INSERT INTO sale_order_customer_reconciliations(order_id,previous_company_id,previous_contact_id,company_id,contact_id,reason,correlation_id,linked_by_iam_user_id)
                 VALUES(?,?,?,?,?,?,?,?)',
                [$orderId, $order['customer_company_id'] ?? null, $order['customer_contact_id'] ?? null, $companyId, $contactId, $reason, $correlationId, $iamUserId]
            );
            $updated = $this->orders->requireOrder($orderId);
            if (!hash_equals($snapshotBefore, (string) $updated['customer_snapshot_json'])) {
                throw new \App\Modules\Sale\Exceptions\SaleValidationException('sale.customer_snapshot_changed');
            }
            return $updated + ['reconciliation_correlation_id' => $correlationId];
        });
    }
}
