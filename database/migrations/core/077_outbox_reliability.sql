PRAGMA foreign_keys = OFF;

CREATE TABLE IF NOT EXISTS outbox_events_v2 (
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

INSERT OR IGNORE INTO outbox_events_v2(
    id,event_id,event_type,schema_version,occurred_at,correlation_id,aggregate_type,
    topic,payload_json,metadata_json,status,attempts,max_attempts,last_error,
    created_at,available_at,claimed_at,processed_at,updated_at
)
SELECT id,'legacy-' || id,topic,1,created_at,'legacy-' || id,'system',
       topic,payload_json,'{}',status,attempts,5,last_error,
       created_at,available_at,claimed_at,processed_at,COALESCE(processed_at,claimed_at,created_at)
FROM outbox_events;

DROP TABLE outbox_events;
ALTER TABLE outbox_events_v2 RENAME TO outbox_events;

CREATE TABLE IF NOT EXISTS outbox_consumptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id TEXT NOT NULL,
    consumer_key TEXT NOT NULL,
    processed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    result_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(result_json)),
    UNIQUE(event_id, consumer_key),
    FOREIGN KEY(event_id) REFERENCES outbox_events(event_id) ON DELETE CASCADE
);

ALTER TABLE system_jobs ADD COLUMN last_heartbeat_at TEXT;
ALTER TABLE system_jobs ADD COLUMN locked_until TEXT;

CREATE INDEX IF NOT EXISTS idx_outbox_status_available ON outbox_events(status, available_at, id);
CREATE INDEX IF NOT EXISTS idx_outbox_lock ON outbox_events(status, locked_until, id);
CREATE INDEX IF NOT EXISTS idx_outbox_event_id ON outbox_events(event_id);
CREATE INDEX IF NOT EXISTS idx_outbox_correlation ON outbox_events(correlation_id);
CREATE INDEX IF NOT EXISTS idx_outbox_site_status ON outbox_events(site_id, status, available_at);
CREATE INDEX IF NOT EXISTS idx_outbox_consumptions_event ON outbox_consumptions(event_id);

PRAGMA foreign_keys = ON;
