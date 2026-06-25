-- Explicit IAM login mode model.
-- Existing legacy rows with totp_enabled=1 represented email code login.

ALTER TABLE iam_users ADD COLUMN login_mode TEXT NOT NULL DEFAULT 'password' CHECK(login_mode IN ('password', 'email_code', 'totp'));
UPDATE iam_users
SET login_mode = CASE
    WHEN totp_enabled = 1 AND (totp_secret_protected IS NULL OR totp_secret_protected = '') THEN 'email_code'
    WHEN totp_enabled = 1 AND totp_secret_protected IS NOT NULL AND totp_secret_protected <> '' THEN 'totp'
    ELSE 'password'
END
WHERE login_mode = 'password';
CREATE INDEX IF NOT EXISTS idx_iam_users_login_mode ON iam_users(login_mode);
