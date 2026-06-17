<?php

declare(strict_types=1);

namespace App\Health;

final class HealthEndpoint
{
    /**
     * Handles native health endpoints before session, database and CMS boot.
     * Returns true only when the current request has been answered.
     */
    public static function handle(array $healthConfig, array $databaseConfig): bool
    {
        if (!(bool) ($healthConfig['enabled'] ?? true)) {
            return false;
        }

        $path = self::requestPath();
        $livePath = (string) ($healthConfig['live_path'] ?? '/health/live');
        $readyPath = (string) ($healthConfig['ready_path'] ?? '/health/ready');
        if ($path !== $livePath && $path !== $readyPath) {
            return false;
        }

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            self::respond(405, ['status' => 'method_not_allowed'], ['Allow' => 'GET']);
            return true;
        }

        if ($path === $livePath) {
            self::respond(200, ['status' => 'live']);
            return true;
        }

        $result = (new HealthCheckService($healthConfig, $databaseConfig))->readiness();
        self::respond($result['status'] === 'ready' ? 200 : 503, $result);
        return true;
    }

    private static function requestPath(): string
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $basePath = rtrim((string) cms_env_value('APP_BASE_PATH', ''), '/');
        if ($basePath !== '' && $basePath !== '/' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
            $path = substr($path, strlen($basePath)) ?: '/';
        }
        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /** @param array<string,mixed> $payload @param array<string,string> $extraHeaders */
    private static function respond(int $status, array $payload, array $extraHeaders = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        foreach ($extraHeaders as $name => $value) {
            header($name . ': ' . $value);
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
