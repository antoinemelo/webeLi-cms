PRAGMA foreign_keys = ON;


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
