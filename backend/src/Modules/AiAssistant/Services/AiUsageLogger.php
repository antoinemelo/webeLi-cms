<?php

declare(strict_types=1);

namespace App\Modules\AiAssistant\Services;

use App\Core\Database;
use App\Modules\AiAssistant\Repositories\AiRepositoryBase;

final class AiUsageLogger extends AiRepositoryBase
{
    public function __construct(?Database $db) { parent::__construct($db); }

    /** @param array<string,mixed> $event */
    public function log(array $event): void
    {
        if (!$this->isAvailable()) {
            return;
        }
        $details = is_array($event['details'] ?? null) ? $event['details'] : [];
        $input = max(0, (int) ($event['input_tokens'] ?? 0));
        $output = max(0, (int) ($event['output_tokens'] ?? 0));

        // Les providers ne renvoient pas toujours les tokens exacts (notamment
        // certains streams SSE ou erreurs partielles). Dans ce cas, on garde un
        // suivi financier utile avec une estimation conservatrice à partir du
        // prompt et de la réponse journalisés.
        if ($input <= 0) {
            $input = $this->estimateInputTokensFromDetails($details);
        }
        if ($output <= 0) {
            $output = $this->estimateOutputTokensFromDetails($details);
        }

        $params = [
            'site_id' => $event['site_id'] ?? null,
            'user_id' => $event['user_id'] ?? null,
            'provider_key' => (string) ($event['provider_key'] ?? 'null_provider'),
            'model_key' => (string) ($event['model_key'] ?? 'null_text_model'),
            'task_type' => (string) ($event['task_type'] ?? 'provider.test'),
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => max($input + $output, (int) ($event['total_tokens'] ?? 0)),
            'duration_ms' => max(0, (int) ($event['duration_ms'] ?? 0)),
            'estimated_cost' => max(0, (float) ($event['estimated_cost'] ?? 0)),
            'currency' => strtoupper(substr((string) ($event['currency'] ?? 'CHF'), 0, 3)) ?: 'CHF',
        ];

        $columns = ['site_id', 'user_id', 'provider_key', 'model_key', 'task_type', 'input_tokens', 'output_tokens', 'total_tokens', 'duration_ms', 'estimated_cost', 'currency'];
        if ($this->hasColumn('status')) {
            $params['status'] = in_array((string) ($event['status'] ?? 'success'), ['success', 'failed', 'blocked'], true) ? (string) ($event['status'] ?? 'success') : 'failed';
            $columns[] = 'status';
        }
        if ($this->hasColumn('details_json')) {
            $params['details_json'] = $this->encodeDetails($details);
            $columns[] = 'details_json';
        }

        $columnsSql = implode(', ', $columns) . ', created_at';
        $valuesSql = ':' . implode(', :', $columns) . ', CURRENT_TIMESTAMP';
        $sql = "INSERT INTO ai_usage_events({$columnsSql}) VALUES({$valuesSql})";
        $this->pdo()->prepare($sql)->execute($params);
    }

    public function clear(?int $siteId = null): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }
        if ($siteId !== null && $siteId > 0) {
            $stmt = $this->pdo()->prepare('DELETE FROM ai_usage_events WHERE site_id = :site_id');
            $stmt->execute(['site_id' => $siteId]);
            return $stmt->rowCount();
        }
        $stmt = $this->pdo()->prepare('DELETE FROM ai_usage_events');
        $stmt->execute();
        return $stmt->rowCount();
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 25): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo()->prepare('SELECT * FROM ai_usage_events ORDER BY created_at DESC, id DESC LIMIT :limit');
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return array_map(function (array $row): array {
            $details = $this->decodeDetails((string) ($row['details_json'] ?? ''));
            $inputTokens = max(0, (int) ($row['input_tokens'] ?? 0));
            $outputTokens = max(0, (int) ($row['output_tokens'] ?? 0));
            if ($inputTokens <= 0) {
                $inputTokens = $this->estimateInputTokensFromDetails($details);
            }
            if ($outputTokens <= 0) {
                $outputTokens = $this->estimateOutputTokensFromDetails($details);
            }
            $totalTokens = max($inputTokens + $outputTokens, (int) ($row['total_tokens'] ?? 0));
            return [
                'id' => (int) $row['id'],
                'site_id' => isset($row['site_id']) ? (int) $row['site_id'] : null,
                'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
                'provider_key' => (string) $row['provider_key'],
                'model_key' => (string) $row['model_key'],
                'task_type' => (string) $row['task_type'],
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $totalTokens,
                'duration_ms' => (int) $row['duration_ms'],
                'estimated_cost' => (float) $row['estimated_cost'],
                'currency' => (string) $row['currency'],
                'status' => (string) ($row['status'] ?? 'success'),
                'details' => $details,
                'created_at' => (string) $row['created_at'],
            ];
        }, $stmt->fetchAll());
    }


    /** @param array<string,mixed> $details */
    private function estimateInputTokensFromDetails(array $details): int
    {
        $text = trim((string) ($details['system_message'] ?? '')) . "
" . trim((string) ($details['user_message'] ?? $details['prompt'] ?? ''));
        return $this->estimateTokens($text);
    }

    /** @param array<string,mixed> $details */
    private function estimateOutputTokensFromDetails(array $details): int
    {
        $text = (string) ($details['content'] ?? $details['response_preview'] ?? $details['body_preview'] ?? '');
        return $this->estimateTokens($text);
    }

    private function estimateTokens(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }
        return max(1, (int) ceil(mb_strlen($text) / 4));
    }

    private function hasColumn(string $name): bool
    {
        try {
            foreach ($this->pdo()->query('PRAGMA table_info(ai_usage_events)')->fetchAll() as $column) {
                if (($column['name'] ?? null) === $name) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }
        return false;
    }

    /** @param array<string,mixed> $details */
    private function encodeDetails(array $details): string
    {
        $safe = $this->sanitizeDetails($details);
        $json = json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '{}';
    }

    /** @return array<string,mixed> */
    private function decodeDetails(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $details @return array<string,mixed> */
    private function sanitizeDetails(array $details): array
    {
        foreach (['api_key', 'api_key_ref', 'api_key_env', 'authorization', 'headers', 'body'] as $key) {
            unset($details[$key]);
        }
        if (isset($details['json']) && is_array($details['json'])) {
            unset($details['json']['request'], $details['json']['headers']);
        }
        if (isset($details['body_preview'])) {
            $details['body_preview'] = mb_substr((string) $details['body_preview'], 0, 300);
        }
        if (isset($details['system_message'])) {
            $details['system_message'] = mb_substr((string) $details['system_message'], 0, 5000);
        }
        if (isset($details['user_message'])) {
            $details['user_message'] = mb_substr((string) $details['user_message'], 0, 5000);
        }
        if (isset($details['content'])) {
            $details['content'] = mb_substr((string) $details['content'], 0, 20000);
        }
        return $details;
    }
}
