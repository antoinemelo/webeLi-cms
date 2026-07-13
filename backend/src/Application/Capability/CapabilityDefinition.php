<?php

declare(strict_types=1);

namespace App\Application\Capability;

final class CapabilityDefinition
{
    public const TYPES = ['port', 'provider', 'workflow', 'event', 'validator'];

    /** @param array<string,mixed> $inputSchema @param array<string,mixed> $outputSchema @param array<string,mixed> $config */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $module,
        public readonly string $permission,
        public readonly string $version = '1.0',
        public readonly string $type = 'workflow',
        public readonly string $contract = '',
        public readonly array $config = [],
        public readonly bool $active = true,
        public readonly ?int $priority = null,
        public readonly array $inputSchema = [],
        public readonly array $outputSchema = [],
        public readonly bool $supportsDryRun = true,
        public readonly bool $requiresConfirmation = false,
        public readonly string $riskLevel = 'low',
        public readonly string $description = '',
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/', $this->key)) {
            throw new \InvalidArgumentException('Invalid capability key: ' . $this->key);
        }
        if (!preg_match('/^\d+\.\d+(?:\.\d+)?$/', $this->version)) {
            throw new \InvalidArgumentException('Invalid capability version: ' . $this->version);
        }
        if (!in_array($this->type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Invalid capability type: ' . $this->type);
        }
        if ($this->contract === '' || !preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+\.v\d+$/', $this->contract)) {
            throw new \InvalidArgumentException('Invalid capability contract: ' . $this->contract);
        }
        if (!in_array($this->riskLevel, ['low', 'medium', 'high'], true)) {
            throw new \InvalidArgumentException('Invalid capability risk level: ' . $this->riskLevel);
        }
        if ($this->permission === '') {
            throw new \InvalidArgumentException('Capability permission is required: ' . $this->key);
        }
    }

    /** @param array<string,mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            key: trim((string) ($row['key'] ?? '')),
            label: trim((string) ($row['label'] ?? $row['key'] ?? '')),
            module: trim((string) ($row['module'] ?? 'core')),
            permission: trim((string) ($row['permission'] ?? '')),
            version: trim((string) ($row['version'] ?? '1.0')) ?: '1.0',
            type: trim((string) ($row['type'] ?? 'workflow')) ?: 'workflow',
            contract: trim((string) ($row['contract'] ?? '')),
            config: is_array($row['config'] ?? null) ? $row['config'] : [],
            active: (bool) ($row['active'] ?? $row['enabled'] ?? true),
            priority: isset($row['priority']) && $row['priority'] !== '' ? (int) $row['priority'] : null,
            inputSchema: is_array($row['input_schema'] ?? null) ? $row['input_schema'] : [],
            outputSchema: is_array($row['output_schema'] ?? null) ? $row['output_schema'] : [],
            supportsDryRun: (bool) ($row['supports_dry_run'] ?? true),
            requiresConfirmation: (bool) ($row['requires_confirmation'] ?? false),
            riskLevel: trim((string) ($row['risk_level'] ?? 'low')) ?: 'low',
            description: trim((string) ($row['description'] ?? '')),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(bool $includeSchemas = true): array
    {
        $payload = [
            'key' => $this->key,
            'label' => $this->label,
            'module' => $this->module,
            'permission' => $this->permission,
            'version' => $this->version,
            'type' => $this->type,
            'contract' => $this->contract,
            'config' => (object) $this->config,
            'active' => $this->active,
            'priority' => $this->priority,
            'supports_dry_run' => $this->supportsDryRun,
            'requires_confirmation' => $this->requiresConfirmation,
            'risk_level' => $this->riskLevel,
            'description' => $this->description,
        ];
        if ($includeSchemas) {
            $payload['input_schema'] = (object) $this->inputSchema;
            $payload['output_schema'] = (object) $this->outputSchema;
        }
        return $payload;
    }
}
