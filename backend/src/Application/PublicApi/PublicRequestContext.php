<?php

declare(strict_types=1);

namespace App\Application\PublicApi;

use App\Core\Request;
use App\Repository\SiteRepository;

final class PublicRequestContext
{
    public function __construct(private readonly Request $request, private readonly SiteRepository $sites) {}

    /** @return array{site:array<string,mixed>,site_id:int,language_code:string} */
    public function resolve(): array
    {
        $site = $this->sites->resolveCurrentSite((string) ($this->request->server['HTTP_HOST'] ?? ''));
        $language = strtolower(trim((string) ($this->request->query['lang'] ?? $this->request->query['language_code'] ?? $site['default_language_code'] ?? 'fr')));
        return ['site' => $site, 'site_id' => (int) ($site['id'] ?? 0), 'language_code' => $language !== '' ? $language : 'fr'];
    }

    public function normalizePath(string $path): string
    {
        $path = '/' . ltrim(trim($path), '/');
        $path = preg_replace('#/+#', '/', $path) ?: '/';
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /** @return array{limit:int,offset:int} */
    public function pagination(int $default = 20, int $max = 100): array
    {
        return [
            'limit' => max(1, min($max, (int) ($this->request->query['limit'] ?? $default))),
            'offset' => max(0, (int) ($this->request->query['offset'] ?? 0)),
        ];
    }
}
