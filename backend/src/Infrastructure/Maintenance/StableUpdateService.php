<?php

declare(strict_types=1);

namespace App\Infrastructure\Maintenance;

use Closure;
use FilesystemIterator;
use PDO;
use PharData;
use RecursiveIteratorIterator;
use Throwable;

/** Applies one verified stable component package at a time. */
final class StableUpdateService
{
    private const PROTECTED_PREFIXES = [
        'storage/database/',
        'storage/media/',
        'storage/uploads/',
        'storage/logs/',
        'storage/backups/',
        'storage/cache/',
        'storage/exports/',
        'storage/operations/',
        'storage/updates/',
        'local/',
    ];
    private const PROTECTED_FILES = [
        'ops/.env',
        'ops/modules.local.json',
        '.env',
    ];
    private const REQUIRED_CORE_FILES = [
        'index.php',
        'backend/public/index.php',
        'backend/bootstrap/runtime.php',
        'backend/bin/console',
        'config/release.json',
    ];

    private readonly Closure $httpGet;
    private readonly Closure $commandRunner;

    /**
     * @param null|Closure(string,int,int):string $httpGet
     * @param null|Closure(list<string>,string,string):array{exit_code:int,stdout:string,stderr:string} $commandRunner
     */
    public function __construct(
        private readonly StableUpdateCatalogService $catalogs,
        private readonly array $config = [],
        ?Closure $httpGet = null,
        ?Closure $commandRunner = null,
    ) {
        $this->httpGet = $httpGet ?? $this->download(...);
        $this->commandRunner = $commandRunner ?? $this->runCommand(...);
    }

    /**
     * @return array{component_type:string,component_key:string,version:string,file_count:int,backup_path:string,migrations_output:string,reload_required:bool}
     */
    public function apply(string $type, string $key, string $expectedVersion, string $expectedCatalogFingerprint): array
    {
        if (!in_array($type, ['core', 'module'], true)) {
            throw new StableUpdateException('Le type de composant demandé est invalide.');
        }
        if (preg_match('/^[a-z][a-z0-9-]{1,63}$/', $key) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $expectedVersion) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $expectedCatalogFingerprint) !== 1) {
            throw new StableUpdateException('La publication attendue doit être vérifiée à nouveau.');
        }

        $status = $this->catalogs->status(true);
        $catalog = is_array($status['catalog'] ?? null) ? $status['catalog'] : null;
        if ($catalog === null || ($status['error'] ?? null) !== null) {
            throw new StableUpdateException((string) ($status['error'] ?? 'Le catalogue stable est indisponible.'));
        }
        if (!hash_equals($expectedCatalogFingerprint, $this->catalogs->catalogFingerprint($catalog))) {
            throw new StableUpdateException('Le catalogue stable a changé. Actualisez les versions avant de continuer.');
        }

        $component = $this->componentFromStatus($status, $type, $key);
        if (!hash_equals($expectedVersion, (string) ($component['version'] ?? ''))
            || !($component['update_available'] ?? false)
            || !($component['update_allowed'] ?? false)) {
            throw new StableUpdateException((string) ($component['blocked_reason'] ?? 'Cette mise à jour stable n’est plus applicable.'));
        }

        $root = $this->projectRoot();
        $updates = $root . '/storage/updates';
        $this->ensureDirectory($updates);
        if (is_link($updates)) {
            throw new StableUpdateException('Le dossier de mise à jour ne peut pas être un lien symbolique.');
        }
        $lock = fopen($updates . '/stable-update.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new StableUpdateException('Une autre mise à jour est déjà en cours.');
        }

        $token = bin2hex(random_bytes(6));
        $work = $updates . '/work-' . $token;
        $backup = $root . '/storage/backups/updates/' . gmdate('Ymd-His') . '-' . $type . '-' . $key . '-' . $token;
        $archive = $work . '/component.zip';
        $extract = $work . '/extract';
        $maintenance = $root . '/storage/maintenance.flag';
        $touched = [];
        $migrationStarted = false;

        try {
            $this->ensureDirectory($work);
            $this->ensureDirectory($extract);
            $this->ensureDirectory($backup . '/code');
            $archiveBytes = ($this->httpGet)(
                (string) $component['archive_url'],
                max(1_000_000, (int) ($this->config['max_archive_bytes'] ?? 150_000_000)),
                120
            );
            if (file_put_contents($archive, $archiveBytes, LOCK_EX) === false
                || !hash_equals((string) $component['sha256'], hash_file('sha256', $archive) ?: '')) {
                throw new StableUpdateException('L’empreinte SHA-256 de l’archive est invalide.');
            }

            [$releaseRoot, $manifest] = $this->extractAndVerify($archive, $extract, $component);
            $files = array_keys($manifest['files']);
            $this->assertTargetsWritable($root, $files);
            $this->backupDatabases($root, $backup . '/databases');

            $oldManifest = $this->installedComponentManifest($type, $key);
            $oldFiles = is_array($oldManifest['files'] ?? null) ? array_keys($oldManifest['files']) : [];
            $managed = array_values(array_unique(array_merge($oldFiles, $files)));
            foreach ($managed as $relative) {
                if (!$this->safeManagedPath($relative, $type, $key, $manifest)) {
                    continue;
                }
                $target = $root . '/' . $relative;
                if (!is_file($target)) {
                    continue;
                }
                $saved = $backup . '/code/' . $relative;
                $this->ensureDirectory(dirname($saved));
                if (!copy($target, $saved)) {
                    throw new StableUpdateException("La sauvegarde du fichier {$relative} a échoué.");
                }
            }
            $this->writeJson($backup . '/previous-component-manifest.json', $oldManifest);

            if (file_put_contents($maintenance, json_encode([
                'started_at' => gmdate('c'),
                'component_type' => $type,
                'component_key' => $key,
                'target_version' => $expectedVersion,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
                throw new StableUpdateException('Le mode maintenance ne peut pas être activé.');
            }

            foreach ($files as $relative) {
                $touched[] = $relative;
                $this->copyAtomic($releaseRoot . '/' . $relative, $root . '/' . $relative);
            }
            foreach (array_diff($oldFiles, $files) as $relative) {
                if (!$this->safeManagedPath($relative, $type, $key, $manifest)) {
                    continue;
                }
                $target = $root . '/' . $relative;
                if (is_file($target)) {
                    $touched[] = $relative;
                    if (!unlink($target)) {
                        throw new StableUpdateException("L’ancien fichier {$relative} ne peut pas être retiré.");
                    }
                }
            }

            $migrationStarted = true;
            $command = $type === 'core' ? 'migrate' : 'modules:sync';
            $migration = ($this->commandRunner)(
                [PHP_BINARY, $root . '/backend/bin/console', $command],
                $root,
                $backup
            );
            if ((int) ($migration['exit_code'] ?? 1) !== 0) {
                throw new StableUpdateException(
                    'Les migrations ont échoué : ' . trim((string) ($migration['stderr'] ?? $migration['stdout'] ?? ''))
                );
            }

            $installedPath = $this->installedManifestPath($type, $key);
            $this->writeJson($installedPath, $manifest + [
                'installed_at' => gmdate('c'),
                'catalog_fingerprint' => $expectedCatalogFingerprint,
            ]);
            $this->writeJson($backup . '/operation.json', [
                'status' => 'completed',
                'component' => $manifest['component'],
                'file_count' => count($files),
                'completed_at' => gmdate('c'),
            ]);
            @unlink($maintenance);
            @unlink($root . '/storage/updates/stable-catalog-cache.json');
            $this->removeTree($work);
            flock($lock, LOCK_UN);
            fclose($lock);
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            return [
                'component_type' => $type,
                'component_key' => $key,
                'version' => $expectedVersion,
                'file_count' => count($files),
                'backup_path' => $this->relativeRootPath($backup),
                'migrations_output' => trim((string) ($migration['stdout'] ?? '')),
                'reload_required' => true,
            ];
        } catch (Throwable $exception) {
            foreach (array_reverse(array_values(array_unique($touched))) as $relative) {
                $saved = $backup . '/code/' . $relative;
                $target = $root . '/' . $relative;
                try {
                    if (is_file($saved)) {
                        $this->copyAtomic($saved, $target);
                    } elseif (is_file($target)) {
                        @unlink($target);
                    }
                } catch (Throwable) {
                    // The backup remains available for manual recovery.
                }
            }
            if (!$migrationStarted) {
                @unlink($maintenance);
            } else {
                try {
                    $this->writeJson($backup . '/operation.json', [
                        'status' => 'recovery_required',
                        'error' => $exception->getMessage(),
                        'failed_at' => gmdate('c'),
                    ]);
                } catch (Throwable) {
                }
            }
            $this->removeTree($work);
            flock($lock, LOCK_UN);
            fclose($lock);
            throw $exception instanceof StableUpdateException
                ? $exception
                : new StableUpdateException('La mise à jour a échoué : ' . $exception->getMessage(), 0, $exception);
        }
    }

    /** @param array<string,mixed> $status @return array<string,mixed> */
    private function componentFromStatus(array $status, string $type, string $key): array
    {
        if ($type === 'core') {
            $core = is_array($status['core'] ?? null) ? $status['core'] : [];
            if (($core['key'] ?? '') !== 'core' || $key !== 'core') {
                throw new StableUpdateException('Le core stable est indisponible.');
            }
            return $core;
        }
        foreach (is_array($status['modules'] ?? null) ? $status['modules'] : [] as $module) {
            if (is_array($module) && ($module['key'] ?? '') === $key) {
                return $module;
            }
        }
        throw new StableUpdateException("Le module {$key} n’est pas publié sur le canal stable.");
    }

    /**
     * @param array<string,mixed> $catalogComponent
     * @return array{0:string,1:array<string,mixed>}
     */
    private function extractAndVerify(string $archive, string $destination, array $catalogComponent): array
    {
        if (!class_exists(PharData::class)) {
            throw new StableUpdateException('L’extension PHP Phar est requise.');
        }
        try {
            $phar = new PharData($archive);
            foreach (new RecursiveIteratorIterator($phar) as $entry) {
                $name = str_replace('\\', '/', (string) $entry->getPathname());
                if ($entry->isLink() || str_contains($name, "\0") || preg_match('~(?:^|/)\.\.(?:/|$)~', $name) === 1) {
                    throw new StableUpdateException('L’archive contient une entrée interdite.');
                }
            }
            $phar->extractTo($destination, null, true);
        } catch (StableUpdateException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new StableUpdateException('L’archive ne peut pas être extraite : ' . $exception->getMessage(), 0, $exception);
        }

        $entries = array_values(array_filter(scandir($destination) ?: [], static fn(string $name): bool => !in_array($name, ['.', '..'], true)));
        if (count($entries) !== 1 || !is_dir($destination . '/' . $entries[0])) {
            throw new StableUpdateException('La structure de l’archive de composant est invalide.');
        }
        $releaseRoot = $destination . '/' . $entries[0];
        $manifestPath = $releaseRoot . '/component-manifest.json';
        if (!is_file($manifestPath)) {
            throw new StableUpdateException('Le manifeste du composant manque dans l’archive.');
        }
        $decoded = json_decode((string) file_get_contents($manifestPath), true, 256, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new StableUpdateException('Le manifeste du composant est invalide.');
        }
        $manifest = $this->validateComponentManifest($decoded, $catalogComponent);
        $expectedFiles = array_keys($manifest['files']);
        $archiveFiles = [];
        $iterator = new RecursiveIteratorIterator(new \RecursiveDirectoryIterator($releaseRoot, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($releaseRoot) + 1));
            if ($relative !== 'component-manifest.json') {
                $archiveFiles[] = $relative;
            }
        }
        sort($archiveFiles);
        $sortedExpected = $expectedFiles;
        sort($sortedExpected);
        if ($archiveFiles !== $sortedExpected) {
            throw new StableUpdateException('Le contenu de l’archive diffère de son manifeste.');
        }
        foreach ($manifest['files'] as $relative => $metadata) {
            $path = $releaseRoot . '/' . $relative;
            if (!is_file($path)
                || !hash_equals((string) $metadata['sha256'], hash_file('sha256', $path) ?: '')) {
                throw new StableUpdateException("L’empreinte du fichier {$relative} est invalide.");
            }
        }
        return [$releaseRoot, $manifest];
    }

    /** @param array<string,mixed> $manifest @param array<string,mixed> $catalogComponent @return array<string,mixed> */
    public function validateComponentManifest(array $manifest, array $catalogComponent): array
    {
        if ((int) ($manifest['schema_version'] ?? 0) !== 1
            || (string) ($manifest['application'] ?? '') !== 'dec-cms'
            || (string) ($manifest['channel'] ?? '') !== 'stable') {
            throw new StableUpdateException('Le manifeste du composant ne concerne pas DEC CMS stable.');
        }
        $component = is_array($manifest['component'] ?? null) ? $manifest['component'] : [];
        foreach (['type', 'key', 'version'] as $field) {
            if (!hash_equals((string) ($catalogComponent[$field] ?? ''), (string) ($component[$field] ?? ''))) {
                throw new StableUpdateException('Le catalogue et l’archive ne désignent pas le même composant.');
            }
        }
        if (($component['type'] ?? '') === 'module'
            && !hash_equals((string) ($catalogComponent['requires_core'] ?? ''), (string) ($component['requires_core'] ?? ''))) {
            throw new StableUpdateException('La contrainte core du module a changé dans l’archive.');
        }
        if (($component['type'] ?? '') === 'module') {
            $catalogDatabases = array_values(is_array($catalogComponent['database_keys'] ?? null) ? $catalogComponent['database_keys'] : []);
            $archiveDatabases = array_values(is_array($component['database_keys'] ?? null) ? $component['database_keys'] : []);
            sort($catalogDatabases);
            sort($archiveDatabases);
            if ($catalogDatabases !== $archiveDatabases) {
                throw new StableUpdateException('Les bases possédées par le module diffèrent du catalogue.');
            }
            $this->validateModuleOwnershipDeclarations($component, $manifest);
        }
        $files = $manifest['files'] ?? null;
        if (!is_array($files) || $files === [] || count($files) > 20_000) {
            throw new StableUpdateException('L’inventaire de fichiers du composant est invalide.');
        }
        foreach ($files as $path => $metadata) {
            if (!is_string($path) || !is_array($metadata)
                || preg_match('/^[a-f0-9]{64}$/', (string) ($metadata['sha256'] ?? '')) !== 1
                || !$this->safeManagedPath($path, (string) $component['type'], (string) $component['key'], $manifest)) {
                throw new StableUpdateException("Le chemin {$path} n’est pas autorisé pour ce composant.");
            }
        }
        if (($component['type'] ?? '') === 'core') {
            foreach (self::REQUIRED_CORE_FILES as $required) {
                if (!isset($files[$required])) {
                    throw new StableUpdateException("Le fichier core requis {$required} manque.");
                }
            }
        }
        return $manifest;
    }

    /** @param array<string,mixed> $manifest */
    private function safeManagedPath(string $path, string $type, string $key, array $manifest): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0") || str_contains($path, '\\')) {
            return false;
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return false;
            }
        }
        if (in_array($path, self::PROTECTED_FILES, true)) {
            return false;
        }
        foreach (self::PROTECTED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }
        if ($type === 'core') {
            if (str_starts_with($path, 'backend/src/Modules/')
                || preg_match('#^database/migrations/(?!core/|iam/)#', $path) === 1
                || str_starts_with($path, 'database/modules/')) {
                return false;
            }
            return true;
        }

        $owned = is_array($manifest['owned_prefixes'] ?? null) ? $manifest['owned_prefixes'] : [];
        $shared = is_array($manifest['shared_prefixes'] ?? null) ? $manifest['shared_prefixes'] : [];
        foreach ([...$owned, ...$shared] as $prefix) {
            $prefix = trim((string) $prefix, '/');
            if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $component @param array<string,mixed> $manifest */
    private function validateModuleOwnershipDeclarations(array $component, array $manifest): void
    {
        $key = (string) ($component['key'] ?? '');
        $classDirectory = implode('', array_map(
            static fn(string $part): string => ucfirst($part),
            explode('-', $key)
        ));
        $allowedOwned = ['backend/src/Modules/' . $classDirectory];
        foreach (is_array($component['database_keys'] ?? null) ? $component['database_keys'] : [] as $databaseKey) {
            $databaseKey = (string) $databaseKey;
            $allowedOwned[] = 'database/migrations/' . $databaseKey;
            $allowedOwned[] = 'database/modules/' . $databaseKey . '.sql';
        }
        foreach (is_array($manifest['owned_prefixes'] ?? null) ? $manifest['owned_prefixes'] : [] as $prefix) {
            if (!in_array(trim((string) $prefix, '/'), $allowedOwned, true)) {
                throw new StableUpdateException("Le module {$key} revendique un chemin qui ne lui appartient pas.");
            }
        }
        foreach (is_array($manifest['shared_prefixes'] ?? null) ? $manifest['shared_prefixes'] : [] as $prefix) {
            if (trim((string) $prefix, '/') !== 'admin-app') {
                throw new StableUpdateException("Le module {$key} revendique un chemin partagé non autorisé.");
            }
        }
    }

    /** @param list<string> $files */
    private function assertTargetsWritable(string $root, array $files): void
    {
        foreach ($files as $relative) {
            $target = $root . '/' . $relative;
            if (is_link($target)) {
                throw new StableUpdateException("Le chemin {$relative} ne peut pas être un lien symbolique.");
            }
            $cursor = $root;
            foreach (array_slice(explode('/', $relative), 0, -1) as $part) {
                $cursor .= '/' . $part;
                if (is_link($cursor)) {
                    throw new StableUpdateException("Le parent du chemin {$relative} ne peut pas être un lien symbolique.");
                }
            }
            $probe = is_file($target) ? $target : dirname($target);
            while (!file_exists($probe) && dirname($probe) !== $probe) {
                $probe = dirname($probe);
            }
            if (!is_writable($probe)) {
                throw new StableUpdateException("Le chemin {$relative} n’est pas inscriptible.");
            }
        }
    }

    private function backupDatabases(string $root, string $destination): void
    {
        $this->ensureDirectory($destination);
        foreach (glob($root . '/storage/database/*.sqlite') ?: [] as $database) {
            if (is_link($database) || !is_file($database)) {
                continue;
            }
            $target = $destination . '/' . basename($database);
            $pdo = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('PRAGMA busy_timeout = 10000');
            $integrity = (string) $pdo->query('PRAGMA integrity_check')->fetchColumn();
            if ($integrity !== 'ok') {
                throw new StableUpdateException('La base ' . basename($database) . ' ne passe pas le contrôle d’intégrité.');
            }
            $quoted = str_replace("'", "''", $target);
            $pdo->exec("VACUUM INTO '{$quoted}'");
            $pdo = null;
        }
    }

    /** @return array<string,mixed> */
    private function installedComponentManifest(string $type, string $key): array
    {
        $path = $this->installedManifestPath($type, $key);
        if (is_file($path)) {
            try {
                $decoded = json_decode((string) file_get_contents($path), true, 256, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (Throwable) {
                // Fall back to the last full-release inventory below.
            }
        }

        // Existing installations predate per-component manifests. Their last
        // full-release inventory lets the first stable update also retire stale
        // managed files; safeManagedPath() still limits this list to the component.
        $legacyPath = $this->projectRoot() . '/storage/deployments/release-manifest.json';
        if (is_file($legacyPath)) {
            try {
                $legacy = json_decode((string) file_get_contents($legacyPath), true, 256, JSON_THROW_ON_ERROR);
                if (is_array($legacy) && is_array($legacy['files'] ?? null)) {
                    return ['files' => $legacy['files'], 'source' => 'legacy-release-manifest'];
                }
            } catch (Throwable) {
                // An unreadable legacy inventory must not prevent an update.
            }
        }
        return [];
    }

    private function installedManifestPath(string $type, string $key): string
    {
        return $this->projectRoot() . '/storage/updates/installed/' . $type . '-' . $key . '.json';
    }

    private function copyAtomic(string $source, string $destination): void
    {
        if (!is_file($source) || is_link($source)) {
            throw new StableUpdateException('Un fichier du composant est introuvable.');
        }
        $this->ensureDirectory(dirname($destination));
        $temporary = $destination . '.stable-update-' . bin2hex(random_bytes(4));
        if (!copy($source, $temporary) || !rename($temporary, $destination)) {
            @unlink($temporary);
            throw new StableUpdateException('Un fichier de l’application ne peut pas être remplacé.');
        }
        @chmod($destination, fileperms($source) & 0777);
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new StableUpdateException("Le dossier {$directory} ne peut pas être créé.");
        }
    }

    /** @param array<string,mixed> $payload */
    private function writeJson(string $path, array $payload): void
    {
        $this->ensureDirectory(dirname($path));
        if (file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", LOCK_EX) === false) {
            throw new StableUpdateException("Le fichier {$path} ne peut pas être écrit.");
        }
    }

    /** @param list<string> $command @return array{exit_code:int,stdout:string,stderr:string} */
    private function runCommand(array $command, string $cwd, string $logDirectory): array
    {
        if (!function_exists('proc_open')) {
            throw new StableUpdateException('La fonction PHP proc_open est requise pour les migrations.');
        }
        $stdoutPath = $logDirectory . '/migration.stdout.log';
        $stderrPath = $logDirectory . '/migration.stderr.log';
        $process = proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $stdoutPath, 'w'],
            2 => ['file', $stderrPath, 'w'],
        ], $pipes, $cwd);
        if (!is_resource($process)) {
            throw new StableUpdateException('Le processus de migration ne peut pas être lancé.');
        }
        $exit = proc_close($process);
        return [
            'exit_code' => $exit,
            'stdout' => is_file($stdoutPath) ? (string) file_get_contents($stdoutPath) : '',
            'stderr' => is_file($stderrPath) ? (string) file_get_contents($stderrPath) : '',
        ];
    }

    private function download(string $url, int $maxBytes, int $timeout): string
    {
        $prefix = 'https://github.com/antoinemelo/webeLi-cms/releases/download/';
        if (!str_starts_with($url, $prefix) || !function_exists('curl_init')) {
            throw new StableUpdateException('La source GitHub de l’archive n’est pas autorisée ou cURL manque.');
        }
        $contents = '';
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'dec-cms-stable-updater/1',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FAILONERROR => true,
            CURLOPT_WRITEFUNCTION => static function (mixed $curl, string $chunk) use (&$contents, $maxBytes): int {
                if (strlen($contents) + strlen($chunk) > $maxBytes) {
                    return 0;
                }
                $contents .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($ok !== true || $status !== 200) {
            throw new StableUpdateException('Téléchargement GitHub impossible' . ($error !== '' ? ' : ' . $error : '.'));
        }
        return $contents;
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $item) {
            $this->removeTree($item->getPathname());
        }
        @rmdir($path);
    }

    private function relativeRootPath(string $path): string
    {
        $root = $this->projectRoot() . '/';
        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    private function projectRoot(): string
    {
        $configured = trim((string) ($this->config['root_path'] ?? ''));
        return rtrim($configured !== '' ? $configured : base_path(), '/');
    }
}
