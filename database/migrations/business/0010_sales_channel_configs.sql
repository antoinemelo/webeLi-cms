PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS business_sales_channel_configs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    channel_id INTEGER NOT NULL UNIQUE,
    site_id INTEGER NOT NULL,
    catalog_channel TEXT NOT NULL CHECK(catalog_channel IN ('public','ecommerce','pos','catalogue','admin','partner')),
    price_list_code TEXT,
    visibility_policy TEXT NOT NULL DEFAULT 'published' CHECK(visibility_policy IN ('published','private','all')),
    default_currency TEXT NOT NULL DEFAULT 'CHF' CHECK(length(default_currency)=3 AND default_currency=upper(default_currency)),
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','disabled')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    CHECK(channel_id>0), CHECK(site_id>0)
);
CREATE INDEX IF NOT EXISTS idx_business_sales_channel_configs_site ON business_sales_channel_configs(site_id,status,catalog_channel);
INSERT OR IGNORE INTO business_sales_channel_configs(channel_id,site_id,catalog_channel,visibility_policy,default_currency,status) VALUES
 (1,1,'admin','all','CHF','active'),(2,1,'pos','published','CHF','disabled'),(3,1,'ecommerce','published','CHF','active');
