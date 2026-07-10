<?php

declare(strict_types=1);

namespace App\Modules\Business\Catalog;

final class BusinessCatalogDemoData
{
    /** @return array<string,mixed> */
    public static function minimal(): array
    {
        return [
            'brand' => [
                'name' => 'Demo Outdoor',
                'slug' => 'demo-outdoor',
                'description' => 'Marque de demonstration pour le catalogue Business.',
                'website_url' => 'https://example.test',
                'is_public' => true,
            ],
            'category' => [
                'name' => 'Experiences',
                'slug' => 'experiences',
                'parent_slug' => null,
                'sort_order' => 10,
                'is_public' => true,
            ],
            'product' => [
                'name' => 'Vol decouverte',
                'slug' => 'vol-decouverte',
                'type' => 'service',
                'status' => 'active',
                'channels' => ['public', 'ecommerce', 'pos', 'internal'],
                'unit' => 'service',
                'currency' => 'CHF',
                'tax_class' => 'standard',
                'stock_enabled' => false,
                'base_purchase_price' => 80.00,
                'base_sale_price' => 149.00,
            ],
            'options' => [
                ['name' => 'Modèle', 'option_key' => 'model', 'values' => [
                    ['label' => 'Classic', 'value_key' => 'classic'],
                    ['label' => 'Premium', 'value_key' => 'premium'],
                ]],
                ['name' => 'Taille', 'option_key' => 'size', 'values' => [
                    ['label' => 'M', 'value_key' => 'm'],
                    ['label' => 'L', 'value_key' => 'l'],
                ]],
            ],
            'variants' => [
                [
                    'sku' => 'DEMO-VOL-CLASSIC-20',
                    'status' => 'active',
                    'option_values' => ['model' => 'classic', 'size' => 'm'],
                    'purchase_adjustment_type' => 'none',
                    'sale_adjustment_type' => 'none',
                    'stock_quantity' => 0,
                ],
                [
                    'sku' => 'DEMO-VOL-PREMIUM-40',
                    'status' => 'active',
                    'option_values' => ['model' => 'premium', 'size' => 'l'],
                    'purchase_adjustment_type' => 'percent_delta',
                    'purchase_adjustment_value' => 20.00,
                    'sale_adjustment_type' => 'amount_delta',
                    'sale_adjustment_value' => 60.00,
                    'stock_quantity' => 0,
                ],
            ],
            'offer' => [
                'name' => 'Lancement POS',
                'type' => 'percent',
                'value' => 10.00,
                'scope' => 'product',
                'channel' => 'pos',
            ],
        ];
    }
}
