ALTER TABLE sale_fulfillments ADD COLUMN carrier_code TEXT;
ALTER TABLE sale_fulfillments ADD COLUMN tracking_url TEXT;
ALTER TABLE sale_fulfillments ADD COLUMN tracking_validated_at TEXT;
ALTER TABLE sale_fulfillments ADD COLUMN exception_at TEXT;

PRAGMA foreign_keys=OFF;

CREATE TABLE sale_events_m88 (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    event_type TEXT NOT NULL CHECK(event_type IN (
        'sale.cart.created','sale.cart.line_added','sale.cart.abandoned','sale.order.placed','sale.order.confirmed','sale.order.cancelled',
        'sale.payment.recorded','sale.payment.confirmed','sale.payment.capture.requested','sale.payment.capture.completed',
        'sale.payment.capture.retry_scheduled','sale.payment.capture.dead_lettered','sale.payment.failed','sale.fulfillment.completed',
        'sale.return.created','sale.pos.session.opened','sale.pos.session.closed','sale.pos.order.completed','sale.refund.created',
        'sale.refund.completed','sale.refund.requested','sale.refund.retry_scheduled','sale.refund.dead_lettered','sale.gift_card.issued',
        'sale.gift_card.redeemed','customer.account.created','sale.invoice.sent','sale.notification.requested',
        'sale.stock.reserved','sale.stock.consumed','sale.stock.released'
    )),
    aggregate_type TEXT NOT NULL,
    aggregate_id INTEGER NOT NULL,
    payload_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(payload_json)),
    correlation_id TEXT,
    created_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(site_id > 0),
    CHECK(aggregate_type = lower(trim(aggregate_type)) AND aggregate_type GLOB '[a-z0-9_.:-]*'),
    CHECK(aggregate_id > 0),
    CHECK(created_by_iam_user_id IS NULL OR created_by_iam_user_id > 0)
);

INSERT INTO sale_events_m88(id,site_id,event_type,aggregate_type,aggregate_id,payload_json,correlation_id,created_by_iam_user_id,created_at)
SELECT id,site_id,event_type,aggregate_type,aggregate_id,payload_json,correlation_id,created_by_iam_user_id,created_at FROM sale_events;

DROP TABLE sale_events;
ALTER TABLE sale_events_m88 RENAME TO sale_events;
CREATE INDEX idx_sale_events_site_created ON sale_events(site_id, created_at DESC, id DESC);
CREATE INDEX idx_sale_events_aggregate ON sale_events(aggregate_type, aggregate_id, created_at DESC);
CREATE INDEX idx_sale_events_type ON sale_events(event_type, created_at DESC);
CREATE INDEX idx_sale_events_correlation ON sale_events(correlation_id, id);

PRAGMA foreign_keys=ON;

CREATE TABLE IF NOT EXISTS sale_fulfillment_tracking_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fulfillment_id INTEGER NOT NULL,
    provider_event_id TEXT,
    event_type TEXT NOT NULL,
    event_status TEXT NOT NULL CHECK(event_status IN ('information','in_transit','delivered','exception')),
    details_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(details_json)),
    occurred_at TEXT NOT NULL,
    received_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by_iam_user_id INTEGER,
    UNIQUE(fulfillment_id, provider_event_id),
    FOREIGN KEY(fulfillment_id) REFERENCES sale_fulfillments(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(trim(event_type) <> ''),
    CHECK(provider_event_id IS NULL OR trim(provider_event_id) <> '')
);

CREATE INDEX IF NOT EXISTS idx_sale_fulfillment_tracking_events
    ON sale_fulfillment_tracking_events(fulfillment_id, occurred_at, id);

CREATE TABLE IF NOT EXISTS sale_order_notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    order_id INTEGER NOT NULL,
    fulfillment_id INTEGER,
    document_id INTEGER,
    outbox_id INTEGER,
    notification_type TEXT NOT NULL CHECK(notification_type IN ('order_confirmed','payment_expected','payment_received','pickup_ready','shipment_sent','fulfillment_exception','delivery_completed')),
    recipient_hash TEXT NOT NULL,
    language TEXT NOT NULL DEFAULT 'fr' CHECK(language IN ('fr','en')),
    status TEXT NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','sent','failed','cancelled')),
    idempotency_key TEXT NOT NULL,
    resend_of_id INTEGER,
    last_error TEXT,
    queued_by_iam_user_id INTEGER,
    queued_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at TEXT,
    UNIQUE(site_id, idempotency_key),
    FOREIGN KEY(order_id) REFERENCES sale_orders(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY(fulfillment_id) REFERENCES sale_fulfillments(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY(document_id) REFERENCES sale_order_documents(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY(outbox_id) REFERENCES sale_outbox(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY(resend_of_id) REFERENCES sale_order_notifications(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CHECK(trim(recipient_hash) <> ''),
    CHECK(trim(idempotency_key) <> ''),
    CHECK(sent_at IS NULL OR status = 'sent')
);

CREATE INDEX IF NOT EXISTS idx_sale_order_notifications_order
    ON sale_order_notifications(order_id, queued_at DESC, id DESC);
