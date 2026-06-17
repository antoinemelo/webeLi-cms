<?php

declare(strict_types=1);

namespace App\Application\Content;

use App\Application\Publication\PublishedProjectionPipeline;
use App\Application\Support\TransactionManager;
use App\Application\Iam\UserReferenceValidator;
use App\Core\ApiException;
use App\Core\ErrorCode;
use App\Domain\Content\ContentEntry;
use App\Domain\Content\ContentRevision;
use App\Domain\Workflow\WorkflowState;
use App\Module\HookDispatcher;
use App\Service\OutboxService;

/**
 * Publication atomique SEO-first.
 *
 * La publication devient valide uniquement si l'état éditorial ET les projections
 * publiques critiques de la langue demandée sont écrits dans la même transaction :
 * public_content_snapshots, routes, seo_metadata, search_documents.
 *
 * L'outbox reste réservée aux effets secondaires non critiques : purge cache,
 * notifications, webhooks, indexation externe, génération média, sitemap ping.
 */
final class PublishContentEntry
{
    private const EVENT_ENTRY_PUBLISHED = 'content.entry_published';
    private const EVENT_PROJECTION_READY = 'content.published_projection_ready';

    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly ContentEntryRepository $entries,
        private readonly ContentRevisionRepository $revisions,
        private readonly PublishedProjectionPipeline $projectionPipeline,
        private readonly OutboxService $outbox,
        private readonly ?HookDispatcher $hooks = null,
        private readonly ?UserReferenceValidator $userReferences = null,
        private readonly ?BlockDocumentNormalizer $blocks = null,
    ) {}

    public function execute(int $entryId, string $languageCode, int $userId, ?int $revisionId = null, bool $canManageHtmlRaw = false): PublishContentEntryResult
    {
        if ($entryId < 1) { throw new \InvalidArgumentException('Entrée invalide.'); }
        $languageCode = trim($languageCode);
        if ($languageCode === '') { throw new \InvalidArgumentException('Langue de publication invalide.'); }
        if ($userId < 1) { throw new \InvalidArgumentException('Utilisateur de publication invalide.'); }
        $this->userReferences?->assertCanPublish($userId);

        return $this->transactions->transaction(function () use ($entryId, $languageCode, $userId, $revisionId, $canManageHtmlRaw): PublishContentEntryResult {
            $entryRow = $this->entries->findProjectionRowById($entryId);
            if (!$entryRow) { throw new ApiException(ErrorCode::CONTENT_ENTRY_NOT_FOUND, ErrorCode::message(ErrorCode::CONTENT_ENTRY_NOT_FOUND), 404, ['entry_id' => $entryId]); }

            $entry = ContentEntry::fromArray($entryRow);
            $this->assertEntryCanBePublished($entry);
            $this->assertWorkflowAllowsPublication($entry);

            $workingRevisionId = $this->entries->workingRevisionId($entryId, $languageCode);
            if (!$workingRevisionId) {
                throw new ApiException(ErrorCode::PUBLICATION_CONFLICT, 'Publication refusée : aucune révision de travail pour cette langue.', 409, ['entry_id' => $entryId, 'language_code' => $languageCode]);
            }
            $revisionId = $revisionId ?? $workingRevisionId;
            if ($revisionId !== $workingRevisionId) {
                throw new ApiException(ErrorCode::REVISION_CONFLICT, ErrorCode::message(ErrorCode::REVISION_CONFLICT), 409, ['entry_id' => $entryId, 'language_code' => $languageCode, 'requested_revision_id' => $revisionId, 'working_revision_id' => $workingRevisionId]);
            }

            $publicationRow = $this->entries->publicationRow($entryId, $languageCode);
            $previousPublishedRevisionId = $publicationRow && isset($publicationRow['published_revision_id']) ? (int) $publicationRow['published_revision_id'] : null;

            $revision = $this->revisions->findById($revisionId);
            $this->assertRevisionCanBePublished($revision, $entryId, $languageCode, $previousPublishedRevisionId);
            \assert($revision instanceof ContentRevision);
            $this->assertRevisionBlocksCanBePublished($revision, $canManageHtmlRaw);

            $projection = $this->projectionPipeline->buildForPublication($entryRow, $revision, $languageCode);
            $oldRoutes = $this->projectionPipeline->listRoutesForEntry($entryId);

            $this->hooks?->dispatch('content.before_publish', [
                'entry_id' => $entryId,
                'user_id' => $userId,
                'entry' => $entryRow,
                'revision_id' => $revisionId,
                'language_code' => $languageCode,
                'document' => $revision->document(),
                'projection' => $projection,
            ]);

            $publishedAt = $this->publicationDateFromRevision($revision) ?: now_utc();

            // Ordre volontaire : la nouvelle révision devient d'abord publiée,
            // puis les pointeurs publics et projections basculent vers elle.
            // Les anciennes révisions ne sont passées en superseded qu'après le
            // remplacement des projections critiques, afin de respecter les
            // triggers SQL qui interdisent toute projection publique pointant
            // vers une révision non publiée.
            $this->revisions->markPublished($revisionId, $userId, $publishedAt);
            $this->entries->publishRevisionForLanguage($entryId, $languageCode, $revisionId, $userId, $publishedAt);

            // Critique : ces écritures sont dans la transaction de publication.
            // Toute exception SQL annule donc aussi l'état éditorial publié.
            $this->projectionPipeline->replacePublishedLanguage($projection, (string) ($entryRow['entry_key'] ?? ''), $oldRoutes);
            $this->revisions->markOtherPublishedRevisionsAsSuperseded($entryId, $languageCode, $revisionId, $publishedAt);

            $eventPayload = [
                'entry_id' => $entryId,
                'site_id' => (int) ($entryRow['site_id'] ?? 0),
                'language_code' => $languageCode,
                'published_revision_id' => $revisionId,
                'previous_published_revision_id' => $previousPublishedRevisionId,
                'published_by_user_id' => $userId,
                'published_at' => $publishedAt,
                'critical_projections' => ['public_content_snapshots', 'routes', 'seo_metadata', 'search_documents'],
                'secondary_tasks_only' => true,
            ];
            $this->outbox->push(self::EVENT_ENTRY_PUBLISHED, $eventPayload);
            $this->outbox->push(self::EVENT_PROJECTION_READY, ['entry_id' => $entryId, 'languages' => [$languageCode]]);
            $this->hooks?->dispatch('content.after_publish', $eventPayload + ['entry' => $entryRow, 'document' => $revision->document(), 'projection' => $projection]);

            return new PublishContentEntryResult(
                $entryId,
                $revisionId,
                $languageCode,
                $previousPublishedRevisionId,
                $userId,
                $publishedAt,
                ContentEntry::STATUS_PUBLISHED,
                WorkflowState::PUBLISHED,
                [self::EVENT_ENTRY_PUBLISHED, self::EVENT_PROJECTION_READY],
                [],
                ['public_content_snapshots', 'routes', 'seo_metadata', 'search_documents'],
            );
        });
    }

    private function publicationDateFromRevision(ContentRevision $revision): string
    {
        $document = $revision->document();
        $fields = is_array($document['fields'] ?? null) ? $document['fields'] : [];
        foreach (['display_published_at', 'published_at', 'publication_date'] as $key) {
            $value = trim((string) ($fields[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $timestamp = strtotime($value);
            return $timestamp !== false ? gmdate('Y-m-d H:i:s', $timestamp) : $value;
        }
        return '';
    }

    private function assertRevisionBlocksCanBePublished(ContentRevision $revision, bool $canManageHtmlRaw): void
    {
        $document = $revision->document();
        $normalizer = $this->blocks ?? new BlockDocumentNormalizer();
        $blocks = $normalizer->normalize($document['blocks'] ?? ($document['content']['blocks'] ?? []));
        $publicBlocks = $normalizer->publicBlocks($blocks);
        if ($publicBlocks === []) {
            throw new \InvalidArgumentException('Publication refusée : au moins un bloc actif avec l’état éditorial publié est obligatoire.');
        }
        $errors = $normalizer->validate($publicBlocks, $canManageHtmlRaw);
        if ($errors !== []) {
            throw new \InvalidArgumentException("Publication refusée : blocs éditoriaux incomplets ou invalides.\n- " . implode("\n- ", $errors));
        }
    }

    private function assertEntryCanBePublished(ContentEntry $entry): void
    {
        if (!$entry->isActive) { throw new ApiException(ErrorCode::PUBLICATION_CONFLICT, 'Publication refusée : une entrée inactive ne peut pas être publiée.', 409, ['entry_id' => $entry->id]); }
        if ($entry->isArchived()) { throw new ApiException(ErrorCode::PUBLICATION_CONFLICT, 'Publication refusée : une entrée archivée ne peut pas être publiée directement.', 409, ['entry_id' => $entry->id]); }
    }

    private function assertWorkflowAllowsPublication(ContentEntry $entry): void
    {
        $currentState = new WorkflowState($entry->workflowState);
        $targetState = WorkflowState::published();
        if (!$currentState->canTransitionTo($targetState)) {
            throw new ApiException(ErrorCode::PUBLICATION_CONFLICT, 'Publication refusée : transition workflow non autorisée.', 409, ['current_state' => (string) $currentState, 'target_state' => (string) $targetState]);
        }
    }

    private function assertRevisionCanBePublished(?ContentRevision $revision, int $entryId, string $languageCode, ?int $currentPublishedRevisionId): void
    {
        if (!$revision || !$revision->id || !$revision->isForEntry($entryId)) {
            throw new ApiException(ErrorCode::REVISION_CONFLICT, 'Publication refusée : la révision n’existe pas ou ne correspond pas à cette entrée.', 409, ['entry_id' => $entryId, 'language_code' => $languageCode]);
        }
        if ($revision->isPublished() && $revision->id === $currentPublishedRevisionId) {
            throw new ApiException(ErrorCode::PUBLICATION_CONFLICT, 'Publication refusée : cette révision est déjà publiée pour cette langue.', 409, ['entry_id' => $entryId, 'language_code' => $languageCode, 'revision_id' => $revision->id]);
        }
        $documentLanguage = trim((string) ($revision->document()['language_code'] ?? ''));
        if ($documentLanguage !== $languageCode) {
            throw new ApiException(ErrorCode::REVISION_CONFLICT, 'Publication refusée : la révision ne correspond pas à la langue demandée.', 409, ['entry_id' => $entryId, 'revision_language_code' => $documentLanguage, 'requested_language_code' => $languageCode]);
        }
        if (!$revision->isDraft() && !$revision->isPublished()) {
            throw new ApiException(ErrorCode::PUBLICATION_CONFLICT, 'Publication refusée : une révision dans cet état ne peut pas être publiée directement.', 409, ['entry_id' => $entryId, 'language_code' => $languageCode, 'revision_id' => $revision->id, 'workflow_status' => $revision->workflowStatus]);
        }
    }
}
