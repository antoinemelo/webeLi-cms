<?php

declare(strict_types=1);

namespace App\Module;

use App\Core\Logger;

final class ModuleRouteLoader
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ?Logger $logger = null,
    ) {}

    /** @return list<array{0:string,1:string,2:string}> */
    public function routes(string $scope): array
    {
        if (!in_array($scope, ['admin', 'api', 'web', 'headless'], true)) {
            throw new \InvalidArgumentException('Scope de routes module invalide: ' . $scope);
        }

        $routes = [];
        foreach ($this->registry->activeProviders() as $provider) {
            foreach ($this->routesForScope($provider, $scope) as $route) {
                if (!$this->isValidRoute($route)) {
                    $this->logger?->warning('module.route_invalid', ['module' => $provider->key(), 'scope' => $scope, 'route' => $route]);
                    continue;
                }
                $routes[] = [strtoupper($route[0]), $route[1], $route[2]];
            }
        }
        return $routes;
    }

    /** @return list<array{0:string,1:string,2:string}> */
    private function routesForScope(ModuleProvider $provider, string $scope): array
    {
        return match ($scope) {
            'admin' => $provider->adminRoutes(),
            'api' => $provider->apiRoutes(),
            'headless' => $provider->publicHeadlessRoutes(),
            // Compatibilité avec l'ancien nom de scope public.
            'web' => $provider->publicHeadlessRoutes(),
            default => [],
        };
    }

    private function isValidRoute(mixed $route): bool
    {
        return is_array($route)
            && count($route) === 3
            && is_string($route[0])
            && is_string($route[1])
            && is_string($route[2])
            && trim($route[0]) !== ''
            && str_starts_with($route[1], '/')
            && str_contains($route[2], '@');
    }
}
