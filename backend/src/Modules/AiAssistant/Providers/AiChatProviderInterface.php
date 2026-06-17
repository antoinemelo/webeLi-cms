<?php

declare(strict_types=1);

namespace App\Modules\AiAssistant\Providers;

interface AiChatProviderInterface extends AiProviderInterface
{
    /** @param list<array{role:string,content:string}> $messages @param array<string,mixed> $options @return array<string,mixed> */
    public function chat(array $messages, array $options = []): array;
}
