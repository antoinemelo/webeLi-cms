<?php

declare(strict_types=1);

namespace App\Modules\AiAssistant\Providers;

interface AiSpeechToTextProviderInterface extends AiProviderInterface
{
    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function transcribe(string $audioPath, array $options = []): array;
}
