<?php

declare(strict_types=1);

namespace App\Service;

use App\Application\Content\RebuildEntryProjection;
use App\Application\Projection\ProjectionRepository;
use App\Application\Support\TransactionManager;
use App\Core\Logger;

final class ProjectionService
{
    public function __construct(
        private readonly ProjectionRepository $projections,
        private readonly RebuildEntryProjection $rebuildEntryProjection,
        private readonly OutboxService $outbox,
        private readonly Logger $logger,
        private readonly TransactionManager $transactions,
    ) {}

    public function rebuildAll(): void
    {
        $this->transactions->transaction(function (): void {
            $entryIds = $this->projections->listContentEntryIds();
            $this->projections->clearGlobalProjections();
            foreach ($entryIds as $entryId) {
                $this->rebuildEntryProjection->execute($entryId);
            }
            $this->projections->replaceTaxonomyRoutes();
            $this->logger->info('projection.rebuild_all');
            $this->outbox->push('projection.rebuilt', ['scope' => 'all']);
        });
    }

    public function rebuildEntry(int $entryId): void
    {
        $this->transactions->transaction(function () use ($entryId): void {
            $this->rebuildEntryProjection->execute($entryId);
        });
    }

    public function rebuildTaxonomyRoutes(): void
    {
        $this->transactions->transaction(function (): void {
            $this->projections->replaceTaxonomyRoutes();
            $this->logger->info('projection.rebuild_taxonomies');
        });
    }
}
