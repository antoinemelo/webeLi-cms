<?php

declare(strict_types=1);

namespace App\Infrastructure\Maintenance;

use Closure;
use Throwable;

/**
 * Reads the immutable stable component catalog published as a GitHub Release
 * asset. This service deliberately has no dev/staging apply path.
 */
final class StableUpdateCatalogService
{
    private const APPLICATION = 'dec-cms';
    private const CHANNEL = 'stable';
    private const REPOSITORY = 'antoinemelo/webeLi-cms';

    private readonly Closure $httpGet;

    /** @param null|Closure(string,int,int):string $httpGet */
    public function __construct(
        private readonly array $config = [],
        ?Closure $httpGet = null,
    ) {
        $this->httpGet = $httpGet ?? $this->download(...);
    }

    /** @return array<string,mixed> */
    public function status(bool $refresh = false): array
    {
        if (!(bool) ($this->config['enabled'] ?? true)) {
            return $this->emptyStatus('Le système de mise à jour stable est désactivé.');
        }

        $cached = $refresh ? null : $this->readCache();
        if ($cached === null) {
            try {
                $catalog = $this->fetchCatalog();
                $cached = [
                    'checked_at' => time(),
                    'catalog' => $catalog,
                    'error' => null,
                ];
            } catch (Throwable $exception) {
                $cached = [
                    'checked_at' => time(),
                    'catalog' => null,
                    'error' => $exception->getMessage(),
                ];
            }
            $this->writeCache($cached);
        }

        $catalog = is_array($cached['catalog'] ?? null) ? $cached['catalog'] : null;
        $installedCore = $this->installedCoreVersion();
        $stableCore = trim((string) ($catalog['core']['version'] ?? ''));
        $comparison = $stableCore === '' ? 0 : $this->compareCoreVersions($installedCore, $stableCore);
        $coreCurrent = $stableCore !== '' && $comparison === 0;
        $coreUpdateAvailable = $stableCore !== '' && $comparison < 0;
        $coreAhead = $stableCore !== '' && $comparison > 0;

        $installedModules = $this->installedModules();
        $modules = [];
        foreach (is_array($catalog['modules'] ?? null) ? $catalog['modules'] : [] as $component) {
            if (!is_array($component)) {
                continue;
            }
            $key = (string) ($component['key'] ?? '');
            $installed = (string) ($installedModules[$key]['version'] ?? '');
            $stable = (string) ($component['version'] ?? '');
            $available = $installed === '' || ($stable !== '' && version_compare($installed, $stable, '<'));
            $modules[] = [
                ...$component,
                'installed_version' => $installed !== '' ? $installed : null,
                'update_available' => $available,
                'update_allowed' => $available && $coreCurrent,
                'blocked_reason' => $available && !$coreCurrent
                    ? ($coreAhead
                        ? 'Le core installé est hors du canal stable.'
                        : 'Mettez d’abord DEC CMS à jour.')
                    : null,
            ];
        }

        return [
            'enabled' => true,
            'channel' => self::CHANNEL,
            'checked_at' => (int) ($cached['checked_at'] ?? 0),
            'error' => $cached['error'] ?? null,
            'repository' => self::REPOSITORY,
            'release_url' => $catalog['release_url'] ?? null,
            'catalog_fingerprint' => $catalog === null ? null : $this->catalogFingerprint($catalog),
            'core' => $catalog === null ? null : [
                ...$catalog['core'],
                'installed_version' => $installedCore !== '' ? $installedCore : null,
                'update_available' => $coreUpdateAvailable,
                'update_allowed' => $coreUpdateAvailable,
                'current' => $coreCurrent,
                'ahead_of_stable' => $coreAhead,
            ],
            'modules' => $modules,
            // Kept server-side for the apply endpoint; controllers must remove it
            // if they expose this payload without filtering.
            'catalog' => $catalog,
        ];
    }

    /** @return array<string,mixed> */
    public function fetchCatalog(): array
    {
        $apiUrl = (string) ($this->config['github_api_url']
            ?? 'https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest');
        $this->assertGithubApiUrl($apiUrl);
        $releaseRaw = ($this->httpGet)($apiUrl, 2_000_000, 15);
        $release = json_decode($releaseRaw, true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($release) || (bool) ($release['draft'] ?? true) || (bool) ($release['prerelease'] ?? true)) {
            throw new StableUpdateException('La dernière publication GitHub n’est pas une release stable exploitable.');
        }

        $assetName = trim((string) ($this->config['catalog_asset_name'] ?? 'dec-cms-stable.json'));
        $catalogUrl = '';
        foreach (is_array($release['assets'] ?? null) ? $release['assets'] : [] as $asset) {
            if (is_array($asset) && (string) ($asset['name'] ?? '') === $assetName) {
                $catalogUrl = (string) ($asset['browser_download_url'] ?? '');
                break;
            }
        }
        $this->assertReleaseAssetUrl($catalogUrl);

        $raw = ($this->httpGet)(
            $catalogUrl,
            max(1024, (int) ($this->config['max_catalog_bytes'] ?? 2_000_000)),
            20
        );
        $catalog = json_decode($raw, true, 256, JSON_THROW_ON_ERROR);
        if (!is_array($catalog)) {
            throw new StableUpdateException('Le catalogue stable GitHub est invalide.');
        }
        $catalog['release_url'] = (string) ($release['html_url'] ?? '');
        return $this->validateCatalog($catalog);
    }

    /** @param array<string,mixed> $catalog @return array<string,mixed> */
    public function validateCatalog(array $catalog): array
    {
        if ((int) ($catalog['schema_version'] ?? 0) !== 1
            || (string) ($catalog['application'] ?? '') !== self::APPLICATION
            || (string) ($catalog['channel'] ?? '') !== self::CHANNEL
            || (string) ($catalog['repository'] ?? '') !== self::REPOSITORY) {
            throw new StableUpdateException('Le catalogue ne concerne pas le canal stable de DEC CMS.');
        }

        $core = $catalog['core'] ?? null;
        if (!is_array($core)) {
            throw new StableUpdateException('Le composant core manque dans le catalogue stable.');
        }
        $catalog['core'] = $this->validateComponent($core, 'core');

        $modules = [];
        $keys = [];
        foreach (is_array($catalog['modules'] ?? null) ? $catalog['modules'] : [] as $module) {
            if (!is_array($module)) {
                throw new StableUpdateException('Une entrée module du catalogue est invalide.');
            }
            $module = $this->validateComponent($module, 'module');
            $key = (string) $module['key'];
            if (isset($keys[$key])) {
                throw new StableUpdateException("Le module {$key} est déclaré plusieurs fois.");
            }
            if (!hash_equals((string) $catalog['core']['version'], (string) $module['requires_core'])) {
                throw new StableUpdateException("Le module {$key} ne cible pas le core stable publié.");
            }
            $keys[$key] = true;
            $modules[] = $module;
        }
        usort($modules, static fn(array $a, array $b): int => strcmp((string) $a['key'], (string) $b['key']));
        $catalog['modules'] = $modules;
        return $catalog;
    }

    /** @param array<string,mixed> $component @return array<string,mixed> */
    private function validateComponent(array $component, string $expectedType): array
    {
        $type = (string) ($component['type'] ?? '');
        $key = trim((string) ($component['key'] ?? ''));
        $version = trim((string) ($component['version'] ?? ''));
        $sha = strtolower(trim((string) ($component['sha256'] ?? '')));
        $url = trim((string) ($component['archive_url'] ?? ''));
        if ($type !== $expectedType
            || preg_match('/^[a-z][a-z0-9-]{1,63}$/', $key) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $version) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $sha) !== 1) {
            throw new StableUpdateException("Le composant {$expectedType} est invalide.");
        }
        if ($expectedType === 'core' && ($key !== 'core' || preg_match('/^dec_v\d+-e\d+[a-z]$/', $version) !== 1)) {
            throw new StableUpdateException('La version du core stable est invalide.');
        }
        if ($expectedType === 'module'
            && preg_match('/^dec_v\d+-e\d+[a-z]$/', (string) ($component['requires_core'] ?? '')) !== 1) {
            throw new StableUpdateException("La contrainte core du module {$key} est invalide.");
        }
        if ($expectedType === 'module') {
            $databaseKeys = is_array($component['database_keys'] ?? null) ? $component['database_keys'] : [];
            foreach ($databaseKeys as $databaseKey) {
                if (!is_string($databaseKey) || preg_match('/^[a-z][a-z0-9-]{0,63}$/', $databaseKey) !== 1) {
                    throw new StableUpdateException("Une base déclarée par le module {$key} est invalide.");
                }
            }
            $component['database_keys'] = array_values(array_unique($databaseKeys));
        }
        $this->assertReleaseAssetUrl($url);
        $component['sha256'] = $sha;
        return $component;
    }

    /** @param array<string,mixed> $catalog */
    public function catalogFingerprint(array $catalog): string
    {
        $validated = $this->validateCatalog($catalog);
        return hash('sha256', json_encode([
            'schema_version' => $validated['schema_version'],
            'application' => $validated['application'],
            'channel' => $validated['channel'],
            'repository' => $validated['repository'],
            'core' => $validated['core'],
            'modules' => $validated['modules'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function installedCoreVersion(): string
    {
        $release = $this->readJson(base_path('config/release.json'));
        return trim((string) ($release['technical_version'] ?? ''));
    }

    /** @return array<string,array<string,mixed>> */
    private function installedModules(): array
    {
        $modules = [];
        foreach (glob(base_path('backend/src/Modules/*/module.json')) ?: [] as $path) {
            $manifest = $this->readJson($path);
            $key = trim((string) ($manifest['key'] ?? ''));
            if ($key !== '') {
                $modules[$key] = $manifest;
            }
        }
        return $modules;
    }

    private function compareCoreVersions(string $left, string $right): int
    {
        $pattern = '/^dec_v(\d+)-e(\d+)([a-z])$/';
        if (preg_match($pattern, $left, $a) === 1 && preg_match($pattern, $right, $b) === 1) {
            foreach ([[1, 1], [2, 2]] as [$ai, $bi]) {
                $comparison = (int) $a[$ai] <=> (int) $b[$bi];
                if ($comparison !== 0) {
                    return $comparison;
                }
            }
            return ord($a[3]) <=> ord($b[3]);
        }
        return version_compare($left, $right);
    }

    /** @return array<string,mixed>|null */
    private function readCache(): ?array
    {
        $cache = $this->readJson($this->cachePath());
        $ttl = max(60, (int) ($this->config['cache_ttl_seconds'] ?? 900));
        if ($cache === [] || (int) ($cache['checked_at'] ?? 0) < time() - $ttl) {
            return null;
        }
        return $cache;
    }

    /** @param array<string,mixed> $cache */
    private function writeCache(array $cache): void
    {
        try {
            $directory = dirname($this->cachePath());
            if (!is_dir($directory)) {
                mkdir($directory, 0770, true);
            }
            file_put_contents($this->cachePath(), json_encode($cache, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), LOCK_EX);
        } catch (Throwable) {
            // A cache failure must not hide the remote result.
        }
    }

    private function cachePath(): string
    {
        $configured = trim((string) ($this->config['cache_path'] ?? ''));
        return $configured !== '' ? $configured : base_path('storage/updates/stable-catalog-cache.json');
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 256, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string,mixed> */
    private function emptyStatus(string $error): array
    {
        return [
            'enabled' => false,
            'channel' => self::CHANNEL,
            'checked_at' => 0,
            'error' => $error,
            'repository' => self::REPOSITORY,
            'release_url' => null,
            'catalog_fingerprint' => null,
            'core' => null,
            'modules' => [],
            'catalog' => null,
        ];
    }

    private function assertGithubApiUrl(string $url): void
    {
        if ($url !== 'https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest') {
            throw new StableUpdateException('La source API GitHub configurée n’est pas autorisée.');
        }
    }

    private function assertReleaseAssetUrl(string $url): void
    {
        $prefix = 'https://github.com/' . self::REPOSITORY . '/releases/download/';
        if ($url === '' || !str_starts_with($url, $prefix) || str_contains($url, "\0")) {
            throw new StableUpdateException('Une adresse d’artefact GitHub n’est pas autorisée.');
        }
    }

    private function download(string $url, int $maxBytes, int $timeout): string
    {
        if (!function_exists('curl_init')) {
            throw new StableUpdateException('L’extension PHP cURL est requise.');
        }
        $contents = '';
        $handle = curl_init($url);
        $headers = ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'];
        $token = trim((string) ($_ENV['APP_UPDATES_GITHUB_TOKEN'] ?? $_SERVER['APP_UPDATES_GITHUB_TOKEN'] ?? getenv('APP_UPDATES_GITHUB_TOKEN') ?: ''));
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'dec-cms-stable-updater/1',
            CURLOPT_HTTPHEADER => $headers,
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
            if ($status === 404 && str_contains($url, '/releases/latest')) {
                throw new StableUpdateException('Aucune publication stable GitHub n’est encore disponible.');
            }
            throw new StableUpdateException('GitHub est indisponible' . ($error !== '' ? ' : ' . $error : '.'));
        }
        return $contents;
    }
}
