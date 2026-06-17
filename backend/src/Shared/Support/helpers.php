<?php

declare(strict_types=1);

function base_path(string $path = ''): string
{
    $root = dirname(__DIR__, 4);
    return $path ? $root . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : $root;
}

function env(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return ($value === false || $value === null || $value === '') ? $default : $value;
}

function app_base_path(): string
{
    $configured = env('APP_BASE_PATH', '');
    if (is_string($configured) && $configured !== '') {
        $configured = '/' . trim($configured, '/');
        return $configured === '/' ? '' : $configured;
    }
    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $dir = str_replace('\\', '/', dirname($scriptName));
    if ($dir === '/' || $dir === '.' || $dir === '\\') {
        return '';
    }
    if (str_ends_with($dir, '/backend/public')) {
        $dir = substr($dir, 0, -15);
    }
    return rtrim($dir, '/');
}

function url_path(string $path = ''): string
{
    $base = app_base_path();
    $path = '/' . ltrim($path, '/');
    if ($path === '/') {
        return $base !== '' ? $base . '/' : '/';
    }
    return ($base !== '' ? $base : '') . $path;
}

function asset_path(string $path): string
{
    return url_path($path);
}

function current_site_base_path(): string
{
    $basePath = (string) ($_SERVER['CMS_SITE_BASE_PATH'] ?? '');
    $basePath = trim($basePath);
    if ($basePath === '' || $basePath === '/') {
        return '';
    }
    return '/' . trim($basePath, '/');
}

function admin_url_path(string $path = '/admin/app'): string
{
    return admin_url_path_for_site(current_site_base_path(), $path);
}

function admin_url_path_for_site(string $siteBasePath, string $path = '/admin/app'): string
{
    $siteBasePath = trim($siteBasePath);
    if ($siteBasePath !== '' && $siteBasePath !== '/') {
        $siteBasePath = '/' . trim($siteBasePath, '/');
    } else {
        $siteBasePath = '';
    }
    $path = '/' . ltrim($path, '/');
    return url_path($siteBasePath . $path);
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function redirect(string $location, int $status = 302): App\Core\Response
{
    if (preg_match('#^https?://#i', $location)) {
        return new App\Core\Response($status, '', ['Location' => $location]);
    }

    $base = app_base_path();
    if ($base !== '' && ($location === $base || str_starts_with($location, $base . '/'))) {
        return new App\Core\Response($status, '', ['Location' => $location]);
    }

    return new App\Core\Response($status, '', ['Location' => url_path($location)]);
}

function app_url(string $path = '/'): string
{
    return url_path($path);
}



/**
 * Register the active language URL prefixes for the currently resolved site.
 *
 * The mapping is intentionally runtime-scoped: URL generation must follow
 * site_languages.url_prefix, not a generic /{languageCode} convention.
 *
 * @param list<array<string,mixed>> $languages
 */
function register_localized_path_prefixes(array $languages): void
{
    $prefixes = [];
    foreach ($languages as $language) {
        $code = strtolower(trim((string) ($language['language_code'] ?? $language['code'] ?? '')));
        if ($code === '') {
            continue;
        }
        $prefix = trim((string) ($language['url_prefix'] ?? ''));
        if ($prefix !== '') {
            $prefix = '/' . trim($prefix, '/');
        }
        $prefixes[$code] = $prefix;
    }

    $GLOBALS['CMS_LOCALIZED_PATH_PREFIXES'] = $prefixes;
}

/** @return array<string,string> */
function localized_path_prefixes(): array
{
    $prefixes = $GLOBALS['CMS_LOCALIZED_PATH_PREFIXES'] ?? [];
    return is_array($prefixes) ? $prefixes : [];
}

function localized_path_prefix(?string $languageCode): ?string
{
    $languageCode = strtolower(trim((string) ($languageCode ?? '')));
    if ($languageCode === '') {
        return '';
    }

    $prefixes = localized_path_prefixes();
    if ($prefixes === []) {
        return '';
    }

    return array_key_exists($languageCode, $prefixes) ? $prefixes[$languageCode] : null;
}

function strip_localized_path_prefix(string $path): string
{
    $path = '/' . ltrim($path, '/');
    $prefixes = array_values(array_filter(localized_path_prefixes(), fn(string $prefix): bool => $prefix !== ''));
    usort($prefixes, fn(string $a, string $b): int => strlen($b) <=> strlen($a));

    foreach ($prefixes as $prefix) {
        if ($path === $prefix) {
            return '/';
        }
        if (str_starts_with($path, $prefix . '/')) {
            return substr($path, strlen($prefix)) ?: '/';
        }
    }

    return $path;
}

function localized_site_path(string $path = '/', ?string $languageCode = null): string
{
    if (preg_match('#^https?://#i', $path) || str_starts_with($path, '#')) {
        return $path;
    }

    $parts = explode('?', $path, 2);
    $cleanPath = '/' . ltrim($parts[0] ?? '/', '/');
    $query = isset($parts[1]) && $parts[1] !== '' ? '?' . $parts[1] : '';

    $prefix = localized_path_prefix($languageCode);
    $neutralPath = strip_localized_path_prefix($cleanPath);

    // Unknown or unconfigured language: do not invent /{lang}. The canonical
    // source of truth is site_languages.url_prefix.
    if ($prefix === null || $prefix === '') {
        return $neutralPath . $query;
    }

    return $prefix . ($neutralPath === '/' ? '' : $neutralPath) . $query;
}

function localized_path(string $path = '/', ?string $languageCode = null): string
{
    if (preg_match('#^https?://#i', $path) || str_starts_with($path, '#')) {
        return $path;
    }

    $parts = explode('?', $path, 2);
    $cleanPath = '/' . ltrim($parts[0] ?? '/', '/');
    $query = isset($parts[1]) && $parts[1] !== '' ? '?' . $parts[1] : '';

    if (preg_match('#^/(admin/api|frontend|storage|assets)(/|$)#', $cleanPath)) {
        return url_path($cleanPath . $query);
    }

    $siteBasePath = current_site_base_path();

    return url_path($siteBasePath . localized_site_path($cleanPath . $query, $languageCode));
}

function localized_absolute_url(string $path = '/', ?string $languageCode = null, ?string $siteBaseUrl = null): string
{
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    $base = $siteBaseUrl;
    if ($base) {
        // siteBaseUrl already contains the canonical domain base path from
        // site_domains.base_path. Do not prepend APP_BASE_PATH again, otherwise
        // installations under /mod generate /mod/mod URLs in SEO outputs.
        return rtrim($base, '/') . localized_site_path($path, $languageCode);
    }

    $relative = localized_path($path, $languageCode);
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : ((string) ($_SERVER['REQUEST_SCHEME'] ?? 'http'));
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return rtrim($scheme . '://' . $host, '/') . $relative;
}

function absolute_url(string $path = '/', ?string $siteBaseUrl = null): string
{
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    $path = '/' . ltrim($path, '/');
    $base = $siteBaseUrl;
    if ($base) {
        // siteBaseUrl may already include the public deployment base path
        // (for example https://webe.li/mod). Do not call url_path() here:
        // url_path() would prepend APP_BASE_PATH again and create /mod/mod
        // in canonical SEO assets such as og:image and twitter:image.
        $basePath = (string) (parse_url($base, PHP_URL_PATH) ?: '');
        $basePath = $basePath !== '' && $basePath !== '/' ? '/' . trim($basePath, '/') : '';
        if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
            $path = substr($path, strlen($basePath)) ?: '/';
        }
        return rtrim($base, '/') . ($path === '/' ? '/' : $path);
    }

    $relative = url_path($path);
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : ((string) ($_SERVER['REQUEST_SCHEME'] ?? 'http'));
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return rtrim($scheme . '://' . $host, '/') . $relative;
}
