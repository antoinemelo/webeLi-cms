<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Database;
use App\Core\ErrorCode;
use App\Core\Request;
use App\Core\Response;

final class PublicApiRateLimitGuard
{
    public function __construct(
        private readonly Request $request,
        private readonly Database $db,
        private readonly array $config,
    ) {}

    public function enforce(): ?Response
    {
        if (!$this->isProtectedPublicApiV1Request()) {
            return null;
        }

        $settings = $this->settings();
        if (!(bool) ($settings['enabled'] ?? true)) {
            return null;
        }

        $rule = $this->ruleForPath((string) $this->request->path, $settings);
        $limit = max(1, (int) ($rule['limit'] ?? 120));
        $window = max(1, (int) ($rule['window'] ?? 60));
        $configuredGroup = (string) ($rule['group'] ?? 'route');
        $group = $configuredGroup === 'route' ? $this->routeGroup((string) $this->request->path) : $this->routeGroup($configuredGroup);
        $cleanupProbability = max(0, min(100, (int) ($settings['cleanup_probability'] ?? 5)));

        $limiter = new RateLimiter($this->db);
        $checks = [
            'ip' => $this->clientIp($settings),
        ];

        $bearerToken = $this->bearerToken();
        if ($bearerToken !== null) {
            $checks['token'] = hash('sha256', $bearerToken);
        }

        foreach ($checks as $scope => $subject) {
            $result = $limiter->attempt('public_api_v1|' . $scope . '|' . $subject . '|' . $group, $limit, $window, $cleanupProbability);
            if (!$result->allowed) {
                return $this->tooManyRequests($result, $scope, $group);
            }
        }

        return null;
    }

    private function isProtectedPublicApiV1Request(): bool
    {
        return str_starts_with((string) $this->request->path, '/api/v1/');
    }

    /** @return array<string,mixed> */
    private function settings(): array
    {
        $settings = $this->config['app']['public_api_rate_limit'] ?? [];
        return is_array($settings) ? $settings : [];
    }

    /** @param array<string,mixed> $settings */
    private function ruleForPath(string $path, array $settings): array
    {
        $default = is_array($settings['default'] ?? null) ? $settings['default'] : [];
        $endpoints = is_array($settings['endpoints'] ?? null) ? $settings['endpoints'] : [];

        foreach ($endpoints as $pattern => $rule) {
            if (!is_string($pattern) || !is_array($rule)) {
                continue;
            }
            if (@preg_match($pattern, '') === false) {
                continue;
            }
            if (preg_match($pattern, $path) === 1) {
                return $rule + $default;
            }
        }

        return $default;
    }

    private function routeGroup(string $value): string
    {
        $value = trim($value) !== '' ? trim($value) : (string) $this->request->path;
        if (str_starts_with($value, '/api/v1/media/')) {
            return '/api/v1/media/{id}';
        }
        if (str_starts_with($value, '/api/v1/menus/')) {
            return '/api/v1/menus/{key}';
        }
        if (str_starts_with($value, '/api/v1/taxonomies/')) {
            return '/api/v1/taxonomies/{taxonomy}';
        }
        if (preg_match('#^/api/v1/content/[^/]+/[^/]+$#', $value) === 1) {
            return '/api/v1/content/{type}/{slug}';
        }
        if (preg_match('#^/api/v1/content/[^/]+$#', $value) === 1) {
            return '/api/v1/content/{type}';
        }
        return rtrim($value, '/') ?: '/api/v1';
    }

    /** @param array<string,mixed> $settings */
    private function clientIp(array $settings): string
    {
        $candidates = [(string) ($this->request->server['REMOTE_ADDR'] ?? '')];

        if ((bool) ($settings['trust_proxy_headers'] ?? false)) {
            array_unshift(
                $candidates,
                (string) ($this->request->server['HTTP_CF_CONNECTING_IP'] ?? ''),
                (string) ($this->request->server['HTTP_X_REAL_IP'] ?? ''),
                $this->firstForwardedForIp((string) ($this->request->server['HTTP_X_FORWARDED_FOR'] ?? ''))
            );
        }

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        return 'unknown';
    }

    private function firstForwardedForIp(string $header): string
    {
        if ($header === '') {
            return '';
        }
        $parts = array_map('trim', explode(',', $header));
        return (string) ($parts[0] ?? '');
    }

    private function bearerToken(): ?string
    {
        $authorization = (string) ($this->request->header('Authorization', '') ?? '');
        if (preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $matches) !== 1) {
            return null;
        }
        $token = trim((string) $matches[1]);
        return $token !== '' ? $token : null;
    }

    private function tooManyRequests(RateLimitResult $result, string $scope, string $group): Response
    {
        return Response::error(
            ErrorCode::RATE_LIMIT_EXCEEDED,
            'Trop de requêtes sur cette API publique. Réessayez plus tard.',
            429,
            [
                'scope' => $scope,
                'group' => $group,
                'limit' => $result->limit,
                'window_seconds' => $result->windowSeconds,
                'retry_after_seconds' => $result->retryAfterSeconds,
            ],
            [
                'Retry-After' => (string) $result->retryAfterSeconds,
                'X-RateLimit-Limit' => (string) $result->limit,
                'X-RateLimit-Remaining' => '0',
                'X-RateLimit-Reset' => (string) $result->resetAt,
            ]
        );
    }
}
