<?php

declare(strict_types=1);

namespace App\Modules\AiAssistant\Providers;

interface AiEmbeddingProviderInterface extends AiProviderInterface
{
    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function embed(string $text, array $options = []): array;
}
