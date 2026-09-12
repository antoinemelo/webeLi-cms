<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/runtime.php';

$healthConfig = require __DIR__ . '/../config/health.php';
$databaseConfig = require __DIR__ . '/../config/databases.php';
if (\App\Health\HealthEndpoint::handle($healthConfig, $databaseConfig)) {
    exit;
}

$maintenanceFlag = dirname(__DIR__, 2) . '/storage/maintenance.flag';
if (is_file($maintenanceFlag)) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('Retry-After: 60');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('X-Content-Type-Options: nosniff');
    echo '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Maintenance en cours</title><style>body{font:16px/1.5 system-ui,sans-serif;max-width:42rem;margin:10vh auto;padding:2rem;color:#172033}.card{border:1px solid #dbe2ea;border-radius:16px;padding:2rem;box-shadow:0 12px 34px rgba(15,23,42,.08)}</style><main class="card"><h1>Maintenance en cours</h1><p>DEC CMS applique une mise à jour vérifiée. Réessayez dans quelques instants.</p></main></html>';
    exit;
}

try {
    $app = require __DIR__ . '/../bootstrap/app.php';
    $response = $app->handle();
    http_response_code($response->status());
    foreach ($response->headers() as $name => $value) {
        header($name . ': ' . $value);
    }
    echo $response->body();
} catch (Throwable $e) {
    $message = $e->getMessage();
    $isRuntimeRequirementError = str_contains($message, 'pdo_sqlite')
        || str_contains($message, 'SQLite')
        || str_contains($message, 'dossier SQLite')
        || str_contains($message, 'cache Twig')
        || str_contains($message, 'Répertoire de templates');

    http_response_code($isRuntimeRequirementError ? 503 : 500);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $fallbackPath = '/' . trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
    $fallbackPath = $fallbackPath === '/' ? '/' : rtrim($fallbackPath, '/');
    $isFallbackAdmin = $fallbackPath === '/admin' || str_starts_with($fallbackPath, '/admin/') || (bool) preg_match('#/(admin)(?:$|/)#', $fallbackPath);
    $isFallbackApi = str_starts_with($fallbackPath, '/api/');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: ' . ($isFallbackApi ? 'no-referrer' : ($isFallbackAdmin ? 'same-origin' : 'strict-origin-when-cross-origin')));
    header('X-Frame-Options: ' . ($isFallbackApi ? 'DENY' : 'SAMEORIGIN'));
    if ($isFallbackApi) {
        header("Content-Security-Policy: default-src 'none'; base-uri 'none'; object-src 'none'; frame-ancestors 'none'; form-action 'none'");
    } elseif ($isFallbackAdmin) {
        header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:");
        header('X-Robots-Tag: noindex, nofollow, noarchive');
    } else {
        header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; img-src 'self' https: data: blob:; font-src 'self' https: data:");
    }
    header('Permissions-Policy: accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()');
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    $scheme = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? $_SERVER['REQUEST_SCHEME'] ?? ''));
    if ($https === 'on' || $https === '1' || $scheme === 'https') {
        header('Strict-Transport-Security: max-age=15552000; includeSubDomains');
    }

    $debug = (bool) (($_ENV['APP_DEBUG'] ?? $_SERVER['APP_DEBUG'] ?? '0') !== '0');
    $title = $isRuntimeRequirementError ? 'Préflight serveur requis' : 'Erreur 500';
    $intro = $isRuntimeRequirementError
        ? 'Le serveur ne satisfait pas encore les prérequis du CMS ou le stockage local n’est pas prêt.'
        : 'Le runtime a rencontré une erreur interne.';

    echo '<!doctype html><html lang="fr"><meta charset="utf-8"><title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title><style>body{font:16px/1.5 system-ui,sans-serif;padding:2rem;max-width:900px;margin:auto;color:#172033}pre{white-space:pre-wrap;background:#f6f6f6;padding:1rem;border-radius:8px;overflow:auto}code{background:#f6f6f6;padding:.1rem .3rem;border-radius:4px}.card{border:1px solid #e5e7eb;border-radius:16px;padding:1rem;background:#fff;box-shadow:0 12px 34px rgba(15,23,42,.06)}</style><main class="card"><h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1><p>' . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . '</p>';

    if ($isRuntimeRequirementError) {
        echo '<ul><li>Activez l’extension PHP <code>pdo_sqlite</code> sur l’hébergement.</li><li>Vérifiez les droits d’écriture sur <code>storage/database</code>, <code>storage/cache</code>, <code>storage/uploads</code> et <code>storage/logs</code>.</li><li>Lancez <code>python3 tools/python/d1_preflight_local.py</code> avant packaging/déploiement.</li></ul>';
    }

    if ($debug || $isRuntimeRequirementError) {
        echo '<h2>Détail</h2><pre>' . htmlspecialchars($e::class . ': ' . $message . "\n\n" . $e->getTraceAsString(), ENT_QUOTES, 'UTF-8') . '</pre>';
    }
    echo '</main></html>';
}
