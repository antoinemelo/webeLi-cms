<?php

declare(strict_types=1);

namespace App\Modules\Business\Services;

final class CatalogVisibilityService
{
    /** @param array<string,mixed> $product @param array<string,mixed> $variant */
    public function isPubliclyVisible(array $product, array $variant): bool
    {
        return ($product['status'] ?? '') === 'active'
            && ($variant['status'] ?? '') === 'active'
            && (bool) ($product['is_public'] ?? false)
            && (bool) ($product['is_ecommerce_enabled'] ?? false)
            && ($product['archived_at'] ?? null) === null
            && ($variant['archived_at'] ?? null) === null;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function publicPayload(array $payload): array
    {
        unset(
            $payload['base_purchase_price'],
            $payload['purchase_adjustment_type'],
            $payload['purchase_adjustment_value'],
            $payload['regular_purchase_price'],
            $payload['unit_purchase_price_minor'],
            $payload['gross_margin_amount'],
            $payload['gross_margin_percent']
        );
        return $payload;
    }
}
