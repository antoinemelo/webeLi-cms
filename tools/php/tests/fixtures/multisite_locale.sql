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
    url_prefix TEXT NOT NULL,
    hreflang_code TEXT NOT NULL,
    is_default INTEGER NOT NULL DEFAULT 0 CHECK(is_default IN (0,1)),
    is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0,1)),
    PRIMARY KEY(site_id, language_code),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY(language_code) REFERENCES languages(code)
);

CREATE TABLE search_documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    resource_type TEXT NOT NULL CHECK(resource_type IN ('content_entry','taxonomy_term','system')),
    resource_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    path TEXT NOT NULL,
    title TEXT,
    summary TEXT,
    search_text TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(site_id, resource_type, resource_id, language_code),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY(site_id, language_code) REFERENCES site_languages(site_id, language_code) ON DELETE CASCADE
);
