<?php

declare(strict_types=1);

namespace App\Application\Projection;

interface ProjectionRepository
{
    /** @return list<int> */
    public function listContentEntryIds(): array;

    public function clearGlobalProjections(): void;

    public function replaceTaxonomyRoutes(): void;
}
