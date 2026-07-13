PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS storefront_product_projections (
    id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER NOT NULL, channel_id INTEGER NOT NULL, locale TEXT NOT NULL,
    product_id INTEGER NOT NULL, slug TEXT NOT NULL, collection_id INTEGER, dto_version INTEGER NOT NULL DEFAULT 1,
    is_indexable INTEGER NOT NULL DEFAULT 1 CHECK(is_indexable IN (0,1)), dto_json TEXT NOT NULL CHECK(json_valid(dto_json)),
    source_hash TEXT NOT NULL, projected_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(site_id,channel_id,locale,product_id), UNIQUE(site_id,channel_id,locale,slug),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE, CHECK(channel_id>0), CHECK(product_id>0)
);
CREATE INDEX IF NOT EXISTS idx_storefront_products_listing ON storefront_product_projections(site_id,channel_id,locale,collection_id,slug);
CREATE TABLE IF NOT EXISTS storefront_collection_projections (
    id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER NOT NULL, channel_id INTEGER NOT NULL, locale TEXT NOT NULL,
    collection_id INTEGER NOT NULL, slug TEXT NOT NULL, dto_version INTEGER NOT NULL DEFAULT 1,
    dto_json TEXT NOT NULL CHECK(json_valid(dto_json)), source_hash TEXT NOT NULL, projected_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(site_id,channel_id,locale,collection_id), UNIQUE(site_id,channel_id,locale,slug),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE, CHECK(channel_id>0), CHECK(collection_id>0)
);

INSERT OR IGNORE INTO editor_block_types(block_type,label,category,schema_json,sort_order) VALUES
('featured_product','Produit vedette','commerce','{"fields":["product_id","layout","show_price","show_availability","cta_label"],"required":["product_id"]}',200),
('product_card','Carte produit','commerce','{"fields":["product_id","show_price","show_availability","cta_label"],"required":["product_id"]}',201),
('product_grid','Grille produits','commerce','{"fields":["product_ids","collection_id","columns","limit","sort"],"required_any":[["product_ids","collection_id"]]}',202),
('collection_grid','Grille collections','commerce','{"fields":["collection_ids","columns","limit"]}',203),
('product_detail','Détail produit','commerce','{"fields":["product_id","show_media","show_variants","show_description"],"required":["product_id"]}',204),
('add_to_cart','Ajout au panier','commerce','{"fields":["sellable_id","quantity","label"],"required":["sellable_id"]}',205);
