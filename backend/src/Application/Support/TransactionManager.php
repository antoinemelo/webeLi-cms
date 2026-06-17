<?php

declare(strict_types=1);

namespace App\Application\Support;

interface TransactionManager
{
    /** @template T @param callable():T $callback @return T */
    public function transaction(callable $callback): mixed;
}
