<?php

declare(strict_types=1);

namespace App\Modules\AiAssistant\Services;

use App\Core\Database;
use App\Modules\AiAssistant\Repositories\AiRepositoryBase;

/**
 * File d'attente IA minimale basée sur SQLite.
 *
 * Statuts supportés : pending, running, completed, failed, cancelled.
 * Le service reste volontairement simple pour fonctionner sur hébergement mutualisé :
 * aucune dépendance Redis/queue externe, un claim atomique et un worker CLI manuel/cron.
 */
final class AiTaskService extends AiRepositoryBase
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_RUNNING, self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED];

    /** @var list<string> */
    public const SUPPORTED_TASK_TYPES = ['ai.test_usage', 'ai.generate_from_prompt'];

    public function __construct(?Database $db) { parent::__construct($db); }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function createTask(
        ?int $siteId,
        ?int $userId,
        string $taskType,
        array $payload = [],
        ?string $targetType = null,
        ?string $targetId = null,
        int $maxAttempts = 1,
    ): array {
        $this->ensureAvailable();
        $taskType = $this->normalizeTaskType($taskType);
        if (!in_array($taskType, self::SUPPORTED_TASK_TYPES, true)) {
            throw new \InvalidArgumentException('Type de tâche IA non supporté : ' . $taskType);
        }
        $payloadJson = $this->encodeJson($this->sanitizePayload($payload));
        $title = $this->buildTaskTitle($taskType, $payload);
        $this->pdo()->prepare(
            'INSERT INTO ai_tasks(site_id, user_id, title, task_type, status, input_hash, payload_json, target_type, target_id, max_attempts, created_at)
             VALUES(:site_id, :user_id, :title, :task_type, :status, :input_hash, :payload_json, :target_type, :target_id, :max_attempts, CURRENT_TIMESTAMP)'
        )->execute([
            'site_id' => $siteId !== null && $siteId > 0 ? $siteId : null,
            'user_id' => $userId !== null && $userId > 0 ? $userId : null,
            'title' => $title,
            'task_type' => $taskType,
            'status' => self::STATUS_PENDING,
            'input_hash' => hash('sha256', $taskType . '|' . $payloadJson),
            'payload_json' => $payloadJson,
            'target_type' => $targetType !== null ? $this->normalizeFreeKey($targetType) : null,
            'target_id' => $targetId,
            'max_attempts' => max(1, $maxAttempts),
        ]);
        return $this->getTask((int) $this->pdo()->lastInsertId()) ?? [];
    }

    /** @param list<string> $taskTypes @return array<string,mixed>|null */
    public function claimNextTask(array $taskTypes = []): ?array
    {
        $this->ensureAvailable();
        $types = array_values(array_filter(array_map(fn($v) => $this->normalizeTaskType((string) $v), $taskTypes)));
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $params = ['status' => self::STATUS_PENDING];
            $where = 'status = :status AND attempts < max_attempts';
            if ($types !== []) {
                $placeholders = [];
                foreach ($types as $i => $type) {
                    $key = 'type_' . $i;
                    $placeholders[] = ':' . $key;
                    $params[$key] = $type;
                }
                $where .= ' AND task_type IN (' . implode(',', $placeholders) . ')';
            }
            $stmt = $pdo->prepare('SELECT * FROM ai_tasks WHERE ' . $where . ' ORDER BY created_at ASC, id ASC LIMIT 1');
            $stmt->execute($params);
            $row = $stmt->fetch();
            if (!$row) {
                $pdo->commit();
                return null;
            }
            $updated = $pdo->prepare("UPDATE ai_tasks SET status = :running, attempts = attempts + 1, started_at = COALESCE(started_at, CURRENT_TIMESTAMP), error_type = NULL, error_message = NULL WHERE id = :id AND status = :pending");
            $updated->execute(['running' => self::STATUS_RUNNING, 'pending' => self::STATUS_PENDING, 'id' => (int) $row['id']]);
            if ($updated->rowCount() !== 1) {
                $pdo->commit();
                return null;
            }
            $pdo->commit();
            return $this->getTask((int) $row['id']);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<string,mixed>|null */
    public function getTask(int $id): ?array
    {
        $this->ensureAvailable();
        $stmt = $this->pdo()->prepare('SELECT * FROM ai_tasks WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return $this->hydrateTask($row);
    }


    /** @return array<string,mixed> */
    public function setRuntimeMetadata(int $id, ?string $providerKey, ?string $modelKey): array
    {
        $this->ensureAvailable();
        $this->pdo()->prepare('UPDATE ai_tasks SET provider_key = :provider_key, model_key = :model_key WHERE id = :id')
            ->execute([
                'provider_key' => $providerKey !== null ? $this->normalizeFreeKey($providerKey) : null,
                'model_key' => $modelKey !== null ? strtolower(trim($modelKey)) : null,
                'id' => $id,
            ]);
        return $this->getTask($id) ?? [];
    }

    /** @return array<string,mixed> */
    public function markRunning(int $id): array
    {
        $this->ensureAvailable();
        $this->pdo()->prepare('UPDATE ai_tasks SET status = :status, started_at = COALESCE(started_at, CURRENT_TIMESTAMP) WHERE id = :id')
            ->execute(['status' => self::STATUS_RUNNING, 'id' => $id]);
        return $this->getTask($id) ?? [];
    }

    /** @param array<string,mixed> $result @param list<mixed> $warnings @return array<string,mixed> */
    public function markCompleted(int $id, string $resultType, array $result = [], string $summary = '', array $warnings = []): array
    {
        $this->ensureAvailable();
        $resultType = $this->normalizeTaskType($resultType);
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO ai_task_results(task_id, result_type, result_json, summary, warnings_json, created_at) VALUES(:task_id, :result_type, :result_json, :summary, :warnings_json, CURRENT_TIMESTAMP)');
            $stmt->execute([
                'task_id' => $id,
                'result_type' => $resultType,
                'result_json' => $this->encodeJson($result),
                'summary' => mb_substr($summary, 0, 500),
                'warnings_json' => $this->encodeJson($warnings),
            ]);
            $resultId = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE ai_tasks SET status = :status, result_id = :result_id, error_type = NULL, error_message = NULL, finished_at = CURRENT_TIMESTAMP WHERE id = :id')
                ->execute(['status' => self::STATUS_COMPLETED, 'result_id' => $resultId, 'id' => $id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $this->getTask($id) ?? [];
    }

    /** @return array<string,mixed> */
    public function markFailed(int $id, string $errorMessage, string $errorType = 'unknown_error'): array
    {
        $this->ensureAvailable();
        $this->pdo()->prepare('UPDATE ai_tasks SET status = :status, error_type = :error_type, error_message = :error_message, finished_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute([
                'status' => self::STATUS_FAILED,
                'error_type' => $this->normalizeFreeKey($errorType) ?: 'unknown_error',
                'error_message' => mb_substr($errorMessage, 0, 1000),
                'id' => $id,
            ]);
        return $this->getTask($id) ?? [];
    }

    /** @return array<string,mixed> */
    public function cancelTask(int $id): array
    {
        $this->ensureAvailable();
        $task = $this->getTask($id);
        if (!$task) {
            throw new \RuntimeException('Tâche IA introuvable.');
        }
        if (($task['status'] ?? '') === self::STATUS_COMPLETED) {
            throw new \InvalidArgumentException('Une tâche IA terminée ne peut pas être annulée.');
        }
        $this->pdo()->prepare('UPDATE ai_tasks SET status = :status, finished_at = COALESCE(finished_at, CURRENT_TIMESTAMP) WHERE id = :id')
            ->execute(['status' => self::STATUS_CANCELLED, 'id' => $id]);
        return $this->getTask($id) ?? [];
    }

    /** @return array<string,mixed> */
    private function hydrateTask(array $row): array
    {
        $result = null;
        if (!empty($row['result_id'])) {
            $stmt = $this->pdo()->prepare('SELECT * FROM ai_task_results WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => (int) $row['result_id']]);
            $resultRow = $stmt->fetch();
            if ($resultRow) {
                $result = [
                    'id' => (int) $resultRow['id'],
                    'task_id' => (int) $resultRow['task_id'],
                    'result_type' => (string) $resultRow['result_type'],
                    'result' => $this->decodeJson((string) $resultRow['result_json'], []),
                    'summary' => (string) ($resultRow['summary'] ?? ''),
                    'warnings' => $this->decodeJson((string) $resultRow['warnings_json'], []),
                    'created_at' => (string) $resultRow['created_at'],
                ];
            }
        }
        return [
            'id' => (int) $row['id'],
            'site_id' => $row['site_id'] !== null ? (int) $row['site_id'] : null,
            'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'title' => (string) ($row['title'] ?? $row['task_type']),
            'task_type' => (string) $row['task_type'],
            'provider_key' => $row['provider_key'],
            'model_key' => $row['model_key'],
            'status' => (string) $row['status'],
            'input_hash' => (string) ($row['input_hash'] ?? ''),
            'payload' => $this->decodeJson((string) ($row['payload_json'] ?? '{}'), []),
            'target_type' => $row['target_type'],
            'target_id' => $row['target_id'],
            'result_id' => $row['result_id'] !== null ? (int) $row['result_id'] : null,
            'result' => $result,
            'error_type' => $row['error_type'] ?? null,
            'error_message' => $row['error_message'] ?? null,
            'attempts' => (int) ($row['attempts'] ?? 0),
            'max_attempts' => (int) ($row['max_attempts'] ?? 1),
            'created_at' => (string) $row['created_at'],
            'started_at' => $row['started_at'],
            'finished_at' => $row['finished_at'],
        ];
    }


    /** @param array<string,mixed> $payload */
    private function buildTaskTitle(string $taskType, array $payload): string
    {
        $usage = isset($payload['usage_key']) ? $this->normalizeFreeKey((string) $payload['usage_key']) : '';
        $action = isset($payload['action_key']) ? $this->normalizeTaskType((string) $payload['action_key']) : '';
        $label = $taskType;
        if ($usage !== '') {
            $label .= ' · ' . $usage;
        }
        if ($action !== '') {
            $label .= ' · ' . $action;
        }
        return mb_substr($label, 0, 180);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function sanitizePayload(array $payload): array
    {
        $blocked = ['api_key', 'apiKey', 'authorization', 'Authorization', 'token', 'secret', 'headers'];
        foreach ($blocked as $key) {
            unset($payload[$key]);
        }
        return $payload;
    }

    private function ensureAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Base IA indisponible.');
        }
    }

    private function normalizeTaskType(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_.-]+/', '_', $value) ?: '';
        return trim($value, '_.-');
    }

    private function normalizeFreeKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_.-]+/', '_', $value) ?: '';
        return trim($value, '_.-');
    }
}
