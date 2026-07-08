<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

final class SalePosService
{
    public function __construct(private readonly SaleCartService $carts, private readonly SaleCheckoutService $checkout) {}

    public function carts(): SaleCartService { return $this->carts; }

    public function checkout(): SaleCheckoutService { return $this->checkout; }
}
