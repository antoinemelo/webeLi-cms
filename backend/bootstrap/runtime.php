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
 * Fallback Twig loader for installations without backend/vendor.
 *
 * A Composer autoloader mapping App\\ must never be loaded here: doing so before
 * backend/vendor/autoload.php registers two project autoloaders and can re-enter
 * a provider file while PHP is still declaring it.
 */
function cms_register_twig_fallback(string $configuredPath): void
{
    if (class_exists(\Twig\Environment::class, false)
        && class_exists(\Twig\Loader\FilesystemLoader::class, false)) {
        return;
    }

    $twigRoot = cms_project_path($configuredPath ?: './vendor/twig/');
    $autoloadCandidates = [
        $twigRoot . '/autoload.php',
        $twigRoot . '/twig/autoload.php',
        dirname($twigRoot) . '/autoload.php',
    ];

    foreach ($autoloadCandidates as $autoload) {
        if (!is_file($autoload) || cms_autoload_maps_prefix($autoload, 'App\\')) {
            continue;
        }
        require_once $autoload;
        if (class_exists(\Twig\Environment::class)
            && class_exists(\Twig\Loader\FilesystemLoader::class)) {
            return;
        }
    }

    $srcCandidates = [
        $twigRoot . '/src',
        $twigRoot . '/twig/src',
        $twigRoot . '/twig/twig/src',
    ];

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
