PRAGMA foreign_keys = ON;

CREATE TABLE languages (
    code TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    locale TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0,1))
);

CREATE TABLE sites (
    id INTEGER PRIMARY KEY,
    site_key TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    default_language_code TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0,1)),
    FOREIGN KEY(default_language_code) REFERENCES languages(code)
);

CREATE TABLE site_languages (
    site_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    locale TEXT NOT NULL,
    url_prefix TEXT NOT NULL DEFAULT '',
    hreflang_code TEXT NOT NULL DEFAULT '',
    fallback_language_code TEXT,
    is_default INTEGER NOT NULL DEFAULT 0 CHECK(is_default IN (0,1)),
    is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0,1)),
    is_rtl INTEGER NOT NULL DEFAULT 0 CHECK(is_rtl IN (0,1)),
    sort_order INTEGER NOT NULL DEFAULT 10,
    PRIMARY KEY(site_id, language_code),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY(language_code) REFERENCES languages(code)
);

CREATE TABLE site_domains (
    id INTEGER PRIMARY KEY,
    site_id INTEGER NOT NULL,
    host TEXT NOT NULL,
    base_path TEXT NOT NULL DEFAULT '',
    scheme TEXT NOT NULL DEFAULT 'https',
    is_primary INTEGER NOT NULL DEFAULT 0 CHECK(is_primary IN (0,1)),
    is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0,1)),
    enforce_https INTEGER NOT NULL DEFAULT 1 CHECK(enforce_https IN (0,1)),
    canonical_host_strategy TEXT NOT NULL DEFAULT 'primary',
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
    UNIQUE(host, base_path)
);
