<?php

declare(strict_types=1);

namespace App\Modules\Business\Catalog;

final class BusinessCatalogDefinitions
{
    public const PRODUCT_TYPES = ['physical', 'service', 'gift_card', 'bundle'];
    public const PRODUCT_STATUSES = ['draft', 'active', 'archived'];
    public const VARIANT_STATUSES = ['draft', 'active', 'archived'];
    public const CHANNELS = ['public', 'ecommerce', 'pos', 'catalogue', 'internal'];
    public const PRICE_KINDS = ['purchase', 'sale'];
    public const PRICE_ADJUSTMENT_TYPES = ['amount_delta', 'percent_delta', 'fixed_override'];
    public const PRICE_ADJUSTMENT_MODES = ['none', 'amount_delta', 'percent_delta', 'fixed_override'];
    public const OFFER_TYPES = ['percent', 'amount'];
    public const OFFER_SCOPES = ['product', 'variant', 'category', 'brand'];
    public const OFFER_CHANNELS = ['all', 'ecommerce', 'pos', 'catalogue', 'admin'];
    public const CURRENCIES = ['CHF', 'EUR', 'USD'];
    public const TAX_CLASSES = ['standard', 'reduced', 'zero', 'exempt'];

    /** @return array<string,list<string>> */
    public static function all(): array
    {
        return [
            'product_types' => self::PRODUCT_TYPES,
            'product_statuses' => self::PRODUCT_STATUSES,
            'variant_statuses' => self::VARIANT_STATUSES,
            'channels' => self::CHANNELS,
            'price_kinds' => self::PRICE_KINDS,
            'price_adjustment_types' => self::PRICE_ADJUSTMENT_TYPES,
            'price_adjustment_modes' => self::PRICE_ADJUSTMENT_MODES,
            'offer_types' => self::OFFER_TYPES,
            'offer_scopes' => self::OFFER_SCOPES,
            'offer_channels' => self::OFFER_CHANNELS,
            'currencies' => self::CURRENCIES,
            'tax_classes' => self::TAX_CLASSES,
        ];
    }

    public static function isPublicChannel(string $channel): bool
    {
        return in_array($channel, ['public', 'ecommerce'], true);
    }
}
