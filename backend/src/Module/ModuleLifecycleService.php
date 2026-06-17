<?php

declare(strict_types=1);

namespace App\Module;

use App\Core\Database;
use App\Core\Logger;

final class ModuleLifecycleService
{
    public function __construct(
        private readonly Database $coreDb,
        private readonly ModuleRegistry $registry,
        private readonly ModuleDatabaseManager $databases,
        private readonly ModulePermissionRegistrar $permissions,
        private readonly ModuleBlueprintRegistrar $blueprints,
        private readonly ModuleBlueprintGovernanceService $blueprintGovernance,
        private readonly ?Logger $logger = null,
    ) {}

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        $this->registry->syncManifest();
        return array_map(fn(ModuleProvider $provider): array => $this->contract($provider), $this->registry->allProviders());
    }

    /** @return array<string,mixed> */
    public function show(string $key): array
    {
        $provider = $this->requireProvider($key);
        return $this->contract($provider) + ['health' => $this->health($key)];
    }

    /** @return array<string,mixed> */
    public function install(string $key): array
    {
        $provider = $this->requireProvider($key);
        $this->registry->syncManifest();
        $this->databases->ensureDatabases($provider);
        $this->permissions->register($provider);
        $this->blueprints->register($provider);
        $this->blueprintGovernance->sync($provider, false);
        $this->applyMigrations($provider, false);
        $this->setState($provider, true, false);
        $this->blueprintGovernance->sync($provider, false);
        $this->syncDeclarations($provider, false);
        $this->logEvent($provider->key(), 'install');
        return $this->show($key);
    }

    /** @return array<string,mixed> */
    public function enable(string $key): array
    {
        $provider = $this->requireProvider($key);
        $missing = $this->missingActiveDependencies($provider);
        if ($missing !== []) {
            throw new \RuntimeException('Dépendances inactives: ' . implode(', ', $missing));
        }
        $this->databases->ensureDatabases($provider);
        $this->permissions->register($provider);
        $this->blueprints->register($provider);
        $this->blueprintGovernance->sync($provider, true);
        $this->setState($provider, true, true);
        $this->syncDeclarations($provider, true);
        $this->logEvent($provider->key(), 'enable');
        return $this->show($key);
    }

    /** @return array<string,mixed> */
    public function disable(string $key): array
    {
        $provider = $this->requireProvider($key);
        $this->setState($provider, true, false);
        $this->blueprintGovernance->sync($provider, false);
        $this->syncDeclarations($provider, false);
        $this->logEvent($provider->key(), 'disable');
        return $this->show($key);
    }

    /** @return array<string,mixed> */
    public function migrate(string $key): array
    {
        $provider = $this->requireProvider($key);
        $applied = $this->applyMigrations($provider, true);
        $this->logEvent($provider->key(), 'migrate', ['applied' => $applied]);
        return ['module' => $this->contract($provider), 'applied' => $applied];
    }

    /** @return array<string,mixed> */
    public function health(string $key): array
    {
        $provider = $this->requireProvider($key);
        $state = $this->registry->state($provider->key());
        $missingDependencies = $this->missingActiveDependencies($provider);
        $missingPermissions = $this->permissions->missing($provider);
        $databaseHealth = $this->databases->health($provider);
        $routeCount = count($provider->adminRoutes()) + count($provider->apiRoutes()) + count($provider->publicHeadlessRoutes());

        return [
            'ok' => $missingDependencies === [] && $missingPermissions === [] && !in_array(false, array_map(static fn(array $db): bool => (bool) ($db['ok'] ?? false), $databaseHealth), true),
            'state' => $state,
            'missing_dependencies' => $missingDependencies,
            'missing_permissions' => $missingPermissions,
            'databases' => $databaseHealth,
            'route_count' => $routeCount,
            'blueprint_count' => count($provider->blueprints()),
            'blueprint_errors' => $this->blueprintErrors($provider),
        ];
    }

    /** @return list<string> */
    public function dependencyAlerts(ModuleProvider $provider): array
    {
        return array_map(static fn(string $key): string => 'Dépendance inactive ou absente: ' . $key, $this->missingActiveDependencies($provider));
    }

    /** @return array<string,mixed> */
    public function contract(ModuleProvider $provider): array
    {
        $state = $this->registry->state($provider->key());
        return [
            'key' => $provider->key(),
            'name' => $provider->name(),
            'version' => $provider->version(),
            'description' => $provider->description(),
            'installed' => (bool) ($state['is_installed'] ?? false),
            'enabled' => (bool) ($state['is_enabled'] ?? false),
            'features' => [
                'databases' => count($provider->databases()),
                'permissions' => count($provider->permissions()),
                'blueprints' => count($provider->blueprints()),
                'module_resources' => count(array_filter($provider->blueprints(), static fn(array $blueprint): bool => ($blueprint['resource_type'] ?? 'module_resource') === 'module_resource')),
                'content_types' => count($provider->contentTypes()),
                'admin_navigation' => count($provider->adminNavigation()),
                'admin_routes' => count($provider->adminRoutes()),
                'api_routes' => count($provider->apiRoutes()),
                'public_headless_routes' => count($provider->publicHeadlessRoutes()),
                'hooks' => count($provider->hooks()),
                'api_contracts' => count($provider->apiContracts()),
            ],
            'dependencies' => $provider->dependencies(),
            'permissions' => array_values(array_filter(array_map(static fn(array $p): string => (string) ($p['key'] ?? $p['permission_key'] ?? ''), $provider->permissions()))),
            'blueprints' => array_map(fn(array $blueprint): array => $this->blueprintGovernance->normalize($provider, $blueprint), $provider->blueprints()),
            'navigation' => $provider->adminNavigation(),
            'endpoints' => $this->providerEndpoints($provider),
            'alerts' => $this->dependencyAlerts($provider),
        ];
    }


    /** @return list<string> */
    private function blueprintErrors(ModuleProvider $provider): array
    {
        $errors = [];
        foreach ($provider->blueprints() as $blueprint) {
            foreach ($this->blueprintGovernance->validate($provider, $this->blueprintGovernance->normalize($provider, $blueprint)) as $error) {
                $errors[] = $error;
            }
        }
        return $errors;
    }

    private function syncDeclarations(ModuleProvider $provider, bool $published): void
    {
        if ($this->coreDb->tableExists('module_routes')) {
            $this->coreDb->run('DELETE FROM module_routes WHERE module_key = :module_key', ['module_key' => $provider->key()]);
            foreach (['admin' => $provider->adminRoutes(), 'api' => $provider->apiRoutes(), 'headless' => $provider->publicHeadlessRoutes()] as $scope => $routes) {
                foreach ($routes as $index => $route) {
                    $this->coreDb->run(
                        'INSERT INTO module_routes(module_key, route_key, scope, method, path, handler, is_published, updated_at)
                         VALUES(:module_key, :route_key, :scope, :method, :path, :handler, :is_published, :updated_at)',
                        [
                            'module_key' => $provider->key(),
                            'route_key' => $scope . '.' . $index,
                            'scope' => $scope,
                            'method' => strtoupper((string) $route[0]),
                            'path' => (string) $route[1],
                            'handler' => (string) $route[2],
                            'is_published' => (int) $published,
                            'updated_at' => $this->now(),
                        ]
                    );
                }
            }
        }

        if ($this->coreDb->tableExists('module_api_contracts')) {
            $this->coreDb->run('DELETE FROM module_api_contracts WHERE module_key = :module_key', ['module_key' => $provider->key()]);
            foreach ($provider->apiContracts() as $contract) {
                $key = (string) ($contract['key'] ?? $contract['contract'] ?? '');
                if ($key === '') {
                    continue;
                }
                $version = (string) ($contract['version'] ?? $provider->version());
                $this->coreDb->run(
                    'INSERT INTO module_api_contracts(module_key, contract_key, version, schema_json, updated_at)
                     VALUES(:module_key, :contract_key, :version, :schema_json, :updated_at)',
                    [
                        'module_key' => $provider->key(),
                        'contract_key' => $key,
                        'version' => $version,
                        'schema_json' => json_encode($contract, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                        'updated_at' => $this->now(),
                    ]
                );
            }
        }
    }

    /** @return list<array<string,string>> */
    private function providerEndpoints(ModuleProvider $provider): array
    {
        $endpoints = [];
        foreach (['admin' => $provider->adminRoutes(), 'api' => $provider->apiRoutes(), 'headless' => $provider->publicHeadlessRoutes()] as $scope => $routes) {
            foreach ($routes as $index => $route) {
                $endpoints[] = [
                    'key' => 'module.' . $provider->key() . '.' . $scope . '.' . $index,
                    'scope' => $scope,
                    'method' => strtoupper((string) $route[0]),
                    'path' => (string) $route[1],
                ];
            }
        }
        return $endpoints;
    }

    /** @return list<string> */
    private function applyMigrations(ModuleProvider $provider, bool $includeSeeds): array
    {
        $this->ensureMigrationLog();
        $applied = [];
        foreach ($this->normalizedSqlSteps($provider, $provider->migrations(), 'migration') as $step) {
            if ($this->isApplied($step['name'])) {
                continue;
            }
            $this->databases->applySql($provider, $step['database'], $step['sql']);
            $this->markApplied($step['name']);
            $applied[] = $step['name'];
        }
        if ($includeSeeds) {
            foreach ($this->normalizedSqlSteps($provider, $provider->seeds(), 'seed') as $step) {
                if ($this->isApplied($step['name'])) {
                    continue;
                }
                $this->databases->applySql($provider, $step['database'], $step['sql']);
                $this->markApplied($step['name']);
                $applied[] = $step['name'];
            }
        }
        return $applied;
    }

    /** @param array<string,mixed>|list<array<string,mixed>> $steps @return list<array{name:string,database:string,sql:string}> */
    private function normalizedSqlSteps(ModuleProvider $provider, array $steps, string $kind): array
    {
        $result = [];
        foreach ($steps as $name => $step) {
            if (is_string($step)) {
                $result[] = ['name' => 'module:' . $provider->key() . ':' . $kind . ':' . (string) $name, 'database' => 'core', 'sql' => $step];
                continue;
            }
            if (is_array($step)) {
                $stepName = (string) ($step['name'] ?? $name);
                $result[] = [
                    'name' => 'module:' . $provider->key() . ':' . $kind . ':' . $stepName,
                    'database' => (string) ($step['database'] ?? 'core'),
                    'sql' => (string) ($step['sql'] ?? ''),
                ];
            }
        }
        return array_values(array_filter($result, static fn(array $step): bool => trim($step['sql']) !== ''));
    }

    private function requireProvider(string $key): ModuleProvider
    {
        $provider = $this->registry->get($key);
        if (!$provider) {
            throw new \InvalidArgumentException('Module inconnu: ' . $key);
        }
        return $provider;
    }

    /** @return list<string> */
    private function missingActiveDependencies(ModuleProvider $provider): array
    {
        $missing = [];
        foreach ($provider->dependencies() as $dependency) {
            if (!$this->registry->isEnabled($dependency)) {
                $missing[] = $dependency;
            }
        }
        return $missing;
    }

    private function setState(ModuleProvider $provider, bool $installed, bool $enabled): void
    {
        $this->coreDb->run(
            'INSERT INTO modules(module_key, name, version, provider_class, is_installed, is_enabled, config_json, updated_at)
             VALUES(:module_key, :name, :version, :provider_class, :is_installed, :is_enabled, :config_json, :updated_at)
             ON CONFLICT(module_key) DO UPDATE SET
                name = excluded.name,
                version = excluded.version,
                provider_class = excluded.provider_class,
                is_installed = excluded.is_installed,
                is_enabled = excluded.is_enabled,
                config_json = excluded.config_json,
                updated_at = excluded.updated_at',
            [
                'module_key' => $provider->key(),
                'name' => $provider->name(),
                'version' => $provider->version(),
                'provider_class' => $provider::class,
                'is_installed' => (int) $installed,
                'is_enabled' => (int) $enabled,
                'config_json' => json_encode(['description' => $provider->description()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => $this->now(),
            ]
        );
        $this->registry->reset();
    }

    private function ensureMigrationLog(): void
    {
        $this->coreDb->pdo()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration TEXT NOT NULL UNIQUE, migrated_at TEXT NOT NULL)');
    }

    private function isApplied(string $name): bool
    {
        $row = $this->coreDb->one('SELECT id FROM schema_migrations WHERE migration = :migration LIMIT 1', ['migration' => $name]);
        return (bool) $row;
    }

    private function markApplied(string $name): void
    {
        $this->coreDb->run('INSERT OR IGNORE INTO schema_migrations(migration, migrated_at) VALUES(:migration, :migrated_at)', ['migration' => $name, 'migrated_at' => $this->now()]);
    }

    /** @param array<string,mixed> $payload */
    private function logEvent(string $key, string $event, array $payload = []): void
    {
        try {
            $this->coreDb->run(
                'INSERT INTO module_lifecycle_events(module_key, event, payload_json, created_at) VALUES(:module_key, :event, :payload_json, :created_at)',
                [
                    'module_key' => $key,
                    'event' => $event,
                    'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => $this->now(),
                ]
            );
        } catch (\Throwable $e) {
            $this->logger?->warning('module.lifecycle_event_skipped', ['module' => $key, 'event' => $event, 'error' => $e->getMessage()]);
        }
    }

    private function now(): string
    {
        return function_exists('now_utc') ? now_utc() : gmdate('Y-m-d H:i:s');
    }
}
