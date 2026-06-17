<?php

declare(strict_types=1);

namespace App\Modules\AiAssistant\Repositories;

use App\Core\Database;
use PDO;

abstract class AiRepositoryBase
{
    public function __construct(protected readonly ?Database $db) {}

    public function isAvailable(): bool
    {
        return $this->db !== null;
    }

    protected function pdo(): PDO
    {
        if ($this->db === null) {
            throw new \RuntimeException('Base IA indisponible.');
        }
        return $this->db->pdo();
    }

    /** @return array<string,mixed> */
    protected function decodeJson(?string $json, mixed $fallback = null): mixed
    {
        if ($json === null || trim($json) === '') {
            return $fallback;
        }
        $value = json_decode($json, true);
        return json_last_error() === JSON_ERROR_NONE ? $value : $fallback;
    }

    protected function encodeJson(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
