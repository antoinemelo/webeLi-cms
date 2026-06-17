-- Native IAM 2FA / TOTP support.
-- This migration is idempotent for SQLite rebuilds and explicit upgrade runs.

ALTER TABLE iam_users ADD COLUMN totp_enabled INTEGER NOT NULL DEFAULT 0 CHECK(totp_enabled IN (0, 1));
ALTER TABLE iam_users ADD COLUMN totp_required INTEGER NOT NULL DEFAULT 0 CHECK(totp_required IN (0, 1));
ALTER TABLE iam_users ADD COLUMN totp_secret_protected TEXT;
ALTER TABLE iam_users ADD COLUMN totp_recovery_codes_json TEXT;
ALTER TABLE iam_users ADD COLUMN totp_enabled_at TEXT;
CREATE INDEX IF NOT EXISTS idx_iam_users_totp_enabled ON iam_users(totp_enabled);


CREATE TABLE IF NOT EXISTS iam_email_2fa_challenges (
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
CREATE INDEX IF NOT EXISTS idx_iam_email_2fa_user_expires ON iam_email_2fa_challenges(user_id, expires_at);
CREATE INDEX IF NOT EXISTS idx_iam_email_2fa_consumed ON iam_email_2fa_challenges(consumed_at);
