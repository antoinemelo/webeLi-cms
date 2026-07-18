PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS storefront_product_publication_history (
    site_id INTEGER NOT NULL, channel_id INTEGER NOT NULL, locale TEXT NOT NULL, product_id INTEGER NOT NULL,
    first_published_at TEXT NOT NULL, last_published_at TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0,1)),
    PRIMARY KEY(site_id,channel_id,locale,product_id),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_storefront_publication_newest ON storefront_product_publication_history(site_id,channel_id,locale,is_active,first_published_at,product_id);

CREATE TABLE IF NOT EXISTS storefront_analytics_daily (
    site_id INTEGER NOT NULL, locale TEXT NOT NULL, event_date TEXT NOT NULL,
    event_type TEXT NOT NULL CHECK(event_type IN ('product_view','search')), entity_key TEXT NOT NULL,
    event_count INTEGER NOT NULL DEFAULT 0 CHECK(event_count >= 0), updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(site_id,locale,event_date,event_type,entity_key),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
    CHECK(event_date GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'), CHECK(trim(entity_key)<>'')
);
CREATE INDEX IF NOT EXISTS idx_storefront_analytics_window ON storefront_analytics_daily(site_id,locale,event_type,event_date,event_count,entity_key);

CREATE TABLE IF NOT EXISTS storefront_analytics_dedup (
    site_id INTEGER NOT NULL, locale TEXT NOT NULL, event_type TEXT NOT NULL CHECK(event_type IN ('product_view','search')),
    entity_key TEXT NOT NULL, dedupe_hash TEXT NOT NULL, bucket_started_at TEXT NOT NULL, expires_at TEXT NOT NULL,
    PRIMARY KEY(site_id,locale,event_type,entity_key,dedupe_hash,bucket_started_at),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
    CHECK(length(dedupe_hash)=64)
);
CREATE INDEX IF NOT EXISTS idx_storefront_analytics_dedup_expiry ON storefront_analytics_dedup(expires_at);
