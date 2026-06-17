PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS ai_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    key TEXT NOT NULL,
    value_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(value_json)),
    description TEXT NOT NULL DEFAULT '',
    enabled INTEGER NOT NULL DEFAULT 1 CHECK(enabled IN (0,1)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(key),
    CHECK(key = lower(trim(key)) AND key GLOB '[a-z0-9_.-]*')
);

CREATE TABLE IF NOT EXISTS ai_provider_types (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    key TEXT NOT NULL,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 100 CHECK(sort_order >= 0),
    enabled INTEGER NOT NULL DEFAULT 1 CHECK(enabled IN (0,1)),
    is_system INTEGER NOT NULL DEFAULT 0 CHECK(is_system IN (0,1)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(key),
    CHECK(key = lower(trim(key)) AND key GLOB '[a-z0-9_]*')
);

CREATE TABLE IF NOT EXISTS ai_providers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type_key TEXT NOT NULL DEFAULT 'editorial',
    key TEXT NOT NULL,
    name TEXT NOT NULL,
    provider_type TEXT NOT NULL CHECK(provider_type IN ('null','openai_compatible','infomaniak','local','custom_http','ollama')),
    base_url TEXT,
    api_key_ref TEXT,
    enabled INTEGER NOT NULL DEFAULT 0 CHECK(enabled IN (0,1)),
    is_default INTEGER NOT NULL DEFAULT 0 CHECK(is_default IN (0,1)),
    options_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(options_json)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(key),
    FOREIGN KEY(type_key) REFERENCES ai_provider_types(key) ON UPDATE CASCADE ON DELETE RESTRICT,
    CHECK(key = lower(trim(key)) AND key GLOB '[a-z0-9_]*'),
    CHECK(type_key = lower(trim(type_key)) AND type_key GLOB '[a-z0-9_]*'),
    CHECK(api_key_ref IS NULL OR api_key_ref GLOB 'env:[A-Z0-9_]*')
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_ai_providers_default
    ON ai_providers(is_default)
    WHERE is_default = 1;
CREATE INDEX IF NOT EXISTS idx_ai_providers_type_enabled ON ai_providers(type_key, enabled, name);

CREATE TABLE IF NOT EXISTS ai_models (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    provider_id INTEGER NOT NULL,
    key TEXT NOT NULL,
    name TEXT NOT NULL,
    model_type TEXT NOT NULL DEFAULT 'chat' CHECK(model_type IN ('chat','embedding','reranker','speech_to_text','image','audio','code','local')),
    context_window INTEGER NOT NULL DEFAULT 0 CHECK(context_window >= 0),
    input_price REAL NOT NULL DEFAULT 0 CHECK(input_price >= 0),
    output_price REAL NOT NULL DEFAULT 0 CHECK(output_price >= 0),
    currency TEXT NOT NULL DEFAULT 'CHF' CHECK(length(currency) = 3 AND currency = upper(currency)),
    api_key_ref TEXT,
    max_monthly_budget_json TEXT NOT NULL DEFAULT '{"amount":0,"currency":"CHF"}' CHECK(json_valid(max_monthly_budget_json)),
    enabled INTEGER NOT NULL DEFAULT 0 CHECK(enabled IN (0,1)),
    options_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(options_json)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(provider_id, key),
    FOREIGN KEY(provider_id) REFERENCES ai_providers(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(key = lower(trim(key)) AND key GLOB '[a-z0-9_.:-]*'),
    CHECK(api_key_ref IS NULL OR api_key_ref GLOB 'env:[A-Z0-9_]*')
);

CREATE TABLE IF NOT EXISTS ai_model_usages (
    model_id INTEGER NOT NULL,
    usage_key TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 100 CHECK(sort_order >= 0),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(model_id, usage_key),
    FOREIGN KEY(model_id) REFERENCES ai_models(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(usage_key) REFERENCES ai_provider_types(key) ON UPDATE CASCADE ON DELETE RESTRICT,
    CHECK(usage_key = lower(trim(usage_key)) AND usage_key GLOB '[a-z0-9_]*')
);

CREATE TABLE IF NOT EXISTS ai_site_usage_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL CHECK(site_id > 0),
    usage_key TEXT NOT NULL,
    mode TEXT NOT NULL DEFAULT 'disabled' CHECK(mode IN ('inherit','enabled','disabled')),
    model_id INTEGER,
    provider_key TEXT,
    model_key TEXT,
    translation_enabled INTEGER NOT NULL DEFAULT 0 CHECK(translation_enabled IN (0,1)),
    seo_enabled INTEGER NOT NULL DEFAULT 0 CHECK(seo_enabled IN (0,1)),
    notes TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(site_id, usage_key),
    FOREIGN KEY(usage_key) REFERENCES ai_provider_types(key) ON UPDATE CASCADE ON DELETE RESTRICT,
    FOREIGN KEY(model_id) REFERENCES ai_models(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CHECK(usage_key = lower(trim(usage_key)) AND usage_key GLOB '[a-z0-9_]*'),
    CHECK(provider_key IS NULL OR (provider_key = lower(trim(provider_key)) AND provider_key GLOB '[a-z0-9_]*')),
    CHECK(model_key IS NULL OR (model_key = lower(trim(model_key)) AND model_key GLOB '[a-z0-9_.:-]*'))
);

CREATE TABLE IF NOT EXISTS ai_prompts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    key TEXT NOT NULL,
    name TEXT NOT NULL,
    category TEXT NOT NULL CHECK(category IN ('editorial','seo','translation','composition','draft','diagnostic','image')),
    system_prompt TEXT NOT NULL DEFAULT '',
    user_template TEXT NOT NULL DEFAULT '',
    output_schema_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(output_schema_json)),
    language TEXT,
    enabled INTEGER NOT NULL DEFAULT 0 CHECK(enabled IN (0,1)),
    version INTEGER NOT NULL DEFAULT 1 CHECK(version >= 1),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(key, version),
    CHECK(key = lower(trim(key)) AND key GLOB '[a-z0-9_.-]*'),
    CHECK(language IS NULL OR language = lower(trim(language)))
);

CREATE TABLE IF NOT EXISTS ai_tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER,
    user_id INTEGER,
    title TEXT NOT NULL DEFAULT 'Tâche IA',
    task_type TEXT NOT NULL,
    provider_key TEXT,
    model_key TEXT,
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','running','completed','failed','cancelled')),
    input_hash TEXT NOT NULL DEFAULT '',
    payload_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(payload_json)),
    target_type TEXT,
    target_id TEXT,
    result_id INTEGER,
    error_type TEXT,
    error_message TEXT,
    attempts INTEGER NOT NULL DEFAULT 0 CHECK(attempts >= 0),
    max_attempts INTEGER NOT NULL DEFAULT 1 CHECK(max_attempts >= 1),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at TEXT,
    finished_at TEXT,
    CHECK(site_id IS NULL OR site_id > 0),
    CHECK(user_id IS NULL OR user_id > 0),
    CHECK(task_type = lower(trim(task_type)) AND task_type GLOB '[a-z0-9_.-]*')
);

CREATE INDEX IF NOT EXISTS idx_ai_tasks_status_created ON ai_tasks(status, created_at, id);
CREATE INDEX IF NOT EXISTS idx_ai_tasks_site_status ON ai_tasks(site_id, status, created_at);
CREATE INDEX IF NOT EXISTS idx_ai_tasks_type_status ON ai_tasks(task_type, status, created_at);

CREATE TABLE IF NOT EXISTS ai_task_results (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id INTEGER NOT NULL,
    result_type TEXT NOT NULL,
    result_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(result_json)),
    summary TEXT NOT NULL DEFAULT '',
    warnings_json TEXT NOT NULL DEFAULT '[]' CHECK(json_valid(warnings_json)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(task_id) REFERENCES ai_tasks(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(result_type = lower(trim(result_type)) AND result_type GLOB '[a-z0-9_.-]*')
);

CREATE TABLE IF NOT EXISTS ai_suggestions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER,
    user_id INTEGER,
    title TEXT NOT NULL DEFAULT 'Suggestion IA',
    target_type TEXT NOT NULL,
    target_id TEXT NOT NULL,
    suggestion_type TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','proposed','accepted','rejected','applied','expired')),
    source_field TEXT,
    target_field TEXT,
    suggestion_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(suggestion_json)),
    preview_text TEXT NOT NULL DEFAULT '',
    reason TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    accepted_at TEXT,
    rejected_at TEXT,
    applied_at TEXT,
    expired_at TEXT,
    applied_action_run_id INTEGER,
    CHECK(site_id IS NULL OR site_id > 0),
    CHECK(user_id IS NULL OR user_id > 0),
    CHECK(target_type = lower(trim(target_type)) AND target_type GLOB '[a-z0-9_.-]*'),
    CHECK(suggestion_type = lower(trim(suggestion_type)) AND suggestion_type GLOB '[a-z0-9_.-]*')
);

CREATE TABLE IF NOT EXISTS ai_usage_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER,
    user_id INTEGER,
    provider_key TEXT NOT NULL,
    model_key TEXT NOT NULL,
    task_type TEXT NOT NULL,
    input_tokens INTEGER NOT NULL DEFAULT 0 CHECK(input_tokens >= 0),
    output_tokens INTEGER NOT NULL DEFAULT 0 CHECK(output_tokens >= 0),
    total_tokens INTEGER NOT NULL DEFAULT 0 CHECK(total_tokens >= 0),
    duration_ms INTEGER NOT NULL DEFAULT 0 CHECK(duration_ms >= 0),
    estimated_cost REAL NOT NULL DEFAULT 0 CHECK(estimated_cost >= 0),
    currency TEXT NOT NULL DEFAULT 'CHF' CHECK(length(currency) = 3 AND currency = upper(currency)),
    status TEXT NOT NULL DEFAULT 'success' CHECK(status IN ('success','failed','blocked')),
    details_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(site_id IS NULL OR site_id > 0),
    CHECK(user_id IS NULL OR user_id > 0),
    CHECK(provider_key = lower(trim(provider_key)) AND provider_key GLOB '[a-z0-9_]*'),
    CHECK(task_type = lower(trim(task_type)) AND task_type GLOB '[a-z0-9_.-]*')
);

CREATE VIEW IF NOT EXISTS ai_model_catalog AS
SELECT
    m.id AS model_id,
    m.key AS model_key,
    m.name AS model_name,
    COALESCE((SELECT group_concat(mu.usage_key, ',') FROM ai_model_usages mu WHERE mu.model_id = m.id), '') AS usage_keys,
    COALESCE((SELECT group_concat(ut.name, ', ') FROM ai_model_usages mu JOIN ai_provider_types ut ON ut.key = mu.usage_key WHERE mu.model_id = m.id ORDER BY ut.name), '') AS usage_names,
    m.model_type,
    m.enabled AS model_enabled,
    m.context_window,
    m.input_price,
    m.output_price,
    m.currency,
    m.api_key_ref AS model_api_key_ref,
    m.max_monthly_budget_json,
    p.id AS provider_id,
    p.key AS provider_key,
    p.name AS provider_name,
    p.provider_type,
    p.base_url,
    p.api_key_ref AS provider_api_key_ref,
    p.enabled AS provider_enabled,
    p.type_key,
    t.name AS type_name
FROM ai_models m
JOIN ai_providers p ON p.id = m.provider_id
JOIN ai_provider_types t ON t.key = p.type_key;

CREATE INDEX IF NOT EXISTS idx_ai_provider_types_enabled ON ai_provider_types(enabled, sort_order, name);
CREATE INDEX IF NOT EXISTS idx_ai_models_provider_type ON ai_models(provider_id, model_type, enabled);
CREATE INDEX IF NOT EXISTS idx_ai_model_usages_usage ON ai_model_usages(usage_key, model_id);
CREATE INDEX IF NOT EXISTS idx_ai_prompts_category_language ON ai_prompts(category, language, enabled);
CREATE INDEX IF NOT EXISTS idx_ai_site_usage_settings_site ON ai_site_usage_settings(site_id, usage_key, mode);
CREATE INDEX IF NOT EXISTS idx_ai_site_usage_settings_model ON ai_site_usage_settings(model_id);
CREATE INDEX IF NOT EXISTS idx_ai_tasks_status_created ON ai_tasks(status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_ai_tasks_target ON ai_tasks(target_type, target_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_ai_task_results_task ON ai_task_results(task_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_ai_suggestions_status_created ON ai_suggestions(status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_ai_suggestions_target ON ai_suggestions(target_type, target_id, status);
CREATE INDEX IF NOT EXISTS idx_ai_usage_site_created ON ai_usage_events(site_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_ai_usage_provider_model ON ai_usage_events(provider_key, model_key, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_ai_usage_status_created ON ai_usage_events(status, created_at DESC);
