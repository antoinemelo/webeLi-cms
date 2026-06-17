<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sql;

use App\Application\Taxonomy\TaxonomyAssignmentRepository;
use App\Core\Database;

final class SqlTaxonomyAssignmentRepository implements TaxonomyAssignmentRepository
{
    public function __construct(private readonly Database $db) {}

    public function replaceTermsForEntry(int $entryId, array $termIds): void
    {
        $this->db->run('DELETE FROM content_entry_taxonomy_terms WHERE entry_id = :entry_id', ['entry_id' => $entryId]);
        $sort = 1;
        foreach (array_unique(array_filter(array_map('intval', $termIds))) as $termId) {
            $this->db->run('INSERT INTO content_entry_taxonomy_terms(entry_id, term_id, sort_order) VALUES(:entry_id, :term_id, :sort_order)', [
                'entry_id' => $entryId,
                'term_id' => $termId,
                'sort_order' => $sort++,
            ]);
        }
    }
}
