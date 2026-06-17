<?php

declare(strict_types=1);

namespace App\StaticExport;

/**
 * Historique filesystem des exports statiques.
 *
 * Cette classe ne crée aucune table SQL: l'interface d'administration lit les
 * releases depuis storage/exports/static/<release_id>/ afin de garder Jamstack
 * comme une capacité technique de publication, optionnelle et réversible.
 */
final class StaticExportReleaseRepository
{
    private const SAFE_RELEASE = '/^[A-Za-z0-9._-]{8,80}$/';

    public function __construct(private readonly string $baseDir = '', private readonly string $adminApiBasePath = '') {}

    /** @return list<array<string,mixed>> */
    public function list(int $limit = 12, ?string $siteKey = null): array
    {
        $base = $this->baseDirectory();
        if (!is_dir($base)) {
            return [];
        }
        $rows = [];
        foreach (scandir($base) ?: [] as $name) {
            if ($name === '.' || $name === '..' || !preg_match(self::SAFE_RELEASE, $name)) {
                continue;
            }
            $dir = $base . '/' . $name;
            if (!is_dir($dir)) {
                continue;
            }
            $manifest = $this->readJson($dir . '/static-export-manifest.json');
            $report = $this->readJson($dir . '/static-export-report.json');
            $summary=$this->summary($name, $dir, $manifest, $report);
            if($siteKey!==null && $siteKey!=='' && (string)($summary['site']??'')!==$siteKey){continue;}
            $rows[] = $summary;
        }
        usort($rows, static fn(array $a, array $b): int => strcmp((string) ($b['generated_at'] ?? ''), (string) ($a['generated_at'] ?? '')) ?: strcmp((string) $b['release_id'], (string) $a['release_id']));
        return array_slice($rows, 0, max(1, $limit));
    }

    /** @return array<string,mixed>|null */
    public function latest(?string $siteKey = null): ?array
    {
        $rows = $this->list(1, $siteKey);
        return $rows[0] ?? null;
    }

    /** @return array<string,mixed>|null */
    public function find(string $releaseId): ?array
    {
        if (!preg_match(self::SAFE_RELEASE, $releaseId)) {
            return null;
        }
        $dir = $this->baseDirectory() . '/' . $releaseId;
        if (!is_dir($dir)) {
            return null;
        }
        return $this->summary($releaseId, $dir, $this->readJson($dir . '/static-export-manifest.json'), $this->readJson($dir . '/static-export-report.json'));
    }

    /** @return array<string,mixed>|null */
    public function manifest(string $releaseId): ?array
    {
        $release = $this->find($releaseId);
        return $release ? $this->readJson((string) $release['manifest_path']) : null;
    }

    /** @return array<string,mixed>|null */
    public function report(string $releaseId): ?array
    {
        $release = $this->find($releaseId);
        return $release ? $this->readJson((string) $release['report_path']) : null;
    }


    public function delete(string $releaseId): bool
    {
        if (!preg_match(self::SAFE_RELEASE, $releaseId)) {
            return false;
        }
        $dir = $this->baseDirectory() . '/' . $releaseId;
        if (!is_dir($dir) || !$this->isInsideBase($dir)) {
            return false;
        }
        return $this->removeDirectory($dir) && !is_dir($dir);
    }

    private function removeDirectory(string $dir): bool
    {
        $ok=true;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $removed=$file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
            if(!$removed){$ok=false;}
        }
        if(!@rmdir($dir)){$ok=false;}
        return $ok;
    }

    public function filePath(string $releaseId, string $kind): ?string
    {
        $release = $this->find($releaseId);
        if (!$release) {
            return null;
        }
        $key = match ($kind) {
            'manifest' => 'manifest_path',
            'report' => 'report_path',
            'zip' => 'zip_path',
            'editorial_zip' => 'editorial_zip_path',
            default => '',
        };
        if ($key === '' || empty($release[$key])) {
            return null;
        }
        $path = (string) $release[$key];
        return is_file($path) && $this->isInsideBase($path) ? $path : null;
    }

    private function baseDirectory(): string
    {
        return rtrim($this->baseDir !== '' ? $this->baseDir : base_path('storage/exports/static'), '/');
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $json = json_decode((string) file_get_contents($path), true);
        return is_array($json) ? $json : [];
    }

    /** @param array<string,mixed> $manifest @param array<string,mixed> $report @return array<string,mixed> */
    private function summary(string $releaseId, string $dir, array $manifest, array $report): array
    {
        $errors = $manifest['errors'] ?? $report['errors'] ?? [];
        $warnings = $manifest['warnings'] ?? $report['warnings'] ?? [];
        $status = $this->status($manifest, $report);
        $zip = $dir . '/static-export.zip';
        $editorialZip = $dir . '/editorial-export.zip';
        return [
            'release_id' => $releaseId,
            'status' => $status,
            'site' => $manifest['site'] ?? null,
            'languages' => array_values((array) ($manifest['languages'] ?? [])),
            'mode' => (string) ($manifest['mode'] ?? 'unknown'),
            'route' => $this->routePath($manifest, $report),
            'routes' => (array) ($manifest['routes'] ?? []),
            'exported_routes' => (int) (($manifest['routes']['exported'] ?? 0)),
            'ignored_routes' => (int) (($manifest['routes']['ignored'] ?? 0)),
            'errors_count' => is_array($errors) ? count($errors) : 0,
            'warnings_count' => is_array($warnings) ? count($warnings) : 0,
            'generated_at' => (string) ($manifest['generated_at'] ?? date('c', filemtime($dir) ?: time())),
            'duration_seconds' => $manifest['duration_seconds'] ?? null,
            'output_path' => (string) ($manifest['output_path'] ?? $dir . '/public'),
            'release_dir' => $dir,
            'manifest_path' => $dir . '/static-export-manifest.json',
            'report_path' => $dir . '/static-export-report.json',
            'zip_path' => is_file($zip) ? $zip : null,
            'editorial_zip_path' => is_file($editorialZip) ? $editorialZip : null,
            'artifacts' => (array) ($manifest['artifacts'] ?? $report['artifacts'] ?? []),
            'links' => [
                'manifest' => $this->adminLink('/imports-exports/static/' . rawurlencode($releaseId) . '/manifest'),
                'report' => $this->adminLink('/imports-exports/static/' . rawurlencode($releaseId) . '/report'),
                'zip' => is_file($zip) ? $this->adminLink('/imports-exports/static/' . rawurlencode($releaseId) . '/zip') : null,
                'static_zip' => is_file($zip) ? $this->adminLink('/imports-exports/static/' . rawurlencode($releaseId) . '/zip') : null,
                'editorial_zip' => is_file($editorialZip) ? $this->adminLink('/imports-exports/editorial/' . rawurlencode($releaseId) . '/zip') : null,
            ],
        ];
    }


    /** @param array<string,mixed> $manifest @param array<string,mixed> $report */
    private function routePath(array $manifest, array $report): ?string
    {
        $route = trim((string) ($manifest['route'] ?? ''));
        if ($route !== '') {
            return '/' . ltrim($route, '/');
        }
        $exported = $report['routes_exported'] ?? [];
        if (is_array($exported) && isset($exported[0]) && is_array($exported[0])) {
            $path = trim((string) ($exported[0]['path'] ?? ''));
            return $path !== '' ? '/' . ltrim($path, '/') : null;
        }
        return null;
    }

    /** @param array<string,mixed> $manifest @param array<string,mixed> $report */
    private function adminLink(string $path): string
    {
        $base = trim($this->adminApiBasePath);
        if ($base === '' && function_exists('admin_url_path')) {
            $base = admin_url_path('/admin/api');
        }
        if ($base === '') {
            $base = '/admin/api';
        }
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    private function status(array $manifest, array $report): string
    {
        if (($manifest['dry_run'] ?? false) === true || ($report['dry_run'] ?? false) === true) {
            return 'skipped';
        }
        $errors = $manifest['errors'] ?? $report['errors'] ?? [];
        if (($manifest['status'] ?? null) === 'partial') { return 'partial'; }
        if (is_array($errors) && count($errors) > 0) {
            return 'failed';
        }
        if ($manifest !== [] && is_file((string) (($manifest['output_path'] ?? '') . '/index.html')) || $manifest !== []) {
            return 'succeeded';
        }
        return 'pending';
    }

    private function isInsideBase(string $path): bool
    {
        $base = realpath($this->baseDirectory());
        $real = realpath(is_dir($path) ? $path : dirname($path));
        if ($base === false || $real === false) {
            return false;
        }
        $base = rtrim(str_replace('\\', '/', $base), '/');
        $real = rtrim(str_replace('\\', '/', $real), '/');
        return $real === $base || str_starts_with($real, $base . '/');
    }
}
