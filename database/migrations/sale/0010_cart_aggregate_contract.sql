PRAGMA foreign_keys = ON;
ALTER TABLE sale_carts ADD COLUMN cart_kind TEXT CHECK(cart_kind IN ('web','pos','admin'));
ALTER TABLE sale_carts ADD COLUMN locale TEXT;
ALTER TABLE sale_carts ADD COLUMN customer_ref_id INTEGER;
ALTER TABLE sale_carts ADD COLUMN public_token_hash TEXT;
ALTER TABLE sale_carts ADD COLUMN register_session_id INTEGER REFERENCES sale_cash_sessions(id) ON DELETE RESTRICT;
ALTER TABLE sale_carts ADD COLUMN calculation_version INTEGER NOT NULL DEFAULT 1;
UPDATE sale_carts SET cart_kind=CASE WHEN cart_token_hash IS NOT NULL THEN 'web' WHEN EXISTS(SELECT 1 FROM sale_channels c WHERE c.id=sale_carts.channel_id AND c.channel_type='pos') THEN 'pos' ELSE 'admin' END WHERE cart_kind IS NULL;
UPDATE sale_carts SET locale=COALESCE((SELECT default_language FROM sale_channels c WHERE c.id=sale_carts.channel_id),'fr') WHERE locale IS NULL;
UPDATE sale_carts SET public_token_hash=cart_token_hash WHERE public_token_hash IS NULL AND cart_token_hash IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_sale_carts_public_token ON sale_carts(public_token_hash) WHERE public_token_hash IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_sale_carts_kind_status ON sale_carts(site_id,cart_kind,status,expires_at);
CREATE INDEX IF NOT EXISTS idx_sale_carts_register_session ON sale_carts(register_session_id,status);
CREATE TRIGGER IF NOT EXISTS trg_sale_carts_context_immutable
BEFORE UPDATE OF site_id,channel_id,cart_kind,currency ON sale_carts
WHEN NEW.site_id<>OLD.site_id OR NEW.channel_id<>OLD.channel_id OR NEW.cart_kind<>OLD.cart_kind OR NEW.currency<>OLD.currency
BEGIN SELECT RAISE(ABORT,'sale.cart_context_immutable'); END;
CREATE TRIGGER IF NOT EXISTS trg_sale_carts_public_token_policy_insert
BEFORE INSERT ON sale_carts WHEN NEW.cart_kind<>'web' AND NEW.public_token_hash IS NOT NULL
BEGIN SELECT RAISE(ABORT,'sale.cart_public_token_forbidden'); END;
CREATE TRIGGER IF NOT EXISTS trg_sale_carts_public_token_policy_update
BEFORE UPDATE OF public_token_hash,cart_kind ON sale_carts WHEN NEW.cart_kind<>'web' AND NEW.public_token_hash IS NOT NULL
BEGIN SELECT RAISE(ABORT,'sale.cart_public_token_forbidden'); END;

ALTER TABLE sale_cart_lines ADD COLUMN options_json TEXT NOT NULL DEFAULT '{}';
ALTER TABLE sale_cart_lines ADD COLUMN personalization_json TEXT NOT NULL DEFAULT '{}';
ALTER TABLE sale_cart_lines ADD COLUMN fulfillment_class TEXT NOT NULL DEFAULT 'shipping';
ALTER TABLE sale_cart_lines ADD COLUMN availability_state TEXT NOT NULL DEFAULT 'available';
ALTER TABLE sale_cart_lines ADD COLUMN calculation_version INTEGER NOT NULL DEFAULT 1;
ALTER TABLE sale_cart_lines ADD COLUMN previous_unit_price_minor INTEGER;
ALTER TABLE sale_cart_lines ADD COLUMN price_changed_at TEXT;
UPDATE sale_cart_lines SET sellable_id=business_variant_id WHERE sellable_id IS NULL;
UPDATE sale_cart_lines SET fulfillment_class=CASE product_type WHEN 'service' THEN 'none' WHEN 'gift_card' THEN 'digital' WHEN 'digital' THEN 'digital' ELSE 'shipping' END;
