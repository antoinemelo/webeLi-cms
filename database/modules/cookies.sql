PRAGMA foreign_keys = ON;

CREATE TABLE cookie_banner_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    is_enabled INTEGER NOT NULL DEFAULT 1 CHECK (is_enabled IN (0,1)),
    consent_version TEXT NOT NULL DEFAULT '2026-05-09',
    cookie_name TEXT NOT NULL DEFAULT 'amcms_cookie_consent',
    consent_lifetime_days INTEGER NOT NULL DEFAULT 180 CHECK (consent_lifetime_days BETWEEN 1 AND 730),
    log_retention_days INTEGER NOT NULL DEFAULT 180 CHECK (log_retention_days BETWEEN 1 AND 1095),
    banner_position TEXT NOT NULL DEFAULT 'bottom' CHECK (banner_position IN ('bottom','top','modal')),
    theme TEXT NOT NULL DEFAULT 'auto' CHECK (theme IN ('auto','light','dark')),
    accent_color TEXT NOT NULL DEFAULT '',
    show_floating_button INTEGER NOT NULL DEFAULT 1 CHECK (show_floating_button IN (0,1)),
    reject_equal_prominence INTEGER NOT NULL DEFAULT 1 CHECK (reject_equal_prominence IN (0,1)),
    respect_dnt INTEGER NOT NULL DEFAULT 1 CHECK (respect_dnt IN (0,1)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(site_id > 0),
    UNIQUE(site_id)
);

CREATE TABLE cookie_banner_translations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    setting_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    banner_title TEXT NOT NULL DEFAULT '',
    banner_summary TEXT NOT NULL DEFAULT '',
    preferences_title TEXT NOT NULL DEFAULT '',
    preferences_summary TEXT NOT NULL DEFAULT '',
    accept_all_label TEXT NOT NULL DEFAULT '',
    reject_all_label TEXT NOT NULL DEFAULT '',
    customize_label TEXT NOT NULL DEFAULT '',
    save_choices_label TEXT NOT NULL DEFAULT '',
    manage_link_label TEXT NOT NULL DEFAULT '',
    legal_notice_html TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(setting_id) REFERENCES cookie_banner_settings(id) ON DELETE CASCADE,
    UNIQUE(setting_id, language_code),
    CHECK(language_code = lower(trim(language_code)))
);

CREATE TABLE cookie_categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    category_key TEXT NOT NULL,
    is_required INTEGER NOT NULL DEFAULT 0 CHECK (is_required IN (0,1)),
    is_enabled INTEGER NOT NULL DEFAULT 1 CHECK (is_enabled IN (0,1)),
    is_preselected INTEGER NOT NULL DEFAULT 0 CHECK (is_preselected IN (0,1)),
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(site_id > 0),
    CHECK(category_key = lower(trim(category_key)) AND category_key GLOB '[a-z0-9_]*'),
    UNIQUE(site_id, category_key)
);

CREATE TABLE cookie_category_translations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    name TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(category_id) REFERENCES cookie_categories(id) ON DELETE CASCADE,
    UNIQUE(category_id, language_code),
    CHECK(language_code = lower(trim(language_code)))
);

CREATE TABLE cookie_services (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    category_id INTEGER NOT NULL,
    service_key TEXT NOT NULL,
    provider_name TEXT NOT NULL DEFAULT '',
    service_type TEXT NOT NULL DEFAULT 'script' CHECK (service_type IN ('script','iframe','pixel','local_storage','server','other')),
    cookie_type TEXT NOT NULL DEFAULT 'http' CHECK (cookie_type IN ('http','html5','pixel','server','mixed','none')),
    domain TEXT NOT NULL DEFAULT '',
    duration TEXT NOT NULL DEFAULT '',
    privacy_url TEXT NOT NULL DEFAULT '',
    is_enabled INTEGER NOT NULL DEFAULT 1 CHECK (is_enabled IN (0,1)),
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(site_id > 0),
    CHECK(service_key = lower(trim(service_key)) AND service_key GLOB '[a-z0-9_]*'),
    FOREIGN KEY(category_id) REFERENCES cookie_categories(id) ON DELETE RESTRICT,
    UNIQUE(site_id, service_key)
);

CREATE TABLE cookie_service_translations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    service_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    name TEXT NOT NULL DEFAULT '',
    purpose TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    fallback_message TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(service_id) REFERENCES cookie_services(id) ON DELETE CASCADE,
    UNIQUE(service_id, language_code),
    CHECK(language_code = lower(trim(language_code)))
);

CREATE TABLE cookie_service_cookies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    service_id INTEGER NOT NULL,
    cookie_name TEXT NOT NULL,
    purpose TEXT NOT NULL DEFAULT '',
    duration TEXT NOT NULL DEFAULT '',
    domain TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(service_id) REFERENCES cookie_services(id) ON DELETE CASCADE
);

CREATE TABLE cookie_script_bindings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    service_id INTEGER NOT NULL,
    binding_key TEXT NOT NULL,
    location TEXT NOT NULL DEFAULT 'footer' CHECK (location IN ('head','footer','manual')),
    trigger_mode TEXT NOT NULL DEFAULT 'after_consent' CHECK (trigger_mode IN ('after_consent','manual')),
    script_kind TEXT NOT NULL DEFAULT 'external' CHECK (script_kind IN ('external','inline','html')),
    src_url TEXT NOT NULL DEFAULT '',
    inline_code TEXT NOT NULL DEFAULT '',
    attributes_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(attributes_json)),
    is_enabled INTEGER NOT NULL DEFAULT 1 CHECK (is_enabled IN (0,1)),
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(site_id > 0),
    CHECK(binding_key = lower(trim(binding_key)) AND binding_key GLOB '[a-z0-9_]*'),
    FOREIGN KEY(service_id) REFERENCES cookie_services(id) ON DELETE CASCADE,
    UNIQUE(site_id, binding_key)
);

CREATE TABLE cookie_consent_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    consent_uid TEXT NOT NULL,
    consent_version TEXT NOT NULL,
    language_code TEXT NOT NULL,
    action TEXT NOT NULL CHECK (action IN ('accept_all','reject_all','save_choices','revoke','update')),
    choices_json TEXT NOT NULL CHECK(json_valid(choices_json)),
    ip_hash TEXT NOT NULL DEFAULT '',
    user_agent_hash TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(site_id > 0),
    CHECK(language_code = lower(trim(language_code)))
);

CREATE INDEX idx_cookie_categories_site_sort ON cookie_categories(site_id, sort_order, id);
CREATE INDEX idx_cookie_services_site_category ON cookie_services(site_id, category_id, sort_order, id);
CREATE INDEX idx_cookie_script_bindings_site_service ON cookie_script_bindings(site_id, service_id, sort_order, id);
CREATE INDEX idx_cookie_consent_logs_site_created ON cookie_consent_logs(site_id, created_at DESC);
CREATE INDEX idx_cookie_consent_logs_uid ON cookie_consent_logs(consent_uid, created_at DESC);
