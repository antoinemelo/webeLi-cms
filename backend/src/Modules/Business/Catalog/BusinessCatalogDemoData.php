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
                ['name' => 'Formule', 'option_key' => 'formule', 'values' => [
                    ['label' => 'Classic', 'value_key' => 'classic'],
                    ['label' => 'Premium', 'value_key' => 'premium'],
                ]],
                ['name' => 'Duree', 'option_key' => 'duree', 'values' => [
                    ['label' => '20 min', 'value_key' => '20_min'],
                    ['label' => '40 min', 'value_key' => '40_min'],
                ]],
            ],
            'variants' => [
                [
                    'sku' => 'DEMO-VOL-CLASSIC-20',
                    'status' => 'active',
                    'option_values' => ['formule' => 'classic', 'duree' => '20_min'],
                    'purchase_adjustment_type' => 'none',
                    'sale_adjustment_type' => 'none',
                    'stock_quantity' => 0,
                ],
                [
                    'sku' => 'DEMO-VOL-PREMIUM-40',
                    'status' => 'active',
                    'option_values' => ['formule' => 'premium', 'duree' => '40_min'],
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
