<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Modules\Sale\Exceptions\SaleValidationException;
use App\Modules\Sale\Repositories\SalePosRepository;

final class SalePosService
{
    public function __construct(
        private readonly SaleCartService $carts,
        private readonly SaleCheckoutService $checkout,
        private readonly SalePosRepository $repository
    ) {}

    public function carts(): SaleCartService { return $this->carts; }

    public function checkout(): SaleCheckoutService { return $this->checkout; }

    public function repository(): SalePosRepository { return $this->repository; }

    /** @return array<string,mixed> */
    public function context(int $sessionId, int $siteId, int $operatorId): array
    {
        $session = $this->repository->requireSession($sessionId, $siteId);
        if ((string) $session['status'] !== 'open' || (int) $session['opened_by_iam_user_id'] !== $operatorId) {
            throw new SaleValidationException('sale.cash_session_required');
        }
        return [
            'site_id' => $siteId,
            'channel_id' => (int) $session['channel_id'],
            'location_id' => (int) $session['stock_location_id'],
            'register_id' => (int) $session['register_id'],
            'device_id' => isset($session['device_id']) ? (int) $session['device_id'] : null,
            'register_session_id' => (int) $session['id'],
            'operator_id' => $operatorId,
            'currency' => (string) $session['currency'],
            'locale' => (string) $session['locale'],
        ];
    }

    /** @return array<string,mixed> */
    public function requireAllowedPaymentMethod(array $session, string $requested): array
    {
        $requested = strtolower(trim($requested));
        foreach ($this->repository->allowedPaymentMethods((int) $session['register_id']) as $method) {
            if ($requested === (string) $method['code'] || $requested === (string) $method['method_type']) {
                return $method;
            }
        }
        throw new SaleValidationException('sale.pos_payment_method_not_allowed');
    }
}
