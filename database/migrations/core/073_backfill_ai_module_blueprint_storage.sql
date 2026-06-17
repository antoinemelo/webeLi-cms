-- Backfill idempotent des contrats de stockage pour le module optionnel Assistant IA.
--
-- Pourquoi cette migration existe :
-- - Les blueprints du module IA peuvent avoir été synchronisés dans
--   module_blueprints avant que les contrats de stockage external_table vers
--   ai.sqlite ne soient complets.
-- - c71_validate_module_blueprint_governance.py exige que toute ressource
--   module_resource déclare storage.database, storage.table et storage.primary_key.
-- - La migration répare les installations existantes sans toucher aux tables métier
--   du CMS et sans déplacer les données IA hors de ai.sqlite.

INSERT INTO modules(module_key, name, version, provider_class, is_system, is_installed, is_enabled, config_json, updated_at)
VALUES('ai-assistant', 'Assistant IA', '0.1.0', 'App\Modules\AiAssistant\AiAssistantModuleProvider', 1, 1, 1, '{}', CURRENT_TIMESTAMP)
ON CONFLICT(module_key) DO UPDATE SET
    name = excluded.name,
    version = excluded.version,
    provider_class = excluded.provider_class,
    is_system = 1,
    is_installed = 1,
    is_enabled = 1,
    updated_at = CURRENT_TIMESTAMP;

UPDATE module_blueprints
SET resource = 'setting',
    storage_json = '{"database":"ai","table":"ai_settings","primary_key":"id","mode":"external_table"}',
    is_published = 1,
    updated_at = CURRENT_TIMESTAMP
WHERE module_key = 'ai-assistant'
  AND blueprint_key = 'ai_assistant_setting'
  AND resource_type = 'module_resource';

UPDATE module_blueprints
SET resource = 'provider',
    storage_json = '{"database":"ai","table":"ai_providers","primary_key":"id","mode":"external_table"}',
    is_published = 1,
    updated_at = CURRENT_TIMESTAMP
WHERE module_key = 'ai-assistant'
  AND blueprint_key = 'ai_assistant_provider'
  AND resource_type = 'module_resource';

UPDATE module_blueprints
SET resource = 'model',
    storage_json = '{"database":"ai","table":"ai_models","primary_key":"id","mode":"external_table"}',
    is_published = 1,
    updated_at = CURRENT_TIMESTAMP
WHERE module_key = 'ai-assistant'
  AND blueprint_key = 'ai_assistant_model'
  AND resource_type = 'module_resource';

UPDATE module_blueprints
SET resource = 'prompt',
    storage_json = '{"database":"ai","table":"ai_prompts","primary_key":"id","mode":"external_table"}',
    is_published = 1,
    updated_at = CURRENT_TIMESTAMP
WHERE module_key = 'ai-assistant'
  AND blueprint_key = 'ai_assistant_prompt'
  AND resource_type = 'module_resource';

UPDATE module_blueprints
SET resource = 'site_setting',
    storage_json = '{"database":"ai","table":"ai_site_usage_settings","primary_key":"id","mode":"external_table"}',
    is_published = 1,
    updated_at = CURRENT_TIMESTAMP
WHERE module_key = 'ai-assistant'
  AND blueprint_key = 'ai_assistant_site_setting'
  AND resource_type = 'module_resource';

UPDATE module_blueprints
SET resource = 'suggestion',
    storage_json = '{"database":"ai","table":"ai_suggestions","primary_key":"id","mode":"external_table"}',
    is_published = 1,
    updated_at = CURRENT_TIMESTAMP
WHERE module_key = 'ai-assistant'
  AND blueprint_key = 'ai_assistant_suggestion'
  AND resource_type = 'module_resource';


UPDATE module_blueprints
SET resource = 'task',
    storage_json = '{"database":"ai","table":"ai_tasks","primary_key":"id","mode":"external_table"}',
    is_published = 1,
    updated_at = CURRENT_TIMESTAMP
WHERE module_key = 'ai-assistant'
  AND blueprint_key = 'ai_assistant_task'
  AND resource_type = 'module_resource';

UPDATE module_blueprints
SET resource = 'usage_event',
    storage_json = '{"database":"ai","table":"ai_usage_events","primary_key":"id","mode":"external_table"}',
    is_published = 1,
    updated_at = CURRENT_TIMESTAMP
WHERE module_key = 'ai-assistant'
  AND blueprint_key = 'ai_assistant_usage_event'
  AND resource_type = 'module_resource';

UPDATE module_blueprints
SET resource = 'provider_type',
    storage_json = '{"database":"ai","table":"ai_provider_types","primary_key":"id","mode":"external_table"}',
    is_published = 1,
    updated_at = CURRENT_TIMESTAMP
WHERE module_key = 'ai-assistant'
  AND blueprint_key = 'ai_assistant_provider_type'
  AND resource_type = 'module_resource';

