<?php

declare(strict_types=1);

namespace App\Modules\AiAssistant\Services;

use App\Modules\AiAssistant\Providers\AiChatProviderInterface;
use App\Modules\AiAssistant\Providers\AiProviderInterface;
use App\Modules\AiAssistant\Providers\NullAiProvider;
use App\Modules\AiAssistant\Providers\OpenAiCompatibleProvider;
use App\Modules\AiAssistant\Providers\AiStreamingChatProviderInterface;

final class AiProviderManager
{
    public function __construct(
        private readonly AiSettingsService $settings,
        private readonly ?AiPromptService $prompts = null,
        private readonly ?AiBudgetService $budgets = null,
    ) {}

    public function provider(): AiProviderInterface
    {
        $provider = $this->settings->defaultProvider();
        $model = $this->settings->defaultChatModel();
        return $this->providerFromConfig($provider, $model);
    }

    public function chatProvider(): AiChatProviderInterface
    {
        $provider = $this->provider();
        return $provider instanceof AiChatProviderInterface ? $provider : new NullAiProvider();
    }

    /** @return array<string,mixed> */
    public function test(): array
    {
        return $this->testProvider($this->chatProvider(), (string) (($this->settings->defaultChatModel()['key'] ?? '') ?: 'null_text_model'));
    }

    /** @param array<string,mixed> $variables @return array<string,mixed> */
    public function testForSiteUsage(int $siteId, string $usageKey = 'editorial', string $prompt = '', string $actionKey = '', ?string $locale = null, array $variables = [], string $testKind = 'usage_configured'): array
    {
        $started = microtime(true);
        $usageKey = $this->normalizeUsageKey($usageKey);
        $actionKey = $this->normalizeActionKey($actionKey) ?: $this->defaultActionForUsage($usageKey);
        $testKind = $this->normalizeTestKind($testKind);
        $usageType = $this->settings->usageTypeForKey($usageKey);
        $prompt = $this->testPrompt($prompt);
        if (!$usageType || empty($usageType['enabled'])) {
            return $this->usageTestError(
                $siteId,
                $usageKey,
                $prompt,
                'bad_request',
                "L’usage IA {$usageKey} n’existe pas ou n’est pas actif dans le catalogue.",
                $started,
                ['action_key' => $actionKey],
            );
        }

        $model = $this->settings->modelForSiteUsage($siteId, $usageKey);
        if (!$model) {
            return $this->usageTestError(
                $siteId,
                $usageKey,
                $prompt,
                'bad_request',
                "Aucun modèle actif n’est configuré pour l’usage {$usageKey} du site courant.",
                $started,
                ['usage_label' => (string) ($usageType['name'] ?? $usageKey), 'action_key' => $actionKey],
            );
        }

        $providerConfig = [
            'key' => $model['provider_key'] ?? 'null_provider',
            'name' => $model['provider_name'] ?? $model['provider_key'] ?? 'Provider IA',
            'provider_type' => $model['provider_type'] ?? 'null',
            'base_url' => $model['base_url'] ?? null,
            'api_key_ref' => $model['effective_api_key_ref'] ?? $model['api_key_ref'] ?? null,
        ];
        $provider = $this->providerFromConfig($providerConfig, $model);
        $chat = $provider instanceof AiChatProviderInterface ? $provider : new NullAiProvider();

        if ($testKind === 'connection_provider') {
            $connection = $this->testProvider($chat, (string) ($model['key'] ?? 'null_text_model'));
            $connection['test_kind'] = 'connection_provider';
            $connection['prompt'] = '';
            $connection['system_message'] = null;
            $connection['user_message'] = null;
            $connection['usage_key'] = $usageKey;
            $connection['usage'] = $connection['usage'] ?? ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0];
            $connection['estimated_cost'] = 0.0;
            $connection['currency'] = (string) ($model['currency'] ?? 'CHF');
            $connection['diagnostic_badges'] = $this->diagnosticBadges($model, $connection);
            return $this->withUsageMetadata($connection, $siteId, $usageKey, '', $usageType, $model);
        }

        $promptPayload = $this->buildPromptPayload($siteId, $usageKey, $actionKey, $locale, $prompt, $variables);
        // Un test "Prompt" doit vérifier le modèle réellement configuré avec le texte saisi,
        // même lorsqu'aucun template ai_prompts actif n'existe encore pour l'action.
        // L'absence de template ne doit donc pas transformer une configuration valide en échec.

        if ($promptPayload['variables_missing'] !== []) {
            return $this->withUsageMetadata($this->usageTestError(
                $siteId,
                $usageKey,
                (string) $promptPayload['user_message'],
                'bad_request',
                'Le template IA contient des variables obligatoires non fournies : ' . implode(', ', $promptPayload['variables_missing']) . '.',
                $started,
                $promptPayload,
            ), $siteId, $usageKey, (string) $promptPayload['user_message'], $usageType, $model);
        }

        $budgetCheck = $this->budgetCheck($siteId, $usageKey, $model, $promptPayload, 120);
        if (empty($budgetCheck['allowed'])) {
            return $this->withUsageMetadata($this->usageTestError(
                $siteId,
                $usageKey,
                (string) $promptPayload['user_message'],
                'budget_exceeded',
                (string) ($budgetCheck['human_message'] ?? 'Budget IA mensuel dépassé.'),
                $started,
                $promptPayload + ['budget' => $budgetCheck, 'estimated_cost' => (float) ($budgetCheck['estimated_cost'] ?? 0)],
            ), $siteId, $usageKey, (string) $promptPayload['user_message'], $usageType, $model);
        }

        $response = $this->runUsagePrompt($chat, (string) ($model['key'] ?? 'null_text_model'), $promptPayload, $started, $testKind);
        $response = $this->enrichCost($model, $response, (int) ($budgetCheck['estimated_input_tokens'] ?? 0));
        $response['budget'] = $budgetCheck;

        return $this->withUsageMetadata($response + $promptPayload, $siteId, $usageKey, (string) $promptPayload['user_message'], $usageType, $model);
    }

    /** @param array<string,mixed> $variables @return array<string,mixed> */
    public function generateForSiteUsage(int $siteId, string $usageKey = 'editorial', string $prompt = '', string $actionKey = '', ?string $locale = null, array $variables = []): array
    {
        $started = microtime(true);
        $usageKey = $this->normalizeUsageKey($usageKey);
        $actionKey = $this->normalizeActionKey($actionKey) ?: $this->defaultActionForUsage($usageKey);
        $model = $this->settings->modelForSiteUsage($siteId, $usageKey);
        if (!$model) {
            return [
                'ok' => false,
                'provider' => 'null_provider',
                'model' => 'null_text_model',
                'content' => '',
                'error_type' => 'bad_request',
                'error_message' => "Aucun modèle actif n’est configuré pour l’usage {$usageKey} du site courant.",
                'status_code' => 0,
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0],
                'external_calls' => false,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'usage_key' => $usageKey,
                'action_key' => $actionKey,
            ];
        }

        $provider = $this->providerFromConfig([
            'key' => $model['provider_key'] ?? 'null_provider',
            'name' => $model['provider_name'] ?? $model['provider_key'] ?? 'Provider IA',
            'provider_type' => $model['provider_type'] ?? 'null',
            'base_url' => $model['base_url'] ?? null,
            'api_key_ref' => $model['effective_api_key_ref'] ?? $model['api_key_ref'] ?? null,
        ], $model);
        $chat = $provider instanceof AiChatProviderInterface ? $provider : new NullAiProvider();
        $promptPayload = $this->buildPromptPayload($siteId, $usageKey, $actionKey, $locale, $this->testPrompt($prompt), $variables);
        if ($promptPayload['variables_missing'] !== []) {
            return $promptPayload + [
                'ok' => false,
                'provider' => (string) ($model['provider_key'] ?? 'null_provider'),
                'model' => (string) ($model['key'] ?? 'null_text_model'),
                'content' => '',
                'error_type' => 'bad_request',
                'error_message' => 'Le template IA contient des variables obligatoires non fournies : ' . implode(', ', $promptPayload['variables_missing']) . '.',
                'status_code' => 0,
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0],
                'external_calls' => false,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'currency' => (string) ($model['currency'] ?? 'CHF'),
            ];
        }
        $budgetCheck = $this->budgetCheck($siteId, $usageKey, $model, $promptPayload, 120);
        if (empty($budgetCheck['allowed'])) {
            return $promptPayload + [
                'ok' => false,
                'provider' => (string) ($model['provider_key'] ?? 'null_provider'),
                'model' => (string) ($model['key'] ?? 'null_text_model'),
                'content' => '',
                'error_type' => 'budget_exceeded',
                'error_message' => (string) ($budgetCheck['human_message'] ?? 'Budget IA mensuel dépassé.'),
                'human_message' => (string) ($budgetCheck['human_message'] ?? 'Budget IA mensuel dépassé.'),
                'suggested_fix' => (string) ($budgetCheck['suggested_fix'] ?? 'Augmentez le budget du modèle ou attendez le mois suivant.'),
                'status_code' => 0,
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0],
                'external_calls' => false,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'usage_key' => $usageKey,
                'currency' => (string) ($model['currency'] ?? 'CHF'),
                'estimated_cost' => (float) ($budgetCheck['estimated_cost'] ?? 0),
                'budget' => $budgetCheck,
            ];
        }

        $response = $this->runUsagePrompt($chat, (string) ($model['key'] ?? 'null_text_model'), $promptPayload, $started);
        $response = $this->enrichCost($model, $response, (int) ($budgetCheck['estimated_input_tokens'] ?? 0));
        $response['usage_key'] = $usageKey;
        $response['currency'] = (string) ($model['currency'] ?? 'CHF');
        $response['budget'] = $budgetCheck;
        return $response + $promptPayload;
    }


    /**
     * Stream interactif réservé aux usages ponctuels (pas aux tâches batch ai_tasks).
     *
     * @param array<string,mixed> $variables
     * @param callable(string,array<string,mixed>):void $onEvent
     * @return array<string,mixed>
     */
    public function streamForSiteUsage(int $siteId, string $usageKey = 'editorial', string $prompt = '', string $actionKey = '', ?string $locale = null, array $variables = [], callable $onEvent = null): array
    {
        $started = microtime(true);
        $usageKey = $this->normalizeUsageKey($usageKey);
        $actionKey = $this->normalizeActionKey($actionKey) ?: $this->defaultActionForUsage($usageKey);
        $usageType = $this->settings->usageTypeForKey($usageKey);
        $prompt = $this->testPrompt($prompt);
        $emit = $onEvent ?? static function (string $event, array $payload): void {};

        if (!$usageType || empty($usageType['enabled'])) {
            $response = $this->usageTestError($siteId, $usageKey, $prompt, 'bad_request', "L’usage IA {$usageKey} n’existe pas ou n’est pas actif dans le catalogue.", $started, ['action_key' => $actionKey]);
            $emit('error', $this->safeStreamPayload($response));
            return $response;
        }

        $model = $this->settings->modelForSiteUsage($siteId, $usageKey);
        if (!$model) {
            $response = $this->usageTestError($siteId, $usageKey, $prompt, 'bad_request', "Aucun modèle actif n’est configuré pour l’usage {$usageKey} du site courant.", $started, ['usage_label' => (string) ($usageType['name'] ?? $usageKey), 'action_key' => $actionKey]);
            $emit('error', $this->safeStreamPayload($response));
            return $response;
        }

        $provider = $this->providerFromConfig([
            'key' => $model['provider_key'] ?? 'null_provider',
            'name' => $model['provider_name'] ?? $model['provider_key'] ?? 'Provider IA',
            'provider_type' => $model['provider_type'] ?? 'null',
            'base_url' => $model['base_url'] ?? null,
            'api_key_ref' => $model['effective_api_key_ref'] ?? $model['api_key_ref'] ?? null,
        ], $model);

        $promptPayload = $this->buildPromptPayload($siteId, $usageKey, $actionKey, $locale, $prompt, $variables);
        if ($promptPayload['variables_missing'] !== []) {
            $response = $this->withUsageMetadata($this->usageTestError(
                $siteId,
                $usageKey,
                (string) $promptPayload['user_message'],
                'bad_request',
                'Le template IA contient des variables obligatoires non fournies : ' . implode(', ', $promptPayload['variables_missing']) . '.',
                $started,
                $promptPayload,
            ), $siteId, $usageKey, (string) $promptPayload['user_message'], $usageType, $model);
            $emit('error', $this->safeStreamPayload($response));
            return $response;
        }

        $budgetCheck = $this->budgetCheck($siteId, $usageKey, $model, $promptPayload, 120);
        if (empty($budgetCheck['allowed'])) {
            $response = $this->withUsageMetadata($this->usageTestError(
                $siteId,
                $usageKey,
                (string) $promptPayload['user_message'],
                'budget_exceeded',
                (string) ($budgetCheck['human_message'] ?? 'Budget IA mensuel dépassé.'),
                $started,
                $promptPayload + ['budget' => $budgetCheck, 'estimated_cost' => (float) ($budgetCheck['estimated_cost'] ?? 0)],
            ), $siteId, $usageKey, (string) $promptPayload['user_message'], $usageType, $model);
            $emit('error', $this->safeStreamPayload($response));
            return $response;
        }

        if (!$provider instanceof AiStreamingChatProviderInterface || !$provider->supportsStreaming()) {
            $response = $this->withUsageMetadata($this->usageTestError(
                $siteId,
                $usageKey,
                (string) $promptPayload['user_message'],
                'provider_unavailable',
                'Le provider ou le modèle configuré ne supporte pas encore le streaming SSE. Utilisez le test non-streaming.',
                $started,
                $promptPayload + ['streaming_available' => false, 'fallback_available' => true],
            ), $siteId, $usageKey, (string) $promptPayload['user_message'], $usageType, $model);
            $emit('error', $this->safeStreamPayload($response));
            return $response;
        }

        $systemMessage = trim((string) ($promptPayload['system_message'] ?? '')) ?: 'Vous êtes le test de configuration du module AI Assistant. Répondez brièvement, en français, sans markdown.';
        $userMessage = trim((string) ($promptPayload['user_message'] ?? '')) ?: 'Répondez uniquement par : OK CMS';
        $metadata = $this->withUsageMetadata($promptPayload + [
            'ok' => true,
            'provider' => (string) ($model['provider_key'] ?? 'null_provider'),
            'model' => (string) ($model['key'] ?? 'null_text_model'),
            'content' => '',
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0],
            'external_calls' => true,
            'streaming_available' => true,
        ], $siteId, $usageKey, $userMessage, $usageType, $model);
        $emit('start', $this->safeStreamPayload($metadata));

        $response = $provider->streamChat([
            ['role' => 'system', 'content' => $systemMessage],
            ['role' => 'user', 'content' => $userMessage],
        ], [
            'model' => (string) ($model['key'] ?? 'null_text_model'),
            'temperature' => 0.1,
            'max_tokens' => 120,
            'timeout_seconds' => 60,
        ], static function (string $event, array $payload) use ($emit): void {
            $emit($event, $payload);
        });

        $response = $this->enrichCost($model, $response, (int) ($budgetCheck['estimated_input_tokens'] ?? 0));
        $response['duration_ms'] = (int) ($response['duration_ms'] ?? round((microtime(true) - $started) * 1000));
        $response['budget'] = $budgetCheck;
        $response['streamed'] = true;
        return $this->withUsageMetadata($response + $promptPayload, $siteId, $usageKey, $userMessage, $usageType, $model);
    }

    /** @param array<string,mixed>|null $provider @param array<string,mixed>|null $model */
    private function providerFromConfig(?array $provider, ?array $model): AiProviderInterface
    {
        if (!$provider || (($provider['provider_type'] ?? 'null') === 'null')) {
            return new NullAiProvider();
        }

        if (in_array(($provider['provider_type'] ?? ''), ['openai_compatible', 'infomaniak', 'custom_http'], true)) {
            return new OpenAiCompatibleProvider(
                (string) ($provider['key'] ?? 'openai_compatible'),
                (string) ($provider['name'] ?? 'Provider OpenAI compatible'),
                isset($provider['base_url']) ? (string) $provider['base_url'] : null,
                isset($provider['api_key_ref']) ? (string) $provider['api_key_ref'] : null,
                (string) (($model['key'] ?? '') ?: 'null_text_model'),
            );
        }

        return new NullAiProvider();
    }

    /** @return array<string,mixed> */
    private function testProvider(AiChatProviderInterface $provider, string $modelKey): array
    {
        $started = microtime(true);
        if (method_exists($provider, 'testConnection')) {
            /** @var array<string,mixed> $response */
            $response = $provider->testConnection();
        } else {
            $response = $provider->chat([
                ['role' => 'system', 'content' => 'Test de disponibilité du provider IA.'],
                ['role' => 'user', 'content' => 'Réponds par un message de test.'],
            ], ['model' => $modelKey]);
        }
        $response['duration_ms'] = (int) ($response['duration_ms'] ?? round((microtime(true) - $started) * 1000));
        return $response;
    }

    /** @param array<string,mixed> $promptPayload @return array<string,mixed> */
    private function runUsagePrompt(AiChatProviderInterface $provider, string $modelKey, array $promptPayload, float $started, string $testKind = 'usage_configured'): array
    {
        $systemMessage = trim((string) ($promptPayload['system_message'] ?? '')) ?: 'Vous êtes le test de configuration du module AI Assistant. Répondez brièvement, en français, sans markdown.';
        $userMessage = trim((string) ($promptPayload['user_message'] ?? '')) ?: 'Répondez uniquement par : OK CMS';
        $response = $provider->chat([
            ['role' => 'system', 'content' => $systemMessage],
            ['role' => 'user', 'content' => $userMessage],
        ], [
            'model' => $modelKey,
            'temperature' => 0.1,
            'max_tokens' => 120,
        ]);
        $response['duration_ms'] = (int) ($response['duration_ms'] ?? round((microtime(true) - $started) * 1000));
        $response['test_kind'] = $testKind === 'real_prompt' ? 'real_prompt' : 'usage_configured';
        return $response;
    }

    /** @param array<string,mixed> $variables @return array<string,mixed> */
    private function buildPromptPayload(int $siteId, string $usageKey, string $actionKey, ?string $locale, string $fallbackPrompt, array $variables): array
    {
        $variables = $this->defaultVariables($fallbackPrompt) + $variables;
        $prompt = $this->prompts?->activePrompt($actionKey, $usageKey, $locale, $siteId);
        if (!$prompt) {
            return [
                'action_key' => $actionKey,
                'prompt_key' => null,
                'prompt_name' => null,
                'prompt_source' => 'fallback',
                'system_message' => 'Vous êtes le test de configuration du module AI Assistant. Répondez brièvement, en français, sans markdown.',
                'user_message' => $fallbackPrompt,
                'variables_used' => [],
                'variables_missing' => [],
                'variables_unknown' => array_values(array_keys($variables)),
            ];
        }
        $rendered = $this->prompts?->renderPrompt($prompt, $variables) ?? [];
        return [
            'action_key' => $actionKey,
            'prompt_key' => $rendered['prompt_key'] ?? $prompt['key'] ?? null,
            'prompt_name' => $rendered['prompt_name'] ?? $prompt['name'] ?? null,
            'prompt_source' => 'ai_prompts',
            'system_message' => (string) ($rendered['system_message'] ?? ''),
            'user_message' => (string) ($rendered['user_message'] ?? $fallbackPrompt),
            'variables_used' => $rendered['variables_used'] ?? [],
            'variables_missing' => $rendered['variables_missing'] ?? [],
            'variables_unknown' => $rendered['variables_unknown'] ?? [],
        ];
    }

    /** @return array<string,string> */
    private function defaultVariables(string $fallbackPrompt): array
    {
        return [
            'input' => $fallbackPrompt,
            'text' => $fallbackPrompt,
            'source_text' => $fallbackPrompt,
            'title' => 'Titre de test',
            'locale' => 'fr',
            'target_locale' => 'de',
            'image_context' => 'Image de test du CMS',
            'site_name' => 'DEC CMS',
        ];
    }

    /** @param array<string,mixed> $response @param array<string,mixed> $usageType @param array<string,mixed> $model @return array<string,mixed> */
    private function withUsageMetadata(array $response, int $siteId, string $usageKey, string $prompt, array $usageType, array $model): array
    {
        $content = trim((string) ($response['content'] ?? ''));
        $metadata = [
            'site_id' => $siteId,
            'usage_key' => $usageKey,
            'usage_label' => (string) ($usageType['name'] ?? $usageKey),
            'provider_key' => (string) ($model['provider_key'] ?? $response['provider'] ?? 'null_provider'),
            'provider_label' => (string) ($model['provider_name'] ?? $model['provider_key'] ?? $response['provider'] ?? 'Provider IA'),
            'model_key' => (string) ($model['key'] ?? $response['model'] ?? 'null_text_model'),
            'model_label' => (string) ($model['name'] ?? $model['key'] ?? $response['model'] ?? 'Modèle IA'),
            'endpoint' => $this->endpointForModel($model, (string) ($response['test_kind'] ?? 'usage_configured')),
            'prompt' => $prompt,
            'response_preview' => mb_substr($content, 0, 240),
            'currency' => (string) ($model['currency'] ?? 'CHF'),
            'diagnostic_badges' => $this->diagnosticBadges($model, $response),
        ];
        return array_merge($response, $metadata);
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function usageTestError(int $siteId, string $usageKey, string $prompt, string $errorType, string $message, float $started, array $extra = []): array
    {
        return $extra + [
            'ok' => false,
            'site_id' => $siteId,
            'usage_key' => $usageKey,
            'provider' => 'null_provider',
            'provider_key' => 'null_provider',
            'provider_label' => 'Aucun provider',
            'model' => 'null_text_model',
            'model_key' => 'null_text_model',
            'model_label' => 'Aucun modèle',
            'prompt' => $prompt,
            'content' => '',
            'response_preview' => '',
            'error_type' => $errorType,
            'error_message' => $message,
            'status_code' => 0,
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0],
            'external_calls' => false,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'currency' => 'CHF',
        ];
    }


    /** @param array<string,mixed> $model @param array<string,mixed> $promptPayload @return array<string,mixed> */
    private function budgetCheck(int $siteId, string $usageKey, array $model, array $promptPayload, int $estimatedOutputTokens): array
    {
        $messages = [
            ['role' => 'system', 'content' => (string) ($promptPayload['system_message'] ?? '')],
            ['role' => 'user', 'content' => (string) ($promptPayload['user_message'] ?? '')],
        ];
        $inputTokens = $this->budgets?->estimateInputTokens($messages) ?? $this->fallbackTokenEstimate(implode(' ', array_column($messages, 'content')));
        $check = $this->budgets?->checkBeforeCall($siteId, $usageKey, $model, $inputTokens, $estimatedOutputTokens) ?? ['allowed' => true, 'status' => 'not_configured', 'estimated_cost' => 0.0];
        $check['estimated_input_tokens'] = $inputTokens;
        $check['estimated_output_tokens'] = $estimatedOutputTokens;
        return $check;
    }

    /** @param array<string,mixed> $model @param array<string,mixed> $response @return array<string,mixed> */
    private function enrichCost(array $model, array $response, int $fallbackInputTokens): array
    {
        if ($this->budgets) {
            return $this->budgets->enrichResponseCost($model, $response, $fallbackInputTokens);
        }
        $response['estimated_cost'] = 0.0;
        $response['currency'] = (string) ($model['currency'] ?? 'CHF');
        return $response;
    }

    private function fallbackTokenEstimate(string $text): int
    {
        $text = trim($text);
        return $text === '' ? 0 : max(1, (int) ceil(mb_strlen($text) / 4));
    }


    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function safeStreamPayload(array $payload): array
    {
        unset($payload['api_key_ref'], $payload['api_key_env'], $payload['api_key'], $payload['headers'], $payload['body'], $payload['json']);
        if (isset($payload['body_preview'])) {
            $payload['body_preview'] = mb_substr((string) $payload['body_preview'], 0, 300);
        }
        return $payload;
    }


    /** @return list<array{key:string,label:string,tone:string}> */
    private function diagnosticBadges(array $model, array $response): array
    {
        $badges = [];
        $badges[] = !empty($model['enabled']) && !empty($model['provider_enabled'])
            ? ['key' => 'active', 'label' => 'actif', 'tone' => 'success']
            : ['key' => 'disabled', 'label' => 'désactivé', 'tone' => 'muted'];
        if (empty($model['api_key_ref']) && empty($model['effective_api_key_ref'])) {
            $badges[] = ['key' => 'missing_key', 'label' => 'clé manquante', 'tone' => 'warning'];
        }
        if ((string) ($response['error_type'] ?? '') === 'budget_exceeded') {
            $badges[] = ['key' => 'budget_exceeded', 'label' => 'budget dépassé', 'tone' => 'danger'];
        }
        if (!empty($response['error_type'])) {
            $badges[] = ['key' => 'error', 'label' => 'erreur', 'tone' => 'danger'];
        }
        if (!empty($response['inherited'])) {
            $badges[] = ['key' => 'inherited', 'label' => 'hérité', 'tone' => 'info'];
        }
        if ((string) ($response['error_type'] ?? '') === 'provider_unavailable') {
            $badges[] = ['key' => 'model_unavailable', 'label' => 'modèle indisponible', 'tone' => 'danger'];
        }
        return $badges;
    }

    private function endpointForModel(array $model, string $testKind): string
    {
        $baseUrl = rtrim((string) ($model['base_url'] ?? 'https://api.openai.com/v1'), '/');
        if ($testKind === 'connection_provider') {
            return $baseUrl . '/models';
        }
        return $baseUrl . '/chat/completions';
    }

    private function normalizeTestKind(string $kind): string
    {
        $kind = strtolower(trim($kind));
        return match ($kind) {
            'connection', 'connection_provider', 'provider_connection' => 'connection_provider',
            'prompt', 'real_prompt', 'prompt_real' => 'real_prompt',
            default => 'usage_configured',
        };
    }

    private function testPrompt(string $prompt): string
    {
        $prompt = trim($prompt);
        return $prompt !== '' ? $prompt : 'Répondez uniquement par : OK CMS';
    }

    private function normalizeUsageKey(string $usageKey): string
    {
        $usageKey = strtolower(trim($usageKey));
        $usageKey = preg_replace('/[^a-z0-9_]+/', '_', $usageKey) ?: '';
        return trim($usageKey, '_') ?: 'editorial';
    }

    private function normalizeActionKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9_.-]+/', '_', $key) ?: '';
        return trim($key, '_.-');
    }

    private function defaultActionForUsage(string $usageKey): string
    {
        return match ($usageKey) {
            'seo' => 'seo.meta_description',
            'translation' => 'translation.draft',
            'image' => 'media.alt_text',
            default => 'editorial.rewrite',
        };
    }
}
