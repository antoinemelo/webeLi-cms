PRAGMA foreign_keys = ON;

CREATE TABLE languages (
    code TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    locale TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0,1))
);

CREATE TABLE sites (
    id INTEGER PRIMARY KEY,
    site_key TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    default_language_code TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0,1)),
    FOREIGN KEY(default_language_code) REFERENCES languages(code)
);

CREATE TABLE webhook_endpoints (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER,
    name TEXT NOT NULL DEFAULT '',
    url TEXT NOT NULL,
    events_json TEXT NOT NULL CHECK(json_valid(events_json)),
    secret TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0,1)),
    max_attempts INTEGER NOT NULL DEFAULT 5 CHECK(max_attempts BETWEEN 1 AND 25),
    last_attempt_at TEXT,
    next_attempt_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(url LIKE 'https://%' OR url LIKE 'http://localhost:%' OR url LIKE 'http://127.0.0.1:%'),
    CHECK(length(secret) >= 16),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE
);

CREATE TABLE outbox_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    topic TEXT NOT NULL,
    payload_json TEXT NOT NULL CHECK(json_valid(payload_json)),
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','processing','processed','failed')),
    attempts INTEGER NOT NULL DEFAULT 0,
    last_error TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    available_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    claimed_at TEXT,
    processed_at TEXT
);

CREATE TABLE webhook_deliveries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    webhook_id INTEGER NOT NULL,
    outbox_event_id INTEGER NOT NULL,
    delivery_id TEXT NOT NULL UNIQUE,
    event_topic TEXT NOT NULL,
    payload_json TEXT NOT NULL CHECK(json_valid(payload_json)),
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','processing','succeeded','failed')),
    attempts INTEGER NOT NULL DEFAULT 0 CHECK(attempts >= 0),
    http_status INTEGER,
    response_body TEXT,
    last_error TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    next_attempt_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_attempt_at TEXT,
    delivered_at TEXT,
    UNIQUE(webhook_id, outbox_event_id),
    CHECK(delivered_at IS NULL OR status = 'succeeded'),
    FOREIGN KEY(webhook_id) REFERENCES webhook_endpoints(id) ON DELETE CASCADE,
    FOREIGN KEY(outbox_event_id) REFERENCES outbox_events(id) ON DELETE CASCADE
);

CREATE INDEX idx_webhook_endpoints_site_active ON webhook_endpoints(site_id, is_active);
CREATE INDEX idx_webhook_deliveries_due ON webhook_deliveries(status, next_attempt_at, id);
