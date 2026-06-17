<?php

declare(strict_types=1);

namespace App\Modules\AiAssistant\Providers;

interface AiProviderInterface
{
    public function key(): string;

    public function name(): string;

    public function isConfigured(): bool;

    /** @return array<string,mixed> */
    public function status(): array;
}
