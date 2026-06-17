<?php

declare(strict_types=1);

namespace App\Application\Capability;

use App\Module\ModuleRegistry;

final class CapabilityRegistry
{
    /** @var array<string,CapabilityDefinition> */
    private array $definitions = [];
    /** @var array<string,callable> */
    private array $handlers = [];
    private bool $booted = false;

    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly BlueprintActionContextService $context,
    ) {}

    /** @return list<CapabilityDefinition> */
    public function all(): array
    {
        $this->boot();
        $items = array_values($this->definitions);
        usort($items, static fn(CapabilityDefinition $a, CapabilityDefinition $b): int => strcmp($a->key, $b->key));
        return $items;
    }

    public function get(string $key): ?CapabilityDefinition
    {
        $this->boot();
        return $this->definitions[$key] ?? null;
    }

    public function handler(string $key): ?callable
    {
        $this->boot();
        return $this->handlers[$key] ?? null;
    }

    public function register(CapabilityDefinition $definition, ?callable $handler = null): void
    {
        $this->definitions[$definition->key] = $definition;
        if ($handler !== null) {
            $this->handlers[$definition->key] = $handler;
        }
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        $this->register(new CapabilityDefinition(
            key: 'core.context.describe',
            label: 'Décrire le contexte actionnable',
            module: 'core',
            permission: 'fields.read',
            inputSchema: [
                'type' => 'object',
                'properties' => ['site_id' => ['type' => 'integer']],
                'required' => [],
                'additionalProperties' => false,
            ],
            outputSchema: ['type' => 'object'],
            supportsDryRun: true,
            requiresConfirmation: false,
            riskLevel: 'low',
            description: 'Expose un contexte réduit blueprints/blocs/champs pour modules autorisés, sans accès direct à SQLite.',
        ), function (array $input, string $mode): array {
            $siteId = isset($input['site_id']) && (int) $input['site_id'] > 0 ? (int) $input['site_id'] : null;
            return $this->context->describe($siteId);
        });

        foreach ($this->modules->activeProviders() as $provider) {
            if (!$provider instanceof ModuleCapabilityProvider) {
                continue;
            }
            foreach ($provider->capabilities() as $capability) {
                try {
                    $definition = $capability instanceof CapabilityDefinition ? $capability : CapabilityDefinition::fromArray($capability);
                    $this->register($definition);
                } catch (\Throwable) {
                    continue;
                }
            }
        }
    }
}
