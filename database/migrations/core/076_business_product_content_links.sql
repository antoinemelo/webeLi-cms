PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS business_product_content_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    content_entry_id INTEGER NOT NULL,
    relation_type TEXT NOT NULL DEFAULT 'product_page' CHECK(relation_type IN ('product_page','storytelling','faq','guide','comparison','seo','related')),
    locale TEXT,
    is_canonical INTEGER NOT NULL DEFAULT 0 CHECK(is_canonical IN (0,1)),
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','inactive')),
    seo_config_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(seo_config_json)),
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE RESTRICT,
    FOREIGN KEY(content_entry_id) REFERENCES content_entries(id) ON DELETE RESTRICT,
    CHECK(site_id > 0 AND product_id > 0),
    CHECK(locale IS NULL OR locale = lower(trim(locale)))
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_business_product_content_links_identity
    ON business_product_content_links(site_id, product_id, content_entry_id, relation_type, COALESCE(locale, ''));
CREATE UNIQUE INDEX IF NOT EXISTS idx_business_product_content_links_canonical
    ON business_product_content_links(site_id, product_id, COALESCE(locale, ''))
    WHERE is_canonical = 1 AND status = 'active';
CREATE INDEX IF NOT EXISTS idx_business_product_content_links_content
    ON business_product_content_links(site_id, content_entry_id, locale, status);

CREATE TABLE IF NOT EXISTS business_product_public_projections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    link_id INTEGER NOT NULL UNIQUE,
    site_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    content_entry_id INTEGER NOT NULL,
    relation_type TEXT NOT NULL,
    locale TEXT,
    is_canonical INTEGER NOT NULL DEFAULT 0 CHECK(is_canonical IN (0,1)),
    is_active INTEGER NOT NULL DEFAULT 0 CHECK(is_active IN (0,1)),
    product_json TEXT NOT NULL CHECK(json_valid(product_json)),
    structured_data_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(structured_data_json)),
    source_product_updated_at TEXT,
    projected_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(link_id) REFERENCES business_product_content_links(id) ON DELETE CASCADE,
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE RESTRICT,
    FOREIGN KEY(content_entry_id) REFERENCES content_entries(id) ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_business_product_public_projections_content
    ON business_product_public_projections(site_id, content_entry_id, locale, is_active);

-- Keep trigger bodies on one physical line: the legacy PHP migrator splits SQL
-- on semicolons followed by a newline.
CREATE TRIGGER IF NOT EXISTS trg_business_product_content_links_site_insert BEFORE INSERT ON business_product_content_links BEGIN SELECT RAISE(ABORT, 'content entry must belong to product link site') WHERE NOT EXISTS (SELECT 1 FROM content_entries ce WHERE ce.id=NEW.content_entry_id AND ce.site_id=NEW.site_id); END;
CREATE TRIGGER IF NOT EXISTS trg_business_product_content_links_site_update BEFORE UPDATE OF site_id, content_entry_id ON business_product_content_links BEGIN SELECT RAISE(ABORT, 'content entry must belong to product link site') WHERE NOT EXISTS (SELECT 1 FROM content_entries ce WHERE ce.id=NEW.content_entry_id AND ce.site_id=NEW.site_id); END;
CREATE TRIGGER IF NOT EXISTS trg_business_product_content_links_content_delete_guard BEFORE DELETE ON content_entries WHEN EXISTS (SELECT 1 FROM business_product_content_links l WHERE l.content_entry_id=OLD.id) BEGIN SELECT RAISE(ABORT, 'remove product content links before deleting content entry'); END;
