<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sql;

use App\Application\Support\TransactionManager;
use App\Core\Database;

final class SqlTransactionManager implements TransactionManager
{
    public function __construct(private readonly Database $db) {}

    public function transaction(callable $callback): mixed
    {
        return $this->db->transaction(static fn() => $callback());
    }
}
