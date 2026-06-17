<?php

declare(strict_types=1);

namespace App\Application\Capability;

final class CapabilityResult
{
    /** @param array<string,mixed> $data @param list<array<string,mixed>|string> $warnings @param list<array<string,mixed>|string> $errors */
    public function __construct(
        public readonly bool $ok,
        public readonly string $action,
        public readonly string $mode,
        public readonly string $status,
        public readonly array $data = [],
        public readonly array $warnings = [],
        public readonly array $errors = [],
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'action' => $this->action,
            'mode' => $this->mode,
            'status' => $this->status,
            'data' => (object) $this->data,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
        ];
    }
}
