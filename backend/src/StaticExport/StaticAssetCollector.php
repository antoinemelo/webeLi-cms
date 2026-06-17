<?php

declare(strict_types=1);

namespace App\StaticExport;

final class StaticAssetCollector
{
    private const DENY = '#(^|/)(\.env|node_modules|tools|database|ops|storage/database|storage/logs|storage/cache|backups?|\.git)(/|$)|\.(sqlite|sqlite-wal|sqlite-shm|sqlite-journal|log|bak|zip|tmp)$#i';

    /** @var list<string> */
    private array $warnings = [];

    /** @return array{assets:list<string>,media:list<string>,warnings:list<string>} */
    public function copyForHtml(string $html, string $publicDir, array $publicMediaRows = []): array
    {
        $this->warnings = [];
        $assets = [];
        $media = [];
        foreach ($this->extractPublicPaths($html) as $path) {
            $relative = $this->normalizePublicPath($path);
            if (str_starts_with($relative, 'frontend/')) {
                foreach ($this->copyAssetWithDependencies($relative, $publicDir) as $copied) {
                    $assets[] = $copied;
                }
            }
            if (str_starts_with($relative, 'storage/media/')) {
                if ($this->copyPublicPath($relative, $publicDir)) {
                    $media[] = $relative;
                }
            }
        }
        foreach ($publicMediaRows as $row) {
            $publicPath = trim((string) ($row['public_path'] ?? ''));
            $path = $publicPath !== '' ? 'storage/media/' . ltrim($publicPath, '/') : 'storage/media/' . ltrim((string) ($row['path'] ?? ''), '/');
            $path = $this->normalizePublicPath($path);
            if ($path !== 'storage/media/' && $this->copyPublicPath($path, $publicDir)) {
                $media[] = $path;
            }
        }
        return [
            'assets' => array_values(array_unique($assets)),
            'media' => array_values(array_unique($media)),
            'warnings' => $this->warnings,
        ];
    }

    /** @return list<string> */
    private function extractPublicPaths(string $html): array
    {
        $paths = [];
        if (preg_match_all('/(?:src|href|poster)=(["\'])([^"\']+)\1/i', $html, $m)) {
            foreach ($m[2] as $value) {
                $path = trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($path === '' || str_starts_with($path, '#') || preg_match('#^(https?:)?//#i', $path) || str_starts_with($path, 'data:') || str_starts_with($path, 'mailto:') || str_starts_with($path, 'tel:')) {
                    continue;
                }
                $path = parse_url($path, PHP_URL_PATH) ?: '';
                if ($path !== '') {
                    $paths[] = $path;
                }
            }
        }
        return array_values(array_unique($paths));
    }

    /** @return list<string> */
    private function copyAssetWithDependencies(string $relative, string $publicDir, array $seen = []): array
    {
        $relative = $this->normalizePublicPath($relative);
        if (isset($seen[$relative])) {
            return [];
        }
        $seen[$relative] = true;
        if (!$this->copyPublicPath($relative, $publicDir)) {
            return [];
        }
        $copied = [$relative];
        if (!str_ends_with(strtolower($relative), '.css')) {
            return $copied;
        }
        $source = base_path($relative);
        $css = is_file($source) ? (string) file_get_contents($source) : '';
        foreach ($this->extractCssDependencies($css) as $dependency) {
            $dep = $this->resolveCssDependency($relative, $dependency);
            if ($dep === null) {
                continue;
            }
            foreach ($this->copyAssetWithDependencies($dep, $publicDir, $seen) as $nested) {
                $copied[] = $nested;
            }
        }
        return array_values(array_unique($copied));
    }

    /** @return list<string> */
    private function extractCssDependencies(string $css): array
    {
        $deps = [];
        if (preg_match_all('/@import\s+(?:url\()?\s*["\']?([^"\')\s]+)["\']?\s*\)?/i', $css, $m)) {
            foreach ($m[1] as $value) {
                $deps[] = (string) $value;
            }
        }
        if (preg_match_all('/url\(\s*["\']?([^"\')]+)["\']?\s*\)/i', $css, $m)) {
            foreach ($m[1] as $value) {
                $deps[] = (string) $value;
            }
        }
        return array_values(array_unique($deps));
    }

    private function resolveCssDependency(string $fromRelativeCss, string $dependency): ?string
    {
        $dependency = trim(html_entity_decode($dependency, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($dependency === '' || str_starts_with($dependency, '#') || preg_match('#^(https?:)?//#i', $dependency) || str_starts_with($dependency, 'data:')) {
            return null;
        }
        $path = parse_url($dependency, PHP_URL_PATH) ?: '';
        if ($path === '') {
            return null;
        }
        if (str_starts_with($path, '/')) {
            return $this->normalizePublicPath($path);
        }
        $dir = trim(str_replace('\\', '/', dirname($fromRelativeCss)), '.');
        $candidate = ($dir !== '' ? $dir . '/' : '') . $path;
        return $this->collapseRelativePath($candidate);
    }

    private function copyPublicPath(string $publicPath, string $publicDir): bool
    {
        $relative = $this->normalizePublicPath($publicPath);
        if ($relative === '' || preg_match(self::DENY, $relative)) {
            $this->warnings[] = 'Chemin ignoré pour raison de sécurité: ' . $publicPath;
            return false;
        }
        $source = base_path($relative);
        if (!is_file($source)) {
            $this->warnings[] = 'Fichier public introuvable: ' . $publicPath;
            return false;
        }
        $target = rtrim($publicDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->warnings[] = 'Impossible de créer le dossier: ' . $dir;
            return false;
        }
        if (!copy($source, $target)) {
            $this->warnings[] = 'Copie impossible: ' . $publicPath;
            return false;
        }
        if (str_ends_with(strtolower($relative), '.css')) {
            $this->rewriteCopiedCssReferences($relative, $target);
        }
        return true;
    }


    private function rewriteCopiedCssReferences(string $cssRelativePath, string $targetPath): void
    {
        $css = is_file($targetPath) ? (string) file_get_contents($targetPath) : '';
        if ($css === '') {
            return;
        }
        $self = $this;
        $rewrite = static function (string $value) use ($self, $cssRelativePath): string {
            $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($value === '' || str_starts_with($value, '#') || preg_match('#^(https?:)?//#i', $value) || str_starts_with($value, 'data:')) {
                return $value;
            }
            $path = parse_url($value, PHP_URL_PATH) ?: '';
            if ($path === '') {
                return $value;
            }
            $dep = $self->resolveCssDependency($cssRelativePath, $value);
            if ($dep === null) {
                return $value;
            }
            $relative = $self->relativePathFrom(dirname($cssRelativePath), $dep);
            $parts = parse_url($value);
            if (isset($parts['query']) && $parts['query'] !== '') {
                $relative .= '?' . $parts['query'];
            }
            if (isset($parts['fragment']) && $parts['fragment'] !== '') {
                $relative .= '#' . $parts['fragment'];
            }
            return $relative;
        };
        $css = preg_replace_callback('/@import\s+(url\()?\s*(["\']?)([^"\')\s]+)(["\']?)\s*\)?/i', static function (array $m) use ($rewrite): string {
            $value = $rewrite((string) $m[3]);
            return '@import url("' . addcslashes($value, '"') . '")';
        }, $css) ?? $css;
        $css = preg_replace_callback('/url\(\s*(["\']?)([^"\')]+)(["\']?)\s*\)/i', static function (array $m) use ($rewrite): string {
            $value = $rewrite((string) $m[2]);
            return 'url("' . addcslashes($value, '"') . '")';
        }, $css) ?? $css;
        file_put_contents($targetPath, $css);
    }

    private function relativePathFrom(string $fromDir, string $toPath): string
    {
        $from = array_values(array_filter(explode('/', trim(str_replace('\\', '/', $fromDir), '/')), static fn(string $part): bool => $part !== '' && $part !== '.'));
        $to = array_values(array_filter(explode('/', trim(str_replace('\\', '/', $toPath), '/')), static fn(string $part): bool => $part !== '' && $part !== '.'));
        while ($from !== [] && $to !== [] && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }
        $relative = array_merge(array_fill(0, count($from), '..'), $to);
        return $relative === [] ? './' : implode('/', $relative);
    }

    private function normalizePublicPath(string $path): string
    {
        $path = parse_url(trim($path), PHP_URL_PATH) ?: trim($path);
        $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
        $basePath = function_exists('app_base_path') ? (string) app_base_path() : '';
        if ($basePath !== '' && str_starts_with($path, rtrim($basePath, '/') . '/')) {
            $path = substr($path, strlen(rtrim($basePath, '/')));
        }
        $relative = ltrim($path, '/');
        if (!is_file(base_path($relative))) {
            $parts = explode('/', $relative, 2);
            if (count($parts) === 2 && in_array($parts[1] === '' ? '' : explode('/', $parts[1], 2)[0], ['frontend', 'storage'], true)) {
                $stripped = $parts[1];
                if (is_file(base_path($stripped)) || str_starts_with($stripped, 'storage/media/')) {
                    $relative = $stripped;
                }
            }
        }
        return $this->collapseRelativePath($relative);
    }

    private function collapseRelativePath(string $path): string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        return implode('/', $parts);
    }
}
