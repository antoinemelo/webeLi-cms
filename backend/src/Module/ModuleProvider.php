<?php

declare(strict_types=1);

namespace App\Module;

/**
 * Contrat applicatif d'un module DEC CMS.
 *
 * Le provider reste déclaratif : il ne modifie pas directement le noyau.
 * Le cycle de vie, les routes, les permissions, les bases SQLite dédiées,
 * les blueprints et les contrats API sont branchés par les services du core.
 */
interface ModuleProvider
{
    public function key(): string;

    public function name(): string;

    public function version(): string;

    public function description(): string;

    /** @return list<string> */
    public function dependencies(): array;

    /**
     * Bases SQLite requises par le module.
     *
     * Format recommandé :
     * [
     *   ['key' => 'crm', 'path' => 'storage/database/crm.sqlite', 'schema' => 'database/modules/crm.sql'],
     * ]
     *
     * @return list<array<string,mixed>>
     */
    public function databases(): array;

    /**
     * Permissions IAM déclarées par le module.
     *
     * Format : [['key' => 'crm.read', 'name' => 'Lire le CRM', 'description' => '...']]
     *
     * @return list<array<string,string>>
     */
    public function permissions(): array;

    /** @return array<string,mixed> */
    public function settingsSchema(): array;

    /**
     * Blueprints déclarés par le module.
     *
     * Pour une ressource métier, utiliser le contrat canonique :
     * [
     *   'module' => 'crm',
     *   'resource' => 'contact',
     *   'blueprint_key' => 'crm_contact',
     *   'resource_type' => 'module_resource',
     *   'storage' => ['database' => 'crm', 'table' => 'crm_contacts', 'primary_key' => 'id'],
     *   'capabilities' => ['admin' => true, 'headless' => true, 'public' => false],
     *   'permissions' => ['read' => 'crm.read', 'create' => 'crm.manage', 'update' => 'crm.manage', 'delete' => 'crm.manage'],
     *   'headless' => ['enabled' => true, 'collection_endpoint' => '/api/v1/modules/crm/contacts'],
     *   'fields' => [],
     * ]
     *
     * @return list<array<string,mixed>>
     */
    public function blueprints(): array;

    /** @return list<array<string,mixed>> */
    public function contentTypes(): array;

    /** @return list<array<string,mixed>> */
    public function adminNavigation(): array;

    /** @return list<array{0:string,1:string,2:string}> */
    public function adminRoutes(): array;

    /**
     * Routes API internes/admin historiques ou techniques.
     * Pour les routes publiques headless v1, préférer publicHeadlessRoutes().
     *
     * @return list<array{0:string,1:string,2:string}>
     */
    public function apiRoutes(): array;

    /** @return list<array{0:string,1:string,2:string}> */
    public function publicHeadlessRoutes(): array;

    /** @return array<string,list<callable|string>> */
    public function hooks(): array;

    /**
     * Migrations SQL déclaratives du module.
     * Chaque item peut cibler core ou une base module déclarée par databases().
     * Format simple compatible : ['001_init.sql' => 'CREATE TABLE ...'].
     * Format avancé : [['name'=>'001_init', 'database'=>'crm', 'sql'=>'...']]
     *
     * @return array<string,mixed>|list<array<string,mixed>>
     */
    public function migrations(): array;

    /**
     * Seeds non destructifs du module.
     * Format identique à migrations().
     *
     * @return array<string,mixed>|list<array<string,mixed>>
     */
    public function seeds(): array;

    /** @return list<array<string,mixed>> */
    public function apiContracts(): array;
}
