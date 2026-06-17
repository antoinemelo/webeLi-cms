<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Database;
use App\Core\ErrorCode;
use App\Core\Request;
use App\Core\Response;

final class PublicApiTokenGuard
{
    /** @var array<string,mixed>|null */
    private ?array $authenticatedToken = null;

    public function __construct(
        private readonly Request $request,
        private readonly Database $iamDb,
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

        $token = $this->bearerToken();
        if ($token === null) {
            return $this->unauthorized('Bearer token manquant.');
        }

        $tokenHash = hash('sha256', $token);
        $row = $this->iamDb->one(
            'SELECT * FROM api_tokens WHERE token_hash = :token_hash LIMIT 1',
            ['token_hash' => $tokenHash]
        );

        if (!$row || (int) ($row['is_active'] ?? 0) !== 1) {
            return $this->unauthorized('Bearer token invalide ou inactif.');
        }

        $expiresAt = trim((string) ($row['expires_at'] ?? ''));
        if ($expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) <= time()) {
            return $this->unauthorized('Bearer token expiré.');
        }

        $requiredScope = $this->requiredScopeForPath((string) $this->request->path, $settings);
        if ($requiredScope !== '' && !$this->hasScope((string) ($row['scopes'] ?? ''), $requiredScope)) {
            return $this->forbidden($requiredScope);
        }

        $tokenSiteId = isset($row['site_id']) ? (int) $row['site_id'] : 0;
        $requestSiteId = (int) ($this->request->server['CMS_SITE_ID'] ?? 0);
        if ($tokenSiteId > 0 && $requestSiteId > 0 && $tokenSiteId !== $requestSiteId) {
            return $this->forbidden('site:' . $requestSiteId);
        }

        $this->authenticatedToken = $row;
        $this->touchToken((int) $row['id']);
        return null;
    }

    /** @return array<string,mixed>|null */
    public function authenticatedToken(): ?array
    {
        return $this->authenticatedToken;
    }

    private function isProtectedPublicApiV1Request(): bool
    {
        $path = (string) $this->request->path;
        if (!str_starts_with($path, '/api/v1/')) {
            return false;
        }

        $settings = $this->settings();
        foreach ($this->patterns($settings['public_paths'] ?? []) as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return false;
            }
        }

        foreach ($this->patterns($settings['protected_paths'] ?? []) as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return (bool) ($settings['protect_all_v1_by_default'] ?? true);
    }

    /** @return array<string,mixed> */
    private function settings(): array
    {
        $settings = $this->config['app']['public_api_auth'] ?? [];
        return is_array($settings) ? $settings : [];
    }

    /** @param mixed $patterns @return list<string> */
    private function patterns(mixed $patterns): array
    {
        if (!is_array($patterns)) {
            return [];
        }
        $out = [];
        foreach ($patterns as $pattern) {
            if (!is_string($pattern) || $pattern === '') {
                continue;
            }
            if (@preg_match($pattern, '') === false) {
                continue;
            }
            $out[] = $pattern;
        }
        return $out;
    }

    /** @param array<string,mixed> $settings */
    private function requiredScopeForPath(string $path, array $settings): string
    {
        $default = (string) ($settings['default_scope'] ?? 'headless:read');
        $scopes = is_array($settings['endpoint_scopes'] ?? null) ? $settings['endpoint_scopes'] : [];
        foreach ($scopes as $pattern => $scope) {
            if (!is_string($pattern) || !is_string($scope) || @preg_match($pattern, '') === false) {
                continue;
            }
            if (preg_match($pattern, $path) === 1) {
                return trim($scope);
            }
        }
        return trim($default);
    }

    private function hasScope(string $storedScopes, string $requiredScope): bool
    {
        $scopes = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $storedScopes) ?: [])));
        if (in_array('*', $scopes, true) || in_array($requiredScope, $scopes, true)) {
            return true;
        }

        $aliases = $this->settings()['scope_aliases'] ?? [];
        if (!is_array($aliases)) {
            return false;
        }

        foreach ($scopes as $scope) {
            $granted = $aliases[$scope] ?? [];
            if (is_string($granted)) {
                $granted = preg_split('/[\s,]+/', $granted) ?: [];
            }
            if (is_array($granted) && in_array($requiredScope, array_map('trim', $granted), true)) {
                return true;
            }
        }

        return false;
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

    private function touchToken(int $id): void
    {
        try {
            $this->iamDb->run('UPDATE api_tokens SET last_used_at = CURRENT_TIMESTAMP WHERE id = :id', ['id' => $id]);
        } catch (\Throwable) {
            // L'authentification ne doit pas échouer pour une statistique d'usage.
        }
    }

    private function unauthorized(string $message): Response
    {
        return Response::error(
            ErrorCode::AUTH_REQUIRED,
            $message,
            401,
            ['auth_scheme' => 'Bearer'],
            ['WWW-Authenticate' => 'Bearer realm="DEC CMS headless API"', 'Cache-Control' => 'no-store']
        );
    }

    private function forbidden(string $required): Response
    {
        return Response::error(
            ErrorCode::AUTHZ_FORBIDDEN,
            'Bearer token insuffisant pour cet endpoint.',
            403,
            ['required' => $required],
            ['Cache-Control' => 'no-store']
        );
    }
}
