<?php

declare(strict_types=1);

namespace App\Modules\Business\Catalog;

final class CatalogMarginResult
{
    public function __construct(
        private readonly CatalogMoney $amount,
        private readonly ?float $percent
    ) {}

    public function amount(): CatalogMoney
    {
        return $this->amount;
    }

    public function percent(): ?float
    {
        return $this->percent;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'gross_margin_amount' => $this->amount->formatted(),
            'gross_margin_percent' => $this->percent === null ? null : round($this->percent, 2),
            'currency' => $this->amount->currency(),
        ];
    }
}
