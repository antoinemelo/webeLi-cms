<?php

declare(strict_types=1);

namespace App\Modules\AiAssistant;

use App\Module\ModuleProvider;

/**
 * Module optionnel Assistant IA.
 *
 * Le provider reste déclaratif. Le core découvre le module, ses permissions,
 * ses routes et sa base SQLite dédiée, mais ne porte aucune logique IA.
 */
final class AiAssistantModuleProvider implements ModuleProvider
{
    public function key(): string { return 'ai-assistant'; }

    public function name(): string { return 'Assistant IA'; }

    public function version(): string { return '0.1.0'; }

    public function description(): string
    {
        return 'Socle applicatif optionnel pour providers IA interchangeables, prompts, suggestions, journalisation d’usage et configuration dans ai.sqlite.';
    }

    public function dependencies(): array { return []; }

    public function databases(): array
    {
        return [[
            'key' => 'ai',
            'driver' => 'sqlite',
            'path' => 'storage/database/ai.sqlite',
            'schema' => 'database/modules/ai.sql',
            // La base est créée par les scripts from scratch. Le runtime doit
            // rester robuste si elle est absente ou si le module est désactivé.
            'required' => false,
        ]];
    }

    public function permissions(): array
    {
        return [
            ['key' => 'ai.use', 'name' => 'Utiliser l’IA', 'description' => 'Accéder aux fonctions IA non destructives.'],
            ['key' => 'ai.provider.manage', 'name' => 'Gérer les providers IA', 'description' => 'Configurer et tester les fournisseurs IA.'],
            ['key' => 'ai.suggestions.read', 'name' => 'Lire les suggestions IA', 'description' => 'Consulter les suggestions produites par l’IA.'],
            ['key' => 'ai.suggestions.manage', 'name' => 'Gérer les suggestions IA', 'description' => 'Accepter, rejeter ou archiver les suggestions IA.'],
            ['key' => 'ai.content.suggest', 'name' => 'Suggérer du contenu avec l’IA', 'description' => 'Demander des suggestions éditoriales sans publication automatique.'],
            ['key' => 'ai.content.draft', 'name' => 'Préparer des brouillons IA', 'description' => 'Préparer des brouillons via capabilities du core.'],
            ['key' => 'ai.seo.suggest', 'name' => 'Suggérer du SEO avec l’IA', 'description' => 'Préparer des suggestions de métadonnées et diagnostics SEO.'],
            ['key' => 'ai.translation.suggest', 'name' => 'Suggérer des traductions IA', 'description' => 'Préparer des suggestions de traduction.'],
            ['key' => 'ai.actions.apply', 'name' => 'Appliquer une action IA', 'description' => 'Appliquer une suggestion après dry-run, permission et confirmation.'],
            ['key' => 'ai.logs.read', 'name' => 'Lire les logs IA', 'description' => 'Consulter les journaux d’usage IA.'],
        ];
    }

    public function settingsSchema(): array
    {
        return [
            'schema_version' => 1,
            'database' => ['key' => 'ai', 'path' => 'storage/database/ai.sqlite', 'optional' => true],
            'defaults' => ['enabled' => false, 'provider' => 'null_provider', 'text_model' => 'null_text_model'],
        ];
    }

    public function blueprints(): array
    {
        return [
            $this->settingsBlueprint(),
            $this->providerTypeBlueprint(),
            $this->providerBlueprint(),
            $this->modelBlueprint(),
            $this->promptBlueprint(),
            $this->siteSettingBlueprint(),
            $this->suggestionBlueprint(),
            $this->taskBlueprint(),
            $this->usageEventBlueprint(),
        ];
    }

    public function contentTypes(): array { return []; }

    public function adminNavigation(): array
    {
        return [[
            'key' => 'ai-assistant.manage',
            'label' => 'Assistant IA',
            'navLabel' => 'Assistant IA',
            'route' => '/modules/ai-assistant/config',
            'permission' => 'ai.use',
            'section' => 'Modules',
            'hint' => 'Consulter l’état du module IA, sa base dédiée, ses routes, ses permissions et sa configuration sans appel externe.',
            'sort_order' => 130,
        ]];
    }

    public function adminRoutes(): array
    {
        return [
            ['GET', '/admin/api/ai/status', 'App\\Application\\Api\\Admin\\AiAssistantApiController@status'],
            ['GET', '/admin/api/ai/settings', 'App\\Application\\Api\\Admin\\AiAssistantApiController@settings'],
            ['POST', '/admin/api/ai/settings', 'App\\Application\\Api\\Admin\\AiAssistantApiController@updateSettings'],
            ['GET', '/admin/api/ai/site-settings', 'App\\Application\\Api\\Admin\\AiAssistantApiController@siteSettings'],
            ['POST', '/admin/api/ai/site-settings', 'App\\Application\\Api\\Admin\\AiAssistantApiController@updateSiteSettings'],
            ['GET', '/admin/api/ai/providers', 'App\\Application\\Api\\Admin\\AiAssistantApiController@providers'],
            ['POST', '/admin/api/ai/provider-types', 'App\\Application\\Api\\Admin\\AiAssistantApiController@saveProviderType'],
            ['DELETE', '/admin/api/ai/provider-types/{key}', 'App\\Application\\Api\\Admin\\AiAssistantApiController@deleteProviderType'],
            ['POST', '/admin/api/ai/providers', 'App\\Application\\Api\\Admin\\AiAssistantApiController@saveProvider'],
            ['DELETE', '/admin/api/ai/providers/{key}', 'App\\Application\\Api\\Admin\\AiAssistantApiController@deleteProvider'],
            ['POST', '/admin/api/ai/models', 'App\\Application\\Api\\Admin\\AiAssistantApiController@saveModel'],
            ['DELETE', '/admin/api/ai/models/{providerKey}/{modelKey}', 'App\\Application\\Api\\Admin\\AiAssistantApiController@deleteModel'],
            ['POST', '/admin/api/ai/providers/test', 'App\\Application\\Api\\Admin\\AiAssistantApiController@testProvider'],
            ['GET', '/admin/api/ai/providers/test/stream', 'App\\Application\\Api\\Admin\\AiAssistantApiController@streamTestUsage'],
            ['POST', '/admin/api/ai/editorial/test-generation', 'App\\Application\\Api\\Admin\\AiAssistantApiController@testEditorialGeneration'],
            ['GET', '/admin/api/ai/prompts', 'App\\Application\\Api\\Admin\\AiAssistantApiController@prompts'],
            ['GET', '/admin/api/ai/actions', 'App\\Application\\Api\\Admin\\AiAssistantApiController@actions'],
            ['GET', '/admin/api/ai/suggestions', 'App\\Application\\Api\\Admin\\AiAssistantApiController@suggestions'],
            ['POST', '/admin/api/ai/suggestions/propose', 'App\\Application\\Api\\Admin\\AiAssistantApiController@proposeSuggestion'],
            ['POST', '/admin/api/ai/suggestions/{id}/propose', 'App\\Application\\Api\\Admin\\AiAssistantApiController@proposeExistingSuggestion'],
            ['POST', '/admin/api/ai/suggestions/{id}/accept', 'App\\Application\\Api\\Admin\\AiAssistantApiController@acceptSuggestion'],
            ['POST', '/admin/api/ai/suggestions/{id}/reject', 'App\\Application\\Api\\Admin\\AiAssistantApiController@rejectSuggestion'],
            ['POST', '/admin/api/ai/suggestions/{id}/apply', 'App\\Application\\Api\\Admin\\AiAssistantApiController@applySuggestion'],
            ['POST', '/admin/api/ai/suggestions/{id}/expire', 'App\\Application\\Api\\Admin\\AiAssistantApiController@expireSuggestion'],
            ['GET', '/admin/api/ai/usage', 'App\\Application\\Api\\Admin\\AiAssistantApiController@usage'],
            ['GET', '/admin/api/ai/budget', 'App\\Application\\Api\\Admin\\AiAssistantApiController@budgetSummary'],
            ['POST', '/admin/api/ai/tasks', 'App\\Application\\Api\\Admin\\AiAssistantApiController@createTask'],
            ['GET', '/admin/api/ai/tasks/{id}', 'App\\Application\\Api\\Admin\\AiAssistantApiController@taskStatus'],
            ['POST', '/admin/api/ai/tasks/{id}/cancel', 'App\\Application\\Api\\Admin\\AiAssistantApiController@cancelTask'],
            ['DELETE', '/admin/api/ai/usage', 'App\\Application\\Api\\Admin\\AiAssistantApiController@clearUsage'],
        ];
    }

    public function apiRoutes(): array { return []; }

    public function publicHeadlessRoutes(): array { return []; }

    public function hooks(): array { return []; }

    public function migrations(): array { return []; }

    public function seeds(): array { return []; }

    private function settingsBlueprint(): array
    {
        return [
            'module' => 'ai-assistant',
            'resource' => 'setting',
            'blueprint_key' => 'ai_assistant_setting',
            'resource_type' => 'module_resource',
            'label' => 'Paramètre IA',
            'description' => 'Paramètres globaux du module IA stockés dans ai.sqlite.',
            'version' => 1,
            'storage' => ['database' => 'ai', 'table' => 'ai_settings', 'primary_key' => 'id', 'mode' => 'external_table'],
            'capabilities' => ['admin' => true, 'headless' => false, 'public' => false, 'localized' => false, 'revisions' => false, 'workflow' => false, 'seo' => false, 'export' => true],
            'permissions' => ['read' => 'ai.use', 'create' => 'ai.provider.manage', 'update' => 'ai.provider.manage', 'delete' => 'ai.provider.manage'],
            'headless' => ['enabled' => false],
            'admin' => ['route' => '/modules/ai-assistant/config', 'component' => 'AiAssistantConfigView', 'schema_driven' => false, 'title' => 'Assistant IA'],
            'sections' => [['key' => 'setting', 'label' => 'Paramètre', 'fields' => ['key', 'value_json', 'description', 'enabled']]],
            'fields' => [
                $this->field('key', 'Clé', 'text', 'setting', true, 'key', ['system' => true]),
                $this->field('value_json', 'Valeur JSON', 'json', 'setting', true, 'value_json'),
                $this->field('description', 'Description', 'textarea', 'setting', false, 'description'),
                $this->field('enabled', 'Actif', 'boolean', 'setting', false, 'enabled'),
            ],
            'export' => ['enabled' => true, 'formats' => ['json']],
        ];
    }


    private function providerTypeBlueprint(): array
    {
        return [
            'module' => 'ai-assistant',
            'resource' => 'provider_type',
            'blueprint_key' => 'ai_assistant_provider_type',
            'resource_type' => 'module_resource',
            'label' => 'Usage IA',
            'description' => 'Types d’usage IA configurables : éditorial, embeddings, re-ranking, transcription, vocal, image, développement, automatisation ou local.',
            'version' => 1,
            'storage' => ['database' => 'ai', 'table' => 'ai_provider_types', 'primary_key' => 'id', 'mode' => 'external_table'],
            'capabilities' => ['admin' => true, 'headless' => false, 'public' => false, 'localized' => false, 'revisions' => false, 'workflow' => false, 'seo' => false, 'export' => true],
            'permissions' => ['read' => 'ai.use', 'create' => 'ai.provider.manage', 'update' => 'ai.provider.manage', 'delete' => 'ai.provider.manage'],
            'headless' => ['enabled' => false],
            'admin' => ['route' => '/modules/ai-assistant/config', 'component' => 'AiAssistantConfigView', 'schema_driven' => false, 'title' => 'Types IA'],
            'sections' => [['key' => 'provider_type', 'label' => 'Type', 'fields' => ['key', 'name', 'description', 'sort_order', 'enabled']]],
            'fields' => [
                $this->field('key', 'Clé', 'text', 'provider_type', true, 'key', ['system' => true]),
                $this->field('name', 'Nom', 'text', 'provider_type', true, 'name'),
                $this->field('description', 'Description', 'textarea', 'provider_type', false, 'description'),
                $this->field('sort_order', 'Ordre', 'number', 'provider_type', false, 'sort_order'),
                $this->field('enabled', 'Actif', 'boolean', 'provider_type', false, 'enabled'),
            ],
            'relations' => [['resource' => 'provider', 'type' => 'has_many', 'foreign_key' => 'type_key']],
            'export' => ['enabled' => true, 'formats' => ['json']],
        ];
    }

    private function providerBlueprint(): array
    {
        return [
            'module' => 'ai-assistant',
            'resource' => 'provider',
            'blueprint_key' => 'ai_assistant_provider',
            'resource_type' => 'module_resource',
            'label' => 'Fournisseur IA',
            'description' => 'Fournisseurs IA configurables. Les secrets restent référencés par api_key_ref; les modèles sont gérés séparément.',
            'version' => 1,
            'storage' => ['database' => 'ai', 'table' => 'ai_providers', 'primary_key' => 'id', 'mode' => 'external_table'],
            'capabilities' => ['admin' => true, 'headless' => false, 'public' => false, 'localized' => false, 'revisions' => false, 'workflow' => false, 'seo' => false, 'export' => true],
            'permissions' => ['read' => 'ai.use', 'create' => 'ai.provider.manage', 'update' => 'ai.provider.manage', 'delete' => 'ai.provider.manage'],
            'headless' => ['enabled' => false],
            'admin' => ['route' => '/modules/ai-assistant/config', 'component' => 'AiAssistantConfigView', 'schema_driven' => false, 'title' => 'Providers IA'],
            'sections' => [['key' => 'provider', 'label' => 'Fournisseur', 'fields' => ['type_key', 'key', 'name', 'provider_type', 'base_url', 'api_key_ref', 'enabled', 'is_default']]],
            'fields' => [
                $this->field('type_key', 'Utilisation principale', 'select', 'provider', true, 'type_key'),
                $this->field('key', 'Clé', 'text', 'provider', true, 'key', ['system' => true]),
                $this->field('name', 'Nom', 'text', 'provider', true, 'name'),
                $this->field('provider_type', 'Type', 'select', 'provider', true, 'provider_type'),
                $this->field('base_url', 'Base URL', 'text', 'provider', false, 'base_url', ['help_text' => 'Endpoint technique du provider, par exemple https://api.openai.com/v1.']),
                $this->field('api_key_ref', 'Référence clé API', 'text', 'provider', false, 'api_key_ref', ['help_text' => 'Référence de secret uniquement, par exemple env:OPENAI_API_KEY. Ne jamais saisir la clé réelle dans le CMS.']),
                $this->field('enabled', 'Actif', 'boolean', 'provider', false, 'enabled'),
                $this->field('is_default', 'Par défaut', 'boolean', 'provider', false, 'is_default'),
            ],
            'relations' => [['resource' => 'model', 'type' => 'has_many', 'foreign_key' => 'provider_id']],
            'export' => ['enabled' => true, 'formats' => ['json']],
        ];
    }


    private function modelBlueprint(): array
    {
        return [
            'module' => 'ai-assistant',
            'resource' => 'model',
            'blueprint_key' => 'ai_assistant_model',
            'resource_type' => 'module_resource',
            'label' => 'Modèle IA',
            'description' => 'Catalogue des modèles IA. Un modèle peut être compatible avec plusieurs usages via ai_model_usages; la capacité technique reste déduite automatiquement.',
            'version' => 1,
            'storage' => ['database' => 'ai', 'table' => 'ai_models', 'primary_key' => 'id', 'mode' => 'external_table'],
            'capabilities' => ['admin' => true, 'headless' => false, 'public' => false, 'localized' => false, 'revisions' => false, 'workflow' => false, 'seo' => false, 'export' => true],
            'permissions' => ['read' => 'ai.use', 'create' => 'ai.provider.manage', 'update' => 'ai.provider.manage', 'delete' => 'ai.provider.manage'],
            'headless' => ['enabled' => false],
            'sections' => [['key' => 'model', 'label' => 'Modèle', 'fields' => ['provider_id', 'usage_keys', 'key', 'name', 'model_type', 'context_window', 'input_price', 'output_price', 'currency', 'api_key_ref', 'max_monthly_budget_json', 'enabled']]],
            'fields' => [
                $this->field('provider_id', 'Fournisseur', 'number', 'model', true, 'provider_id', ['system' => true, 'help_text' => 'Fournisseur IA auquel ce modèle est rattaché.']),
                $this->field('usage_keys', 'Usages compatibles', 'multiselect', 'model', false, 'usage_keys', ['virtual' => true, 'storage_table' => 'ai_model_usages', 'help_text' => 'Usages métier pour lesquels le modèle peut être proposé dans l’Assistant IA.']),
                $this->field('key', 'Clé du modèle', 'text', 'model', true, 'key'),
                $this->field('name', 'Nom', 'text', 'model', true, 'name'),
                $this->field('model_type', 'Capacité déduite', 'select', 'model', true, 'model_type', ['system' => true, 'help_text' => 'Champ technique déduit des usages compatibles et utilisé par les adaptateurs runtime.']),
                $this->field('context_window', 'Fenêtre de contexte', 'number', 'model', false, 'context_window'),
                $this->field('input_price', 'Prix input', 'number', 'model', false, 'input_price', ['help_text' => 'Prix indicatif des tokens entrants.']),
                $this->field('output_price', 'Prix output', 'number', 'model', false, 'output_price', ['help_text' => 'Prix indicatif des tokens sortants.']),
                $this->field('currency', 'Devise', 'text', 'model', false, 'currency', ['help_text' => 'Devise ISO utilisée pour les prix et le budget, par exemple CHF.']),
                $this->field('api_key_ref', 'Référence clé API spécifique', 'text', 'model', false, 'api_key_ref', ['help_text' => 'Optionnel : laisser vide pour hériter du fournisseur, ou indiquer une autre référence env:... dédiée à ce modèle.']),
                $this->field('max_monthly_budget_json', 'Budget mensuel', 'json', 'model', false, 'max_monthly_budget_json', ['help_text' => 'Budget mensuel indicatif du modèle. L’interface l’édite sous forme de montant et l’affiche avec la devise du modèle, par exemple USD 5.00.']),
                $this->field('enabled', 'Actif', 'boolean', 'model', false, 'enabled', ['help_text' => 'Active ou désactive ce modèle dans les sélections de l’Assistant IA.']),
            ],
            'relations' => [['resource' => 'provider', 'type' => 'belongs_to', 'foreign_key' => 'provider_id']],
            'export' => ['enabled' => true, 'formats' => ['json']],
        ];
    }

    private function promptBlueprint(): array
    {
        return [
            'module' => 'ai-assistant',
            'resource' => 'prompt',
            'blueprint_key' => 'ai_assistant_prompt',
            'resource_type' => 'module_resource',
            'label' => 'Prompt IA',
            'description' => 'Templates de prompts versionnés du module IA.',
            'version' => 1,
            'storage' => ['database' => 'ai', 'table' => 'ai_prompts', 'primary_key' => 'id', 'mode' => 'external_table'],
            'capabilities' => ['admin' => true, 'headless' => false, 'public' => false, 'localized' => true, 'revisions' => false, 'workflow' => false, 'seo' => false, 'export' => true],
            'permissions' => ['read' => 'ai.use', 'create' => 'ai.provider.manage', 'update' => 'ai.provider.manage', 'delete' => 'ai.provider.manage'],
            'headless' => ['enabled' => false],
            'sections' => [['key' => 'prompt', 'label' => 'Prompt', 'fields' => ['key', 'name', 'category', 'language', 'version', 'enabled']]],
            'fields' => [
                $this->field('key', 'Clé', 'text', 'prompt', true, 'key'),
                $this->field('name', 'Nom', 'text', 'prompt', true, 'name'),
                $this->field('category', 'Catégorie', 'select', 'prompt', true, 'category'),
                $this->field('language', 'Langue', 'text', 'prompt', false, 'language'),
                $this->field('version', 'Version', 'number', 'prompt', false, 'version'),
                $this->field('enabled', 'Actif', 'boolean', 'prompt', false, 'enabled'),
            ],
            'export' => ['enabled' => true, 'formats' => ['json']],
        ];
    }


    private function siteSettingBlueprint(): array
    {
        return [
            'module' => 'ai-assistant',
            'resource' => 'site_setting',
            'blueprint_key' => 'ai_assistant_site_setting',
            'resource_type' => 'module_resource',
            'label' => 'Usage IA par site',
            'description' => 'Gouvernance IA par site courant et par usage : activation, héritage, modèle choisi, SEO et traduction.',
            'version' => 1,
            'storage' => ['database' => 'ai', 'table' => 'ai_site_usage_settings', 'primary_key' => 'id', 'mode' => 'external_table'],
            'capabilities' => ['admin' => true, 'headless' => false, 'public' => false, 'localized' => false, 'revisions' => false, 'workflow' => false, 'seo' => false, 'export' => true],
            'permissions' => ['read' => 'ai.use', 'create' => 'ai.provider.manage', 'update' => 'ai.provider.manage', 'delete' => 'ai.provider.manage'],
            'headless' => ['enabled' => false],
            'sections' => [['key' => 'scope', 'label' => 'Usage', 'fields' => ['site_id', 'usage_key', 'mode', 'model_id', 'provider_key', 'model_key', 'translation_enabled', 'seo_enabled']]],
            'fields' => [
                $this->field('site_id', 'Site', 'number', 'scope', true, 'site_id', ['system' => true]),
                $this->field('usage_key', 'Usage', 'select', 'scope', true, 'usage_key'),
                $this->field('mode', 'Mode', 'select', 'scope', true, 'mode'),
                $this->field('model_id', 'Modèle', 'number', 'scope', false, 'model_id'),
                $this->field('provider_key', 'Fournisseur résolu', 'text', 'scope', false, 'provider_key'),
                $this->field('model_key', 'Modèle', 'text', 'scope', false, 'model_key'),
                $this->field('translation_enabled', 'Traductions IA', 'boolean', 'scope', false, 'translation_enabled'),
                $this->field('seo_enabled', 'SEO IA', 'boolean', 'scope', false, 'seo_enabled'),
            ],
            'export' => ['enabled' => true, 'formats' => ['json']],
        ];
    }

    private function suggestionBlueprint(): array
    {
        return [
            'module' => 'ai-assistant',
            'resource' => 'suggestion',
            'blueprint_key' => 'ai_assistant_suggestion',
            'resource_type' => 'module_resource',
            'label' => 'Suggestion IA',
            'description' => 'Suggestions IA proposées, acceptées, rejetées ou appliquées via action officielle.',
            'version' => 1,
            'storage' => ['database' => 'ai', 'table' => 'ai_suggestions', 'primary_key' => 'id', 'mode' => 'external_table'],
            'capabilities' => ['admin' => true, 'headless' => false, 'public' => false, 'localized' => false, 'revisions' => false, 'workflow' => false, 'seo' => false, 'export' => true],
            'permissions' => ['read' => 'ai.suggestions.read', 'create' => 'ai.suggestions.manage', 'update' => 'ai.suggestions.manage', 'delete' => 'ai.suggestions.manage'],
            'headless' => ['enabled' => false],
            'sections' => [['key' => 'suggestion', 'label' => 'Suggestion', 'fields' => ['title', 'target_type', 'target_id', 'suggestion_type', 'status', 'preview_text', 'applied_action_run_id']]],
            'fields' => [
                $this->field('title', 'Titre', 'text', 'suggestion', true, 'title', ['system' => true]),
                $this->field('target_type', 'Type cible', 'text', 'suggestion', true, 'target_type'),
                $this->field('target_id', 'ID cible', 'text', 'suggestion', false, 'target_id'),
                $this->field('suggestion_type', 'Type de suggestion', 'select', 'suggestion', true, 'suggestion_type'),
                $this->field('status', 'Statut', 'select', 'suggestion', true, 'status', ['system' => true, 'options' => ['draft', 'proposed', 'accepted', 'rejected', 'applied', 'expired']]),
                $this->field('preview_text', 'Aperçu', 'textarea', 'suggestion', false, 'preview_text'),
                $this->field('applied_action_run_id', 'Action d’application', 'number', 'suggestion', false, 'applied_action_run_id', ['system' => true, 'help_text' => 'Identifiant de l’action qui a créé une révision ou un brouillon. L’application directe en production est désactivée.']),
            ],
            'export' => ['enabled' => true, 'formats' => ['json']],
        ];
    }

    private function taskBlueprint(): array
    {
        return [
            'module' => 'ai-assistant',
            'resource' => 'task',
            'blueprint_key' => 'ai_assistant_task',
            'resource_type' => 'module_resource',
            'label' => 'Tâche IA',
            'description' => 'File d’attente IA SQLite pour les actions longues : tests, génération depuis prompt, traduction, SEO, embeddings.',
            'version' => 1,
            'storage' => ['database' => 'ai', 'table' => 'ai_tasks', 'primary_key' => 'id', 'mode' => 'external_table'],
            'capabilities' => ['admin' => true, 'headless' => false, 'public' => false, 'localized' => false, 'revisions' => false, 'workflow' => false, 'seo' => false, 'export' => true],
            'permissions' => ['read' => 'ai.use', 'create' => 'ai.use', 'update' => 'ai.provider.manage', 'delete' => 'ai.provider.manage'],
            'headless' => ['enabled' => false],
            'admin' => ['route' => '/modules/ai-assistant/config', 'component' => 'AiAssistantConfigView', 'schema_driven' => false, 'title' => 'Tâches IA'],
            'sections' => [['key' => 'task', 'label' => 'Tâche', 'fields' => ['title', 'task_type', 'status', 'payload_json', 'target_type', 'target_id', 'error_message']]],
            'fields' => [
                $this->field('title', 'Titre', 'text', 'task', true, 'title', ['system' => true]),
                $this->field('task_type', 'Type de tâche', 'text', 'task', true, 'task_type'),
                $this->field('status', 'Statut', 'select', 'task', true, 'status', ['system' => true, 'options' => ['pending', 'running', 'completed', 'failed', 'cancelled']]),
                $this->field('payload_json', 'Payload JSON', 'json', 'task', false, 'payload_json'),
                $this->field('target_type', 'Type cible', 'text', 'task', false, 'target_type'),
                $this->field('target_id', 'ID cible', 'text', 'task', false, 'target_id'),
                $this->field('error_message', 'Erreur', 'textarea', 'task', false, 'error_message'),
            ],
            'export' => ['enabled' => true, 'formats' => ['json']],
        ];
    }

    private function usageEventBlueprint(): array
    {
        return [
            'module' => 'ai-assistant',
            'resource' => 'usage_event',
            'blueprint_key' => 'ai_assistant_usage_event',
            'resource_type' => 'module_resource',
            'label' => 'Événement usage IA',
            'description' => 'Journal d’usage technique : provider, modèle, tokens, durée et coût estimé.',
            'version' => 1,
            'storage' => ['database' => 'ai', 'table' => 'ai_usage_events', 'primary_key' => 'id', 'mode' => 'external_table'],
            'capabilities' => ['admin' => true, 'headless' => false, 'public' => false, 'localized' => false, 'revisions' => false, 'workflow' => false, 'seo' => false, 'export' => true],
            'permissions' => ['read' => 'ai.logs.read'],
            'headless' => ['enabled' => false],
            'sections' => [['key' => 'usage', 'label' => 'Usage', 'fields' => ['provider_key', 'model_key', 'task_type', 'total_tokens', 'duration_ms', 'estimated_cost', 'details_json']]],
            'fields' => [
                $this->field('provider_key', 'Fournisseur', 'text', 'usage', true, 'provider_key', ['system' => true]),
                $this->field('model_key', 'Modèle', 'text', 'usage', true, 'model_key', ['system' => true]),
                $this->field('task_type', 'Tâche', 'text', 'usage', true, 'task_type', ['system' => true]),
                $this->field('total_tokens', 'Tokens', 'number', 'usage', false, 'total_tokens', ['system' => true]),
                $this->field('duration_ms', 'Durée ms', 'number', 'usage', false, 'duration_ms', ['system' => true]),
                $this->field('estimated_cost', 'Coût estimé', 'number', 'usage', false, 'estimated_cost', ['system' => true]),
                $this->field('details_json', 'Détails diagnostic', 'json', 'usage', false, 'details_json', ['system' => true]),
            ],
            'export' => ['enabled' => true, 'formats' => ['json']],
        ];
    }

    /** @return array<string,mixed> */
    private function field(string $key, string $label, string $type, string $section, bool $required, string $column, array $options = []): array
    {
        $system = (bool) ($options['system'] ?? false);
        return [
            'key' => $key,
            'field_key' => $key,
            'label' => $label,
            'field_type' => $type,
            'type' => $type,
            'section' => $section,
            'tab_key' => $section,
            'is_required' => $required,
            'required' => $required,
            'is_localized' => false,
            'column' => $column,
            'is_system' => $system,
            'is_deletable' => !$system,
            'field_scope' => $system ? 'system_context' : 'configuration',
            'ui_visibility' => $system ? 'summary' : 'form',
            'help_text' => trim((string) ($options['help_text'] ?? '')),
            'validation' => $required ? ['required' => true] : [],
        ];
    }

    public function apiContracts(): array
    {
        return [
            ['key' => 'admin.ai.status.v1', 'version' => '1', 'scope' => 'admin', 'method' => 'GET', 'path' => '/admin/api/ai/status'],
            ['key' => 'admin.ai.settings.v1', 'version' => '1', 'scope' => 'admin', 'method' => 'GET/POST', 'path' => '/admin/api/ai/settings'],
            ['key' => 'admin.ai.site_settings.v1', 'version' => '1', 'scope' => 'admin', 'method' => 'GET/POST', 'path' => '/admin/api/ai/site-settings'],
            ['key' => 'admin.ai.provider_types.v1', 'version' => '1', 'scope' => 'admin', 'method' => 'POST/DELETE', 'path' => '/admin/api/ai/provider-types'],
            ['key' => 'admin.ai.providers.v1', 'version' => '1', 'scope' => 'admin', 'method' => 'GET/POST/DELETE', 'path' => '/admin/api/ai/providers'],
            ['key' => 'admin.ai.provider_site_scopes.v1', 'version' => '1', 'scope' => 'admin', 'method' => 'GET/POST', 'path' => '/admin/api/ai/site-settings'],
            ['key' => 'admin.ai.models.v1', 'version' => '1', 'scope' => 'admin', 'method' => 'POST/DELETE', 'path' => '/admin/api/ai/models'],
            ['key' => 'admin.ai.prompts.v1', 'version' => '1', 'scope' => 'admin', 'method' => 'GET', 'path' => '/admin/api/ai/prompts'],
            ['key' => 'admin.ai.suggestions.v1', 'version' => '1', 'scope' => 'admin', 'method' => 'GET/POST', 'path' => '/admin/api/ai/suggestions', 'description' => 'Liste, proposition, acceptation, rejet, application contrôlée et expiration des suggestions IA.'],
            ['key' => 'admin.ai.editorial_test_generation.v1', 'version' => '1', 'scope' => 'admin', 'method' => 'POST', 'path' => '/admin/api/ai/editorial/test-generation'],
            ['key' => 'admin.ai.providers.test.stream.v1', 'version' => '1', 'scope' => 'admin', 'method' => 'GET', 'path' => '/admin/api/ai/providers/test/stream'],
            ['key' => 'admin.ai.usage.v1', 'version' => '1', 'scope' => 'admin', 'method' => 'GET/DELETE', 'path' => '/admin/api/ai/usage'],
            ['key' => 'admin.ai.budget.v1', 'version' => '1', 'scope' => 'admin', 'method' => 'GET', 'path' => '/admin/api/ai/budget'],
            ['key' => 'admin.ai.tasks.create.v1', 'version' => '1', 'scope' => 'admin', 'method' => 'POST', 'path' => '/admin/api/ai/tasks'],
            ['key' => 'admin.ai.tasks.read.v1', 'version' => '1', 'scope' => 'admin', 'method' => 'GET', 'path' => '/admin/api/ai/tasks/{id}'],
            ['key' => 'admin.ai.tasks.cancel.v1', 'version' => '1', 'scope' => 'admin', 'method' => 'POST', 'path' => '/admin/api/ai/tasks/{id}/cancel'],
        ];
    }
}
