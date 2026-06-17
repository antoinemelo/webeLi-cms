<?php

declare(strict_types=1);

namespace App\Application\Capability;

use App\Repository\AuthRepository;

final class CapabilityExecutor
{
    public function __construct(
        private readonly CapabilityRegistry $registry,
        private readonly ActionRunRepository $runs,
        private readonly AuthRepository $auth,
    ) {}

    /** @param array<string,mixed> $input */
    public function execute(string $key, array $input, string $mode, ?int $siteId = null, bool $confirmed = false): CapabilityResult
    {
        $mode = $mode === 'apply' ? 'apply' : 'dry_run';
        $definition = $this->registry->get($key);
        if ($definition === null) {
            return $this->resultAndLog(null, $key, $mode, 'not_found', false, [], [], [['code' => 'capability_not_found', 'message' => 'Capability introuvable.']], $input, $siteId);
        }

        if (!$this->auth->hasPermission($definition->permission, $siteId)) {
            return $this->resultAndLog($definition, $key, $mode, 'forbidden', false, [], [], [['code' => 'permission_denied', 'message' => 'Permission insuffisante.', 'permission' => $definition->permission]], $input, $siteId);
        }

        if ($mode === 'dry_run' && !$definition->supportsDryRun) {
            return $this->resultAndLog($definition, $key, $mode, 'dry_run_not_supported', false, [], [], [['code' => 'dry_run_not_supported', 'message' => 'Cette capability ne supporte pas le dry-run.']], $input, $siteId);
        }

        $validationErrors = $this->validateInput($definition->inputSchema, $input);
        if ($validationErrors !== []) {
            return $this->resultAndLog($definition, $key, $mode, 'validation_failed', false, [], [], $validationErrors, $input, $siteId);
        }

        if ($mode === 'apply' && $definition->requiresConfirmation && !$confirmed) {
            return $this->resultAndLog($definition, $key, $mode, 'confirmation_required', false, ['requires_confirmation' => true], [], [['code' => 'confirmation_required', 'message' => 'Confirmation explicite requise avant application.']], $input, $siteId);
        }

        $handler = $this->registry->handler($key);
        if ($handler === null) {
            $data = $mode === 'dry_run'
                ? DryRunResult::make(true, false, ['action' => $key], [], ['Capability déclarée sans handler applicatif dans le core.'], [], $definition->requiresConfirmation)
                : [];
            $status = $mode === 'dry_run' ? 'success' : 'not_implemented';
            $ok = $mode === 'dry_run';
            $errors = $mode === 'apply' ? [['code' => 'not_implemented', 'message' => 'Capability déclarée mais non exécutable par le core.']] : [];
            return $this->resultAndLog($definition, $key, $mode, $status, $ok, $data, [], $errors, $input, $siteId);
        }

        try {
            $data = $handler($input, $mode, $definition);
            if ($mode === 'dry_run' && !isset($data['would_change'])) {
                $data = DryRunResult::make(true, false, ['action' => $key], [], [], [], $definition->requiresConfirmation) + ['result' => $data];
            }
            return $this->resultAndLog($definition, $key, $mode, 'success', true, is_array($data) ? $data : ['value' => $data], [], [], $input, $siteId);
        } catch (\Throwable $e) {
            return $this->resultAndLog($definition, $key, $mode, 'failed', false, [], [], [['code' => 'execution_failed', 'message' => $e->getMessage()]], $input, $siteId);
        }
    }

    /** @param array<string,mixed> $inputSchema @param array<string,mixed> $input @return list<array<string,mixed>> */
    private function validateInput(array $inputSchema, array $input): array
    {
        if ($inputSchema === []) {
            return [];
        }
        $errors = [];
        foreach ((array) ($inputSchema['required'] ?? []) as $required) {
            $required = (string) $required;
            if ($required !== '' && !array_key_exists($required, $input)) {
                $errors[] = ['code' => 'required', 'field' => $required, 'message' => $required . ' est obligatoire.'];
            }
        }
        $properties = is_array($inputSchema['properties'] ?? null) ? $inputSchema['properties'] : [];
        foreach ($input as $key => $value) {
            if (($inputSchema['additionalProperties'] ?? true) === false && !array_key_exists($key, $properties)) {
                $errors[] = ['code' => 'unknown_field', 'field' => (string) $key, 'message' => 'Champ non autorisé.'];
                continue;
            }
            $expected = is_array($properties[$key] ?? null) ? (string) ($properties[$key]['type'] ?? '') : '';
            if ($expected !== '' && !$this->matchesType($expected, $value)) {
                $errors[] = ['code' => 'invalid_type', 'field' => (string) $key, 'message' => 'Type attendu: ' . $expected . '.'];
            }
        }
        return $errors;
    }

    private function matchesType(string $expected, mixed $value): bool
    {
        return match ($expected) {
            'string' => is_string($value),
            'integer' => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1),
            'number' => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value),
            default => true,
        };
    }

    /** @param array<string,mixed> $data @param list<array<string,mixed>|string> $warnings @param list<array<string,mixed>|string> $errors @param array<string,mixed> $input */
    private function resultAndLog(?CapabilityDefinition $definition, string $key, string $mode, string $status, bool $ok, array $data, array $warnings, array $errors, array $input, ?int $siteId): CapabilityResult
    {
        $userId = (int) ($this->auth->user()['id'] ?? 0) ?: null;
        $this->runs->log(
            $siteId,
            $userId,
            $definition?->module ?? 'unknown',
            $key,
            $mode,
            $status,
            $definition?->riskLevel ?? 'low',
            $input,
            ['ok' => $ok, 'status' => $status, 'errors' => $errors],
        );

        return new CapabilityResult($ok, $key, $mode, $status, $data, $warnings, $errors);
    }
}
