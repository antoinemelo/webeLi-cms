<?php

declare(strict_types=1);

namespace App\Application\Capability;

final class DryRunResult
{
    /** @param array<string,mixed> $target @param list<array<string,mixed>> $changes @param list<string> $warnings @param list<string> $errors */
    public static function make(
        bool $ok,
        bool $wouldChange,
        array $target = [],
        array $changes = [],
        array $warnings = [],
        array $errors = [],
        bool $requiresConfirmation = false,
    ): array {
        return [
            'ok' => $ok,
            'would_change' => $wouldChange,
            'target' => (object) $target,
            'changes' => $changes,
            'warnings' => $warnings,
            'errors' => $errors,
            'requires_confirmation' => $requiresConfirmation,
        ];
    }
}
