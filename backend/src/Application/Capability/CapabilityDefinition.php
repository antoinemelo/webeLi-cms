<?php

declare(strict_types=1);

namespace App\Application\Capability;

final class CapabilityDefinition
{
    /** @param array<string,mixed> $inputSchema @param array<string,mixed> $outputSchema */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $module,
        public readonly string $permission,
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
        if (!in_array($this->riskLevel, ['low', 'medium', 'high'], true)) {
            throw new \InvalidArgumentException('Invalid capability risk level: ' . $this->riskLevel);
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
