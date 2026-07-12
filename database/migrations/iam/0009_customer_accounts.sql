PRAGMA foreign_keys = ON;

ALTER TABLE iam_users ADD COLUMN email_verified_at TEXT;

CREATE TABLE IF NOT EXISTS iam_customer_site_accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    site_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','disabled','merged')),
    merged_into_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    UNIQUE(user_id, site_id),
    FOREIGN KEY(user_id) REFERENCES iam_users(id) ON DELETE CASCADE,
    CHECK(site_id > 0),
    CHECK(merged_into_user_id IS NULL OR merged_into_user_id > 0)
);

CREATE TABLE IF NOT EXISTS iam_customer_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    site_id INTEGER NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    expires_at TEXT NOT NULL,
    last_seen_at TEXT,
    revoked_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(user_id) REFERENCES iam_users(id) ON DELETE CASCADE,
    CHECK(site_id > 0),
    CHECK(length(token_hash) >= 32)
);

CREATE TABLE IF NOT EXISTS iam_customer_email_changes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    previous_email_normalized TEXT NOT NULL,
    new_email_normalized TEXT NOT NULL,
    verification_method TEXT NOT NULL CHECK(verification_method IN ('current_password','admin','email_token')),
    changed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(user_id) REFERENCES iam_users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_iam_customer_site_accounts_site ON iam_customer_site_accounts(site_id,status,user_id);
CREATE INDEX IF NOT EXISTS idx_iam_customer_sessions_user_site ON iam_customer_sessions(user_id,site_id,expires_at);
