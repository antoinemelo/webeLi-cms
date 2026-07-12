PRAGMA foreign_keys = ON;

ALTER TABLE business_products ADD COLUMN external_id TEXT;
ALTER TABLE business_product_completeness_rules ADD COLUMN product_type TEXT NOT NULL DEFAULT 'all'
    CHECK(product_type IN ('all','physical','service','gift_card','bundle'));
ALTER TABLE business_product_completeness_rules ADD COLUMN severity TEXT NOT NULL DEFAULT 'block'
    CHECK(severity IN ('block','warn'));
ALTER TABLE business_product_completeness_rules ADD COLUMN required_language TEXT;

CREATE UNIQUE INDEX IF NOT EXISTS idx_business_products_external_id
    ON business_products(site_id, external_id)
    WHERE external_id IS NOT NULL AND archived_at IS NULL;

CREATE TABLE IF NOT EXISTS business_product_channel_visibility (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    channel TEXT NOT NULL CHECK(channel IN ('public','ecommerce','pos','catalogue')),
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('draft','active','archived')),
    starts_at TEXT,
    ends_at TEXT,
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(product_id) REFERENCES business_products(id) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE(site_id, product_id, channel),
    CHECK(site_id > 0),
    CHECK(ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at)
);

CREATE INDEX IF NOT EXISTS idx_business_product_channel_visibility_context
    ON business_product_channel_visibility(site_id, channel, status, starts_at, ends_at);

CREATE TABLE IF NOT EXISTS business_catalog_import_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    idempotency_key TEXT NOT NULL,
    format_version TEXT NOT NULL,
    checksum TEXT NOT NULL,
    status TEXT NOT NULL CHECK(status IN ('applied','rejected')),
    rows_total INTEGER NOT NULL DEFAULT 0 CHECK(rows_total >= 0),
    changed_rows INTEGER NOT NULL DEFAULT 0 CHECK(changed_rows >= 0),
    summary_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(summary_json)),
    created_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(site_id, idempotency_key),
    CHECK(site_id > 0),
    CHECK(trim(idempotency_key) <> ''),
    CHECK(trim(format_version) <> ''),
    CHECK(trim(checksum) <> '')
);

CREATE INDEX IF NOT EXISTS idx_business_catalog_import_runs_site_created
    ON business_catalog_import_runs(site_id, created_at DESC);
