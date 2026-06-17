<?php

declare(strict_types=1);

namespace App\StaticExport;

final class StaticExportRouteCollector
{
    /** @var list<array<string,mixed>> */
    private array $ignored = [];

    public function __construct(private readonly StaticExportSourceContract $source) {}

    /** @return list<StaticExportRoute> */
    public function collect(?string $siteKey = null, ?string $languageCode = null, ?string $routePath = null): array
    {
        $this->ignored = [];
        $routes = [];
        $seen = [];
        foreach ($this->source->listExportableRoutes($siteKey, $languageCode, $routePath) as $route) {
            $reason = $this->exclusionReason($route);
            if ($reason !== null) {
                $this->ignored[] = ['path' => $route->path, 'site' => $route->siteKey, 'language' => $route->languageCode, 'reason' => $reason];
                continue;
            }
            $key = $route->siteKey . '|' . $route->languageCode . '|' . $route->path;
            if (isset($seen[$key])) {
                $this->ignored[] = ['path' => $route->path, 'site' => $route->siteKey, 'language' => $route->languageCode, 'reason' => 'duplicate_route'];
                continue;
            }
            $seen[$key] = true;
            $routes[] = $route;
        }
        return $routes;
    }

    /** @return list<array<string,mixed>> */
    public function ignoredRoutes(): array
    {
        return $this->ignored;
    }

    private function exclusionReason(StaticExportRoute $route): ?string
    {
        if ($route->statusCode !== 200 && $route->statusCode !== 410) {
            return 'unsupported_status';
        }
        if (preg_match('#^/(admin|api|preview|backend|tools|database|ops|_debug|_internal)(/|$)#i', $route->path)) {
            return 'internal_route';
        }
        if (preg_match('#\.(sqlite|sqlite-wal|sqlite-shm|sqlite-journal|log|bak|zip|tmp|env)$#i', $route->path)) {
            return 'sensitive_or_technical_file';
        }
        if (!$route->isCanonical) {
            return 'non_canonical_route';
        }
        return null;
    }
}
