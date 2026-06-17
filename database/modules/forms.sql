PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS forms (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    form_key TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','published','archived')),
    is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0,1)),
    store_submissions INTEGER NOT NULL DEFAULT 1 CHECK(store_submissions IN (0,1)),
    notification_enabled INTEGER NOT NULL DEFAULT 0 CHECK(notification_enabled IN (0,1)),
    notification_recipients_json TEXT NOT NULL DEFAULT '[]' CHECK(json_valid(notification_recipients_json)),
    notification_subject TEXT,
    honeypot_field TEXT NOT NULL DEFAULT 'website',
    min_submit_seconds INTEGER NOT NULL DEFAULT 2,
    rate_limit_max_attempts INTEGER NOT NULL DEFAULT 5,
    rate_limit_window_seconds INTEGER NOT NULL DEFAULT 900,
    settings_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(settings_json)),
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(site_id, form_key),
    CHECK(form_key = lower(trim(form_key)) AND form_key GLOB '[a-z0-9_-]*')
);

CREATE TABLE IF NOT EXISTS form_translations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    form_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    name TEXT NOT NULL,
    description_text TEXT,
    submit_label TEXT NOT NULL DEFAULT 'Envoyer',
    success_message TEXT NOT NULL DEFAULT 'Merci, votre message a été envoyé.',
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(form_id, language_code),
    FOREIGN KEY(form_id) REFERENCES forms(id) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS form_fields (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    form_id INTEGER NOT NULL,
    field_key TEXT NOT NULL,
    field_type TEXT NOT NULL CHECK(field_type IN ('text','textarea','email','tel','url','number','select','radio','checkbox','checkboxes','hidden','date','consent')),
    sort_order INTEGER NOT NULL DEFAULT 0,
    is_required INTEGER NOT NULL DEFAULT 0 CHECK(is_required IN (0,1)),
    is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0,1)),
    width TEXT NOT NULL DEFAULT 'full' CHECK(width IN ('full','half','third')),
    default_value TEXT,
    validation_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(validation_json)),
    settings_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(settings_json)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(form_id, field_key),
    FOREIGN KEY(form_id) REFERENCES forms(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(field_key = lower(trim(field_key)) AND field_key GLOB '[a-z0-9_]*')
);

CREATE TABLE IF NOT EXISTS form_field_translations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    field_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    label TEXT NOT NULL,
    placeholder TEXT,
    help_text TEXT,
    options_json TEXT NOT NULL DEFAULT '[]' CHECK(json_valid(options_json)),
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(field_id, language_code),
    FOREIGN KEY(field_id) REFERENCES form_fields(id) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS form_submissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    form_id INTEGER NOT NULL,
    site_id INTEGER NOT NULL,
    language_code TEXT,
    submission_status TEXT NOT NULL DEFAULT 'received' CHECK(submission_status IN ('received','spam','validated','notified','failed','archived')),
    spam_score REAL NOT NULL DEFAULT 0,
    spam_reasons_json TEXT NOT NULL DEFAULT '[]' CHECK(json_valid(spam_reasons_json)),
    ip_hash TEXT,
    user_agent TEXT,
    referer_url TEXT,
    payload_json TEXT NOT NULL CHECK(json_valid(payload_json)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(form_id) REFERENCES forms(id) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS form_submission_values (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    submission_id INTEGER NOT NULL,
    field_id INTEGER,
    field_key TEXT NOT NULL,
    field_label TEXT,
    value_json TEXT NOT NULL CHECK(json_valid(value_json)),
    value_text TEXT,
    FOREIGN KEY(submission_id) REFERENCES form_submissions(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(field_id) REFERENCES form_fields(id) ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS form_rate_limit_hits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    form_id INTEGER NOT NULL,
    ip_hash TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(form_id) REFERENCES forms(id) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS form_notification_deliveries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    form_id INTEGER NOT NULL,
    submission_id INTEGER,
    channel TEXT NOT NULL DEFAULT 'email' CHECK(channel IN ('email','webhook','log')),
    recipient TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','sent','failed','skipped')),
    subject TEXT,
    error_message TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at TEXT,
    FOREIGN KEY(form_id) REFERENCES forms(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(submission_id) REFERENCES form_submissions(id) ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_forms_site_status ON forms(site_id, status, is_active);
CREATE INDEX IF NOT EXISTS idx_form_fields_form ON form_fields(form_id, sort_order);
CREATE INDEX IF NOT EXISTS idx_form_submissions_form_created ON form_submissions(form_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_form_submissions_site_created ON form_submissions(site_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_form_values_submission ON form_submission_values(submission_id, field_key);
CREATE INDEX IF NOT EXISTS idx_form_rate_limit_hits ON form_rate_limit_hits(form_id, ip_hash, created_at DESC);
