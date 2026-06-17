<?php

declare(strict_types=1);

namespace App\Application\Schema;

use App\Domain\Schema\ContentType;

interface ContentTypeRepository
{
    public function findByKey(string $typeKey): ?ContentType;

    public function findIdByKey(string $typeKey): ?int;

    /** @return list<ContentType> */
    public function listEnabled(bool $adminOnly = false): array;

    public function exists(string $typeKey): bool;

    public function save(ContentType $contentType): int;
}
