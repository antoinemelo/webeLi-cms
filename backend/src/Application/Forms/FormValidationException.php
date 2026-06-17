<?php

declare(strict_types=1);

namespace App\Application\Forms;

use InvalidArgumentException;

final class FormValidationException extends InvalidArgumentException
{
    /** @param array<string,list<string>> $fields */
    public function __construct(private readonly array $fields)
    {
        parent::__construct('FORM_VALIDATION_FAILED');
    }

    /** @return array<string,list<string>> */
    public function fields(): array
    {
        return $this->fields;
    }
}
