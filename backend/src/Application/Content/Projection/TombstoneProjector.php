<?php

declare(strict_types=1);

namespace App\Application\Content\Projection;

use App\Application\Routing\TombstoneRepository;
use App\Domain\Routing\Tombstone;

final class TombstoneProjector
{
    public function __construct(private readonly TombstoneRepository $tombstones) {}

    /**
     * @param array<string,mixed> $entry
     * @param list<array<string,mixed>> $oldRoutes
     */
    public function projectUnpublishedEntry(array $entry, array $oldRoutes): void
    {
        foreach ($oldRoutes as $old) {
            $this->tombstones->replace(new Tombstone(
                (int) $entry['site_id'],
                (string) $old['full_path'],
                $old['language_code'] ?: null,
                RouteProjector::RESOURCE_TYPE,
                (int) $entry['id'],
                null,
                'entry_unpublished'
            ));
        }
    }
}
