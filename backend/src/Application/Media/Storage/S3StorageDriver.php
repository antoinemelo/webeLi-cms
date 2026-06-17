<?php

declare(strict_types=1);

namespace App\Application\Media\Storage;

final class S3StorageDriver implements StorageDriverInterface
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config) {}

    public function disk(): string
    {
        return 's3';
    }

    public function isConfigured(): bool
    {
        return $this->endpoint() !== ''
            && $this->bucket() !== ''
            && $this->region() !== ''
            && $this->accessKey() !== ''
            && $this->secretKey() !== '';
    }

    /** @return array<string,mixed> */
    public function preflight(): array
    {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('MEDIA_S3_CURL_EXTENSION_REQUIRED');
        }
        if (!$this->isConfigured()) {
            throw new \RuntimeException('MEDIA_S3_CONFIGURATION_INCOMPLETE');
        }
        return [
            'driver' => 's3',
            'endpoint' => $this->endpoint(),
            'bucket' => $this->bucket(),
            'region' => $this->region(),
            'public_base_url' => $this->publicBaseUrl(),
            'path_prefix' => $this->pathPrefix(),
        ];
    }

    public function putFile(string $relativePath, string $absoluteSourcePath, string $mimeType = 'application/octet-stream'): void
    {
        $this->preflight();
        if (!is_file($absoluteSourcePath)) {
            throw new \RuntimeException('MEDIA_STORAGE_SOURCE_MISSING');
        }
        $body = file_get_contents($absoluteSourcePath);
        if ($body === false) {
            throw new \RuntimeException('MEDIA_STORAGE_READ_FAILED');
        }
        $this->request('PUT', $this->objectKey($relativePath), $body, $mimeType);
    }

    public function readStream(string $relativePath)
    {
        throw new \RuntimeException('MEDIA_S3_READ_STREAM_NOT_SUPPORTED');
    }

    public function exists(string $relativePath): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }
        try {
            $this->request('HEAD', $this->objectKey($relativePath));
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    public function delete(string $relativePath): void
    {
        if ($relativePath === '' || !$this->isConfigured()) {
            return;
        }
        $this->request('DELETE', $this->objectKey($relativePath));
    }

    public function publicUrl(string $relativePath): string
    {
        $relativePath = trim($relativePath);
        if ($relativePath === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $relativePath) || str_starts_with($relativePath, '//')) {
            return $relativePath;
        }
        $key = $this->objectKey($relativePath);
        $base = $this->publicBaseUrl();
        if ($base !== '') {
            return rtrim($base, '/') . '/' . str_replace('%2F', '/', rawurlencode($key));
        }
        return rtrim($this->endpoint(), '/') . '/' . rawurlencode($this->bucket()) . '/' . str_replace('%2F', '/', rawurlencode($key));
    }

    public function objectKey(string $relativePath): string
    {
        $path = str_replace('\\', '/', trim($relativePath));
        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, '..')) {
            throw new \InvalidArgumentException('MEDIA_PATH_INVALID');
        }
        $prefix = $this->pathPrefix();
        return $prefix !== '' ? $prefix . '/' . $path : $path;
    }

    private function request(string $method, string $key, string $body = '', string $contentType = 'application/octet-stream'): void
    {
        $url = rtrim($this->endpoint(), '/') . '/' . rawurlencode($this->bucket()) . '/' . str_replace('%2F', '/', rawurlencode($key));
        $payloadHash = hash('sha256', $body);
        $now = gmdate('Ymd\THis\Z');
        $date = substr($now, 0, 8);
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        $hostHeader = (string) $host . ($port ? ':' . $port : '');
        $canonicalUri = '/' . rawurlencode($this->bucket()) . '/' . str_replace('%2F', '/', rawurlencode($key));
        $headers = [
            'host' => $hostHeader,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $now,
        ];
        if ($method === 'PUT') {
            $headers['content-type'] = $contentType !== '' ? $contentType : 'application/octet-stream';
        }
        ksort($headers);
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= strtolower($name) . ':' . trim((string) $value) . "\n";
        }
        $signedHeaders = implode(';', array_keys($headers));
        $scope = $date . '/' . $this->region() . '/s3/aws4_request';
        $canonicalRequest = implode("\n", [$method, $canonicalUri, '', $canonicalHeaders, $signedHeaders, $payloadHash]);
        $stringToSign = implode("\n", ['AWS4-HMAC-SHA256', $now, $scope, hash('sha256', $canonicalRequest)]);
        $signingKey = $this->signingKey($date);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);
        $headers['authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $this->accessKey() . '/' . $scope . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;

        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = $this->headerName($name) . ': ' . $value;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('MEDIA_S3_CURL_INIT_FAILED');
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false || $status < 200 || $status >= 300) {
            if ($method === 'DELETE' && $status === 404) {
                return;
            }
            throw new \RuntimeException('MEDIA_S3_REQUEST_FAILED:' . $method . ':' . $status . ($error !== '' ? ':' . $error : ''));
        }
    }

    private function signingKey(string $date): string
    {
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey(), true);
        $kRegion = hash_hmac('sha256', $this->region(), $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    private function headerName(string $name): string
    {
        return implode('-', array_map(static fn(string $part): string => ucfirst($part), explode('-', $name)));
    }

    private function endpoint(): string { return rtrim(trim((string) ($this->config['endpoint'] ?? '')), '/'); }
    private function bucket(): string { return trim((string) ($this->config['bucket'] ?? '')); }
    private function region(): string { return trim((string) ($this->config['region'] ?? '')); }
    private function accessKey(): string { return trim((string) ($this->config['access_key'] ?? '')); }
    private function secretKey(): string { return trim((string) ($this->config['secret_key'] ?? '')); }
    private function publicBaseUrl(): string { return rtrim(trim((string) ($this->config['public_base_url'] ?? '')), '/'); }
    private function pathPrefix(): string
    {
        $prefix = trim(str_replace('\\', '/', (string) ($this->config['path_prefix'] ?? '')), '/');
        if ($prefix === '' || str_contains($prefix, '..')) {
            return '';
        }
        return preg_replace('#/+#', '/', $prefix) ?: '';
    }
}
