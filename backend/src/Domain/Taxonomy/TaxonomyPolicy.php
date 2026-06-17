<?php

declare(strict_types=1);

namespace App\Domain\Taxonomy;

final class TaxonomyPolicy
{
    /** @param list<string> $allowedTaxonomyKeys @param list<string> $requiredTaxonomyKeys @param array<string,int> $maxTermsByTaxonomy */
    public function __construct(
        public readonly bool $enabled = false,
        public readonly array $allowedTaxonomyKeys = [],
        public readonly array $requiredTaxonomyKeys = [],
        public readonly array $maxTermsByTaxonomy = []
    ) {}

    public static function disabled(): self
    {
        return new self(false);
    }

    public function allowsTaxonomies(): bool
    {
        return $this->enabled;
    }

    public function allowsTaxonomy(string $taxonomyKey): bool
    {
        return $this->enabled && ($this->allowedTaxonomyKeys === [] || in_array($taxonomyKey, $this->allowedTaxonomyKeys, true));
    }

    public function requiresTaxonomy(string $taxonomyKey): bool
    {
        return in_array($taxonomyKey, $this->requiredTaxonomyKeys, true);
    }

    public function maxTermsFor(string $taxonomyKey): ?int
    {
        return isset($this->maxTermsByTaxonomy[$taxonomyKey]) ? (int) $this->maxTermsByTaxonomy[$taxonomyKey] : null;
    }

    /** @param list<TaxonomyAssignment> $assignments @return list<string> */
    public function validateAssignments(array $assignments): array
    {
        if (!$this->enabled && $assignments !== []) {
            return ['Ce type de contenu n’autorise pas les taxonomies.'];
        }
        $errors = [];
        $counts = [];
        foreach ($assignments as $assignment) {
            if (!$assignment instanceof TaxonomyAssignment) {
                continue;
            }
            if (!$this->allowsTaxonomy($assignment->taxonomyKey)) {
                $errors[] = sprintf('La taxonomie "%s" n’est pas autorisée.', $assignment->taxonomyKey);
            }
            $counts[$assignment->taxonomyKey] = ($counts[$assignment->taxonomyKey] ?? 0) + 1;
        }
        foreach ($this->requiredTaxonomyKeys as $requiredKey) {
            if (($counts[$requiredKey] ?? 0) === 0) {
                $errors[] = sprintf('La taxonomie "%s" est obligatoire.', $requiredKey);
            }
        }
        foreach ($counts as $taxonomyKey => $count) {
            $max = $this->maxTermsFor($taxonomyKey);
            if ($max !== null && $count > $max) {
                $errors[] = sprintf('La taxonomie "%s" accepte au maximum %d terme(s).', $taxonomyKey, $max);
            }
        }
        return $errors;
    }

    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'allowed_taxonomy_keys' => $this->allowedTaxonomyKeys,
            'required_taxonomy_keys' => $this->requiredTaxonomyKeys,
            'max_terms_by_taxonomy' => $this->maxTermsByTaxonomy,
        ];
    }
}
