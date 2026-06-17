<?php

declare(strict_types=1);

namespace App\Application\Content;

/**
 * Validation applicative structurée pour l'édition admin.
 *
 * Les anciens validateurs métier retournent encore majoritairement des messages
 * lisibles. Cette exception conserve ces messages, tout en exposant une carte
 * field => messages compatible avec le contrat d'erreur admin-api-v1.
 */
final class ContentValidationException extends \InvalidArgumentException
{
    /** @param array<string,list<string>> $fields */
    public function __construct(string $message, private readonly array $fields)
    {
        parent::__construct($message);
    }

    /** @return array<string,list<string>> */
    public function fields(): array
    {
        return $this->fields;
    }
}
