<?php

declare(strict_types=1);

namespace App\Modules\AiAssistant\Services;

use App\Core\Logger;
use App\Modules\AiAssistant\Diagnostics\AiErrorDiagnostics;

/** Worker synchrone simple pour la file ai_tasks. */
final class AiTaskWorker
{
    public function __construct(
        private readonly AiTaskService $tasks,
        private readonly AiProviderManager $providers,
        private readonly AiUsageLogger $usage,
        private readonly Logger $logger,
    ) {}

    /** @param list<string> $taskTypes */
    public function run(int $limit = 10, array $taskTypes = []): array
    {
        $limit = max(1, min(100, $limit));
        $processed = 0;
        $completed = 0;
        $failed = 0;
        $cancelled = 0;
        $results = [];

        for ($i = 0; $i < $limit; $i++) {
            $task = $this->tasks->claimNextTask($taskTypes);
            if (!$task) {
                break;
            }
            $processed++;
            try {
                $result = $this->processTask($task);
                $results[] = ['task_id' => $task['id'], 'status' => $result['status'] ?? 'completed'];
                if (($result['status'] ?? '') === AiTaskService::STATUS_COMPLETED) {
                    $completed++;
                } elseif (($result['status'] ?? '') === AiTaskService::STATUS_CANCELLED) {
                    $cancelled++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->tasks->markFailed((int) $task['id'], $e->getMessage(), 'worker_exception');
                $this->logger->error('ai_worker.task_exception', ['task_id' => (int) $task['id'], 'message' => $e->getMessage()]);
                $results[] = ['task_id' => $task['id'], 'status' => AiTaskService::STATUS_FAILED, 'error' => $e->getMessage()];
            }
        }

        return compact('processed', 'completed', 'failed', 'cancelled', 'results');
    }

    /** @param array<string,mixed> $task @return array<string,mixed> */
    private function processTask(array $task): array
    {
        if (($task['status'] ?? '') === AiTaskService::STATUS_CANCELLED) {
            return ['status' => AiTaskService::STATUS_CANCELLED];
        }
        $taskId = (int) $task['id'];
        $payload = is_array($task['payload'] ?? null) ? $task['payload'] : [];
        $siteId = max(1, (int) ($task['site_id'] ?? $payload['site_id'] ?? 1));
        $usageKey = $this->normalizeUsageKey((string) ($payload['usage_key'] ?? 'editorial'));
        $actionKey = $this->normalizeActionKey((string) ($payload['action_key'] ?? ''));
        $prompt = (string) ($payload['prompt'] ?? 'Répondez uniquement par : OK CMS');
        $locale = isset($payload['locale']) ? (string) $payload['locale'] : null;
        $variables = is_array($payload['variables'] ?? null) ? $payload['variables'] : [];

        $this->logger->info('ai_worker.task_started', ['task_id' => $taskId, 'task_type' => (string) $task['task_type'], 'site_id' => $siteId, 'usage_key' => $usageKey]);

        $result = match ((string) $task['task_type']) {
            'ai.test_usage' => $this->providers->testForSiteUsage($siteId, $usageKey, $prompt, $actionKey, $locale, $variables),
            'ai.generate_from_prompt' => $this->providers->generateForSiteUsage($siteId, $usageKey, $prompt, $actionKey, $locale, $variables),
            default => ['ok' => false, 'error_type' => 'bad_request', 'error_message' => 'Type de tâche IA non supporté par le worker.'],
        };
        $result = AiErrorDiagnostics::enrich($result);
        $this->tasks->setRuntimeMetadata($taskId, (string) ($result['provider_key'] ?? $result['provider'] ?? ''), (string) ($result['model_key'] ?? $result['model'] ?? ''));
        $this->logUsageEvent($task, $siteId, $usageKey, $result);

        $ok = !empty($result['ok']) || !empty($result['success']);
        if ($ok) {
            $summary = trim((string) ($result['response_preview'] ?? $result['content'] ?? 'Tâche IA terminée.'));
            $completed = $this->tasks->markCompleted($taskId, (string) $task['task_type'], $result, $summary);
            $this->logger->info('ai_worker.task_completed', ['task_id' => $taskId, 'duration_ms' => (int) ($result['duration_ms'] ?? 0)]);
            return ['status' => AiTaskService::STATUS_COMPLETED, 'task' => $completed];
        }

        $errorType = (string) ($result['error_type'] ?? 'unknown_error');
        $errorMessage = (string) ($result['human_message'] ?? $result['error_message'] ?? 'Tâche IA échouée.');
        $failed = $this->tasks->markFailed($taskId, $errorMessage, $errorType);
        $this->logger->warning('ai_worker.task_failed', ['task_id' => $taskId, 'error_type' => $errorType, 'message' => $errorMessage]);
        return ['status' => AiTaskService::STATUS_FAILED, 'task' => $failed];
    }

    /** @param array<string,mixed> $task @param array<string,mixed> $result */
    private function logUsageEvent(array $task, int $siteId, string $usageKey, array $result): void
    {
        $usage = is_array($result['usage'] ?? null) ? $result['usage'] : [];
        $this->usage->log([
            'site_id' => $siteId,
            'user_id' => (int) (($task['user_id'] ?? 0) ?: 0) ?: null,
            'provider_key' => (string) ($result['provider_key'] ?? $result['provider'] ?? 'null_provider'),
            'model_key' => (string) ($result['model_key'] ?? $result['model'] ?? 'null_text_model'),
            'task_type' => (string) ($task['task_type'] ?? 'ai.task') . '.' . $usageKey,
            'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
            'duration_ms' => (int) ($result['duration_ms'] ?? 0),
            'estimated_cost' => max(0, (float) ($result['estimated_cost'] ?? 0)),
            'currency' => (string) ($result['currency'] ?? 'CHF'),
            'status' => !empty($result['ok']) || !empty($result['success']) ? 'success' : (((string) ($result['error_type'] ?? '') === 'budget_exceeded') ? 'blocked' : 'failed'),
        ]);
    }

    private function normalizeUsageKey(string $usageKey): string
    {
        $usageKey = strtolower(trim($usageKey));
        $usageKey = preg_replace('/[^a-z0-9_]+/', '_', $usageKey) ?: '';
        return trim($usageKey, '_') ?: 'editorial';
    }

    private function normalizeActionKey(string $actionKey): string
    {
        $actionKey = strtolower(trim($actionKey));
        $actionKey = preg_replace('/[^a-z0-9_.-]+/', '_', $actionKey) ?: '';
        return trim($actionKey, '_.-');
    }
}
