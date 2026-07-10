PRAGMA foreign_keys = OFF;

CREATE TABLE content_types (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type_key TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    singular_label TEXT NOT NULL,
    plural_label TEXT NOT NULL
);

CREATE TABLE blueprints (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    blueprint_key TEXT NOT NULL,
    resource_type TEXT NOT NULL,
    site_id INTEGER,
    legacy_content_type_id INTEGER,
    label TEXT NOT NULL,
    description TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    active_version_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX idx_blueprints_global_key ON blueprints(blueprint_key, resource_type) WHERE site_id IS NULL;
CREATE UNIQUE INDEX idx_blueprints_site_key ON blueprints(site_id, blueprint_key, resource_type) WHERE site_id IS NOT NULL;

CREATE TABLE blueprint_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    blueprint_id INTEGER NOT NULL,
    version INTEGER NOT NULL,
    version_label TEXT,
    status TEXT NOT NULL DEFAULT 'draft',
    schema_json TEXT NOT NULL,
    ui_schema_json TEXT NOT NULL,
    validation_json TEXT NOT NULL,
    seo_policy_json TEXT NOT NULL,
    routing_policy_json TEXT NOT NULL,
    workflow_policy_json TEXT NOT NULL,
    translation_policy_json TEXT NOT NULL,
    permissions_policy_json TEXT NOT NULL,
    checksum_sha256 TEXT,
    is_active INTEGER NOT NULL DEFAULT 0,
    created_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    activated_at TEXT,
    archived_at TEXT,
    UNIQUE(blueprint_id, version)
);
CREATE UNIQUE INDEX idx_blueprint_versions_one_active ON blueprint_versions(blueprint_id) WHERE is_active = 1;

CREATE TABLE blueprint_usage (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    blueprint_id INTEGER NOT NULL,
    blueprint_version_id INTEGER,
    usage_type TEXT NOT NULL,
    resource_type TEXT NOT NULL,
    resource_id INTEGER NOT NULL,
    site_id INTEGER,
    language_code TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE fieldsets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fieldset_key TEXT NOT NULL UNIQUE,
    label TEXT NOT NULL,
    description TEXT,
    fieldset_purpose TEXT NOT NULL DEFAULT 'content',
    schema_version INTEGER NOT NULL DEFAULT 1,
    is_system INTEGER NOT NULL DEFAULT 0,
    is_deletable INTEGER NOT NULL DEFAULT 1,
    config_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE fieldset_fields (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fieldset_id INTEGER NOT NULL,
    field_handle TEXT NOT NULL,
    field_type TEXT NOT NULL,
    label TEXT NOT NULL,
    help_text TEXT,
    field_purpose TEXT NOT NULL DEFAULT 'content',
    width INTEGER NOT NULL DEFAULT 100,
    is_required INTEGER NOT NULL DEFAULT 0,
    is_localized INTEGER NOT NULL DEFAULT 1,
    is_system INTEGER NOT NULL DEFAULT 0,
    is_deletable INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0,
    default_value_json TEXT,
    options_json TEXT NOT NULL DEFAULT '{}',
    validation_json TEXT NOT NULL DEFAULT '{}',
    conditions_json TEXT NOT NULL DEFAULT '[]',
    config_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(fieldset_id, field_handle)
);

CREATE TABLE blueprint_sections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    blueprint_id INTEGER NOT NULL,
    section_key TEXT NOT NULL,
    label TEXT NOT NULL,
    description TEXT,
    layout TEXT NOT NULL DEFAULT 'tab',
    sort_order INTEGER NOT NULL DEFAULT 0,
    conditions_json TEXT NOT NULL DEFAULT '[]',
    config_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(blueprint_id, section_key)
);

CREATE TABLE blueprint_fields (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    blueprint_id INTEGER NOT NULL,
    section_id INTEGER,
    source_fieldset_id INTEGER,
    field_handle TEXT NOT NULL,
    field_type TEXT NOT NULL,
    label TEXT NOT NULL,
    help_text TEXT,
    field_purpose TEXT NOT NULL DEFAULT 'content',
    width INTEGER NOT NULL DEFAULT 100,
    is_required INTEGER NOT NULL DEFAULT 0,
    is_localized INTEGER NOT NULL DEFAULT 1,
    is_system INTEGER NOT NULL DEFAULT 0,
    is_deletable INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0,
    default_value_json TEXT,
    options_json TEXT NOT NULL DEFAULT '{}',
    validation_json TEXT NOT NULL DEFAULT '{}',
    conditions_json TEXT NOT NULL DEFAULT '[]',
    config_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(blueprint_id, field_handle)
);

CREATE TABLE blueprint_fieldsets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    blueprint_id INTEGER NOT NULL,
    section_id INTEGER,
    fieldset_id INTEGER NOT NULL,
    mount_handle TEXT NOT NULL,
    label TEXT,
    sort_order INTEGER NOT NULL DEFAULT 0,
    conditions_json TEXT NOT NULL DEFAULT '[]',
    config_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(blueprint_id, mount_handle)
);

CREATE TABLE content_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    content_type_id INTEGER NOT NULL,
    entry_key TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'draft',
    workflow_state TEXT NOT NULL DEFAULT 'draft',
    published_at TEXT
);

CREATE TABLE content_entry_field_values (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    entry_id INTEGER NOT NULL,
    content_type_id INTEGER NOT NULL,
    field_id INTEGER NOT NULL,
    field_key TEXT NOT NULL,
    language_code TEXT NOT NULL,
    source_revision_id INTEGER NOT NULL,
    value_text TEXT
);

CREATE TABLE taxonomies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    taxonomy_key TEXT NOT NULL,
    name TEXT NOT NULL,
    description TEXT,
    is_hierarchical INTEGER NOT NULL DEFAULT 0,
    is_localized INTEGER NOT NULL DEFAULT 1,
    seo_enabled INTEGER NOT NULL DEFAULT 1,
    archive_enabled INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE content_type_taxonomies (
    content_type_id INTEGER NOT NULL,
    taxonomy_id INTEGER NOT NULL,
    is_required INTEGER NOT NULL DEFAULT 0,
    max_terms INTEGER
);
