<?php

declare(strict_types=1);

namespace App\Application\Maintenance;

final class DependencyInventoryService
{
    /** @var array<string,array<string,mixed>> */
    private array $composerInstalledPackages = [];
    private float $latestDeadline = 0.0;
    private string $composerVendorRoot = '';
    private bool $refreshLatest = false;
    private bool $manualRefresh = false;

    public function __construct(
        private readonly array $appConfig = [],
        private readonly array $config = [],
    ) {}

    /** @return array<string,mixed> */
    public function maintenancePayload(bool $refreshLatest = false, bool $manualRefresh = false): array
    {
        $this->refreshLatest = $refreshLatest;
        $this->manualRefresh = $manualRefresh;
        $budget = $manualRefresh
            ? ($this->config['dependencies']['refresh_request_budget_seconds'] ?? 45.0)
            : ($this->config['dependencies']['latest_request_budget_seconds'] ?? 3.0);
        $this->latestDeadline = microtime(true) + max(0.5, (float) $budget);
        $composer = $this->composerPackages();
        $npm = $this->npmPackages();

        return [
            'generated_at' => gmdate('c'),
            'latest_enabled' => $this->latestEnabled(),
            'latest_cache_ttl_seconds' => $this->latestCacheTtl(),
            'runtimes' => $this->runtimeInventory(),
            'paths' => $this->pathInventory($composer),
            'packages' => [...$composer, ...$npm],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function runtimeInventory(): array
    {
        $package = $this->readJsonFile(base_path('frontend/admin-vue/package.json')) ?? [];
        $engines = is_array($package['engines'] ?? null) ? $package['engines'] : [];
        $composer = $this->readJsonFile(base_path('backend/composer.json')) ?? [];
        $requires = is_array($composer['require'] ?? null) ? $composer['require'] : [];

        return [
            $this->runtimeRow(
                'php',
                'PHP',
                PHP_VERSION,
                (string) ($requires['php'] ?? ''),
                PHP_BINARY,
                'detected'
            ),
            $this->commandRuntime('composer', 'Composer', ['composer', 'composer2'], ['--version']),
            $this->commandRuntime('node', 'Node', ['node'], ['--version'], (string) ($engines['node'] ?? '')),
            $this->commandRuntime('npm', 'npm', ['npm'], ['--version'], (string) ($engines['npm'] ?? '')),
        ];
    }

    /** @return array<string,mixed> */
    private function runtimeRow(string $key, string $name, string $installed, string $required, string $path, string $status): array
    {
        $installed = $this->normalizeVersion($installed);
        $latest = $this->latestRuntimeVersion($key, $installed);
        return [
            'key' => $key,
            'name' => $name,
            'installed' => $installed,
            'latest' => $latest['version'],
            'latest_checked_at' => $latest['checked_at'],
            'latest_source' => $latest['source'],
            'required' => $required,
            'path' => $path,
            'status' => $status === 'absent' ? 'missing' : $this->dependencyStatus($installed, (string) $latest['version']),
        ];
    }

    /** @return array{version:string,source:string,checked_at:string} */
    private function latestRuntimeVersion(string $key, string $installed): array
    {
        if (!$this->latestEnabled()) {
            return ['version' => '', 'source' => 'disabled', 'checked_at' => ''];
        }

        $cache = $this->readLatestCache('runtime', $key);
        if (!$this->refreshLatest) {
            return $cache ?? ['version' => '', 'source' => 'cache_empty', 'checked_at' => ''];
        }
        if ($this->latestDeadline > 0.0 && microtime(true) >= $this->latestDeadline) {
            return $cache ?? ['version' => '', 'source' => 'budget_exceeded', 'checked_at' => ''];
        }

        [$version, $source] = match ($key) {
            'php' => [$this->fetchPhpLatest($installed), 'php_site'],
            'composer' => [$this->fetchComposerRuntimeLatest($installed), 'composer_site'],
            'node' => [$this->fetchNodeLatest($installed), 'node_site'],
            'npm' => [$this->fetchNpmRuntimeLatest($installed), 'npm_registry'],
            default => ['', 'unavailable'],
        };

        $row = [
            'version' => $version,
            'source' => $version !== '' ? $source : 'unavailable',
            'checked_at' => gmdate('c'),
        ];
        if ($version === '' && $cache !== null) {
            return $cache;
        }
        $this->writeLatestCache('runtime', $key, $row);
        return $row;
    }

    /*
     * PHP exposes releases per maintained branch. For runtime compatibility this
     * tracks the latest patch of the installed major.minor branch.
     */
    private function fetchPhpLatest(string $installed): string
    {
        $branch = $this->majorMinorBranch($installed);
        if ($branch === '') {
            return '';
        }
        $data = $this->fetchJson('https://www.php.net/releases/index.php?json&version=' . rawurlencode($branch));
        return $this->normalizeVersion((string) ($data['version'] ?? ''));
    }

    private function fetchComposerRuntimeLatest(string $installed): string
    {
        $data = $this->fetchJson('https://getcomposer.org/versions');
        if (!is_array($data)) {
            return '';
        }
        $major = $this->majorVersion($installed);
        $candidates = $major !== '' && is_array($data[$major] ?? null)
            ? $data[$major]
            : (is_array($data['stable'] ?? null) ? $data['stable'] : []);
        return $this->latestVersionFromRows($candidates);
    }

    private function fetchNodeLatest(string $installed): string
    {
        $data = $this->fetchJson('https://nodejs.org/dist/index.json');
        if (!is_array($data)) {
            return '';
        }
        $major = $this->majorVersion($installed);
        $best = '';
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $version = $this->normalizeVersion((string) ($row['version'] ?? ''));
            if ($version === '' || !$this->isStableVersion($version)) {
                continue;
            }
            if ($major !== '' && $this->majorVersion($version) !== $major) {
                continue;
            }
            if ($best === '' || version_compare($version, $best, '>')) {
                $best = $version;
            }
        }
        return $best;
    }

    private function fetchNpmRuntimeLatest(string $installed): string
    {
        $data = $this->fetchJson('https://registry.npmjs.org/npm');
        $versions = is_array($data['versions'] ?? null) ? array_keys($data['versions']) : [];
        $major = $this->majorVersion($installed);
        $best = '';
        foreach ($versions as $version) {
            $version = $this->normalizeVersion((string) $version);
            if ($version === '' || !$this->isStableVersion($version)) {
                continue;
            }
            if ($major !== '' && $this->majorVersion($version) !== $major) {
                continue;
            }
            if ($best === '' || version_compare($version, $best, '>')) {
                $best = $version;
            }
        }
        return $best;
    }

    /** @param mixed $rows */
    private function latestVersionFromRows(mixed $rows): string
    {
        if (!is_array($rows)) {
            return '';
        }
        $best = '';
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $version = $this->normalizeVersion((string) ($row['version'] ?? ''));
            if ($version === '' || !$this->isStableVersion($version)) {
                continue;
            }
            if ($best === '' || version_compare($version, $best, '>')) {
                $best = $version;
            }
        }
        return $best;
    }

    private function majorVersion(string $version): string
    {
        return preg_match('/^(\d+)/', $this->normalizeVersion($version), $matches) ? $matches[1] : '';
    }

    private function majorMinorBranch(string $version): string
    {
        return preg_match('/^(\d+\.\d+)/', $this->normalizeVersion($version), $matches) ? $matches[1] : '';
    }

    /** @return array<string,mixed> */
    private function absentRuntimeRow(string $key, string $name, string $required = ''): array
    {
        return [
            'key' => $key,
            'name' => $name,
            'installed' => '',
            'latest' => '',
            'latest_checked_at' => '',
            'latest_source' => 'unavailable',
            'required' => $required,
            'path' => '',
            'status' => 'missing',
        ];
    }

    /** @param list<string> $candidates @param list<string> $arguments @return array<string,mixed> */
    private function commandRuntime(string $key, string $name, array $candidates, array $arguments, string $required = ''): array
    {
        $path = $this->findExecutable($candidates);
        if ($path === null) {
            return $this->absentRuntimeRow($key, $name, $required);
        }

        return $this->runtimeRow($key, $name, $this->commandVersion($path, $arguments), $required, $path, 'detected');
    }

    /** @param list<string> $candidates */
    private function findExecutable(array $candidates): ?string
    {
        $paths = explode(PATH_SEPARATOR, (string) ($_SERVER['PATH'] ?? $_ENV['PATH'] ?? ''));
        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            if ($this->isAbsolutePath($candidate) && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
            foreach ($paths as $path) {
                if ($path === '') {
                    continue;
                }
                $executable = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $candidate;
                if (is_file($executable) && is_executable($executable)) {
                    return $executable;
                }
            }
        }
        return null;
    }

    /** @param list<string> $arguments */
    private function commandVersion(string $binary, array $arguments): string
    {
        if (!function_exists('proc_open')) {
            return '';
        }

        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open([$binary, ...$arguments], $descriptor, $pipes, base_path());
        if (!is_resource($process)) {
            return '';
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $deadline = microtime(true) + 1.5;
        $output = '';
        do {
            $output .= stream_get_contents($pipes[1]) ?: '';
            $output .= stream_get_contents($pipes[2]) ?: '';
            $status = proc_get_status($process);
            if (!($status['running'] ?? false)) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        foreach ([$pipes[1], $pipes[2]] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $status = proc_get_status($process);
        if (($status['running'] ?? false)) {
            proc_terminate($process);
        }
        proc_close($process);

        $firstLine = trim((string) strtok(trim($output), "\n"));
        if (preg_match('/(?:version\s*)?v?([0-9]+(?:\.[0-9]+)+(?:[-+][A-Za-z0-9._-]+)?)/i', $firstLine, $matches)) {
            return $matches[1];
        }
        return $firstLine;
    }

    /** @return list<array<string,mixed>> */
    private function composerPackages(): array
    {
        $lock = $this->readJsonFile(base_path('backend/composer.lock')) ?? [];
        $packages = is_array($lock['packages'] ?? null) ? $lock['packages'] : [];
        $this->composerInstalledPackages = $this->composerInstalledPackages();
        $rows = [];

        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }
            $name = (string) ($package['name'] ?? '');
            if ($name === '' || !$this->isTrackedComposerPackage($name)) {
                continue;
            }
            $installed = $this->normalizeVersion((string) ($package['version'] ?? ''));
            $latest = $this->latestVersion('composer', $name);
            $path = $this->composerPackagePath($name);
            $rows[] = [
                'manager' => 'composer',
                'name' => $name,
                'label' => $this->packageLabel($name),
                'installed' => $installed,
                'latest' => $latest['version'],
                'latest_checked_at' => $latest['checked_at'],
                'latest_source' => $latest['source'],
                'path' => $path,
                'path_exists' => $path !== '' && is_dir($path),
                'source_path' => 'backend/composer.lock',
                'direct' => $this->isDirectComposerPackage($name),
                'status' => $this->dependencyStatus($installed, (string) $latest['version']),
            ];
        }

        usort($rows, static fn(array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));
        return $rows;
    }

    private function isTrackedComposerPackage(string $name): bool
    {
        return $name === 'twig/twig' || str_starts_with($name, 'symfony/');
    }

    private function isDirectComposerPackage(string $name): bool
    {
        $composer = $this->readJsonFile(base_path('backend/composer.json')) ?? [];
        $requires = is_array($composer['require'] ?? null) ? $composer['require'] : [];
        return array_key_exists($name, $requires);
    }

    /** @return array<string,array<string,mixed>> */
    private function composerInstalledPackages(): array
    {
        $this->composerVendorRoot = '';
        foreach ([base_path('backend/vendor'), base_path('vendor'), base_path('../vendor')] as $vendorRoot) {
            if (is_file($vendorRoot . '/composer/installed.json')) {
                $this->composerVendorRoot = $vendorRoot;
                break;
            }
        }

        $installed = $this->composerVendorRoot !== ''
            ? ($this->readJsonFile($this->composerVendorRoot . '/composer/installed.json') ?? [])
            : [];
        $packages = is_array($installed['packages'] ?? null) ? $installed['packages'] : (is_array($installed[0] ?? null) ? $installed : []);
        $indexed = [];
        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }
            $name = (string) ($package['name'] ?? '');
            if ($name !== '') {
                $indexed[$name] = $package;
            }
        }
        return $indexed;
    }

    private function composerPackagePath(string $name): string
    {
        $installed = $this->composerInstalledPackages[$name] ?? null;
        $installPath = is_array($installed) ? (string) ($installed['install-path'] ?? '') : '';
        if ($installPath !== '' && $this->composerVendorRoot !== '') {
            return $this->normalizeAbsolutePath($this->composerVendorRoot . '/composer/' . $installPath);
        }
        if ($name === 'twig/twig') {
            $twigPath = $this->twigRuntimePath('', (string) ($this->appConfig['twig_vendor_path'] ?? base_path('vendor/twig')));
            if (is_dir($twigPath)) {
                return $this->normalizeAbsolutePath($twigPath);
            }
        }
        foreach ([base_path('backend/vendor/' . $name), base_path('vendor/' . $name), base_path('../vendor/' . $name)] as $candidate) {
            if (is_dir($candidate)) {
                return $this->normalizeAbsolutePath($candidate);
            }
        }
        return $this->normalizeAbsolutePath(base_path('backend/vendor/' . $name));
    }

    /** @return list<array<string,mixed>> */
    private function npmPackages(): array
    {
        $lock = $this->readJsonFile(base_path('frontend/admin-vue/package-lock.json')) ?? [];
        $packages = is_array($lock['packages'] ?? null) ? $lock['packages'] : [];
        $root = is_array($packages[''] ?? null) ? $packages[''] : [];
        $dependencies = is_array($root['dependencies'] ?? null) ? $root['dependencies'] : [];
        $devDependencies = is_array($root['devDependencies'] ?? null) ? $root['devDependencies'] : [];
        $tracked = array_unique([...array_keys($dependencies), ...array_keys($devDependencies)]);
        sort($tracked, SORT_NATURAL | SORT_FLAG_CASE);

        $rows = [];
        foreach ($tracked as $name) {
            $package = $this->npmLockPackage($lock, (string) $name);
            $installed = (string) ($package['version'] ?? ($dependencies[$name] ?? $devDependencies[$name] ?? ''));
            $latest = $this->latestVersion('npm', (string) $name);
            $path = $this->normalizeAbsolutePath(base_path('frontend/admin-vue/node_modules/' . $name));
            $rows[] = [
                'manager' => 'npm',
                'name' => (string) $name,
                'label' => $this->packageLabel((string) $name),
                'installed' => $this->normalizeVersion($installed),
                'latest' => $latest['version'],
                'latest_checked_at' => $latest['checked_at'],
                'latest_source' => $latest['source'],
                'path' => $path,
                'path_exists' => is_dir($path),
                'source_path' => 'frontend/admin-vue/package-lock.json',
                'direct' => true,
                'status' => $this->dependencyStatus($installed, (string) $latest['version']),
            ];
        }

        return $rows;
    }

    /** @param array<string,mixed> $lock @return array<string,mixed> */
    private function npmLockPackage(array $lock, string $name): array
    {
        $packages = is_array($lock['packages'] ?? null) ? $lock['packages'] : [];
        $key = 'node_modules/' . $name;
        return is_array($packages[$key] ?? null) ? $packages[$key] : [];
    }

    /** @param list<array<string,mixed>> $composerPackages @return list<array<string,mixed>> */
    private function pathInventory(array $composerPackages): array
    {
        $twigPackage = null;
        foreach ($composerPackages as $package) {
            if (($package['name'] ?? '') === 'twig/twig') {
                $twigPackage = $package;
                break;
            }
        }
        $twigConfigured = (string) ($this->appConfig['twig_vendor_path'] ?? base_path('vendor/twig'));
        $nodeConfigured = (string) ($this->appConfig['vue_node_modules_path'] ?? base_path('vendor/node_modules'));
        $twigRuntime = $this->twigRuntimePath((string) ($twigPackage['path'] ?? ''), $twigConfigured);

        return [
            $this->pathRow('backend_vendor', 'Vendor PHP backend', base_path('backend/vendor'), is_file(base_path('backend/vendor/autoload.php')), 'Autoload Composer principal.'),
            $this->pathRow('root_vendor', 'Vendor PHP racine', base_path('vendor'), is_file(base_path('vendor/autoload.php')), 'Vendor partagé optionnel.'),
            $this->pathRow('parent_vendor', 'Vendor PHP parent', base_path('../vendor'), is_file(base_path('../vendor/autoload.php')), 'Fallback runtime ../vendor.'),
            $this->pathRow('twig_runtime', 'Twig runtime', $twigRuntime, is_dir($twigRuntime), 'Chemin Twig effectivement résolu.'),
            $this->pathRow('twig_configured', 'Twig configuré', $twigConfigured, is_dir($twigConfigured), 'APP_TWIG_VENDOR_PATH ou valeur par défaut.'),
            $this->pathRow('admin_node_modules', 'node_modules admin', base_path('frontend/admin-vue/node_modules'), is_dir(base_path('frontend/admin-vue/node_modules')), 'Dépendances utilisées pour compiler le back-office.'),
            $this->pathRow('admin_node_modules_configured', 'node_modules configuré', $nodeConfigured, is_dir($nodeConfigured), 'APP_VUE_NODE_MODULES_PATH ou valeur par défaut.'),
            $this->pathRow('admin_assets', 'Assets admin', base_path('admin-app/assets'), is_dir(base_path('admin-app/assets')), 'Assets Vue compilés servis en production.'),
            $this->pathRow('composer_lock', 'Lock Composer', base_path('backend/composer.lock'), is_file(base_path('backend/composer.lock')), 'Source des versions PHP installées.'),
            $this->pathRow('npm_lock', 'Lock npm', base_path('frontend/admin-vue/package-lock.json'), is_file(base_path('frontend/admin-vue/package-lock.json')), 'Source des versions npm installées.'),
        ];
    }

    private function twigRuntimePath(string $composerPath, string $configuredPath): string
    {
        foreach ([
            $composerPath,
            $configuredPath,
            $configuredPath . '/twig',
            $configuredPath . '/twig/twig',
            base_path('vendor/twig'),
            base_path('vendor/twig/twig'),
            base_path('../vendor/twig'),
            base_path('../vendor/twig/twig'),
        ] as $candidate) {
            $candidate = rtrim((string) $candidate, DIRECTORY_SEPARATOR . '/');
            if ($candidate !== '' && is_file($candidate . '/src/Environment.php')) {
                return $candidate;
            }
        }
        return $composerPath !== '' ? $composerPath : $configuredPath;
    }

    /** @return array<string,mixed> */
    private function pathRow(string $key, string $label, string $path, bool $active, string $detail): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'path' => $this->normalizeAbsolutePath($path),
            'exists' => is_file($path) || is_dir($path),
            'active' => $active,
            'detail' => $detail,
        ];
    }

    /** @return array{version:string,source:string,checked_at:string} */
    private function latestVersion(string $manager, string $name): array
    {
        if (!$this->latestEnabled()) {
            return ['version' => '', 'source' => 'disabled', 'checked_at' => ''];
        }

        $cache = $this->readLatestCache($manager, $name);
        if (!$this->refreshLatest) {
            return $cache ?? ['version' => '', 'source' => 'cache_empty', 'checked_at' => ''];
        }
        if ($this->latestDeadline > 0.0 && microtime(true) >= $this->latestDeadline) {
            return $cache ?? ['version' => '', 'source' => 'budget_exceeded', 'checked_at' => ''];
        }

        $version = $manager === 'composer'
            ? $this->fetchPackagistLatest($name)
            : $this->fetchNpmLatest($name);
        $row = [
            'version' => $version,
            'source' => $version !== '' ? $manager . '_registry' : 'unavailable',
            'checked_at' => gmdate('c'),
        ];
        if ($version === '' && $cache !== null) {
            return $cache;
        }
        $this->writeLatestCache($manager, $name, $row);
        return $row;
    }

    /** @return array{version:string,source:string,checked_at:string}|null */
    private function readLatestCache(string $manager, string $name): ?array
    {
        $path = $this->latestCachePath($manager, $name);
        $raw = is_file($path) ? @file_get_contents($path) : false;
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        return [
            'version' => (string) ($data['version'] ?? ''),
            'source' => 'cache',
            'checked_at' => (string) ($data['checked_at'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $row */
    private function writeLatestCache(string $manager, string $name, array $row): void
    {
        $path = $this->latestCachePath($manager, $name);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($path, json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function latestCachePath(string $manager, string $name): string
    {
        return base_path('storage/cache/maintenance-dependencies/' . $manager . '-' . sha1($name) . '.json');
    }

    private function fetchPackagistLatest(string $name): string
    {
        $data = $this->fetchJson('https://repo.packagist.org/p2/' . $name . '.json');
        $packages = is_array($data['packages'][$name] ?? null) ? $data['packages'][$name] : [];
        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }
            $version = $this->normalizeVersion((string) ($package['version'] ?? ''));
            if ($version !== '' && $this->isStableVersion($version)) {
                return $version;
            }
        }
        return '';
    }

    private function fetchNpmLatest(string $name): string
    {
        $data = $this->fetchJson('https://registry.npmjs.org/' . rawurlencode($name) . '/latest');
        return $this->normalizeVersion((string) ($data['version'] ?? ''));
    }

    /** @return array<string,mixed>|null */
    private function fetchJson(string $url): ?array
    {
        $timeout = $this->manualRefresh
            ? ($this->config['dependencies']['refresh_http_timeout_seconds'] ?? 6)
            : ($this->config['dependencies']['http_timeout_seconds'] ?? 1);
        $context = stream_context_create([
            'http' => [
                'timeout' => max(1, (int) $timeout),
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nUser-Agent: webeLi-cms-dependency-check\r\n",
            ],
        ]);
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function dependencyStatus(string $installed, string $latest): string
    {
        $installed = $this->normalizeVersion($installed);
        $latest = $this->normalizeVersion($latest);
        if ($installed === '') {
            return 'missing';
        }
        if ($latest === '') {
            return 'latest_unknown';
        }
        return version_compare($latest, $installed, '>') ? 'update_available' : 'up_to_date';
    }

    private function normalizeVersion(string $version): string
    {
        $version = trim($version);
        return preg_replace('/^v(?=\d)/i', '', $version) ?? $version;
    }

    private function isStableVersion(string $version): bool
    {
        return !preg_match('/(?:dev|alpha|a\d*|beta|b\d*|rc\d*)/i', $version);
    }

    private function packageLabel(string $name): string
    {
        return match ($name) {
            'twig/twig' => 'Twig',
            'vue' => 'Vue',
            'vite' => 'Vite',
            'vue-router' => 'Vue Router',
            'pinia' => 'Pinia',
            'bootstrap' => 'Bootstrap',
            '@vitejs/plugin-vue' => 'Vite Vue',
            '@playwright/test' => 'Playwright',
            'typescript' => 'TypeScript',
            'vue-tsc' => 'vue-tsc',
            default => str_starts_with($name, 'symfony/') ? 'Symfony ' . substr($name, 8) : $name,
        };
    }

    /** @return array<string,mixed>|null */
    private function readJsonFile(string $path): ?array
    {
        $raw = is_file($path) ? @file_get_contents($path) : false;
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function latestEnabled(): bool
    {
        return (bool) ($this->config['dependencies']['latest_enabled'] ?? true);
    }

    private function latestCacheTtl(): int
    {
        return max(300, (int) ($this->config['dependencies']['latest_cache_ttl_seconds'] ?? 43200));
    }

    private function normalizeAbsolutePath(string $path): string
    {
        $real = realpath($path);
        if ($real !== false) {
            return str_replace('\\', '/', $real);
        }
        return str_replace('\\', '/', $path);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }
}
