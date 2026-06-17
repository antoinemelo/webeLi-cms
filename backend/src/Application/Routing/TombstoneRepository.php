<?php

declare(strict_types=1);

namespace App\Application\Routing;

use App\Domain\Routing\Tombstone;

interface TombstoneRepository
{
    public function deleteByResource(string $resourceType, int $resourceId): void;

    public function replace(Tombstone $tombstone): void;
}
