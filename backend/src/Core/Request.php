<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    private ?array $jsonPayload = null;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $server,
        public readonly array $files,
        public readonly array $cookies,
    ) {}

    public static function capture(array $config): self
    {
        $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $configuredBasePath = (string) ($config['app']['base_path'] ?? '');
        $basePath = rtrim($configuredBasePath !== '' ? $configuredBasePath : app_base_path(), '/');
        if ($basePath && $basePath !== '/' && str_starts_with($requestUri, $basePath)) {
            $requestUri = substr($requestUri, strlen($basePath)) ?: '/';
        }

        $query = $_GET;

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $requestUri,
            $query,
            $_POST,
            $_SERVER,
            $_FILES,
            $_COOKIE,
        );
    }

    public function withPathQueryServer(string $path, array $query, array $server): self
    {
        return new self(
            $this->method,
            $path,
            $query,
            $this->post,
            $server,
            $this->files,
            $this->cookies,
        );
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $json = $this->json();
        return $this->post[$key] ?? $json[$key] ?? $this->query[$key] ?? $default;
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        if ($this->jsonPayload !== null) {
            return $this->jsonPayload;
        }

        $contentType = (string) ($this->server['CONTENT_TYPE'] ?? $this->server['HTTP_CONTENT_TYPE'] ?? '');
        if (!str_contains(strtolower($contentType), 'application/json')) {
            return $this->jsonPayload = [];
        }

        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return $this->jsonPayload = [];
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new ApiException(ErrorCode::INVALID_JSON, ErrorCode::message(ErrorCode::INVALID_JSON), 422, [
                'json_error' => json_last_error_msg(),
            ]);
        }
        return $this->jsonPayload = $decoded;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $normalized = strtoupper(str_replace('-', '_', $name));
        $key = 'HTTP_' . $normalized;
        if (isset($this->server[$key])) {
            return (string) $this->server[$key];
        }
        if ($normalized === 'AUTHORIZATION') {
            foreach (['REDIRECT_HTTP_AUTHORIZATION', 'AUTHORIZATION'] as $fallbackKey) {
                if (isset($this->server[$fallbackKey]) && (string) $this->server[$fallbackKey] !== '') {
                    return (string) $this->server[$fallbackKey];
                }
            }
            $headers = [];
            if (function_exists('getallheaders')) {
                $headers = getallheaders() ?: [];
            } elseif (function_exists('apache_request_headers')) {
                $headers = apache_request_headers() ?: [];
            }
            foreach ($headers as $headerName => $value) {
                if (strcasecmp((string) $headerName, 'Authorization') === 0) {
                    return (string) $value;
                }
            }
        }
        if ($normalized === 'CONTENT_TYPE' && isset($this->server['CONTENT_TYPE'])) {
            return (string) $this->server['CONTENT_TYPE'];
        }
        return $default;
    }

    public function contentType(): string
    {
        return strtolower(trim((string) ($this->server['CONTENT_TYPE'] ?? $this->server['HTTP_CONTENT_TYPE'] ?? '')));
    }
}
