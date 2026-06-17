<?php

declare(strict_types=1);

namespace App\Module;

use App\Core\Database;
use App\Core\Logger;

final class ModuleBlueprintRegistrar
{
    private const ALLOWED_RESOURCE_TYPES = ['content_type', 'block', 'taxonomy', 'media', 'system', 'module_resource', 'headless'];

    public function __construct(
        private readonly Database $coreDb,
        private readonly ?Logger $logger = null,
    ) {}

    public function register(ModuleProvider $provider): void
    {
        if (!$this->coreDb->tableExists('blueprints')) {
            return;
        }
        foreach ($provider->blueprints() as $blueprint) {
            $key = trim((string) ($blueprint['key'] ?? $blueprint['blueprint_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $resourceType = (string) ($blueprint['resource_type'] ?? 'system');
            if (!in_array($resourceType, self::ALLOWED_RESOURCE_TYPES, true)) {
                $resourceType = 'system';
            }
            $label = (string) ($blueprint['label'] ?? $blueprint['name'] ?? $key);
            $description = (string) ($blueprint['description'] ?? ('Blueprint module ' . $provider->key()));
            $version = (int) ($blueprint['version'] ?? 1);
            $schema = $blueprint['schema'] ?? $blueprint;
            $json = static fn(mixed $value): string => json_encode($value ?: new \stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

            $this->coreDb->run(
                'INSERT INTO blueprints(blueprint_key, resource_type, label, description, is_active, updated_at)
                 VALUES(:blueprint_key, :resource_type, :label, :description, 1, :updated_at)
                 ON CONFLICT(blueprint_key, resource_type) WHERE site_id IS NULL DO UPDATE SET
                    label = excluded.label,
                    description = excluded.description,
                    is_active = 1,
                    updated_at = excluded.updated_at',
                [
                    'blueprint_key' => strtolower($key),
                    'resource_type' => $resourceType,
                    'label' => $label,
                    'description' => $description,
                    'updated_at' => $this->now(),
                ]
            );

            $blueprintId = (int) ($this->coreDb->one(
                'SELECT id FROM blueprints WHERE blueprint_key = :key AND resource_type = :resource_type AND site_id IS NULL LIMIT 1',
                ['key' => strtolower($key), 'resource_type' => $resourceType]
            )['id'] ?? 0);
            if ($blueprintId < 1 || !$this->coreDb->tableExists('blueprint_versions')) {
                continue;
            }

            $existingVersion = $this->coreDb->one(
                'SELECT id, is_active FROM blueprint_versions WHERE blueprint_id = :blueprint_id AND version = :version LIMIT 1',
                ['blueprint_id' => $blueprintId, 'version' => $version]
            );
            if ($existingVersion) {
                $versionId = (int) $existingVersion['id'];
                if ((int) $existingVersion['is_active'] !== 1) {
                    $this->coreDb->run(
                        "UPDATE blueprint_versions SET is_active = 0, status = CASE WHEN status = 'active' THEN 'archived' ELSE status END WHERE blueprint_id = :blueprint_id AND is_active = 1",
                        ['blueprint_id' => $blueprintId]
                    );
                    $this->coreDb->run(
                        "UPDATE blueprint_versions SET is_active = 1, status = 'active', activated_at = COALESCE(activated_at, :activated_at) WHERE id = :id",
                        ['activated_at' => $this->now(), 'id' => $versionId]
                    );
                }
                $this->coreDb->run(
                    'UPDATE blueprints SET active_version_id = :version_id, updated_at = :updated_at WHERE id = :blueprint_id',
                    ['version_id' => $versionId, 'updated_at' => $this->now(), 'blueprint_id' => $blueprintId]
                );
                continue;
            }

            $this->coreDb->run(
                "UPDATE blueprint_versions SET is_active = 0, status = CASE WHEN status = 'active' THEN 'archived' ELSE status END WHERE blueprint_id = :blueprint_id",
                ['blueprint_id' => $blueprintId]
            );
            $this->coreDb->run(
                <<<SQL
INSERT INTO blueprint_versions(
    blueprint_id, version, version_label, status, schema_json, ui_schema_json, validation_json,
    seo_policy_json, routing_policy_json, workflow_policy_json, translation_policy_json,
    permissions_policy_json, is_active, created_at, activated_at
) VALUES(
    :blueprint_id, :version, :version_label, 'active', :schema_json, :ui_schema_json, :validation_json,
    :seo_policy_json, :routing_policy_json, :workflow_policy_json, :translation_policy_json,
    :permissions_policy_json, 1, :created_at, :activated_at
) ON CONFLICT(blueprint_id, version) DO UPDATE SET
    version_label = excluded.version_label,
    status = 'active',
    schema_json = excluded.schema_json,
    ui_schema_json = excluded.ui_schema_json,
    validation_json = excluded.validation_json,
    seo_policy_json = excluded.seo_policy_json,
    routing_policy_json = excluded.routing_policy_json,
    workflow_policy_json = excluded.workflow_policy_json,
    translation_policy_json = excluded.translation_policy_json,
    permissions_policy_json = excluded.permissions_policy_json,
    is_active = 1,
    activated_at = excluded.activated_at
SQL,
                [
                    'blueprint_id' => $blueprintId,
                    'version' => $version,
                    'version_label' => (string) ($blueprint['version_label'] ?? ('v' . $version)),
                    'schema_json' => $json($schema),
                    'ui_schema_json' => $json($blueprint['ui_schema'] ?? []),
                    'validation_json' => $json($blueprint['validation'] ?? []),
                    'seo_policy_json' => $json($blueprint['seo_policy'] ?? []),
                    'routing_policy_json' => $json($blueprint['routing_policy'] ?? []),
                    'workflow_policy_json' => $json($blueprint['workflow_policy'] ?? []),
                    'translation_policy_json' => $json($blueprint['translation_policy'] ?? []),
                    'permissions_policy_json' => $json($blueprint['permissions_policy'] ?? []),
                    'created_at' => $this->now(),
                    'activated_at' => $this->now(),
                ]
            );
            $versionId = (int) ($this->coreDb->one(
                'SELECT id FROM blueprint_versions WHERE blueprint_id = :blueprint_id AND version = :version LIMIT 1',
                ['blueprint_id' => $blueprintId, 'version' => $version]
            )['id'] ?? 0);
            if ($versionId > 0) {
                $this->coreDb->run('UPDATE blueprints SET active_version_id = :version_id WHERE id = :id', ['version_id' => $versionId, 'id' => $blueprintId]);
            }
            if ($this->coreDb->tableExists('module_blueprints')) {
                $storage = is_array($blueprint['storage'] ?? null) ? $blueprint['storage'] : [];
                $capabilities = is_array($blueprint['capabilities'] ?? null) ? $blueprint['capabilities'] : [];
                $permissions = is_array($blueprint['permissions'] ?? null) ? $blueprint['permissions'] : [];
                $headless = is_array($blueprint['headless'] ?? null) ? $blueprint['headless'] : [];
                $admin = is_array($blueprint['admin'] ?? null) ? $blueprint['admin'] : [];
                $relations = is_array($blueprint['relations'] ?? null) ? $blueprint['relations'] : [];
                $export = is_array($blueprint['export'] ?? null) ? $blueprint['export'] : [];
                $contract = is_array($blueprint['contract'] ?? null) ? $blueprint['contract'] : [];

                $this->coreDb->run(
                    'INSERT INTO module_blueprints(
                        module_key, resource, blueprint_key, resource_type, declared_version,
                        storage_json, capabilities_json, permissions_json, headless_json, admin_schema_json,
                        relations_json, export_policy_json, contract_json, validation_errors_json, is_published,
                        created_at, updated_at
                     ) VALUES(
                        :module_key, :resource, :blueprint_key, :resource_type, :declared_version,
                        :storage_json, :capabilities_json, :permissions_json, :headless_json, :admin_schema_json,
                        :relations_json, :export_policy_json, :contract_json, :validation_errors_json, :is_published,
                        :created_at, :updated_at
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
                        updated_at = excluded.updated_at',
                    [
                        'module_key' => $provider->key(),
                        'resource' => (string) ($blueprint['resource'] ?? ''),
                        'blueprint_key' => strtolower($key),
                        'resource_type' => $resourceType,
                        'declared_version' => $version,
                        'storage_json' => $json($storage),
                        'capabilities_json' => $json($capabilities),
                        'permissions_json' => $json($permissions),
                        'headless_json' => $json($headless),
                        'admin_schema_json' => $json($admin),
                        'relations_json' => $json($relations),
                        'export_policy_json' => $json($export),
                        'contract_json' => $json($contract),
                        'validation_errors_json' => '[]',
                        'is_published' => 0,
                        'created_at' => $this->now(),
                        'updated_at' => $this->now(),
                    ]
                );
            }
        }
    }

    private function now(): string
    {
        return function_exists('now_utc') ? now_utc() : gmdate('Y-m-d H:i:s');
    }
}
