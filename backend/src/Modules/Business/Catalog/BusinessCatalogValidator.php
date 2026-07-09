<?php

declare(strict_types=1);

namespace App\Modules\Business\Catalog;

use InvalidArgumentException;

final class BusinessCatalogValidator
{
    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function product(array $payload): array
    {
        $name = $this->text($payload['name'] ?? null, 'name', 180);
        $slug = $this->slug($payload['slug'] ?? $name, 'slug');
        $type = $this->choice($payload['type'] ?? 'physical', BusinessCatalogDefinitions::PRODUCT_TYPES, 'product_type');
        $status = $this->choice($payload['status'] ?? 'draft', BusinessCatalogDefinitions::PRODUCT_STATUSES, 'product_status');
        $channels = $this->channels($payload['channels'] ?? ['internal']);
        $currency = $this->choice($payload['currency'] ?? 'CHF', BusinessCatalogDefinitions::CURRENCIES, 'currency');
        $taxClass = $this->choice($payload['tax_class'] ?? 'standard', BusinessCatalogDefinitions::TAX_CLASSES, 'tax_class');
        $basePurchasePrice = $this->money($payload['base_purchase_price'] ?? null, 'base_purchase_price', false);
        $baseSalePrice = $this->money($payload['base_sale_price'] ?? null, 'base_sale_price', false);

        if (in_array('ecommerce', $channels, true) && $status === 'active' && $baseSalePrice === null) {
            throw new InvalidArgumentException('business.catalog.sale_price_required_for_ecommerce');
        }

        return [
            'name' => $name,
            'slug' => $slug,
            'type' => $type,
            'status' => $status,
            'channels' => $channels,
            'unit' => $this->key($payload['unit'] ?? 'unit', 'unit', 32),
            'currency' => $currency,
            'tax_class' => $taxClass,
            'stock_enabled' => $this->boolValue($payload['stock_enabled'] ?? $type === 'physical'),
            'allow_backorder' => $this->boolValue($payload['allow_backorder'] ?? true),
            'backorder_delivery_days' => max(0, (int) ($payload['backorder_delivery_days'] ?? $payload['delivery_lead_time_days'] ?? 7)),
            'base_purchase_price' => $basePurchasePrice,
            'base_sale_price' => $baseSalePrice,
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function option(array $payload): array
    {
        $name = $this->text($payload['name'] ?? null, 'name', 120);
        return [
            'name' => $name,
            'option_key' => $this->key($payload['option_key'] ?? $name, 'option_key', 80),
            'is_required' => (bool) ($payload['is_required'] ?? true),
            'sort_order' => max(0, (int) ($payload['sort_order'] ?? 0)),
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function optionValue(array $payload): array
    {
        $label = $this->text($payload['label'] ?? null, 'label', 120);
        return [
            'label' => $label,
            'value_key' => $this->key($payload['value_key'] ?? $label, 'value_key', 80),
            'sort_order' => max(0, (int) ($payload['sort_order'] ?? 0)),
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function variant(array $payload): array
    {
        $sku = $this->key($payload['sku'] ?? null, 'sku', 80, true);
        $purchaseAdjustmentType = $this->normalizeAdjustmentType($payload['purchase_adjustment_type'] ?? $payload['purchase_adjustment_mode'] ?? 'none');
        $saleAdjustmentType = $this->normalizeAdjustmentType($payload['sale_adjustment_type'] ?? $payload['sale_adjustment_mode'] ?? 'none');
        $validated = [
            'sku' => $sku,
            'barcode' => $this->nullableText($payload['barcode'] ?? null, 'barcode', 80),
            'name' => $this->nullableText($payload['name'] ?? null, 'name', 180) ?? $sku,
            'sales_note' => $this->nullableText($payload['sales_note'] ?? null, 'sales_note', 2000),
            'status' => $this->choice($payload['status'] ?? 'draft', BusinessCatalogDefinitions::VARIANT_STATUSES, 'variant_status'),
            'purchase_adjustment_type' => $purchaseAdjustmentType,
            'purchase_adjustment_value' => $this->adjustmentValue($purchaseAdjustmentType, $payload['purchase_adjustment_value'] ?? null, 'purchase_adjustment_value'),
            'sale_adjustment_type' => $saleAdjustmentType,
            'sale_adjustment_value' => $this->adjustmentValue($saleAdjustmentType, $payload['sale_adjustment_value'] ?? null, 'sale_adjustment_value'),
            'stock_quantity' => max(0, (int) ($payload['stock_quantity'] ?? 0)),
            'stock_reserved' => max(0, (int) ($payload['stock_reserved'] ?? 0)),
            'weight_grams' => array_key_exists('weight_grams', $payload) && $payload['weight_grams'] !== null ? max(0, (int) $payload['weight_grams']) : null,
            'sort_order' => max(0, (int) ($payload['sort_order'] ?? 0)),
        ];
        if (array_key_exists('track_stock', $payload)) {
            $validated['track_stock'] = $this->boolValue($payload['track_stock']);
        }
        if (array_key_exists('allow_backorder', $payload)) {
            $validated['allow_backorder'] = $this->boolValue($payload['allow_backorder']);
        }
        if (array_key_exists('backorder_delivery_days', $payload) || array_key_exists('delivery_lead_time_days', $payload)) {
            $validated['backorder_delivery_days'] = max(0, (int) ($payload['backorder_delivery_days'] ?? $payload['delivery_lead_time_days'] ?? 0));
        }
        return $validated;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function offer(array $payload): array
    {
        $type = $this->choice($payload['type'] ?? 'percent', BusinessCatalogDefinitions::OFFER_TYPES, 'offer_type');
        $value = $this->money($payload['value'] ?? null, 'offer_value', true);
        if ($type === 'percent' && ($value <= 0 || $value > 100)) {
            throw new InvalidArgumentException('business.catalog.offer_percent_invalid');
        }
        if ($type === 'amount' && $value <= 0) {
            throw new InvalidArgumentException('business.catalog.offer_amount_invalid');
        }

        return [
            'name' => $this->text($payload['name'] ?? null, 'name', 180),
            'type' => $type,
            'value' => $value,
            'scope' => $this->choice($payload['scope'] ?? 'product', BusinessCatalogDefinitions::OFFER_SCOPES, 'offer_scope'),
            'channel' => $this->choice($payload['channel'] ?? 'all', BusinessCatalogDefinitions::OFFER_CHANNELS, 'offer_channel'),
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public function publicProductPayload(array $row): array
    {
        unset(
            $row['base_purchase_price'],
            $row['purchase_adjustment_type'],
            $row['purchase_adjustment_mode'],
            $row['purchase_adjustment_value'],
            $row['regular_purchase_price'],
            $row['effective_purchase_price'],
            $row['gross_margin_amount'],
            $row['gross_margin_percent']
        );
        return $row;
    }

    public function effectivePrice(?float $base, string $type, ?float $value): ?float
    {
        $type = $this->normalizeAdjustmentType($type);
        if ($base === null) {
            return $type === 'fixed_override' ? $this->roundMoney((float) $value) : null;
        }
        return match ($type) {
            'none' => $this->roundMoney($base),
            'amount_delta' => $this->roundMoney($base + (float) $value),
            'percent_delta' => $this->roundMoney($base + ($base * ((float) $value / 100))),
            'fixed_override' => $this->roundMoney((float) $value),
            default => throw new InvalidArgumentException('business.catalog.price_adjustment_type_invalid'),
        };
    }

    public function discountedSalePrice(float $salePrice, string $offerType, float $offerValue): float
    {
        $discounted = match ($offerType) {
            'percent' => $salePrice - ($salePrice * ($offerValue / 100)),
            'amount' => $salePrice - $offerValue,
            default => throw new InvalidArgumentException('business.catalog.offer_type_invalid'),
        };
        return $this->roundMoney(max(0.0, $discounted));
    }

    /** @param mixed $value @return list<string> */
    private function channels(mixed $value): array
    {
        $channels = is_array($value) ? $value : [$value];
        $channels = array_values(array_unique(array_map(static fn(mixed $channel): string => trim((string) $channel), $channels)));
        if ($channels === []) {
            throw new InvalidArgumentException('business.catalog.channels_required');
        }
        foreach ($channels as $channel) {
            $this->choice($channel, BusinessCatalogDefinitions::CHANNELS, 'channel');
        }
        return $channels;
    }

    private function adjustmentValue(mixed $type, mixed $value, string $field): ?float
    {
        $type = $this->choice($this->normalizeAdjustmentType($type), BusinessCatalogDefinitions::PRICE_ADJUSTMENT_MODES, $field . '_type');
        if ($type === 'none') {
            return null;
        }
        $amount = $this->money($value, $field, true, true);
        if ($amount < 0) {
            throw new InvalidArgumentException('business.catalog.' . $field . '_positive_required');
        }
        if ($type === 'percent_delta' && ($amount < -100 || $amount > 1000)) {
            throw new InvalidArgumentException('business.catalog.' . $field . '_percent_invalid');
        }
        if ($type === 'fixed_override' && $amount < 0) {
            throw new InvalidArgumentException('business.catalog.' . $field . '_fixed_invalid');
        }
        return $amount;
    }

    private function normalizeAdjustmentType(mixed $type): string
    {
        return match (trim((string) $type)) {
            'amount' => 'amount_delta',
            'percent' => 'percent_delta',
            'fixed' => 'fixed_override',
            default => trim((string) $type),
        };
    }

    /** @param list<string> $allowed */
    private function choice(mixed $value, array $allowed, string $field): string
    {
        $choice = trim((string) $value);
        if (!in_array($choice, $allowed, true)) {
            throw new InvalidArgumentException('business.catalog.' . $field . '_invalid');
        }
        return $choice;
    }

    private function text(mixed $value, string $field, int $max): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            throw new InvalidArgumentException('business.catalog.' . $field . '_required');
        }
        if (strlen($text) > $max) {
            throw new InvalidArgumentException('business.catalog.' . $field . '_too_long');
        }
        return $text;
    }

    private function nullableText(mixed $value, string $field, int $max): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (strlen($text) > $max) {
            throw new InvalidArgumentException('business.catalog.' . $field . '_too_long');
        }
        return $text;
    }

    private function slug(mixed $value, string $field): string
    {
        return $this->key($value, $field, 120);
    }

    private function key(mixed $value, string $field, int $max, bool $allowUppercase = false): string
    {
        $key = trim((string) $value);
        if (!$allowUppercase) {
            $key = strtolower($key);
        }
        $key = preg_replace($allowUppercase ? '/[^A-Za-z0-9_-]+/' : '/[^a-z0-9_-]+/', '_', $key) ?? '';
        $key = trim($key, '_-');
        if ($key === '') {
            throw new InvalidArgumentException('business.catalog.' . $field . '_invalid');
        }
        return substr($key, 0, $max);
    }

    private function money(mixed $value, string $field, bool $required, bool $allowNegative = false): ?float
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw new InvalidArgumentException('business.catalog.' . $field . '_required');
            }
            return null;
        }
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('business.catalog.' . $field . '_invalid');
        }
        $amount = (float) $value;
        if (!$allowNegative && $amount < 0) {
            throw new InvalidArgumentException('business.catalog.' . $field . '_negative');
        }
        return $this->roundMoney($amount);
    }

    private function roundMoney(float $value): float
    {
        return round($value, 2);
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value !== 0.0;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['', '0', 'false', 'no', 'off', 'null'], true)) {
                return false;
            }
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
        }
        return (bool) $value;
    }
}
