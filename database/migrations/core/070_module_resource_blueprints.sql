-- Idempotent helper migration for existing local installs.
-- From-scratch installs already include this state in database/schema/core.sql.
PRAGMA foreign_keys=OFF;

CREATE TABLE IF NOT EXISTS module_blueprints_v2 (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    module_key TEXT NOT NULL,
    resource TEXT,
    blueprint_key TEXT NOT NULL,
    resource_type TEXT NOT NULL CHECK(resource_type IN ('content_type','block','taxonomy','media','system','module_resource','headless')),
    declared_version INTEGER NOT NULL DEFAULT 1,
    storage_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(storage_json)),
    capabilities_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(capabilities_json)),
    permissions_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(permissions_json)),
    headless_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(headless_json)),
    admin_schema_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(admin_schema_json)),
    relations_json TEXT NOT NULL DEFAULT '[]' CHECK(json_valid(relations_json)),
    export_policy_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(export_policy_json)),
    contract_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(contract_json)),
    validation_errors_json TEXT NOT NULL DEFAULT '[]' CHECK(json_valid(validation_errors_json)),
    is_published INTEGER NOT NULL DEFAULT 0 CHECK(is_published IN (0, 1)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(module_key, blueprint_key, resource_type),
    FOREIGN KEY(module_key) REFERENCES modules(module_key) ON DELETE CASCADE
);

INSERT OR IGNORE INTO module_blueprints_v2(module_key, resource, blueprint_key, resource_type, declared_version, created_at, updated_at)
SELECT module_key, NULL, blueprint_key, resource_type, declared_version, created_at, CURRENT_TIMESTAMP
FROM module_blueprints
WHERE EXISTS (SELECT 1 FROM sqlite_master WHERE type='table' AND name='module_blueprints');

DROP TABLE IF EXISTS module_blueprints;
ALTER TABLE module_blueprints_v2 RENAME TO module_blueprints;

-- SQLite does not allow widening a CHECK constraint in place. Existing installs that
-- need resource_type='module_resource' should be recreated from schema/core.sql, or
-- handled by a dedicated rebuild step. This migration safely upgrades module links.
PRAGMA foreign_keys=ON;
