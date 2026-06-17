<?php

declare(strict_types=1);

namespace App\Modules\Forms;

use App\Module\ModuleProvider;

/**
 * Module système Forms.
 *
 * Le bloc éditorial `form` reste un bloc natif de l'éditeur. Ce provider porte
 * la gouvernance métier des formulaires : base SQLite séparée, permissions,
 * navigation admin, ressources blueprint-ready et contrats API existants.
 */
final class FormsModuleProvider implements ModuleProvider
{
    public function key(): string
    {
        return 'forms';
    }

    public function name(): string
    {
        return 'Formulaires';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function description(): string
    {
        return 'Gestion des formulaires, champs, soumissions, anti-spam, notifications et exports CSV dans une base SQLite dédiée.';
    }

    public function dependencies(): array
    {
        return [];
    }

    public function databases(): array
    {
        return [
            [
                'key' => 'forms',
                'driver' => 'sqlite',
                'path' => 'storage/database/forms.sqlite',
                'schema' => 'database/modules/forms.sql',
                'required' => true,
            ],
        ];
    }

    public function permissions(): array
    {
        return [
            ['key' => 'forms.read', 'name' => 'Lire les formulaires', 'description' => 'Lire les formulaires, leurs champs, soumissions et exports.'],
            ['key' => 'forms.manage', 'name' => 'Gérer les formulaires', 'description' => 'Créer, modifier, supprimer et configurer les formulaires.'],
        ];
    }

    public function settingsSchema(): array
    {
        return [
            'schema_version' => 1,
            'settings' => [
                'default_honeypot_field' => ['type' => 'string', 'default' => 'website'],
                'default_min_submit_seconds' => ['type' => 'integer', 'default' => 2],
                'default_rate_limit_max_attempts' => ['type' => 'integer', 'default' => 5],
                'default_rate_limit_window_seconds' => ['type' => 'integer', 'default' => 900],
            ],
        ];
    }

    public function blueprints(): array
    {
        return [
            $this->formBlueprint(),
            $this->formFieldBlueprint(),
            $this->formSubmissionBlueprint(),
        ];
    }

    public function contentTypes(): array
    {
        return [];
    }

    public function adminNavigation(): array
    {
        return [
            [
                'key' => 'forms.manage',
                'label' => 'Formulaires',
                'navLabel' => 'Formulaires',
                'route' => '/forms',
                'permission' => 'forms.read',
                'section' => 'Modules',
                'hint' => 'Créer les formulaires, gérer les champs, consulter les soumissions et exporter les données.',
                'sort_order' => 120,
            ],
        ];
    }

    public function adminRoutes(): array
    {
        // Les routes administratives contractuelles restent déclarées dans
        // backend/routes/api.php, source canonique vérifiée par c10.
        return [];
    }

    public function apiRoutes(): array
    {
        return [];
    }

    public function publicHeadlessRoutes(): array
    {
        return [
            ['GET', '/api/v1/modules/forms/forms/schema', 'App\\Application\\Api\\ModuleHeadlessSchemaController@schema'],
        ];
    }

    public function hooks(): array
    {
        return [];
    }

    public function migrations(): array
    {
        return [];
    }

    public function seeds(): array
    {
        return [];
    }

    public function apiContracts(): array
    {
        return [
            ['key' => 'admin.forms.index.v1', 'version' => '1', 'scope' => 'admin', 'method' => 'GET', 'path' => '/admin/api/forms'],
            ['key' => 'admin.forms.show.v1', 'version' => '1', 'scope' => 'admin', 'method' => 'GET', 'path' => '/admin/api/forms/{id}'],
            ['key' => 'admin.forms.write.v1', 'version' => '1', 'scope' => 'admin', 'method' => 'POST/PATCH', 'path' => '/admin/api/forms'],
            ['key' => 'admin.forms.submissions.index.v1', 'version' => '1', 'scope' => 'admin', 'method' => 'GET', 'path' => '/admin/api/forms/{id}/submissions'],
            ['key' => 'public.forms.show.v1', 'version' => '1', 'scope' => 'public', 'method' => 'GET', 'path' => '/api/v1/forms/{key}'],
            ['key' => 'public.forms.submit.v1', 'version' => '1', 'scope' => 'public', 'method' => 'POST', 'path' => '/api/v1/forms/{key}/submit'],
        ];
    }

    private function formBlueprint(): array
    {
        return [
            'module' => 'forms',
            'resource' => 'form',
            'blueprint_key' => 'forms_form',
            'resource_type' => 'module_resource',
            'label' => 'Formulaire',
            'description' => 'Créer les formulaires, gérer les champs, consulter les soumissions et exporter les données.',
            'version' => 1,
            'storage' => ['database' => 'forms', 'table' => 'forms', 'primary_key' => 'id', 'mode' => 'external_table'],
            'capabilities' => ['admin' => true, 'headless' => true, 'public' => false, 'localized' => true, 'revisions' => false, 'workflow' => false, 'seo' => false, 'export' => true],
            'permissions' => ['read' => 'forms.read', 'create' => 'forms.manage', 'update' => 'forms.manage', 'delete' => 'forms.manage'],
            'headless' => ['enabled' => true, 'public' => false, 'collection_endpoint' => '/api/v1/modules/forms/forms', 'item_endpoint' => '/api/v1/modules/forms/forms/{id}', 'schema_endpoint' => '/api/v1/modules/forms/forms/schema'],
            'admin' => ['route' => '/forms', 'component' => 'FormsView', 'schema_driven' => false, 'title' => 'Formulaires'],
            'sections' => [
                ['key' => 'identity', 'label' => 'Identité', 'fields' => ['title', 'form_key', 'status', 'is_active']],
                ['key' => 'security', 'label' => 'Sécurité', 'fields' => ['honeypot_field', 'min_submit_seconds', 'rate_limit_max_attempts']],
                ['key' => 'notifications', 'label' => 'Notifications', 'fields' => ['notification_enabled', 'notification_recipients_json']],
            ],
            'fields' => [
                $this->field('title', 'Titre', 'text', 'identity', true, 'name', ['system' => true]),
                $this->field('form_key', 'Clé formulaire', 'text', 'identity', true, 'form_key'),
                $this->field('status', 'Statut', 'select', 'identity', true, 'status', ['system' => true]),
                $this->field('is_active', 'Actif', 'boolean', 'identity', false, 'is_active'),
                $this->field('honeypot_field', 'Champ honeypot', 'text', 'security', false, 'honeypot_field'),
                $this->field('min_submit_seconds', 'Temps minimal avant envoi', 'number', 'security', false, 'min_submit_seconds'),
                $this->field('rate_limit_max_attempts', 'Tentatives max.', 'number', 'security', false, 'rate_limit_max_attempts'),
                $this->field('notification_enabled', 'Notifications actives', 'boolean', 'notifications', false, 'notification_enabled'),
                $this->field('notification_recipients_json', 'Destinataires', 'json', 'notifications', false, 'notification_recipients_json'),
            ],
            'relations' => [
                ['resource' => 'form_field', 'type' => 'has_many', 'foreign_key' => 'form_id'],
                ['resource' => 'form_submission', 'type' => 'has_many', 'foreign_key' => 'form_id'],
            ],
            'export' => ['enabled' => true, 'formats' => ['json', 'csv']],
        ];
    }

    private function formFieldBlueprint(): array
    {
        return [
            'module' => 'forms',
            'resource' => 'form_field',
            'blueprint_key' => 'forms_form_field',
            'resource_type' => 'module_resource',
            'label' => 'Champ de formulaire',
            'description' => 'Champ administrable d’un formulaire : type, validation, traduction et options.',
            'version' => 1,
            'storage' => ['database' => 'forms', 'table' => 'form_fields', 'primary_key' => 'id', 'mode' => 'external_table'],
            'capabilities' => ['admin' => true, 'headless' => true, 'public' => false, 'localized' => true, 'revisions' => false, 'workflow' => false, 'seo' => false, 'export' => true],
            'permissions' => ['read' => 'forms.read', 'create' => 'forms.manage', 'update' => 'forms.manage', 'delete' => 'forms.manage'],
            'headless' => ['enabled' => true, 'public' => false, 'collection_endpoint' => '/api/v1/modules/forms/form-fields', 'item_endpoint' => '/api/v1/modules/forms/form-fields/{id}'],
            'sections' => [['key' => 'field', 'label' => 'Champ', 'fields' => ['title', 'field_key', 'field_type', 'is_required', 'sort_order']]],
            'fields' => [
                $this->field('title', 'Titre', 'text', 'field', true, 'field_key', ['system' => true]),
                $this->field('field_key', 'Clé champ', 'text', 'field', true, 'field_key'),
                $this->field('field_type', 'Type', 'select', 'field', true, 'field_type'),
                $this->field('is_required', 'Obligatoire', 'boolean', 'field', false, 'is_required'),
                $this->field('sort_order', 'Ordre', 'number', 'field', false, 'sort_order', ['system' => true]),
            ],
            'relations' => [['resource' => 'form', 'type' => 'belongs_to', 'foreign_key' => 'form_id']],
            'export' => ['enabled' => true, 'formats' => ['json']],
        ];
    }

    private function formSubmissionBlueprint(): array
    {
        return [
            'module' => 'forms',
            'resource' => 'form_submission',
            'blueprint_key' => 'forms_form_submission',
            'resource_type' => 'module_resource',
            'label' => 'Soumission de formulaire',
            'description' => 'Soumission reçue depuis un formulaire public, stockée dans forms.sqlite et exportable.',
            'version' => 1,
            'storage' => ['database' => 'forms', 'table' => 'form_submissions', 'primary_key' => 'id', 'mode' => 'external_table'],
            'capabilities' => ['admin' => true, 'headless' => false, 'public' => false, 'localized' => false, 'revisions' => false, 'workflow' => false, 'seo' => false, 'export' => true],
            'permissions' => ['read' => 'forms.read', 'delete' => 'forms.manage'],
            'headless' => ['enabled' => false],
            'sections' => [['key' => 'submission', 'label' => 'Soumission', 'fields' => ['title', 'submission_status', 'created_at', 'payload_json']]],
            'fields' => [
                $this->field('title', 'Titre', 'text', 'submission', true, 'id', ['system' => true]),
                $this->field('submission_status', 'Statut', 'select', 'submission', true, 'submission_status', ['system' => true]),
                $this->field('created_at', 'Reçue le', 'datetime', 'submission', false, 'created_at', ['system' => true]),
                $this->field('payload_json', 'Données', 'json', 'submission', true, 'payload_json'),
            ],
            'relations' => [['resource' => 'form', 'type' => 'belongs_to', 'foreign_key' => 'form_id']],
            'export' => ['enabled' => true, 'formats' => ['json', 'csv']],
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
            'field_scope' => $system ? 'system_context' : 'editorial',
            'ui_visibility' => $system ? 'summary' : 'form',
            'validation' => $required ? ['required' => true] : [],
        ];
    }
}
