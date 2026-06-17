CREATE TABLE IF NOT EXISTS action_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER,
    user_id INTEGER,
    module_key TEXT NOT NULL,
    action_key TEXT NOT NULL,
    mode TEXT NOT NULL CHECK(mode IN ('dry_run','apply')),
    status TEXT NOT NULL,
    risk_level TEXT NOT NULL DEFAULT 'low' CHECK(risk_level IN ('low','medium','high')),
    input_summary_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(input_summary_json)),
    output_summary_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(output_summary_json)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(site_id IS NULL OR site_id > 0),
    CHECK(user_id IS NULL OR user_id > 0),
    FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_action_runs_action_created ON action_runs(action_key, created_at);
CREATE INDEX IF NOT EXISTS idx_action_runs_site_created ON action_runs(site_id, created_at);
CREATE INDEX IF NOT EXISTS idx_action_runs_user_created ON action_runs(user_id, created_at);
