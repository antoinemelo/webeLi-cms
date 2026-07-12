PRAGMA foreign_keys = ON;

CREATE TABLE languages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    native_name TEXT,
    locale TEXT NOT NULL,
    is_default INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE sites (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_key TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    default_language_code TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(default_language_code) REFERENCES languages(code) ON DELETE RESTRICT
);


CREATE TABLE site_languages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    locale TEXT,
    url_prefix TEXT NOT NULL DEFAULT '',
    hreflang_code TEXT,
    fallback_language_code TEXT,
    is_default INTEGER NOT NULL DEFAULT 0 CHECK(is_default IN (0, 1)),
    is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0, 1)),
    is_rtl INTEGER NOT NULL DEFAULT 0 CHECK(is_rtl IN (0, 1)),
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(site_id, language_code),
    UNIQUE(site_id, url_prefix),
    CHECK(url_prefix = '' OR (substr(url_prefix, 1, 1) = '/' AND substr(url_prefix, -1, 1) <> '/')),
    CHECK(fallback_language_code IS NULL OR fallback_language_code <> language_code),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY(language_code) REFERENCES languages(code) ON DELETE RESTRICT,
    FOREIGN KEY(fallback_language_code) REFERENCES languages(code) ON DELETE SET NULL
);

CREATE UNIQUE INDEX idx_site_languages_one_default_per_site
    ON site_languages(site_id)
    WHERE is_default = 1;
CREATE TABLE site_domains (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    host TEXT NOT NULL,
    base_path TEXT NOT NULL DEFAULT '',
    scheme TEXT NOT NULL DEFAULT 'https' CHECK(scheme IN ('http', 'https')),
    is_primary INTEGER NOT NULL DEFAULT 0 CHECK(is_primary IN (0, 1)),
    is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0, 1)),
    enforce_https INTEGER NOT NULL DEFAULT 1 CHECK(enforce_https IN (0, 1)),
    canonical_host_strategy TEXT NOT NULL DEFAULT 'primary' CHECK(canonical_host_strategy IN ('primary','www','non_www','none')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(host, base_path),
    CHECK(base_path = '' OR (substr(base_path, 1, 1) = '/' AND substr(base_path, -1, 1) <> '/')),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX idx_site_domains_one_primary_per_site
    ON site_domains(site_id)
    WHERE is_primary = 1;

CREATE INDEX idx_site_domains_lookup
    ON site_domains(host, base_path, is_active);

CREATE TABLE site_localizations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    site_title TEXT NOT NULL,
    baseline TEXT,
    footer_text TEXT,
    default_meta_title_suffix TEXT,
    default_meta_description TEXT,
    og_default_image_media_id INTEGER,
    apple_touch_icon_media_id INTEGER,
    UNIQUE(site_id, language_code),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY(site_id, language_code) REFERENCES site_languages(site_id, language_code) ON DELETE CASCADE,
    FOREIGN KEY(og_default_image_media_id) REFERENCES media_assets(id) ON DELETE SET NULL,
    FOREIGN KEY(apple_touch_icon_media_id) REFERENCES media_assets(id) ON DELETE SET NULL
);


CREATE TABLE site_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    namespace TEXT NOT NULL DEFAULT 'general',
    setting_key TEXT NOT NULL,
    value_json TEXT NOT NULL CHECK(json_valid(value_json)),
    is_public INTEGER NOT NULL DEFAULT 0,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(site_id, namespace, setting_key),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE
);

CREATE TABLE site_preferences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    preference_key TEXT NOT NULL,
    value_json TEXT NOT NULL CHECK(json_valid(value_json)),
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(site_id, preference_key),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE
);

CREATE TABLE configuration_revisions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    group_key TEXT NOT NULL CHECK(group_key IN ('site','languages','backoffice','localization','seo','public_ui','media','articles','relations')),
    value_json TEXT NOT NULL CHECK(json_valid(value_json)),
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE
);

CREATE INDEX idx_configuration_revisions_site_group_created
    ON configuration_revisions(site_id, group_key, created_at DESC);

CREATE TABLE global_variables (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    variable_key TEXT NOT NULL,
    variable_type TEXT NOT NULL DEFAULT 'text',
    value_json TEXT NOT NULL CHECK(json_valid(value_json)),
    is_localized INTEGER NOT NULL DEFAULT 0,
    is_public INTEGER NOT NULL DEFAULT 1,
    description TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(site_id, variable_key),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE
);

CREATE TABLE global_variable_localizations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    variable_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    value_json TEXT NOT NULL CHECK(json_valid(value_json)),
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(variable_id, language_code),
    FOREIGN KEY(variable_id) REFERENCES global_variables(id) ON DELETE CASCADE
);

CREATE TABLE themes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    theme_key TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    version TEXT NOT NULL,
    is_default INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    config_json TEXT CHECK(config_json IS NULL OR json_valid(config_json))
);

CREATE TABLE modules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    module_key TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    version TEXT NOT NULL,
    provider_class TEXT,
    is_system INTEGER NOT NULL DEFAULT 0,
    is_installed INTEGER NOT NULL DEFAULT 1,
    is_enabled INTEGER NOT NULL DEFAULT 1,
    config_json TEXT CHECK(config_json IS NULL OR json_valid(config_json)),
    installed_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE module_dependencies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    module_id INTEGER NOT NULL,
    depends_on_module_id INTEGER NOT NULL,
    dependency_type TEXT NOT NULL DEFAULT 'required',
    UNIQUE(module_id, depends_on_module_id),
    FOREIGN KEY(module_id) REFERENCES modules(id) ON DELETE CASCADE,
    FOREIGN KEY(depends_on_module_id) REFERENCES modules(id) ON DELETE CASCADE
);

CREATE TABLE module_hooks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    module_id INTEGER NOT NULL,
    hook_name TEXT NOT NULL,
    handler_class TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY(module_id) REFERENCES modules(id) ON DELETE CASCADE
);

CREATE TABLE module_databases (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    module_key TEXT NOT NULL,
    database_key TEXT NOT NULL,
    driver TEXT NOT NULL DEFAULT 'sqlite' CHECK(driver IN ('sqlite')),
    path TEXT NOT NULL,
    schema_path TEXT,
    is_required INTEGER NOT NULL DEFAULT 1 CHECK(is_required IN (0, 1)),
    exists_at_last_check INTEGER NOT NULL DEFAULT 0 CHECK(exists_at_last_check IN (0, 1)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(module_key, database_key),
    FOREIGN KEY(module_key) REFERENCES modules(module_key) ON DELETE CASCADE
);

CREATE TABLE module_routes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    module_key TEXT NOT NULL,
    route_key TEXT NOT NULL,
    scope TEXT NOT NULL CHECK(scope IN ('admin','api','headless')),
    method TEXT NOT NULL,
    path TEXT NOT NULL,
    handler TEXT NOT NULL,
    is_published INTEGER NOT NULL DEFAULT 1 CHECK(is_published IN (0, 1)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(module_key, route_key),
    FOREIGN KEY(module_key) REFERENCES modules(module_key) ON DELETE CASCADE
);

CREATE TABLE module_permissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    module_key TEXT NOT NULL,
    permission_key TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(module_key, permission_key),
    FOREIGN KEY(module_key) REFERENCES modules(module_key) ON DELETE CASCADE
);

CREATE TABLE module_blueprints (
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

CREATE TABLE module_api_contracts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    module_key TEXT NOT NULL,
    contract_key TEXT NOT NULL,
    version TEXT NOT NULL,
    schema_json TEXT NOT NULL CHECK(json_valid(schema_json)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(module_key, contract_key, version),
    FOREIGN KEY(module_key) REFERENCES modules(module_key) ON DELETE CASCADE
);

CREATE TABLE module_lifecycle_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    module_key TEXT NOT NULL,
    event TEXT NOT NULL CHECK(event IN ('install','enable','disable','migrate','seed','uninstall')),
    payload_json TEXT CHECK(payload_json IS NULL OR json_valid(payload_json)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(module_key) REFERENCES modules(module_key) ON DELETE CASCADE
);

CREATE TABLE action_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER,
    user_id INTEGER,
    module_key TEXT NOT NULL,
    action_key TEXT NOT NULL,
    mode TEXT NOT NULL CHECK(mode IN ('dry_run','apply')),
    status TEXT NOT NULL,
    risk_level TEXT NOT NULL DEFAULT 'low' CHECK(risk_level IN ('low','medium','high')),
    input_summary_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(input_summary_json)),
    output_summary_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(output_summary_json)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(site_id IS NULL OR site_id > 0),
    CHECK(user_id IS NULL OR user_id > 0),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE SET NULL
);
CREATE INDEX idx_action_runs_action_created ON action_runs(action_key, created_at);
CREATE INDEX idx_action_runs_site_created ON action_runs(site_id, created_at);
CREATE INDEX idx_action_runs_user_created ON action_runs(user_id, created_at);


CREATE TABLE content_types (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    module_id INTEGER,
    type_key TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    singular_label TEXT NOT NULL,
    plural_label TEXT NOT NULL,
    description TEXT,
    icon TEXT,
    is_system INTEGER NOT NULL DEFAULT 0,
    is_hidden INTEGER NOT NULL DEFAULT 0,
    storage_mode TEXT NOT NULL DEFAULT 'hybrid' CHECK(storage_mode IN ('hybrid','single_table','json')),
    has_localizations INTEGER NOT NULL DEFAULT 1,
    has_revisions INTEGER NOT NULL DEFAULT 1,
    has_workflow INTEGER NOT NULL DEFAULT 1,
    has_permalink INTEGER NOT NULL DEFAULT 1,
    has_layout INTEGER NOT NULL DEFAULT 0,
    has_taxonomies INTEGER NOT NULL DEFAULT 0,
    has_seo INTEGER NOT NULL DEFAULT 1,
    has_publish_window INTEGER NOT NULL DEFAULT 1,
    default_status TEXT NOT NULL DEFAULT 'draft' CHECK(default_status IN ('draft','review','published','archived','scheduled')),
    default_sort TEXT NOT NULL DEFAULT '-published_at',
    frontend_template TEXT,
    frontend_resolver TEXT,
    api_enabled INTEGER NOT NULL DEFAULT 1,
    admin_enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(module_id) REFERENCES modules(id) ON DELETE SET NULL
);

CREATE TABLE field_groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    content_type_id INTEGER NOT NULL,
    group_key TEXT NOT NULL,
    label TEXT NOT NULL,
    tab_key TEXT NOT NULL DEFAULT 'content',
    sort_order INTEGER NOT NULL DEFAULT 0,
    UNIQUE(content_type_id, group_key),
    FOREIGN KEY(content_type_id) REFERENCES content_types(id) ON DELETE CASCADE
);

CREATE TABLE fields (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    content_type_id INTEGER NOT NULL,
    group_id INTEGER,
    field_key TEXT NOT NULL,
    label TEXT NOT NULL,
    field_type TEXT NOT NULL CHECK(field_type IN ('text','textarea','richtext','markdown','bard','number','integer','boolean','toggle','date','time','datetime','json','yaml','code','media','assets','relation','entries','taxonomy','select','radio','multiselect','checkboxes','slug','link','list','replicator','table','color','video','button_group','range','revealer','sites','structures','template','users')),
    storage_mode TEXT NOT NULL DEFAULT 'value_table' CHECK(storage_mode IN ('value_table','json')),
    interface_key TEXT,
    help_text TEXT,
    placeholder TEXT,
    default_value_json TEXT CHECK(default_value_json IS NULL OR json_valid(default_value_json)),
    options_json TEXT CHECK(options_json IS NULL OR json_valid(options_json)),
    validation_json TEXT CHECK(validation_json IS NULL OR json_valid(validation_json)),
    conditions_json TEXT CHECK(conditions_json IS NULL OR json_valid(conditions_json)),
    config_json TEXT CHECK(config_json IS NULL OR json_valid(config_json)),
    field_purpose TEXT NOT NULL DEFAULT 'content' CHECK(field_purpose IN ('content','seo','system','page_builder','metadata')),
    width INTEGER NOT NULL DEFAULT 100 CHECK(width IN (25,33,50,66,75,100)),
    is_required INTEGER NOT NULL DEFAULT 0,
    is_unique INTEGER NOT NULL DEFAULT 0,
    is_localized INTEGER NOT NULL DEFAULT 1,
    is_indexed INTEGER NOT NULL DEFAULT 0,
    is_filterable INTEGER NOT NULL DEFAULT 0,
    is_sortable INTEGER NOT NULL DEFAULT 0,
    is_searchable INTEGER NOT NULL DEFAULT 0,
    is_hidden INTEGER NOT NULL DEFAULT 0,
    is_system INTEGER NOT NULL DEFAULT 0 CHECK(is_system IN (0, 1)),
    is_deletable INTEGER NOT NULL DEFAULT 1 CHECK(is_deletable IN (0, 1)),
    sort_order INTEGER NOT NULL DEFAULT 0,
    UNIQUE(content_type_id, field_key),
    CHECK(field_key = lower(field_key) AND field_key NOT LIKE '% %'),
    FOREIGN KEY(content_type_id) REFERENCES content_types(id) ON DELETE CASCADE,
    FOREIGN KEY(group_id) REFERENCES field_groups(id) ON DELETE SET NULL
);




-- Legacy/reference field-type registry.
-- `schema_field_types` is still read by the SQL blueprint repository to expose
-- the admin field-type palette. It is a compatibility/reference registry, not a
-- second blueprint source of truth.
CREATE TABLE schema_field_types (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type_key TEXT NOT NULL UNIQUE,
    label TEXT NOT NULL,
    storage_mode TEXT NOT NULL DEFAULT 'scalar' CHECK(storage_mode IN ('scalar','json')),
    component_key TEXT NOT NULL,
    is_implemented INTEGER NOT NULL DEFAULT 0,
    definition_json TEXT CHECK(definition_json IS NULL OR json_valid(definition_json)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);


-- Active v1 versioned blueprints: source of truth for editorial models,
-- admin forms/schema builder data, validations, SEO, routing, workflow,
-- translation, permissions and headless exposure.
CREATE TABLE blueprints (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    blueprint_key TEXT NOT NULL,
    resource_type TEXT NOT NULL CHECK(resource_type IN ('content_type','block','taxonomy','media','system','module_resource','headless')),
    site_id INTEGER,
    legacy_content_type_id INTEGER,
    label TEXT NOT NULL,
    description TEXT,
    is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0, 1)),
    active_version_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(blueprint_key = lower(blueprint_key) AND blueprint_key NOT LIKE '% %'),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY(legacy_content_type_id) REFERENCES content_types(id) ON DELETE SET NULL,
    FOREIGN KEY(active_version_id) REFERENCES blueprint_versions(id) ON DELETE SET NULL
);

CREATE UNIQUE INDEX idx_blueprints_global_key
    ON blueprints(blueprint_key, resource_type)
    WHERE site_id IS NULL;

CREATE UNIQUE INDEX idx_blueprints_site_key
    ON blueprints(site_id, blueprint_key, resource_type)
    WHERE site_id IS NOT NULL;

CREATE TABLE blueprint_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    blueprint_id INTEGER NOT NULL,
    version INTEGER NOT NULL,
    version_label TEXT,
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','active','archived')),
    schema_json TEXT NOT NULL CHECK(json_valid(schema_json)),
    ui_schema_json TEXT NOT NULL CHECK(json_valid(ui_schema_json)),
    validation_json TEXT NOT NULL CHECK(json_valid(validation_json)),
    seo_policy_json TEXT NOT NULL CHECK(json_valid(seo_policy_json)),
    routing_policy_json TEXT NOT NULL CHECK(json_valid(routing_policy_json)),
    workflow_policy_json TEXT NOT NULL CHECK(json_valid(workflow_policy_json)),
    translation_policy_json TEXT NOT NULL CHECK(json_valid(translation_policy_json)),
    permissions_policy_json TEXT NOT NULL CHECK(json_valid(permissions_policy_json)),
    checksum_sha256 TEXT CHECK(checksum_sha256 IS NULL OR (length(checksum_sha256) = 64 AND checksum_sha256 NOT GLOB '*[^0-9a-f]*')),
    is_active INTEGER NOT NULL DEFAULT 0 CHECK(is_active IN (0, 1)),
    created_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    activated_at TEXT,
    archived_at TEXT,
    UNIQUE(blueprint_id, version),
    CHECK((is_active = 1 AND status = 'active') OR (is_active = 0 AND status IN ('draft','archived'))),
    FOREIGN KEY(blueprint_id) REFERENCES blueprints(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX idx_blueprint_versions_one_active
    ON blueprint_versions(blueprint_id)
    WHERE is_active = 1;

-- Compatibility/publication-scope table for blueprint versions.
-- The active v1 blueprint is selected by `blueprints.active_version_id` and
-- `blueprint_versions.is_active`. This table is kept for historical/scoped
-- publication metadata and must not be used as the primary active-version source.
CREATE TABLE blueprint_publications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    blueprint_id INTEGER NOT NULL,
    blueprint_version_id INTEGER NOT NULL,
    site_id INTEGER,
    language_code TEXT,
    publication_status TEXT NOT NULL DEFAULT 'published' CHECK(publication_status IN ('draft','published','retired')),
    published_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_by_iam_user_id INTEGER,
    CHECK(site_id IS NOT NULL OR language_code IS NULL),
    UNIQUE(blueprint_id, site_id, language_code, publication_status),
    FOREIGN KEY(blueprint_id) REFERENCES blueprints(id) ON DELETE RESTRICT,
    FOREIGN KEY(blueprint_version_id) REFERENCES blueprint_versions(id) ON DELETE RESTRICT,
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY(site_id, language_code) REFERENCES site_languages(site_id, language_code) ON DELETE CASCADE
);

CREATE UNIQUE INDEX idx_blueprint_publications_global_scope
    ON blueprint_publications(blueprint_id, publication_status)
    WHERE site_id IS NULL AND language_code IS NULL;

CREATE TABLE blueprint_migrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    blueprint_id INTEGER NOT NULL,
    from_version_id INTEGER,
    to_version_id INTEGER NOT NULL,
    migration_key TEXT NOT NULL,
    migration_json TEXT NOT NULL CHECK(json_valid(migration_json)),
    status TEXT NOT NULL DEFAULT 'planned' CHECK(status IN ('planned','ready','applied','failed','cancelled')),
    applied_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(blueprint_id, migration_key),
    FOREIGN KEY(blueprint_id) REFERENCES blueprints(id) ON DELETE RESTRICT,
    FOREIGN KEY(from_version_id) REFERENCES blueprint_versions(id) ON DELETE SET NULL,
    FOREIGN KEY(to_version_id) REFERENCES blueprint_versions(id) ON DELETE RESTRICT
);

CREATE TABLE blueprint_usage (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    blueprint_id INTEGER NOT NULL,
    blueprint_version_id INTEGER,
    usage_type TEXT NOT NULL CHECK(usage_type IN ('content_type','content_entry','taxonomy','route','seo','headless','module')),
    resource_type TEXT NOT NULL,
    resource_id INTEGER NOT NULL,
    site_id INTEGER,
    language_code TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(site_id IS NOT NULL OR language_code IS NULL),
    UNIQUE(blueprint_id, usage_type, resource_type, resource_id, site_id, language_code),
    FOREIGN KEY(blueprint_id) REFERENCES blueprints(id) ON DELETE RESTRICT,
    FOREIGN KEY(blueprint_version_id) REFERENCES blueprint_versions(id) ON DELETE SET NULL,
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY(site_id, language_code) REFERENCES site_languages(site_id, language_code) ON DELETE CASCADE
);

CREATE UNIQUE INDEX idx_blueprint_usage_global_scope
    ON blueprint_usage(blueprint_id, usage_type, resource_type, resource_id)
    WHERE site_id IS NULL AND language_code IS NULL;


-- Active v1 normalized blueprint model. The public front still reads published
-- projections; these tables are admin/API source data for schema builders.
CREATE TABLE fieldsets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fieldset_key TEXT NOT NULL UNIQUE,
    label TEXT NOT NULL,
    description TEXT,
    fieldset_purpose TEXT NOT NULL DEFAULT 'content' CHECK(fieldset_purpose IN ('content','seo','system','page_builder','metadata')),
    schema_version INTEGER NOT NULL DEFAULT 1,
    is_system INTEGER NOT NULL DEFAULT 0 CHECK(is_system IN (0, 1)),
    is_deletable INTEGER NOT NULL DEFAULT 1 CHECK(is_deletable IN (0, 1)),
    config_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(config_json)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(fieldset_key = lower(fieldset_key) AND fieldset_key NOT LIKE '% %')
);

CREATE TABLE fieldset_fields (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fieldset_id INTEGER NOT NULL,
    field_handle TEXT NOT NULL,
    field_type TEXT NOT NULL CHECK(field_type IN ('text','textarea','richtext','markdown','bard','number','integer','boolean','toggle','date','time','datetime','json','yaml','code','media','assets','relation','entries','taxonomy','select','radio','multiselect','checkboxes','slug','link','list','replicator','table','color','video','button_group','range','revealer','sites','structures','template','users')),
    label TEXT NOT NULL,
    help_text TEXT,
    field_purpose TEXT NOT NULL DEFAULT 'content' CHECK(field_purpose IN ('content','seo','system','page_builder','metadata')),
    width INTEGER NOT NULL DEFAULT 100 CHECK(width IN (25,33,50,66,75,100)),
    is_required INTEGER NOT NULL DEFAULT 0 CHECK(is_required IN (0, 1)),
    is_localized INTEGER NOT NULL DEFAULT 1 CHECK(is_localized IN (0, 1)),
    is_system INTEGER NOT NULL DEFAULT 0 CHECK(is_system IN (0, 1)),
    is_deletable INTEGER NOT NULL DEFAULT 1 CHECK(is_deletable IN (0, 1)),
    sort_order INTEGER NOT NULL DEFAULT 0,
    default_value_json TEXT CHECK(default_value_json IS NULL OR json_valid(default_value_json)),
    options_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(options_json)),
    validation_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(validation_json)),
    conditions_json TEXT NOT NULL DEFAULT '[]' CHECK(json_valid(conditions_json)),
    config_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(config_json)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(fieldset_id, field_handle),
    CHECK(field_handle = lower(field_handle) AND field_handle NOT LIKE '% %'),
    FOREIGN KEY(fieldset_id) REFERENCES fieldsets(id) ON DELETE CASCADE
);

CREATE TABLE blueprint_sections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    blueprint_id INTEGER NOT NULL,
    section_key TEXT NOT NULL,
    label TEXT NOT NULL,
    description TEXT,
    layout TEXT NOT NULL DEFAULT 'tab' CHECK(layout IN ('tab','section','sidebar')),
    sort_order INTEGER NOT NULL DEFAULT 0,
    conditions_json TEXT NOT NULL DEFAULT '[]' CHECK(json_valid(conditions_json)),
    config_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(config_json)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(blueprint_id, section_key),
    CHECK(section_key = lower(section_key) AND section_key NOT LIKE '% %'),
    FOREIGN KEY(blueprint_id) REFERENCES blueprints(id) ON DELETE CASCADE
);

CREATE TABLE blueprint_fields (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    blueprint_id INTEGER NOT NULL,
    section_id INTEGER,
    source_fieldset_id INTEGER,
    field_handle TEXT NOT NULL,
    field_type TEXT NOT NULL CHECK(field_type IN ('text','textarea','richtext','markdown','bard','number','integer','boolean','toggle','date','time','datetime','json','yaml','code','media','assets','relation','entries','taxonomy','select','radio','multiselect','checkboxes','slug','link','list','replicator','table','color','video','button_group','range','revealer','sites','structures','template','users')),
    label TEXT NOT NULL,
    help_text TEXT,
    field_purpose TEXT NOT NULL DEFAULT 'content' CHECK(field_purpose IN ('content','seo','system','page_builder','metadata')),
    width INTEGER NOT NULL DEFAULT 100 CHECK(width IN (25,33,50,66,75,100)),
    is_required INTEGER NOT NULL DEFAULT 0 CHECK(is_required IN (0, 1)),
    is_localized INTEGER NOT NULL DEFAULT 1 CHECK(is_localized IN (0, 1)),
    is_system INTEGER NOT NULL DEFAULT 0 CHECK(is_system IN (0, 1)),
    is_deletable INTEGER NOT NULL DEFAULT 1 CHECK(is_deletable IN (0, 1)),
    sort_order INTEGER NOT NULL DEFAULT 0,
    default_value_json TEXT CHECK(default_value_json IS NULL OR json_valid(default_value_json)),
    options_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(options_json)),
    validation_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(validation_json)),
    conditions_json TEXT NOT NULL DEFAULT '[]' CHECK(json_valid(conditions_json)),
    config_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(config_json)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(blueprint_id, field_handle),
    CHECK(field_handle = lower(field_handle) AND field_handle NOT LIKE '% %'),
    FOREIGN KEY(blueprint_id) REFERENCES blueprints(id) ON DELETE CASCADE,
    FOREIGN KEY(section_id) REFERENCES blueprint_sections(id) ON DELETE SET NULL,
    FOREIGN KEY(source_fieldset_id) REFERENCES fieldsets(id) ON DELETE SET NULL
);

CREATE TABLE blueprint_fieldsets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    blueprint_id INTEGER NOT NULL,
    section_id INTEGER,
    fieldset_id INTEGER NOT NULL,
    mount_handle TEXT NOT NULL,
    label TEXT,
    sort_order INTEGER NOT NULL DEFAULT 0,
    conditions_json TEXT NOT NULL DEFAULT '[]' CHECK(json_valid(conditions_json)),
    config_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(config_json)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(blueprint_id, mount_handle),
    CHECK(mount_handle = lower(mount_handle) AND mount_handle NOT LIKE '% %'),
    FOREIGN KEY(blueprint_id) REFERENCES blueprints(id) ON DELETE CASCADE,
    FOREIGN KEY(section_id) REFERENCES blueprint_sections(id) ON DELETE SET NULL,
    FOREIGN KEY(fieldset_id) REFERENCES fieldsets(id) ON DELETE RESTRICT
);

CREATE TABLE field_validation_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    scope_type TEXT NOT NULL CHECK(scope_type IN ('field','blueprint_field','fieldset_field')),
    field_id INTEGER,
    blueprint_field_id INTEGER,
    fieldset_field_id INTEGER,
    rule_key TEXT NOT NULL,
    rule_value_json TEXT NOT NULL DEFAULT 'true' CHECK(json_valid(rule_value_json)),
    message TEXT,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK((scope_type='field' AND field_id IS NOT NULL AND blueprint_field_id IS NULL AND fieldset_field_id IS NULL)
       OR (scope_type='blueprint_field' AND field_id IS NULL AND blueprint_field_id IS NOT NULL AND fieldset_field_id IS NULL)
       OR (scope_type='fieldset_field' AND field_id IS NULL AND blueprint_field_id IS NULL AND fieldset_field_id IS NOT NULL)),
    FOREIGN KEY(field_id) REFERENCES fields(id) ON DELETE CASCADE,
    FOREIGN KEY(blueprint_field_id) REFERENCES blueprint_fields(id) ON DELETE CASCADE,
    FOREIGN KEY(fieldset_field_id) REFERENCES fieldset_fields(id) ON DELETE CASCADE
);

CREATE TABLE field_visibility_conditions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    scope_type TEXT NOT NULL CHECK(scope_type IN ('field','blueprint_field','fieldset_field','blueprint_section','blueprint_fieldset')),
    field_id INTEGER,
    blueprint_field_id INTEGER,
    fieldset_field_id INTEGER,
    blueprint_section_id INTEGER,
    blueprint_fieldset_id INTEGER,
    effect TEXT NOT NULL DEFAULT 'show' CHECK(effect IN ('show','hide','require','disable')),
    condition_json TEXT NOT NULL CHECK(json_valid(condition_json)),
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK((scope_type='field' AND field_id IS NOT NULL AND blueprint_field_id IS NULL AND fieldset_field_id IS NULL AND blueprint_section_id IS NULL AND blueprint_fieldset_id IS NULL)
       OR (scope_type='blueprint_field' AND field_id IS NULL AND blueprint_field_id IS NOT NULL AND fieldset_field_id IS NULL AND blueprint_section_id IS NULL AND blueprint_fieldset_id IS NULL)
       OR (scope_type='fieldset_field' AND field_id IS NULL AND blueprint_field_id IS NULL AND fieldset_field_id IS NOT NULL AND blueprint_section_id IS NULL AND blueprint_fieldset_id IS NULL)
       OR (scope_type='blueprint_section' AND field_id IS NULL AND blueprint_field_id IS NULL AND fieldset_field_id IS NULL AND blueprint_section_id IS NOT NULL AND blueprint_fieldset_id IS NULL)
       OR (scope_type='blueprint_fieldset' AND field_id IS NULL AND blueprint_field_id IS NULL AND fieldset_field_id IS NULL AND blueprint_section_id IS NULL AND blueprint_fieldset_id IS NOT NULL)),
    FOREIGN KEY(field_id) REFERENCES fields(id) ON DELETE CASCADE,
    FOREIGN KEY(blueprint_field_id) REFERENCES blueprint_fields(id) ON DELETE CASCADE,
    FOREIGN KEY(fieldset_field_id) REFERENCES fieldset_fields(id) ON DELETE CASCADE,
    FOREIGN KEY(blueprint_section_id) REFERENCES blueprint_sections(id) ON DELETE CASCADE,
    FOREIGN KEY(blueprint_fieldset_id) REFERENCES blueprint_fieldsets(id) ON DELETE CASCADE
);

CREATE INDEX idx_blueprint_sections_blueprint_order ON blueprint_sections(blueprint_id, sort_order, section_key);
CREATE INDEX idx_blueprint_fields_blueprint_section_order ON blueprint_fields(blueprint_id, section_id, sort_order, field_handle);
CREATE INDEX idx_fieldset_fields_fieldset_order ON fieldset_fields(fieldset_id, sort_order, field_handle);
CREATE INDEX idx_blueprint_fieldsets_blueprint_order ON blueprint_fieldsets(blueprint_id, sort_order, mount_handle);

CREATE TRIGGER trg_blueprints_active_version_same_blueprint_insert
BEFORE INSERT ON blueprints
WHEN NEW.active_version_id IS NOT NULL
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM blueprint_versions bv WHERE bv.id = NEW.active_version_id AND bv.blueprint_id = NEW.id AND bv.is_active = 1
    ) THEN RAISE(ABORT, 'active_version_id must reference an active version of the same blueprint') END;
END;

CREATE TRIGGER trg_blueprints_active_version_same_blueprint_update
BEFORE UPDATE OF active_version_id ON blueprints
WHEN NEW.active_version_id IS NOT NULL
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM blueprint_versions bv WHERE bv.id = NEW.active_version_id AND bv.blueprint_id = NEW.id AND bv.is_active = 1
    ) THEN RAISE(ABORT, 'active_version_id must reference an active version of the same blueprint') END;
END;

CREATE TRIGGER trg_blueprint_versions_active_pointer_insert
AFTER INSERT ON blueprint_versions
WHEN NEW.is_active = 1
BEGIN
    UPDATE blueprints SET active_version_id = NEW.id, updated_at = CURRENT_TIMESTAMP WHERE id = NEW.blueprint_id;
END;

CREATE TRIGGER trg_blueprint_versions_active_pointer_update
AFTER UPDATE OF is_active, status ON blueprint_versions
WHEN NEW.is_active = 1
BEGIN
    UPDATE blueprints SET active_version_id = NEW.id, updated_at = CURRENT_TIMESTAMP WHERE id = NEW.blueprint_id;
END;

CREATE TRIGGER trg_blueprint_publications_site_scope_insert
BEFORE INSERT ON blueprint_publications
BEGIN
    SELECT CASE WHEN EXISTS (
        SELECT 1 FROM blueprints b WHERE b.id = NEW.blueprint_id AND b.site_id IS NOT NULL AND NEW.site_id IS NOT b.site_id
    ) THEN RAISE(ABORT, 'site-specific blueprint publication must target the blueprint site') END;
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM blueprint_versions bv WHERE bv.id = NEW.blueprint_version_id AND bv.blueprint_id = NEW.blueprint_id
    ) THEN RAISE(ABORT, 'publication version must belong to blueprint') END;
END;

CREATE TRIGGER trg_blueprint_publications_site_scope_update
BEFORE UPDATE OF blueprint_id, blueprint_version_id, site_id, language_code ON blueprint_publications
BEGIN
    SELECT CASE WHEN EXISTS (
        SELECT 1 FROM blueprints b WHERE b.id = NEW.blueprint_id AND b.site_id IS NOT NULL AND NEW.site_id IS NOT b.site_id
    ) THEN RAISE(ABORT, 'site-specific blueprint publication must target the blueprint site') END;
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM blueprint_versions bv WHERE bv.id = NEW.blueprint_version_id AND bv.blueprint_id = NEW.blueprint_id
    ) THEN RAISE(ABORT, 'publication version must belong to blueprint') END;
END;

CREATE TRIGGER trg_blueprint_usage_site_scope_insert
BEFORE INSERT ON blueprint_usage
BEGIN
    SELECT CASE WHEN EXISTS (
        SELECT 1 FROM blueprints b WHERE b.id = NEW.blueprint_id AND b.site_id IS NOT NULL AND NEW.site_id IS NOT b.site_id
    ) THEN RAISE(ABORT, 'site-specific blueprint usage must target the blueprint site') END;
    SELECT CASE WHEN NEW.blueprint_version_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM blueprint_versions bv WHERE bv.id = NEW.blueprint_version_id AND bv.blueprint_id = NEW.blueprint_id
    ) THEN RAISE(ABORT, 'usage version must belong to blueprint') END;
END;

CREATE TRIGGER trg_blueprint_usage_site_scope_update
BEFORE UPDATE OF blueprint_id, blueprint_version_id, site_id, language_code ON blueprint_usage
BEGIN
    SELECT CASE WHEN EXISTS (
        SELECT 1 FROM blueprints b WHERE b.id = NEW.blueprint_id AND b.site_id IS NOT NULL AND NEW.site_id IS NOT b.site_id
    ) THEN RAISE(ABORT, 'site-specific blueprint usage must target the blueprint site') END;
    SELECT CASE WHEN NEW.blueprint_version_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM blueprint_versions bv WHERE bv.id = NEW.blueprint_version_id AND bv.blueprint_id = NEW.blueprint_id
    ) THEN RAISE(ABORT, 'usage version must belong to blueprint') END;
END;

CREATE TABLE content_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    content_type_id INTEGER NOT NULL,
    entry_key TEXT NOT NULL,
    parent_entry_id INTEGER,
    author_iam_user_id INTEGER,
    owner_iam_user_id INTEGER,
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    published_by_iam_user_id INTEGER,
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','review','published','archived','scheduled')),
    workflow_state TEXT NOT NULL DEFAULT 'draft' CHECK(workflow_state IN ('draft','review','published','archived','scheduled')),
    is_active INTEGER NOT NULL DEFAULT 1,
    publish_at TEXT,
    unpublish_at TEXT,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_at TEXT,
    CHECK(unpublish_at IS NULL OR publish_at IS NULL OR unpublish_at > publish_at),
    CHECK(status = workflow_state),
    CHECK(published_at IS NULL OR status = 'published'),
    UNIQUE(site_id, id),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY(content_type_id) REFERENCES content_types(id) ON DELETE CASCADE,
    FOREIGN KEY(parent_entry_id) REFERENCES content_entries(id) ON DELETE SET NULL
);


CREATE TRIGGER trg_content_entries_editorial_status_insert
BEFORE INSERT ON content_entries
BEGIN
    SELECT CASE WHEN NEW.status <> NEW.workflow_state
        THEN RAISE(ABORT, 'content_entries.status and workflow_state must stay synchronized') END;
END;

CREATE TRIGGER trg_content_entries_editorial_status_update
BEFORE UPDATE OF status, workflow_state ON content_entries
BEGIN
    SELECT CASE WHEN NEW.status <> NEW.workflow_state
        THEN RAISE(ABORT, 'content_entries.status and workflow_state must stay synchronized') END;
END;

CREATE UNIQUE INDEX idx_content_entries_site_entry_key
ON content_entries(site_id, entry_key);
CREATE INDEX IF NOT EXISTS idx_content_entries_author_iam_user_id ON content_entries(author_iam_user_id);
CREATE INDEX IF NOT EXISTS idx_content_entries_owner_iam_user_id ON content_entries(owner_iam_user_id);
CREATE INDEX IF NOT EXISTS idx_content_entries_created_by_iam_user_id ON content_entries(created_by_iam_user_id);
CREATE INDEX IF NOT EXISTS idx_content_entries_updated_by_iam_user_id ON content_entries(updated_by_iam_user_id);
CREATE INDEX IF NOT EXISTS idx_content_entries_published_by_iam_user_id ON content_entries(published_by_iam_user_id);

CREATE TABLE content_entry_localizations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    entry_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    title TEXT,
    draft_slug TEXT,
    draft_full_path TEXT CHECK(
        draft_full_path IS NULL OR (
            draft_full_path LIKE '/%'
            AND (draft_full_path = '/' OR instr(draft_full_path, '//') = 0)
            AND (draft_full_path = '/' OR substr(draft_full_path, -1, 1) <> '/')
            AND draft_full_path NOT LIKE '% %'
            AND draft_full_path = lower(draft_full_path)
        )
    ),
    translation_status TEXT NOT NULL DEFAULT 'draft' CHECK(translation_status IN ('draft','needs_translation','translated','reviewed','locked')),
    source_language_code TEXT,
    translated_from_revision_id INTEGER,
    localized_at TEXT,
    updated_by_iam_user_id INTEGER,
    draft_status TEXT NOT NULL DEFAULT 'draft' CHECK(draft_status IN ('draft','review','ready','archived','scheduled')),
    is_active INTEGER NOT NULL DEFAULT 1,
    admin_cache_published_at TEXT,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(entry_id, language_code),
    FOREIGN KEY(translated_from_revision_id) REFERENCES revisions(id) ON DELETE SET NULL,
    FOREIGN KEY(entry_id) REFERENCES content_entries(id) ON DELETE CASCADE
);


CREATE TABLE content_slug_registry (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    slug TEXT NOT NULL,
    entry_id INTEGER NOT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(site_id, language_code, slug),
    UNIQUE(site_id, entry_id, language_code),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY(site_id, language_code) REFERENCES site_languages(site_id, language_code) ON DELETE CASCADE,
    FOREIGN KEY(entry_id) REFERENCES content_entries(id) ON DELETE CASCADE
);

CREATE TABLE content_field_unique_values (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    content_type_id INTEGER NOT NULL,
    field_key TEXT NOT NULL,
    language_code TEXT NOT NULL DEFAULT '',
    normalized_value TEXT NOT NULL,
    entry_id INTEGER NOT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(site_id, content_type_id, field_key, language_code, normalized_value),
    UNIQUE(site_id, content_type_id, entry_id, field_key, language_code),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY(content_type_id) REFERENCES content_types(id) ON DELETE CASCADE,
    FOREIGN KEY(entry_id) REFERENCES content_entries(id) ON DELETE CASCADE
);
CREATE TABLE content_entry_working_revisions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    entry_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    working_revision_id INTEGER NOT NULL,
    workflow_status TEXT NOT NULL DEFAULT 'draft' CHECK(workflow_status IN ('draft','published','superseded')),
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(entry_id, language_code),
    UNIQUE(site_id, entry_id, language_code),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY(site_id, language_code) REFERENCES site_languages(site_id, language_code) ON DELETE CASCADE,
    FOREIGN KEY(site_id, entry_id) REFERENCES content_entries(site_id, id) ON DELETE CASCADE,
    FOREIGN KEY(entry_id) REFERENCES content_entries(id) ON DELETE CASCADE,
    FOREIGN KEY(working_revision_id) REFERENCES revisions(id) ON DELETE RESTRICT
);

CREATE TABLE content_entry_publications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    entry_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    published_revision_id INTEGER,
    workflow_status TEXT NOT NULL DEFAULT 'published' CHECK(workflow_status IN ('published', 'unpublished')),
    published_by_iam_user_id INTEGER,
    published_at TEXT,
    unpublished_by_iam_user_id INTEGER,
    unpublished_at TEXT,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(entry_id, language_code),
    UNIQUE(site_id, entry_id, language_code),
    CHECK((workflow_status = 'published' AND published_revision_id IS NOT NULL AND published_at IS NOT NULL) OR workflow_status = 'unpublished'),
    CHECK(workflow_status <> 'published' OR published_at IS NOT NULL),
    CHECK(unpublished_at IS NULL OR workflow_status = 'unpublished'),
    CHECK(workflow_status <> 'unpublished' OR unpublished_at IS NOT NULL),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY(site_id, language_code) REFERENCES site_languages(site_id, language_code) ON DELETE CASCADE,
    FOREIGN KEY(site_id, entry_id) REFERENCES content_entries(site_id, id) ON DELETE CASCADE,
    FOREIGN KEY(entry_id) REFERENCES content_entries(id) ON DELETE CASCADE,
    FOREIGN KEY(published_revision_id) REFERENCES revisions(id) ON DELETE RESTRICT
);

CREATE TABLE content_entry_field_values (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    entry_id INTEGER NOT NULL,
    localization_id INTEGER,
    content_type_id INTEGER NOT NULL,
    field_id INTEGER NOT NULL,
    field_key TEXT NOT NULL,
    language_code TEXT NOT NULL,
    projection_scope TEXT NOT NULL DEFAULT 'draft_index' CHECK(projection_scope IN ('draft_index')),
    source_revision_id INTEGER NOT NULL,
    source_revision_checksum_sha256 TEXT,
    value_text TEXT,
    value_number REAL,
    value_boolean INTEGER CHECK(value_boolean IS NULL OR value_boolean IN (0, 1)),
    value_date TEXT,
    value_datetime TEXT,
    value_json TEXT CHECK(value_json IS NULL OR json_valid(value_json)),
    value_media_id INTEGER,
    value_relation_entry_id INTEGER,
    sort_order INTEGER NOT NULL DEFAULT 0,
    projected_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(entry_id, language_code, field_id, projection_scope, sort_order),
    FOREIGN KEY(entry_id) REFERENCES content_entries(id) ON DELETE CASCADE,
    FOREIGN KEY(localization_id) REFERENCES content_entry_localizations(id) ON DELETE CASCADE,
    FOREIGN KEY(content_type_id) REFERENCES content_types(id) ON DELETE CASCADE,
    FOREIGN KEY(field_id) REFERENCES fields(id) ON DELETE CASCADE,
    FOREIGN KEY(source_revision_id) REFERENCES revisions(id) ON DELETE CASCADE,
    FOREIGN KEY(value_media_id) REFERENCES media_assets(id) ON DELETE SET NULL
);

CREATE TABLE content_relations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_entry_id INTEGER NOT NULL,
    target_entry_id INTEGER NOT NULL,
    relation_type TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    metadata_json TEXT CHECK(metadata_json IS NULL OR json_valid(metadata_json)),
    UNIQUE(source_entry_id, target_entry_id, relation_type),
    FOREIGN KEY(source_entry_id) REFERENCES content_entries(id) ON DELETE CASCADE,
    FOREIGN KEY(target_entry_id) REFERENCES content_entries(id) ON DELETE CASCADE
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
    sort_order INTEGER NOT NULL DEFAULT 0,
    UNIQUE(site_id, taxonomy_key),
    UNIQUE(id, site_id),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE
);

CREATE TABLE taxonomy_terms (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    taxonomy_id INTEGER NOT NULL,
    parent_id INTEGER,
    term_key TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0,
    UNIQUE(taxonomy_id, term_key),
    UNIQUE(id, taxonomy_id),
    FOREIGN KEY(taxonomy_id) REFERENCES taxonomies(id) ON DELETE CASCADE,
    FOREIGN KEY(parent_id) REFERENCES taxonomy_terms(id) ON DELETE SET NULL
);

CREATE TABLE taxonomy_term_localizations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    taxonomy_id INTEGER NOT NULL,
    term_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    name TEXT NOT NULL,
    slug TEXT NOT NULL,
    full_path TEXT CHECK(
        full_path IS NULL OR (
            full_path LIKE '/%'
            AND (full_path = '/' OR instr(full_path, '//') = 0)
            AND (full_path = '/' OR substr(full_path, -1, 1) <> '/')
            AND full_path NOT LIKE '% %'
            AND full_path = lower(full_path)
        )
    ),
    description TEXT,
    meta_title TEXT,
    meta_description TEXT,
    canonical_url TEXT,
    json_ld TEXT,
    UNIQUE(term_id, language_code),
    UNIQUE(site_id, language_code, taxonomy_id, full_path),
    FOREIGN KEY(site_id, language_code) REFERENCES site_languages(site_id, language_code) ON DELETE RESTRICT,
    FOREIGN KEY(taxonomy_id, site_id) REFERENCES taxonomies(id, site_id) ON DELETE CASCADE,
    FOREIGN KEY(term_id, taxonomy_id) REFERENCES taxonomy_terms(id, taxonomy_id) ON DELETE CASCADE
);

CREATE TABLE content_type_taxonomies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    content_type_id INTEGER NOT NULL,
    taxonomy_id INTEGER NOT NULL,
    is_required INTEGER NOT NULL DEFAULT 0,
    max_terms INTEGER,
    UNIQUE(content_type_id, taxonomy_id),
    FOREIGN KEY(content_type_id) REFERENCES content_types(id) ON DELETE CASCADE,
    FOREIGN KEY(taxonomy_id) REFERENCES taxonomies(id) ON DELETE CASCADE
);

CREATE TABLE content_entry_taxonomy_terms (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    entry_id INTEGER NOT NULL,
    term_id INTEGER NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    UNIQUE(entry_id, term_id),
    FOREIGN KEY(entry_id) REFERENCES content_entries(id) ON DELETE CASCADE,
    FOREIGN KEY(term_id) REFERENCES taxonomy_terms(id) ON DELETE CASCADE
);

CREATE TABLE routes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    resource_type TEXT NOT NULL CHECK(resource_type IN ('content_entry','taxonomy_term','system')),
    resource_id INTEGER NOT NULL,
    route_type TEXT NOT NULL DEFAULT 'content' CHECK(route_type IN ('content', 'taxonomy', 'system')),
    slug TEXT,
    full_path TEXT NOT NULL CHECK(
        full_path LIKE '/%'
        AND (full_path = '/' OR instr(full_path, '//') = 0)
        AND (full_path = '/' OR substr(full_path, -1, 1) <> '/')
        AND full_path NOT LIKE '% %'
        AND full_path = lower(full_path)
    ),
    is_primary INTEGER NOT NULL DEFAULT 1 CHECK(is_primary IN (0, 1)),
    is_canonical INTEGER NOT NULL DEFAULT 1 CHECK(is_canonical IN (0, 1)),
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active', 'inactive', 'archived')),
    source_published_revision_id INTEGER,
    source_revision_checksum_sha256 TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(site_id, language_code, full_path),
    CHECK(
        resource_type <> 'content_entry'
        OR route_type <> 'content'
        OR (
            source_published_revision_id IS NOT NULL
            AND source_revision_checksum_sha256 IS NOT NULL
            AND length(source_revision_checksum_sha256) = 64
            AND source_revision_checksum_sha256 NOT GLOB '*[^0-9a-f]*'
        )
    ),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY(site_id, language_code) REFERENCES site_languages(site_id, language_code) ON DELETE CASCADE,
    FOREIGN KEY(source_published_revision_id) REFERENCES revisions(id)
);

CREATE TABLE redirects (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    language_code TEXT,
    old_path TEXT NOT NULL CHECK(
        old_path LIKE '/%'
        AND (old_path = '/' OR instr(old_path, '//') = 0)
        AND (old_path = '/' OR substr(old_path, -1, 1) <> '/')
        AND old_path NOT LIKE '% %'
        AND old_path = lower(old_path)
    ),
    new_path TEXT NOT NULL CHECK(
        new_path LIKE '/%'
        AND (new_path = '/' OR instr(new_path, '//') = 0)
        AND (new_path = '/' OR substr(new_path, -1, 1) <> '/')
        AND new_path NOT LIKE '% %'
        AND new_path = lower(new_path)
    ),
    http_code INTEGER NOT NULL DEFAULT 301 CHECK(http_code IN (301,302,307,308)),
    redirect_reason TEXT,
    resource_type TEXT CHECK(resource_type IS NULL OR resource_type IN ('content_entry','taxonomy_term','system')),
    resource_id INTEGER,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(updated_at >= created_at),
    UNIQUE(site_id, language_code, old_path),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY(site_id, language_code) REFERENCES site_languages(site_id, language_code) ON DELETE CASCADE
);

CREATE TABLE tombstones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    language_code TEXT,
    old_path TEXT NOT NULL CHECK(
        old_path LIKE '/%'
        AND (old_path = '/' OR instr(old_path, '//') = 0)
        AND (old_path = '/' OR substr(old_path, -1, 1) <> '/')
        AND old_path NOT LIKE '% %'
        AND old_path = lower(old_path)
    ),
    resource_type TEXT CHECK(resource_type IS NULL OR resource_type IN ('content_entry','taxonomy_term','system')),
    resource_id INTEGER,
    replacement_path TEXT CHECK(
        replacement_path IS NULL OR (
            replacement_path LIKE '/%'
            AND (replacement_path = '/' OR instr(replacement_path, '//') = 0)
            AND (replacement_path = '/' OR substr(replacement_path, -1, 1) <> '/')
            AND replacement_path NOT LIKE '% %'
            AND replacement_path = lower(replacement_path)
        )
    ),
    gone_reason TEXT,
    gone_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    CHECK(expires_at IS NULL OR expires_at > gone_at),
    CHECK(updated_at >= gone_at),
    UNIQUE(site_id, language_code, old_path),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY(site_id, language_code) REFERENCES site_languages(site_id, language_code) ON DELETE CASCADE
);

CREATE TABLE seo_metadata (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    resource_type TEXT NOT NULL CHECK(resource_type IN ('content_entry','taxonomy_term','system')),
    resource_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    meta_title TEXT,
    meta_description TEXT,
    meta_robots TEXT,
    canonical_url TEXT,
    og_title TEXT,
    og_description TEXT,
    og_image_media_id INTEGER,
    twitter_title TEXT,
    twitter_description TEXT,
    twitter_image_media_id INTEGER,
    hreflang_code TEXT,
    json_ld TEXT,
    seo_score INTEGER,
    source_published_revision_id INTEGER,
    source_revision_checksum_sha256 TEXT,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(site_id, resource_type, resource_id, language_code),
    CHECK(json_ld IS NULL OR json_valid(json_ld)),
    CHECK(meta_robots IS NULL OR trim(meta_robots) <> ''),
    CHECK(seo_score IS NULL OR (seo_score >= 0 AND seo_score <= 100)),
    CHECK(
        resource_type <> 'content_entry'
        OR (
            source_published_revision_id IS NOT NULL
            AND source_revision_checksum_sha256 IS NOT NULL
            AND length(source_revision_checksum_sha256) = 64
            AND source_revision_checksum_sha256 NOT GLOB '*[^0-9a-f]*'
        )
    ),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY(site_id, language_code) REFERENCES site_languages(site_id, language_code) ON DELETE CASCADE,
    FOREIGN KEY(source_published_revision_id) REFERENCES revisions(id),
    FOREIGN KEY(og_image_media_id) REFERENCES media_assets(id) ON DELETE SET NULL,
    FOREIGN KEY(twitter_image_media_id) REFERENCES media_assets(id) ON DELETE SET NULL
);

CREATE TABLE seo_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    resource_type TEXT NOT NULL,
    resource_subtype TEXT,
    language_code TEXT NOT NULL,
    meta_title_template TEXT,
    meta_description_template TEXT,
    og_title_template TEXT,
    og_description_template TEXT,
    robots_default TEXT,
    json_ld_template TEXT,
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY(site_id, language_code) REFERENCES site_languages(site_id, language_code) ON DELETE CASCADE
);

CREATE UNIQUE INDEX idx_seo_templates_unique_generic
ON seo_templates(site_id, resource_type, language_code)
WHERE resource_subtype IS NULL;

CREATE UNIQUE INDEX idx_seo_templates_unique_subtype
ON seo_templates(site_id, resource_type, resource_subtype, language_code)
WHERE resource_subtype IS NOT NULL;

CREATE TABLE seo_audit_issues (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    resource_type TEXT NOT NULL CHECK(resource_type IN ('content_entry','taxonomy_term','system')),
    resource_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    issue_code TEXT NOT NULL,
    severity TEXT NOT NULL CHECK(severity IN ('low','medium','high','critical')),
    message TEXT NOT NULL,
    is_resolved INTEGER NOT NULL DEFAULT 0,
    detected_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY(site_id, language_code) REFERENCES site_languages(site_id, language_code) ON DELETE CASCADE
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
    source_published_revision_id INTEGER,
    source_revision_checksum_sha256 TEXT,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(site_id, resource_type, resource_id, language_code),
    CHECK(
        resource_type <> 'content_entry'
        OR (
            source_published_revision_id IS NOT NULL
            AND source_revision_checksum_sha256 IS NOT NULL
            AND length(source_revision_checksum_sha256) = 64
            AND source_revision_checksum_sha256 NOT GLOB '*[^0-9a-f]*'
        )
    ),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY(site_id, language_code) REFERENCES site_languages(site_id, language_code) ON DELETE CASCADE,
    FOREIGN KEY(source_published_revision_id) REFERENCES revisions(id)
);

CREATE TABLE editor_block_types (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    block_type TEXT NOT NULL UNIQUE,
    label TEXT NOT NULL,
    category TEXT NOT NULL DEFAULT 'content',
    schema_json TEXT NOT NULL CHECK(json_valid(schema_json)),
    is_enabled INTEGER NOT NULL DEFAULT 1 CHECK(is_enabled IN (0,1)),
    sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE public_content_snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    resource_type TEXT NOT NULL CHECK(resource_type IN ('content_entry')),
    resource_id INTEGER NOT NULL,
    route_path TEXT NOT NULL CHECK(
        route_path LIKE '/%'
        AND (route_path = '/' OR instr(route_path, '//') = 0)
        AND (route_path = '/' OR substr(route_path, -1, 1) <> '/')
        AND route_path NOT LIKE '% %'
        AND route_path = lower(route_path)
    ),
    title TEXT NOT NULL,
    slug TEXT NOT NULL,
    blocks_json TEXT NOT NULL DEFAULT '[]' CHECK(json_valid(blocks_json)),
    block_count INTEGER NOT NULL DEFAULT 0 CHECK(block_count >= 0),
    document_json TEXT NOT NULL CHECK(json_valid(document_json)),
    seo_json TEXT NOT NULL CHECK(json_valid(seo_json)),
    source_published_revision_id INTEGER NOT NULL,
    source_revision_checksum_sha256 TEXT NOT NULL CHECK(length(source_revision_checksum_sha256) = 64 AND source_revision_checksum_sha256 NOT GLOB '*[^0-9a-f]*'),
    published_at TEXT,
    projected_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(site_id, language_code, resource_type, resource_id),
    UNIQUE(site_id, language_code, route_path),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY(site_id, language_code) REFERENCES site_languages(site_id, language_code) ON DELETE CASCADE,
    FOREIGN KEY(source_published_revision_id) REFERENCES revisions(id)
);


CREATE VIRTUAL TABLE search_documents_fts
USING fts5(
    title,
    summary,
    search_text,
    content='search_documents',
    content_rowid='id'
);

CREATE TABLE media_folders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    folder_key TEXT NOT NULL,
    name TEXT NOT NULL,
    parent_id INTEGER,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(folder_key = TRIM(folder_key)),
    CHECK(folder_key <> ''),
    CHECK(folder_key NOT LIKE '/%'),
    CHECK(folder_key NOT LIKE '%/'),
    CHECK(folder_key NOT LIKE '%//%'),
    CHECK(folder_key NOT LIKE '../%' AND folder_key NOT LIKE '%/..' AND folder_key NOT LIKE '%/../%' AND folder_key <> '..'),
    CHECK(name = TRIM(name) AND name <> ''),
    CHECK(parent_id IS NULL OR parent_id <> id),
    UNIQUE(site_id, folder_key),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY(parent_id) REFERENCES media_folders(id) ON DELETE SET NULL
);

CREATE INDEX idx_media_folders_site_parent_sort ON media_folders(site_id, parent_id, sort_order, name);

CREATE TRIGGER trg_media_folders_parent_site_insert
BEFORE INSERT ON media_folders
WHEN NEW.parent_id IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'media_folders.parent_id must belong to same site')
    WHERE NOT EXISTS (SELECT 1 FROM media_folders parent WHERE parent.id = NEW.parent_id AND parent.site_id = NEW.site_id);
END;

CREATE TRIGGER trg_media_folders_parent_site_update
BEFORE UPDATE OF site_id, parent_id ON media_folders
WHEN NEW.parent_id IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'media_folders.parent_id must belong to same site')
    WHERE NOT EXISTS (SELECT 1 FROM media_folders parent WHERE parent.id = NEW.parent_id AND parent.site_id = NEW.site_id);
END;

CREATE TRIGGER trg_media_folders_site_update_referenced_assets
BEFORE UPDATE OF site_id ON media_folders
BEGIN
    SELECT RAISE(ABORT, 'media_folders.site_id would break media_assets folder consistency')
    WHERE EXISTS (SELECT 1 FROM media_assets ma WHERE ma.folder_id = OLD.id AND ma.site_id <> NEW.site_id);
END;

CREATE TRIGGER trg_media_folders_site_update_referenced_children
BEFORE UPDATE OF site_id ON media_folders
BEGIN
    SELECT RAISE(ABORT, 'media_folders.site_id would break child folder consistency')
    WHERE EXISTS (SELECT 1 FROM media_folders child WHERE child.parent_id = OLD.id AND child.site_id <> NEW.site_id);
END;

CREATE TABLE media_assets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid TEXT NOT NULL,
    site_id INTEGER NOT NULL,
    storage_disk TEXT NOT NULL DEFAULT 'local',
    path TEXT NOT NULL,
    quarantine_path TEXT,
    public_path TEXT,
    filename TEXT NOT NULL,
    original_filename TEXT,
    extension TEXT,
    mime_type TEXT NOT NULL,
    media_type TEXT NOT NULL CHECK(media_type IN ('image','video','audio','document','binary')),
    size_bytes INTEGER NOT NULL DEFAULT 0,
    width INTEGER,
    height INTEGER,
    sha256 TEXT NOT NULL,
    duplicate_of_media_id INTEGER,
    lifecycle_status TEXT NOT NULL DEFAULT 'quarantined' CHECK(lifecycle_status IN ('quarantined','ready','rejected','delete_pending','deleted')),
    validation_status TEXT NOT NULL DEFAULT 'pending' CHECK(validation_status IN ('pending','valid','invalid')),
    validation_errors_json TEXT CHECK(validation_errors_json IS NULL OR json_valid(validation_errors_json)),
    variants_status TEXT NOT NULL DEFAULT 'pending' CHECK(variants_status IN ('pending','ready','failed')),
    metadata_status TEXT NOT NULL DEFAULT 'incomplete' CHECK(metadata_status IN ('incomplete','complete')),
    dominant_color TEXT,
    copyright_text TEXT,
    license_type TEXT,
    source_url TEXT,
    folder_id INTEGER NOT NULL,
    metadata_json TEXT CHECK(metadata_json IS NULL OR json_valid(metadata_json)),
    uploaded_by_user_id INTEGER,
    validated_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_requested_at TEXT,
    deleted_at TEXT,
    CHECK(delete_requested_at IS NULL OR lifecycle_status IN ('delete_pending','deleted')),
    CHECK(deleted_at IS NULL OR lifecycle_status = 'deleted'),
    CHECK(updated_at >= created_at),
    CHECK(storage_disk IN ('local','s3')),
    CHECK(path = TRIM(path) AND path <> '' AND path NOT LIKE '/%' AND path NOT LIKE '%..%' AND path NOT LIKE '%//%'),
    CHECK(public_path IS NULL OR (public_path = TRIM(public_path) AND public_path NOT LIKE '/%' AND public_path NOT LIKE '%..%' AND public_path NOT LIKE '%//%')),
    CHECK(quarantine_path IS NULL OR (quarantine_path = TRIM(quarantine_path) AND quarantine_path NOT LIKE '/%' AND quarantine_path NOT LIKE '%..%' AND quarantine_path NOT LIKE '%//%')),
    CHECK(mime_type IN ('image/jpeg','image/png','image/webp','image/gif','audio/mpeg','audio/mp4','audio/ogg','video/mp4','video/webm','application/pdf')),
    CHECK(extension IN ('jpg','png','webp','gif','mp3','m4a','ogg','mp4','webm','pdf')),
    CHECK(size_bytes > 0),
    CHECK(width IS NULL OR width > 0),
    CHECK(height IS NULL OR height > 0),
    UNIQUE(uuid),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY(folder_id) REFERENCES media_folders(id) ON DELETE RESTRICT,
    FOREIGN KEY(duplicate_of_media_id) REFERENCES media_assets(id) ON DELETE SET NULL
);

CREATE INDEX idx_media_assets_site_status ON media_assets(site_id, lifecycle_status, created_at);
CREATE INDEX idx_media_assets_sha256 ON media_assets(sha256);
CREATE UNIQUE INDEX idx_media_assets_unique_active_sha256
    ON media_assets(site_id, sha256)
    WHERE lifecycle_status != 'deleted';
CREATE INDEX idx_media_assets_delete_pending
    ON media_assets(site_id, delete_requested_at)
    WHERE lifecycle_status = 'delete_pending';
CREATE INDEX idx_media_assets_metadata_status
    ON media_assets(site_id, metadata_status, lifecycle_status);
CREATE INDEX idx_media_assets_folder_status
    ON media_assets(site_id, folder_id, lifecycle_status, created_at);
CREATE INDEX idx_media_assets_type_status_created
    ON media_assets(site_id, media_type, lifecycle_status, created_at);
CREATE INDEX idx_media_assets_variants_status
    ON media_assets(site_id, variants_status, lifecycle_status);
CREATE INDEX idx_media_assets_sha_status
    ON media_assets(site_id, sha256, lifecycle_status);

CREATE TRIGGER trg_media_assets_folder_site_insert
BEFORE INSERT ON media_assets
BEGIN
    SELECT RAISE(ABORT, 'media_assets.folder_id must belong to same site')
    WHERE NOT EXISTS (SELECT 1 FROM media_folders mf WHERE mf.id = NEW.folder_id AND mf.site_id = NEW.site_id);
END;

CREATE TRIGGER trg_media_assets_folder_site_update
BEFORE UPDATE OF site_id, folder_id ON media_assets
BEGIN
    SELECT RAISE(ABORT, 'media_assets.folder_id must belong to same site')
    WHERE NOT EXISTS (SELECT 1 FROM media_folders mf WHERE mf.id = NEW.folder_id AND mf.site_id = NEW.site_id);
END;

CREATE TABLE media_asset_localizations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    media_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    alt_text TEXT,
    caption TEXT,
    title TEXT,
    is_alt_verified INTEGER NOT NULL DEFAULT 0 CHECK(is_alt_verified IN (0,1)),
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(alt_text IS NULL OR length(TRIM(alt_text)) BETWEEN 1 AND 180),
    CHECK(caption IS NULL OR length(TRIM(caption)) BETWEEN 1 AND 300),
    CHECK(title IS NULL OR length(TRIM(title)) BETWEEN 1 AND 160),
    UNIQUE(media_id, language_code),
    FOREIGN KEY(media_id) REFERENCES media_assets(id) ON DELETE CASCADE
);

CREATE TABLE media_variant_presets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    preset_key TEXT NOT NULL,
    width INTEGER NOT NULL,
    height INTEGER,
    format TEXT NOT NULL DEFAULT 'webp' CHECK(format IN ('webp','jpg','jpeg','png')),
    quality INTEGER NOT NULL DEFAULT 82 CHECK(quality BETWEEN 1 AND 100),
    mode TEXT NOT NULL DEFAULT 'fit' CHECK(mode IN ('fit','crop','contain')),
    sort_order INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0,1)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(preset_key = TRIM(preset_key)),
    CHECK(preset_key <> ''),
    CHECK(preset_key NOT LIKE '%/%'),
    CHECK(width > 0),
    CHECK(height IS NULL OR height > 0),
    UNIQUE(site_id, preset_key),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE
);

CREATE INDEX idx_media_variant_presets_site_active
    ON media_variant_presets(site_id, is_active, sort_order, preset_key);

CREATE TABLE media_asset_variants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    media_id INTEGER NOT NULL,
    preset_id INTEGER,
    variant_key TEXT NOT NULL,
    path TEXT NOT NULL,
    width INTEGER,
    height INTEGER,
    format TEXT,
    mime_type TEXT,
    size_bytes INTEGER,
    sha256 TEXT,
    generator TEXT,
    generation_status TEXT NOT NULL DEFAULT 'ready' CHECK(generation_status IN ('pending','ready','failed')),
    generated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(path = TRIM(path) AND path <> '' AND path NOT LIKE '/%' AND path NOT LIKE '%..%' AND path NOT LIKE '%//%'),
    CHECK(width IS NULL OR width > 0),
    CHECK(height IS NULL OR height > 0),
    CHECK(format IS NULL OR format IN ('jpg','jpeg','png','webp','gif','mp3','m4a','ogg','mp4','webm','pdf')),
    CHECK(mime_type IS NULL OR mime_type IN ('image/jpeg','image/png','image/webp','image/gif','audio/mpeg','audio/mp4','audio/ogg','video/mp4','video/webm','application/pdf')),
    CHECK(size_bytes IS NULL OR size_bytes > 0),
    UNIQUE(media_id, variant_key),
    FOREIGN KEY(media_id) REFERENCES media_assets(id) ON DELETE CASCADE,
    FOREIGN KEY(preset_id) REFERENCES media_variant_presets(id) ON DELETE SET NULL
);

CREATE INDEX idx_media_asset_variants_preset ON media_asset_variants(preset_id);

CREATE TRIGGER trg_media_asset_variants_preset_site_insert
BEFORE INSERT ON media_asset_variants
WHEN NEW.preset_id IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'media_asset_variants.preset_id must belong to same site as media')
    WHERE NOT EXISTS (
        SELECT 1
        FROM media_assets ma
        JOIN media_variant_presets mvp ON mvp.id = NEW.preset_id AND mvp.site_id = ma.site_id
        WHERE ma.id = NEW.media_id
    );
END;

CREATE TRIGGER trg_media_asset_variants_preset_site_update
BEFORE UPDATE OF media_id, preset_id ON media_asset_variants
WHEN NEW.preset_id IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'media_asset_variants.preset_id must belong to same site as media')
    WHERE NOT EXISTS (
        SELECT 1
        FROM media_assets ma
        JOIN media_variant_presets mvp ON mvp.id = NEW.preset_id AND mvp.site_id = ma.site_id
        WHERE ma.id = NEW.media_id
    );
END;

CREATE TRIGGER trg_media_assets_site_update_referenced_variants
BEFORE UPDATE OF site_id ON media_assets
BEGIN
    SELECT RAISE(ABORT, 'media_assets.site_id would break media_asset_variants preset consistency')
    WHERE EXISTS (
        SELECT 1
        FROM media_asset_variants mav
        JOIN media_variant_presets mvp ON mvp.id = mav.preset_id
        WHERE mav.media_id = OLD.id AND mvp.site_id <> NEW.site_id
    );
END;

CREATE TRIGGER trg_media_variant_presets_site_update_referenced_variants
BEFORE UPDATE OF site_id ON media_variant_presets
BEGIN
    SELECT RAISE(ABORT, 'media_variant_presets.site_id would break generated variant consistency')
    WHERE EXISTS (
        SELECT 1
        FROM media_asset_variants mav
        JOIN media_assets ma ON ma.id = mav.media_id
        WHERE mav.preset_id = OLD.id AND ma.site_id <> NEW.site_id
    );
END;

CREATE TABLE media_usages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    media_id INTEGER NOT NULL,
    site_id INTEGER NOT NULL,
    resource_type TEXT NOT NULL CHECK(resource_type IN ('content_entry','taxonomy_term','system')),
    resource_id INTEGER NOT NULL,
    field_key TEXT,
    language_code TEXT,
    usage_context TEXT NOT NULL DEFAULT 'content' CHECK(usage_context IN ('content','seo','opengraph','thumbnail','gallery','decorative','system')),
    source_revision_id INTEGER,
    alt_policy TEXT NOT NULL DEFAULT 'decorative_allowed' CHECK(alt_policy IN ('required','decorative_allowed','forbidden')),
    alt_text_snapshot TEXT,
    usage_hash TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(usage_hash),
    FOREIGN KEY(media_id) REFERENCES media_assets(id) ON DELETE CASCADE,
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY(source_revision_id) REFERENCES revisions(id) ON DELETE SET NULL
);

CREATE INDEX idx_media_usages_media ON media_usages(media_id);
CREATE INDEX idx_media_usages_resource ON media_usages(site_id, resource_type, resource_id);
CREATE INDEX idx_media_usages_source_revision ON media_usages(source_revision_id, media_id);
CREATE INDEX idx_media_usages_context_language ON media_usages(site_id, usage_context, language_code);
CREATE INDEX idx_media_usages_alt_required
    ON media_usages(media_id, language_code)
    WHERE alt_policy = 'required';

CREATE TABLE media_asset_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    media_id INTEGER NOT NULL,
    site_id INTEGER NOT NULL,
    event_type TEXT NOT NULL CHECK(event_type IN ('uploaded','deduplicated','validated','promoted','variants_generated','metadata_updated','attached','delete_pending','deleted','rejected')),
    actor_iam_user_id INTEGER,
    payload_json TEXT CHECK(payload_json IS NULL OR json_valid(payload_json)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(media_id) REFERENCES media_assets(id) ON DELETE CASCADE,
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE
);

CREATE INDEX idx_media_asset_events_media ON media_asset_events(media_id, created_at);
CREATE INDEX idx_media_asset_events_site ON media_asset_events(site_id, created_at);
CREATE INDEX IF NOT EXISTS idx_media_asset_events_actor_iam_user_id ON media_asset_events(actor_iam_user_id);

CREATE TABLE menus (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    menu_key TEXT NOT NULL,
    name TEXT NOT NULL,
    menu_location TEXT,
    language_mode TEXT NOT NULL DEFAULT 'shared' CHECK(language_mode IN ('shared','localized')),
    is_active INTEGER NOT NULL DEFAULT 1,
    UNIQUE(site_id, menu_key),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE
);

CREATE TABLE menu_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    menu_id INTEGER NOT NULL,
    language_code TEXT,
    parent_id INTEGER,
    resource_type TEXT CHECK(resource_type IS NULL OR resource_type IN ('content_entry','taxonomy_term','system')),
    resource_id INTEGER,
    term_id INTEGER,
    manual_url TEXT,
    open_in_new_tab INTEGER NOT NULL DEFAULT 0,
    css_class TEXT,
    visibility_rules_json TEXT,
    sort_order INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    CHECK((resource_type IS NULL AND resource_id IS NULL) OR (resource_type IS NOT NULL AND resource_id IS NOT NULL)),
    CHECK(manual_url IS NULL OR trim(manual_url) <> ''),
    CHECK(
        (CASE WHEN resource_type IS NOT NULL AND resource_id IS NOT NULL THEN 1 ELSE 0 END) +
        (CASE WHEN term_id IS NOT NULL THEN 1 ELSE 0 END) +
        (CASE WHEN manual_url IS NOT NULL THEN 1 ELSE 0 END) = 1
    ),
    FOREIGN KEY(menu_id) REFERENCES menus(id) ON DELETE CASCADE,
    FOREIGN KEY(parent_id) REFERENCES menu_items(id) ON DELETE CASCADE,
    FOREIGN KEY(term_id) REFERENCES taxonomy_terms(id) ON DELETE SET NULL
);

CREATE INDEX idx_menu_items_menu_language_parent_sort
    ON menu_items(menu_id, language_code, parent_id, sort_order, id);

CREATE INDEX idx_menu_items_menu_parent_sort
    ON menu_items(menu_id, parent_id, sort_order, id);

CREATE TABLE menu_item_localizations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    menu_item_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    label TEXT NOT NULL,
    title_attr TEXT,
    UNIQUE(menu_item_id, language_code),
    FOREIGN KEY(menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE
);

CREATE TABLE layout_containers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    entry_id INTEGER NOT NULL,
    localization_id INTEGER,
    container_key TEXT NOT NULL,
    container_type TEXT NOT NULL DEFAULT 'page',
    theme_id INTEGER,
    sort_order INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY(entry_id) REFERENCES content_entries(id) ON DELETE CASCADE,
    FOREIGN KEY(localization_id) REFERENCES content_entry_localizations(id) ON DELETE CASCADE,
    FOREIGN KEY(theme_id) REFERENCES themes(id) ON DELETE SET NULL
);

CREATE TABLE layout_blocks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    container_id INTEGER NOT NULL,
    module_id INTEGER,
    block_type TEXT NOT NULL,
    block_key TEXT,
    component_key TEXT,
    layout_width TEXT,
    column_position INTEGER,
    row_group INTEGER,
    sort_order INTEGER NOT NULL DEFAULT 0,
    visibility_rules_json TEXT,
    css_class TEXT,
    inline_style TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','review','published','archived','scheduled')),
    FOREIGN KEY(container_id) REFERENCES layout_containers(id) ON DELETE CASCADE,
    FOREIGN KEY(module_id) REFERENCES modules(id) ON DELETE SET NULL
);

CREATE TABLE layout_block_localizations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    block_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    title TEXT,
    content_text TEXT,
    embed_code TEXT,
    settings_json TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','review','published','archived','scheduled')),
    UNIQUE(block_id, language_code),
    FOREIGN KEY(block_id) REFERENCES layout_blocks(id) ON DELETE CASCADE
);


-- Contraintes langue/site pour tables localisées sans site_id direct.
-- Ces triggers évitent qu'une ligne enfant utilise une langue absente du site porté par son parent.
CREATE TRIGGER trg_content_entry_localizations_site_language_insert
BEFORE INSERT ON content_entry_localizations
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'language_code is not declared for this entry site')
    WHERE NOT EXISTS (
        SELECT 1
        FROM content_entries ce
        JOIN site_languages sl ON sl.site_id = ce.site_id AND sl.language_code = NEW.language_code
        WHERE ce.id = NEW.entry_id
    );
END;

CREATE TRIGGER trg_content_entry_localizations_site_language_update
BEFORE UPDATE OF entry_id, language_code ON content_entry_localizations
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'language_code is not declared for this entry site')
    WHERE NOT EXISTS (
        SELECT 1
        FROM content_entries ce
        JOIN site_languages sl ON sl.site_id = ce.site_id AND sl.language_code = NEW.language_code
        WHERE ce.id = NEW.entry_id
    );
END;

CREATE TRIGGER trg_taxonomy_term_localizations_consistency_insert
BEFORE INSERT ON taxonomy_term_localizations
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'taxonomy localization site_id/taxonomy_id/term_id are inconsistent')
    WHERE NOT EXISTS (
        SELECT 1
        FROM taxonomy_terms tt
        JOIN taxonomies t ON t.id = tt.taxonomy_id
        WHERE tt.id = NEW.term_id
          AND tt.taxonomy_id = NEW.taxonomy_id
          AND t.site_id = NEW.site_id
    );
END;

CREATE TRIGGER trg_taxonomy_term_localizations_consistency_update
BEFORE UPDATE OF site_id, taxonomy_id, term_id ON taxonomy_term_localizations
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'taxonomy localization site_id/taxonomy_id/term_id are inconsistent')
    WHERE NOT EXISTS (
        SELECT 1
        FROM taxonomy_terms tt
        JOIN taxonomies t ON t.id = tt.taxonomy_id
        WHERE tt.id = NEW.term_id
          AND tt.taxonomy_id = NEW.taxonomy_id
          AND t.site_id = NEW.site_id
    );
END;

CREATE TRIGGER trg_media_asset_localizations_site_language_insert
BEFORE INSERT ON media_asset_localizations
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'language_code is not declared for this media site')
    WHERE NOT EXISTS (
        SELECT 1
        FROM media_assets ma
        JOIN site_languages sl ON sl.site_id = ma.site_id AND sl.language_code = NEW.language_code
        WHERE ma.id = NEW.media_id
    );
END;

CREATE TRIGGER trg_media_asset_localizations_site_language_update
BEFORE UPDATE OF media_id, language_code ON media_asset_localizations
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'language_code is not declared for this media site')
    WHERE NOT EXISTS (
        SELECT 1
        FROM media_assets ma
        JOIN site_languages sl ON sl.site_id = ma.site_id AND sl.language_code = NEW.language_code
        WHERE ma.id = NEW.media_id
    );
END;

CREATE TRIGGER trg_menu_items_parent_scope_insert
BEFORE INSERT ON menu_items
FOR EACH ROW
WHEN NEW.parent_id IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'menu_items.parent_id must point to an item in the same menu')
    WHERE NOT EXISTS (
        SELECT 1 FROM menu_items parent
        WHERE parent.id = NEW.parent_id
          AND parent.menu_id = NEW.menu_id
    );
END;

CREATE TRIGGER trg_menu_items_parent_scope_update
BEFORE UPDATE OF menu_id, parent_id ON menu_items
FOR EACH ROW
WHEN NEW.parent_id IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'menu_items.parent_id cannot point to itself')
    WHERE NEW.parent_id = NEW.id;

    SELECT RAISE(ABORT, 'menu_items.parent_id must point to an item in the same menu')
    WHERE NOT EXISTS (
        SELECT 1 FROM menu_items parent
        WHERE parent.id = NEW.parent_id
          AND parent.menu_id = NEW.menu_id
    );
END;

CREATE TRIGGER trg_menu_items_language_scope_insert
BEFORE INSERT ON menu_items
FOR EACH ROW
WHEN NEW.language_code IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'menu_items.language_code is not declared for this menu site')
    WHERE NOT EXISTS (
        SELECT 1
        FROM menus m
        JOIN site_languages sl ON sl.site_id = m.site_id AND sl.language_code = NEW.language_code
        WHERE m.id = NEW.menu_id
    );
END;

CREATE TRIGGER trg_menu_items_language_scope_update
BEFORE UPDATE OF menu_id, language_code ON menu_items
FOR EACH ROW
WHEN NEW.language_code IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'menu_items.language_code is not declared for this menu site')
    WHERE NOT EXISTS (
        SELECT 1
        FROM menus m
        JOIN site_languages sl ON sl.site_id = m.site_id AND sl.language_code = NEW.language_code
        WHERE m.id = NEW.menu_id
    );
END;

CREATE TRIGGER trg_menu_item_localizations_site_language_insert
BEFORE INSERT ON menu_item_localizations
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'language_code is not declared for this menu site')
    WHERE NOT EXISTS (
        SELECT 1
        FROM menu_items mi
        JOIN menus m ON m.id = mi.menu_id
        JOIN site_languages sl ON sl.site_id = m.site_id AND sl.language_code = NEW.language_code
        WHERE mi.id = NEW.menu_item_id
    );
END;

CREATE TRIGGER trg_menu_item_localizations_site_language_update
BEFORE UPDATE OF menu_item_id, language_code ON menu_item_localizations
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'language_code is not declared for this menu site')
    WHERE NOT EXISTS (
        SELECT 1
        FROM menu_items mi
        JOIN menus m ON m.id = mi.menu_id
        JOIN site_languages sl ON sl.site_id = m.site_id AND sl.language_code = NEW.language_code
        WHERE mi.id = NEW.menu_item_id
    );
END;

CREATE TRIGGER trg_layout_block_localizations_site_language_insert
BEFORE INSERT ON layout_block_localizations
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'language_code is not declared for this layout block site')
    WHERE NOT EXISTS (
        SELECT 1
        FROM layout_blocks lb
        JOIN layout_containers lc ON lc.id = lb.container_id
        JOIN content_entries ce ON ce.id = lc.entry_id
        JOIN site_languages sl ON sl.site_id = ce.site_id AND sl.language_code = NEW.language_code
        WHERE lb.id = NEW.block_id
    );
END;

CREATE TRIGGER trg_layout_block_localizations_site_language_update
BEFORE UPDATE OF block_id, language_code ON layout_block_localizations
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'language_code is not declared for this layout block site')
    WHERE NOT EXISTS (
        SELECT 1
        FROM layout_blocks lb
        JOIN layout_containers lc ON lc.id = lb.container_id
        JOIN content_entries ce ON ce.id = lc.entry_id
        JOIN site_languages sl ON sl.site_id = ce.site_id AND sl.language_code = NEW.language_code
        WHERE lb.id = NEW.block_id
    );
END;


CREATE TRIGGER trg_site_localizations_media_site_insert
BEFORE INSERT ON site_localizations
FOR EACH ROW
WHEN NEW.og_default_image_media_id IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'site_localizations.og_default_image_media_id must reference a media asset from the same site')
    WHERE NOT EXISTS (
        SELECT 1
        FROM media_assets ma
        WHERE ma.id = NEW.og_default_image_media_id
          AND ma.site_id = NEW.site_id
    );
END;

CREATE TRIGGER trg_site_localizations_media_site_update
BEFORE UPDATE OF site_id, og_default_image_media_id ON site_localizations
FOR EACH ROW
WHEN NEW.og_default_image_media_id IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'site_localizations.og_default_image_media_id must reference a media asset from the same site')
    WHERE NOT EXISTS (
        SELECT 1
        FROM media_assets ma
        WHERE ma.id = NEW.og_default_image_media_id
          AND ma.site_id = NEW.site_id
    );
END;

CREATE TRIGGER trg_seo_metadata_og_media_site_insert
BEFORE INSERT ON seo_metadata
FOR EACH ROW
WHEN NEW.og_image_media_id IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'seo_metadata.og_image_media_id must reference a media asset from the same site')
    WHERE NOT EXISTS (
        SELECT 1
        FROM media_assets ma
        WHERE ma.id = NEW.og_image_media_id
          AND ma.site_id = NEW.site_id
    );
END;

CREATE TRIGGER trg_seo_metadata_og_media_site_update
BEFORE UPDATE OF site_id, og_image_media_id ON seo_metadata
FOR EACH ROW
WHEN NEW.og_image_media_id IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'seo_metadata.og_image_media_id must reference a media asset from the same site')
    WHERE NOT EXISTS (
        SELECT 1
        FROM media_assets ma
        WHERE ma.id = NEW.og_image_media_id
          AND ma.site_id = NEW.site_id
    );
END;

CREATE TRIGGER trg_seo_metadata_twitter_media_site_insert
BEFORE INSERT ON seo_metadata
FOR EACH ROW
WHEN NEW.twitter_image_media_id IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'seo_metadata.twitter_image_media_id must reference a media asset from the same site')
    WHERE NOT EXISTS (
        SELECT 1
        FROM media_assets ma
        WHERE ma.id = NEW.twitter_image_media_id
          AND ma.site_id = NEW.site_id
    );
END;

CREATE TRIGGER trg_seo_metadata_twitter_media_site_update
BEFORE UPDATE OF site_id, twitter_image_media_id ON seo_metadata
FOR EACH ROW
WHEN NEW.twitter_image_media_id IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'seo_metadata.twitter_image_media_id must reference a media asset from the same site')
    WHERE NOT EXISTS (
        SELECT 1
        FROM media_assets ma
        WHERE ma.id = NEW.twitter_image_media_id
          AND ma.site_id = NEW.site_id
    );
END;

CREATE TRIGGER trg_content_entry_field_values_media_site_insert
BEFORE INSERT ON content_entry_field_values
FOR EACH ROW
WHEN NEW.value_media_id IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'content_entry_field_values.value_media_id must reference a media asset from the same entry site')
    WHERE NOT EXISTS (
        SELECT 1
        FROM content_entries ce
        JOIN media_assets ma ON ma.id = NEW.value_media_id
        WHERE ce.id = NEW.entry_id
          AND ma.site_id = ce.site_id
    );
END;

CREATE TRIGGER trg_content_entry_field_values_media_site_update
BEFORE UPDATE OF entry_id, value_media_id ON content_entry_field_values
FOR EACH ROW
WHEN NEW.value_media_id IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'content_entry_field_values.value_media_id must reference a media asset from the same entry site')
    WHERE NOT EXISTS (
        SELECT 1
        FROM content_entries ce
        JOIN media_assets ma ON ma.id = NEW.value_media_id
        WHERE ce.id = NEW.entry_id
          AND ma.site_id = ce.site_id
    );
END;

CREATE TRIGGER trg_media_assets_site_update_referenced_site_localizations
BEFORE UPDATE OF site_id ON media_assets
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'cannot move media asset to another site while referenced by site_localizations')
    WHERE EXISTS (
        SELECT 1
        FROM site_localizations sl
        WHERE sl.og_default_image_media_id = OLD.id
          AND sl.site_id <> NEW.site_id
    );
END;

CREATE TRIGGER trg_media_assets_site_update_referenced_seo_metadata
BEFORE UPDATE OF site_id ON media_assets
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'cannot move media asset to another site while referenced by seo_metadata')
    WHERE EXISTS (
        SELECT 1
        FROM seo_metadata sm
        WHERE (sm.og_image_media_id = OLD.id OR sm.twitter_image_media_id = OLD.id)
          AND sm.site_id <> NEW.site_id
    );
END;

CREATE TRIGGER trg_media_assets_site_update_referenced_field_values
BEFORE UPDATE OF site_id ON media_assets
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'cannot move media asset to another site while referenced by content_entry_field_values')
    WHERE EXISTS (
        SELECT 1
        FROM content_entry_field_values fv
        JOIN content_entries ce ON ce.id = fv.entry_id
        WHERE fv.value_media_id = OLD.id
          AND ce.site_id <> NEW.site_id
    );
END;

CREATE TABLE revisions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_type TEXT NOT NULL,
    resource_id INTEGER NOT NULL,
    blueprint_id INTEGER,
    blueprint_version_id INTEGER,
    revision_number INTEGER NOT NULL,
    language_code TEXT,
    workflow_status TEXT NOT NULL DEFAULT 'draft' CHECK(workflow_status IN ('draft','published','superseded')),
    document_schema_version INTEGER NOT NULL DEFAULT 1,
    base_revision_id INTEGER,
    source_published_revision_id INTEGER,
    created_from_event TEXT,
    revision_label TEXT,
    summary TEXT,
    change_notes TEXT,
    document_json TEXT NOT NULL CHECK(json_valid(document_json)),
    checksum_sha256 TEXT CHECK(checksum_sha256 IS NULL OR (
        length(checksum_sha256) = 64
        AND checksum_sha256 NOT GLOB '*[^0-9a-f]*'
    )),
    scheduled_for TEXT,
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    published_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_at TEXT,
    UNIQUE(resource_type, resource_id, language_code, revision_number),
    FOREIGN KEY(base_revision_id) REFERENCES revisions(id) ON DELETE SET NULL,
    FOREIGN KEY(source_published_revision_id) REFERENCES revisions(id) ON DELETE SET NULL,
    FOREIGN KEY(blueprint_id) REFERENCES blueprints(id) ON DELETE RESTRICT,
    FOREIGN KEY(blueprint_version_id) REFERENCES blueprint_versions(id) ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_revisions_content_entry_language_number
ON revisions(resource_type, resource_id, language_code, revision_number DESC);

CREATE INDEX IF NOT EXISTS idx_revisions_content_entry_created_at
ON revisions(resource_type, resource_id, language_code, created_at DESC);


CREATE INDEX IF NOT EXISTS idx_revisions_blueprint_version
ON revisions(blueprint_version_id, resource_type, resource_id);

-- Les révisions éditoriales réelles doivent figer le blueprint utilisé. Les
-- validateurs peuvent encore créer des lignes synthétiques sans content_entry.
CREATE TRIGGER revisions_bind_blueprint_version_after_insert
AFTER INSERT ON revisions
WHEN NEW.resource_type = 'content_entry'
 AND EXISTS (
     SELECT 1 FROM content_entries ce
     JOIN content_types ct ON ct.id = ce.content_type_id
     JOIN blueprints b ON b.resource_type = 'content_type'
        AND (b.legacy_content_type_id = ct.id OR b.blueprint_key = ct.type_key)
        AND (b.site_id = ce.site_id OR b.site_id IS NULL)
     WHERE ce.id = NEW.resource_id AND b.active_version_id IS NOT NULL
 )
BEGIN
    UPDATE revisions
       SET blueprint_id = COALESCE(NEW.blueprint_id, (
               SELECT b.id FROM content_entries ce
               JOIN content_types ct ON ct.id = ce.content_type_id
               JOIN blueprints b ON b.resource_type = 'content_type'
                  AND (b.legacy_content_type_id = ct.id OR b.blueprint_key = ct.type_key)
                  AND (b.site_id = ce.site_id OR b.site_id IS NULL)
               WHERE ce.id = NEW.resource_id
               ORDER BY b.site_id IS NOT NULL DESC LIMIT 1
           )),
           blueprint_version_id = COALESCE(NEW.blueprint_version_id, (
               SELECT b.active_version_id FROM content_entries ce
               JOIN content_types ct ON ct.id = ce.content_type_id
               JOIN blueprints b ON b.resource_type = 'content_type'
                  AND (b.legacy_content_type_id = ct.id OR b.blueprint_key = ct.type_key)
                  AND (b.site_id = ce.site_id OR b.site_id IS NULL)
               JOIN blueprint_versions bv ON bv.id = b.active_version_id AND bv.blueprint_id = b.id AND bv.is_active = 1
               WHERE ce.id = NEW.resource_id
               ORDER BY b.site_id IS NOT NULL DESC LIMIT 1
           ))
     WHERE id = NEW.id;
    SELECT CASE WHEN (SELECT blueprint_id FROM revisions WHERE id = NEW.id) IS NULL
                  OR (SELECT blueprint_version_id FROM revisions WHERE id = NEW.id) IS NULL
        THEN RAISE(ABORT, 'content revision requires an active blueprint version') END;
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM revisions r JOIN blueprint_versions bv ON bv.id = r.blueprint_version_id
        WHERE r.id = NEW.id AND bv.blueprint_id = r.blueprint_id
    ) THEN RAISE(ABORT, 'revision blueprint version does not belong to blueprint') END;
END;

CREATE TRIGGER revisions_lock_blueprint_binding_update
BEFORE UPDATE OF blueprint_id, blueprint_version_id ON revisions
WHEN (OLD.blueprint_id IS NOT NULL OR OLD.blueprint_version_id IS NOT NULL)
 AND (OLD.blueprint_id IS NOT NEW.blueprint_id OR OLD.blueprint_version_id IS NOT NEW.blueprint_version_id)
BEGIN
    SELECT RAISE(ABORT, 'revision blueprint binding is immutable');
END;

CREATE TRIGGER blueprint_versions_immutable_definition_update
BEFORE UPDATE OF schema_json, ui_schema_json, validation_json, seo_policy_json,
    routing_policy_json, workflow_policy_json, translation_policy_json,
    permissions_policy_json, checksum_sha256 ON blueprint_versions
WHEN OLD.is_active = 1
 OR EXISTS (SELECT 1 FROM revisions r WHERE r.blueprint_version_id = OLD.id)
BEGIN
    SELECT RAISE(ABORT, 'active or used blueprint version is immutable');
END;

CREATE TRIGGER blueprint_versions_immutable_used_delete
BEFORE DELETE ON blueprint_versions
WHEN OLD.is_active = 1
 OR EXISTS (SELECT 1 FROM revisions r WHERE r.blueprint_version_id = OLD.id)
BEGIN
    SELECT RAISE(ABORT, 'active or used blueprint version cannot be deleted');
END;

CREATE TABLE revision_comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    revision_id INTEGER NOT NULL,
    language_code TEXT,
    anchor_path TEXT,
    comment_text TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','resolved')),
    created_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(revision_id) REFERENCES revisions(id) ON DELETE CASCADE
);

CREATE TABLE revision_locks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_type TEXT NOT NULL,
    resource_id INTEGER NOT NULL,
    locked_by_iam_user_id INTEGER NOT NULL,
    lock_token TEXT NOT NULL UNIQUE,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(expires_at > created_at)
);

CREATE INDEX idx_site_settings_lookup ON site_settings(site_id, namespace, setting_key);
CREATE INDEX idx_global_variables_lookup ON global_variables(site_id, variable_key, is_public);
CREATE INDEX idx_content_entries_type_status ON content_entries(content_type_id, status, workflow_state);
CREATE INDEX IF NOT EXISTS idx_content_entries_admin_updated ON content_entries(site_id, updated_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS idx_content_entries_admin_type_status_updated ON content_entries(site_id, content_type_id, status, updated_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS idx_content_localizations_admin_entry_lang_title ON content_entry_localizations(entry_id, language_code, title);
CREATE INDEX IF NOT EXISTS idx_routes_admin_entry_lang_primary ON routes(resource_type, resource_id, language_code, is_primary, is_canonical);
CREATE INDEX idx_content_working_entry_lang ON content_entry_working_revisions(entry_id, language_code);
CREATE INDEX idx_content_working_site_lang ON content_entry_working_revisions(site_id, language_code);
CREATE INDEX idx_content_publications_entry_lang ON content_entry_publications(entry_id, language_code);
CREATE INDEX idx_content_publications_site_lang ON content_entry_publications(site_id, language_code);
CREATE INDEX idx_content_publications_revision ON content_entry_publications(published_revision_id);
CREATE INDEX IF NOT EXISTS idx_content_working_updated_by_iam_user_id ON content_entry_working_revisions(updated_by_iam_user_id);
CREATE INDEX IF NOT EXISTS idx_content_publications_published_by_iam_user_id ON content_entry_publications(published_by_iam_user_id);
CREATE INDEX IF NOT EXISTS idx_content_publications_unpublished_by_iam_user_id ON content_entry_publications(unpublished_by_iam_user_id);
CREATE INDEX IF NOT EXISTS idx_revisions_created_by_iam_user_id ON revisions(created_by_iam_user_id);
CREATE INDEX IF NOT EXISTS idx_revisions_updated_by_iam_user_id ON revisions(updated_by_iam_user_id);
CREATE INDEX IF NOT EXISTS idx_revisions_published_by_iam_user_id ON revisions(published_by_iam_user_id);
CREATE INDEX IF NOT EXISTS idx_revision_comments_created_by_iam_user_id ON revision_comments(created_by_iam_user_id);
CREATE INDEX IF NOT EXISTS idx_revision_locks_locked_by_iam_user_id ON revision_locks(locked_by_iam_user_id);
CREATE INDEX idx_routes_source_revision ON routes(source_published_revision_id);
CREATE INDEX idx_seo_metadata_source_revision ON seo_metadata(source_published_revision_id);
CREATE INDEX idx_search_documents_source_revision ON search_documents(source_published_revision_id);
CREATE INDEX IF NOT EXISTS idx_content_localizations_admin_draft_path ON content_entry_localizations(draft_full_path);
CREATE INDEX idx_routes_path ON routes(full_path);
CREATE UNIQUE INDEX idx_routes_one_primary
    ON routes(site_id, language_code, resource_type, resource_id)
    WHERE is_primary = 1;
CREATE UNIQUE INDEX idx_routes_one_canonical
    ON routes(site_id, language_code, resource_type, resource_id)
    WHERE is_canonical = 1;
CREATE INDEX idx_tax_term_parent ON taxonomy_terms(parent_id);
CREATE INDEX idx_field_values_entry_field ON content_entry_field_values(entry_id, language_code, field_id);
CREATE INDEX idx_field_values_revision ON content_entry_field_values(source_revision_id);
CREATE INDEX idx_field_values_lookup_text ON content_entry_field_values(content_type_id, field_key, language_code, value_text);
CREATE INDEX idx_field_values_lookup_number ON content_entry_field_values(content_type_id, field_key, language_code, value_number);
CREATE INDEX idx_seo_metadata_lookup ON seo_metadata(site_id, resource_type, resource_id, language_code);
CREATE INDEX idx_seo_audit_lookup ON seo_audit_issues(site_id, resource_type, resource_id, language_code, is_resolved);
CREATE INDEX idx_search_documents_lookup ON search_documents(site_id, language_code, resource_type, resource_id);
CREATE INDEX IF NOT EXISTS idx_search_documents_public_runtime ON search_documents(site_id, language_code, resource_type, updated_at DESC);
CREATE INDEX IF NOT EXISTS idx_content_entry_taxonomy_terms_term ON content_entry_taxonomy_terms(term_id, entry_id);

CREATE TRIGGER IF NOT EXISTS trg_search_documents_fts_insert
AFTER INSERT ON search_documents
FOR EACH ROW
BEGIN
    INSERT INTO search_documents_fts(rowid, title, summary, search_text)
    VALUES (NEW.id, NEW.title, NEW.summary, NEW.search_text);
END;

CREATE TRIGGER IF NOT EXISTS trg_search_documents_fts_update
AFTER UPDATE OF title, summary, search_text ON search_documents
FOR EACH ROW
BEGIN
    INSERT INTO search_documents_fts(search_documents_fts, rowid, title, summary, search_text)
    VALUES ('delete', OLD.id, OLD.title, OLD.summary, OLD.search_text);
    INSERT INTO search_documents_fts(rowid, title, summary, search_text)
    VALUES (NEW.id, NEW.title, NEW.summary, NEW.search_text);
END;

CREATE TRIGGER IF NOT EXISTS trg_search_documents_fts_delete
AFTER DELETE ON search_documents
FOR EACH ROW
BEGIN
    INSERT INTO search_documents_fts(search_documents_fts, rowid, title, summary, search_text)
    VALUES ('delete', OLD.id, OLD.title, OLD.summary, OLD.search_text);
END;


CREATE TABLE webhook_endpoints (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER,
    name TEXT NOT NULL DEFAULT '',
    url TEXT NOT NULL,
    events_json TEXT NOT NULL CHECK(json_valid(events_json)),
    secret TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0, 1)),
    max_attempts INTEGER NOT NULL DEFAULT 5 CHECK(max_attempts BETWEEN 1 AND 25),
    last_attempt_at TEXT,
    next_attempt_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(url LIKE 'https://%' OR url LIKE 'http://localhost:%' OR url LIKE 'http://127.0.0.1:%'),
    CHECK(length(secret) >= 16),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE
);

CREATE TABLE webhook_deliveries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    webhook_id INTEGER NOT NULL,
    outbox_event_id INTEGER NOT NULL,
    delivery_id TEXT NOT NULL UNIQUE,
    event_topic TEXT NOT NULL,
    payload_json TEXT NOT NULL CHECK(json_valid(payload_json)),
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','processing','succeeded','failed')),
    attempts INTEGER NOT NULL DEFAULT 0,
    http_status INTEGER,
    response_body TEXT,
    last_error TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    next_attempt_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_attempt_at TEXT,
    delivered_at TEXT,
    UNIQUE(webhook_id, outbox_event_id),
    CHECK(delivered_at IS NULL OR status = 'succeeded'),
    CHECK(attempts >= 0),
    FOREIGN KEY(webhook_id) REFERENCES webhook_endpoints(id) ON DELETE CASCADE,
    FOREIGN KEY(outbox_event_id) REFERENCES outbox_events(id) ON DELETE CASCADE
);

CREATE TABLE outbox_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id TEXT NOT NULL UNIQUE,
    event_type TEXT NOT NULL,
    schema_version INTEGER NOT NULL DEFAULT 1 CHECK(schema_version >= 1),
    occurred_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    site_id INTEGER,
    correlation_id TEXT NOT NULL,
    causation_id TEXT,
    aggregate_type TEXT NOT NULL DEFAULT 'system',
    aggregate_id TEXT,
    topic TEXT NOT NULL,
    payload_json TEXT NOT NULL CHECK(json_valid(payload_json)),
    metadata_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(metadata_json)),
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','processing','processed','failed','dead_letter','archived')),
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 5 CHECK(max_attempts BETWEEN 1 AND 100),
    last_error TEXT,
    error_type TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    available_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_until TEXT,
    lock_token TEXT,
    claimed_at TEXT,
    processed_at TEXT,
    dead_lettered_at TEXT,
    archived_at TEXT,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(site_id IS NULL OR site_id > 0),
    CHECK(available_at >= created_at),
    CHECK(claimed_at IS NULL OR claimed_at >= created_at),
    CHECK(processed_at IS NULL OR processed_at >= created_at),
    CHECK(dead_lettered_at IS NULL OR status = 'dead_letter'),
    CHECK(archived_at IS NULL OR status = 'archived'),
    CHECK(processed_at IS NULL OR status IN ('processed','archived'))
);

CREATE TABLE outbox_consumptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id TEXT NOT NULL,
    consumer_key TEXT NOT NULL,
    processed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    result_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(result_json)),
    UNIQUE(event_id, consumer_key),
    FOREIGN KEY(event_id) REFERENCES outbox_events(event_id) ON DELETE CASCADE
);

CREATE TABLE system_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    job_key TEXT NOT NULL UNIQUE,
    last_run_at TEXT,
    last_heartbeat_at TEXT,
    last_status TEXT,
    last_message TEXT,
    locked_until TEXT,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);


-- Runtime hardening indexes
CREATE INDEX IF NOT EXISTS idx_routes_site_language_path ON routes(site_id, language_code, full_path);
CREATE INDEX IF NOT EXISTS idx_routes_active_path ON routes(site_id, language_code, full_path) WHERE status = 'active';
CREATE INDEX IF NOT EXISTS idx_routes_runtime_active ON routes(site_id, language_code, full_path, status);
CREATE INDEX IF NOT EXISTS idx_routes_sitemap_canonical ON routes(site_id, language_code, status, is_canonical, full_path);
CREATE INDEX IF NOT EXISTS idx_routes_resource_alternates ON routes(site_id, resource_type, resource_id, status, is_canonical, language_code);
CREATE INDEX IF NOT EXISTS idx_search_documents_runtime_lang ON search_documents(site_id, language_code, updated_at DESC);
CREATE INDEX IF NOT EXISTS idx_search_documents_path ON search_documents(site_id, language_code, path);
CREATE INDEX IF NOT EXISTS idx_seo_metadata_runtime ON seo_metadata(site_id, resource_type, resource_id, language_code);
CREATE INDEX IF NOT EXISTS idx_publications_runtime ON content_entry_publications(entry_id, language_code, workflow_status, published_revision_id);
CREATE INDEX IF NOT EXISTS idx_revisions_resource_lang_status ON revisions(resource_type, resource_id, language_code, workflow_status, revision_number DESC);
CREATE UNIQUE INDEX IF NOT EXISTS idx_redirects_one_global_path ON redirects(site_id, old_path) WHERE language_code IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_tombstones_one_global_path ON tombstones(site_id, old_path) WHERE language_code IS NULL;
CREATE INDEX IF NOT EXISTS idx_redirects_lookup ON redirects(site_id, old_path, is_active, language_code, id DESC);
CREATE INDEX IF NOT EXISTS idx_tombstones_lookup ON tombstones(site_id, old_path, is_active, language_code, id DESC);
CREATE INDEX IF NOT EXISTS idx_redirects_active_old_path ON redirects(site_id, language_code, old_path) WHERE is_active = 1;
CREATE INDEX IF NOT EXISTS idx_tombstones_active_old_path ON tombstones(site_id, language_code, old_path) WHERE is_active = 1;
CREATE INDEX IF NOT EXISTS idx_outbox_status_available ON outbox_events(status, available_at, id);
CREATE INDEX IF NOT EXISTS idx_outbox_lock ON outbox_events(status, locked_until, id);
CREATE INDEX IF NOT EXISTS idx_outbox_event_id ON outbox_events(event_id);
CREATE INDEX IF NOT EXISTS idx_outbox_correlation ON outbox_events(correlation_id);
CREATE INDEX IF NOT EXISTS idx_outbox_site_status ON outbox_events(site_id, status, available_at);
CREATE INDEX IF NOT EXISTS idx_outbox_consumptions_event ON outbox_consumptions(event_id);

CREATE INDEX IF NOT EXISTS idx_webhook_endpoints_site_active ON webhook_endpoints(site_id, is_active);
CREATE INDEX IF NOT EXISTS idx_webhook_deliveries_due ON webhook_deliveries(status, next_attempt_at, id);
CREATE INDEX IF NOT EXISTS idx_webhook_deliveries_event ON webhook_deliveries(outbox_event_id, webhook_id);
CREATE INDEX IF NOT EXISTS idx_webhook_deliveries_webhook_created ON webhook_deliveries(webhook_id, created_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS idx_webhook_deliveries_cleanup ON webhook_deliveries(status, created_at, webhook_id);


-- Polymorphic resource integrity guards --------------------------------------
-- SQLite cannot express a native foreign key from (resource_type, resource_id)
-- to several possible parent tables. These triggers make the polymorphic
-- contract explicit and fail fast when a route/projection/audit/usage record
-- points to a resource that does not exist or belongs to another site.
-- Supported resource types are intentionally narrow:
--   content_entry  -> content_entries(id), same site
--   taxonomy_term  -> taxonomy_terms(id) through taxonomies.site_id, same site
--   system         -> resource_id = 0, site-scoped virtual resource


-- Public path exclusivity guards ---------------------------------------------
-- At runtime, a public path must have one active meaning only for the same
-- site and language: either a route, a redirect or a tombstone. Table-level
-- UNIQUE constraints protect each table internally; these cross-table triggers
-- prevent ambiguous resolution across the three public path registries.

CREATE TRIGGER IF NOT EXISTS trg_routes_public_path_exclusivity_insert
BEFORE INSERT ON routes
FOR EACH ROW
WHEN NEW.status = 'active'
BEGIN
    SELECT RAISE(ABORT, 'routes.full_path conflicts with an active redirect old_path for the same site/language')
    WHERE EXISTS (SELECT 1 FROM redirects rd WHERE rd.site_id = NEW.site_id AND rd.language_code = NEW.language_code AND rd.old_path = NEW.full_path AND rd.is_active = 1);
    SELECT RAISE(ABORT, 'routes.full_path conflicts with an active tombstone old_path for the same site/language')
    WHERE EXISTS (SELECT 1 FROM tombstones ts WHERE ts.site_id = NEW.site_id AND ts.language_code = NEW.language_code AND ts.old_path = NEW.full_path AND ts.is_active = 1);
END;

CREATE TRIGGER IF NOT EXISTS trg_routes_public_path_exclusivity_update
BEFORE UPDATE OF site_id, language_code, full_path, status ON routes
FOR EACH ROW
WHEN NEW.status = 'active'
BEGIN
    SELECT RAISE(ABORT, 'routes.full_path conflicts with an active redirect old_path for the same site/language')
    WHERE EXISTS (SELECT 1 FROM redirects rd WHERE rd.site_id = NEW.site_id AND rd.language_code = NEW.language_code AND rd.old_path = NEW.full_path AND rd.is_active = 1);
    SELECT RAISE(ABORT, 'routes.full_path conflicts with an active tombstone old_path for the same site/language')
    WHERE EXISTS (SELECT 1 FROM tombstones ts WHERE ts.site_id = NEW.site_id AND ts.language_code = NEW.language_code AND ts.old_path = NEW.full_path AND ts.is_active = 1);
END;

CREATE TRIGGER IF NOT EXISTS trg_redirects_public_path_exclusivity_insert
BEFORE INSERT ON redirects
FOR EACH ROW
WHEN NEW.is_active = 1
BEGIN
    SELECT RAISE(ABORT, 'redirects.old_path conflicts with an active route full_path for the same site/language')
    WHERE NEW.language_code IS NOT NULL AND EXISTS (SELECT 1 FROM routes ro WHERE ro.site_id = NEW.site_id AND ro.language_code = NEW.language_code AND ro.full_path = NEW.old_path AND ro.status = 'active');
    SELECT RAISE(ABORT, 'redirects.old_path conflicts with an active tombstone old_path for the same site/language')
    WHERE EXISTS (SELECT 1 FROM tombstones ts WHERE ts.site_id = NEW.site_id AND ts.language_code IS NEW.language_code AND ts.old_path = NEW.old_path AND ts.is_active = 1);
END;

CREATE TRIGGER IF NOT EXISTS trg_redirects_public_path_exclusivity_update
BEFORE UPDATE OF site_id, language_code, old_path, is_active ON redirects
FOR EACH ROW
WHEN NEW.is_active = 1
BEGIN
    SELECT RAISE(ABORT, 'redirects.old_path conflicts with an active route full_path for the same site/language')
    WHERE NEW.language_code IS NOT NULL AND EXISTS (SELECT 1 FROM routes ro WHERE ro.site_id = NEW.site_id AND ro.language_code = NEW.language_code AND ro.full_path = NEW.old_path AND ro.status = 'active');
    SELECT RAISE(ABORT, 'redirects.old_path conflicts with an active tombstone old_path for the same site/language')
    WHERE EXISTS (SELECT 1 FROM tombstones ts WHERE ts.site_id = NEW.site_id AND ts.language_code IS NEW.language_code AND ts.old_path = NEW.old_path AND ts.is_active = 1);
END;

CREATE TRIGGER IF NOT EXISTS trg_tombstones_public_path_exclusivity_insert
BEFORE INSERT ON tombstones
FOR EACH ROW
WHEN NEW.is_active = 1
BEGIN
    SELECT RAISE(ABORT, 'tombstones.old_path conflicts with an active route full_path for the same site/language')
    WHERE NEW.language_code IS NOT NULL AND EXISTS (SELECT 1 FROM routes ro WHERE ro.site_id = NEW.site_id AND ro.language_code = NEW.language_code AND ro.full_path = NEW.old_path AND ro.status = 'active');
    SELECT RAISE(ABORT, 'tombstones.old_path conflicts with an active redirect old_path for the same site/language')
    WHERE EXISTS (SELECT 1 FROM redirects rd WHERE rd.site_id = NEW.site_id AND rd.language_code IS NEW.language_code AND rd.old_path = NEW.old_path AND rd.is_active = 1);
END;

CREATE TRIGGER IF NOT EXISTS trg_tombstones_public_path_exclusivity_update
BEFORE UPDATE OF site_id, language_code, old_path, is_active ON tombstones
FOR EACH ROW
WHEN NEW.is_active = 1
BEGIN
    SELECT RAISE(ABORT, 'tombstones.old_path conflicts with an active route full_path for the same site/language')
    WHERE NEW.language_code IS NOT NULL AND EXISTS (SELECT 1 FROM routes ro WHERE ro.site_id = NEW.site_id AND ro.language_code = NEW.language_code AND ro.full_path = NEW.old_path AND ro.status = 'active');
    SELECT RAISE(ABORT, 'tombstones.old_path conflicts with an active redirect old_path for the same site/language')
    WHERE EXISTS (SELECT 1 FROM redirects rd WHERE rd.site_id = NEW.site_id AND rd.language_code IS NEW.language_code AND rd.old_path = NEW.old_path AND rd.is_active = 1);
END;

CREATE TRIGGER IF NOT EXISTS trg_routes_resource_insert
BEFORE INSERT ON routes
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'routes.resource_type/resource_id invalid for site')
    WHERE NOT (
        (NEW.resource_type = 'content_entry' AND NEW.route_type = 'content' AND EXISTS (SELECT 1 FROM content_entries ce WHERE ce.id = NEW.resource_id AND ce.site_id = NEW.site_id))
        OR (NEW.resource_type = 'taxonomy_term' AND NEW.route_type = 'taxonomy' AND EXISTS (SELECT 1 FROM taxonomy_terms tt JOIN taxonomies tx ON tx.id = tt.taxonomy_id WHERE tt.id = NEW.resource_id AND tx.site_id = NEW.site_id))
        OR (NEW.resource_type = 'system' AND NEW.route_type = 'system' AND NEW.resource_id = 0)
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_routes_resource_update
BEFORE UPDATE OF site_id, resource_type, resource_id, route_type ON routes
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'routes.resource_type/resource_id invalid for site')
    WHERE NOT (
        (NEW.resource_type = 'content_entry' AND NEW.route_type = 'content' AND EXISTS (SELECT 1 FROM content_entries ce WHERE ce.id = NEW.resource_id AND ce.site_id = NEW.site_id))
        OR (NEW.resource_type = 'taxonomy_term' AND NEW.route_type = 'taxonomy' AND EXISTS (SELECT 1 FROM taxonomy_terms tt JOIN taxonomies tx ON tx.id = tt.taxonomy_id WHERE tt.id = NEW.resource_id AND tx.site_id = NEW.site_id))
        OR (NEW.resource_type = 'system' AND NEW.route_type = 'system' AND NEW.resource_id = 0)
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_seo_metadata_resource_insert
BEFORE INSERT ON seo_metadata
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'seo_metadata.resource_type/resource_id invalid for site')
    WHERE NOT (
        (NEW.resource_type = 'content_entry' AND EXISTS (SELECT 1 FROM content_entries ce WHERE ce.id = NEW.resource_id AND ce.site_id = NEW.site_id))
        OR (NEW.resource_type = 'taxonomy_term' AND EXISTS (SELECT 1 FROM taxonomy_terms tt JOIN taxonomies tx ON tx.id = tt.taxonomy_id WHERE tt.id = NEW.resource_id AND tx.site_id = NEW.site_id))
        OR (NEW.resource_type = 'system' AND NEW.resource_id = 0)
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_seo_metadata_resource_update
BEFORE UPDATE OF site_id, resource_type, resource_id ON seo_metadata
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'seo_metadata.resource_type/resource_id invalid for site')
    WHERE NOT (
        (NEW.resource_type = 'content_entry' AND EXISTS (SELECT 1 FROM content_entries ce WHERE ce.id = NEW.resource_id AND ce.site_id = NEW.site_id))
        OR (NEW.resource_type = 'taxonomy_term' AND EXISTS (SELECT 1 FROM taxonomy_terms tt JOIN taxonomies tx ON tx.id = tt.taxonomy_id WHERE tt.id = NEW.resource_id AND tx.site_id = NEW.site_id))
        OR (NEW.resource_type = 'system' AND NEW.resource_id = 0)
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_search_documents_resource_insert
BEFORE INSERT ON search_documents
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'search_documents.resource_type/resource_id invalid for site')
    WHERE NOT (
        (NEW.resource_type = 'content_entry' AND EXISTS (SELECT 1 FROM content_entries ce WHERE ce.id = NEW.resource_id AND ce.site_id = NEW.site_id))
        OR (NEW.resource_type = 'taxonomy_term' AND EXISTS (SELECT 1 FROM taxonomy_terms tt JOIN taxonomies tx ON tx.id = tt.taxonomy_id WHERE tt.id = NEW.resource_id AND tx.site_id = NEW.site_id))
        OR (NEW.resource_type = 'system' AND NEW.resource_id = 0)
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_search_documents_resource_update
BEFORE UPDATE OF site_id, resource_type, resource_id ON search_documents
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'search_documents.resource_type/resource_id invalid for site')
    WHERE NOT (
        (NEW.resource_type = 'content_entry' AND EXISTS (SELECT 1 FROM content_entries ce WHERE ce.id = NEW.resource_id AND ce.site_id = NEW.site_id))
        OR (NEW.resource_type = 'taxonomy_term' AND EXISTS (SELECT 1 FROM taxonomy_terms tt JOIN taxonomies tx ON tx.id = tt.taxonomy_id WHERE tt.id = NEW.resource_id AND tx.site_id = NEW.site_id))
        OR (NEW.resource_type = 'system' AND NEW.resource_id = 0)
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_media_usages_resource_insert
BEFORE INSERT ON media_usages
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'media_usages.media_id must reference a media asset from the same site')
    WHERE NOT EXISTS (SELECT 1 FROM media_assets ma WHERE ma.id = NEW.media_id AND ma.site_id = NEW.site_id);
    SELECT RAISE(ABORT, 'media_usages.resource_type/resource_id invalid for site')
    WHERE NOT (
        (NEW.resource_type = 'content_entry' AND EXISTS (SELECT 1 FROM content_entries ce WHERE ce.id = NEW.resource_id AND ce.site_id = NEW.site_id))
        OR (NEW.resource_type = 'taxonomy_term' AND EXISTS (SELECT 1 FROM taxonomy_terms tt JOIN taxonomies tx ON tx.id = tt.taxonomy_id WHERE tt.id = NEW.resource_id AND tx.site_id = NEW.site_id))
        OR (NEW.resource_type = 'system' AND NEW.resource_id = 0)
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_media_usages_resource_update
BEFORE UPDATE OF media_id, site_id, resource_type, resource_id ON media_usages
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'media_usages.media_id must reference a media asset from the same site')
    WHERE NOT EXISTS (SELECT 1 FROM media_assets ma WHERE ma.id = NEW.media_id AND ma.site_id = NEW.site_id);
    SELECT RAISE(ABORT, 'media_usages.resource_type/resource_id invalid for site')
    WHERE NOT (
        (NEW.resource_type = 'content_entry' AND EXISTS (SELECT 1 FROM content_entries ce WHERE ce.id = NEW.resource_id AND ce.site_id = NEW.site_id))
        OR (NEW.resource_type = 'taxonomy_term' AND EXISTS (SELECT 1 FROM taxonomy_terms tt JOIN taxonomies tx ON tx.id = tt.taxonomy_id WHERE tt.id = NEW.resource_id AND tx.site_id = NEW.site_id))
        OR (NEW.resource_type = 'system' AND NEW.resource_id = 0)
    );
END;


CREATE TRIGGER IF NOT EXISTS trg_media_usages_language_insert
BEFORE INSERT ON media_usages
FOR EACH ROW
WHEN NEW.language_code IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'media_usages.language_code is not declared for this site')
    WHERE NOT EXISTS (SELECT 1 FROM site_languages sl WHERE sl.site_id = NEW.site_id AND sl.language_code = NEW.language_code AND sl.is_active = 1);
END;

CREATE TRIGGER IF NOT EXISTS trg_media_usages_language_update
BEFORE UPDATE OF site_id, language_code ON media_usages
FOR EACH ROW
WHEN NEW.language_code IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'media_usages.language_code is not declared for this site')
    WHERE NOT EXISTS (SELECT 1 FROM site_languages sl WHERE sl.site_id = NEW.site_id AND sl.language_code = NEW.language_code AND sl.is_active = 1);
END;


CREATE TRIGGER IF NOT EXISTS trg_redirects_resource_insert
BEFORE INSERT ON redirects
FOR EACH ROW
WHEN NEW.resource_type IS NOT NULL OR NEW.resource_id IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'redirects.resource_type and resource_id must be both NULL or both set') WHERE (NEW.resource_type IS NULL) <> (NEW.resource_id IS NULL);
    SELECT RAISE(ABORT, 'redirects.resource_type/resource_id invalid for site')
    WHERE NOT ((NEW.resource_type = 'content_entry' AND EXISTS (SELECT 1 FROM content_entries ce WHERE ce.id = NEW.resource_id AND ce.site_id = NEW.site_id)) OR (NEW.resource_type = 'taxonomy_term' AND EXISTS (SELECT 1 FROM taxonomy_terms tt JOIN taxonomies tx ON tx.id = tt.taxonomy_id WHERE tt.id = NEW.resource_id AND tx.site_id = NEW.site_id)) OR (NEW.resource_type = 'system' AND NEW.resource_id = 0));
END;

CREATE TRIGGER IF NOT EXISTS trg_redirects_resource_update
BEFORE UPDATE OF site_id, resource_type, resource_id ON redirects
FOR EACH ROW
WHEN NEW.resource_type IS NOT NULL OR NEW.resource_id IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'redirects.resource_type and resource_id must be both NULL or both set') WHERE (NEW.resource_type IS NULL) <> (NEW.resource_id IS NULL);
    SELECT RAISE(ABORT, 'redirects.resource_type/resource_id invalid for site')
    WHERE NOT ((NEW.resource_type = 'content_entry' AND EXISTS (SELECT 1 FROM content_entries ce WHERE ce.id = NEW.resource_id AND ce.site_id = NEW.site_id)) OR (NEW.resource_type = 'taxonomy_term' AND EXISTS (SELECT 1 FROM taxonomy_terms tt JOIN taxonomies tx ON tx.id = tt.taxonomy_id WHERE tt.id = NEW.resource_id AND tx.site_id = NEW.site_id)) OR (NEW.resource_type = 'system' AND NEW.resource_id = 0));
END;

CREATE TRIGGER IF NOT EXISTS trg_tombstones_resource_insert
BEFORE INSERT ON tombstones
FOR EACH ROW
WHEN NEW.resource_type IS NOT NULL OR NEW.resource_id IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'tombstones.resource_type and resource_id must be both NULL or both set') WHERE (NEW.resource_type IS NULL) <> (NEW.resource_id IS NULL);
    SELECT RAISE(ABORT, 'tombstones.resource_type/resource_id invalid for site')
    WHERE NOT ((NEW.resource_type = 'content_entry' AND EXISTS (SELECT 1 FROM content_entries ce WHERE ce.id = NEW.resource_id AND ce.site_id = NEW.site_id)) OR (NEW.resource_type = 'taxonomy_term' AND EXISTS (SELECT 1 FROM taxonomy_terms tt JOIN taxonomies tx ON tx.id = tt.taxonomy_id WHERE tt.id = NEW.resource_id AND tx.site_id = NEW.site_id)) OR (NEW.resource_type = 'system' AND NEW.resource_id = 0));
END;

CREATE TRIGGER IF NOT EXISTS trg_tombstones_resource_update
BEFORE UPDATE OF site_id, resource_type, resource_id ON tombstones
FOR EACH ROW
WHEN NEW.resource_type IS NOT NULL OR NEW.resource_id IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'tombstones.resource_type and resource_id must be both NULL or both set') WHERE (NEW.resource_type IS NULL) <> (NEW.resource_id IS NULL);
    SELECT RAISE(ABORT, 'tombstones.resource_type/resource_id invalid for site')
    WHERE NOT ((NEW.resource_type = 'content_entry' AND EXISTS (SELECT 1 FROM content_entries ce WHERE ce.id = NEW.resource_id AND ce.site_id = NEW.site_id)) OR (NEW.resource_type = 'taxonomy_term' AND EXISTS (SELECT 1 FROM taxonomy_terms tt JOIN taxonomies tx ON tx.id = tt.taxonomy_id WHERE tt.id = NEW.resource_id AND tx.site_id = NEW.site_id)) OR (NEW.resource_type = 'system' AND NEW.resource_id = 0));
END;

CREATE TRIGGER IF NOT EXISTS trg_menu_items_resource_insert
BEFORE INSERT ON menu_items
FOR EACH ROW
WHEN NEW.resource_type IS NOT NULL OR NEW.resource_id IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'menu_items.resource_type and resource_id must be both NULL or both set') WHERE (NEW.resource_type IS NULL) <> (NEW.resource_id IS NULL);
    SELECT RAISE(ABORT, 'menu_items.resource_type/resource_id invalid for menu site')
    WHERE NOT ((NEW.resource_type = 'content_entry' AND EXISTS (SELECT 1 FROM menus m JOIN content_entries ce ON ce.site_id = m.site_id WHERE m.id = NEW.menu_id AND ce.id = NEW.resource_id)) OR (NEW.resource_type = 'taxonomy_term' AND EXISTS (SELECT 1 FROM menus m JOIN taxonomies tx ON tx.site_id = m.site_id JOIN taxonomy_terms tt ON tt.taxonomy_id = tx.id WHERE m.id = NEW.menu_id AND tt.id = NEW.resource_id)) OR (NEW.resource_type = 'system' AND NEW.resource_id = 0));
END;

CREATE TRIGGER IF NOT EXISTS trg_menu_items_resource_update
BEFORE UPDATE OF menu_id, resource_type, resource_id ON menu_items
FOR EACH ROW
WHEN NEW.resource_type IS NOT NULL OR NEW.resource_id IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'menu_items.resource_type and resource_id must be both NULL or both set') WHERE (NEW.resource_type IS NULL) <> (NEW.resource_id IS NULL);
    SELECT RAISE(ABORT, 'menu_items.resource_type/resource_id invalid for menu site')
    WHERE NOT ((NEW.resource_type = 'content_entry' AND EXISTS (SELECT 1 FROM menus m JOIN content_entries ce ON ce.site_id = m.site_id WHERE m.id = NEW.menu_id AND ce.id = NEW.resource_id)) OR (NEW.resource_type = 'taxonomy_term' AND EXISTS (SELECT 1 FROM menus m JOIN taxonomies tx ON tx.site_id = m.site_id JOIN taxonomy_terms tt ON tt.taxonomy_id = tx.id WHERE m.id = NEW.menu_id AND tt.id = NEW.resource_id)) OR (NEW.resource_type = 'system' AND NEW.resource_id = 0));
END;

CREATE TRIGGER IF NOT EXISTS trg_menu_items_term_site_insert
BEFORE INSERT ON menu_items
FOR EACH ROW
WHEN NEW.term_id IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'menu_items.term_id invalid for menu site')
    WHERE NOT EXISTS (SELECT 1 FROM menus m JOIN taxonomies tx ON tx.site_id = m.site_id JOIN taxonomy_terms tt ON tt.taxonomy_id = tx.id WHERE m.id = NEW.menu_id AND tt.id = NEW.term_id);
END;

CREATE TRIGGER IF NOT EXISTS trg_menu_items_term_site_update
BEFORE UPDATE OF menu_id, term_id ON menu_items
FOR EACH ROW
WHEN NEW.term_id IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'menu_items.term_id invalid for menu site')
    WHERE NOT EXISTS (SELECT 1 FROM menus m JOIN taxonomies tx ON tx.site_id = m.site_id JOIN taxonomy_terms tt ON tt.taxonomy_id = tx.id WHERE m.id = NEW.menu_id AND tt.id = NEW.term_id);
END;

CREATE TRIGGER IF NOT EXISTS trg_seo_audit_issues_resource_insert
BEFORE INSERT ON seo_audit_issues
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'seo_audit_issues.resource_type/resource_id invalid for site')
    WHERE NOT ((NEW.resource_type = 'content_entry' AND EXISTS (SELECT 1 FROM content_entries ce WHERE ce.id = NEW.resource_id AND ce.site_id = NEW.site_id)) OR (NEW.resource_type = 'taxonomy_term' AND EXISTS (SELECT 1 FROM taxonomy_terms tt JOIN taxonomies tx ON tx.id = tt.taxonomy_id WHERE tt.id = NEW.resource_id AND tx.site_id = NEW.site_id)) OR (NEW.resource_type = 'system' AND NEW.resource_id = 0));
END;

CREATE TRIGGER IF NOT EXISTS trg_seo_audit_issues_resource_update
BEFORE UPDATE OF site_id, resource_type, resource_id ON seo_audit_issues
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'seo_audit_issues.resource_type/resource_id invalid for site')
    WHERE NOT ((NEW.resource_type = 'content_entry' AND EXISTS (SELECT 1 FROM content_entries ce WHERE ce.id = NEW.resource_id AND ce.site_id = NEW.site_id)) OR (NEW.resource_type = 'taxonomy_term' AND EXISTS (SELECT 1 FROM taxonomy_terms tt JOIN taxonomies tx ON tx.id = tt.taxonomy_id WHERE tt.id = NEW.resource_id AND tx.site_id = NEW.site_id)) OR (NEW.resource_type = 'system' AND NEW.resource_id = 0));
END;

CREATE TRIGGER IF NOT EXISTS trg_content_entries_resource_delete_guard
BEFORE DELETE ON content_entries
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'cannot delete content entry while polymorphic resources still reference it')
    WHERE EXISTS (SELECT 1 FROM routes WHERE resource_type = 'content_entry' AND resource_id = OLD.id)
       OR EXISTS (SELECT 1 FROM seo_metadata WHERE resource_type = 'content_entry' AND resource_id = OLD.id)
       OR EXISTS (SELECT 1 FROM search_documents WHERE resource_type = 'content_entry' AND resource_id = OLD.id)
       OR EXISTS (SELECT 1 FROM public_content_snapshots WHERE resource_type = 'content_entry' AND resource_id = OLD.id)
       OR EXISTS (SELECT 1 FROM seo_audit_issues WHERE resource_type = 'content_entry' AND resource_id = OLD.id)
       OR EXISTS (SELECT 1 FROM media_usages WHERE resource_type = 'content_entry' AND resource_id = OLD.id)
       OR EXISTS (SELECT 1 FROM redirects WHERE resource_type = 'content_entry' AND resource_id = OLD.id)
       OR EXISTS (SELECT 1 FROM tombstones WHERE resource_type = 'content_entry' AND resource_id = OLD.id)
       OR EXISTS (SELECT 1 FROM menu_items WHERE resource_type = 'content_entry' AND resource_id = OLD.id);
END;

CREATE TRIGGER IF NOT EXISTS trg_taxonomy_terms_resource_delete_guard
BEFORE DELETE ON taxonomy_terms
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'cannot delete taxonomy term while polymorphic resources still reference it')
    WHERE EXISTS (SELECT 1 FROM routes WHERE resource_type = 'taxonomy_term' AND resource_id = OLD.id)
       OR EXISTS (SELECT 1 FROM seo_metadata WHERE resource_type = 'taxonomy_term' AND resource_id = OLD.id)
       OR EXISTS (SELECT 1 FROM search_documents WHERE resource_type = 'taxonomy_term' AND resource_id = OLD.id)
       OR EXISTS (SELECT 1 FROM seo_audit_issues WHERE resource_type = 'taxonomy_term' AND resource_id = OLD.id)
       OR EXISTS (SELECT 1 FROM media_usages WHERE resource_type = 'taxonomy_term' AND resource_id = OLD.id)
       OR EXISTS (SELECT 1 FROM redirects WHERE resource_type = 'taxonomy_term' AND resource_id = OLD.id)
       OR EXISTS (SELECT 1 FROM tombstones WHERE resource_type = 'taxonomy_term' AND resource_id = OLD.id)
       OR EXISTS (SELECT 1 FROM menu_items WHERE resource_type = 'taxonomy_term' AND resource_id = OLD.id)
       OR EXISTS (SELECT 1 FROM menu_items WHERE term_id = OLD.id);
END;

-- Revision integrity guards --------------------------------------------------
-- These triggers turn revision pointers into semantic foreign keys for the
-- content publication contract. They ensure that a pointer to revisions(id) also
-- points to the expected content entry, language and, for public projections,
-- to a revision that is actually published.

CREATE TRIGGER IF NOT EXISTS trg_content_entry_working_revisions_revision_insert
BEFORE INSERT ON content_entry_working_revisions
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'working_revision_id must reference a non-superseded content_entry revision for the same entry and language')
    WHERE NOT EXISTS (
        SELECT 1
        FROM revisions r
        WHERE r.id = NEW.working_revision_id
          AND r.resource_type = 'content_entry'
          AND r.resource_id = NEW.entry_id
          AND r.language_code = NEW.language_code
          AND r.workflow_status IN ('draft', 'published')
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_content_entry_working_revisions_revision_update
BEFORE UPDATE OF entry_id, language_code, working_revision_id ON content_entry_working_revisions
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'working_revision_id must reference a non-superseded content_entry revision for the same entry and language')
    WHERE NOT EXISTS (
        SELECT 1
        FROM revisions r
        WHERE r.id = NEW.working_revision_id
          AND r.resource_type = 'content_entry'
          AND r.resource_id = NEW.entry_id
          AND r.language_code = NEW.language_code
          AND r.workflow_status IN ('draft', 'published')
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_content_entry_publications_revision_insert
BEFORE INSERT ON content_entry_publications
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'published_revision_id must reference a content_entry revision for the same entry and language')
    WHERE NEW.published_revision_id IS NOT NULL
      AND NOT EXISTS (
        SELECT 1
        FROM revisions r
        WHERE r.id = NEW.published_revision_id
          AND r.resource_type = 'content_entry'
          AND r.resource_id = NEW.entry_id
          AND r.language_code = NEW.language_code
    );

    SELECT RAISE(ABORT, 'published content_entry publications must reference a published revision')
    WHERE NEW.workflow_status = 'published'
      AND NOT EXISTS (
        SELECT 1
        FROM revisions r
        WHERE r.id = NEW.published_revision_id
          AND r.resource_type = 'content_entry'
          AND r.resource_id = NEW.entry_id
          AND r.language_code = NEW.language_code
          AND r.workflow_status = 'published'
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_content_entry_publications_revision_update
BEFORE UPDATE OF entry_id, language_code, published_revision_id, workflow_status ON content_entry_publications
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'published_revision_id must reference a content_entry revision for the same entry and language')
    WHERE NEW.published_revision_id IS NOT NULL
      AND NOT EXISTS (
        SELECT 1
        FROM revisions r
        WHERE r.id = NEW.published_revision_id
          AND r.resource_type = 'content_entry'
          AND r.resource_id = NEW.entry_id
          AND r.language_code = NEW.language_code
    );

    SELECT RAISE(ABORT, 'published content_entry publications must reference a published revision')
    WHERE NEW.workflow_status = 'published'
      AND NOT EXISTS (
        SELECT 1
        FROM revisions r
        WHERE r.id = NEW.published_revision_id
          AND r.resource_type = 'content_entry'
          AND r.resource_id = NEW.entry_id
          AND r.language_code = NEW.language_code
          AND r.workflow_status = 'published'
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_routes_source_published_revision_insert
BEFORE INSERT ON routes
FOR EACH ROW
WHEN NEW.resource_type = 'content_entry'
BEGIN
    SELECT RAISE(ABORT, 'content_entry routes must reference a published revision for the same site, entry and language')
    WHERE NOT EXISTS (
        SELECT 1
        FROM revisions r
        JOIN content_entries ce ON ce.id = r.resource_id
        WHERE r.id = NEW.source_published_revision_id
          AND r.resource_type = 'content_entry'
          AND r.resource_id = NEW.resource_id
          AND r.language_code = NEW.language_code
          AND r.workflow_status = 'published'
          AND ce.site_id = NEW.site_id
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_routes_source_published_revision_update
BEFORE UPDATE OF site_id, language_code, resource_type, resource_id, source_published_revision_id ON routes
FOR EACH ROW
WHEN NEW.resource_type = 'content_entry'
BEGIN
    SELECT RAISE(ABORT, 'content_entry routes must reference a published revision for the same site, entry and language')
    WHERE NOT EXISTS (
        SELECT 1
        FROM revisions r
        JOIN content_entries ce ON ce.id = r.resource_id
        WHERE r.id = NEW.source_published_revision_id
          AND r.resource_type = 'content_entry'
          AND r.resource_id = NEW.resource_id
          AND r.language_code = NEW.language_code
          AND r.workflow_status = 'published'
          AND ce.site_id = NEW.site_id
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_seo_metadata_source_published_revision_insert
BEFORE INSERT ON seo_metadata
FOR EACH ROW
WHEN NEW.resource_type = 'content_entry'
BEGIN
    SELECT RAISE(ABORT, 'content_entry seo_metadata must reference a published revision for the same site, entry and language')
    WHERE NOT EXISTS (
        SELECT 1
        FROM revisions r
        JOIN content_entries ce ON ce.id = r.resource_id
        WHERE r.id = NEW.source_published_revision_id
          AND r.resource_type = 'content_entry'
          AND r.resource_id = NEW.resource_id
          AND r.language_code = NEW.language_code
          AND r.workflow_status = 'published'
          AND ce.site_id = NEW.site_id
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_seo_metadata_source_published_revision_update
BEFORE UPDATE OF site_id, resource_type, resource_id, language_code, source_published_revision_id ON seo_metadata
FOR EACH ROW
WHEN NEW.resource_type = 'content_entry'
BEGIN
    SELECT RAISE(ABORT, 'content_entry seo_metadata must reference a published revision for the same site, entry and language')
    WHERE NOT EXISTS (
        SELECT 1
        FROM revisions r
        JOIN content_entries ce ON ce.id = r.resource_id
        WHERE r.id = NEW.source_published_revision_id
          AND r.resource_type = 'content_entry'
          AND r.resource_id = NEW.resource_id
          AND r.language_code = NEW.language_code
          AND r.workflow_status = 'published'
          AND ce.site_id = NEW.site_id
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_search_documents_source_published_revision_insert
BEFORE INSERT ON search_documents
FOR EACH ROW
WHEN NEW.resource_type = 'content_entry'
BEGIN
    SELECT RAISE(ABORT, 'content_entry search_documents must reference a published revision for the same site, entry and language')
    WHERE NOT EXISTS (
        SELECT 1
        FROM revisions r
        JOIN content_entries ce ON ce.id = r.resource_id
        WHERE r.id = NEW.source_published_revision_id
          AND r.resource_type = 'content_entry'
          AND r.resource_id = NEW.resource_id
          AND r.language_code = NEW.language_code
          AND r.workflow_status = 'published'
          AND ce.site_id = NEW.site_id
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_search_documents_source_published_revision_update
BEFORE UPDATE OF site_id, resource_type, resource_id, language_code, source_published_revision_id ON search_documents
FOR EACH ROW
WHEN NEW.resource_type = 'content_entry'
BEGIN
    SELECT RAISE(ABORT, 'content_entry search_documents must reference a published revision for the same site, entry and language')
    WHERE NOT EXISTS (
        SELECT 1
        FROM revisions r
        JOIN content_entries ce ON ce.id = r.resource_id
        WHERE r.id = NEW.source_published_revision_id
          AND r.resource_type = 'content_entry'
          AND r.resource_id = NEW.resource_id
          AND r.language_code = NEW.language_code
          AND r.workflow_status = 'published'
          AND ce.site_id = NEW.site_id
    );
END;


CREATE TRIGGER IF NOT EXISTS trg_public_content_snapshots_source_published_revision_insert
BEFORE INSERT ON public_content_snapshots
FOR EACH ROW
WHEN NEW.resource_type = 'content_entry'
BEGIN
    SELECT RAISE(ABORT, 'public_content_snapshots must reference a published revision for the same site, entry and language')
    WHERE NOT EXISTS (
        SELECT 1
        FROM revisions r
        JOIN content_entries ce ON ce.id = r.resource_id
        WHERE r.id = NEW.source_published_revision_id
          AND r.resource_type = 'content_entry'
          AND r.resource_id = NEW.resource_id
          AND r.language_code = NEW.language_code
          AND r.workflow_status = 'published'
          AND r.checksum_sha256 = NEW.source_revision_checksum_sha256
          AND ce.site_id = NEW.site_id
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_public_content_snapshots_source_published_revision_update
BEFORE UPDATE OF site_id, resource_type, resource_id, language_code, source_published_revision_id, source_revision_checksum_sha256 ON public_content_snapshots
FOR EACH ROW
WHEN NEW.resource_type = 'content_entry'
BEGIN
    SELECT RAISE(ABORT, 'public_content_snapshots must reference a published revision for the same site, entry and language')
    WHERE NOT EXISTS (
        SELECT 1
        FROM revisions r
        JOIN content_entries ce ON ce.id = r.resource_id
        WHERE r.id = NEW.source_published_revision_id
          AND r.resource_type = 'content_entry'
          AND r.resource_id = NEW.resource_id
          AND r.language_code = NEW.language_code
          AND r.workflow_status = 'published'
          AND r.checksum_sha256 = NEW.source_revision_checksum_sha256
          AND ce.site_id = NEW.site_id
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_content_entry_field_values_source_revision_insert
BEFORE INSERT ON content_entry_field_values
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'source_revision_id must reference a content_entry revision for the same entry and language')
    WHERE NOT EXISTS (
        SELECT 1
        FROM revisions r
        WHERE r.id = NEW.source_revision_id
          AND r.resource_type = 'content_entry'
          AND r.resource_id = NEW.entry_id
          AND r.language_code = NEW.language_code
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_content_entry_field_values_source_revision_update
BEFORE UPDATE OF entry_id, language_code, source_revision_id ON content_entry_field_values
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'source_revision_id must reference a content_entry revision for the same entry and language')
    WHERE NOT EXISTS (
        SELECT 1
        FROM revisions r
        WHERE r.id = NEW.source_revision_id
          AND r.resource_type = 'content_entry'
          AND r.resource_id = NEW.entry_id
          AND r.language_code = NEW.language_code
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_revisions_referenced_content_contract_update
BEFORE UPDATE OF resource_type, resource_id, language_code, workflow_status ON revisions
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'cannot alter a revision so that an existing working revision pointer becomes invalid')
    WHERE EXISTS (
        SELECT 1
        FROM content_entry_working_revisions w
        WHERE w.working_revision_id = NEW.id
          AND NOT (
            NEW.resource_type = 'content_entry'
            AND NEW.resource_id = w.entry_id
            AND NEW.language_code = w.language_code
            AND NEW.workflow_status IN ('draft', 'published')
          )
    );

    SELECT RAISE(ABORT, 'cannot alter a revision so that an existing publication pointer becomes invalid')
    WHERE EXISTS (
        SELECT 1
        FROM content_entry_publications p
        WHERE p.published_revision_id = NEW.id
          AND p.workflow_status = 'published'
          AND NOT (
            NEW.resource_type = 'content_entry'
            AND NEW.resource_id = p.entry_id
            AND NEW.language_code = p.language_code
            AND NEW.workflow_status = 'published'
          )
    );

    SELECT RAISE(ABORT, 'cannot alter a revision so that an existing route projection becomes invalid')
    WHERE EXISTS (
        SELECT 1
        FROM routes ro
        JOIN content_entries ce ON ce.id = ro.resource_id
        WHERE ro.source_published_revision_id = NEW.id
          AND ro.resource_type = 'content_entry'
          AND NOT (
            NEW.resource_type = 'content_entry'
            AND NEW.resource_id = ro.resource_id
            AND NEW.language_code = ro.language_code
            AND NEW.workflow_status = 'published'
            AND ce.site_id = ro.site_id
          )
    );

    SELECT RAISE(ABORT, 'cannot alter a revision so that existing SEO metadata becomes invalid')
    WHERE EXISTS (
        SELECT 1
        FROM seo_metadata sm
        JOIN content_entries ce ON ce.id = sm.resource_id
        WHERE sm.source_published_revision_id = NEW.id
          AND sm.resource_type = 'content_entry'
          AND NOT (
            NEW.resource_type = 'content_entry'
            AND NEW.resource_id = sm.resource_id
            AND NEW.language_code = sm.language_code
            AND NEW.workflow_status = 'published'
            AND ce.site_id = sm.site_id
          )
    );

    SELECT RAISE(ABORT, 'cannot alter a revision so that an existing search document becomes invalid')
    WHERE EXISTS (
        SELECT 1
        FROM search_documents sd
        JOIN content_entries ce ON ce.id = sd.resource_id
        WHERE sd.source_published_revision_id = NEW.id
          AND sd.resource_type = 'content_entry'
          AND NOT (
            NEW.resource_type = 'content_entry'
            AND NEW.resource_id = sd.resource_id
            AND NEW.language_code = sd.language_code
            AND NEW.workflow_status = 'published'
            AND ce.site_id = sd.site_id
          )
    );

    SELECT RAISE(ABORT, 'cannot alter a revision so that existing field value projections become invalid')
    WHERE EXISTS (
        SELECT 1
        FROM content_entry_field_values fv
        WHERE fv.source_revision_id = NEW.id
          AND NOT (
            NEW.resource_type = 'content_entry'
            AND NEW.resource_id = fv.entry_id
            AND NEW.language_code = fv.language_code
          )
    );
END;

-- Additional priority SQL hardening for mod2_v01-e06w -----------------------
-- These guards complete the sober SQLite contract around site/language scope,
-- public path exclusivity, taxonomies and taxonomy assignments.

-- Localized global variables: language must belong to the variable site.
CREATE TRIGGER IF NOT EXISTS trg_global_variable_localizations_site_language_insert
BEFORE INSERT ON global_variable_localizations
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'language_code is not declared for this global variable site')
    WHERE NOT EXISTS (
        SELECT 1
        FROM global_variables gv
        JOIN site_languages sl
          ON sl.site_id = gv.site_id
         AND sl.language_code = NEW.language_code
        WHERE gv.id = NEW.variable_id
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_global_variable_localizations_site_language_update
BEFORE UPDATE OF variable_id, language_code ON global_variable_localizations
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'language_code is not declared for this global variable site')
    WHERE NOT EXISTS (
        SELECT 1
        FROM global_variables gv
        JOIN site_languages sl
          ON sl.site_id = gv.site_id
         AND sl.language_code = NEW.language_code
        WHERE gv.id = NEW.variable_id
    );
END;

-- Unique field values: empty language_code is reserved for non-localized fields;
-- any non-empty language_code must belong to the entry site.
CREATE TRIGGER IF NOT EXISTS trg_content_field_unique_values_site_language_insert
BEFORE INSERT ON content_field_unique_values
FOR EACH ROW
WHEN NEW.language_code <> ''
BEGIN
    SELECT RAISE(ABORT, 'language_code is not declared for this unique field value site')
    WHERE NOT EXISTS (
        SELECT 1
        FROM site_languages sl
        WHERE sl.site_id = NEW.site_id
          AND sl.language_code = NEW.language_code
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_content_field_unique_values_site_language_update
BEFORE UPDATE OF site_id, language_code ON content_field_unique_values
FOR EACH ROW
WHEN NEW.language_code <> ''
BEGIN
    SELECT RAISE(ABORT, 'language_code is not declared for this unique field value site')
    WHERE NOT EXISTS (
        SELECT 1
        FROM site_languages sl
        WHERE sl.site_id = NEW.site_id
          AND sl.language_code = NEW.language_code
    );
END;

-- Prevent taxonomy parent inconsistencies and cycles.
CREATE TRIGGER IF NOT EXISTS trg_taxonomy_terms_parent_integrity_insert
BEFORE INSERT ON taxonomy_terms
FOR EACH ROW
WHEN NEW.parent_id IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'taxonomy term parent must belong to the same taxonomy')
    WHERE NOT EXISTS (
        SELECT 1
        FROM taxonomy_terms parent
        WHERE parent.id = NEW.parent_id
          AND parent.taxonomy_id = NEW.taxonomy_id
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_taxonomy_terms_parent_integrity_update
BEFORE UPDATE OF taxonomy_id, parent_id ON taxonomy_terms
FOR EACH ROW
WHEN NEW.parent_id IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'taxonomy term cannot be its own parent')
    WHERE NEW.parent_id = NEW.id;

    SELECT RAISE(ABORT, 'taxonomy term parent must belong to the same taxonomy')
    WHERE NOT EXISTS (
        SELECT 1
        FROM taxonomy_terms parent
        WHERE parent.id = NEW.parent_id
          AND parent.taxonomy_id = NEW.taxonomy_id
    );

    SELECT RAISE(ABORT, 'taxonomy term parent would create a cycle')
    WHERE EXISTS (
        WITH RECURSIVE descendants(id) AS (
            SELECT id FROM taxonomy_terms WHERE parent_id = OLD.id
            UNION ALL
            SELECT tt.id
            FROM taxonomy_terms tt
            JOIN descendants d ON tt.parent_id = d.id
        )
        SELECT 1 FROM descendants WHERE id = NEW.parent_id
    );
END;

-- Assigned taxonomy terms must be allowed for the entry content type and site.
CREATE TRIGGER IF NOT EXISTS trg_content_entry_taxonomy_terms_allowed_insert
BEFORE INSERT ON content_entry_taxonomy_terms
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'taxonomy term is not allowed for this entry content type or site')
    WHERE NOT EXISTS (
        SELECT 1
        FROM content_entries ce
        JOIN taxonomy_terms tt ON tt.id = NEW.term_id
        JOIN taxonomies tx ON tx.id = tt.taxonomy_id AND tx.site_id = ce.site_id
        JOIN content_type_taxonomies ctt
          ON ctt.content_type_id = ce.content_type_id
         AND ctt.taxonomy_id = tx.id
        WHERE ce.id = NEW.entry_id
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_content_entry_taxonomy_terms_allowed_update
BEFORE UPDATE OF entry_id, term_id ON content_entry_taxonomy_terms
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'taxonomy term is not allowed for this entry content type or site')
    WHERE NOT EXISTS (
        SELECT 1
        FROM content_entries ce
        JOIN taxonomy_terms tt ON tt.id = NEW.term_id
        JOIN taxonomies tx ON tx.id = tt.taxonomy_id AND tx.site_id = ce.site_id
        JOIN content_type_taxonomies ctt
          ON ctt.content_type_id = ce.content_type_id
         AND ctt.taxonomy_id = tx.id
        WHERE ce.id = NEW.entry_id
    );
END;

-- Global redirects/tombstones (language_code IS NULL) must not coexist with a
-- localized active route for the same site/path, and vice versa.
CREATE TRIGGER IF NOT EXISTS trg_routes_public_path_global_exclusivity_insert
BEFORE INSERT ON routes
FOR EACH ROW
WHEN NEW.status = 'active'
BEGIN
    SELECT RAISE(ABORT, 'routes.full_path conflicts with an active global redirect old_path for the same site')
    WHERE EXISTS (
        SELECT 1 FROM redirects rd
        WHERE rd.site_id = NEW.site_id
          AND rd.language_code IS NULL
          AND rd.old_path = NEW.full_path
          AND rd.is_active = 1
    );
    SELECT RAISE(ABORT, 'routes.full_path conflicts with an active global tombstone old_path for the same site')
    WHERE EXISTS (
        SELECT 1 FROM tombstones ts
        WHERE ts.site_id = NEW.site_id
          AND ts.language_code IS NULL
          AND ts.old_path = NEW.full_path
          AND ts.is_active = 1
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_routes_public_path_global_exclusivity_update
BEFORE UPDATE OF site_id, full_path, status ON routes
FOR EACH ROW
WHEN NEW.status = 'active'
BEGIN
    SELECT RAISE(ABORT, 'routes.full_path conflicts with an active global redirect old_path for the same site')
    WHERE EXISTS (
        SELECT 1 FROM redirects rd
        WHERE rd.site_id = NEW.site_id
          AND rd.language_code IS NULL
          AND rd.old_path = NEW.full_path
          AND rd.is_active = 1
    );
    SELECT RAISE(ABORT, 'routes.full_path conflicts with an active global tombstone old_path for the same site')
    WHERE EXISTS (
        SELECT 1 FROM tombstones ts
        WHERE ts.site_id = NEW.site_id
          AND ts.language_code IS NULL
          AND ts.old_path = NEW.full_path
          AND ts.is_active = 1
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_redirects_global_public_path_exclusivity_insert
BEFORE INSERT ON redirects
FOR EACH ROW
WHEN NEW.is_active = 1 AND NEW.language_code IS NULL
BEGIN
    SELECT RAISE(ABORT, 'global redirects.old_path conflicts with an active route full_path for the same site')
    WHERE EXISTS (
        SELECT 1 FROM routes ro
        WHERE ro.site_id = NEW.site_id
          AND ro.full_path = NEW.old_path
          AND ro.status = 'active'
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_redirects_global_public_path_exclusivity_update
BEFORE UPDATE OF site_id, language_code, old_path, is_active ON redirects
FOR EACH ROW
WHEN NEW.is_active = 1 AND NEW.language_code IS NULL
BEGIN
    SELECT RAISE(ABORT, 'global redirects.old_path conflicts with an active route full_path for the same site')
    WHERE EXISTS (
        SELECT 1 FROM routes ro
        WHERE ro.site_id = NEW.site_id
          AND ro.full_path = NEW.old_path
          AND ro.status = 'active'
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_tombstones_global_public_path_exclusivity_insert
BEFORE INSERT ON tombstones
FOR EACH ROW
WHEN NEW.is_active = 1 AND NEW.language_code IS NULL
BEGIN
    SELECT RAISE(ABORT, 'global tombstones.old_path conflicts with an active route full_path for the same site')
    WHERE EXISTS (
        SELECT 1 FROM routes ro
        WHERE ro.site_id = NEW.site_id
          AND ro.full_path = NEW.old_path
          AND ro.status = 'active'
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_tombstones_global_public_path_exclusivity_update
BEFORE UPDATE OF site_id, language_code, old_path, is_active ON tombstones
FOR EACH ROW
WHEN NEW.is_active = 1 AND NEW.language_code IS NULL
BEGIN
    SELECT RAISE(ABORT, 'global tombstones.old_path conflicts with an active route full_path for the same site')
    WHERE EXISTS (
        SELECT 1 FROM routes ro
        WHERE ro.site_id = NEW.site_id
          AND ro.full_path = NEW.old_path
          AND ro.status = 'active'
    );
END;


CREATE INDEX IF NOT EXISTS idx_public_content_snapshots_runtime ON public_content_snapshots(site_id, language_code, route_path);
CREATE INDEX IF NOT EXISTS idx_public_content_snapshots_source_revision ON public_content_snapshots(source_published_revision_id);

-- CMS/Product links live in core because content is local and products belong
-- to business.sqlite. product_id is intentionally an application-level
-- reference; public rendering consumes only the projected JSON below.
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
CREATE UNIQUE INDEX IF NOT EXISTS idx_business_product_content_links_identity ON business_product_content_links(site_id, product_id, content_entry_id, relation_type, COALESCE(locale, ''));
CREATE UNIQUE INDEX IF NOT EXISTS idx_business_product_content_links_canonical ON business_product_content_links(site_id, product_id, COALESCE(locale, '')) WHERE is_canonical = 1 AND status = 'active';
CREATE INDEX IF NOT EXISTS idx_business_product_content_links_content ON business_product_content_links(site_id, content_entry_id, locale, status);

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
CREATE INDEX IF NOT EXISTS idx_business_product_public_projections_content ON business_product_public_projections(site_id, content_entry_id, locale, is_active);

CREATE TRIGGER IF NOT EXISTS trg_business_product_content_links_site_insert BEFORE INSERT ON business_product_content_links BEGIN
    SELECT RAISE(ABORT, 'content entry must belong to product link site') WHERE NOT EXISTS (SELECT 1 FROM content_entries ce WHERE ce.id=NEW.content_entry_id AND ce.site_id=NEW.site_id);
END;
CREATE TRIGGER IF NOT EXISTS trg_business_product_content_links_site_update BEFORE UPDATE OF site_id, content_entry_id ON business_product_content_links BEGIN
    SELECT RAISE(ABORT, 'content entry must belong to product link site') WHERE NOT EXISTS (SELECT 1 FROM content_entries ce WHERE ce.id=NEW.content_entry_id AND ce.site_id=NEW.site_id);
END;
CREATE TRIGGER IF NOT EXISTS trg_business_product_content_links_content_delete_guard BEFORE DELETE ON content_entries
WHEN EXISTS (SELECT 1 FROM business_product_content_links l WHERE l.content_entry_id=OLD.id) BEGIN
    SELECT RAISE(ABORT, 'remove product content links before deleting content entry');
END;

CREATE TABLE IF NOT EXISTS schema_migrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    migration TEXT NOT NULL UNIQUE,
    migrated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
