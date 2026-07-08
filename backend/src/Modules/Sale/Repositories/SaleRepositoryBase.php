<?php

declare(strict_types=1);

namespace App\Modules\Sale\Repositories;

use App\Core\Database;
use App\Modules\Sale\Exceptions\SaleBusinessException;
use App\Modules\Sale\Services\SaleDatabaseConnection;

abstract class SaleRepositoryBase
{
    public function __construct(private readonly SaleDatabaseConnection $connection) {}

    protected function db(): Database
    {
        $db = $this->connection->database();
        if ($db === null) {
            throw new SaleBusinessException('sale.database_unavailable');
        }
        return $db;
    }

    public function rawDatabase(): Database
    {
        return $this->db();
    }

    protected function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
