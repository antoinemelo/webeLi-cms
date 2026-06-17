CREATE TABLE IF NOT EXISTS cross_database_operations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    correlation_id TEXT NOT NULL UNIQUE,
    operation_key TEXT NOT NULL,
    operation_type TEXT NOT NULL,
    primary_store TEXT NOT NULL,
    status TEXT NOT NULL CHECK(status IN ('started','running','succeeded','failed','repair_required','compensated')),
    step TEXT NOT NULL,
    metadata_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(metadata_json)),
    attempts INTEGER NOT NULL DEFAULT 1 CHECK(attempts > 0),
    last_error TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_cross_database_operations_status_updated ON cross_database_operations(status, updated_at);
CREATE INDEX IF NOT EXISTS idx_cross_database_operations_key_updated ON cross_database_operations(operation_key, updated_at);
