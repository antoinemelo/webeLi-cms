<?php

declare(strict_types=1);

namespace App\Health;

use PDO;
use Throwable;

final class HealthCheckService
{
    /**
     * @param array<string,mixed> $healthConfig
     * @param array<string,array<string,mixed>> $databaseConfig
     */
    public function __construct(
        private readonly array $healthConfig,
        private readonly array $databaseConfig,
    ) {}

    /** @return array{status:string,checks:array<string,array{status:string,duration_ms:int}>} */
    public function readiness(): array
    {
        $startedAt = hrtime(true);
        $checks = [];
        $checks['runtime'] = $this->measure(static function (): bool {
            return PHP_VERSION_ID >= 80200 && extension_loaded('pdo_sqlite');
        });

        if ((bool) ($this->healthConfig['check_storage'] ?? true)) {
            $checks['storage'] = $this->measure(function (): bool {
                foreach ((array) ($this->healthConfig['required_storage_paths'] ?? []) as $path) {
                    if (!is_string($path) || !is_dir($path) || !is_readable($path)) {
                        return false;
                    }
                }
                return true;
            });
        }

        if ((bool) ($this->healthConfig['check_databases'] ?? true)) {
            $checks['databases'] = $this->measure(fn (): bool => $this->databasesReadable());
        }

        $elapsedMs = self::elapsedMs($startedAt);
        $withinTimeout = $elapsedMs <= (int) ($this->healthConfig['timeout_ms'] ?? 1600);
        $checks['deadline'] = ['status' => $withinTimeout ? 'ok' : 'fail', 'duration_ms' => $elapsedMs];

        $ready = !in_array('fail', array_column($checks, 'status'), true);
        return ['status' => $ready ? 'ready' : 'not_ready', 'checks' => $checks];
    }

    /** @return array{status:string,duration_ms:int} */
    private function measure(callable $check): array
    {
        $startedAt = hrtime(true);
        try {
            $ok = $check() === true;
        } catch (Throwable) {
            $ok = false;
        }
        return ['status' => $ok ? 'ok' : 'fail', 'duration_ms' => self::elapsedMs($startedAt)];
    }

    private function databasesReadable(): bool
    {
        $busyTimeout = max(10, (int) ($this->healthConfig['database_busy_timeout_ms'] ?? 100));
        foreach (['core', 'iam'] as $name) {
            $path = $this->databaseConfig[$name]['path'] ?? null;
            if (!is_string($path) || $path === '' || !is_file($path) || !is_readable($path)) {
                return false;
            }
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 1,
            ]);
            $pdo->exec('PRAGMA busy_timeout = ' . $busyTimeout);
            $value = $pdo->query('SELECT 1')->fetchColumn();
            $pdo = null;
            if ((int) $value !== 1) {
                return false;
            }
        }
        return true;
    }

    private static function elapsedMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
