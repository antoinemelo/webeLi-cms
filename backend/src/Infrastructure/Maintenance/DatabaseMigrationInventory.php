<?php

declare(strict_types=1);

namespace App\Infrastructure\Maintenance;

use PDO;

final class DatabaseMigrationInventory
{
    /** @param list<array<string,mixed>> $specs @return list<array<string,mixed>> */
    public function inspect(array $specs): array
    {
        $items = [];
        foreach ($specs as $spec) {
            $items[] = $this->databaseStatus($spec);
        }
        return $items;
    }

    /** @param array<string,mixed> $spec @return array<string,mixed> */
    private function databaseStatus(array $spec): array
    {
        $expected = $this->expectedMigrations((string) ($spec['migrations_path'] ?? ''));
        $absolutePath = $this->absoluteProjectPath((string) ($spec['path'] ?? ''));
        $exists = $absolutePath !== null && is_file($absolutePath);
        $appliedState = $exists
            ? $this->appliedMigrations($absolutePath, (string) ($spec['key'] ?? ''))
            : ['journal_exists' => false, 'migrations' => []];
        $applied = is_array($appliedState['migrations'] ?? null) ? $appliedState['migrations'] : [];
        $expectedByName = $this->migrationsByName($expected);
        $appliedByName = $this->migrationsByName($applied);

        $missing = array_values(array_diff(array_keys($expectedByName), array_keys($appliedByName)));
        sort($missing, SORT_NATURAL);
        $unknown = array_values(array_filter(
            array_diff(array_keys($appliedByName), array_keys($expectedByName)),
            static fn(string $name): bool => !str_starts_with($name, 'module:')
        ));
        sort($unknown, SORT_NATURAL);

        $checksumMismatches = [];
        foreach ($expectedByName as $name => $migration) {
            $actualChecksum = (string) ($appliedByName[$name]['checksum'] ?? '');
            $expectedChecksum = (string) ($migration['checksum'] ?? '');
            if ($actualChecksum !== '' && $expectedChecksum !== '' && $actualChecksum !== $expectedChecksum) {
                $checksumMismatches[] = $name;
            }
        }

        $status = $this->databaseStatusCode($exists, (bool) ($appliedState['journal_exists'] ?? false), count($expected), $missing, $unknown, $checksumMismatches, (bool) ($spec['required'] ?? true));

        return [
            'key' => (string) ($spec['key'] ?? ''),
            'name' => (string) ($spec['name'] ?? $spec['key'] ?? ''),
            'kind' => (string) ($spec['kind'] ?? 'database'),
            'module_key' => $spec['module_key'] ?? null,
            'path' => (string) ($spec['path'] ?? ''),
            'schema_path' => (string) ($spec['schema_path'] ?? ''),
            'migrations_path' => (string) ($spec['migrations_path'] ?? ''),
            'required' => (bool) ($spec['required'] ?? true),
            'exists' => $exists,
            'journal_exists' => (bool) ($appliedState['journal_exists'] ?? false),
            'status' => $status,
            'expected_migrations' => $expected,
            'expected_count' => count($expected),
            'expected_latest' => $this->latestMigrationName($expected),
            'applied_migrations' => $applied,
            'applied_count' => count($applied),
            'applied_latest' => $this->latestMigrationName($applied),
            'missing_migrations' => $missing,
            'unknown_migrations' => $unknown,
            'checksum_mismatches' => $checksumMismatches,
        ];
    }

    /** @return list<array{name:string,checksum:string}> */
    private function expectedMigrations(string $path): array
    {
        $absolutePath = $this->absoluteProjectPath($path);
        if ($absolutePath === null || !is_dir($absolutePath)) {
            return [];
        }

        $files = glob(rtrim($absolutePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        sort($files, SORT_NATURAL);
        $migrations = [];
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $checksum = hash_file('sha256', $file);
            $migrations[] = [
                'name' => basename($file),
                'checksum' => is_string($checksum) ? $checksum : '',
            ];
        }
        return $migrations;
    }

    /** @return array{journal_exists:bool,migrations:list<array{name:string,migrated_at:string,checksum:string}>} */
    private function appliedMigrations(string $path, string $scope): array
    {
        try {
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1");
            $stmt->execute(['schema_migrations']);
            if ($stmt->fetchColumn() === false) {
                return ['journal_exists' => false, 'migrations' => []];
            }

            $columns = $this->migrationLogColumns($pdo);
            $items = [];
            if (in_array('migration', $columns, true)) {
                $checksumColumn = in_array('checksum', $columns, true) ? 'checksum' : "'' AS checksum";
                $dateColumn = in_array('migrated_at', $columns, true) ? 'migrated_at' : (in_array('applied_at', $columns, true) ? 'applied_at AS migrated_at' : "'' AS migrated_at");
                foreach ($pdo->query("SELECT migration AS name, {$dateColumn}, {$checksumColumn} FROM schema_migrations WHERE migration IS NOT NULL AND migration != ''")->fetchAll() as $row) {
                    $items[(string) $row['name']] = [
                        'name' => (string) $row['name'],
                        'migrated_at' => (string) ($row['migrated_at'] ?? ''),
                        'checksum' => (string) ($row['checksum'] ?? ''),
                    ];
                }
            }

            if (in_array('scope', $columns, true) && in_array('migration_name', $columns, true)) {
                $checksumColumn = in_array('checksum', $columns, true) ? 'checksum' : "'' AS checksum";
                $dateColumn = in_array('applied_at', $columns, true) ? 'applied_at' : (in_array('migrated_at', $columns, true) ? 'migrated_at AS applied_at' : "'' AS applied_at");
                $stmt = $pdo->prepare("SELECT migration_name AS name, {$dateColumn}, {$checksumColumn} FROM schema_migrations WHERE scope = ? AND migration_name IS NOT NULL AND migration_name != ''");
                $stmt->execute([$scope]);
                foreach ($stmt->fetchAll() as $row) {
                    $items[(string) $row['name']] = [
                        'name' => (string) $row['name'],
                        'migrated_at' => (string) ($row['applied_at'] ?? ''),
                        'checksum' => (string) ($row['checksum'] ?? ''),
                    ];
                }
            }

            ksort($items, SORT_NATURAL);
            return ['journal_exists' => true, 'migrations' => array_values($items)];
        } catch (\Throwable) {
            return ['journal_exists' => false, 'migrations' => []];
        }
    }

    /** @return list<string> */
    private function migrationLogColumns(PDO $pdo): array
    {
        $stmt = $pdo->query('PRAGMA table_info(schema_migrations)');
        if (!$stmt) {
            return [];
        }
        return array_values(array_filter(array_map(
            static fn(array $row): ?string => isset($row['name']) ? (string) $row['name'] : null,
            $stmt->fetchAll()
        )));
    }

    /** @param list<array<string,mixed>> $migrations @return array<string,array<string,mixed>> */
    private function migrationsByName(array $migrations): array
    {
        $indexed = [];
        foreach ($migrations as $migration) {
            $name = trim((string) ($migration['name'] ?? ''));
            if ($name !== '') {
                $indexed[$name] = $migration;
            }
        }
        return $indexed;
    }

    /** @param list<array<string,mixed>> $migrations */
    private function latestMigrationName(array $migrations): string
    {
        $names = array_keys($this->migrationsByName($migrations));
        sort($names, SORT_NATURAL);
        return $names === [] ? '' : (string) end($names);
    }

    /** @param list<string> $missing @param list<string> $unknown @param list<string> $checksumMismatches */
    private function databaseStatusCode(bool $exists, bool $journalExists, int $expectedCount, array $missing, array $unknown, array $checksumMismatches, bool $required): string
    {
        if (!$exists) {
            return $required ? 'missing_database' : 'optional_database_absent';
        }
        if (!$journalExists && $expectedCount > 0) {
            return 'missing_journal';
        }
        if ($checksumMismatches !== []) {
            return 'checksum_mismatch';
        }
        if ($missing !== []) {
            return 'pending_migrations';
        }
        if ($unknown !== []) {
            return 'unknown_migrations';
        }
        if ($expectedCount === 0) {
            return 'no_migrations';
        }
        return 'up_to_date';
    }

    private function absoluteProjectPath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }

        $normalized = str_replace('\\', '/', $path);
        if (!$this->isAbsolutePath($path)) {
            if ($normalized === '..' || str_starts_with($normalized, '../') || str_contains($normalized, '/../')) {
                return null;
            }
            $path = base_path($normalized);
        }

        $root = rtrim(str_replace('\\', '/', base_path()), '/') . '/';
        $candidate = str_replace('\\', '/', $path);
        if ($candidate === rtrim($root, '/') || str_starts_with($candidate, $root)) {
            return $path;
        }

        return null;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }
}
