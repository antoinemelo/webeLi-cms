<?php

declare(strict_types=1);

namespace App\Domain\Taxonomy;

final class TaxonomyAssignment
{
    public function __construct(
        public readonly int $entryId,
        public readonly int $termId,
        public readonly string $taxonomyKey = '',
        public readonly int $sortOrder = 0
    ) {
        if ($this->entryId < 1) {
            throw new \InvalidArgumentException('Une affectation de taxonomie doit viser une entrée valide.');
        }
        if ($this->termId < 1) {
            throw new \InvalidArgumentException('Une affectation de taxonomie doit viser un terme valide.');
        }
    }

    public function belongsToTaxonomy(string $taxonomyKey): bool
    {
        return $this->taxonomyKey === $taxonomyKey;
    }

    public function toArray(): array
    {
        return [
            'entry_id' => $this->entryId,
            'term_id' => $this->termId,
            'taxonomy_key' => $this->taxonomyKey,
            'sort_order' => $this->sortOrder,
        ];
    }
}
