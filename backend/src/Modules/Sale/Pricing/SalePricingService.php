<?php

declare(strict_types=1);

namespace App\Modules\Sale\Pricing;

use App\Modules\Sale\Exceptions\SaleValidationException;

final class SalePricingService
{
    /** @param array<string,mixed> $snapshot @return array<string,int|bool> */
    public function lineAmounts(array $snapshot): array
    {
        $regular = (int) ($snapshot['regular_unit_price_minor'] ?? $snapshot['unit_price_minor'] ?? 0);
        $unit = (int) ($snapshot['unit_price_minor'] ?? 0);
        if ($unit < 0 || $regular < 0) {
            throw new SaleValidationException('sale.total_negative');
        }
        $catalogDiscount = max(0, $regular - $unit);

        return [
            'regular_unit_price_minor' => $regular,
            'unit_price_minor' => $unit,
            'catalog_unit_discount_minor' => $catalogDiscount,
            'tax_rate_basis_points' => max(0, (int) ($snapshot['tax_rate_basis_points'] ?? 0)),
            'tax_included' => (bool) ($snapshot['tax_included'] ?? true),
        ];
    }

    /**
     * @param array<string,int|bool> $amounts
     * @param list<array{type?:string,value_minor?:int,value_basis_points?:int,label?:string,source_type?:string,source_id?:int}> $lineAdjustments
     * @return array{
     *   line_subtotal_minor:int,
     *   line_discount_minor:int,
     *   line_tax_minor:int,
     *   line_total_minor:int,
     *   taxable_amount_minor:int,
     *   tax_lines:list<array<string,int|string|null>>,
     *   adjustments:list<array<string,int|string|null>>
     * }
     */
    public function lineTotals(array $amounts, int $quantity, array $lineAdjustments = []): array
    {
        if ($quantity < 1) {
            throw new SaleValidationException('sale.quantity_invalid');
        }

        $regular = $this->minor($amounts['regular_unit_price_minor'] ?? 0);
        $unit = $this->minor($amounts['unit_price_minor'] ?? 0);
        $rate = max(0, (int) ($amounts['tax_rate_basis_points'] ?? 0));
        $taxIncluded = (bool) ($amounts['tax_included'] ?? true);

        $subtotal = $regular * $quantity;
        $lineNetOrGross = $unit * $quantity;
        $discount = max(0, $subtotal - $lineNetOrGross);
        $adjustments = [];

        foreach ($lineAdjustments as $adjustment) {
            $amount = $this->adjustmentAmount($adjustment, $lineNetOrGross);
            if ($amount <= 0) {
                continue;
            }
            $amount = min($amount, $lineNetOrGross);
            $lineNetOrGross -= $amount;
            $discount += $amount;
            $adjustments[] = [
                'adjustment_type' => (string) ($adjustment['type'] ?? 'manual_discount'),
                'source_type' => (string) ($adjustment['source_type'] ?? 'manual'),
                'source_id' => isset($adjustment['source_id']) ? (int) $adjustment['source_id'] : null,
                'label' => (string) ($adjustment['label'] ?? 'Remise'),
                'amount_minor' => $amount,
            ];
        }

        $tax = $taxIncluded
            ? $this->divideRounded($lineNetOrGross * $rate, 10000 + $rate)
            : $this->divideRounded($lineNetOrGross * $rate, 10000);
        $lineTotal = $taxIncluded ? $lineNetOrGross : $lineNetOrGross + $tax;
        $taxable = $taxIncluded ? max(0, $lineNetOrGross - $tax) : $lineNetOrGross;

        return [
            'line_subtotal_minor' => max(0, $subtotal),
            'line_discount_minor' => max(0, $discount),
            'line_tax_minor' => max(0, $tax),
            'line_total_minor' => max(0, $lineTotal),
            'taxable_amount_minor' => max(0, $taxable),
            'tax_lines' => $rate > 0 ? [[
                'tax_class_code' => 'standard',
                'tax_rate_basis_points' => $rate,
                'taxable_amount_minor' => max(0, $taxable),
                'tax_amount_minor' => max(0, $tax),
            ]] : [],
            'adjustments' => $adjustments,
        ];
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @param list<array<string,mixed>> $cartAdjustments
     * @return array{subtotal_minor:int,discount_total_minor:int,tax_total_minor:int,shipping_total_minor:int,grand_total_minor:int,surcharge_total_minor:int,tax_lines:list<array<string,int|string>>,adjustments:list<array<string,int|string|null>>}
     */
    public function cartTotals(array $lines, int $shippingTotalMinor = 0, array $cartAdjustments = []): array
    {
        $subtotal = 0;
        $discount = 0;
        $tax = 0;
        $grand = max(0, $shippingTotalMinor);
        $surcharge = 0;
        $taxLines = [];
        $adjustments = [];

        foreach ($lines as $line) {
            $subtotal += max(0, (int) ($line['line_subtotal_minor'] ?? 0));
            $discount += max(0, (int) ($line['line_discount_minor'] ?? 0));
            $tax += max(0, (int) ($line['line_tax_minor'] ?? 0));
            $grand += max(0, (int) ($line['line_total_minor'] ?? 0));

            $metadata = $this->metadata($line['metadata_json'] ?? null);
            foreach (($metadata['pricing']['tax_lines'] ?? []) as $taxLine) {
                if (is_array($taxLine)) {
                    $taxLines[] = $taxLine;
                }
            }
            foreach (($metadata['pricing']['adjustments'] ?? []) as $adjustment) {
                if (is_array($adjustment)) {
                    $adjustments[] = $adjustment;
                }
            }
        }

        foreach ($cartAdjustments as $adjustment) {
            $amount = max(0, (int) ($adjustment['amount_minor'] ?? 0));
            if ($amount <= 0) {
                continue;
            }
            $type = (string) ($adjustment['adjustment_type'] ?? 'manual_discount');
            if ($type === 'surcharge') {
                $grand += $amount;
                $surcharge += $amount;
            } else {
                $amount = min($amount, $grand);
                $grand -= $amount;
                $discount += $amount;
            }
            $adjustments[] = [
                'adjustment_type' => $type,
                'source_type' => (string) ($adjustment['source_type'] ?? 'manual'),
                'source_id' => isset($adjustment['source_id']) ? (int) $adjustment['source_id'] : null,
                'label' => (string) ($adjustment['label'] ?? ($type === 'surcharge' ? 'Ajout' : 'Remise')),
                'amount_minor' => $amount,
            ];
        }

        return [
            'subtotal_minor' => $subtotal,
            'discount_total_minor' => $discount,
            'tax_total_minor' => $tax,
            'shipping_total_minor' => max(0, $shippingTotalMinor),
            'grand_total_minor' => max(0, $grand),
            'surcharge_total_minor' => $surcharge,
            'tax_lines' => $taxLines,
            'adjustments' => $adjustments,
        ];
    }

    private function minor(mixed $value): int
    {
        $minor = (int) $value;
        if ($minor < 0) {
            throw new SaleValidationException('sale.total_negative');
        }
        return $minor;
    }

    /** @param array<string,mixed> $adjustment */
    private function adjustmentAmount(array $adjustment, int $baseMinor): int
    {
        if (isset($adjustment['value_basis_points'])) {
            return $this->divideRounded($baseMinor * max(0, (int) $adjustment['value_basis_points']), 10000);
        }
        return max(0, (int) ($adjustment['value_minor'] ?? 0));
    }

    private function divideRounded(int $numerator, int $denominator): int
    {
        if ($denominator <= 0 || $numerator <= 0) {
            return 0;
        }
        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }

    /** @return array<string,mixed> */
    private function metadata(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
