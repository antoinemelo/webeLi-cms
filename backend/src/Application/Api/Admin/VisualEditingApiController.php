<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Application\Content\GeneratePreviewUrl;
use App\Application\VisualEditing\BlockEditingLockRepository;
use App\Application\VisualEditing\SaveVisualField;
use App\Application\VisualEditing\VisualEditingMapBuilder;
use App\Core\ErrorCode;
use App\Core\Request;
use App\Core\Response;
use App\Repository\AuthRepository;
use App\Application\Content\Read\EditorialContentReadRepository;
use App\Application\Content\Read\PublicContentReadRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;
use App\Security\Csrf;

final class VisualEditingApiController
{
    public function __construct(
        private readonly Request $request,
        private readonly EditorialContentReadRepository $content,
        private readonly PublicContentReadRepository $publicContent,
        private readonly SiteRepository $sites,
        private readonly AuthRepository $auth,
        private readonly Authorization $authorization,
        private readonly VisualEditingMapBuilder $mapBuilder,
        private readonly SaveVisualField $saveVisualField,
        private readonly GeneratePreviewUrl $generatePreviewUrl,
        private readonly BlockEditingLockRepository $blockLocks,
    ) {}

    public function map(int $id): Response
    {
        $this->auth->requireAuth();
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null, $this->auth);
        $this->authorization->require('content.read', (int) $site['id']);
        $lang = AdminApiContract::language($this->request, $this->sites, $site);
        $aggregate = $this->content->getWorkingEntryAggregate($id, $lang);
        if (!$aggregate || (int) ($aggregate['entry']['site_id'] ?? 0) !== (int) $site['id']) {
            return Response::error(ErrorCode::PUBLIC_CONTENT_NOT_FOUND, ErrorCode::message(ErrorCode::PUBLIC_CONTENT_NOT_FOUND), ErrorCode::httpStatus(ErrorCode::PUBLIC_CONTENT_NOT_FOUND));
        }
        return Response::success($this->mapBuilder->build($aggregate, (int) $site['id'], $lang), 'admin.visual.map.v1', AdminApiContract::meta($site, $lang));
    }

    public function preview(int $id): Response
    {
        $this->auth->requireAuth();
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null, $this->auth);
        $this->authorization->require('content.preview', (int) $site['id']);
        $lang = AdminApiContract::language($this->request, $this->sites, $site);
        $revisionId = isset($this->request->query['revision_id']) ? (int) $this->request->query['revision_id'] : null;
        $aggregate = $this->content->previewAggregate($id, $lang, $revisionId, (int) $site['id']);
        if (!$aggregate) {
            return Response::error(ErrorCode::PREVIEW_NOT_FOUND, ErrorCode::message(ErrorCode::PREVIEW_NOT_FOUND), ErrorCode::httpStatus(ErrorCode::PREVIEW_NOT_FOUND));
        }
        $url = $this->generatePreviewUrl->execute($id, (int) ($aggregate['revision']['id'] ?? $revisionId ?? 0), $lang, (int) $site['id'], (string) ($site['matched_request_base_path'] ?? $site['matched_base_path'] ?? ''));
        $url .= (str_contains($url, '?') ? '&' : '?') . 'visual=1';
        return Response::success([
            'entry_id' => $id,
            'language_code' => $lang,
            'revision_id' => (int) ($aggregate['revision']['id'] ?? 0),
            'preview_url' => $url,
        ], 'admin.visual.preview.v1', AdminApiContract::meta($site, $lang));
    }


    public function resolve(): Response
    {
        $this->auth->requireAuth();
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null, $this->auth);
        $this->authorization->require('content.preview', (int) $site['id']);
        $lang = AdminApiContract::language($this->request, $this->sites, $site);
        $path = $this->normaliseNavigationPath((string) ($this->request->query['url'] ?? $this->request->query['path'] ?? ''), $site, $lang);
        if ($path === '') {
            return Response::error(ErrorCode::PUBLIC_CONTENT_NOT_FOUND, 'URL interne introuvable pour l’édition visuelle.', 404);
        }

        $aggregate = $this->publicContent->getPublishedByPath((int) $site['id'], $path, $lang);
        if (!$aggregate && $path !== '/') {
            $aggregate = $this->publicContent->getPublishedByPath((int) $site['id'], '/' . trim($path, '/'), $lang);
        }
        if (!$aggregate) {
            return Response::error(ErrorCode::PUBLIC_CONTENT_NOT_FOUND, 'Cette URL ne correspond pas à une page ou à un article publié dans cette langue.', 404, [
                'path' => $path,
                'language_code' => $lang,
            ]);
        }

        $entry = is_array($aggregate['entry'] ?? null) ? $aggregate['entry'] : [];
        return Response::success([
            'entry_id' => (int) ($entry['id'] ?? 0),
            'content_type_key' => (string) ($entry['type_key'] ?? $entry['content_type_key'] ?? 'page'),
            'entry_key' => (string) ($entry['entry_key'] ?? ''),
            'language_code' => $lang,
            'path' => $path,
        ], 'admin.visual.resolve.v1', AdminApiContract::meta($site, $lang));
    }


    public function blockLocks(int $id): Response
    {
        $this->auth->requireAuth();
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null, $this->auth);
        $this->authorization->require('content.read', (int) $site['id']);
        $lang = AdminApiContract::language($this->request, $this->sites, $site);
        $aggregate = $this->content->getWorkingEntryAggregate($id, $lang);
        if (!$aggregate || (int) ($aggregate['entry']['site_id'] ?? 0) !== (int) $site['id']) {
            return Response::error(ErrorCode::PUBLIC_CONTENT_NOT_FOUND, ErrorCode::message(ErrorCode::PUBLIC_CONTENT_NOT_FOUND), ErrorCode::httpStatus(ErrorCode::PUBLIC_CONTENT_NOT_FOUND));
        }
        return Response::success([
            'entry_id' => $id,
            'language_code' => $lang,
            'locks' => $this->blockLocks->listForEntry($id, $lang, (int) ($this->auth->user()['id'] ?? 0)),
        ], 'admin.visual.block_locks.v1', AdminApiContract::meta($site, $lang));
    }

    public function acquireBlockLock(int $id): Response
    {
        $this->auth->requireAuth();
        $input = AdminApiContract::dataPayload($this->request, true);
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($input['site_id']) ? (int) $input['site_id'] : null, $this->auth);
        $this->authorization->require('content.update', (int) $site['id']);
        $this->requireCsrf();
        $lang = AdminApiContract::language($this->request, $this->sites, $site, $input);
        $aggregate = $this->content->getWorkingEntryAggregate($id, $lang);
        if (!$aggregate || (int) ($aggregate['entry']['site_id'] ?? 0) !== (int) $site['id']) {
            return Response::error(ErrorCode::PUBLIC_CONTENT_NOT_FOUND, ErrorCode::message(ErrorCode::PUBLIC_CONTENT_NOT_FOUND), ErrorCode::httpStatus(ErrorCode::PUBLIC_CONTENT_NOT_FOUND));
        }
        $blockId = trim((string) ($input['block_id'] ?? ''));
        if ($blockId === '') {
            return Response::error(ErrorCode::VALIDATION_FAILED, 'Bloc manquant pour le verrouillage visuel.', 422, ['fields' => ['block_id' => ['Le bloc est obligatoire.']]]);
        }
        $user = $this->auth->user() ?? [];
        $lock = $this->blockLocks->acquire($id, $lang, $blockId, (int) ($user['id'] ?? 0), (string) ($user['email'] ?? ''), isset($input['lock_token']) ? (string) $input['lock_token'] : null);
        $status = !empty($lock['acquired']) ? 200 : ErrorCode::httpStatus(ErrorCode::RESOURCE_LOCKED);
        if (empty($lock['acquired'])) {
            return Response::error(ErrorCode::RESOURCE_LOCKED, 'Ce bloc est déjà en cours d’édition par un autre utilisateur.', $status, ['lock' => $lock]);
        }
        return Response::success([
            'entry_id' => $id,
            'language_code' => $lang,
            'lock' => $lock,
        ], 'admin.visual.block_lock.v1', AdminApiContract::meta($site, $lang));
    }

    public function releaseBlockLock(int $id): Response
    {
        $this->auth->requireAuth();
        $input = AdminApiContract::dataPayload($this->request, true);
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($input['site_id']) ? (int) $input['site_id'] : null, $this->auth);
        $this->authorization->require('content.update', (int) $site['id']);
        $this->requireCsrf();
        $lang = AdminApiContract::language($this->request, $this->sites, $site, $input);
        $blockId = trim((string) ($input['block_id'] ?? ''));
        if ($blockId !== '') {
            $this->blockLocks->release($id, $lang, $blockId, (int) ($this->auth->user()['id'] ?? 0), isset($input['lock_token']) ? (string) $input['lock_token'] : null);
        }
        return Response::success([
            'entry_id' => $id,
            'language_code' => $lang,
            'released' => $blockId !== '',
            'block_id' => $blockId,
        ], 'admin.visual.block_lock.v1', AdminApiContract::meta($site, $lang));
    }

    public function saveField(int $id): Response
    {
        $this->auth->requireAuth();
        $input = AdminApiContract::dataPayload($this->request, true);
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($input['site_id']) ? (int) $input['site_id'] : null, $this->auth);
        $this->authorization->require('content.update', (int) $site['id']);
        $this->requireCsrf();
        $lang = AdminApiContract::language($this->request, $this->sites, $site, $input);
        $aggregate = $this->content->getWorkingEntryAggregate($id, $lang);
        if (!$aggregate || (int) ($aggregate['entry']['site_id'] ?? 0) !== (int) $site['id']) {
            return Response::error(ErrorCode::PUBLIC_CONTENT_NOT_FOUND, ErrorCode::message(ErrorCode::PUBLIC_CONTENT_NOT_FOUND), ErrorCode::httpStatus(ErrorCode::PUBLIC_CONTENT_NOT_FOUND));
        }
        $expected = (int) ($input['expected_working_revision_id'] ?? 0);
        $current = (int) (($aggregate['working_revision']['id'] ?? 0));
        if ($expected > 0 && $current > 0 && $expected !== $current) {
            return Response::error(ErrorCode::REVISION_CONFLICT, ErrorCode::message(ErrorCode::REVISION_CONFLICT), 409, [
                'entry_id' => $id,
                'language_code' => $lang,
                'expected_working_revision_id' => $expected,
                'current_working_revision_id' => $current,
            ]);
        }
        $target = is_array($input['target'] ?? null) ? $input['target'] : [];
        $blockId = trim((string) ($target['block_id'] ?? ''));
        if ($blockId !== '') {
            $conflict = $this->blockLocks->conflictingLock($id, $lang, $blockId, (int) ($this->auth->user()['id'] ?? 0), isset($input['lock_token']) ? (string) $input['lock_token'] : null);
            if ($conflict) {
                return Response::error(ErrorCode::RESOURCE_LOCKED, 'Ce bloc est verrouillé par un autre utilisateur.', ErrorCode::httpStatus(ErrorCode::RESOURCE_LOCKED), ['lock' => $conflict]);
            }
        }
        $result = $this->saveVisualField->execute((int) $site['id'], $id, $lang, $aggregate, $input, (int) ($this->auth->user()['id'] ?? 1));
        return Response::success($result, 'admin.visual.save_field.v1', AdminApiContract::meta($site, $lang));
    }

    public function translationStatus(int $id): Response
    {
        $this->auth->requireAuth();
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null, $this->auth);
        $this->authorization->require('content.read', (int) $site['id']);
        $lang = AdminApiContract::language($this->request, $this->sites, $site);
        $languages = $this->sites->getLanguages((int) $site['id']);
        $items = [];
        foreach ($languages as $language) {
            $code = strtolower((string) ($language['language_code'] ?? $language['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $aggregate = $this->content->getWorkingEntryAggregate($id, $code);
            $loc = is_array($aggregate['localization'] ?? null) ? $aggregate['localization'] : [];
            $seo = is_array($aggregate['seo'] ?? null) ? $aggregate['seo'] : [];
            $revision = is_array($aggregate['working_revision'] ?? null) ? $aggregate['working_revision'] : null;
            $document = $revision ? (json_decode((string) ($revision['document_json'] ?? '{}'), true) ?: []) : [];
            $blocks = is_array($document['blocks'] ?? null) ? $document['blocks'] : [];
            $missing = [];
            if (trim((string) ($loc['title'] ?? '')) === '') { $missing[] = 'title'; }
            if (trim((string) ($loc['slug'] ?? $loc['draft_slug'] ?? '')) === '') { $missing[] = 'slug'; }
            if (trim((string) ($seo['meta_title'] ?? $document['seo']['meta_title'] ?? '')) === '') { $missing[] = 'meta_title'; }
            if (trim((string) ($seo['meta_description'] ?? $document['seo']['meta_description'] ?? '')) === '') { $missing[] = 'meta_description'; }
            if ($blocks === []) { $missing[] = 'blocks'; }
            $status = $missing === [] ? 'complete' : ($revision ? 'partial' : 'missing');
            // In the visual editor, the multilingual completion timestamp must reflect
            // the latest saved working revision. `localized_at` is a historical localization
            // marker and may stay unchanged while fields continue to be edited.
            $completedAt = $status === 'complete'
                ? (string) ($revision['updated_at'] ?? $revision['created_at'] ?? $loc['localized_at'] ?? '')
                : null;
            $items[] = [
                'language_code' => $code,
                'label' => (string) ($language['name'] ?? strtoupper($code)),
                'is_current' => $code === strtolower($lang),
                'has_draft' => $revision !== null,
                'working_revision_id' => (int) ($revision['id'] ?? 0),
                'published_revision_id' => (int) (($aggregate['publication']['published_revision_id'] ?? 0)),
                'missing' => $missing,
                'status' => $status,
                'completed_at' => $completedAt !== '' ? $completedAt : null,
            ];
        }
        return Response::success(['entry_id' => $id, 'site_id' => (int) $site['id'], 'language_code' => $lang, 'languages' => $items], 'admin.visual.translation_status.v1', AdminApiContract::meta($site, $lang));
    }


    /** @param array<string,mixed> $site */
    private function normaliseNavigationPath(string $url, array $site, string $languageCode): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        $path = is_array($parts) ? (string) ($parts['path'] ?? $url) : $url;
        if ($path === '') {
            $path = '/';
        }
        $path = '/' . ltrim($path, '/');

        $basePath = trim((string) ($site['base_path'] ?? ''), '/');
        if ($basePath !== '') {
            $prefix = '/' . $basePath;
            if ($path === $prefix) {
                $path = '/';
            } elseif (str_starts_with($path, $prefix . '/')) {
                $path = substr($path, strlen($prefix)) ?: '/';
            }
        }

        $lang = strtolower(trim($languageCode));
        if ($lang !== '') {
            $prefix = '/' . $lang;
            if ($path === $prefix) {
                $path = '/';
            } elseif (str_starts_with($path, $prefix . '/')) {
                $path = substr($path, strlen($prefix)) ?: '/';
            }
        }

        $path = preg_replace('~/+~', '/', $path) ?: '/';
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private function requireCsrf(): void
    {
        $token = $this->request->header('X-CSRF-Token')
            ?? $this->request->header('X-Csrf-Token')
            ?? (is_string($this->request->input('_csrf_token')) ? $this->request->input('_csrf_token') : null);

        if (!Csrf::verifyHeader($token)) {
            throw new \App\Core\ApiException(
                ErrorCode::CSRF_TOKEN_REJECTED,
                ErrorCode::message(ErrorCode::CSRF_TOKEN_REJECTED),
                ErrorCode::httpStatus(ErrorCode::CSRF_TOKEN_REJECTED)
            );
        }
    }
}
