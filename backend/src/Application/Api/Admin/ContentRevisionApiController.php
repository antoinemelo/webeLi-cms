<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Application\Content\ContentEntryRepository;
use App\Application\Content\ContentLocalizationRepository;
use App\Application\Content\ContentRevisionRepository;
use App\Application\Content\GeneratePreviewUrl;
use App\Core\ApiException;
use App\Core\Database;
use App\Core\ErrorCode;
use App\Core\Request;
use App\Core\Response;
use App\Repository\AuthRepository;
use App\Application\Content\Read\EditorialContentReadRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;

final class ContentRevisionApiController
{
    public function __construct(
        private readonly Request $request,
        private readonly EditorialContentReadRepository $content,
        private readonly SiteRepository $sites,
        private readonly AuthRepository $auth,
        private readonly Authorization $authorization,
        private readonly ContentRevisionRepository $revisions,
        private readonly ContentEntryRepository $entries,
        private readonly ContentLocalizationRepository $localizations,
        private readonly Database $db,
        private readonly GeneratePreviewUrl $generatePreviewUrl,
    ) {}

    public function index(int $id): Response
    {
        [$site, $lang] = $this->guardEntry($id, 'content.read');
        return Response::success([
            'entry_id' => $id,
            'language_code' => $lang,
            'revisions' => AdminApiContract::revisionList($this->revisions->listForEntry($id, $lang)),
        ], 'admin.entries.revisions.index.v1', AdminApiContract::meta($site, $lang));
    }

    public function show(int $id, int $revisionId): Response
    {
        [$site, $lang] = $this->guardEntry($id, 'content.read');
        $revision = $this->revisionOr404($id, $lang, $revisionId);
        return Response::success([
            'entry_id' => $id,
            'language_code' => $lang,
            'revision' => AdminApiContract::revisionSummary($revision),
            'document' => $this->documentFromRevision($revision),
            'preview_url' => $this->generatePreviewUrl->execute($id, $revisionId, $lang, (int) $site['id'], (string) ($site['matched_request_base_path'] ?? $site['matched_base_path'] ?? '')),
        ], 'admin.entries.revisions.show.v1', AdminApiContract::meta($site, $lang));
    }

    public function preview(int $id, int $revisionId): Response
    {
        [$site, $lang] = $this->guardEntry($id, 'content.preview');
        $this->revisionOr404($id, $lang, $revisionId);
        $aggregate = $this->content->previewAggregate($id, $lang, $revisionId, (int) $site['id']);
        if (!$aggregate) {
            return Response::error(ErrorCode::PREVIEW_NOT_FOUND, ErrorCode::message(ErrorCode::PREVIEW_NOT_FOUND), ErrorCode::httpStatus(ErrorCode::PREVIEW_NOT_FOUND), [
                'entry_id' => $id,
                'revision_id' => $revisionId,
                'site_id' => (int) $site['id'],
                'language_code' => $lang,
            ]);
        }
        return Response::success([
            'aggregate' => $aggregate,
            'preview_url' => $this->generatePreviewUrl->execute($id, $revisionId, $lang, (int) $site['id'], (string) ($site['matched_request_base_path'] ?? $site['matched_base_path'] ?? '')),
            'preview' => $aggregate['preview'] ?? [],
        ], 'admin.entries.revisions.preview.v1', AdminApiContract::meta($site, $lang));
    }

    public function restore(int $id, int $revisionId): Response
    {
        [$site, $lang] = $this->guardEntry($id, 'content.revisions.restore');
        $source = $this->revisionOr404($id, $lang, $revisionId);
        $userId = $this->currentUserId();
        $newRevisionId = $this->db->transaction(function () use ($id, $lang, $source, $userId): int {
            $newRevisionId = $this->revisions->createRestoredDraftForEntry($id, $lang, $source, $userId);
            $document = $this->documentFromRevision($source);
            $content = is_array($document['content'] ?? null) ? $document['content'] : [];
            $this->localizations->upsertDraft($id, $lang, [
                'title' => (string) ($content['title'] ?? ''),
                'draft_slug' => (string) ($content['slug'] ?? ''),
            ]);
            $this->entries->setWorkingRevision($id, $lang, $newRevisionId, $userId);
            return $newRevisionId;
        });

        $restored = $this->revisions->findForEntry($id, $lang, $newRevisionId);
        return Response::success([
            'entry_id' => $id,
            'language_code' => $lang,
            'restored_from_revision_id' => $revisionId,
            'working_revision' => AdminApiContract::revisionSummary($restored),
            'preview_url' => $this->generatePreviewUrl->execute($id, $newRevisionId, $lang, (int) $site['id'], (string) ($site['matched_request_base_path'] ?? $site['matched_base_path'] ?? '')),
        ], 'admin.entries.revisions.restore.v1', AdminApiContract::meta($site, $lang));
    }

    public function pruneBeforePublished(int $id): Response
    {
        [$site, $lang] = $this->guardEntry($id, 'content.revisions.prune');
        $result = $this->revisions->pruneBeforePublished($id, $lang);
        return Response::success([
            'entry_id' => $id,
            'language_code' => $lang,
            'deleted_revisions' => $result['deleted'],
            'kept_revisions' => $result['kept'],
            'published_revision_id' => $result['published_revision_id'],
            'published_revision_number' => $result['published_revision_number'],
            'kept_revision_ids' => $result['kept_revision_ids'],
            'deleted_revision_ids' => $result['deleted_revision_ids'],
            'revisions' => AdminApiContract::revisionList($result['revisions'] ?? []),
        ], 'admin.entries.revisions.prune.v1', AdminApiContract::meta($site, $lang));
    }

    /** @return array{0:array<string,mixed>,1:string} */
    private function guardEntry(int $id, string $permission): array
    {
        $this->auth->requireAuth();
        $payload = AdminApiContract::dataPayload($this->request, false);
        $siteId = (int) ($payload['site_id'] ?? $this->request->query['site_id'] ?? 0);
        $site = AdminApiContract::siteContext($this->request, $this->sites, $siteId > 0 ? $siteId : null, $this->auth);
        $this->authorization->require($permission, (int) $site['id']);
        $lang = AdminApiContract::language($this->request, $this->sites, $site, $payload);
        $entry = $this->content->getWorkingEntryAggregate($id, $lang);
        if (!$entry) {
            throw new ApiException(ErrorCode::CONTENT_ENTRY_NOT_FOUND, ErrorCode::message(ErrorCode::CONTENT_ENTRY_NOT_FOUND), 404, ['entry_id' => $id]);
        }
        AdminApiContract::assertEntryBelongsToSite($entry['entry'] ?? [], $site);
        return [$site, $lang];
    }

    /** @return array<string,mixed> */
    private function revisionOr404(int $entryId, string $languageCode, int $revisionId): array
    {
        $revision = $this->revisions->findForEntry($entryId, $languageCode, $revisionId);
        if (!$revision) {
            throw new ApiException(ErrorCode::CONTENT_ENTRY_NOT_FOUND, 'Révision introuvable pour ce contenu.', 404, [
                'entry_id' => $entryId,
                'revision_id' => $revisionId,
                'language_code' => $languageCode,
            ]);
        }
        return $revision;
    }

    /** @return array<string,mixed> */
    private function documentFromRevision(array $revision): array
    {
        $document = json_decode((string) ($revision['document_json'] ?? '{}'), true);
        return is_array($document) ? $document : [];
    }

    private function currentUserId(): int
    {
        $user = $this->auth->user();
        return is_array($user) && isset($user['id']) ? (int) $user['id'] : 0;
    }

}
