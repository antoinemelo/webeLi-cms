<?php

declare(strict_types=1);

namespace App\Application\Publication;

use App\Application\Content\ContentEntryRepository;
use App\Application\Content\ContentRevisionRepository;
use App\Application\Content\Projection\RouteProjector;
use App\Core\Logger;
use App\Domain\Content\EditorialTruth;
use App\Domain\Content\ContentRevision;
use App\Service\OutboxService;

/**
 * Pipeline unique de projection publique.
 *
 * Toute opération capable de produire les tables publiques critiques doit passer
 * ici : publication normale, rebuild global, import post-seed, réparation locale.
 *
 * Le pipeline ne connaît qu'un contrat de sortie : PublishedProjection. Il écrit
 * toujours le même triptyque critique dans le même ordre logique :
 * public_content_snapshots, routes, seo_metadata, search_documents.
 * Chaque ligne garde sourcePublishedRevisionId et sourceRevisionChecksumSha256 via PublishedProjection.
 */
final class PublishedProjectionPipeline
{
    private const RESOURCE_TYPE = RouteProjector::RESOURCE_TYPE;

    public function __construct(
        private readonly ContentEntryRepository $entries,
        private readonly ContentRevisionRepository $revisions,
        private readonly ProjectionBuilder $builder,
        private readonly PublishedProjectionStore $repository,
        private readonly OutboxService $outbox,
        private readonly Logger $logger,
    ) {}

    /** @param array<string,mixed> $entry */
    public function buildForPublication(array $entry, ContentRevision $revision, string $languageCode): PublishedProjection
    {
        return $this->builder->buildForPublication($entry, $revision, $languageCode);
    }

    /**  list<array<string,mixed>> */
    public function listRoutesForEntry(int $entryId): array
    {
        return $this->repository->listRoutesForEntry($entryId);
    }

    /**
     * Écrit la projection publique critique d'une seule langue.
     *
     * @param list<array<string,mixed>> $oldRoutes
     * @return array{language:string,path:string}
     */
    public function replacePublishedLanguage(PublishedProjection $projection, string $fallbackTitle, array $oldRoutes, bool $createRedirects = true): array
    {
        $this->repository->replaceCriticalProjection($projection, $fallbackTitle);
        $this->assertProjectionVerified($projection);

        if ($createRedirects) {
            $this->repository->createRedirectsForPathChanges($oldRoutes, [$projection->languageCode => $projection->path]);
        }

        return ['language' => $projection->languageCode, 'path' => $projection->path];
    }

    /**
     * Reconstruit toutes les projections publiques d'une entrée à partir des
     * pointeurs publiés officiels content_entry_publications.
     *
     * @return list<string> langues reconstruites
     */
    public function rebuildEntry(int $entryId): array
    {
        $entry = $this->entries->findProjectionRowById($entryId);
        if (!$entry) {
            return [];
        }

        $oldRoutes = $this->repository->listRoutesForEntry($entryId);
        $this->repository->clearEntryProjectionArtifacts(self::RESOURCE_TYPE, $entryId);

        if (($entry['status'] ?? 'draft') !== 'published') {
            $this->repository->createTombstonesForRemovedRoutes((int) ($entry['site_id'] ?? 0), self::RESOURCE_TYPE, $entryId, null, $oldRoutes, 'rebuild_unpublished');
            return [];
        }

        $projections = $this->publishedProjectionsForEntry($entry);
        if ($projections === []) {
            $this->repository->createTombstonesForRemovedRoutes((int) ($entry['site_id'] ?? 0), self::RESOURCE_TYPE, $entryId, null, $oldRoutes, 'rebuild_without_published_revision');
            return [];
        }
        $this->assertNoDuplicateProjectionPaths($projections);

        $pathsByLanguage = [];
        $fallbackTitle = (string) ($entry['entry_key'] ?? '');
        foreach ($projections as $projection) {
            $this->repository->replaceCriticalProjection($projection, $fallbackTitle);
            $this->assertProjectionVerified($projection);
            $pathsByLanguage[$projection->languageCode] = $projection->path;
        }
        $this->repository->createRedirectsForPathChanges($oldRoutes, $pathsByLanguage);

        $languages = array_keys($pathsByLanguage);
        $this->logger->info('projection.rebuild_entry', [
            'entry_id' => $entryId,
            'source' => EditorialTruth::PUBLIC_PROJECTION_SOURCE,
            'pipeline' => self::class,
            'contract' => PublishedProjection::class,
            'critical_tables' => ['public_content_snapshots', 'routes', 'seo_metadata', 'search_documents'],
            'languages' => $languages,
            'projection_count' => count($projections),
        ]);
        $this->outbox->push('content.published_projection_ready', ['entry_id' => $entryId, 'languages' => $languages]);

        return $languages;
    }

    private function assertProjectionVerified(PublishedProjection $projection): void
    {
        $errors = $this->repository->verifyCriticalProjection($projection);
        if ($errors !== []) {
            throw new \RuntimeException('Projection publique non vérifiable : ' . implode(' | ', $errors));
        }
    }

    /** @param list<PublishedProjection> $projections */
    private function assertNoDuplicateProjectionPaths(array $projections): void
    {
        $seen = [];
        foreach ($projections as $projection) {
            $key = $projection->siteId . '|' . $projection->languageCode . '|' . $projection->path;
            if (isset($seen[$key])) {
                throw new \RuntimeException(sprintf('Projection refusée : chemin public dupliqué "%s" pour la langue "%s".', $projection->path, $projection->languageCode));
            }
            $seen[$key] = true;
        }
    }


    /**
     * @param array<string,mixed> $entry
     * @return list<PublishedProjection>
     */
    private function publishedProjectionsForEntry(array $entry): array
    {
        $entryId = (int) ($entry['id'] ?? 0);
        $projections = [];

        foreach ($this->entries->listPublicationRows($entryId) as $publication) {
            $languageCode = trim((string) ($publication['language_code'] ?? ''));
            $revisionId = (int) ($publication['published_revision_id'] ?? 0);
            if ($languageCode === '' || $revisionId < 1) {
                continue;
            }

            $revision = $this->revisions->findById($revisionId);
            EditorialTruth::assertPublicRevision($revision, $entryId);
            \assert($revision instanceof ContentRevision);

            $projections[] = $this->builder->buildForPublishedRevision($entry, $revision, $languageCode);
        }

        return $projections;
    }
}
