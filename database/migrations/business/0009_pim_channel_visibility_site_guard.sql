PRAGMA foreign_keys = ON;

CREATE TRIGGER IF NOT EXISTS trg_business_product_channel_visibility_site_insert
BEFORE INSERT ON business_product_channel_visibility
BEGIN
    SELECT RAISE(ABORT, 'business channel visibility product must belong to site')
    WHERE NOT EXISTS (SELECT 1 FROM business_products p WHERE p.id = NEW.product_id AND p.site_id = NEW.site_id);
END;

CREATE TRIGGER IF NOT EXISTS trg_business_product_channel_visibility_site_update
BEFORE UPDATE OF site_id, product_id ON business_product_channel_visibility
BEGIN
    SELECT RAISE(ABORT, 'business channel visibility product must belong to site')
    WHERE NOT EXISTS (SELECT 1 FROM business_products p WHERE p.id = NEW.product_id AND p.site_id = NEW.site_id);
END;
