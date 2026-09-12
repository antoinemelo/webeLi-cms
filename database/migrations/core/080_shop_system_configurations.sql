PRAGMA foreign_keys = ON;

-- Point 39: upgrade existing instances. Fresh installations already receive
-- this table from database/schema/core.sql. No Shop is initialized or
-- activated implicitly by this migration.
CREATE TABLE IF NOT EXISTS cms_shop_configurations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    channel_id INTEGER,
    channel_code TEXT,
    status TEXT NOT NULL DEFAULT 'inactive' CHECK(status IN ('inactive','activating','active','error')),
    currency TEXT NOT NULL DEFAULT 'CHF' CHECK(length(currency) = 3 AND currency = upper(currency)),
    route_path TEXT NOT NULL DEFAULT '/shop' CHECK(route_path = '/shop'),
    theme_key TEXT NOT NULL DEFAULT 'default',
    menu_key TEXT NOT NULL DEFAULT 'main',
    menu_label TEXT NOT NULL DEFAULT 'Boutique',
    menu_position INTEGER NOT NULL DEFAULT 100,
    cart_visible INTEGER NOT NULL DEFAULT 1 CHECK(cart_visible IN (0,1)),
    show_quantities INTEGER NOT NULL DEFAULT 0 CHECK(show_quantities IN (0,1)),
    last_available_threshold INTEGER NOT NULL DEFAULT 1 CHECK(last_available_threshold >= 0),
    draft_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(draft_json)),
    published_json TEXT CHECK(published_json IS NULL OR json_valid(published_json)),
    config_version INTEGER NOT NULL DEFAULT 1 CHECK(config_version > 0),
    published_version INTEGER CHECK(published_version IS NULL OR published_version > 0),
    activated_at TEXT,
    published_at TEXT,
    last_rebuild_at TEXT,
    last_error_code TEXT,
    last_error_message TEXT,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(site_id, language_code),
    CHECK(channel_code IS NULL OR channel_code GLOB '[a-z0-9_-]*'),
    FOREIGN KEY(site_id, language_code) REFERENCES site_languages(site_id, language_code) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_cms_shop_configurations_status
    ON cms_shop_configurations(site_id, status, language_code);
