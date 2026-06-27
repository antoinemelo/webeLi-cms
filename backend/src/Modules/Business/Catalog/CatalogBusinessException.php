<?php

declare(strict_types=1);

namespace App\Modules\Business\Catalog;

use InvalidArgumentException;

final class CatalogBusinessException extends InvalidArgumentException
{
    public static function because(string $code): self
    {
        return new self($code);
    }
}
