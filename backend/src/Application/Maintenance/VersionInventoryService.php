<?php

declare(strict_types=1);

namespace App\Application\Maintenance;

use App\Core\Database;
use App\Infrastructure\Maintenance\DatabaseMigrationInventory;

final class VersionInventoryService
{
    public function __construct(
        private readonly Database $coreDb,
        private readonly array $config = [],
        private readonly array $modulesConfig = [],
        private readonly array $databaseConfig = [],
        private readonly ?DatabaseMigrationInventory $databaseMigrationInventory = null,
    ) {}

    /** @return array<string,mixed> */
    public function localManifest(string $source = 'local'): array
    {
        $release = $this->readJsonFile(base_path('config/release.json')) ?? [];
        $currentDeployment = $this->readJsonFile(base_path('storage/deployments/current.json')) ?? [];

        return [
            'schema_version' => 1,
            'generated_at' => gmdate('c'),
            'source' => $source,
            'source_url' => $this->publicBaseUrl(),
            'git' => [
                'commit' => $this->gitCommit(),
                'branch' => $this->gitBranch(),
            ],
            'core' => [
                'key' => 'core',
                'name' => (string) ($release['product_name'] ?? $currentDeployment['product_name'] ?? 'DEC CMS'),
                'technical_version' => (string) ($release['technical_version'] ?? $currentDeployment['technical_version'] ?? ''),
                'release_name' => (string) ($release['release_name'] ?? $currentDeployment['release_name'] ?? ''),
                'release_type' => (string) ($release['release_type'] ?? $currentDeployment['release_type'] ?? ''),
                'release_id' => (string) ($currentDeployment['release_id'] ?? ''),
                'deployment_id' => (string) ($currentDeployment['deployment_id'] ?? ''),
            ],
            'modules' => $this->localModules(),
            'databases' => $this->databaseInventory(),
        ];
    }

    /** @return array<string,mixed> */
    public function maintenancePayload(): array
    {
        $local = $this->localManifest('installed');
        $channels = [];
        foreach ($this->channels() as $key => $channel) {
            $channels[$key] = $this->channelManifest((string) $key, $channel);
        }

        return [
            'enabled' => (bool) ($this->config['enabled'] ?? true),
            'local' => $local,
            'channels' => $channels,
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function channels(): array
    {
        $channels = $this->config['channels'] ?? [];
        return is_array($channels) ? $channels : [];
    }

    /** @param array<string,mixed> $channel */
    private function channelManifest(string $key, array $channel): array
    {
        $primaryUrl = (string) ($channel['manifest_url'] ?? '');
        $branch = (string) ($channel['git_branch'] ?? '');
        $primary = $primaryUrl !== '' ? $this->fetchJson($primaryUrl) : null;
        $git = $branch !== ''
            ? $this->gitFallbackManifest($key, $branch, $channel, $primary === null)
            : null;
        if ($branch !== '' && $git !== null && $primary !== null) {
            $primaryCore = is_array($primary['core'] ?? null) ? $primary['core'] : [];
            $gitCore = is_array($git['core'] ?? null) ? $git['core'] : [];
            $primaryModules = $this->modulesByKey($primary['modules'] ?? []);
            if ($this->isNewerCore($gitCore, $primaryCore) || $primaryModules === []) {
                $git = $this->gitFallbackManifest($key, $branch, $channel, true);
            }
        }
        $merged = $this->mergeRemoteManifests($primary, $git);

        if ($merged === null) {
            return [
                'key' => $key,
                'label' => (string) ($channel['label'] ?? $key),
                'status' => 'unavailable',
                'source' => 'none',
                'source_url' => $primaryUrl,
                'git_branch' => $branch,
                'error' => 'Manifest distant indisponible.',
                'core' => null,
                'modules' => [],
                'databases' => [],
            ];
        }

        return [
            'key' => $key,
            'label' => (string) ($channel['label'] ?? $key),
            'status' => 'available',
            'source' => (string) ($merged['source'] ?? 'remote'),
            'source_url' => (string) ($merged['source_url'] ?? $primaryUrl),
            'git_branch' => $branch,
            'error' => null,
            'core' => $merged['core'] ?? null,
            'modules' => $merged['modules'] ?? [],
            'databases' => $merged['databases'] ?? [],
            'git' => $merged['git'] ?? ['branch' => $branch],
            'generated_at' => $merged['generated_at'] ?? null,
        ];
    }

    /** @param array<string,mixed>|null $primary @param array<string,mixed>|null $git */
    private function mergeRemoteManifests(?array $primary, ?array $git): ?array
    {
        if ($primary === null) {
            return $git;
        }
        if ($git === null) {
            return $primary;
        }

        $primaryCore = is_array($primary['core'] ?? null) ? $primary['core'] : [];
        $gitCore = is_array($git['core'] ?? null) ? $git['core'] : [];
        $core = $this->isNewerCore($gitCore, $primaryCore) ? $gitCore : $primaryCore;

        $modules = [];
        foreach ($this->modulesByKey($primary['modules'] ?? []) as $moduleKey => $module) {
            $modules[$moduleKey] = $module;
        }
        foreach ($this->modulesByKey($git['modules'] ?? []) as $moduleKey => $gitModule) {
            if (!isset($modules[$moduleKey]) || $this->isNewerModule($gitModule, $modules[$moduleKey])) {
                $modules[$moduleKey] = $gitModule;
            }
        }

        $databases = [];
        foreach ($this->databasesByKey($primary['databases'] ?? []) as $databaseKey => $database) {
            $databases[$databaseKey] = $database;
        }
        foreach ($this->databasesByKey($git['databases'] ?? []) as $databaseKey => $gitDatabase) {
            if (!isset($databases[$databaseKey]) || $this->isNewerDatabase($gitDatabase, $databases[$databaseKey])) {
                $databases[$databaseKey] = $gitDatabase;
            }
        }

        return [
            'schema_version' => 1,
            'generated_at' => $primary['generated_at'] ?? $git['generated_at'] ?? null,
            'source' => 'reference_with_git_fallback',
            'source_url' => (string) ($primary['source_url'] ?? ''),
            'git' => $git['git'] ?? null,
            'core' => $core,
            'modules' => array_values($modules),
            'databases' => array_values($databases),
        ];
    }

    /** @param array<string,mixed> $channel */
    private function gitFallbackManifest(string $key, string $branch, array $channel, bool $includeModules): ?array
    {
        $repo = trim((string) ($this->config['github_repo'] ?? ''));
        if ($repo === '') {
            return null;
        }
        $base = 'https://raw.githubusercontent.com/' . $repo . '/' . rawurlencode($branch) . '/';
        $release = $this->fetchJson($base . 'config/release.json');
        if ($release === null) {
            return null;
        }

        $modules = [];
        if ($includeModules) {
            foreach ($this->systemModuleManifestPaths() as $path) {
                $relativePath = $this->relativeProjectPath($path);
                if ($relativePath === null || $relativePath === '') {
                    continue;
                }
                $manifest = $this->fetchJson($base . $relativePath);
                if (!is_array($manifest)) {
                    continue;
                }
                $module = $this->moduleFromManifest($manifest, $relativePath);
                if ($module !== null) {
                    $module['source'] = 'git';
                    $modules[] = $module;
                }
            }
        }

        return [
            'schema_version' => 1,
            'generated_at' => gmdate('c'),
            'source' => 'git',
            'source_url' => 'https://github.com/' . $repo . '/tree/' . $branch,
            'git' => ['branch' => $branch, 'repo' => $repo],
            'core' => [
                'key' => 'core',
                'name' => (string) ($release['product_name'] ?? 'DEC CMS'),
                'technical_version' => (string) ($release['technical_version'] ?? ''),
                'release_name' => (string) ($release['release_name'] ?? ''),
                'release_type' => (string) ($release['release_type'] ?? ''),
                'release_id' => '',
                'channel' => $key,
                'instance_url' => (string) ($channel['instance_url'] ?? ''),
            ],
            'modules' => $modules,
            'databases' => $this->gitDatabaseInventory($repo, $branch),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function databaseInventory(): array
    {
        return ($this->databaseMigrationInventory ?? new DatabaseMigrationInventory())->inspect($this->databaseSpecs());
    }

    /** @return list<array<string,mixed>> */
    private function databaseSpecs(): array
    {
        $specs = [];
        foreach ($this->nativeDatabaseSpecs() as $spec) {
            $specs[(string) $spec['key']] = $spec;
        }
        foreach ($this->moduleDatabaseSpecs() as $spec) {
            $key = (string) $spec['key'];
            $specs[$key] = [
                ...($specs[$key] ?? []),
                ...$spec,
            ];
        }
        ksort($specs);
        return array_values($specs);
    }

    /** @return list<array<string,mixed>> */
    private function nativeDatabaseSpecs(): array
    {
        $definitions = [
            ['key' => 'core', 'name' => 'Core', 'kind' => 'core', 'module_key' => null, 'path' => 'storage/database/core.sqlite', 'migrations_path' => 'database/migrations/core', 'required' => true],
            ['key' => 'iam', 'name' => 'IAM', 'kind' => 'core', 'module_key' => null, 'path' => 'storage/database/iam.sqlite', 'migrations_path' => 'database/migrations/iam', 'required' => true],
            ['key' => 'forms', 'name' => 'Formulaires', 'kind' => 'system_module', 'module_key' => 'forms', 'path' => 'storage/database/forms.sqlite', 'migrations_path' => 'database/migrations/forms', 'required' => true],
            ['key' => 'cookies', 'name' => 'Cookies', 'kind' => 'system_module', 'module_key' => 'cookies', 'path' => 'storage/database/cookies.sqlite', 'migrations_path' => 'database/migrations/cookies', 'required' => true],
        ];

        return array_map(function (array $definition): array {
            $key = (string) $definition['key'];
            $configuredPath = $this->databaseConfig[$key]['path'] ?? null;
            $path = is_string($configuredPath) && trim($configuredPath) !== ''
                ? (string) ($this->relativeProjectPath($configuredPath) ?? $configuredPath)
                : (string) $definition['path'];
            return [...$definition, 'path' => $path];
        }, $definitions);
    }

    /** @return list<array<string,mixed>> */
    private function moduleDatabaseSpecs(): array
    {
        $specs = [];
        foreach ([...$this->systemModuleManifestPaths(), ...$this->configuredLocalModuleManifestPaths()] as $path) {
            $absolutePath = $this->absoluteProjectPath($path);
            if ($absolutePath === null) {
                continue;
            }
            $manifest = $this->readJsonFile($absolutePath);
            if ($manifest === null) {
                continue;
            }
            $moduleKey = trim((string) ($manifest['key'] ?? ''));
            $moduleType = trim((string) ($manifest['type'] ?? ''));
            $databases = is_array($manifest['databases'] ?? null) ? $manifest['databases'] : [];
            foreach ($databases as $database) {
                if (!is_array($database)) {
                    continue;
                }
                $key = trim((string) ($database['key'] ?? ''));
                $path = $this->relativeProjectPath((string) ($database['path'] ?? '')) ?? '';
                if ($key === '' || $path === '') {
                    continue;
                }
                $specs[] = [
                    'key' => $key,
                    'name' => (string) ($manifest['name'] ?? $key),
                    'kind' => $moduleType === 'client' ? 'client_module' : 'system_module',
                    'module_key' => $moduleKey,
                    'path' => $path,
                    'schema_path' => $this->relativeProjectPath((string) ($database['schema'] ?? '')),
                    'migrations_path' => $this->relativeProjectPath((string) ($database['migrations'] ?? 'database/migrations/' . $key)),
                    'required' => (bool) ($database['required'] ?? true),
                ];
            }
        }

        return $specs;
    }

    /** @return list<array<string,mixed>> */
    private function gitDatabaseInventory(string $repo, string $branch): array
    {
        $tree = $this->fetchJson('https://api.github.com/repos/' . $repo . '/git/trees/' . rawurlencode($branch) . '?recursive=1');
        $items = is_array($tree['tree'] ?? null) ? $tree['tree'] : [];
        if ($items === []) {
            return [];
        }

        $paths = [];
        foreach ($items as $item) {
            if (!is_array($item) || ($item['type'] ?? '') !== 'blob') {
                continue;
            }
            $path = (string) ($item['path'] ?? '');
            if (str_ends_with($path, '.sql')) {
                $paths[$path] = (string) ($item['sha'] ?? '');
            }
        }

        $databases = [];
        foreach ($this->databaseSpecs() as $spec) {
            $migrationsPath = trim((string) ($spec['migrations_path'] ?? ''), '/');
            if ($migrationsPath === '') {
                continue;
            }
            $expected = [];
            foreach ($paths as $path => $sha) {
                if (!str_starts_with($path, $migrationsPath . '/')) {
                    continue;
                }
                $expected[] = [
                    'name' => basename($path),
                    'checksum' => $sha,
                ];
            }
            usort($expected, static fn(array $a, array $b): int => strnatcmp((string) $a['name'], (string) $b['name']));
            if ($expected === []) {
                continue;
            }
            $databases[] = [
                'key' => (string) ($spec['key'] ?? ''),
                'name' => (string) ($spec['name'] ?? $spec['key'] ?? ''),
                'kind' => (string) ($spec['kind'] ?? 'database'),
                'module_key' => $spec['module_key'] ?? null,
                'path' => (string) ($spec['path'] ?? ''),
                'migrations_path' => $migrationsPath,
                'required' => (bool) ($spec['required'] ?? true),
                'exists' => null,
                'journal_exists' => null,
                'status' => 'expected_only',
                'expected_migrations' => $expected,
                'expected_count' => count($expected),
                'expected_latest' => $this->latestMigrationName($expected),
                'applied_migrations' => [],
                'applied_count' => 0,
                'applied_latest' => '',
                'missing_migrations' => [],
                'unknown_migrations' => [],
                'checksum_mismatches' => [],
            ];
        }

        return $databases;
    }

    /** @param list<array<string,mixed>> $migrations */
    private function latestMigrationName(array $migrations): string
    {
        $names = [];
        foreach ($migrations as $migration) {
            $name = trim((string) ($migration['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }
        sort($names, SORT_NATURAL);
        return $names === [] ? '' : (string) end($names);
    }

    /** @return list<array<string,mixed>> */
    private function localModules(): array
    {
        $modules = [];
        foreach ($this->manifestModules() as $module) {
            $modules[$module['key']] = $module;
        }

        foreach ($this->databaseModules() as $module) {
            $key = (string) $module['key'];
            $modules[$key] = array_filter([
                ...($modules[$key] ?? []),
                ...$module,
                'manifest_version' => $modules[$key]['version'] ?? null,
                'database_version' => $module['version'] ?? null,
            ], static fn(mixed $value): bool => $value !== null);
        }

        ksort($modules);
        return array_values($modules);
    }

    /** @return array<string,array<string,mixed>> */
    private function manifestModules(): array
    {
        $modules = [];
        $paths = [
            ...$this->systemModuleManifestPaths(),
            ...$this->configuredLocalModuleManifestPaths(),
        ];
        foreach ($paths as $path) {
            $absolutePath = $this->absoluteProjectPath($path);
            if ($absolutePath === null) {
                continue;
            }
            $manifest = $this->readJsonFile($absolutePath);
            if ($manifest === null) {
                continue;
            }
            $module = $this->moduleFromManifest($manifest, $this->relativeProjectPath($absolutePath) ?? $path);
            if ($module !== null) {
                $modules[(string) $module['key']] = $module + ['source' => 'manifest'];
            }
        }
        return $modules;
    }

    /** @return list<string> */
    private function systemModuleManifestPaths(): array
    {
        $configured = $this->modulesConfig['system_manifest_paths'] ?? [];
        $paths = [];
        if (is_array($configured)) {
            foreach ($configured as $path) {
                if (is_string($path) && trim($path) !== '') {
                    $paths[] = $path;
                }
            }
        }

        if ($paths === []) {
            $paths = glob(base_path('backend/src/Modules/*/module.json')) ?: [];
        }

        return $this->uniqueManifestPaths($paths);
    }

    /** @return list<string> */
    private function configuredLocalModuleManifestPaths(): array
    {
        $configPath = $this->modulesConfig['local_modules_config'] ?? null;
        if (!is_string($configPath) || trim($configPath) === '') {
            return [];
        }

        $config = $this->readJsonFile($configPath);
        $entries = is_array($config['modules'] ?? null) ? $config['modules'] : [];
        $paths = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $path = trim((string) ($entry['manifest'] ?? ''));
            if ($path !== '') {
                $paths[] = $path;
            }
        }

        return $this->uniqueManifestPaths($paths);
    }

    /** @param list<string> $paths @return list<string> */
    private function uniqueManifestPaths(array $paths): array
    {
        $normalized = [];
        foreach ($paths as $path) {
            $absolutePath = $this->absoluteProjectPath($path);
            if ($absolutePath === null) {
                continue;
            }
            $relativePath = $this->relativeProjectPath($absolutePath);
            if ($relativePath !== null && $relativePath !== '') {
                $normalized[$relativePath] = $relativePath;
            }
        }
        ksort($normalized);
        return array_values($normalized);
    }

    private function absoluteProjectPath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }

        $normalized = str_replace('\\', '/', $path);
        if (!$this->isAbsolutePath($path)) {
            if ($normalized === '..' || str_starts_with($normalized, '../') || str_contains($normalized, '/../')) {
                return null;
            }
            $path = base_path($normalized);
        }

        $root = rtrim(str_replace('\\', '/', base_path()), '/') . '/';
        $candidate = str_replace('\\', '/', $path);
        if ($candidate === rtrim($root, '/') || str_starts_with($candidate, $root)) {
            return $path;
        }

        return null;
    }

    private function relativeProjectPath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if (!$this->isAbsolutePath($path)) {
            return str_replace('\\', '/', ltrim($path, '/'));
        }

        $root = rtrim(str_replace('\\', '/', base_path()), '/') . '/';
        $candidate = str_replace('\\', '/', $path);
        if (str_starts_with($candidate, $root)) {
            return substr($candidate, strlen($root));
        }

        return null;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }

    /** @return list<array<string,mixed>> */
    private function databaseModules(): array
    {
        try {
            $rows = $this->coreDb->all(
                'SELECT module_key, name, version, is_system, is_installed, is_enabled, updated_at FROM modules ORDER BY module_key'
            );
        } catch (\Throwable) {
            return [];
        }

        return array_map(static fn(array $row): array => [
            'key' => (string) ($row['module_key'] ?? ''),
            'name' => (string) ($row['name'] ?? $row['module_key'] ?? ''),
            'version' => (string) ($row['version'] ?? ''),
            'type' => ((int) ($row['is_system'] ?? 0)) === 1 ? 'system' : 'client',
            'installed' => ((int) ($row['is_installed'] ?? 0)) === 1,
            'enabled' => ((int) ($row['is_enabled'] ?? 0)) === 1,
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'source' => 'database',
        ], $rows);
    }

    /** @param array<string,mixed> $manifest */
    private function moduleFromManifest(array $manifest, string $path): ?array
    {
        $key = trim((string) ($manifest['key'] ?? ''));
        if ($key === '') {
            return null;
        }

        return [
            'key' => $key,
            'name' => (string) ($manifest['name'] ?? $key),
            'version' => (string) ($manifest['version'] ?? ''),
            'type' => (string) ($manifest['type'] ?? ''),
            'installed' => null,
            'enabled' => null,
            'manifest_path' => $path,
        ];
    }

    /** @param mixed $modules @return array<string,array<string,mixed>> */
    private function modulesByKey(mixed $modules): array
    {
        if (!is_array($modules)) {
            return [];
        }
        $indexed = [];
        foreach ($modules as $module) {
            if (!is_array($module)) {
                continue;
            }
            $key = trim((string) ($module['key'] ?? $module['module_key'] ?? ''));
            if ($key !== '') {
                $module['key'] = $key;
                $indexed[$key] = $module;
            }
        }
        return $indexed;
    }

    /** @param mixed $databases @return array<string,array<string,mixed>> */
    private function databasesByKey(mixed $databases): array
    {
        if (!is_array($databases)) {
            return [];
        }
        $indexed = [];
        foreach ($databases as $database) {
            if (!is_array($database)) {
                continue;
            }
            $key = trim((string) ($database['key'] ?? ''));
            if ($key !== '') {
                $database['key'] = $key;
                $indexed[$key] = $database;
            }
        }
        return $indexed;
    }

    /** @param array<string,mixed> $candidate @param array<string,mixed> $current */
    private function isNewerCore(array $candidate, array $current): bool
    {
        return $this->compareTechnicalVersion(
            (string) ($candidate['technical_version'] ?? ''),
            (string) ($current['technical_version'] ?? '')
        ) > 0;
    }

    /** @param array<string,mixed> $candidate @param array<string,mixed> $current */
    private function isNewerModule(array $candidate, array $current): bool
    {
        return $this->compareGenericVersion(
            (string) ($candidate['version'] ?? ''),
            (string) ($current['version'] ?? '')
        ) > 0;
    }

    /** @param array<string,mixed> $candidate @param array<string,mixed> $current */
    private function isNewerDatabase(array $candidate, array $current): bool
    {
        return $this->compareGenericVersion(
            (string) ($candidate['expected_latest'] ?? ''),
            (string) ($current['expected_latest'] ?? '')
        ) > 0;
    }

    private function compareTechnicalVersion(string $left, string $right): int
    {
        $a = $this->technicalParts($left);
        $b = $this->technicalParts($right);
        if ($a !== null && $b !== null) {
            return $a <=> $b;
        }
        return $this->compareGenericVersion($left, $right);
    }

    /** @return array{0:string,1:int,2:int,3:int}|null */
    private function technicalParts(string $version): ?array
    {
        if (!preg_match('/^([A-Za-z][A-Za-z0-9_-]*)_v(\d+)-e(\d+)([a-z])$/', trim($version), $matches)) {
            return null;
        }
        return [$matches[1], (int) $matches[2], (int) $matches[3], ord($matches[4]) - 96];
    }

    private function compareGenericVersion(string $left, string $right): int
    {
        $left = trim($left);
        $right = trim($right);
        if ($left === $right) {
            return 0;
        }
        if ($left === '') {
            return -1;
        }
        if ($right === '') {
            return 1;
        }
        return version_compare($left, $right);
    }

    /** @return array<string,mixed>|null */
    private function readJsonFile(string $path): ?array
    {
        $raw = is_file($path) ? @file_get_contents($path) : false;
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (is_array($data) && isset($data['data']) && is_array($data['data']) && isset($data['data']['core'])) {
            $data = $data['data'];
        }
        return is_array($data) ? $data : null;
    }

    /** @return array<string,mixed>|null */
    private function fetchJson(string $url): ?array
    {
        if (!preg_match('#^https?://#i', $url)) {
            return null;
        }
        $headers = "Accept: application/json\r\nUser-Agent: webeLi-cms-version-check\r\n";
        $token = (string) env('APP_UPDATES_GITHUB_TOKEN', '');
        if ($token !== '' && (str_contains($url, 'githubusercontent.com') || str_contains($url, 'api.github.com'))) {
            $headers .= 'Authorization: Bearer ' . $token . "\r\n";
        }
        $context = stream_context_create([
            'http' => [
                'timeout' => max(1, (int) ($this->config['http_timeout_seconds'] ?? 4)),
                'ignore_errors' => true,
                'header' => $headers,
            ],
        ]);
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function publicBaseUrl(): string
    {
        $configured = (string) env('APP_PUBLIC_BASE_URL', '');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') {
            return '';
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $host . app_base_path();
    }

    private function gitCommit(): ?string
    {
        $head = @file_get_contents(base_path('.git/HEAD'));
        if ($head === false) {
            return null;
        }
        $head = trim($head);
        if (preg_match('/^ref:\s*(\S+)$/', $head, $matches)) {
            $ref = @file_get_contents(base_path('.git/' . $matches[1]));
            if ($ref !== false && trim($ref) !== '') {
                return substr(trim($ref), 0, 12);
            }
            $packed = @file_get_contents(base_path('.git/packed-refs'));
            if ($packed !== false && preg_match('/^([0-9a-f]{40})\s+' . preg_quote($matches[1], '/') . '$/m', $packed, $packedMatch)) {
                return substr($packedMatch[1], 0, 12);
            }
            return null;
        }
        return preg_match('/^[0-9a-f]{40}$/', $head) ? substr($head, 0, 12) : null;
    }

    private function gitBranch(): ?string
    {
        $head = @file_get_contents(base_path('.git/HEAD'));
        if ($head === false || !preg_match('/^ref:\s*refs\/heads\/(.+)$/', trim($head), $matches)) {
            return null;
        }
        return $matches[1];
    }
}
