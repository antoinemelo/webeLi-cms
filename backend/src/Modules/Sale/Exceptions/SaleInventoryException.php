<?php

declare(strict_types=1);

namespace App\Modules\Sale\Exceptions;

class SaleInventoryException extends SaleBusinessException
{
    /** @param array<string,mixed> $context */
    public function __construct(string $message, private readonly array $context = [])
    {
        parent::__construct($message);
    }

    /** @return array<string,mixed> */
    public function context(): array
    {
        return $this->context;
    }
}
