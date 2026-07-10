CREATE TABLE IF NOT EXISTS business_activity_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    actor_iam_user_id INTEGER,
    entity_type TEXT NOT NULL,
    entity_id INTEGER NOT NULL,
    related_company_id INTEGER,
    related_contact_id INTEGER,
    action TEXT NOT NULL,
    summary TEXT NOT NULL,
    metadata_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(metadata_json)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(site_id > 0),
    CHECK(entity_id > 0),
    CHECK(trim(entity_type) <> ''),
    CHECK(trim(action) <> ''),
    CHECK(trim(summary) <> '')
);

CREATE INDEX IF NOT EXISTS idx_business_activity_site_created ON business_activity_log(site_id, created_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS idx_business_activity_company ON business_activity_log(site_id, related_company_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_business_activity_contact ON business_activity_log(site_id, related_contact_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_business_activity_entity ON business_activity_log(site_id, entity_type, entity_id, created_at DESC);
