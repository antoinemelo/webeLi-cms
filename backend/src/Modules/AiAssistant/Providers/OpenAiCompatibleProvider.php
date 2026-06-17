<?php

declare(strict_types=1);

namespace App\Modules\AiAssistant\Providers;

use App\Modules\AiAssistant\Http\AiHttpClient;

/**
 * Provider HTTP minimal compatible OpenAI /v1.
 *
 * Les appels réseau passent par AiHttpClient afin de centraliser cURL,
 * timeouts, erreurs HTTP, décodage JSON, durée et identifiant provider.
 */
final class OpenAiCompatibleProvider implements AiStreamingChatProviderInterface
{
    private readonly AiHttpClient $http;

    public function __construct(
        private readonly string $key,
        private readonly string $name,
        private readonly ?string $baseUrl,
        private readonly ?string $apiKeyRef,
        private readonly string $modelKey,
        ?AiHttpClient $http = null,
    ) {
        $this->http = $http ?? new AiHttpClient();
    }

    public function key(): string { return $this->key; }

    public function name(): string { return $this->name; }

    public function isConfigured(): bool
    {
        return $this->resolvedBaseUrl() !== '' && $this->apiKey() !== null;
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'configured' => $this->isConfigured(),
            'external_calls' => true,
            'base_url' => $this->resolvedBaseUrl(),
            'api_key_ref' => $this->apiKeyRef,
            'api_key_env' => $this->apiKeyEnvName(),
            'api_key_available' => $this->apiKey() !== null,
            'model' => $this->modelKey,
            'http_client' => 'curl',
            'message' => $this->isConfigured()
                ? 'Provider configuré. Le test effectue un appel réseau minimal vers l’endpoint /models via le client HTTP IA cURL.'
                : 'Provider non configuré : base_url ou variable d’environnement de clé API manquante.',
        ];
    }

    public function chat(array $messages, array $options = []): array
    {
        $baseUrl = $this->resolvedBaseUrl();
        $apiKey = $this->apiKey();
        $model = (string) (($options['model'] ?? '') ?: $this->modelKey);
        if ($baseUrl === '' || $apiKey === null) {
            return $this->notConfiguredResponse($model, 'Provider non configuré : renseignez base_url et api_key_ref vers une variable d’environnement existante.');
        }

        $payload = [
            'model' => $model,
            'messages' => array_values($messages),
            'temperature' => (float) ($options['temperature'] ?? 0.2),
            'max_tokens' => max(1, (int) ($options['max_tokens'] ?? 80)),
        ];

        $http = $this->http->requestJson('POST', rtrim($baseUrl, '/') . '/chat/completions', [
            'Authorization' => 'Bearer ' . $apiKey,
        ], $payload, 15);

        $decoded = is_array($http['json'] ?? null) ? $http['json'] : [];
        $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
        $content = (string) ($decoded['choices'][0]['message']['content'] ?? $decoded['output_text'] ?? '');
        $ok = (bool) ($http['ok'] ?? false) && $content !== '';
        $errorType = $ok ? null : (string) (($http['error_type'] ?? null) ?: ((bool) ($http['ok'] ?? false) ? 'invalid_response' : 'unknown_error'));
        if (!$ok && (bool) ($http['ok'] ?? false) && $content === '') {
            $errorType = 'invalid_response';
        }

        return $this->withHttpDiagnostics([
            'ok' => $ok,
            'provider' => $this->key,
            'model' => $model,
            'content' => $ok ? $content : '',
            'usage' => [
                'input_tokens' => (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0),
                'output_tokens' => (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0),
                'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
            ],
            'api_key_ref' => $this->apiKeyRef,
            'api_key_env' => $this->apiKeyEnvName(),
            'api_key_available' => true,
            'external_calls' => true,
            'error_type' => $errorType,
            'error_message' => $ok ? null : (($http['error_message'] ?? null) ?: 'Réponse provider vide ou non exploitable.'),
        ], $http);
    }


    public function supportsStreaming(): bool
    {
        return $this->isConfigured();
    }

    /**
     * @param list<array{role:string,content:string}> $messages
     * @param array<string,mixed> $options
     * @param callable(string,array<string,mixed>):void $onEvent
     * @return array<string,mixed>
     */
    public function streamChat(array $messages, array $options, callable $onEvent): array
    {
        $baseUrl = $this->resolvedBaseUrl();
        $apiKey = $this->apiKey();
        $model = (string) (($options['model'] ?? '') ?: $this->modelKey);
        if ($baseUrl === '' || $apiKey === null) {
            $response = $this->notConfiguredResponse($model, 'Provider non configuré : renseignez base_url et api_key_ref vers une variable d’environnement existante.');
            $onEvent('error', [
                'error_type' => $response['error_type'],
                'error_message' => $response['error_message'],
            ]);
            return $response;
        }

        $payload = [
            'model' => $model,
            'messages' => array_values($messages),
            'temperature' => (float) ($options['temperature'] ?? 0.2),
            'max_tokens' => max(1, (int) ($options['max_tokens'] ?? 120)),
            'stream' => true,
            'stream_options' => ['include_usage' => true],
        ];

        $http = $this->http->streamJson('POST', rtrim($baseUrl, '/') . '/chat/completions', [
            'Authorization' => 'Bearer ' . $apiKey,
        ], $payload, $onEvent, (int) ($options['timeout_seconds'] ?? 60));

        $usage = is_array($http['usage'] ?? null) ? $http['usage'] : [];
        $content = (string) ($http['content'] ?? '');
        $ok = (bool) ($http['ok'] ?? false) && $content !== '';
        $errorType = $ok ? null : (string) (($http['error_type'] ?? null) ?: ((bool) ($http['ok'] ?? false) ? 'invalid_response' : 'unknown_error'));

        return $this->withHttpDiagnostics([
            'ok' => $ok,
            'provider' => $this->key,
            'model' => $model,
            'content' => $ok ? $content : '',
            'usage' => [
                'input_tokens' => (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0),
                'output_tokens' => (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0),
                'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
            ],
            'api_key_ref' => $this->apiKeyRef,
            'api_key_env' => $this->apiKeyEnvName(),
            'api_key_available' => true,
            'external_calls' => true,
            'streamed' => true,
            'error_type' => $errorType,
            'error_message' => $ok ? null : (($http['error_message'] ?? null) ?: 'Réponse streamée provider vide ou non exploitable.'),
        ], $http);
    }

    /** @return array<string,mixed> */
    public function testConnection(): array
    {
        $baseUrl = $this->resolvedBaseUrl();
        $apiKey = $this->apiKey();
        if ($baseUrl === '' || $apiKey === null) {
            $missing = [];
            if ($baseUrl === '') {
                $missing[] = 'base_url';
            }
            if ($apiKey === null) {
                $envName = $this->apiKeyEnvName();
                $missing[] = $envName !== null ? 'variable ' . $envName : 'api_key_ref';
            }

            return $this->notConfiguredResponse($this->modelKey, 'Provider non configuré : ' . implode(', ', $missing) . ' manquant(e). La clé réelle doit être placée dans ops/.env ou dans l’environnement serveur, pas dans le catalogue.');
        }

        $http = $this->http->requestJson('GET', rtrim($baseUrl, '/') . '/models', [
            'Authorization' => 'Bearer ' . $apiKey,
        ], null, 8);
        $ok = (bool) ($http['ok'] ?? false);

        return $this->withHttpDiagnostics([
            'ok' => $ok,
            'provider' => $this->key,
            'model' => $this->modelKey,
            'content' => $ok ? 'Connexion provider OK.' : 'Connexion provider échouée.',
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0],
            'api_key_ref' => $this->apiKeyRef,
            'api_key_env' => $this->apiKeyEnvName(),
            'api_key_available' => true,
            'external_calls' => true,
            'error_type' => $ok ? null : ($http['error_type'] ?? 'provider_unavailable'),
            'error_message' => $ok ? null : ($http['error_message'] ?? 'Erreur de connexion provider.'),
        ], $http);
    }

    private function resolvedBaseUrl(): string
    {
        return rtrim((string) ($this->baseUrl ?: 'https://api.openai.com/v1'), '/');
    }

    private function apiKey(): ?string
    {
        $envName = $this->apiKeyEnvName();
        if ($envName === null) {
            return null;
        }
        $value = $_ENV[$envName] ?? $_SERVER[$envName] ?? getenv($envName);
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function apiKeyEnvName(): ?string
    {
        $ref = trim((string) $this->apiKeyRef);
        if ($ref === '') {
            return null;
        }
        $envName = str_starts_with($ref, 'env:') ? substr($ref, 4) : $ref;
        return preg_match('/^[A-Z][A-Z0-9_]{2,}$/', $envName) ? $envName : null;
    }

    /** @param array<string,mixed> $response @param array<string,mixed> $http */
    private function withHttpDiagnostics(array $response, array $http): array
    {
        $body = is_string($http['body'] ?? null) ? $http['body'] : '';
        $statusCode = (int) ($http['status_code'] ?? 0);

        return $response + [
            'status_code' => $statusCode,
            'http_status' => $statusCode,
            'body' => $body,
            'body_preview' => mb_substr($body, 0, 300),
            'json' => is_array($http['json'] ?? null) ? $http['json'] : null,
            'duration_ms' => (int) ($http['duration_ms'] ?? 0),
            'provider_response_id' => is_string($http['provider_response_id'] ?? null) ? $http['provider_response_id'] : null,
        ];
    }

    /** @return array<string,mixed> */
    private function notConfiguredResponse(string $model, string $message): array
    {
        return [
            'ok' => false,
            'provider' => $this->key,
            'model' => $model,
            'content' => $message,
            'status_code' => 0,
            'http_status' => 0,
            'body' => '',
            'body_preview' => '',
            'json' => null,
            'duration_ms' => 0,
            'error_type' => 'bad_request',
            'error_message' => $message,
            'provider_response_id' => null,
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0],
            'api_key_ref' => $this->apiKeyRef,
            'api_key_env' => $this->apiKeyEnvName(),
            'api_key_available' => $this->apiKey() !== null,
            'external_calls' => false,
        ];
    }
}
