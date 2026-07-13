PRAGMA foreign_keys = ON;

ALTER TABLE sale_channels ADD COLUMN channel_kind TEXT CHECK(channel_kind IN ('storefront','pos','admin','partner'));
ALTER TABLE sale_channels ADD COLUMN is_default INTEGER NOT NULL DEFAULT 0 CHECK(is_default IN (0,1));
UPDATE sale_channels SET channel_kind=CASE channel_type WHEN 'ecommerce' THEN 'storefront' WHEN 'pos' THEN 'pos' ELSE 'admin' END WHERE channel_kind IS NULL;
UPDATE sale_channels SET is_default=1 WHERE code IN ('web-main','pos-main','admin-manual');
CREATE UNIQUE INDEX IF NOT EXISTS idx_sale_channels_default_kind ON sale_channels(site_id,channel_kind) WHERE is_default=1 AND status<>'archived';

CREATE TABLE IF NOT EXISTS sale_channel_checkout_configs (
    channel_id INTEGER PRIMARY KEY,
    site_id INTEGER NOT NULL,
    cart_enabled INTEGER NOT NULL DEFAULT 1 CHECK(cart_enabled IN (0,1)),
    checkout_enabled INTEGER NOT NULL DEFAULT 1 CHECK(checkout_enabled IN (0,1)),
    guest_checkout_enabled INTEGER NOT NULL DEFAULT 1 CHECK(guest_checkout_enabled IN (0,1)),
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','disabled')),
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(channel_id) REFERENCES sale_channels(id) ON DELETE CASCADE,
    CHECK(site_id>0)
);
CREATE TABLE IF NOT EXISTS sale_inventory_channel_configs (
    channel_id INTEGER PRIMARY KEY,
    site_id INTEGER NOT NULL,
    stock_location_id INTEGER NOT NULL,
    availability_policy TEXT NOT NULL DEFAULT 'available' CHECK(availability_policy IN ('available','on_hand','allow_backorder')),
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','disabled')),
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(channel_id) REFERENCES sale_channels(id) ON DELETE CASCADE,
    FOREIGN KEY(stock_location_id) REFERENCES sale_stock_locations(id) ON DELETE RESTRICT,
    CHECK(site_id>0)
);
ALTER TABLE sale_pos_registers ADD COLUMN stock_location_id INTEGER REFERENCES sale_stock_locations(id) ON DELETE RESTRICT;

INSERT OR IGNORE INTO sale_channel_checkout_configs(channel_id,site_id,cart_enabled,checkout_enabled,guest_checkout_enabled,status)
SELECT id,site_id,1,1,CASE WHEN channel_kind='storefront' THEN 1 ELSE 0 END,CASE WHEN status='active' THEN 'active' ELSE 'disabled' END FROM sale_channels;
INSERT OR IGNORE INTO sale_stock_locations(site_id,code,name,location_type,status)
SELECT DISTINCT site_id,'channel-default','Stock canal par défaut','main','active' FROM sale_channels;
INSERT OR IGNORE INTO sale_inventory_channel_configs(channel_id,site_id,stock_location_id,availability_policy,status)
SELECT c.id,c.site_id,l.id,'available',CASE WHEN c.status='active' THEN 'active' ELSE 'disabled' END FROM sale_channels c JOIN sale_stock_locations l ON l.site_id=c.site_id AND l.code='channel-default';
UPDATE sale_pos_registers SET stock_location_id=(SELECT id FROM sale_stock_locations l WHERE l.site_id=sale_pos_registers.site_id AND l.code='channel-default') WHERE stock_location_id IS NULL;
