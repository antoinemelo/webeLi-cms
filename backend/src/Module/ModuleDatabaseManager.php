<?php

declare(strict_types=1);

namespace App\Module;

use App\Core\Database;
use App\Core\Logger;
use App\Application\Consistency\CrossDatabaseOperationJournal;

final class ModuleDatabaseManager
{
    /** @var array<string,Database> */
    private array $connections = [];

    public function __construct(
        private readonly Database $coreDb,
        private readonly array $config,
        private readonly ?Logger $logger = null,
        private readonly ?CrossDatabaseOperationJournal $operations = null,
    ) {}

    /** @return list<array<string,mixed>> */
    public function declaredDatabases(ModuleProvider $provider): array
    {
        $items = [];
        foreach ($provider->databases() as $database) {
            $key = $this->normalizeKey((string) ($database['key'] ?? $provider->key()));
            if ($key === '') {
                continue;
            }
            $path = (string) ($database['path'] ?? ('storage/database/' . $key . '.sqlite'));
            $items[] = [
                'key' => $key,
                'driver' => (string) ($database['driver'] ?? 'sqlite'),
                'path' => $this->resolvePath($path),
                'schema' => isset($database['schema']) ? $this->resolvePath((string) $database['schema']) : null,
                'required' => (bool) ($database['required'] ?? true),
            ];
        }
        return $items;
    }

    public function ensureDatabases(ModuleProvider $provider): void
    {
        $correlationId = $this->operations?->begin(
            $provider->key(),
            'module_database_ensure',
            'core.sqlite',
            ['module' => $provider->key(), 'database_count' => count($provider->databases())]
        );
        $currentStep = 'preconditions';
        try {
        foreach ($this->declaredDatabases($provider) as $database) {
            $currentStep = 'ensure:' . (string) $database['key'];
            if ($correlationId !== null) {
                $this->operations?->step($correlationId, $currentStep, 'running', ['module' => $provider->key(), 'database' => (string) $database['key']]);
            }
            if (($database['driver'] ?? 'sqlite') !== 'sqlite') {
                throw new \RuntimeException('Seul SQLite est supporté pour les bases module.');
            }
            $path = (string) $database['path'];
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('Impossible de créer le dossier SQLite du module.');
            }
            $db = $this->database($path);
            $schema = (string) ($database['schema'] ?? '');
            if ($schema !== '' && is_file($schema)) {
                $sql = trim((string) file_get_contents($schema));
                if ($sql !== '') {
                    $db->pdo()->exec($sql);
                }
            }
            $this->upsertDatabaseDeclaration($provider, $database, file_exists($path));
        }
        if ($correlationId !== null) {
            $this->operations?->succeed($correlationId, ['module' => $provider->key()]);
        }
        } catch (\Throwable $e) {
            if ($correlationId !== null) {
                $this->operations?->fail($correlationId, $currentStep, $e);
            }
            throw $e;
        }
    }

    public function applySql(ModuleProvider $provider, string $databaseKey, string $sql): void
    {
        $sql = trim($sql);
        if ($sql === '') {
            return;
        }
        if ($databaseKey === '' || $databaseKey === 'core') {
            $this->coreDb->pdo()->exec($sql);
            return;
        }
        foreach ($this->declaredDatabases($provider) as $database) {
            if (($database['key'] ?? '') === $databaseKey) {
                $this->database((string) $database['path'])->pdo()->exec($sql);
                return;
            }
        }
        throw new \InvalidArgumentException('Base module non déclarée: ' . $databaseKey);
    }

    /** @return list<array<string,mixed>> */
    public function health(ModuleProvider $provider): array
    {
        $result = [];
        foreach ($this->declaredDatabases($provider) as $database) {
            $path = (string) $database['path'];
            $exists = is_file($path);
            $ok = $exists;
            $error = null;
            if ($exists) {
                try {
                    $this->database($path)->pdo()->query('SELECT 1');
                } catch (\Throwable $e) {
                    $ok = false;
                    $error = $e->getMessage();
                }
            }
            $result[] = [
                'key' => $database['key'],
                'driver' => 'sqlite',
                'path' => $path,
                'exists' => $exists,
                'ok' => $ok,
                'error' => $error,
            ];
        }
        return $result;
    }

    private function database(string $path): Database
    {
        return $this->connections[$path] ??= new Database($path);
    }

    /** @param array<string,mixed> $database */
    private function upsertDatabaseDeclaration(ModuleProvider $provider, array $database, bool $exists): void
    {
        try {
            $this->coreDb->run(
                'INSERT INTO module_databases(module_key, database_key, driver, path, schema_path, is_required, exists_at_last_check, updated_at)
                 VALUES(:module_key, :database_key, :driver, :path, :schema_path, :is_required, :exists_at_last_check, :updated_at)
                 ON CONFLICT(module_key, database_key) DO UPDATE SET
                    driver = excluded.driver,
                    path = excluded.path,
                    schema_path = excluded.schema_path,
                    is_required = excluded.is_required,
                    exists_at_last_check = excluded.exists_at_last_check,
                    updated_at = excluded.updated_at',
                [
                    'module_key' => $provider->key(),
                    'database_key' => (string) $database['key'],
                    'driver' => 'sqlite',
                    'path' => (string) $database['path'],
                    'schema_path' => $database['schema'] ?? null,
                    'is_required' => (int) ($database['required'] ?? true),
                    'exists_at_last_check' => (int) $exists,
                    'updated_at' => $this->now(),
                ]
            );
        } catch (\Throwable $e) {
            $this->logger?->warning('module.database_declaration_skipped', ['module' => $provider->key(), 'error' => $e->getMessage()]);
        }
    }

    private function resolvePath(string $path): string
    {
        if ($path === '') {
            return base_path('storage/database/module.sqlite');
        }
        if (str_starts_with($path, '/')) {
            return $path;
        }
        return base_path($path);
    }

    private function normalizeKey(string $key): string
    {
        return preg_replace('/[^a-z0-9_.-]+/', '_', strtolower(trim($key))) ?? '';
    }

    private function now(): string
    {
        return function_exists('now_utc') ? now_utc() : gmdate('Y-m-d H:i:s');
    }
}
