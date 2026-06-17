<?php

declare(strict_types=1);

namespace App\Modules\AiAssistant\Providers;

interface AiRerankerProviderInterface extends AiProviderInterface
{
    /** @param list<array<string,mixed>> $documents @return array<string,mixed> */
    public function rerank(string $query, array $documents, array $options = []): array;
}
