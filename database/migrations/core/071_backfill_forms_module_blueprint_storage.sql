-- Backfill idempotent des contrats de stockage pour le module système Forms.
--
-- Pourquoi cette migration existe :
-- - 070_module_resource_blueprints.sql a ajouté les colonnes de gouvernance
--   module_blueprints.*, avec storage_json par défaut à '{}'.
-- - Sur une installation existante, le runtime peut donc contenir des lignes
--   forms/forms_* valides mais sans storage.database/table/primary_key.
-- - Le validateur c71 doit pouvoir passer après les migrations applicatives,
--   même si le seeder Python g1_seed_native_blueprints.py n'a pas encore été relancé.

INSERT INTO modules(module_key, name, version, provider_class, is_system, is_installed, is_enabled, config_json, updated_at)
VALUES('forms', 'Formulaires', '1.0.0', 'App\Modules\Forms\FormsModuleProvider', 1, 1, 1, '{}', CURRENT_TIMESTAMP)
ON CONFLICT(module_key) DO UPDATE SET
    name = excluded.name,
    version = excluded.version,
    provider_class = excluded.provider_class,
    is_system = 1,
    is_installed = 1,
    is_enabled = 1,
    updated_at = CURRENT_TIMESTAMP;

UPDATE module_blueprints
SET
    resource = 'form',
    storage_json = '{"database":"forms","table":"forms","primary_key":"id","mode":"external_table"}',
    updated_at = CURRENT_TIMESTAMP
WHERE module_key = 'forms'
  AND blueprint_key = 'forms_form'
  AND resource_type = 'module_resource';

UPDATE module_blueprints
SET
    resource = 'form_field',
    storage_json = '{"database":"forms","table":"form_fields","primary_key":"id","mode":"external_table"}',
    updated_at = CURRENT_TIMESTAMP
WHERE module_key = 'forms'
  AND blueprint_key = 'forms_form_field'
  AND resource_type = 'module_resource';

UPDATE module_blueprints
SET
    resource = 'form_submission',
    storage_json = '{"database":"forms","table":"form_submissions","primary_key":"id","mode":"external_table"}',
    updated_at = CURRENT_TIMESTAMP
WHERE module_key = 'forms'
  AND blueprint_key = 'forms_form_submission'
  AND resource_type = 'module_resource';
