<?php

declare(strict_types=1);

namespace App\Application\Consistency;

use App\Core\Database;

/**
 * Journal applicatif minimal des opérations qui dépassent une transaction SQLite.
 * La base core est autoritative pour l'état de l'orchestration; aucun secret ne doit
 * être placé dans metadata_json.
 */
final class CrossDatabaseOperationJournal
{
    public function __construct(private readonly Database $coreDb) {}

    /** @param array<string,mixed> $metadata */
    public function begin(string $operationKey, string $operationType, string $primaryStore, array $metadata = []): string
    {
        $this->ensureSchema();
        $correlationId = self::correlationId($operationKey, $operationType);
        $now = self::now();
        $this->coreDb->run(
            "INSERT INTO cross_database_operations(correlation_id, operation_key, operation_type, primary_store, status, step, metadata_json, attempts, created_at, updated_at)
             VALUES(:correlation_id,:operation_key,:operation_type,:primary_store,'started','preconditions',:metadata_json,1,:created_at,:updated_at)
             ON CONFLICT(correlation_id) DO UPDATE SET attempts = attempts + 1, updated_at = excluded.updated_at",
            [
                'correlation_id' => $correlationId,
                'operation_key' => self::limit($operationKey, 180),
                'operation_type' => self::limit($operationType, 80),
                'primary_store' => self::limit($primaryStore, 80),
                'metadata_json' => self::safeJson($metadata),
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
        return $correlationId;
    }

    /** @param array<string,mixed> $metadata */
    public function step(string $correlationId, string $step, string $status = 'running', array $metadata = []): void
    {
        $this->ensureSchema();
        $this->coreDb->run(
            'UPDATE cross_database_operations SET step=:step, status=:status, metadata_json=:metadata_json, last_error=NULL, updated_at=:updated_at WHERE correlation_id=:correlation_id',
            [
                'step' => self::limit($step, 100),
                'status' => in_array($status, ['started','running','succeeded','failed','repair_required','compensated'], true) ? $status : 'running',
                'metadata_json' => self::safeJson($metadata),
                'updated_at' => self::now(),
                'correlation_id' => $correlationId,
            ]
        );
    }

    /** @param array<string,mixed> $metadata */
    public function succeed(string $correlationId, array $metadata = []): void
    {
        $this->step($correlationId, 'complete', 'succeeded', $metadata);
    }

    public function fail(string $correlationId, string $step, \Throwable $error): void
    {
        $this->ensureSchema();
        $this->coreDb->run(
            "UPDATE cross_database_operations SET step=:step, status='repair_required', last_error=:last_error, updated_at=:updated_at WHERE correlation_id=:correlation_id",
            [
                'step' => self::limit($step, 100),
                'last_error' => self::limit($error->getMessage(), 1000),
                'updated_at' => self::now(),
                'correlation_id' => $correlationId,
            ]
        );
    }

    public function ensureSchema(): void
    {
        if ($this->coreDb->tableExists('cross_database_operations')) {
            return;
        }
        $this->coreDb->pdo()->exec("CREATE TABLE IF NOT EXISTS cross_database_operations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            correlation_id TEXT NOT NULL UNIQUE,
            operation_key TEXT NOT NULL,
            operation_type TEXT NOT NULL,
            primary_store TEXT NOT NULL,
            status TEXT NOT NULL CHECK(status IN ('started','running','succeeded','failed','repair_required','compensated')),
            step TEXT NOT NULL,
            metadata_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(metadata_json)),
            attempts INTEGER NOT NULL DEFAULT 1 CHECK(attempts > 0),
            last_error TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX IF NOT EXISTS idx_cross_database_operations_status_updated ON cross_database_operations(status, updated_at);
        CREATE INDEX IF NOT EXISTS idx_cross_database_operations_key_updated ON cross_database_operations(operation_key, updated_at);");
    }

    private static function correlationId(string $operationKey, string $operationType): string
    {
        return hash('sha256', trim($operationType) . '|' . trim($operationKey));
    }

    /** @param array<string,mixed> $metadata */
    private static function safeJson(array $metadata): string
    {
        $safe = [];
        foreach ($metadata as $key => $value) {
            $name = self::limit((string) $key, 80);
            $safe[$name] = preg_match('/secret|password|token|authorization|cookie|payload/i', $name)
                ? '[redacted]'
                : (is_scalar($value) || $value === null ? $value : '[structured]');
        }
        return json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private static function limit(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }

    private static function now(): string
    {
        return function_exists('now_utc') ? now_utc() : gmdate('Y-m-d H:i:s');
    }
}
