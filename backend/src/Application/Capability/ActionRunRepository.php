<?php

declare(strict_types=1);

namespace App\Application\Capability;

use App\Core\Database;

final class ActionRunRepository
{
    public function __construct(private readonly Database $db) {}

    /** @param array<string,mixed> $inputSummary @param array<string,mixed> $outputSummary */
    public function log(
        ?int $siteId,
        ?int $userId,
        string $moduleKey,
        string $actionKey,
        string $mode,
        string $status,
        string $riskLevel,
        array $inputSummary = [],
        array $outputSummary = [],
    ): void {
        $this->ensureSchema();
        $this->db->run(
            'INSERT INTO action_runs(site_id, user_id, module_key, action_key, mode, status, risk_level, input_summary_json, output_summary_json, created_at)
             VALUES(:site_id, :user_id, :module_key, :action_key, :mode, :status, :risk_level, :input_summary_json, :output_summary_json, :created_at)',
            [
                'site_id' => $siteId,
                'user_id' => $userId,
                'module_key' => self::limit($moduleKey, 80),
                'action_key' => self::limit($actionKey, 160),
                'mode' => in_array($mode, ['dry_run', 'apply'], true) ? $mode : 'dry_run',
                'status' => self::limit($status, 40),
                'risk_level' => in_array($riskLevel, ['low', 'medium', 'high'], true) ? $riskLevel : 'low',
                'input_summary_json' => self::jsonSummary($inputSummary),
                'output_summary_json' => self::jsonSummary($outputSummary),
                'created_at' => self::now(),
            ],
        );
    }

    public function ensureSchema(): void
    {
        if ($this->db->tableExists('action_runs')) {
            return;
        }
        $this->db->run("CREATE TABLE IF NOT EXISTS action_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site_id INTEGER,
            user_id INTEGER,
            module_key TEXT NOT NULL,
            action_key TEXT NOT NULL,
            mode TEXT NOT NULL CHECK(mode IN ('dry_run','apply')),
            status TEXT NOT NULL,
            risk_level TEXT NOT NULL DEFAULT 'low' CHECK(risk_level IN ('low','medium','high')),
            input_summary_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(input_summary_json)),
            output_summary_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(output_summary_json)),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CHECK(site_id IS NULL OR site_id > 0),
            CHECK(user_id IS NULL OR user_id > 0)
        )");
        $this->db->run('CREATE INDEX IF NOT EXISTS idx_action_runs_action_created ON action_runs(action_key, created_at)');
        $this->db->run('CREATE INDEX IF NOT EXISTS idx_action_runs_site_created ON action_runs(site_id, created_at)');
        $this->db->run('CREATE INDEX IF NOT EXISTS idx_action_runs_user_created ON action_runs(user_id, created_at)');
    }

    /** @param array<string,mixed> $data */
    private static function jsonSummary(array $data): string
    {
        $summary = self::summarize($data);
        return json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private static function summarize(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 3) {
            return '[max_depth]';
        }
        if (is_array($value)) {
            $out = [];
            $count = 0;
            foreach ($value as $key => $item) {
                if ($count++ >= 30) {
                    $out['_truncated'] = true;
                    break;
                }
                $safeKey = self::limit((string) $key, 80);
                if (preg_match('/token|secret|password|key|authorization|cookie/i', $safeKey)) {
                    $out[$safeKey] = '[redacted]';
                    continue;
                }
                $out[$safeKey] = self::summarize($item, $depth + 1);
            }
            return $out;
        }
        if (is_string($value)) {
            return self::limit($value, 500);
        }
        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }
        return '[unsupported]';
    }

    private static function limit(string $value, int $max): string
    {
        $value = str_replace("\0", '', $value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }

    private static function now(): string
    {
        return function_exists('now_utc') ? now_utc() : gmdate('Y-m-d H:i:s');
    }
}
