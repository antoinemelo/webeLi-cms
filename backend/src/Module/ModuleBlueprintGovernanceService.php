<?php

declare(strict_types=1);

namespace App\Module;

use App\Core\Database;
use App\Core\Logger;

final class ModuleBlueprintGovernanceService
{
    public const RESOURCE_TYPES = ['content_type', 'block', 'taxonomy', 'media', 'system', 'module_resource', 'headless'];

    public function __construct(
        private readonly Database $coreDb,
        private readonly Database $iamDb,
        private readonly ModuleRegistry $registry,
        private readonly ModuleDatabaseManager $databases,
        private readonly ?Logger $logger = null,
    ) {}

    /** @return list<array<string,mixed>> */
    public function moduleBlueprints(string $moduleKey, bool $activeOnly = true): array
    {
        $provider = $this->registry->get($moduleKey);
        if (!$provider) {
            throw new \InvalidArgumentException('Module inconnu: ' . $moduleKey);
        }
        if ($activeOnly && !$this->registry->isEnabled($moduleKey)) {
            return [];
        }

        return array_map(fn(array $blueprint): array => $this->normalize($provider, $blueprint), $provider->blueprints());
    }

    /** @return array<string,mixed> */
    public function resourceSchema(string $moduleKey, string $resource, bool $activeOnly = true): array
    {
        $resource = $this->safeKey($resource);
        foreach ($this->moduleBlueprints($moduleKey, $activeOnly) as $blueprint) {
            if (($blueprint['resource'] ?? '') === $resource || ($blueprint['blueprint_key'] ?? '') === $resource) {
                return $blueprint;
            }
        }
        throw new \RuntimeException('Ressource module introuvable: ' . $moduleKey . '/' . $resource);
    }

    public function isHeadlessPublic(string $moduleKey, string $resource): bool
    {
        $schema = $this->resourceSchema($moduleKey, $resource, true);
        $headless = is_array($schema['headless'] ?? null) ? $schema['headless'] : [];
        $capabilities = is_array($schema['capabilities'] ?? null) ? $schema['capabilities'] : [];
        return (bool) ($headless['enabled'] ?? $capabilities['headless'] ?? false)
            && (bool) ($headless['public'] ?? $capabilities['public'] ?? false);
    }

    public function sync(ModuleProvider $provider, bool $published): void
    {
        if (!$this->coreDb->tableExists('module_blueprints')) {
            return;
        }
        foreach ($provider->blueprints() as $raw) {
            $blueprint = $this->normalize($provider, $raw);
            $errors = $this->validate($provider, $blueprint);
            $this->coreDb->run(
                'INSERT INTO module_blueprints(
                    module_key, resource, blueprint_key, resource_type, declared_version,
                    storage_json, capabilities_json, permissions_json, headless_json, admin_schema_json,
                    relations_json, export_policy_json, contract_json, validation_errors_json, is_published, updated_at
                 ) VALUES(
                    :module_key, :resource, :blueprint_key, :resource_type, :declared_version,
                    :storage_json, :capabilities_json, :permissions_json, :headless_json, :admin_schema_json,
                    :relations_json, :export_policy_json, :contract_json, :validation_errors_json, :is_published, :updated_at
                 ) ON CONFLICT(module_key, blueprint_key, resource_type) DO UPDATE SET
                    resource = excluded.resource,
                    declared_version = excluded.declared_version,
                    storage_json = excluded.storage_json,
                    capabilities_json = excluded.capabilities_json,
                    permissions_json = excluded.permissions_json,
                    headless_json = excluded.headless_json,
                    admin_schema_json = excluded.admin_schema_json,
                    relations_json = excluded.relations_json,
                    export_policy_json = excluded.export_policy_json,
                    contract_json = excluded.contract_json,
                    validation_errors_json = excluded.validation_errors_json,
                    is_published = excluded.is_published,
                    updated_at = excluded.updated_at',
                [
                    'module_key' => $provider->key(),
                    'resource' => $blueprint['resource'],
                    'blueprint_key' => $blueprint['blueprint_key'],
                    'resource_type' => $blueprint['resource_type'],
                    'declared_version' => (int) $blueprint['version'],
                    'storage_json' => $this->json($blueprint['storage']),
                    'capabilities_json' => $this->json($blueprint['capabilities']),
                    'permissions_json' => $this->json($blueprint['permissions']),
                    'headless_json' => $this->json($blueprint['headless']),
                    'admin_schema_json' => $this->json($blueprint['admin']),
                    'relations_json' => $this->json($blueprint['relations']),
                    'export_policy_json' => $this->json($blueprint['export']),
                    'contract_json' => $this->json($blueprint['contract']),
                    'validation_errors_json' => $this->json($errors),
                    'is_published' => (int) ($published && $errors === []),
                    'updated_at' => $this->now(),
                ]
            );
        }
    }

    /** @return list<string> */
    public function validate(ModuleProvider $provider, array $blueprint): array
    {
        $errors = [];
        $key = (string) ($blueprint['blueprint_key'] ?? '');
        if ($key === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            $errors[] = 'blueprint_key invalide: ' . $key;
        }
        $resourceType = (string) ($blueprint['resource_type'] ?? '');
        if (!in_array($resourceType, self::RESOURCE_TYPES, true)) {
            $errors[] = 'resource_type non supporté: ' . $resourceType;
        }
        if ($resourceType === 'module_resource') {
            $storage = is_array($blueprint['storage'] ?? null) ? $blueprint['storage'] : [];
            foreach (['database', 'table', 'primary_key'] as $required) {
                if (trim((string) ($storage[$required] ?? '')) === '') {
                    $errors[] = 'storage.' . $required . ' obligatoire pour module_resource';
                }
            }
            $databaseKey = (string) ($storage['database'] ?? '');
            if ($databaseKey !== '' && !$this->providerDeclaresDatabase($provider, $databaseKey)) {
                $errors[] = 'base SQLite non déclarée par le module: ' . $databaseKey;
            }
        }

        foreach (($blueprint['permissions'] ?? []) as $action => $permissionKey) {
            $permissionKey = (string) $permissionKey;
            if ($permissionKey === '') {
                continue;
            }
            if (!$this->permissionExists($permissionKey) && !$this->providerDeclaresPermission($provider, $permissionKey)) {
                $errors[] = 'permission IAM non déclarée: ' . $permissionKey;
            }
        }

        $headless = is_array($blueprint['headless'] ?? null) ? $blueprint['headless'] : [];
        if ((bool) ($headless['enabled'] ?? false)) {
            foreach (['collection_endpoint', 'item_endpoint'] as $endpointKey) {
                $endpoint = (string) ($headless[$endpointKey] ?? '');
                if ($endpoint !== '' && !str_starts_with($endpoint, '/api/v1/')) {
                    $errors[] = 'endpoint headless invalide (' . $endpointKey . '): ' . $endpoint;
                }
            }
        }

        foreach (($blueprint['fields'] ?? []) as $index => $field) {
            if (!is_array($field)) {
                $errors[] = 'field #' . $index . ' doit être un objet';
                continue;
            }
            $fieldKey = (string) ($field['key'] ?? $field['field_key'] ?? '');
            if ($fieldKey === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $fieldKey)) {
                $errors[] = 'field_key invalide: ' . $fieldKey;
            }
            $column = (string) ($field['column'] ?? '');
            if ($column !== '' && !preg_match('/^[a-z][a-z0-9_]*$/', $column)) {
                $errors[] = 'column invalide pour ' . $fieldKey . ': ' . $column;
            }
        }

        return $errors;
    }

    /** @return array<string,mixed> */
    public function normalize(ModuleProvider $provider, array $raw): array
    {
        $module = $this->safeKey((string) ($raw['module'] ?? $provider->key()));
        $resource = $this->safeKey((string) ($raw['resource'] ?? ''));
        $key = $this->safeKey((string) ($raw['blueprint_key'] ?? $raw['key'] ?? ''));
        if ($resource === '' && str_starts_with($key, $module . '_')) {
            $resource = substr($key, strlen($module) + 1);
        }
        if ($key === '' && $resource !== '') {
            $key = $module . '_' . $resource;
        }
        $resourceType = (string) ($raw['resource_type'] ?? 'module_resource');
        if (!in_array($resourceType, self::RESOURCE_TYPES, true)) {
            $resourceType = 'module_resource';
        }
        $capabilities = $this->object($raw['capabilities'] ?? []);
        $storage = $this->object($raw['storage'] ?? []);
        $headless = $this->object($raw['headless'] ?? []);
        $admin = $this->object($raw['admin'] ?? $raw['admin_schema'] ?? $raw['ui_schema'] ?? []);

        return [
            'module' => $module,
            'resource' => $resource !== '' ? $resource : $key,
            'blueprint_key' => $key,
            'key' => $key,
            'resource_type' => $resourceType,
            'version' => (int) ($raw['version'] ?? 1),
            'label' => (string) ($raw['label'] ?? $raw['name'] ?? $key),
            'description' => (string) ($raw['description'] ?? ''),
            'storage' => $storage,
            'capabilities' => [
                'admin' => (bool) ($capabilities['admin'] ?? true),
                'headless' => (bool) ($capabilities['headless'] ?? ($headless['enabled'] ?? false)),
                'public' => (bool) ($capabilities['public'] ?? false),
                'localized' => (bool) ($capabilities['localized'] ?? false),
                'revisions' => (bool) ($capabilities['revisions'] ?? false),
                'workflow' => (bool) ($capabilities['workflow'] ?? false),
                'seo' => (bool) ($capabilities['seo'] ?? false),
                'export' => (bool) ($capabilities['export'] ?? false),
            ],
            'permissions' => $this->object($raw['permissions'] ?? $raw['permissions_policy'] ?? []),
            'headless' => $headless,
            'admin' => $admin,
            'sections' => is_array($raw['sections'] ?? null) ? $raw['sections'] : [],
            'fields' => is_array($raw['fields'] ?? null) ? $raw['fields'] : [],
            'relations' => is_array($raw['relations'] ?? null) ? $raw['relations'] : [],
            'validation' => $this->object($raw['validation'] ?? []),
            'export' => $this->object($raw['export'] ?? $raw['export_policy'] ?? []),
            'contract' => $this->object($raw['contract'] ?? $raw['api_contract'] ?? []),
            'source' => 'module_provider',
        ];
    }

    private function providerDeclaresDatabase(ModuleProvider $provider, string $databaseKey): bool
    {
        foreach ($this->databases->declaredDatabases($provider) as $database) {
            if (($database['key'] ?? '') === $databaseKey) {
                return true;
            }
        }
        return $databaseKey === 'core';
    }

    private function providerDeclaresPermission(ModuleProvider $provider, string $permissionKey): bool
    {
        foreach ($provider->permissions() as $permission) {
            if (($permission['key'] ?? $permission['permission_key'] ?? '') === $permissionKey) {
                return true;
            }
        }
        return false;
    }

    private function permissionExists(string $permissionKey): bool
    {
        try {
            return (bool) $this->iamDb->one('SELECT 1 FROM iam_permissions WHERE permission_key = :permission_key LIMIT 1', ['permission_key' => $permissionKey]);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    private function object(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function safeKey(string $key): string
    {
        return trim(preg_replace('/[^a-z0-9_]+/', '_', strtolower($key)) ?? '', '_');
    }

    private function json(mixed $value): string
    {
        return json_encode($value ?: (is_array($value) ? [] : new \stdClass()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function now(): string
    {
        return function_exists('now_utc') ? now_utc() : gmdate('Y-m-d H:i:s');
    }
}
