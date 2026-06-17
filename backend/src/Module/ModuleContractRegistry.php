<?php

declare(strict_types=1);

namespace App\Module;

final class ModuleContractRegistry
{
    public function __construct(private readonly ModuleRegistry $registry) {}

    /** @return list<array<string,mixed>> */
    public function contracts(): array
    {
        $contracts = [];
        foreach ($this->registry->activeProviders() as $provider) {
            foreach ($provider->apiContracts() as $contract) {
                $contract['module_key'] = $provider->key();
                $contracts[] = $contract;
            }
        }
        return $contracts;
    }

    /** @return list<array<string,string>> */
    public function endpoints(): array
    {
        $endpoints = [];
        foreach ($this->registry->activeProviders() as $provider) {
            foreach ([
                'admin' => $provider->adminRoutes(),
                'api' => $provider->apiRoutes(),
                'headless' => $provider->publicHeadlessRoutes(),
            ] as $scope => $routes) {
                foreach ($routes as $index => $route) {
                    $endpoints[] = [
                        'key' => 'module.' . $provider->key() . '.' . $scope . '.' . $index,
                        'module_key' => $provider->key(),
                        'scope' => $scope,
                        'method' => strtoupper((string) $route[0]),
                        'path' => (string) $route[1],
                    ];
                }
            }
        }
        return $endpoints;
    }
}
