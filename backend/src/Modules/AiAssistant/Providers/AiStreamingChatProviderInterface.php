<?php

declare(strict_types=1);

namespace App\Modules\AiAssistant\Providers;

/**
 * Interface optionnelle pour les providers capables de streamer une réponse.
 *
 * Les callbacks ne doivent jamais recevoir de clé API, d'headers sensibles ou de
 * payload provider complet. Les événements autorisés sont: start, token, usage,
 * done, error.
 */
interface AiStreamingChatProviderInterface extends AiChatProviderInterface
{
    /**
     * @param list<array{role:string,content:string}> $messages
     * @param array<string,mixed> $options
     * @param callable(string,array<string,mixed>):void $onEvent
     * @return array<string,mixed>
     */
    public function streamChat(array $messages, array $options, callable $onEvent): array;

    public function supportsStreaming(): bool;
}
