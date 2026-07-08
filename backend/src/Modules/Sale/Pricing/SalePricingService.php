<?php

declare(strict_types=1);

namespace App\Modules\Sale\Pricing;

use App\Modules\Sale\Exceptions\SaleValidationException;

final class SalePricingService
{
    /** @param array<string,mixed> $snapshot @return array<string,int|bool> */
    public function lineAmounts(array $snapshot): array
    {
        $regular = max(0, (int) ($snapshot['regular_unit_price_minor'] ?? $snapshot['unit_price_minor'] ?? 0));
        $unit = max(0, (int) ($snapshot['unit_price_minor'] ?? 0));
        if ($unit < 0 || $regular < 0) {
            throw new SaleValidationException('sale.total_negative');
        }
        return [
            'regular_unit_price_minor' => $regular,
            'unit_price_minor' => $unit,
            'tax_rate_basis_points' => max(0, (int) ($snapshot['tax_rate_basis_points'] ?? 0)),
            'tax_included' => (bool) ($snapshot['tax_included'] ?? true),
        ];
    }
}
