<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Application\Content\PublishContentEntry;
use App\Application\Content\SaveContentDraft;
use App\Application\Content\UnpublishContentEntry;
use App\Application\Content\ArchiveDeleteContentEntry;
use App\Application\VisualEditing\BlockEditingLockRepository;
use App\Core\ApiException;
use App\Core\ErrorCode;
use App\Core\Request;
use App\Core\Response;
use App\Repository\AuthRepository;
use App\Application\Content\Read\EditorialContentReadRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;
use App\Security\Csrf;
use App\Security\InputValidator;
use App\Service\ProjectionService;

final class ContentEntryApiController
{
    public function __construct(
        private readonly Request $request,
        private readonly EditorialContentReadRepository $content,
        private readonly SiteRepository $sites,
        private readonly AuthRepository $auth,
        private readonly Authorization $authorization,
        private readonly SaveContentDraft $saveContentDraft,
        private readonly PublishContentEntry $publishContentEntry,
        private readonly UnpublishContentEntry $unpublishContentEntry,
        private readonly ArchiveDeleteContentEntry $archiveDeleteContentEntry,
        private readonly ProjectionService $projections,
        private readonly BlockEditingLockRepository $blockLocks,
    ) {}

    public function index(): Response
    {
        $this->auth->requireAuth();
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null, $this->auth);
        $this->authorization->require('content.read', (int) $site['id']);
        $lang = AdminApiContract::language($this->request, $this->sites, $site);
        $type = isset($this->request->query['type']) ? InputValidator::key((string) $this->request->query['type']) : null;
        $status = isset($this->request->query['status']) ? InputValidator::key((string) $this->request->query['status']) : null;
        $q = InputValidator::string((string) ($this->request->query['q'] ?? ''), 120);
        $limit = max(1, min(100, (int) ($this->request->query['limit'] ?? 50)));
        $offset = max(0, (int) ($this->request->query['offset'] ?? 0));
        $sort = InputValidator::string((string) ($this->request->query['sort'] ?? '-updated_at'), 40);

        $page = $this->content->listEntriesPage([
            'site_id' => (int) $site['id'],
            'language_code' => $lang,
            'type' => $type,
            'status' => $status,
            'q' => $q,
            'limit' => $limit,
            'offset' => $offset,
            'sort' => $sort,
        ]);

        return Response::success($this->entryListContracts($page['data']), 'admin.entries.index.v1', AdminApiContract::meta($site, $lang, [
            'pagination' => [
                'total' => $page['total'],
                'limit' => $page['limit'],
                'offset' => $page['offset'],
                'has_more' => $page['has_more'],
            ],
        ]));
    }

    public function show(int $id): Response
    {
        $this->auth->requireAuth();
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null, $this->auth);
        $this->authorization->require('content.read', (int) $site['id']);
        $lang = AdminApiContract::language($this->request, $this->sites, $site);
        $entry = $this->loadEntryAggregate($id, $lang, $site);

        return Response::success($this->entryShowContract($entry, $site, $lang), 'admin.entries.show.v1', AdminApiContract::meta($site, $lang));
    }

    public function store(): Response
    {
        return $this->save(null);
    }

    public function update(int $id): Response
    {
        return $this->save($id);
    }

    public function publish(int $id): Response
    {
        $this->auth->requireAuth();
        $input = AdminApiContract::dataPayload($this->request, true);
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($input['site_id']) ? (int) $input['site_id'] : null, $this->auth);
        $this->authorization->require('content.publish', (int) $site['id']);
        $this->requireCsrf();
        $lang = AdminApiContract::language($this->request, $this->sites, $site, $input);
        $aggregate = $this->loadEntryAggregate($id, $lang, $site);

        $revisionId = (int) ($input['revision_id'] ?? 0);
        if ($revisionId < 1) {
            return Response::validation(['revision_id' => ['revision_id est obligatoire pour publier une version déterministe.']]);
        }

        $workingRevisionId = (int) (($aggregate['working_revision']['id'] ?? 0));
        $expectedWorkingRevisionId = (int) ($input['expected_working_revision_id'] ?? $revisionId);
        if ($workingRevisionId < 1 || $expectedWorkingRevisionId !== $workingRevisionId || $revisionId !== $workingRevisionId) {
            throw new ApiException(ErrorCode::REVISION_CONFLICT, ErrorCode::message(ErrorCode::REVISION_CONFLICT), 409, [
                'entry_id' => $id,
                'language_code' => $lang,
                'requested_revision_id' => $revisionId,
                'expected_working_revision_id' => $expectedWorkingRevisionId,
                'current_working_revision_id' => $workingRevisionId,
            ]);
        }

        $result = $this->publishContentEntry->execute($id, $lang, (int) ($this->auth->user()['id'] ?? 1), $revisionId);
        return Response::success([
            'entry_id' => $result->entryId,
            'language_code' => $result->languageCode,
            'published_revision_id' => $result->publishedRevisionId,
            'previous_published_revision_id' => $result->previousPublishedRevisionId,
            'published_by_user_id' => $result->publishedByUserId,
            'published_at' => $result->publishedAt,
            'status' => $result->entryStatus,
            'workflow_state' => $result->workflowState,
            'critical_projections' => [
                'mode' => 'synchronous_atomic',
                'status' => 'ready',
                'items' => $result->criticalProjectionsReady,
            ],
            'secondary_events' => $result->events,
        ], 'admin.entries.publish.v1', AdminApiContract::meta($site, $lang));
    }

    public function unpublish(int $id): Response
    {
        $this->auth->requireAuth();
        $input = AdminApiContract::dataPayload($this->request, true);
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($input['site_id']) ? (int) $input['site_id'] : null, $this->auth);
        $this->authorization->require('content.unpublish', (int) $site['id']);
        $this->requireCsrf();
        $lang = AdminApiContract::language($this->request, $this->sites, $site, $input);
        $this->loadEntryAggregate($id, $lang, $site);

        $result = $this->unpublishContentEntry->execute($id, $lang, (int) ($this->auth->user()['id'] ?? 1));
        return Response::success($result, 'admin.entries.unpublish.v1', AdminApiContract::meta($site, $lang));
    }


    public function archive(int $id): Response
    {
        $this->auth->requireAuth();
        $input = AdminApiContract::dataPayload($this->request, true);
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($input['site_id']) ? (int) $input['site_id'] : null, $this->auth);
        $this->authorization->require('content.archive', (int) $site['id']);
        $this->requireCsrf();
        $lang = AdminApiContract::language($this->request, $this->sites, $site, $input);
        $this->loadEntryAggregate($id, $lang, $site);

        $result = $this->archiveDeleteContentEntry->archive($id, (int) ($this->auth->user()['id'] ?? 1), [
            'language_code' => !empty($input['language_code']) ? $lang : null,
            'redirect_to' => (string) ($input['redirect_to'] ?? ''),
            'http_code' => (int) ($input['http_code'] ?? 301),
            'base_path' => (string) ($site['matched_request_base_path'] ?? $site['matched_base_path'] ?? $site['base_path'] ?? ''),
        ]);
        return Response::success($result, 'admin.entries.archive.v1', AdminApiContract::meta($site, $lang));
    }

    public function destroy(int $id): Response
    {
        $this->auth->requireAuth();
        $input = AdminApiContract::dataPayload($this->request, false);
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($input['site_id']) ? (int) $input['site_id'] : null, $this->auth);
        $this->authorization->require('content.delete', (int) $site['id']);
        $this->requireCsrf();
        $lang = AdminApiContract::language($this->request, $this->sites, $site, $input);
        $this->loadEntryAggregate($id, $lang, $site);

        $confirm = (string) ($input['confirm'] ?? '');
        if ($confirm !== 'DELETE') {
            return Response::validation(['confirm' => ['La suppression définitive exige confirm=DELETE.']]);
        }

        $result = $this->archiveDeleteContentEntry->delete($id, (int) ($this->auth->user()['id'] ?? 1), [
            'redirect_to' => (string) ($input['redirect_to'] ?? ''),
            'http_code' => (int) ($input['http_code'] ?? 301),
            'base_path' => (string) ($site['matched_request_base_path'] ?? $site['matched_base_path'] ?? $site['base_path'] ?? ''),
        ]);
        return Response::success($result, 'admin.entries.destroy.v1', AdminApiContract::meta($site, $lang));
    }

    private function save(?int $id): Response
    {
        $this->auth->requireAuth();
        $input = AdminApiContract::dataPayload($this->request, true);
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($input['site_id']) ? (int) $input['site_id'] : null, $this->auth);
        $this->authorization->require($id === null ? 'content.create' : 'content.revisions.save', (int) $site['id']);
        $this->requireCsrf();
        $lang = AdminApiContract::language($this->request, $this->sites, $site, $input);

        $existingContentTypeKey = null;
        if ($id !== null) {
            $aggregate = $this->loadEntryAggregate($id, $lang, $site);
            $existingContentTypeKey = (string) (($aggregate['entry']['type_key'] ?? $aggregate['entry']['content_type_key'] ?? '') ?: '');
            $expectedWorkingRevisionId = (int) ($input['expected_working_revision_id'] ?? 0);
            $currentWorkingRevisionId = (int) (($aggregate['working_revision']['id'] ?? 0));
            if ($expectedWorkingRevisionId > 0 && $currentWorkingRevisionId > 0 && $expectedWorkingRevisionId !== $currentWorkingRevisionId) {
                throw new ApiException(ErrorCode::REVISION_CONFLICT, ErrorCode::message(ErrorCode::REVISION_CONFLICT), 409, [
                    'entry_id' => $id,
                    'language_code' => $lang,
                    'expected_working_revision_id' => $expectedWorkingRevisionId,
                    'current_working_revision_id' => $currentWorkingRevisionId,
                ]);
            }
        }

        $contentTypeKey = InputValidator::key((string) ($existingContentTypeKey ?: ($input['content_type_key'] ?? $input['content_type'] ?? $input['type'] ?? 'page')));
        $fieldInput = is_array($input['fields'] ?? null) ? $input['fields'] : [];
        $seoInput = is_array($input['seo'] ?? null) ? $input['seo'] : [];
        if ($id === null && $contentTypeKey === 'article' && trim((string) ($fieldInput['author_name'] ?? '')) === '') {
            $defaultAuthorName = $this->currentUserDisplayName();
            if ($defaultAuthorName !== '') {
                $fieldInput['author_name'] = $defaultAuthorName;
            }
        }
        $value = static fn(string $key, mixed $default = ''): mixed => array_key_exists($key, $input)
            ? $input[$key]
            : (array_key_exists($key, $seoInput) ? $seoInput[$key] : ($fieldInput[$key] ?? $default));

        // Les formulaires générés par blueprint peuvent poster les champs système
        // dans `fields` (source UI unique) ou en racine (contrat historique). Le
        // back-office normalise ici vers le contrat d'écriture canonique afin que
        // title/slug/SEO restent stockés dans les colonnes et documents dédiés.
        $payload = [
            'entry_key' => (string) $value('entry_key', ''),
            'title' => (string) $value('title', ''),
            'slug' => (string) $value('slug', ''),
            'meta_title' => (string) $value('meta_title', $value('seo_title', '')),
            'meta_description' => (string) $value('meta_description', $value('seo_description', '')),
            'meta_robots' => (string) $value('meta_robots', $value('robots', 'index,follow')),
            'og_image_media_id' => $value('og_image_media_id', $value('og_image', 0)),
            'og_image_src' => (string) $value('og_image_src', ''),
            'twitter_image_media_id' => $value('twitter_image_media_id', 0),
            'twitter_image_src' => (string) $value('twitter_image_src', ''),
            'change_notes' => (string) ($input['change_notes'] ?? ''),
            'blocks' => is_array($input['blocks'] ?? null) ? $input['blocks'] : (is_array($fieldInput['blocks'] ?? null) ? $fieldInput['blocks'] : []),
            'taxonomy_terms' => is_array($input['taxonomy_terms'] ?? null) ? $input['taxonomy_terms'] : (is_array($input['taxonomies'] ?? null) ? $input['taxonomies'] : []),
        ];
        $payload['fields'] = $this->dynamicFieldPayload($fieldInput);

        if ($id !== null && isset($aggregate) && is_array($aggregate)) {
            $lockedBlock = $this->firstConflictingStructureLock($id, $lang, $aggregate, $payload['blocks'], (int) ($this->auth->user()['id'] ?? 0));
            if ($lockedBlock !== null) {
                throw new ApiException(
                    ErrorCode::RESOURCE_LOCKED,
                    'Ce bloc est verrouillé par un autre utilisateur.',
                    ErrorCode::httpStatus(ErrorCode::RESOURCE_LOCKED),
                    ['lock' => $lockedBlock]
                );
            }
        }

        $result = $this->saveContentDraft->execute(
            (int) $site['id'],
            $contentTypeKey,
            $lang,
            $payload,
            (int) ($this->auth->user()['id'] ?? 1),
            $id,
        );

        return Response::success([
            'entry_id' => $result->entryId,
            'revision_id' => $result->revisionId,
            'content_type_key' => $result->contentTypeKey,
            'language_code' => $result->languageCode,
            'created' => $result->created,
            'status' => 'draft',
            'editorial_state' => $id === null ? 'new_draft' : 'modified_since_publication',
            'publication_hint' => $id === null ? 'Brouillon enregistré.' : 'Révision enregistrée. Elle doit être publiée par un rôle autorisé pour devenir publique.',
            'next_actions' => [
                'show' => ['method' => 'GET', 'path' => '/admin/api/entries/' . $result->entryId],
                'publish' => ['method' => 'POST', 'path' => '/admin/api/entries/' . $result->entryId . '/publish', 'revision_id' => $result->revisionId],
            ],
        ], 'admin.entries.save_draft.v1', AdminApiContract::meta($site, $lang), $id === null ? 201 : 200);
    }



    private function currentUserDisplayName(): string
    {
        $user = $this->auth->user() ?? [];
        $name = trim((string) ($user['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $profile = $this->auth->currentUserProfile();
        $profileName = trim(((string) ($profile['first_name'] ?? '')) . ' ' . ((string) ($profile['last_name'] ?? '')));
        if ($profileName !== '') {
            return $profileName;
        }

        return trim((string) ($user['email'] ?? $profile['email'] ?? ''));
    }

    /** @param array<string,mixed> $fields @return array<string,mixed> */
    private function dynamicFieldPayload(array $fields): array
    {
        $reserved = [
            'entry_key' => true, 'title' => true, 'slug' => true,
            'meta_title' => true, 'meta_description' => true, 'meta_robots' => true,
            'seo_title' => true, 'seo_description' => true, 'robots' => true,
            'canonical_url' => true, 'og_title' => true, 'og_description' => true,
            'og_image' => true, 'og_image_media_id' => true,
            'twitter_title' => true, 'twitter_description' => true, 'twitter_image_media_id' => true,
            'status' => true, 'language' => true, 'site' => true,
        ];
        return array_filter(
            $fields,
            static fn(string|int $key): bool => is_string($key) && !isset($reserved[$key]),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Prevents the Structure editor from overwriting a block currently owned by
     * another editor through the shared block lock contract used by the Visual editor.
     *
     * @param array<string,mixed> $aggregate
     * @param list<mixed> $incomingBlocks
     * @return array<string,mixed>|null
     */
    private function firstConflictingStructureLock(int $entryId, string $lang, array $aggregate, array $incomingBlocks, int $userId): ?array
    {
        $currentBlocks = $this->blocksById($this->blocksFromWorkingRevision($aggregate));
        $nextBlocks = $this->blocksById(array_values(array_filter($incomingBlocks, 'is_array')));
        $changedBlockIds = [];

        foreach ($nextBlocks as $blockId => $nextBlock) {
            if (!isset($currentBlocks[$blockId]) || $this->canonicalBlock($currentBlocks[$blockId]) !== $this->canonicalBlock($nextBlock)) {
                $changedBlockIds[$blockId] = true;
            }
        }

        foreach ($currentBlocks as $blockId => $_currentBlock) {
            if (!isset($nextBlocks[$blockId])) {
                $changedBlockIds[$blockId] = true;
            }
        }

        foreach (array_keys($changedBlockIds) as $blockId) {
            $conflict = $this->blockLocks->conflictingLock($entryId, $lang, (string) $blockId, $userId);
            if ($conflict !== null) {
                return $conflict;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $aggregate @return list<array<string,mixed>> */
    private function blocksFromWorkingRevision(array $aggregate): array
    {
        $revision = is_array($aggregate['working_revision'] ?? null) ? $aggregate['working_revision'] : null;
        if (!$revision) {
            return [];
        }
        $document = json_decode((string) ($revision['document_json'] ?? '{}'), true);
        $blocks = is_array($document) && is_array($document['blocks'] ?? null) ? $document['blocks'] : [];
        return array_values(array_filter($blocks, 'is_array'));
    }

    /** @param list<array<string,mixed>> $blocks @return array<string,array<string,mixed>> */
    private function blocksById(array $blocks): array
    {
        $indexed = [];
        foreach ($blocks as $index => $block) {
            $blockId = trim((string) ($block['id'] ?? ''));
            if ($blockId === '') {
                $blockId = 'index:' . $index;
            }
            $indexed[$blockId] = $block;
        }
        return $indexed;
    }

    /** @param array<string,mixed> $block */
    private function canonicalBlock(array $block): string
    {
        ksort($block);
        return json_encode($block, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /** @return array<string,mixed> */
    private function loadEntryAggregate(int $id, string $lang, array $site): array
    {
        $entry = $this->content->getWorkingEntryAggregate($id, $lang);
        if (!$entry) {
            throw new ApiException(ErrorCode::CONTENT_ENTRY_NOT_FOUND, ErrorCode::message(ErrorCode::CONTENT_ENTRY_NOT_FOUND), 404, [
                'entry_id' => $id,
                'site_id' => (int) $site['id'],
                'language_code' => $lang,
            ]);
        }
        AdminApiContract::assertEntryBelongsToSite($entry['entry'] ?? [], $site);
        return $entry;
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function entryListContracts(array $rows): array
    {
        return array_map(fn(array $row): array => [
            'id' => (int) ($row['id'] ?? 0),
            'site_id' => (int) ($row['site_id'] ?? 0),
            'content_type_key' => (string) ($row['type_key'] ?? ''),
            'entry_key' => (string) ($row['entry_key'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'language_publication_status' => (string) ($row['language_publication_status'] ?? ''),
            'status_label' => $this->statusLabel((string) ($row['status'] ?? '')),
            'publication_status_label' => $this->statusLabel((string) ($row['language_publication_status'] ?? $row['status'] ?? '')),
            'block_editorial_statuses' => $this->blockEditorialStatuses((string) ($row['working_document_json'] ?? '')),
            'full_path' => $row['full_path'] ?? null,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'published_at' => $row['published_at'] ?? null,
        ], $rows);
    }

    /** @return array{total:int,draft:int,review:int,ready:int,published:int,archived:int} */
    private function blockEditorialStatuses(string $documentJson): array
    {
        $summary = ['total' => 0, 'draft' => 0, 'review' => 0, 'ready' => 0, 'published' => 0, 'archived' => 0];
        if ($documentJson === '') {
            return $summary;
        }
        $document = json_decode($documentJson, true);
        if (!is_array($document)) {
            return $summary;
        }
        $blocks = [];
        if (is_array($document['blocks'] ?? null)) {
            $blocks = $document['blocks'];
        } elseif (is_array($document['content'] ?? null) && is_array($document['content']['blocks'] ?? null)) {
            $blocks = $document['content']['blocks'];
        }
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $summary['total']++;
            $status = (string) ($block['editorial_status'] ?? 'published');
            if (!array_key_exists($status, $summary)) {
                $status = 'published';
            }
            $summary[$status]++;
        }
        return $summary;
    }

    /** @param array<string,mixed> $aggregate @param array<string,mixed> $site @return array<string,mixed> */
    private function entryShowContract(array $aggregate, array $site, string $languageCode): array
    {
        $entry = $aggregate['entry'] ?? [];
        $localization = $aggregate['localization'] ?? [];
        $workingRevision = $aggregate['working_revision'] ?? null;
        $publishedRevision = $aggregate['published_revision'] ?? null;
        $document = $this->revisionDocument(is_array($workingRevision) ? $workingRevision : (is_array($publishedRevision) ? $publishedRevision : null));
        $content = is_array($document['content'] ?? null) ? $document['content'] : [];
        $seo = is_array($document['seo'] ?? null) ? $document['seo'] : ($aggregate['seo'] ?? []);

        return [
            'entry' => [
                'id' => (int) ($entry['id'] ?? 0),
                'site_id' => (int) ($entry['site_id'] ?? 0),
                'content_type_key' => (string) ($entry['type_key'] ?? ''),
                'entry_key' => (string) ($entry['entry_key'] ?? ''),
                'status' => (string) ($entry['status'] ?? ''),
                'workflow_state' => (string) ($entry['workflow_state'] ?? ''),
                'is_active' => (bool) ($entry['is_active'] ?? true),
                'is_archived' => (string) ($entry['status'] ?? '') === 'archived',
                'created_at' => AdminApiContract::utcToApplicationDateTime((string) ($entry['created_at'] ?? '')),
                'updated_at' => AdminApiContract::utcToApplicationDateTime((string) ($entry['updated_at'] ?? '')),
                'published_at' => isset($entry['published_at']) ? AdminApiContract::utcToApplicationDateTime((string) $entry['published_at']) : null,
                'created_at_utc' => (string) ($entry['created_at'] ?? ''),
                'updated_at_utc' => (string) ($entry['updated_at'] ?? ''),
                'published_at_utc' => $entry['published_at'] ?? null,
            ],
            'localization' => [
                'language_code' => $languageCode,
                'title' => (string) ($content['title'] ?? $localization['title'] ?? ''),
                'slug' => (string) ($content['slug'] ?? $localization['draft_slug'] ?? $localization['slug'] ?? ''),
                'blocks' => is_array($document['blocks'] ?? null) ? $document['blocks'] : (is_array($content['blocks'] ?? null) ? $content['blocks'] : []),
            ],
            'fields' => is_array($document['fields'] ?? null) ? $document['fields'] : ($aggregate['fields'] ?? (object) []),
            'seo' => [
                'meta_title' => (string) ($seo['meta_title'] ?? ''),
                'meta_description' => (string) ($seo['meta_description'] ?? ''),
                'meta_robots' => (string) ($seo['meta_robots'] ?? 'index,follow'),
                'canonical_url' => (string) ($seo['canonical_url'] ?? ''),
                'og_image_media_id' => $this->positiveInt($seo['og_image_media_id'] ?? null),
                'og_image_src' => (string) ($seo['og_image_src'] ?? ''),
                'twitter_image_media_id' => $this->positiveInt($seo['twitter_image_media_id'] ?? null),
                'twitter_image_src' => (string) ($seo['twitter_image_src'] ?? ''),
                'status' => $this->seoStatus($seo),
            ],
            'editorial_summary' => $this->editorialSummary($entry, $localization, $document, $aggregate),
            'ui_hints' => $this->editorUiHints($entry, $localization, $document, $aggregate),
            'publication' => $this->publicationContract($aggregate['publication'] ?? null),
            'working_revision' => AdminApiContract::revisionSummary(is_array($workingRevision) ? $workingRevision : null),
            'published_revision' => AdminApiContract::revisionSummary(is_array($publishedRevision) ? $publishedRevision : null),
            'revisions' => AdminApiContract::revisionList($aggregate['revisions'] ?? []),
            'routes' => $this->routeContracts($aggregate['routes'] ?? []),
            'taxonomies' => $aggregate['taxonomies'] ?? [],
            'locks' => $aggregate['locks'] ?? [],
            'permissions' => AdminApiContract::permissionsBlock($this->auth, (int) $site['id'], ['content.read', 'content.revisions.save', 'content.publish', 'content.unpublish', 'content.archive', 'content.delete']),
        ];
    }



    /** @return array{state:string,label:string,warnings:list<string>} */
    private function seoStatus(array $seo): array
    {
        $warnings = [];
        $title = trim((string) ($seo['meta_title'] ?? ''));
        $description = trim((string) ($seo['meta_description'] ?? ''));
        $robots = trim((string) ($seo['meta_robots'] ?? 'index,follow'));
        if ($title === '') {
            $warnings[] = 'Titre SEO manquant.';
        }
        if ($description === '') {
            $warnings[] = 'Meta description manquante.';
        }
        if (str_contains(strtolower($robots), 'noindex')) {
            $warnings[] = 'La page est marquée noindex.';
        }
        return [
            'state' => $warnings === [] ? 'ok' : 'warning',
            'label' => $warnings === [] ? 'SEO prêt' : 'SEO à vérifier',
            'warnings' => $warnings,
        ];
    }

    /** @return array<string,mixed> */
    private function editorialSummary(array $entry, array $localization, array $document, array $aggregate): array
    {
        $workingRevision = is_array($aggregate['working_revision'] ?? null) ? $aggregate['working_revision'] : null;
        $publishedRevision = is_array($aggregate['published_revision'] ?? null) ? $aggregate['published_revision'] : null;
        $publication = is_array($aggregate['publication'] ?? null) ? $aggregate['publication'] : null;
        $blocks = is_array($document['blocks'] ?? null) ? $document['blocks'] : [];
        $status = (string) ($entry['status'] ?? 'draft');
        return [
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'title_missing' => trim((string) ($localization['title'] ?? $document['content']['title'] ?? '')) === '',
            'slug_missing' => trim((string) ($localization['draft_slug'] ?? $localization['slug'] ?? $document['content']['slug'] ?? '')) === '',
            'block_count' => count(array_filter($blocks, 'is_array')),
            'has_working_revision' => $workingRevision !== null,
            'has_published_revision' => $publishedRevision !== null,
            'published_revision_id' => $publication ? (int) ($publication['published_revision_id'] ?? 0) : 0,
            'can_preview' => $workingRevision !== null || $publishedRevision !== null,
            'can_publish' => $workingRevision !== null,
        ];
    }

    /** @return array<string,mixed> */
    private function editorUiHints(array $entry, array $localization, array $document, array $aggregate): array
    {
        $summary = $this->editorialSummary($entry, $localization, $document, $aggregate);
        $messages = [];
        if ($summary['title_missing']) {
            $messages[] = 'Ajoutez un titre avant publication.';
        }
        if ($summary['slug_missing']) {
            $messages[] = 'Ajoutez ou régénérez un slug avant publication.';
        }
        if ($summary['block_count'] < 1) {
            $messages[] = 'Aucun bloc éditorial n’est encore présent.';
        }
        if (!$summary['has_working_revision']) {
            $messages[] = 'Aucune révision de travail disponible pour l’aperçu.';
        }
        return [
            'draft_badge' => $summary['has_working_revision'] ? 'Brouillon disponible' : 'Aucun brouillon',
            'publication_badge' => $summary['has_published_revision'] ? 'Publié' : 'Non publié',
            'preview_label' => $summary['can_preview'] ? 'Prévisualiser la version de travail' : 'Aperçu indisponible',
            'publish_label' => $summary['can_publish'] ? 'Publier cette révision' : 'Publication indisponible',
            'messages' => $messages,
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'draft' => 'Brouillon',
            'review' => 'En révision',
            'ready' => 'Prêt à publier',
            'published' => 'Publié',
            'archived' => 'Archivé',
            'unpublished' => 'Dépublié',
            default => $status !== '' ? ucfirst(str_replace('_', ' ', $status)) : 'Statut inconnu',
        };
    }

    private function positiveInt(mixed $value): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : 0;
    }

    /** @param array<string,mixed>|null $row @return array<string,mixed>|null */
    private function publicationContract(?array $row): ?array
    {
        if (!$row) { return null; }
        return [
            'entry_id' => (int) ($row['entry_id'] ?? 0),
            'language_code' => (string) ($row['language_code'] ?? ''),
            'published_revision_id' => (int) ($row['published_revision_id'] ?? 0),
            'workflow_status' => (string) ($row['workflow_status'] ?? ''),
            'published_at' => (string) ($row['published_at'] ?? ''),
            'published_by_user_id' => isset($row['published_by_user_id']) ? (int) $row['published_by_user_id'] : null,
        ];
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function routeContracts(array $rows): array
    {
        return array_map(static fn(array $row): array => [
            'id' => (int) ($row['id'] ?? 0),
            'language_code' => (string) ($row['language_code'] ?? ''),
            'full_path' => (string) ($row['full_path'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'is_primary' => (bool) ($row['is_primary'] ?? false),
            'is_canonical' => (bool) ($row['is_canonical'] ?? false),
        ], $rows);
    }

    /** @param array<string,mixed>|null $revisionRow @return array<string,mixed> */
    private function revisionDocument(?array $revisionRow): array
    {
        if (!$revisionRow) { return []; }
        $document = json_decode((string) ($revisionRow['document_json'] ?? '{}'), true);
        return is_array($document) ? $document : [];
    }

    private function requireCsrf(): void
    {
        if (!Csrf::verifyHeader($this->request->header(AdminApiContract::HEADER_CSRF))) {
            throw new ApiException(ErrorCode::CSRF_TOKEN_REJECTED, ErrorCode::message(ErrorCode::CSRF_TOKEN_REJECTED), 403);
        }
    }
}
