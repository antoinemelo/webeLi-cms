<?php

declare(strict_types=1);

namespace App\Modules\Sale\Contracts;

final class SaleAiContextContracts
{
    public const ORDER_SUMMARY = 'sale.ai.order_summary';
    public const POS_DAY_SUMMARY = 'sale.ai.pos_day_summary';
    public const CUSTOMER_SALES_ANALYSIS = 'sale.ai.customer_sales_analysis';
    public const UNPAID_ORDERS_DETECTION = 'sale.ai.unpaid_orders_detection';

    /** @return array<string,array<string,mixed>> */
    public static function contexts(): array
    {
        return [
            self::ORDER_SUMMARY => [
                'endpoint' => 'GET /admin/api/sale/ai/orders/{id}/summary-context',
                'permission' => 'sale.orders.read',
                'external_ai_allowed' => false,
                'payload' => [
                    'required' => ['order', 'lines', 'payments', 'refunds', 'events'],
                    'sensitive_fields' => ['customer_snapshot_json', 'billing_address_json', 'shipping_address_json'],
                ],
            ],
            self::POS_DAY_SUMMARY => [
                'endpoint' => 'GET /admin/api/sale/ai/pos/day-summary-context',
                'permission' => 'sale.reports.read',
                'external_ai_allowed' => false,
                'payload' => [
                    'required' => ['date', 'sessions', 'orders', 'payments', 'totals'],
                    'filters' => ['date', 'register_id'],
                ],
            ],
            self::CUSTOMER_SALES_ANALYSIS => [
                'endpoint' => 'GET /admin/api/sale/ai/customers/{type}/{id}/analysis-context',
                'permission' => 'sale.orders.read',
                'external_ai_allowed' => false,
                'payload' => [
                    'required' => ['customer', 'orders', 'payments', 'refunds', 'totals'],
                    'customer_types' => ['company', 'contact'],
                ],
            ],
            self::UNPAID_ORDERS_DETECTION => [
                'endpoint' => 'GET /admin/api/sale/ai/unpaid-orders-context',
                'permission' => 'sale.reports.read',
                'external_ai_allowed' => false,
                'payload' => [
                    'required' => ['orders', 'totals', 'as_of'],
                    'filters' => ['days_overdue', 'channel_id'],
                ],
            ],
        ];
    }
}
