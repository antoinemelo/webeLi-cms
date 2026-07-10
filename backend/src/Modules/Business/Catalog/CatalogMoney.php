<?php

declare(strict_types=1);

namespace App\Modules\Business\Catalog;

use InvalidArgumentException;

final class CatalogMoney
{
    private function __construct(
        private readonly float $amount,
        private readonly string $currency = 'CHF'
    ) {}

    public static function from(float|int|string|null $amount, string $currency = 'CHF'): self
    {
        if ($amount === null || $amount === '') {
            throw new InvalidArgumentException('business.catalog.money_required');
        }
        if (!is_numeric($amount)) {
            throw new InvalidArgumentException('business.catalog.money_invalid');
        }
        return new self(round((float) $amount, 2), strtoupper(trim($currency) ?: 'CHF'));
    }

    public static function zero(string $currency = 'CHF'): self
    {
        return new self(0.0, strtoupper(trim($currency) ?: 'CHF'));
    }

    public function amount(): float
    {
        return $this->amount;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function formatted(): string
    {
        return number_format($this->amount, 2, '.', '');
    }
}
