PRAGMA foreign_keys = ON;

-- Existing variant ids become stable sellable ids: no cart/order identifier is renumbered.
INSERT INTO business_product_variants(product_id,status,sku,name,track_stock,allow_backorder)
SELECT p.id,CASE WHEN p.status='active' THEN 'active' ELSE 'draft' END,'AUTO-' || p.id,'Default',p.track_stock,p.allow_backorder
FROM business_products p
WHERE p.archived_at IS NULL AND NOT EXISTS(SELECT 1 FROM business_product_variants v WHERE v.product_id=p.id AND v.archived_at IS NULL);

CREATE TABLE IF NOT EXISTS business_sellables (
    sellable_id INTEGER PRIMARY KEY,
    site_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    variant_id INTEGER NOT NULL UNIQUE,
    kind TEXT NOT NULL CHECK(kind IN ('simple','variant','service','gift_card','bundle')),
    is_default INTEGER NOT NULL DEFAULT 0 CHECK(is_default IN (0,1)),
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','inactive','archived')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(product_id) REFERENCES business_products(id) ON DELETE CASCADE,
    FOREIGN KEY(variant_id) REFERENCES business_product_variants(id) ON DELETE CASCADE,
    CHECK(sellable_id=variant_id), CHECK(site_id>0)
);
CREATE INDEX IF NOT EXISTS idx_business_sellables_product ON business_sellables(site_id,product_id,status,is_default);
CREATE UNIQUE INDEX IF NOT EXISTS idx_business_sellables_default ON business_sellables(product_id) WHERE is_default=1 AND status='active';
INSERT OR IGNORE INTO business_sellables(sellable_id,site_id,product_id,variant_id,kind,is_default,status)
SELECT v.id,p.site_id,p.id,v.id,
       CASE p.type WHEN 'service' THEN 'service' WHEN 'gift_card' THEN 'gift_card' WHEN 'bundle' THEN 'bundle'
            ELSE CASE WHEN (SELECT COUNT(*) FROM business_product_variants vx WHERE vx.product_id=p.id AND vx.archived_at IS NULL)>1 THEN 'variant' ELSE 'simple' END END,
       CASE WHEN v.id=(SELECT MIN(vd.id) FROM business_product_variants vd WHERE vd.product_id=p.id AND vd.archived_at IS NULL) THEN 1 ELSE 0 END,
       CASE WHEN p.status='active' AND v.status='active' THEN 'active' WHEN p.status='archived' OR v.status='archived' THEN 'archived' ELSE 'inactive' END
FROM business_product_variants v JOIN business_products p ON p.id=v.product_id
WHERE p.archived_at IS NULL AND v.archived_at IS NULL;

CREATE TABLE IF NOT EXISTS business_storefront_projection_invalidations (
    id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER NOT NULL, product_id INTEGER,
    reason TEXT NOT NULL CHECK(reason IN ('product','variant','price','visibility','availability','media','collection')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, processed_at TEXT, CHECK(site_id>0)
);
CREATE INDEX IF NOT EXISTS idx_business_storefront_invalidations_pending ON business_storefront_projection_invalidations(processed_at,site_id,product_id);

CREATE TRIGGER IF NOT EXISTS trg_storefront_product_invalidation AFTER UPDATE ON business_products BEGIN
 INSERT INTO business_storefront_projection_invalidations(site_id,product_id,reason) VALUES(NEW.site_id,NEW.id,'product'); END;
CREATE TRIGGER IF NOT EXISTS trg_storefront_variant_invalidation AFTER UPDATE ON business_product_variants BEGIN
 INSERT INTO business_storefront_projection_invalidations(site_id,product_id,reason) SELECT p.site_id,NEW.product_id,'variant' FROM business_products p WHERE p.id=NEW.product_id; END;
CREATE TRIGGER IF NOT EXISTS trg_storefront_price_invalidation_insert AFTER INSERT ON business_product_base_prices BEGIN
 INSERT INTO business_storefront_projection_invalidations(site_id,product_id,reason) SELECT p.site_id,NEW.product_id,'price' FROM business_products p WHERE p.id=NEW.product_id; END;
CREATE TRIGGER IF NOT EXISTS trg_storefront_price_invalidation_update AFTER UPDATE ON business_product_base_prices BEGIN
 INSERT INTO business_storefront_projection_invalidations(site_id,product_id,reason) SELECT p.site_id,NEW.product_id,'price' FROM business_products p WHERE p.id=NEW.product_id; END;
CREATE TRIGGER IF NOT EXISTS trg_storefront_visibility_invalidation AFTER INSERT ON business_product_channel_visibility BEGIN
 INSERT INTO business_storefront_projection_invalidations(site_id,product_id,reason) VALUES(NEW.site_id,NEW.product_id,'visibility'); END;
