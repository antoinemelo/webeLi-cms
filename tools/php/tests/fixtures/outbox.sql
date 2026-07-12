PRAGMA foreign_keys = ON;

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
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
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

CREATE TABLE webhook_deliveries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_topic TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    http_status INTEGER,
    last_error TEXT,
    last_attempt_at TEXT
);

CREATE INDEX idx_outbox_status_available ON outbox_events(status, available_at, id);
CREATE INDEX idx_outbox_lock ON outbox_events(status, locked_until, id);
CREATE INDEX idx_outbox_event_id ON outbox_events(event_id);
CREATE INDEX idx_outbox_consumptions_event ON outbox_consumptions(event_id);
