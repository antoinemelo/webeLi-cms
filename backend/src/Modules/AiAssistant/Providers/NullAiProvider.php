<?php

declare(strict_types=1);

namespace App\Modules\AiAssistant\Providers;

final class NullAiProvider implements AiChatProviderInterface
{
    public function key(): string { return 'null_provider'; }

    public function name(): string { return 'Provider nul'; }

    public function isConfigured(): bool { return true; }

    public function status(): array
    {
        return [
            'key' => $this->key(),
            'name' => $this->name(),
            'configured' => true,
            'external_calls' => false,
            'message' => 'IA non configurée. Provider nul actif, sans appel réseau ni coût.',
        ];
    }

    public function chat(array $messages, array $options = []): array
    {
        return [
            'ok' => true,
            'provider' => $this->key(),
            'model' => (string) ($options['model'] ?? 'null_text_model'),
            'content' => 'IA non configurée. Suggestion simulée.',
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0],
            'external_calls' => false,
        ];
    }
}
