PRAGMA foreign_keys = ON;

CREATE TABLE content_types (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type_key TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    api_enabled INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE content_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    content_type_id INTEGER NOT NULL,
    entry_key TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'draft',
    is_active INTEGER NOT NULL DEFAULT 1,
    published_at TEXT,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE public_content_snapshots (
    site_id INTEGER NOT NULL,
    resource_type TEXT NOT NULL,
    resource_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    source_published_revision_id INTEGER NOT NULL,
    route_path TEXT NOT NULL,
    title TEXT,
    document_json TEXT NOT NULL DEFAULT '{}',
    published_at TEXT
);

CREATE TABLE content_entry_publications (
    site_id INTEGER NOT NULL,
    entry_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    published_revision_id INTEGER NOT NULL,
    workflow_status TEXT NOT NULL
);

CREATE TABLE routes (
    site_id INTEGER NOT NULL,
    resource_type TEXT NOT NULL,
    resource_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    full_path TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active',
    is_primary INTEGER NOT NULL DEFAULT 1,
    is_canonical INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE seo_metadata (
    site_id INTEGER NOT NULL,
    resource_type TEXT NOT NULL,
    resource_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    meta_title TEXT,
    meta_description TEXT,
    meta_robots TEXT
);

CREATE TABLE search_documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    resource_type TEXT NOT NULL,
    resource_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    path TEXT NOT NULL,
    title TEXT,
    summary TEXT,
    search_text TEXT NOT NULL,
    source_published_revision_id INTEGER NOT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE taxonomies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    taxonomy_key TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE taxonomy_terms (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    taxonomy_id INTEGER NOT NULL,
    term_key TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE taxonomy_term_localizations (
    term_id INTEGER NOT NULL,
    taxonomy_id INTEGER NOT NULL,
    site_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    slug TEXT
);

CREATE TABLE content_entry_taxonomy_terms (
    entry_id INTEGER NOT NULL,
    term_id INTEGER NOT NULL
);
