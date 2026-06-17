<?php

declare(strict_types=1);

namespace App\Application\PublicApi;

final class PublicApiUrlResolver
{
    public const PREFIX = '/api/v1';

    /** @param array<string,mixed> $site */
    public function endpoint(array $site, string $path): string
    {
        $base = rtrim((string) ($site['base_url'] ?? $site['current_base_url'] ?? ''), '/');
        if ($base === '') {
            $base = function_exists('app_base_path') ? rtrim((string) app_base_path(), '/') : '';
        }
        return $base . self::PREFIX . '/' . ltrim($path, '/');
    }
}
