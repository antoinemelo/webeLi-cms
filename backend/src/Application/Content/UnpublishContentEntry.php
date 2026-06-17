<?php

declare(strict_types=1);

namespace App\Application\Content;

use App\Application\Content\Projection\RouteProjector;
use App\Application\Publication\PublishedProjectionStore;
use App\Application\Support\TransactionManager;
use App\Application\Iam\UserReferenceValidator;
use App\Core\ApiException;
use App\Core\ErrorCode;
use App\Service\OutboxService;

final class UnpublishContentEntry
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly ContentEntryRepository $entries,
        private readonly PublishedProjectionStore $publishedProjections,
        private readonly OutboxService $outbox,
        private readonly ?UserReferenceValidator $userReferences = null,
    ) {}

    /** @return array<string,mixed> */
    public function execute(int $entryId, string $languageCode, int $userId, bool $createTombstone = true): array
    {
        if ($entryId < 1) { throw new \InvalidArgumentException('Entrée invalide.'); }
        $languageCode = trim($languageCode);
        if ($languageCode === '') { throw new \InvalidArgumentException('Langue invalide.'); }
        if ($userId < 1) { throw new \InvalidArgumentException('Utilisateur invalide.'); }
        $this->userReferences?->assertCanUnpublish($userId);

        return $this->transactions->transaction(function () use ($entryId, $languageCode, $userId, $createTombstone): array {
            $entry = $this->entries->findProjectionRowById($entryId);
            if (!$entry) {
                throw new ApiException(ErrorCode::CONTENT_ENTRY_NOT_FOUND, ErrorCode::message(ErrorCode::CONTENT_ENTRY_NOT_FOUND), 404, ['entry_id' => $entryId]);
            }

            $publication = $this->entries->publicationRow($entryId, $languageCode);
            if (!$publication || ($publication['workflow_status'] ?? '') !== 'published') {
                throw new ApiException(ErrorCode::PUBLICATION_CONFLICT, sprintf('Dépublication refusée : aucune publication active pour "%s".', $languageCode), 409, ['entry_id' => $entryId, 'language_code' => $languageCode]);
            }

            $unpublishedAt = now_utc();
            $oldRoutes = $this->publishedProjections->listRoutesForEntry($entryId);
            $this->entries->unpublishLanguage($entryId, $languageCode, $userId, $unpublishedAt);
            $this->publishedProjections->removeCriticalProjection(RouteProjector::RESOURCE_TYPE, $entryId, $languageCode);
            if ($createTombstone) {
                $this->publishedProjections->createTombstonesForRemovedRoutes((int) $entry['site_id'], RouteProjector::RESOURCE_TYPE, $entryId, $languageCode, $oldRoutes, 'unpublished');
            }

            $payload = [
                'entry_id' => $entryId,
                'site_id' => (int) ($entry['site_id'] ?? 0),
                'language_code' => $languageCode,
                'unpublished_by_user_id' => $userId,
                'unpublished_at' => $unpublishedAt,
                'critical_projections_removed' => ['public_content_snapshots', 'routes', 'seo_metadata', 'search_documents'],
            ];
            $this->outbox->push('content.entry_unpublished', $payload);
            return ['status' => 'unpublished'] + $payload;
        });
    }
}
