<?php

declare(strict_types=1);

function cms_env_file_path(): string
{
    return dirname(__DIR__, 2) . '/ops/.env';
}

/** @return array<string,string> */
function cms_load_env_file(string $envFile): array
{
    $values = [];
    if (!is_file($envFile)) {
        return $values;
    }

    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if ($key === '') {
            continue;
        }
        $existing = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($existing !== false && $existing !== null && $existing !== '') {
            continue;
        }
        $value = trim($value, " \t\n\r\0\x0B\"'");
        $values[$key] = $value;
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }

    return $values;
}

function cms_env_value(string $key, string $default = ''): string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return (string) $value;
}

function cms_path_is_absolute(string $path): bool
{
    return str_starts_with($path, DIRECTORY_SEPARATOR) || (bool) preg_match('#^[A-Z]:[\\/]#i', $path);
}

function cms_project_path(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return dirname(__DIR__, 2);
    }
    if (cms_path_is_absolute($path)) {
        return rtrim($path, DIRECTORY_SEPARATOR . '/');
    }

    $normalized = preg_replace('#^[.][\\/]#', '', $path) ?? $path;
    return rtrim(
        dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $normalized),
        DIRECTORY_SEPARATOR . '/'
    );
}

/** @return list<string> */
function cms_composer_psr4_prefixes(string $autoload): array
{
    $psr4 = dirname($autoload) . '/composer/autoload_psr4.php';
    if (!is_file($psr4)) {
        return [];
    }

    $map = require $psr4;
    if (!is_array($map)) {
        return [];
    }

    return array_values(array_filter(array_map('strval', array_keys($map))));
}

function cms_autoload_maps_prefix(string $autoload, string $prefix): bool
{
    return in_array($prefix, cms_composer_psr4_prefixes($autoload), true);
}

/**
 * Load dependencies from a portable/shared Composer vendor without allowing
 * that vendor to supply application classes from another CMS instance.
 */
function cms_require_portable_composer_autoload(string $autoload): bool
{
    if (!is_file($autoload)) {
        return false;
    }

    $mapsApplication = cms_autoload_maps_prefix($autoload, 'App\\');
    $loader = require $autoload;
    if (!$mapsApplication) {
        return true;
    }

    // Composer returns its ClassLoader here. Remove the root project's App\\
    // PSR-4 mapping so the native fallback below remains instance-local.
    if (!is_object($loader)
        || !method_exists($loader, 'setPsr4')
        || !method_exists($loader, 'getClassMap')) {
        if (is_object($loader) && method_exists($loader, 'unregister')) {
            $loader->unregister();
        }
        return false;
    }

    // An optimized vendor may contain concrete App\\ class-map entries, which
    // cannot be removed safely through Composer's public API. Reject it rather
    // than risk loading application code from the shared instance.
    foreach (array_keys($loader->getClassMap()) as $class) {
        if (str_starts_with((string) $class, 'App\\')) {
            if (method_exists($loader, 'unregister')) {
                $loader->unregister();
            }
            return false;
        }
    }

    $loader->setPsr4('App\\', []);
    return true;
}

/** Resolve the conventional cms/vendor shared from the web root. */
function cms_named_shared_vendor_root(): string
{
    $projectRoot = cms_project_path('');
    $cursor = $projectRoot;
    while ($cursor !== dirname($cursor)) {
        if (basename($cursor) === 'cms') {
            return dirname($cursor) . '/cms/vendor';
        }
        if (is_dir($cursor . '/cms')) {
            return $cursor . '/cms/vendor';
        }
        $cursor = dirname($cursor);
    }

    foreach (['DOCUMENT_ROOT', 'CONTEXT_DOCUMENT_ROOT'] as $key) {
        $documentRoot = trim((string) ($_SERVER[$key] ?? ''));
        if ($documentRoot !== '') {
            return rtrim($documentRoot, DIRECTORY_SEPARATOR . '/') . '/cms/vendor';
        }
    }

    return dirname($projectRoot) . '/cms/vendor';
}

/**
 * Composer vendor locations supported by every instance.
 *
 * They resolve to instance/backend/vendor, instance/vendor, parent/vendor,
 * the conventional web-root cms/vendor, then grandparent/vendor.
 *
 * @return list<string>
 */
function cms_vendor_roots(): array
{
    $projectRoot = cms_project_path('');
    return array_values(array_unique([
        $projectRoot . '/backend/vendor',
        $projectRoot . '/vendor',
        dirname($projectRoot) . '/vendor',
        cms_named_shared_vendor_root(),
        dirname($projectRoot, 2) . '/vendor',
    ]));
}

/** @return list<string> */
function cms_twig_vendor_roots(string $configuredPath = ''): array
{
    $roots = array_map(
        static fn(string $vendorRoot): string => $vendorRoot . '/twig',
        cms_vendor_roots(),
    );
    if (trim($configuredPath) !== '') {
        $roots[] = cms_project_path($configuredPath);
    }
    return array_values(array_unique($roots));
}

/**
 * Portable dependency loader for installations without backend/vendor.
 *
 * Shared Composer vendors are accepted after their App\\ mapping has been
 * neutralized. Twig still has a direct PSR-4 fallback for older vendor layouts.
 */
function cms_register_twig_fallback(string $configuredPath): void
{
    // Allow the canonical backend Composer loader, registered immediately
    // before this call, to resolve Twig. Using ``false`` here would make us
    // process that same loader as a portable vendor and clear its valid App\\
    // mapping, leaving the application classes unavailable.
    if (class_exists(\Twig\Environment::class)
        && class_exists(\Twig\Loader\FilesystemLoader::class)) {
        return;
    }

    // Keep every instance portable: prefer its canonical Composer vendor,
    // then its local portable vendor, then a vendor shared by the parent.
    // APP_TWIG_VENDOR_PATH remains an additional custom fallback.
    $twigRoots = cms_twig_vendor_roots($configuredPath);

    $autoloadCandidates = [];
    foreach ($twigRoots as $twigRoot) {
        $autoloadCandidates[] = $twigRoot . '/autoload.php';
        $autoloadCandidates[] = $twigRoot . '/twig/autoload.php';
        $autoloadCandidates[] = dirname($twigRoot) . '/autoload.php';
    }

    foreach ($autoloadCandidates as $autoload) {
        if (!cms_require_portable_composer_autoload($autoload)) {
            continue;
        }
        if (class_exists(\Twig\Environment::class)
            && class_exists(\Twig\Loader\FilesystemLoader::class)) {
            return;
        }
    }

    $srcCandidates = [];
    foreach ($twigRoots as $twigRoot) {
        $srcCandidates[] = $twigRoot . '/src';
        $srcCandidates[] = $twigRoot . '/twig/src';
        $srcCandidates[] = $twigRoot . '/twig/twig/src';
    }

    foreach ($srcCandidates as $src) {
        if (!is_file($src . '/Environment.php')) {
            continue;
        }
        spl_autoload_register(static function (string $class) use ($src): void {
            $prefix = 'Twig\\';
            if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
                return;
            }
            $relativeClass = substr($class, strlen($prefix));
            $file = $src . '/' . str_replace('\\', '/', $relativeClass) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
        return;
    }
}


require_once dirname(__DIR__) . '/src/Shared/Support/helpers.php';

cms_load_env_file(cms_env_file_path());

$projectAutoloadAvailable = false;
$backendComposerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($backendComposerAutoload)) {
    require_once $backendComposerAutoload;
    $projectAutoloadAvailable = cms_autoload_maps_prefix($backendComposerAutoload, 'App\\');
}

// Legacy/portable fallback: Twig can still be supplied outside backend/vendor,
// but this loader is invoked only after the canonical backend Composer loader.
$twigVendorPath = cms_env_value('APP_TWIG_VENDOR_PATH', cms_env_value('APP_TWIG_PATH', './vendor/twig/'));
cms_register_twig_fallback($twigVendorPath);

// Native App\\ fallback only when the canonical Composer installation does not
// expose the project namespace (for example in a source-only portable package).
if (!$projectAutoloadAvailable) {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'App\\';
        $baseDir = dirname(__DIR__) . '/src/';
        if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
            return;
        }
        if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false)) {
            return;
        }
        $relativeClass = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}



foreach ([
    dirname(__DIR__, 2) . '/storage/database',
    dirname(__DIR__, 2) . '/storage/cache',
    dirname(__DIR__, 2) . '/storage/cache/twig',
    dirname(__DIR__, 2) . '/storage/uploads',
    dirname(__DIR__, 2) . '/storage/logs',
] as $storageDir) {
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0775, true);
    }
}
