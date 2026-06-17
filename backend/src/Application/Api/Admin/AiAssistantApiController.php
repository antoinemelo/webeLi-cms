<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Modules\AiAssistant\Diagnostics\AiErrorDiagnostics;
use App\Modules\AiAssistant\Services\AiActionRegistry;
use App\Modules\AiAssistant\Services\AiBudgetService;
use App\Modules\AiAssistant\Services\AiModuleStatusService;
use App\Modules\AiAssistant\Services\AiPromptService;
use App\Modules\AiAssistant\Services\AiProviderManager;
use App\Modules\AiAssistant\Services\AiSettingsService;
use App\Modules\AiAssistant\Services\AiSuggestionService;
use App\Modules\AiAssistant\Services\AiTaskService;
use App\Modules\AiAssistant\Services\AiUsageLogger;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;

final class AiAssistantApiController
{
    private const CONTRACT = 'admin.ai_assistant.v1';

    public function __construct(
        private readonly Request $request,
        private readonly SiteRepository $sites,
        private readonly AuthRepository $auth,
        private readonly Authorization $authorization,
        private readonly Database $coreDb,
        private readonly AiModuleStatusService $status,
        private readonly AiSettingsService $settings,
        private readonly AiProviderManager $providers,
        private readonly AiPromptService $prompts,
        private readonly AiSuggestionService $suggestions,
        private readonly AiTaskService $tasks,
        private readonly AiUsageLogger $usage,
        private readonly AiBudgetService $budgets,
        private readonly AiActionRegistry $actions,
    ) {}

    public function status(): Response
    {
        [$site, $languageCode] = $this->authorize('ai.use');
        return $this->ok(['status' => $this->status->status((int) $site['id']), 'permissions' => $this->permissions($site)], $site, $languageCode);
    }

    public function settings(): Response
    {
        [$site, $languageCode] = $this->authorize('ai.use');
        return $this->ok([
            'settings' => $this->settings->allSettings(),
            'blueprints' => $this->aiBlueprints(),
            'provider_types' => $this->settings->providerTypes(),
            'providers' => $this->settings->providers(),
            'models' => $this->settings->models(),
            'site_usage_settings' => $this->settings->siteUsageSettings((int) $site['id']),
            'site_settings' => $this->settings->siteUsageSettings((int) $site['id']),
            'current_site_setting' => $this->settings->siteSettingFor((int) $site['id']),
        ], $site, $languageCode);
    }

    /** @return list<array<string,mixed>> */
    private function aiBlueprints(): array
    {
        // Source unique des libellés/aides : les blueprints persistés.
        // Le provider natif ne sert qu'au bootstrap lorsque les tables blueprints
        // n'existent pas encore (installation incomplète ou outil de diagnostic).
        if (!$this->coreDb->tableExists('blueprints') || !$this->coreDb->tableExists('blueprint_fields')) {
            return (new \App\Modules\AiAssistant\AiAssistantModuleProvider())->blueprints();
        }

        $rows = $this->coreDb->all(
            "SELECT b.id, b.blueprint_key, b.resource_type, b.label, b.description,
                    COALESCE(mb.resource, replace(b.blueprint_key, 'ai_assistant_', '')) AS resource
             FROM blueprints b
             LEFT JOIN module_blueprints mb
                    ON mb.blueprint_key = b.blueprint_key
                   AND mb.module_key = 'ai-assistant'
             WHERE b.resource_type = 'module_resource'
               AND b.blueprint_key LIKE 'ai_assistant_%'
             ORDER BY b.blueprint_key"
        );
        $byKey = [];

        foreach ($rows as $row) {
            $key = (string) $row['blueprint_key'];
            $fields = $this->coreDb->all(
                'SELECT field_handle, field_type, label, help_text, field_purpose, is_required, is_system, sort_order, config_json, validation_json, options_json
                 FROM blueprint_fields
                 WHERE blueprint_id = :blueprint_id
                 ORDER BY sort_order, id',
                ['blueprint_id' => (int) $row['id']]
            );
            $byKey[$key] = array_merge($byKey[$key] ?? [], [
                'resource' => (string) ($row['resource'] ?? str_replace('ai_assistant_', '', $key)),
                'blueprint_key' => $key,
                'resource_type' => (string) $row['resource_type'],
                'label' => (string) $row['label'],
                'description' => (string) ($row['description'] ?? ''),
                'fields' => array_map(fn(array $field): array => [
                    'key' => (string) $field['field_handle'],
                    'field_key' => (string) $field['field_handle'],
                    'handle' => (string) $field['field_handle'],
                    'label' => (string) $field['label'],
                    'field_type' => (string) $field['field_type'],
                    'type' => (string) $field['field_type'],
                    'help_text' => (string) ($field['help_text'] ?? ''),
                    'field_purpose' => (string) ($field['field_purpose'] ?? 'configuration'),
                    'is_required' => (bool) ((int) ($field['is_required'] ?? 0)),
                    'required' => (bool) ((int) ($field['is_required'] ?? 0)),
                    'is_system' => (bool) ((int) ($field['is_system'] ?? 0)),
                    'config' => $this->decodeJson((string) ($field['config_json'] ?? '{}'), []),
                    'validation' => $this->decodeJson((string) ($field['validation_json'] ?? '{}'), []),
                    'options' => $this->decodeJson((string) ($field['options_json'] ?? '{}'), []),
                ], $fields),
                'source' => 'persisted_blueprints',
            ]);
        }

        return array_values($byKey);
    }

    /** @return array<string,mixed>|list<mixed> */
    private function decodeJson(string $json, array $default): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : $default;
    }

    public function updateSettings(): Response
    {
        [$site, $languageCode] = $this->authorize('ai.provider.manage');
        $payload = AdminApiContract::dataPayload($this->request, false);
        $settings = is_array($payload['settings'] ?? null) ? $payload['settings'] : $payload;
        $this->settings->updateSettings($settings);
        return $this->ok(['settings' => $this->settings->allSettings()], $site, $languageCode);
    }


    public function siteSettings(): Response
    {
        [$site, $languageCode] = $this->authorize('ai.use');
        $siteId = isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null;
        if ($siteId !== null && $siteId > 0 && $siteId !== (int) $site['id']) {
            $targetSite = $this->sites->findActiveSite($siteId);
            if (!$targetSite) {
                throw new \RuntimeException('Site introuvable pour le périmètre IA.');
            }
            $this->authorization->require('ai.use', $siteId);
        }
        return $this->ok([
            'site_usage_settings' => $this->settings->siteUsageSettings($siteId ?? (int) $site['id']),
            'site_settings' => $this->settings->siteUsageSettings($siteId ?? (int) $site['id']),
            'current_site_setting' => $this->settings->siteSettingFor((int) $site['id']),
        ], $site, $languageCode);
    }

    public function updateSiteSettings(): Response
    {
        [$site, $languageCode] = $this->authorize('ai.provider.manage');
        $payload = AdminApiContract::dataPayload($this->request, false);
        $scope = is_array($payload['site_setting'] ?? null) ? $payload['site_setting'] : $payload;
        $targetSiteId = (int) ($scope['site_id'] ?? 0);
        if ($targetSiteId <= 0) {
            throw new \InvalidArgumentException('Site obligatoire pour le périmètre IA.');
        }
        $targetSite = $this->sites->findActiveSite($targetSiteId);
        if (!$targetSite) {
            throw new \RuntimeException('Site introuvable pour le périmètre IA.');
        }
        $this->authorization->require('ai.provider.manage', $targetSiteId);
        $saved = $this->settings->saveSiteUsageSetting($scope);
        return $this->ok([
            'site_usage_setting' => $saved,
            'site_setting' => $saved,
            'site_usage_settings' => $this->settings->siteUsageSettings((int) $site['id']),
            'site_settings' => $this->settings->siteUsageSettings((int) $site['id']),
            'current_site_setting' => $this->settings->siteSettingFor((int) $site['id']),
        ], $site, $languageCode);
    }

    public function providers(): Response
    {
        [$site, $languageCode] = $this->authorize('ai.provider.manage');
        return $this->ok([
            'provider_types' => $this->settings->providerTypes(),
            'providers' => $this->settings->providers(),
            'models' => $this->settings->models(),
        ], $site, $languageCode);
    }

    public function saveProviderType(): Response
    {
        [$site, $languageCode] = $this->authorize('ai.provider.manage');
        $payload = AdminApiContract::dataPayload($this->request, false);
        $type = $this->settings->saveProviderType(is_array($payload['provider_type'] ?? null) ? $payload['provider_type'] : $payload);
        return $this->ok(['provider_type' => $type, 'provider_types' => $this->settings->providerTypes()], $site, $languageCode);
    }

    public function deleteProviderType(string $key): Response
    {
        [$site, $languageCode] = $this->authorize('ai.provider.manage');
        return $this->ok($this->settings->deleteProviderType($key), $site, $languageCode);
    }

    public function saveProvider(): Response
    {
        [$site, $languageCode] = $this->authorize('ai.provider.manage');
        $payload = AdminApiContract::dataPayload($this->request, false);
        $provider = $this->settings->saveProvider(is_array($payload['provider'] ?? null) ? $payload['provider'] : $payload);
        return $this->ok([
            'provider' => $provider,
            'provider_types' => $this->settings->providerTypes(),
            'providers' => $this->settings->providers(),
            'models' => $this->settings->models(),
        ], $site, $languageCode);
    }

    public function deleteProvider(string $key): Response
    {
        [$site, $languageCode] = $this->authorize('ai.provider.manage');
        return $this->ok($this->settings->deleteProvider($key), $site, $languageCode);
    }

    public function saveModel(): Response
    {
        [$site, $languageCode] = $this->authorize('ai.provider.manage');
        $payload = AdminApiContract::dataPayload($this->request, false);
        $model = $this->settings->saveModel(is_array($payload['model'] ?? null) ? $payload['model'] : $payload);
        return $this->ok([
            'model' => $model,
            'provider_types' => $this->settings->providerTypes(),
            'providers' => $this->settings->providers(),
            'models' => $this->settings->models(),
        ], $site, $languageCode);
    }

    public function deleteModel(string $providerKey, string $modelKey): Response
    {
        [$site, $languageCode] = $this->authorize('ai.provider.manage');
        return $this->ok($this->settings->deleteModel($providerKey, $modelKey), $site, $languageCode);
    }

    public function testProvider(): Response
    {
        [$site, $languageCode] = $this->authorize('ai.provider.manage');
        $payload = AdminApiContract::dataPayload($this->request, false);
        $usageKey = $this->normalizeUsageKey((string) ($payload['usage_key'] ?? $this->request->query['usage_key'] ?? ''));
        $prompt = trim((string) ($payload['prompt'] ?? ''));
        $actionKey = $this->normalizeActionKey((string) ($payload['action_key'] ?? $this->request->query['action_key'] ?? ''));
        $variables = is_array($payload['variables'] ?? null) ? $payload['variables'] : [];
        $testKind = $this->normalizeTestKind((string) ($payload['test_kind'] ?? $payload['test_type'] ?? $this->request->query['test_kind'] ?? 'usage_configured'));
        $result = $this->providers->testForSiteUsage((int) $site['id'], $usageKey, $prompt, $actionKey, $languageCode, $variables, $testKind);
        $result['site_label'] = (string) ($site['name'] ?? $site['label'] ?? $site['key'] ?? ('Site #' . (int) $site['id']));
        $result['test_kind'] = (string) ($result['test_kind'] ?? $testKind);
        $enriched = AiErrorDiagnostics::enrich($result);
        $usage = is_array($enriched['usage'] ?? null) ? $enriched['usage'] : [];
        $this->usage->log([
            'site_id' => (int) $site['id'],
            'user_id' => (int) (($this->auth->user()['id'] ?? 0) ?: 0) ?: null,
            'provider_key' => (string) ($enriched['provider_key'] ?? $enriched['provider'] ?? 'null_provider'),
            'model_key' => (string) ($enriched['model_key'] ?? $enriched['model'] ?? 'null_text_model'),
            'task_type' => 'provider.test.' . (string) ($enriched['usage_key'] ?? $usageKey),
            'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
            'duration_ms' => (int) ($enriched['duration_ms'] ?? 0),
            'estimated_cost' => max(0, (float) ($enriched['estimated_cost'] ?? 0)),
            'currency' => (string) ($enriched['currency'] ?? 'CHF'),
            'status' => !empty($enriched['ok']) || !empty($enriched['success']) ? 'success' : (((string) ($enriched['error_type'] ?? '') === 'budget_exceeded') ? 'blocked' : 'failed'),
            'details' => $enriched,
        ]);
        return $this->ok(['result' => $enriched], $site, $languageCode);
    }

    public function testEditorialGeneration(): Response
    {
        [$site, $languageCode] = $this->authorize('ai.provider.manage');
        $payload = AdminApiContract::dataPayload($this->request, false);
        $prompt = trim((string) ($payload['prompt'] ?? ''));
        $usageKey = $this->normalizeUsageKey((string) ($payload['usage_key'] ?? 'editorial'));
        $actionKey = $this->normalizeActionKey((string) ($payload['action_key'] ?? ''));
        $variables = is_array($payload['variables'] ?? null) ? $payload['variables'] : [];
        $result = $this->providers->generateForSiteUsage((int) $site['id'], $usageKey, $prompt, $actionKey, $languageCode, $variables);
        $result['site_label'] = (string) ($site['name'] ?? $site['label'] ?? $site['key'] ?? ('Site #' . (int) $site['id']));
        $result['test_kind'] = (string) ($result['test_kind'] ?? 'generation_test');
        $enriched = AiErrorDiagnostics::enrich($result);
        $usage = is_array($enriched['usage'] ?? null) ? $enriched['usage'] : [];
        $this->usage->log([
            'site_id' => (int) $site['id'],
            'user_id' => (int) (($this->auth->user()['id'] ?? 0) ?: 0) ?: null,
            'provider_key' => (string) ($enriched['provider_key'] ?? $enriched['provider'] ?? 'null_provider'),
            'model_key' => (string) ($enriched['model_key'] ?? $enriched['model'] ?? 'null_text_model'),
            'task_type' => 'generation.test.' . (string) ($enriched['usage_key'] ?? $usageKey),
            'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
            'duration_ms' => (int) ($enriched['duration_ms'] ?? 0),
            'estimated_cost' => max(0, (float) ($enriched['estimated_cost'] ?? 0)),
            'currency' => (string) ($enriched['currency'] ?? 'CHF'),
            'status' => !empty($enriched['ok']) || !empty($enriched['success']) ? 'success' : (((string) ($enriched['error_type'] ?? '') === 'budget_exceeded') ? 'blocked' : 'failed'),
            'details' => $enriched,
        ]);
        return $this->ok(['result' => $enriched], $site, $languageCode);
    }


    public function streamTestUsage(): Response
    {
        [$site, $languageCode] = $this->authorize('ai.provider.manage');
        $usageKey = $this->normalizeUsageKey((string) ($this->request->query['usage_key'] ?? ''));
        $prompt = trim((string) ($this->request->query['prompt'] ?? ''));
        $actionKey = $this->normalizeActionKey((string) ($this->request->query['action_key'] ?? ''));
        $variables = [
            'input' => $prompt,
            'text' => $prompt,
            'source_text' => $prompt,
            'locale' => $languageCode,
            'target_locale' => (string) ($this->request->query['target_locale'] ?? 'de'),
            'title' => (string) ($this->request->query['title'] ?? 'Titre de test'),
            'image_context' => $prompt !== '' ? $prompt : 'Image de test du CMS',
        ];

        $this->startSseResponse();
        $result = null;
        try {
            $result = $this->providers->streamForSiteUsage(
                (int) $site['id'],
                $usageKey,
                $prompt,
                $actionKey,
                $languageCode,
                $variables,
                function (string $event, array $payload): void {
                    $this->emitSse($event, $this->sanitizeSsePayload($payload));
                },
            );
            $enriched = AiErrorDiagnostics::enrich($result);
            $enriched['test_kind'] = 'stream_test';
            $enriched['streaming'] = true;
            $enriched['endpoint'] = '/admin/api/ai/providers/test/stream';
            $enriched['site_label'] = (string) ($site['name'] ?? $site['label'] ?? $site['key'] ?? ('Site #' . (int) $site['id']));
            $usage = is_array($enriched['usage'] ?? null) ? $enriched['usage'] : [];
            $this->usage->log([
                'site_id' => (int) $site['id'],
                'user_id' => (int) (($this->auth->user()['id'] ?? 0) ?: 0) ?: null,
                'provider_key' => (string) ($enriched['provider_key'] ?? $enriched['provider'] ?? 'null_provider'),
                'model_key' => (string) ($enriched['model_key'] ?? $enriched['model'] ?? 'null_text_model'),
                'task_type' => 'stream.test.' . (string) ($enriched['usage_key'] ?? $usageKey),
                'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
                'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
                'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
                'duration_ms' => (int) ($enriched['duration_ms'] ?? 0),
                'estimated_cost' => max(0, (float) ($enriched['estimated_cost'] ?? 0)),
                'currency' => (string) ($enriched['currency'] ?? 'CHF'),
                'status' => !empty($enriched['ok']) || !empty($enriched['success']) ? 'success' : (((string) ($enriched['error_type'] ?? '') === 'budget_exceeded') ? 'blocked' : 'failed'),
                'details' => $enriched,
            ]);
            if (!empty($enriched['ok']) || !empty($enriched['success'])) {
                $this->emitSse('done', $this->sanitizeSsePayload($enriched));
            } else {
                $this->emitSse('error', $this->sanitizeSsePayload($enriched));
            }
        } catch (\Throwable $e) {
            $enriched = [
                'success' => false,
                'ok' => false,
                'test_kind' => 'stream_test',
                'streaming' => true,
                'site_id' => (int) $site['id'],
                'site_label' => (string) ($site['name'] ?? $site['label'] ?? $site['key'] ?? ('Site #' . (int) $site['id'])),
                'usage_key' => $usageKey,
                'usage_label' => $usageKey !== '' ? ucfirst($usageKey) : 'Usage non défini',
                'provider_key' => 'null_provider',
                'provider_label' => 'Aucun provider',
                'model_key' => 'null_model',
                'model_label' => 'Aucun modèle',
                'endpoint' => '/admin/api/ai/providers/test/stream',
                'error_type' => 'unknown_error',
                'error_message' => 'Erreur interne pendant le streaming IA.',
                'human_message' => 'Le streaming IA a été interrompu par une erreur interne.',
                'suggested_fix' => 'Relancez le test en mode non-streaming et vérifiez les logs serveur.',
                'status_code' => 0,
                'duration_ms' => 0,
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0],
            ];
            $this->usage->log([
                'site_id' => (int) $site['id'],
                'user_id' => (int) (($this->auth->user()['id'] ?? 0) ?: 0) ?: null,
                'provider_key' => 'null_provider',
                'model_key' => 'null_model',
                'task_type' => 'stream.test.' . ($usageKey !== '' ? $usageKey : 'unknown'),
                'input_tokens' => 0,
                'output_tokens' => 0,
                'total_tokens' => 0,
                'duration_ms' => 0,
                'estimated_cost' => 0,
                'currency' => 'CHF',
                'status' => 'failed',
                'details' => $enriched,
            ]);
            $this->emitSse('error', $this->sanitizeSsePayload($enriched));
        }
        exit;
    }


    public function actions(): Response
    {
        [$site, $languageCode] = $this->authorize('ai.use');
        $contentType = isset($this->request->query['content_type']) ? (string) $this->request->query['content_type'] : 'content';
        $permissions = array_keys(array_filter($this->permissions($site)));
        return $this->ok([
            'actions' => $this->actions->availableFor((int) $site['id'], $contentType, $permissions),
            'result_policy' => 'Toute sortie d’action IA doit être enregistrée comme suggestion IA. Aucune action ne modifie directement le contenu publié.',
        ], $site, $languageCode);
    }

    public function prompts(): Response
    {
        [$site, $languageCode] = $this->authorize('ai.use');
        $includeTemplates = $this->auth->hasPermission('ai.provider.manage', (int) $site['id']);
        return $this->ok(['prompts' => $this->prompts->list($this->request->query['category'] ?? null, $includeTemplates)], $site, $languageCode);
    }

    public function suggestions(): Response
    {
        [$site, $languageCode] = $this->authorize('ai.suggestions.read');
        $status = isset($this->request->query['status']) ? (string) $this->request->query['status'] : null;
        return $this->ok([
            'suggestions' => $this->suggestions->list((int) ($this->request->query['limit'] ?? 25), $status),
            'statuses' => AiSuggestionService::STATUSES,
            'transitions' => [
                'draft' => ['proposed'],
                'proposed' => ['accepted', 'rejected', 'expired'],
                'accepted' => ['applied', 'expired'],
                'rejected' => [],
                'applied' => [],
                'expired' => [],
            ],
            'apply_policy' => 'Application directe en production désactivée : une suggestion acceptée doit être appliquée via une révision ou un brouillon, puis reliée à applied_action_run_id.',
        ], $site, $languageCode);
    }

    public function proposeSuggestion(): Response
    {
        [$site, $languageCode] = $this->authorize('ai.suggestions.manage');
        $payload = AdminApiContract::dataPayload($this->request, false);
        $payload['site_id'] = (int) ($payload['site_id'] ?? $site['id']);
        $payload['user_id'] = (int) (($this->auth->user()['id'] ?? 0) ?: 0) ?: null;
        $suggestion = $this->suggestions->propose($payload);
        return $this->ok(['suggestion' => $suggestion], $site, $languageCode);
    }

    public function proposeExistingSuggestion(string $id): Response
    {
        [$site, $languageCode] = $this->authorize('ai.suggestions.manage');
        $suggestion = $this->suggestions->proposeExisting((int) $id);
        return $this->ok(['suggestion' => $suggestion], $site, $languageCode);
    }

    public function acceptSuggestion(string $id): Response
    {
        [$site, $languageCode] = $this->authorize('ai.suggestions.manage');
        $suggestion = $this->suggestions->accept((int) $id);
        return $this->ok(['suggestion' => $suggestion], $site, $languageCode);
    }

    public function rejectSuggestion(string $id): Response
    {
        [$site, $languageCode] = $this->authorize('ai.suggestions.manage');
        $payload = AdminApiContract::dataPayload($this->request, false);
        $suggestion = $this->suggestions->reject((int) $id, (string) ($payload['reason'] ?? ''));
        return $this->ok(['suggestion' => $suggestion], $site, $languageCode);
    }

    public function applySuggestion(string $id): Response
    {
        [$site, $languageCode] = $this->authorize('ai.actions.apply');
        $payload = AdminApiContract::dataPayload($this->request, false);
        $runId = (int) ($payload['applied_action_run_id'] ?? $payload['action_run_id'] ?? 0);
        $suggestion = $this->suggestions->apply((int) $id, $runId, (string) ($payload['note'] ?? ''));
        return $this->ok([
            'suggestion' => $suggestion,
            'apply_policy' => 'La production directe n’est pas modifiée par cet endpoint : applied_action_run_id doit pointer vers une action de révision/brouillon déjà exécutée.',
        ], $site, $languageCode);
    }

    public function expireSuggestion(string $id): Response
    {
        [$site, $languageCode] = $this->authorize('ai.suggestions.manage');
        $payload = AdminApiContract::dataPayload($this->request, false);
        $suggestion = $this->suggestions->expire((int) $id, (string) ($payload['reason'] ?? ''));
        return $this->ok(['suggestion' => $suggestion], $site, $languageCode);
    }

    public function usage(): Response
    {
        [$site, $languageCode] = $this->authorize('ai.logs.read');
        return $this->ok(['usage' => $this->usage->recent((int) ($this->request->query['limit'] ?? 25))], $site, $languageCode);
    }

    public function budgetSummary(): Response
    {
        [$site, $languageCode] = $this->authorize('ai.logs.read');
        return $this->ok(['budget' => $this->budgets->summary((int) $site['id'])], $site, $languageCode);
    }



    public function createTask(): Response
    {
        [$site, $languageCode] = $this->authorize('ai.use');
        $payload = AdminApiContract::dataPayload($this->request, false);
        $taskType = $this->normalizeActionKey((string) ($payload['task_type'] ?? ''));
        if (!in_array($taskType, AiTaskService::SUPPORTED_TASK_TYPES, true)) {
            throw new \InvalidArgumentException('Type de tâche IA non supporté. Types disponibles : ai.test_usage, ai.generate_from_prompt.');
        }
        if ($taskType === 'ai.test_usage') {
            $this->authorization->require('ai.provider.manage', (int) $site['id']);
        }
        $taskPayload = is_array($payload['payload'] ?? null) ? $payload['payload'] : $payload;
        unset($taskPayload['task_type'], $taskPayload['target_type'], $taskPayload['target_id'], $taskPayload['max_attempts']);
        $task = $this->tasks->createTask(
            (int) $site['id'],
            (int) (($this->auth->user()['id'] ?? 0) ?: 0) ?: null,
            $taskType,
            $taskPayload,
            isset($payload['target_type']) ? (string) $payload['target_type'] : null,
            isset($payload['target_id']) ? (string) $payload['target_id'] : null,
            (int) ($payload['max_attempts'] ?? 1),
        );
        return $this->ok(['task' => $task], $site, $languageCode);
    }

    public function taskStatus(string $id): Response
    {
        [$site, $languageCode] = $this->authorize('ai.use');
        $task = $this->tasks->getTask((int) $id);
        if (!$task) {
            throw new \RuntimeException('Tâche IA introuvable.');
        }
        $taskSiteId = (int) ($task['site_id'] ?? 0);
        if ($taskSiteId > 0 && $taskSiteId !== (int) $site['id']) {
            $this->authorization->require('ai.use', $taskSiteId);
        }
        return $this->ok(['task' => $task], $site, $languageCode);
    }

    public function cancelTask(string $id): Response
    {
        [$site, $languageCode] = $this->authorize('ai.use');
        $task = $this->tasks->getTask((int) $id);
        if (!$task) {
            throw new \RuntimeException('Tâche IA introuvable.');
        }
        $taskSiteId = (int) ($task['site_id'] ?? 0);
        if ($taskSiteId > 0 && $taskSiteId !== (int) $site['id']) {
            $this->authorization->require('ai.use', $taskSiteId);
        }
        $cancelled = $this->tasks->cancelTask((int) $id);
        return $this->ok(['task' => $cancelled], $site, $languageCode);
    }

    public function clearUsage(): Response
    {
        [$site, $languageCode] = $this->authorize('ai.provider.manage');
        // Les événements ai_usage_events servent aussi de registre de dépenses.
        // Nettoyer l'affichage des tests ne doit jamais effacer l'historique financier.
        return $this->ok([
            'deleted' => 0,
            'usage' => $this->usage->recent(25),
            'message' => 'Historique des dépenses conservé. Réinitialisez uniquement l’affichage côté interface.',
        ], $site, $languageCode);
    }


    private function startSseResponse(): void
    {
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
        ignore_user_abort(true);
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        $this->emitSse('start', ['success' => true, 'message' => 'Connexion SSE ouverte.']);
    }

    /** @param array<string,mixed> $payload */
    private function emitSse(string $event, array $payload): void
    {
        if (connection_aborted()) {
            exit;
        }
        $event = preg_replace('/[^a-z0-9_.-]+/i', '', $event) ?: 'message';
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            $json = '{"success":false,"error_type":"invalid_json","error_message":"Événement SSE non sérialisable."}';
        }
        echo "event: {$event}\n";
        echo 'data: ' . $json . "\n\n";
        @ob_flush();
        flush();
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function sanitizeSsePayload(array $payload): array
    {
        unset($payload['api_key'], $payload['api_key_ref'], $payload['api_key_env'], $payload['headers'], $payload['body'], $payload['json']);
        if (isset($payload['body_preview'])) {
            $payload['body_preview'] = mb_substr((string) $payload['body_preview'], 0, 300);
        }
        return $payload;
    }

    /** @return array{0:array<string,mixed>,1:string} */
    private function authorize(string $permission): array
    {
        $this->auth->requireAuth();
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null, $this->auth);
        $this->authorization->require($permission, (int) $site['id']);
        return [$site, AdminApiContract::language($this->request, $this->sites, $site)];
    }

    /** @param array<string,mixed> $site @return array<string,bool> */
    private function permissions(array $site): array
    {
        return AdminApiContract::permissionsBlock($this->auth, (int) $site['id'], [
            'ai.use', 'ai.provider.manage', 'ai.suggestions.read', 'ai.suggestions.manage',
            'ai.content.suggest', 'ai.content.draft', 'ai.seo.suggest', 'ai.translation.suggest',
            'ai.actions.apply', 'ai.logs.read', 'ai.provider.manage',
        ]);
    }

    private function normalizeActionKey(string $actionKey): string
    {
        $actionKey = strtolower(trim($actionKey));
        $actionKey = preg_replace('/[^a-z0-9_.-]+/', '_', $actionKey) ?: '';
        return trim($actionKey, '_.-');
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

    private function normalizeUsageKey(string $usageKey): string
    {
        $usageKey = strtolower(trim($usageKey));
        $usageKey = preg_replace('/[^a-z0-9_]+/', '_', $usageKey) ?: '';
        return trim($usageKey, '_') ?: 'editorial';
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $site */
    private function ok(array $data, array $site, string $languageCode): Response
    {
        return Response::success($data, self::CONTRACT, AdminApiContract::meta($site, $languageCode));
    }
}
