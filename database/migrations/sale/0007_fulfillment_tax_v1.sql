PRAGMA foreign_keys = ON;

ALTER TABLE sale_carts ADD COLUMN shipping_total_minor INTEGER NOT NULL DEFAULT 0 CHECK(shipping_total_minor >= 0);
ALTER TABLE sale_cart_lines ADD COLUMN tax_class_code TEXT NOT NULL DEFAULT 'standard';
ALTER TABLE sale_order_lines ADD COLUMN tax_class_code TEXT NOT NULL DEFAULT 'standard';

CREATE TABLE IF NOT EXISTS sale_fulfillment_zones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    name TEXT NOT NULL,
    country_codes_json TEXT NOT NULL DEFAULT '[]' CHECK(json_valid(country_codes_json)),
    postal_prefixes_json TEXT NOT NULL DEFAULT '[]' CHECK(json_valid(postal_prefixes_json)),
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('draft','active','disabled')),
    active_from TEXT,
    active_until TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    UNIQUE(site_id,code),
    CHECK(site_id>0), CHECK(trim(code)<>''), CHECK(trim(name)<>'')
);

CREATE TABLE IF NOT EXISTS sale_fulfillment_methods (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    zone_id INTEGER,
    code TEXT NOT NULL,
    label_fr TEXT NOT NULL,
    label_en TEXT NOT NULL,
    fulfillment_type TEXT NOT NULL CHECK(fulfillment_type IN ('shipping','pickup','none')),
    flat_rate_minor INTEGER NOT NULL DEFAULT 0 CHECK(flat_rate_minor>=0),
    free_above_minor INTEGER CHECK(free_above_minor IS NULL OR free_above_minor>=0),
    requires_shipping_address INTEGER NOT NULL DEFAULT 1 CHECK(requires_shipping_address IN (0,1)),
    allow_non_physical INTEGER NOT NULL DEFAULT 0 CHECK(allow_non_physical IN (0,1)),
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('draft','active','disabled')),
    active_from TEXT,
    active_until TEXT,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    FOREIGN KEY(zone_id) REFERENCES sale_fulfillment_zones(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    UNIQUE(site_id,code),
    CHECK(site_id>0), CHECK(trim(code)<>''), CHECK(trim(label_fr)<>''), CHECK(trim(label_en)<>'')
);

CREATE INDEX IF NOT EXISTS idx_sale_fulfillment_zones_active ON sale_fulfillment_zones(site_id,status,active_from,active_until);
CREATE INDEX IF NOT EXISTS idx_sale_fulfillment_methods_active ON sale_fulfillment_methods(site_id,status,sort_order,code);

INSERT OR IGNORE INTO sale_fulfillment_zones(site_id,code,name,country_codes_json,status)
SELECT DISTINCT site_id,'ch','Suisse','["CH"]','active' FROM sale_channels;
INSERT OR IGNORE INTO sale_fulfillment_methods(site_id,zone_id,code,label_fr,label_en,fulfillment_type,flat_rate_minor,free_above_minor,requires_shipping_address,allow_non_physical,status,sort_order)
SELECT c.site_id,z.id,'standard','Livraison standard','Standard delivery','shipping',900,10000,1,0,'active',10
FROM (SELECT DISTINCT site_id FROM sale_channels) c JOIN sale_fulfillment_zones z ON z.site_id=c.site_id AND z.code='ch';
INSERT OR IGNORE INTO sale_fulfillment_methods(site_id,code,label_fr,label_en,fulfillment_type,flat_rate_minor,requires_shipping_address,allow_non_physical,status,sort_order)
SELECT DISTINCT site_id,'pickup','Retrait local','Local pickup','pickup',0,0,0,'active',20 FROM sale_channels;
INSERT OR IGNORE INTO sale_fulfillment_methods(site_id,code,label_fr,label_en,fulfillment_type,flat_rate_minor,requires_shipping_address,allow_non_physical,status,sort_order)
SELECT DISTINCT site_id,'none','Aucun fulfillment','No fulfillment','none',0,0,1,'active',30 FROM sale_channels;
