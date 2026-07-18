PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS storefront_product_query_index (
    site_id INTEGER NOT NULL, channel_id INTEGER NOT NULL, locale TEXT NOT NULL, product_id INTEGER NOT NULL,
    slug TEXT NOT NULL, name TEXT NOT NULL, name_sort TEXT NOT NULL, search_text TEXT NOT NULL,
    product_type TEXT NOT NULL, brand_id INTEGER, brand_slug TEXT, brand_name TEXT,
    category_id INTEGER, category_slug TEXT, category_name TEXT,
    regular_price_minor INTEGER, final_price_minor INTEGER, discount_amount_minor INTEGER,
    discount_percent_bps INTEGER, currency TEXT, availability_status TEXT NOT NULL,
    newest_at TEXT NOT NULL,
    PRIMARY KEY(site_id,channel_id,locale,product_id),
    UNIQUE(site_id,channel_id,locale,slug),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
    CHECK(channel_id>0), CHECK(product_id>0),
    CHECK(availability_status IN ('in_stock','deliverable','backorder','unavailable','contact_us'))
);
CREATE INDEX IF NOT EXISTS idx_storefront_query_search ON storefront_product_query_index(site_id,channel_id,locale,search_text);
CREATE INDEX IF NOT EXISTS idx_storefront_query_name ON storefront_product_query_index(site_id,channel_id,locale,name_sort,product_id);
CREATE INDEX IF NOT EXISTS idx_storefront_query_newest ON storefront_product_query_index(site_id,channel_id,locale,newest_at,product_id);
CREATE INDEX IF NOT EXISTS idx_storefront_query_price ON storefront_product_query_index(site_id,channel_id,locale,currency,final_price_minor,product_id);
CREATE INDEX IF NOT EXISTS idx_storefront_query_promotion ON storefront_product_query_index(site_id,channel_id,locale,discount_percent_bps,discount_amount_minor,product_id);

CREATE TABLE IF NOT EXISTS storefront_product_facet_values (
    site_id INTEGER NOT NULL, channel_id INTEGER NOT NULL, locale TEXT NOT NULL, product_id INTEGER NOT NULL,
    facet_type TEXT NOT NULL, facet_key TEXT NOT NULL, facet_label TEXT NOT NULL, value_key TEXT NOT NULL, value_label TEXT NOT NULL,
    group_key TEXT NOT NULL DEFAULT '', group_label TEXT NOT NULL DEFAULT '', sort_order INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY(site_id,channel_id,locale,product_id,facet_type,facet_key,value_key),
    FOREIGN KEY(site_id,channel_id,locale,product_id)
        REFERENCES storefront_product_query_index(site_id,channel_id,locale,product_id) ON DELETE CASCADE,
    CHECK(facet_type IN ('brand','category','group','attribute','availability')),
    CHECK(trim(facet_key)<>''), CHECK(trim(facet_label)<>''), CHECK(trim(value_key)<>''), CHECK(trim(value_label)<>'')
);
CREATE INDEX IF NOT EXISTS idx_storefront_facets_lookup ON storefront_product_facet_values(site_id,channel_id,locale,facet_type,facet_key,value_key,product_id);
CREATE INDEX IF NOT EXISTS idx_storefront_facets_group ON storefront_product_facet_values(site_id,channel_id,locale,group_key,facet_type,facet_key,sort_order);
