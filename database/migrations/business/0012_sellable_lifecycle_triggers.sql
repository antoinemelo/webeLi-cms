PRAGMA foreign_keys = ON;
CREATE TRIGGER IF NOT EXISTS trg_business_sellable_variant_insert AFTER INSERT ON business_product_variants BEGIN
 INSERT OR IGNORE INTO business_sellables(sellable_id,site_id,product_id,variant_id,kind,is_default,status)
 SELECT NEW.id,p.site_id,p.id,NEW.id,CASE p.type WHEN 'service' THEN 'service' WHEN 'gift_card' THEN 'gift_card' WHEN 'bundle' THEN 'bundle'
 ELSE CASE WHEN EXISTS(SELECT 1 FROM business_sellables s WHERE s.product_id=p.id) THEN 'variant' ELSE 'simple' END END,
 CASE WHEN EXISTS(SELECT 1 FROM business_sellables s WHERE s.product_id=p.id AND s.status='active') THEN 0 ELSE 1 END,
 CASE WHEN p.status='active' AND NEW.status='active' THEN 'active' WHEN NEW.status='archived' THEN 'archived' ELSE 'inactive' END
 FROM business_products p WHERE p.id=NEW.product_id;
END;
CREATE TRIGGER IF NOT EXISTS trg_business_sellable_variant_update AFTER UPDATE OF status,product_id ON business_product_variants BEGIN
 UPDATE business_sellables SET product_id=NEW.product_id,status=CASE WHEN NEW.status='active' AND (SELECT status FROM business_products WHERE id=NEW.product_id)='active' THEN 'active' WHEN NEW.status='archived' THEN 'archived' ELSE 'inactive' END,updated_at=CURRENT_TIMESTAMP WHERE variant_id=NEW.id;
END;
CREATE TRIGGER IF NOT EXISTS trg_storefront_asset_invalidation AFTER INSERT ON business_product_assets BEGIN
 INSERT INTO business_storefront_projection_invalidations(site_id,product_id,reason) SELECT p.site_id,NEW.product_id,'media' FROM business_products p WHERE p.id=NEW.product_id; END;
CREATE TRIGGER IF NOT EXISTS trg_storefront_visibility_invalidation_update AFTER UPDATE ON business_product_channel_visibility BEGIN
 INSERT INTO business_storefront_projection_invalidations(site_id,product_id,reason) VALUES(NEW.site_id,NEW.product_id,'visibility'); END;
CREATE TRIGGER IF NOT EXISTS trg_storefront_price_invalidation_delete AFTER DELETE ON business_product_base_prices BEGIN
 INSERT INTO business_storefront_projection_invalidations(site_id,product_id,reason) SELECT p.site_id,OLD.product_id,'price' FROM business_products p WHERE p.id=OLD.product_id; END;
