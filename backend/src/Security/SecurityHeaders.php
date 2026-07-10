<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Request;
use App\Core\Response;

/**
 * Central HTTP security headers policy for the public front, public/headless API
 * and administration area. It is intentionally application-level so it also
 * works on classic Apache/PHP shared hosting without server-level access.
 */
final class SecurityHeaders
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config = []) {}

    public function secure(Response $response, Request $request): Response
    {
        if (!$this->enabled()) {
            return $response;
        }

        return $response->withHeaders($this->headersFor($request, $response));
    }

    /** @return array<string,string> */
    public function headersFor(Request $request, ?Response $response = null): array
    {
        $context = $this->context($request);
        $profile = $this->profile($context);
        $headers = [];

        $csp = trim((string) ($profile['content_security_policy'] ?? ''));
        if ($csp !== '') {
            $headers['Content-Security-Policy'] = $this->productionOnlyUpgradeInsecureRequests($csp);
        }

        $referrerPolicy = trim((string) ($profile['referrer_policy'] ?? $this->config['referrer_policy'] ?? 'strict-origin-when-cross-origin'));
        if ($referrerPolicy !== '') {
            $headers['Referrer-Policy'] = $referrerPolicy;
        }

        if ((bool) ($profile['x_content_type_options'] ?? $this->config['x_content_type_options'] ?? true)) {
            $headers['X-Content-Type-Options'] = 'nosniff';
        }

        $permissionsPolicy = trim((string) ($profile['permissions_policy'] ?? $this->config['permissions_policy'] ?? ''));
        if ($permissionsPolicy !== '') {
            $headers['Permissions-Policy'] = $permissionsPolicy;
        }

        $xFrameOptions = trim((string) ($profile['x_frame_options'] ?? ''));
        if ($xFrameOptions !== '') {
            $headers['X-Frame-Options'] = $xFrameOptions;
        }

        $coop = trim((string) ($profile['cross_origin_opener_policy'] ?? $this->config['cross_origin_opener_policy'] ?? ''));
        if ($coop !== '') {
            $headers['Cross-Origin-Opener-Policy'] = $coop;
        }

        $corp = trim((string) ($profile['cross_origin_resource_policy'] ?? ''));
        if ($corp !== '') {
            $headers['Cross-Origin-Resource-Policy'] = $corp;
        }

        if ($this->shouldSendHsts($request)) {
            $headers['Strict-Transport-Security'] = $this->hstsValue();
        }

        foreach ((array) ($profile['extra'] ?? []) as $name => $value) {
            $name = trim((string) $name);
            $value = trim((string) $value);
            if ($name !== '' && $value !== '') {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    private function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? true);
    }

    /** @return array<string,mixed> */
    private function profile(string $context): array
    {
        $profiles = (array) ($this->config['profiles'] ?? []);
        return (array) ($profiles[$context] ?? $profiles['front'] ?? []);
    }

    private function context(Request $request): string
    {
        $path = '/' . trim($request->path, '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');

        if ($path === '/admin' || str_starts_with($path, '/admin/') || (bool) preg_match('#/(admin)(?:$|/)#', $path)) {
            return 'admin';
        }
        if (str_starts_with($path, '/api/')) {
            return 'api';
        }
        if ($path === '/preview' || str_starts_with($path, '/preview/') || (bool) preg_match('#/(?:[a-z]{2}(?:-[a-z]{2})?/)?preview(?:/|$)#i', $path)) {
            return 'preview';
        }
        return 'front';
    }

    private function shouldSendHsts(Request $request): bool
    {
        if (!(bool) ($this->config['hsts']['enabled'] ?? true)) {
            return false;
        }
        $mode = strtolower((string) ($this->config['hsts']['mode'] ?? 'https_only'));
        if ($mode === 'always') {
            return true;
        }
        if ($mode === 'never') {
            return false;
        }
        return $this->isHttps($request);
    }

    private function isHttps(Request $request): bool
    {
        $https = strtolower((string) ($request->server['HTTPS'] ?? ''));
        if ($https === 'on' || $https === '1') {
            return true;
        }
        $scheme = strtolower((string) ($request->server['HTTP_X_FORWARDED_PROTO'] ?? $request->server['REQUEST_SCHEME'] ?? ''));
        return $scheme === 'https';
    }

    private function hstsValue(): string
    {
        $hsts = (array) ($this->config['hsts'] ?? []);
        $maxAge = max(0, (int) ($hsts['max_age'] ?? 15552000));
        $value = 'max-age=' . $maxAge;
        if ((bool) ($hsts['include_subdomains'] ?? true)) {
            $value .= '; includeSubDomains';
        }
        if ((bool) ($hsts['preload'] ?? false)) {
            $value .= '; preload';
        }
        return $value;
    }

    private function productionOnlyUpgradeInsecureRequests(string $csp): string
    {
        $env = strtolower((string) ($this->config['environment'] ?? 'production'));
        $isProduction = $env === 'production' || $env === 'prod';
        if ($isProduction || !(bool) ($this->config['strip_upgrade_insecure_requests_in_dev'] ?? true)) {
            return $csp;
        }
        $parts = array_filter(array_map('trim', explode(';', $csp)), static fn(string $part): bool => $part !== '' && strtolower($part) !== 'upgrade-insecure-requests');
        return implode('; ', $parts);
    }
}
