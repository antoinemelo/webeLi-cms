<?php

declare(strict_types=1);

namespace App\Application\Schema;

use App\Domain\Schema\FieldDefinition;

interface FieldDefinitionRepository
{
    /** @return list<FieldDefinition> */
    public function listForContentTypeId(int $contentTypeId): array;

    /** @param list<FieldDefinition> $fields */
    public function replaceForContentTypeId(int $contentTypeId, array $fields): void;
}
