PRAGMA foreign_keys = ON;

CREATE TRIGGER IF NOT EXISTS trg_storefront_asset_invalidation_update AFTER UPDATE ON business_product_assets BEGIN
 INSERT INTO business_storefront_projection_invalidations(site_id,product_id,reason)
 SELECT p.site_id,NEW.product_id,'media' FROM business_products p WHERE p.id=NEW.product_id;
END;

CREATE TRIGGER IF NOT EXISTS trg_storefront_asset_invalidation_delete AFTER DELETE ON business_product_assets BEGIN
 INSERT INTO business_storefront_projection_invalidations(site_id,product_id,reason)
 SELECT p.site_id,OLD.product_id,'media' FROM business_products p WHERE p.id=OLD.product_id;
END;
