<?php

declare(strict_types=1);

namespace App\Modules\Sale\Contracts;

final class SaleIntegrationEventContracts
{
    public const CART_CREATED = 'sale.cart.created';
    public const CART_LINE_ADDED = 'sale.cart.line_added';
    public const ORDER_PLACED = 'sale.order.placed';
    public const ORDER_CANCELLED = 'sale.order.cancelled';
    public const PAYMENT_RECORDED = 'sale.payment.recorded';
    public const PAYMENT_FAILED = 'sale.payment.failed';
    public const POS_SESSION_OPENED = 'sale.pos.session.opened';
    public const POS_SESSION_CLOSED = 'sale.pos.session.closed';
    public const REFUND_CREATED = 'sale.refund.created';
    public const STOCK_RESERVED = 'sale.stock.reserved';
    public const STOCK_CONSUMED = 'sale.stock.consumed';
    public const STOCK_RELEASED = 'sale.stock.released';
    public const INVOICE_SENT = 'sale.invoice.sent';

    /** @return array<string,array<string,mixed>> */
    public static function payloads(): array
    {
        return [
            self::CART_CREATED => [
                'entity' => 'cart',
                'required' => ['site_id', 'cart_id', 'channel_id'],
                'optional' => ['customer_ref_id', 'iam_user_id', 'source'],
            ],
            self::CART_LINE_ADDED => [
                'entity' => 'cart',
                'required' => ['site_id', 'cart_id', 'line_id', 'business_variant_id', 'quantity'],
                'optional' => ['sku', 'unit_price_minor', 'currency', 'iam_user_id'],
            ],
            self::ORDER_PLACED => [
                'entity' => 'order',
                'required' => ['site_id', 'order_id', 'order_number', 'grand_total_minor', 'currency'],
                'optional' => ['cart_id', 'customer_ref_id', 'payment_status', 'source', 'iam_user_id'],
            ],
            self::ORDER_CANCELLED => [
                'entity' => 'order',
                'required' => ['site_id', 'order_id'],
                'optional' => ['reason', 'iam_user_id'],
            ],
            self::PAYMENT_RECORDED => [
                'entity' => 'payment',
                'required' => ['site_id', 'order_id', 'transaction_id', 'amount_minor', 'currency', 'provider_key'],
                'optional' => ['payment_status', 'iam_user_id'],
            ],
            self::PAYMENT_FAILED => [
                'entity' => 'payment',
                'required' => ['site_id', 'order_id', 'transaction_id', 'amount_minor', 'currency', 'provider_key'],
                'optional' => ['error_code', 'error_message', 'iam_user_id'],
            ],
            self::POS_SESSION_OPENED => [
                'entity' => 'pos_session',
                'required' => ['site_id', 'cash_session_id', 'register_id', 'opening_cash_minor', 'currency'],
                'optional' => ['opened_by_iam_user_id'],
            ],
            self::POS_SESSION_CLOSED => [
                'entity' => 'pos_session',
                'required' => ['site_id', 'cash_session_id', 'register_id', 'counted_cash_minor', 'expected_cash_minor', 'difference_minor', 'currency'],
                'optional' => ['closed_by_iam_user_id', 'notes'],
            ],
            self::REFUND_CREATED => [
                'entity' => 'refund',
                'required' => ['site_id', 'order_id', 'refund_id', 'transaction_id', 'amount_minor', 'currency', 'provider_key'],
                'optional' => ['payment_transaction_id', 'reason', 'iam_user_id'],
            ],
            self::STOCK_RESERVED => [
                'entity' => 'stock',
                'required' => ['site_id', 'cart_id', 'business_variant_id', 'quantity'],
                'optional' => ['reservation_id', 'inventory_item_id', 'sku', 'source', 'reason'],
            ],
            self::STOCK_CONSUMED => [
                'entity' => 'stock',
                'required' => ['site_id', 'order_id', 'cart_id', 'business_variant_id', 'quantity'],
                'optional' => ['reservation_id', 'inventory_item_id', 'sku', 'reason'],
            ],
            self::STOCK_RELEASED => [
                'entity' => 'stock',
                'required' => ['site_id', 'cart_id', 'business_variant_id', 'quantity'],
                'optional' => ['reservation_id', 'inventory_item_id', 'sku', 'status', 'reason'],
            ],
            self::INVOICE_SENT => [
                'entity' => 'invoice',
                'required' => ['site_id', 'order_id', 'invoice_id', 'sent_at'],
                'optional' => ['recipient', 'amount_due_minor', 'currency', 'iam_user_id'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function outboxEnvelope(): array
    {
        return [
            'schema_version' => 1,
            'required' => ['event_id', 'event_type', 'site_id', 'aggregate', 'payload', 'created_at'],
            'aggregate' => ['required' => ['type', 'id']],
            'status_flow' => ['pending', 'processing', 'processed', 'failed', 'cancelled'],
            'topic_strategy' => 'topic equals event_type',
            'consumers' => ['crm', 'cms', 'ai'],
        ];
    }
}
