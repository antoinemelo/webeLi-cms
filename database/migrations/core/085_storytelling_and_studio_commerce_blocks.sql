PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS business_storytellings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    storytelling_key TEXT NOT NULL,
    title TEXT NOT NULL,
    eyebrow TEXT NOT NULL DEFAULT '',
    body_markdown TEXT NOT NULL DEFAULT '',
    image_media_id INTEGER,
    image_alt TEXT NOT NULL DEFAULT '',
    cta_label TEXT NOT NULL DEFAULT '',
    cta_url TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','published')),
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(site_id,language_code,storytelling_key),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY(site_id,language_code) REFERENCES site_languages(site_id,language_code) ON DELETE CASCADE,
    FOREIGN KEY(image_media_id) REFERENCES media_assets(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_business_storytellings_context ON business_storytellings(site_id,language_code,status,title);

DELETE FROM editor_block_types WHERE block_type IN ('featured_product','product_card','product_grid','collection_grid','product_detail','add_to_cart');

INSERT INTO editor_block_types(block_type,label,category,schema_json,is_enabled,sort_order) VALUES
('commerce_product','Produit ou variante','commerce','{"fields":["product_id","sellable_id","show_price","show_promotion","show_availability","show_cta","empty_state","empty_message"],"required":["product_id"],"stable_references":["product_id","sellable_id"]}',1,200),
('commerce_product_variants','Variantes d’un produit','commerce','{"fields":["product_id","columns","limit","pagination","show_price","show_promotion","show_availability","show_cta","empty_state","empty_message"],"required":["product_id"],"stable_references":["product_id"]}',1,201),
('commerce_product_list','Liste de produits','commerce','{"fields":["selection_mode","product_ids","brand","category","group","attribute_code","attribute_values","promotion_rule","relation_type","source_product_id","manual_product_ids","window_days","limit","sort","columns","pagination","show_price","show_promotion","show_availability","show_cta","empty_state","empty_message"],"selection_modes":["explicit","brand","category","group","attribute","promotion","new","popular","relation"],"stable_references":["product_ids","source_product_id","manual_product_ids"]}',1,202),
('storytelling','Storytelling','commerce','{"fields":["storytelling_id"],"required":["storytelling_id"],"stable_references":["storytelling_id"]}',1,203)
ON CONFLICT(block_type) DO UPDATE SET label=excluded.label,category=excluded.category,schema_json=excluded.schema_json,is_enabled=excluded.is_enabled,sort_order=excluded.sort_order;
