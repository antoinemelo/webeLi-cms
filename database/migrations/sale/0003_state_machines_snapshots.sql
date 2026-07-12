PRAGMA foreign_keys = ON;

ALTER TABLE sale_carts ADD COLUMN shipping_method_snapshot_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(shipping_method_snapshot_json));
ALTER TABLE sale_carts ADD COLUMN version INTEGER NOT NULL DEFAULT 0 CHECK(version >= 0);
ALTER TABLE sale_orders ADD COLUMN source_cart_id INTEGER;
ALTER TABLE sale_orders ADD COLUMN shipping_method_snapshot_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(shipping_method_snapshot_json));
ALTER TABLE sale_orders ADD COLUMN correlation_id TEXT;
ALTER TABLE sale_orders ADD COLUMN version INTEGER NOT NULL DEFAULT 0 CHECK(version >= 0);
ALTER TABLE sale_order_status_history ADD COLUMN correlation_id TEXT;
ALTER TABLE sale_payment_intents ADD COLUMN version INTEGER NOT NULL DEFAULT 0 CHECK(version >= 0);
ALTER TABLE sale_returns ADD COLUMN version INTEGER NOT NULL DEFAULT 0 CHECK(version >= 0);
ALTER TABLE sale_returns ADD COLUMN updated_at TEXT;
ALTER TABLE sale_refunds ADD COLUMN version INTEGER NOT NULL DEFAULT 0 CHECK(version >= 0);
ALTER TABLE sale_refunds ADD COLUMN updated_at TEXT;
ALTER TABLE sale_events ADD COLUMN correlation_id TEXT;

UPDATE sale_orders SET source_cart_id = CAST(json_extract(metadata_json, '$.source_cart_id') AS INTEGER)
WHERE source_cart_id IS NULL AND json_extract(metadata_json, '$.source_cart_id') IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS idx_sale_orders_source_cart ON sale_orders(source_cart_id) WHERE source_cart_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_sale_events_correlation ON sale_events(correlation_id, id);

CREATE TABLE IF NOT EXISTS sale_fulfillments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL,
    fulfillment_number TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','preparing','partially_shipped','shipped','delivered','cancelled','returned')),
    shipping_address_snapshot_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(shipping_address_snapshot_json)),
    shipping_method_snapshot_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(shipping_method_snapshot_json)),
    tracking_reference TEXT,
    correlation_id TEXT NOT NULL,
    version INTEGER NOT NULL DEFAULT 0 CHECK(version >= 0),
    created_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    shipped_at TEXT,
    delivered_at TEXT,
    cancelled_at TEXT,
    UNIQUE(order_id, fulfillment_number),
    FOREIGN KEY(order_id) REFERENCES sale_orders(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CHECK(trim(fulfillment_number) <> ''),
    CHECK(trim(correlation_id) <> ''),
    CHECK(shipped_at IS NULL OR status IN ('shipped','delivered','returned')),
    CHECK(delivered_at IS NULL OR status IN ('delivered','returned')),
    CHECK(cancelled_at IS NULL OR status = 'cancelled')
);

CREATE INDEX IF NOT EXISTS idx_sale_fulfillments_order ON sale_fulfillments(order_id, status, created_at DESC);

CREATE TABLE IF NOT EXISTS sale_fulfillment_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fulfillment_id INTEGER NOT NULL,
    order_line_id INTEGER NOT NULL,
    quantity INTEGER NOT NULL CHECK(quantity > 0),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(fulfillment_id, order_line_id),
    FOREIGN KEY(fulfillment_id) REFERENCES sale_fulfillments(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(order_line_id) REFERENCES sale_order_lines(id) ON DELETE RESTRICT ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS sale_state_transitions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    aggregate_type TEXT NOT NULL CHECK(aggregate_type IN ('cart','order','payment_intent','fulfillment','return','refund')),
    aggregate_id INTEGER NOT NULL,
    from_status TEXT,
    to_status TEXT NOT NULL,
    correlation_id TEXT NOT NULL,
    changed_by_iam_user_id INTEGER,
    reason TEXT,
    metadata_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(metadata_json)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(site_id > 0), CHECK(aggregate_id > 0), CHECK(from_status IS NULL OR from_status <> to_status),
    CHECK(trim(to_status) <> ''), CHECK(trim(correlation_id) <> '')
);

CREATE INDEX IF NOT EXISTS idx_sale_state_transitions_aggregate ON sale_state_transitions(aggregate_type, aggregate_id, id);
CREATE INDEX IF NOT EXISTS idx_sale_state_transitions_correlation ON sale_state_transitions(correlation_id, id);

CREATE TRIGGER IF NOT EXISTS trg_sale_order_snapshots_immutable
BEFORE UPDATE OF customer_snapshot_json, billing_address_json, shipping_address_json, shipping_method_snapshot_json, currency ON sale_orders
WHEN OLD.status <> 'draft'
BEGIN SELECT RAISE(ABORT, 'sale order snapshots are immutable after placement'); END;

CREATE TRIGGER IF NOT EXISTS trg_sale_order_line_snapshots_immutable
BEFORE UPDATE OF business_product_id, business_variant_id, sku, barcode, product_name, variant_name, product_type,
    unit_price_minor, regular_unit_price_minor, unit_purchase_price_minor, currency, tax_class_id,
    tax_rate_basis_points, tax_included, line_subtotal_minor, line_discount_minor, line_tax_minor, line_total_minor, snapshot_json
ON sale_order_lines
BEGIN SELECT RAISE(ABORT, 'sale order line snapshots are immutable'); END;

CREATE TRIGGER IF NOT EXISTS trg_sale_order_lines_delete_immutable
BEFORE DELETE ON sale_order_lines
WHEN EXISTS (SELECT 1 FROM sale_orders o WHERE o.id = OLD.order_id AND o.status <> 'draft')
BEGIN SELECT RAISE(ABORT, 'sale order lines cannot be deleted after placement'); END;
