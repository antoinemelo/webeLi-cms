PRAGMA foreign_keys = ON;

ALTER TABLE sale_carts ADD COLUMN checkout_step TEXT NOT NULL DEFAULT 'cart'
    CHECK(checkout_step IN ('cart','identity','addresses','delivery','review','validated'));
ALTER TABLE sale_carts ADD COLUMN terms_accepted INTEGER NOT NULL DEFAULT 0 CHECK(terms_accepted IN (0,1));
ALTER TABLE sale_carts ADD COLUMN terms_accepted_at TEXT;
ALTER TABLE sale_carts ADD COLUMN marketing_consent INTEGER CHECK(marketing_consent IS NULL OR marketing_consent IN (0,1));
ALTER TABLE sale_carts ADD COLUMN marketing_consent_at TEXT;
ALTER TABLE sale_carts ADD COLUMN payment_method_snapshot_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(payment_method_snapshot_json));
ALTER TABLE sale_carts ADD COLUMN checkout_validated_at TEXT;
ALTER TABLE sale_carts ADD COLUMN abandoned_at TEXT;

ALTER TABLE sale_orders ADD COLUMN terms_accepted INTEGER NOT NULL DEFAULT 0 CHECK(terms_accepted IN (0,1));
ALTER TABLE sale_orders ADD COLUMN terms_accepted_at TEXT;
ALTER TABLE sale_orders ADD COLUMN marketing_consent INTEGER CHECK(marketing_consent IS NULL OR marketing_consent IN (0,1));
ALTER TABLE sale_orders ADD COLUMN marketing_consent_at TEXT;
ALTER TABLE sale_orders ADD COLUMN payment_method_snapshot_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(payment_method_snapshot_json));

CREATE TRIGGER IF NOT EXISTS trg_sale_orders_guest_checkout_immutable
BEFORE UPDATE OF terms_accepted,terms_accepted_at,marketing_consent,marketing_consent_at,payment_method_snapshot_json ON sale_orders
WHEN OLD.status IN ('placed','confirmed','completed','cancelled')
BEGIN SELECT RAISE(ABORT, 'sale guest checkout snapshot is immutable'); END;

CREATE INDEX IF NOT EXISTS idx_sale_carts_checkout_step ON sale_carts(status, checkout_step, expires_at);
