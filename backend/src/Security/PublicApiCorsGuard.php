<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Database;
use App\Core\ErrorCode;
use App\Core\Request;
use App\Core\Response;

final class PublicApiCorsGuard
{
    public function __construct(
        private readonly Request $request,
        private readonly Database $coreDb,
        private readonly array $config,
    ) {}

    public function enforce(): ?Response
    {
        if (!$this->isPublicApiV1Request()) {
            return null;
        }

        $settings = $this->settings();
        if (!(bool) ($settings['enabled'] ?? true)) {
            return null;
        }

        $origin = $this->origin();
        if ($origin === null) {
            return null;
        }

        if (!$this->isAllowedOrigin($origin, $settings)) {
            return Response::error(
                ErrorCode::AUTHZ_FORBIDDEN,
                'Origine CORS non autorisée pour ce site.',
                403,
                [
                    'origin' => $origin,
                    'site_id' => $this->siteId(),
                ],
                ['Cache-Control' => 'no-store', 'Vary' => 'Origin']
            );
        }

        if ($this->request->method === 'OPTIONS') {
            return Response::json([], 204, $this->corsHeaders($origin, $settings));
        }

        return null;
    }

    public function withCorsHeaders(Response $response): Response
    {
        if (!$this->isPublicApiV1Request()) {
            return $response;
        }

        $settings = $this->settings();
        if (!(bool) ($settings['enabled'] ?? true)) {
            return $response;
        }

        $origin = $this->origin();
        if ($origin === null || !$this->isAllowedOrigin($origin, $settings)) {
            return $response;
        }

        return $response->withHeaders($this->corsHeaders($origin, $settings));
    }

    private function isPublicApiV1Request(): bool
    {
        $path = (string) $this->request->path;
        return $path === '/api/v1' || str_starts_with($path, '/api/v1/');
    }

    /** @return array<string,mixed> */
    private function settings(): array
    {
        $settings = $this->config['app']['public_api_cors'] ?? [];
        return is_array($settings) ? $settings : [];
    }

    private function origin(): ?string
    {
        $origin = trim((string) ($this->request->header('Origin', '') ?? ''));
        if ($origin === '' || !preg_match('#^https?://[^/\s]+$#i', $origin)) {
            return null;
        }
        return rtrim($origin, '/');
    }

    /** @param array<string,mixed> $settings */
    private function isAllowedOrigin(string $origin, array $settings): bool
    {
        $allowed = $this->allowedOrigins($settings);
        if (in_array('*', $allowed, true)) {
            return true;
        }
        return in_array(strtolower($origin), array_map('strtolower', $allowed), true);
    }

    /** @param array<string,mixed> $settings @return list<string> */
    private function allowedOrigins(array $settings): array
    {
        $origins = [];

        foreach ($this->listSetting($settings['default_allowed_origins'] ?? []) as $origin) {
            $origins[] = $origin;
        }

        $siteId = $this->siteId();
        if ($siteId > 0) {
            foreach ($this->siteConfiguredOrigins($siteId) as $origin) {
                $origins[] = $origin;
            }
            if ((bool) ($settings['allow_current_site_origin'] ?? true)) {
                foreach ($this->siteDomainOrigins($siteId) as $origin) {
                    $origins[] = $origin;
                }
            }
        }

        return array_values(array_unique(array_filter($origins, static fn(string $origin): bool => $origin !== '')));
    }

    /** @param mixed $value @return list<string> */
    private function listSetting(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = preg_split('/[\s,]+/', $value) ?: [];
            }
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $origin) {
            if (!is_string($origin)) {
                continue;
            }
            $origin = trim($origin);
            if ($origin === '*') {
                $out[] = '*';
                continue;
            }
            if (preg_match('#^https?://[^/\s]+$#i', $origin) === 1) {
                $out[] = rtrim($origin, '/');
            }
        }
        return $out;
    }

    /** @return list<string> */
    private function siteConfiguredOrigins(int $siteId): array
    {
        try {
            $row = $this->coreDb->one(
                "SELECT value_json FROM site_settings WHERE site_id = :site_id AND namespace = 'api' AND setting_key = 'cors_allowed_origins' LIMIT 1",
                ['site_id' => $siteId]
            );
        } catch (\Throwable) {
            return [];
        }
        return $this->listSetting((string) ($row['value_json'] ?? '[]'));
    }

    /** @return list<string> */
    private function siteDomainOrigins(int $siteId): array
    {
        try {
            $rows = $this->coreDb->all(
                'SELECT scheme, host FROM site_domains WHERE site_id = :site_id AND is_active = 1 ORDER BY is_primary DESC, id ASC',
                ['site_id' => $siteId]
            );
        } catch (\Throwable) {
            return [];
        }

        $origins = [];
        foreach ($rows as $row) {
            $scheme = strtolower((string) ($row['scheme'] ?? 'https'));
            $host = strtolower(trim((string) ($row['host'] ?? '')));
            if ($host === '' || !in_array($scheme, ['http', 'https'], true)) {
                continue;
            }
            $origins[] = $scheme . '://' . $host;
        }
        return $origins;
    }

    /** @param array<string,mixed> $settings @return array<string,string> */
    private function corsHeaders(string $origin, array $settings): array
    {
        $methods = $this->headerList($settings['allowed_methods'] ?? ['GET', 'OPTIONS']);
        $headers = $this->headerList($settings['allowed_headers'] ?? ['Authorization', 'Content-Type']);
        $maxAge = max(0, (int) ($settings['max_age'] ?? 600));

        return [
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Methods' => implode(', ', $methods ?: ['GET', 'OPTIONS']),
            'Access-Control-Allow-Headers' => implode(', ', $headers ?: ['Authorization', 'Content-Type']),
            'Access-Control-Max-Age' => (string) $maxAge,
            'Vary' => 'Origin',
        ];
    }

    /** @param mixed $value @return list<string> */
    private function headerList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item !== '' && preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $item) === 1) {
                $out[] = $item;
            }
        }
        return array_values(array_unique($out));
    }

    private function siteId(): int
    {
        return (int) ($this->request->server['CMS_SITE_ID'] ?? 0);
    }
}
