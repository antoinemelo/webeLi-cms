PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS cms_sales_channel_storefronts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    channel_id INTEGER NOT NULL UNIQUE,
    site_id INTEGER NOT NULL,
    domain_id INTEGER,
    route_prefix TEXT NOT NULL DEFAULT '/',
    is_default INTEGER NOT NULL DEFAULT 0 CHECK(is_default IN (0,1)),
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','disabled')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY(domain_id) REFERENCES site_domains(id) ON DELETE SET NULL,
    CHECK(channel_id>0),
    CHECK(route_prefix LIKE '/%')
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_cms_sales_channel_storefront_default
    ON cms_sales_channel_storefronts(site_id) WHERE is_default=1 AND status='active';
INSERT OR IGNORE INTO cms_sales_channel_storefronts(channel_id,site_id,domain_id,route_prefix,is_default,status)
SELECT 3,s.id,(SELECT id FROM site_domains d WHERE d.site_id=s.id AND d.is_primary=1 ORDER BY id LIMIT 1),'/',1,'active'
FROM sites s WHERE s.id=1;
