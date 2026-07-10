PRAGMA foreign_keys = ON;

CREATE TABLE iam_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL,
    email_normalized TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    first_name TEXT,
    last_name TEXT,
    locale TEXT DEFAULT 'fr-CH',
    is_active INTEGER NOT NULL DEFAULT 1,
    disabled_at TEXT,
    disabled_reason TEXT,
    last_login_at TEXT,
    password_reset_selector TEXT,
    password_reset_token_hash TEXT,
    password_reset_expires_at TEXT,
    password_reset_requested_at TEXT,
    password_reset_sent_at TEXT,
    last_password_change_at TEXT,
    login_mode TEXT NOT NULL DEFAULT 'password' CHECK(login_mode IN ('password', 'email_code', 'totp')),
    totp_enabled INTEGER NOT NULL DEFAULT 0 CHECK(totp_enabled IN (0, 1)),
    totp_required INTEGER NOT NULL DEFAULT 0 CHECK(totp_required IN (0, 1)),
    totp_secret_protected TEXT,
    totp_recovery_codes_json TEXT,
    totp_enabled_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(email_normalized = lower(trim(email)))
);

CREATE TABLE iam_roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    role_key TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    description TEXT
);

CREATE TABLE iam_permissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    permission_key TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    description TEXT
);

CREATE TABLE iam_user_roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    role_id INTEGER NOT NULL,
    UNIQUE(user_id, role_id),
    FOREIGN KEY(user_id) REFERENCES iam_users(id) ON DELETE CASCADE,
    FOREIGN KEY(role_id) REFERENCES iam_roles(id) ON DELETE CASCADE
);

-- Site-scoped role assignments. site_id is a logical reference to core.sites(id):
-- core.sqlite and iam.sqlite remain separate, so SQLite cannot enforce this FK here.
CREATE TABLE iam_user_site_roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    site_id INTEGER NOT NULL,
    role_id INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(site_id > 0),
    UNIQUE(user_id, site_id, role_id),
    FOREIGN KEY(user_id) REFERENCES iam_users(id) ON DELETE CASCADE,
    FOREIGN KEY(role_id) REFERENCES iam_roles(id) ON DELETE CASCADE
);

CREATE TABLE iam_role_permissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    role_id INTEGER NOT NULL,
    permission_id INTEGER NOT NULL,
    UNIQUE(role_id, permission_id),
    FOREIGN KEY(role_id) REFERENCES iam_roles(id) ON DELETE CASCADE,
    FOREIGN KEY(permission_id) REFERENCES iam_permissions(id) ON DELETE CASCADE
);

CREATE TABLE iam_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    session_token_hash TEXT NOT NULL UNIQUE,
    ip_address TEXT,
    user_agent TEXT,
    last_seen_at TEXT,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(expires_at > created_at),
    FOREIGN KEY(user_id) REFERENCES iam_users(id) ON DELETE CASCADE
);


CREATE TABLE iam_email_2fa_challenges (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    code_hash TEXT NOT NULL,
    ip_address TEXT,
    user_agent TEXT,
    attempt_count INTEGER NOT NULL DEFAULT 0,
    expires_at TEXT NOT NULL,
    consumed_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(expires_at > created_at),
    FOREIGN KEY(user_id) REFERENCES iam_users(id) ON DELETE CASCADE
);

CREATE TABLE iam_audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_user_id INTEGER,
    action_key TEXT NOT NULL,
    resource_type TEXT,
    resource_id INTEGER,
    context_json TEXT,
    ip_address TEXT,
    user_agent TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(actor_user_id) REFERENCES iam_users(id) ON DELETE SET NULL
);


CREATE TABLE IF NOT EXISTS api_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    site_id INTEGER,
    scopes TEXT NOT NULL DEFAULT 'content:read',
    is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0, 1)),
    expires_at TEXT,
    last_used_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(site_id IS NULL OR site_id > 0),
    CHECK(expires_at IS NULL OR expires_at > created_at)
);
CREATE INDEX IF NOT EXISTS idx_api_tokens_hash_active ON api_tokens(token_hash, is_active);
CREATE INDEX IF NOT EXISTS idx_api_tokens_site_active ON api_tokens(site_id, is_active);
CREATE INDEX IF NOT EXISTS idx_api_tokens_expires ON api_tokens(expires_at);

CREATE TABLE IF NOT EXISTS iam_rate_limits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    rate_key TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_iam_rate_limits_key_created ON iam_rate_limits(rate_key, created_at);
CREATE INDEX IF NOT EXISTS idx_iam_user_roles_user ON iam_user_roles(user_id);
CREATE INDEX IF NOT EXISTS idx_iam_user_site_roles_user_site ON iam_user_site_roles(user_id, site_id);
CREATE INDEX IF NOT EXISTS idx_iam_user_site_roles_site_role ON iam_user_site_roles(site_id, role_id);
CREATE INDEX IF NOT EXISTS idx_iam_users_active ON iam_users(is_active);
CREATE INDEX IF NOT EXISTS idx_iam_users_email_normalized ON iam_users(email_normalized);
CREATE INDEX IF NOT EXISTS idx_iam_users_login_mode ON iam_users(login_mode);
CREATE INDEX IF NOT EXISTS idx_iam_users_password_reset_selector ON iam_users(password_reset_selector);
CREATE INDEX IF NOT EXISTS idx_iam_users_password_reset_expires ON iam_users(password_reset_expires_at);
CREATE INDEX IF NOT EXISTS idx_iam_users_totp_enabled ON iam_users(totp_enabled);
CREATE INDEX IF NOT EXISTS idx_iam_email_2fa_user_expires ON iam_email_2fa_challenges(user_id, expires_at);
CREATE INDEX IF NOT EXISTS idx_iam_email_2fa_consumed ON iam_email_2fa_challenges(consumed_at);
CREATE INDEX IF NOT EXISTS idx_iam_sessions_token_hash_expiry ON iam_sessions(session_token_hash, expires_at);
CREATE INDEX IF NOT EXISTS idx_iam_audit_logs_action_created ON iam_audit_logs(action_key, created_at);

CREATE TABLE IF NOT EXISTS schema_migrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    migration TEXT NOT NULL UNIQUE,
    migrated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
