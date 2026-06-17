<?php

declare(strict_types=1);

namespace App\EditorialPackage\Exception;

use App\EditorialPackage\EditorialPackageException;

final class EditorialPackageValidationException extends EditorialPackageException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Editorial Package invalide : ' . implode(' | ', $errors));
    }
}
