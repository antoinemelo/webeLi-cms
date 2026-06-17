<?php

declare(strict_types=1);

namespace App\Application\Taxonomy;

final class AssignTaxonomyTerms
{
    public function __construct(private readonly TaxonomyAssignmentRepository $assignments) {}

    /** @param list<int>|array<int|string,mixed> $termIds */
    public function execute(int $entryId, array $termIds): void
    {
        $flat = [];
        foreach ($termIds as $value) {
            if (is_array($value)) {
                foreach ($value as $candidate) {
                    if (is_numeric($candidate) && (int) $candidate > 0) { $flat[] = (int) $candidate; }
                }
                continue;
            }
            if (is_numeric($value) && (int) $value > 0) { $flat[] = (int) $value; }
        }
        $this->assignments->replaceTermsForEntry($entryId, array_values(array_unique($flat)));
    }
}
