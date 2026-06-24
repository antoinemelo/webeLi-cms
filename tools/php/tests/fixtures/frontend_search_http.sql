PRAGMA foreign_keys = ON;

CREATE TABLE sites (
    id INTEGER PRIMARY KEY,
    site_key TEXT NOT NULL,
    name TEXT NOT NULL,
    default_language_code TEXT NOT NULL DEFAULT 'fr',
    is_active INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE site_domains (
    id INTEGER PRIMARY KEY,
    site_id INTEGER NOT NULL,
    host TEXT NOT NULL,
    scheme TEXT NOT NULL DEFAULT 'https',
    base_path TEXT NOT NULL DEFAULT '',
    is_primary INTEGER NOT NULL DEFAULT 1,
    is_active INTEGER NOT NULL DEFAULT 1,
    enforce_https INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE languages (
    code TEXT PRIMARY KEY,
    language_code TEXT,
    native_name TEXT NOT NULL,
    is_default INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE site_languages (
    site_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    is_default INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    fallback_language_code TEXT,
    url_prefix TEXT,
    hreflang_code TEXT,
    is_rtl INTEGER NOT NULL DEFAULT 0,
    sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE site_localizations (
    site_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    site_title TEXT,
    apple_touch_icon_media_id INTEGER
);

CREATE TABLE site_settings (
    site_id INTEGER NOT NULL,
    namespace TEXT NOT NULL,
    setting_key TEXT NOT NULL,
    value_json TEXT NOT NULL DEFAULT '{}'
);

INSERT INTO sites(id, site_key, name, default_language_code, is_active)
VALUES (1, 'main', 'Test CMS', 'fr', 1);

INSERT INTO site_domains(id, site_id, host, scheme, base_path, is_primary, is_active, enforce_https)
VALUES (1, 1, 'example.test', 'https', '', 1, 1, 0);

INSERT INTO languages(code, language_code, native_name, is_default, is_active, sort_order)
VALUES ('fr', 'fr', 'Français', 1, 1, 1);

INSERT INTO site_languages(site_id, language_code, is_default, is_active, fallback_language_code, url_prefix, hreflang_code, is_rtl, sort_order)
VALUES (1, 'fr', 1, 1, NULL, '', 'fr', 0, 1);

INSERT INTO site_localizations(site_id, language_code, site_title, apple_touch_icon_media_id)
VALUES (1, 'fr', 'Test CMS', NULL);
