PRAGMA foreign_keys = ON;

CREATE TABLE analytics_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    event_name TEXT NOT NULL,
    site_key TEXT,
    resource_type TEXT CHECK(resource_type IS NULL OR resource_type IN ('content_entry','taxonomy_term','system')),
    resource_id INTEGER,
    language_code TEXT,
    payload_json TEXT CHECK(payload_json IS NULL OR json_valid(payload_json)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY(site_id, language_code) REFERENCES site_languages(site_id, language_code) ON DELETE CASCADE
);

CREATE TABLE content_quality_snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    resource_type TEXT NOT NULL CHECK(resource_type IN ('content_entry','taxonomy_term','system')),
    resource_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    seo_score INTEGER,
    readability_score INTEGER,
    completeness_score INTEGER,
    internal_links_count INTEGER,
    detected_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY(site_id, language_code) REFERENCES site_languages(site_id, language_code) ON DELETE CASCADE
);
-- Polymorphic resource integrity guards for analytics/quality extension tables.
-- These triggers assume the analytics schema is installed in the same SQLite
-- database as the core CMS schema.

CREATE TRIGGER IF NOT EXISTS trg_analytics_events_resource_insert
BEFORE INSERT ON analytics_events
FOR EACH ROW
WHEN NEW.resource_type IS NOT NULL OR NEW.resource_id IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'analytics_events.resource_type and resource_id must be both NULL or both set') WHERE (NEW.resource_type IS NULL) <> (NEW.resource_id IS NULL);
    SELECT RAISE(ABORT, 'analytics_events.resource_type/resource_id invalid for site')
    WHERE NOT ((NEW.resource_type = 'content_entry' AND EXISTS (SELECT 1 FROM content_entries ce WHERE ce.id = NEW.resource_id AND ce.site_id = NEW.site_id)) OR (NEW.resource_type = 'taxonomy_term' AND EXISTS (SELECT 1 FROM taxonomy_terms tt JOIN taxonomies tx ON tx.id = tt.taxonomy_id WHERE tt.id = NEW.resource_id AND tx.site_id = NEW.site_id)) OR (NEW.resource_type = 'system' AND NEW.resource_id = 0));
END;

CREATE TRIGGER IF NOT EXISTS trg_analytics_events_resource_update
BEFORE UPDATE OF site_id, resource_type, resource_id ON analytics_events
FOR EACH ROW
WHEN NEW.resource_type IS NOT NULL OR NEW.resource_id IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'analytics_events.resource_type and resource_id must be both NULL or both set') WHERE (NEW.resource_type IS NULL) <> (NEW.resource_id IS NULL);
    SELECT RAISE(ABORT, 'analytics_events.resource_type/resource_id invalid for site')
    WHERE NOT ((NEW.resource_type = 'content_entry' AND EXISTS (SELECT 1 FROM content_entries ce WHERE ce.id = NEW.resource_id AND ce.site_id = NEW.site_id)) OR (NEW.resource_type = 'taxonomy_term' AND EXISTS (SELECT 1 FROM taxonomy_terms tt JOIN taxonomies tx ON tx.id = tt.taxonomy_id WHERE tt.id = NEW.resource_id AND tx.site_id = NEW.site_id)) OR (NEW.resource_type = 'system' AND NEW.resource_id = 0));
END;

CREATE TRIGGER IF NOT EXISTS trg_content_quality_snapshots_resource_insert
BEFORE INSERT ON content_quality_snapshots
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'content_quality_snapshots.resource_type/resource_id invalid for site')
    WHERE NOT ((NEW.resource_type = 'content_entry' AND EXISTS (SELECT 1 FROM content_entries ce WHERE ce.id = NEW.resource_id AND ce.site_id = NEW.site_id)) OR (NEW.resource_type = 'taxonomy_term' AND EXISTS (SELECT 1 FROM taxonomy_terms tt JOIN taxonomies tx ON tx.id = tt.taxonomy_id WHERE tt.id = NEW.resource_id AND tx.site_id = NEW.site_id)) OR (NEW.resource_type = 'system' AND NEW.resource_id = 0));
END;

CREATE TRIGGER IF NOT EXISTS trg_content_quality_snapshots_resource_update
BEFORE UPDATE OF site_id, resource_type, resource_id ON content_quality_snapshots
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'content_quality_snapshots.resource_type/resource_id invalid for site')
    WHERE NOT ((NEW.resource_type = 'content_entry' AND EXISTS (SELECT 1 FROM content_entries ce WHERE ce.id = NEW.resource_id AND ce.site_id = NEW.site_id)) OR (NEW.resource_type = 'taxonomy_term' AND EXISTS (SELECT 1 FROM taxonomy_terms tt JOIN taxonomies tx ON tx.id = tt.taxonomy_id WHERE tt.id = NEW.resource_id AND tx.site_id = NEW.site_id)) OR (NEW.resource_type = 'system' AND NEW.resource_id = 0));
END;

