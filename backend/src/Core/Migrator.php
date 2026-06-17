<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class Migrator
{
    public function __construct(private readonly PDO $pdo, private readonly string $directory) {}

    public function migrate(): array
    {
        $this->ensureMigrationLog();
        $done = $this->appliedMigrations();
        $applied = [];
        $files = glob(rtrim($this->directory, '/') . '/*.sql') ?: [];
        sort($files, SORT_NATURAL);

        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                continue;
            }

            if ($this->shouldMarkAsAlreadyApplied($name)) {
                $this->markApplied($name);
                $applied[] = $name . ' (baseline)';
                continue;
            }

            $sql = trim((string) file_get_contents($file));
            if ($sql === '') {
                continue;
            }

            $this->applySql($name, $sql);
            $this->markApplied($name);
            $applied[] = $name;
        }

        return $applied;
    }

    private function applySql(string $name, string $sql): void
    {
        $statements = $this->splitStatements($sql);

        foreach ($statements as $statement) {
            try {
                $this->pdo->exec($statement);
            } catch (\PDOException $e) {
                if ($this->canIgnoreStatementError($statement, $e)) {
                    continue;
                }

                if ($this->canTreatAsAlreadyApplied($name, $e)) {
                    return;
                }

                throw $e;
            }
        }
    }

    /**
     * @return list<string>
     */
    private function splitStatements(string $sql): array
    {
        $parts = preg_split('/;\s*(?:\r?\n|$)/', trim($sql)) ?: [];
        $statements = [];

        foreach ($parts as $part) {
            $statement = trim($part);
            if ($statement !== '') {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    private function canIgnoreStatementError(string $statement, \PDOException $e): bool
    {
        $message = strtolower($e->getMessage());
        if (!str_contains($message, 'duplicate column name')) {
            return false;
        }

        if (!preg_match('/alter\s+table\s+([a-zA-Z0-9_]+)\s+add\s+column\s+([a-zA-Z0-9_]+)/i', $statement, $matches)) {
            return false;
        }

        return $this->hasColumn($matches[1], $matches[2]);
    }

    private function canTreatAsAlreadyApplied(string $name, \PDOException $e): bool
    {
        $message = strtolower($e->getMessage());
        if (!str_contains($message, 'duplicate column name')) {
            return false;
        }

        if ($name === '0002_runtime_hardening.sql' && str_contains($this->directory, '/core')) {
            return $this->hasColumn('redirects', 'resource_type')
                && $this->hasColumn('redirects', 'resource_id')
                && $this->hasColumn('redirects', 'updated_at')
                && $this->hasColumn('tombstones', 'updated_at')
                && $this->hasColumn('outbox_events', 'claimed_at')
                && $this->hasColumn('outbox_events', 'last_error')
                && $this->hasColumn('outbox_events', 'available_at')
                && $this->hasColumn('system_jobs', 'last_heartbeat_at')
                && $this->hasColumn('system_jobs', 'locked_until');
        }

        return false;
    }

    private function hasColumn(string $table, string $column): bool
    {
        $stmt = $this->pdo->query(sprintf('PRAGMA table_info(%s)', $table));
        if (!$stmt) {
            return false;
        }

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (($row['name'] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }

    private function shouldMarkAsAlreadyApplied(string $name): bool
    {
        if ($name !== '0001_init.sql') {
            return false;
        }

        $markerTable = str_contains($this->directory, '/iam') ? 'iam_users' : 'languages';
        $stmt = $this->pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = ? LIMIT 1");
        $stmt->execute([$markerTable]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Le journal des migrations a existé sous deux formes pendant l'évolution
     * des outils locaux :
     *
     * - forme PHP historique : migration, migrated_at
     * - forme Python e04c : scope, migration_name, applied_at
     *
     * Le runtime accepte les deux afin qu'une base déjà créée localement ne
     * bloque pas l'installation avec "no such column: migration".
     */
    private function ensureMigrationLog(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration TEXT NOT NULL UNIQUE, migrated_at TEXT NOT NULL)');
        $columns = $this->migrationLogColumns();

        if (!in_array('migration', $columns, true)) {
            $this->pdo->exec('ALTER TABLE schema_migrations ADD COLUMN migration TEXT');
            $columns[] = 'migration';
        }
        if (!in_array('migrated_at', $columns, true)) {
            $this->pdo->exec('ALTER TABLE schema_migrations ADD COLUMN migrated_at TEXT');
            $columns[] = 'migrated_at';
        }

        if (in_array('migration_name', $columns, true)) {
            $this->pdo->exec("UPDATE schema_migrations SET migration = migration_name WHERE migration IS NULL OR migration = ''");
        }
        if (in_array('applied_at', $columns, true)) {
            $this->pdo->exec("UPDATE schema_migrations SET migrated_at = applied_at WHERE migrated_at IS NULL OR migrated_at = ''");
        }
        $stmt = $this->pdo->prepare("UPDATE schema_migrations SET migrated_at = ? WHERE migrated_at IS NULL OR migrated_at = ''");
        $stmt->execute([now_utc()]);
    }

    /** @return list<string> */
    private function migrationLogColumns(): array
    {
        $stmt = $this->pdo->query('PRAGMA table_info(schema_migrations)');
        if (!$stmt) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (array $row): ?string => isset($row['name']) ? (string) $row['name'] : null,
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        )));
    }

    /** @return list<string> */
    private function appliedMigrations(): array
    {
        $columns = $this->migrationLogColumns();
        $done = [];

        if (in_array('migration', $columns, true)) {
            foreach ($this->pdo->query("SELECT migration FROM schema_migrations WHERE migration IS NOT NULL AND migration != ''")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $done[] = (string) $row['migration'];
            }
        }

        if (in_array('scope', $columns, true) && in_array('migration_name', $columns, true)) {
            $stmt = $this->pdo->prepare("SELECT migration_name FROM schema_migrations WHERE scope = ? AND migration_name IS NOT NULL AND migration_name != ''");
            $stmt->execute([$this->migrationScope()]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $done[] = (string) $row['migration_name'];
            }
        }

        return array_values(array_unique($done));
    }

    private function migrationScope(): string
    {
        return str_contains($this->directory, '/iam') ? 'iam' : 'core';
    }

    private function markApplied(string $name): void
    {
        $columns = $this->migrationLogColumns();
        $now = now_utc();

        if (in_array('scope', $columns, true) && in_array('migration_name', $columns, true)) {
            $stmt = $this->pdo->prepare(
                'INSERT OR IGNORE INTO schema_migrations(scope, migration_name, migration, applied_at, migrated_at) VALUES(?, ?, ?, ?, ?)'
            );
            $stmt->execute([$this->migrationScope(), $name, $name, $now, $now]);
            return;
        }

        $stmt = $this->pdo->prepare('INSERT OR IGNORE INTO schema_migrations(migration, migrated_at) VALUES(?, ?)');
        $stmt->execute([$name, $now]);
    }
}
