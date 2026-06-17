<?php

declare(strict_types=1);

namespace App\Modules\AiAssistant\Services;

use App\Core\Database;
use Throwable;

final class AiDatabaseConnection
{
    private ?Database $database = null;
    private ?string $error = null;

    public function __construct(private readonly string $path) {}

    public function path(): string { return $this->path; }

    public function exists(): bool { return is_file($this->path); }

    public function database(): ?Database
    {
        if (!$this->exists()) {
            $this->error = 'Base IA absente.';
            return null;
        }
        if ($this->database !== null) {
            return $this->database;
        }
        try {
            return $this->database = new Database($this->path);
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            return null;
        }
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $db = $this->database();
        $tables = [];
        if ($db !== null) {
            try {
                $tables = array_map(
                    static fn(array $row): string => (string) $row['name'],
                    $db->all("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'ai_%' ORDER BY name")
                );
            } catch (Throwable $e) {
                $this->error = $e->getMessage();
            }
        }
        return [
            'path' => $this->path,
            'exists' => $this->exists(),
            'available' => $db !== null,
            'tables' => $tables,
            'error' => $this->error,
        ];
    }
}
