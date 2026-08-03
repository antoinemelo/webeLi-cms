<?php

declare(strict_types=1);

namespace App\Modules\Accounting;

use App\Module\ModuleProvider;

final class AccountingModuleProvider implements ModuleProvider
{
    public function key(): string { return 'accounting'; }
    public function name(): string { return 'Comptabilité'; }
    public function version(): string { return '0.1.0'; }
    public function description(): string
    {
        return 'Plan comptable configurable, rubriques par préfixe, sens débit/crédit et soldes d’ouverture.';
    }
    public function dependencies(): array { return []; }
    public function databases(): array
    {
        return [[
            'key' => 'accounting',
            'driver' => 'sqlite',
            'path' => 'storage/database/accounting.sqlite',
            'schema' => 'database/modules/accounting.sql',
            'required' => true,
        ]];
    }
    public function permissions(): array
    {
        return [
            ['key' => 'accounting.read', 'name' => 'Lire la comptabilité', 'description' => 'Consulter le plan comptable et les soldes d’ouverture.'],
            ['key' => 'accounting.chart.manage', 'name' => 'Gérer le plan comptable', 'description' => 'Modifier les règles, rubriques et comptes.'],
            ['key' => 'accounting.opening.manage', 'name' => 'Gérer les ouvertures', 'description' => 'Créer les exercices et modifier les soldes d’ouverture.'],
        ];
    }
    public function settingsSchema(): array
    {
        return [
            'schema_version' => 1,
            'database' => ['key' => 'accounting', 'path' => 'storage/database/accounting.sqlite'],
            'defaults' => ['currency' => 'CHF', 'increase_side' => 'debit', 'credit_increase_prefixes' => ['2', '3']],
        ];
    }
    public function blueprints(): array
    {
        return [
            $this->blueprint('account', 'Compte', 'Compte du plan comptable.', 'accounting_accounts', 'accounting.chart.manage'),
            $this->blueprint('category', 'Rubrique comptable', 'Regroupement hiérarchique par préfixe.', 'accounting_categories', 'accounting.chart.manage'),
            $this->blueprint('opening_balance', 'Solde d’ouverture', 'Montant initial d’un compte pour un exercice.', 'accounting_opening_balances', 'accounting.opening.manage'),
        ];
    }
    public function contentTypes(): array { return []; }
    public function adminNavigation(): array
    {
        return [[
            'key' => 'accounting',
            'label' => 'Comptabilité',
            'route' => '/accounting',
            'anyPermission' => ['accounting.read', 'accounting.chart.manage', 'accounting.opening.manage'],
            'section' => 'Modules',
            'hint' => 'Définir les règles débit/crédit, rubriques, comptes et montants d’ouverture.',
            'sort_order' => 175,
            'children' => [
                ['key' => 'accounting.chart', 'label' => 'Plan comptable', 'route' => '/accounting', 'permission' => 'accounting.read'],
                ['key' => 'accounting.opening', 'label' => 'Soldes d’ouverture', 'route' => '/accounting/opening', 'permission' => 'accounting.read'],
            ],
        ]];
    }
    public function adminRoutes(): array
    {
        $c = 'App\\Application\\Api\\Admin\\AccountingAdminApiController@';
        return [
            ['GET', '/admin/api/accounting/structure', $c . 'structure'],
            ['POST', '/admin/api/accounting/rules', $c . 'storeRule'],
            ['PATCH', '/admin/api/accounting/rules/{id}', $c . 'updateRule'],
            ['DELETE', '/admin/api/accounting/rules/{id}', $c . 'deleteRule'],
            ['POST', '/admin/api/accounting/categories', $c . 'storeCategory'],
            ['PATCH', '/admin/api/accounting/categories/{id}', $c . 'updateCategory'],
            ['DELETE', '/admin/api/accounting/categories/{id}', $c . 'deleteCategory'],
            ['POST', '/admin/api/accounting/accounts', $c . 'storeAccount'],
            ['PATCH', '/admin/api/accounting/accounts/{id}', $c . 'updateAccount'],
            ['DELETE', '/admin/api/accounting/accounts/{id}', $c . 'deleteAccount'],
            ['POST', '/admin/api/accounting/fiscal-periods', $c . 'storeFiscalPeriod'],
            ['PUT', '/admin/api/accounting/fiscal-periods/{id}/opening-balances', $c . 'saveOpeningBalances'],
        ];
    }
    public function apiRoutes(): array { return []; }
    public function publicHeadlessRoutes(): array { return []; }
    public function hooks(): array { return []; }
    public function migrations(): array { return []; }
    public function seeds(): array { return []; }
    public function apiContracts(): array
    {
        return [[
            'key' => 'accounting.chart.v1',
            'version' => '1',
            'scope' => 'admin',
            'module' => 'accounting',
            'base_path' => '/admin/api/accounting',
            'permission' => 'accounting.read',
        ]];
    }

    private function blueprint(string $resource, string $label, string $description, string $table, string $managePermission): array
    {
        return [
            'module' => 'accounting',
            'resource' => $resource,
            'blueprint_key' => 'accounting_' . $resource,
            'resource_type' => 'module_resource',
            'label' => $label,
            'description' => $description,
            'version' => 1,
            'storage' => ['database' => 'accounting', 'table' => $table, 'primary_key' => 'id', 'mode' => 'external_table'],
            'capabilities' => ['admin' => true, 'headless' => false, 'public' => false, 'localized' => false, 'revisions' => false, 'workflow' => false, 'seo' => false, 'export' => true],
            'permissions' => ['read' => 'accounting.read', 'create' => $managePermission, 'update' => $managePermission, 'delete' => $managePermission],
            'headless' => ['enabled' => false, 'public' => false],
            'admin' => ['route' => '/accounting', 'component' => 'AccountingView', 'schema_driven' => false, 'title' => 'Comptabilité'],
            'sections' => [],
            'fields' => [],
            'export' => ['enabled' => true, 'formats' => ['json', 'csv']],
        ];
    }
}
