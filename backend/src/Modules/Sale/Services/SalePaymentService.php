<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Modules\Sale\Exceptions\SalePaymentException;
use App\Modules\Sale\Repositories\SaleOrderRepository;
use App\Modules\Sale\Repositories\SalePaymentRepository;

final class SalePaymentService
{
    public function __construct(
        private readonly SalePaymentRepository $payments,
        private readonly SaleOrderRepository $orders,
        private readonly SaleEventService $events
    ) {}

    /** @return array<string,mixed> */
    public function recordManualPayment(int $orderId, int $amountMinor, ?int $iamUserId = null): array
    {
        if ($amountMinor < 1) {
            throw new SalePaymentException('sale.payment_amount_invalid');
        }
        $order = $this->orders->requireOrder($orderId);
        $newTotal = $this->payments->allocatedTotal($orderId) + $amountMinor;
        if ($newTotal > (int) $order['grand_total_minor']) {
            throw new SalePaymentException('sale.payment_exceeds_order_total');
        }
        $transaction = $this->payments->recordTransaction($orderId, $amountMinor, (string) $order['currency']);
        $order = $this->orders->updatePaidTotal($orderId, $newTotal);
        $this->events->emit((int) $order['site_id'], 'sale.payment.recorded', 'order', $orderId, ['transaction_id' => (int) $transaction['id'], 'amount_minor' => $amountMinor], $iamUserId);
        return ['order' => $order, 'transaction' => $transaction];
    }
}
