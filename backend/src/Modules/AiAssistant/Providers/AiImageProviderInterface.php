<?php

declare(strict_types=1);

namespace App\Modules\AiAssistant\Providers;

interface AiImageProviderInterface extends AiProviderInterface
{
    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function generateImage(string $prompt, array $options = []): array;
}
