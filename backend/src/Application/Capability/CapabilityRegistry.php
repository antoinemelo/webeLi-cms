<?php

declare(strict_types=1);

namespace App\Application\Capability;

use App\Module\ModuleRegistry;

final class CapabilityRegistry
{
    /** @var array<string,array{type:string,contract:string,version:string}> */
    private const KNOWN_CAPABILITIES = [
        'core.context.describe' => ['type' => 'port', 'contract' => 'core.context.v1', 'version' => '1.0'],
        'catalog.product.read' => ['type' => 'port', 'contract' => 'catalog.product_reader.v1', 'version' => '1.0'],
        'catalog.product.extend' => ['type' => 'port', 'contract' => 'catalog.product_extension.v1', 'version' => '1.0'],
        'pricing.calculate' => ['type' => 'port', 'contract' => 'sale.pricing_calculator.v1', 'version' => '1.0'],
        'cart.validate' => ['type' => 'validator', 'contract' => 'sale.cart_validator.v1', 'version' => '1.0'],
        'checkout.validate' => ['type' => 'validator', 'contract' => 'sale.checkout_validator.v1', 'version' => '1.0'],
        'order.after_place' => ['type' => 'event', 'contract' => 'sale.order_after_place.v1', 'version' => '1.0'],
        'payment.provider' => ['type' => 'provider', 'contract' => 'sale.payment_provider.v1', 'version' => '1.0'],
        'fulfillment.provider' => ['type' => 'provider', 'contract' => 'sale.fulfillment_provider.v1', 'version' => '1.0'],
        'notification.provider' => ['type' => 'provider', 'contract' => 'core.notification_provider.v1', 'version' => '1.0'],
        'crm.activity.consume' => ['type' => 'event', 'contract' => 'business.crm_activity_consumer.v1', 'version' => '1.0'],
    ];

    /** @var array<string,CapabilityDefinition> */
    private array $definitions = [];
    /** @var array<string,callable> */
    private array $handlers = [];
    /** @var list<array<string,mixed>> */
    private array $diagnostics = [];
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
        usort($items, static function (CapabilityDefinition $a, CapabilityDefinition $b): int {
            $priorityA = $a->priority ?? PHP_INT_MAX;
            $priorityB = $b->priority ?? PHP_INT_MAX;
            return [$priorityA, $a->key, $a->module] <=> [$priorityB, $b->key, $b->module];
        });
        return $items;
    }

    /** @return array<string,array{type:string,contract:string,version:string}> */
    public function knownCapabilities(): array
    {
        return self::KNOWN_CAPABILITIES;
    }

    /** @return list<array<string,mixed>> */
    public function diagnostics(): array
    {
        $this->boot();
        return $this->diagnostics;
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
        $this->assertKnownContract($definition);
        if (isset($this->definitions[$definition->key])) {
            throw new \LogicException(sprintf(
                'Capability collision on %s between %s and %s.',
                $definition->key,
                $this->definitions[$definition->key]->module,
                $definition->module
            ));
        }
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
            version: '1.0',
            type: 'port',
            contract: 'core.context.v1',
            config: ['foreign_tables' => [], 'mutates' => false],
            active: true,
            priority: 10,
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
            $handlers = $provider instanceof ModuleCapabilityHandlerProvider ? $provider->capabilityHandlers() : [];
            foreach ($provider->capabilities() as $capability) {
                $definition = null;
                try {
                    $definition = $capability instanceof CapabilityDefinition ? $capability : CapabilityDefinition::fromArray($capability);
                    $handler = is_callable($handlers[$definition->key] ?? null) ? $handlers[$definition->key] : null;
                    $this->register($definition, $handler);
                } catch (\Throwable $e) {
                    $this->diagnostics[] = [
                        'code' => 'capability_rejected',
                        'module' => method_exists($provider, 'key') ? $provider->key() : ($definition?->module ?? 'unknown'),
                        'capability' => $definition instanceof CapabilityDefinition ? $definition->key : (is_array($capability) ? (string) ($capability['key'] ?? '') : ''),
                        'message' => $e->getMessage(),
                    ];
                    continue;
                }
            }
        }
    }

    private function assertKnownContract(CapabilityDefinition $definition): void
    {
        $expected = self::KNOWN_CAPABILITIES[$definition->key] ?? null;
        if ($expected === null) {
            throw new \InvalidArgumentException('Unknown capability key: ' . $definition->key);
        }
        if ($definition->type !== $expected['type']) {
            throw new \InvalidArgumentException(sprintf(
                'Capability %s must be declared as %s, %s given.',
                $definition->key,
                $expected['type'],
                $definition->type
            ));
        }
        if ($definition->contract !== '' && $definition->contract !== $expected['contract']) {
            throw new \InvalidArgumentException(sprintf(
                'Capability %s expects contract %s, %s given.',
                $definition->key,
                $expected['contract'],
                $definition->contract
            ));
        }
        if (version_compare($definition->version, $expected['version'], '<')) {
            throw new \InvalidArgumentException(sprintf(
                'Capability %s version %s is lower than required %s.',
                $definition->key,
                $definition->version,
                $expected['version']
            ));
        }
    }
}
