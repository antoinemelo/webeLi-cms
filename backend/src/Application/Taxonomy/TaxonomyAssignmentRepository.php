<?php

declare(strict_types=1);

namespace App\Application\Taxonomy;

interface TaxonomyAssignmentRepository
{
    /** @param list<int> $termIds */
    public function replaceTermsForEntry(int $entryId, array $termIds): void;
}
