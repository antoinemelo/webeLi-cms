<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Core\Database;
use Throwable;

final class AccountingDatabaseConnection
{
    private ?Database $database = null;
    private ?string $error = null;

    public function __construct(
        private readonly string $path,
        private readonly ?string $schemaPath = null,
    ) {}
    public function path(): string { return $this->path; }
    public function exists(): bool { return is_file($this->path); }
    public function database(): ?Database
    {
        try {
            if ($this->database !== null) return $this->database;
            $existed = $this->exists();
            $this->database = new Database($this->path);
            if (!$existed && $this->schemaPath !== null && is_file($this->schemaPath)) {
                $schema = trim((string)file_get_contents($this->schemaPath));
                if ($schema !== '') $this->database->pdo()->exec($schema);
            }
            return $this->database;
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            return null;
        }
    }
    public function status(): array
    {
        $db = $this->database();
        return [
            'path' => $this->path,
            'exists' => $this->exists(),
            'available' => $db !== null,
            'error' => $this->error,
        ];
    }
}
