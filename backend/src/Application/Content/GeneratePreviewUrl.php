<?php

declare(strict_types=1);

namespace App\Application\Content;

use App\Security\PreviewSigner;

final class GeneratePreviewUrl
{
    public function __construct(private readonly array $config) {}

    public function execute(int $entryId, int $revisionId = 0, ?string $languageCode = null, int $siteId = 0, ?string $siteBasePath = null): string
    {
        $languageCode = $languageCode ?: (string) ($this->config['app']['default_locale'] ?? 'fr');

        $signer = new PreviewSigner(
            (string) $this->config['app']['preview_signing_key'],
            (int) ($this->config['cms']['preview_ttl_minutes'] ?? 60)
        );

        $query = [
            'rev' => max(0, $revisionId),
            'lang' => $languageCode,
            'token' => $signer->create($entryId, $revisionId, $siteId, $languageCode),
        ];

        $previewPath = '/preview/' . $entryId . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $siteBasePath = $this->normalizeSiteBasePath($siteBasePath);

        if ($siteBasePath !== null) {
            return url_path($siteBasePath . localized_site_path($previewPath, $languageCode));
        }

        $path = localized_path($previewPath, $languageCode);
        $path = '/' . ltrim($path, '/');

        $publicBaseUrl = rtrim((string) ($this->config['app']['public_base_url'] ?? ''), '/');
        if ($publicBaseUrl === '') {
            return $path;
        }

        return $publicBaseUrl . $path;
    }

    private function normalizeSiteBasePath(?string $siteBasePath): ?string
    {
        if ($siteBasePath === null) {
            return null;
        }
        $siteBasePath = trim($siteBasePath);
        if ($siteBasePath === '' || $siteBasePath === '/') {
            return '';
        }
        if (preg_match('#^https?://#i', $siteBasePath)) {
            $parsed = parse_url($siteBasePath);
            $siteBasePath = is_array($parsed) ? (string) ($parsed['path'] ?? '') : '';
            $appBasePath = function_exists('app_base_path') ? app_base_path() : '';
            if ($appBasePath !== '' && ($siteBasePath === $appBasePath || str_starts_with($siteBasePath, $appBasePath . '/'))) {
                $siteBasePath = substr($siteBasePath, strlen($appBasePath)) ?: '';
            }
        }
        return '/' . trim($siteBasePath, '/');
    }
}
