<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Content\GeneratePreviewUrl;
use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Core\ErrorCode;
use App\Core\Request;
use App\Core\Response;
use App\Repository\AuthRepository;
use App\Application\Content\Read\EditorialContentReadRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;

final class PreviewApiController
{
    public function __construct(
        private readonly Request $request,
        private readonly EditorialContentReadRepository $content,
        private readonly SiteRepository $sites,
        private readonly AuthRepository $auth,
        private readonly Authorization $authorization,
        private readonly GeneratePreviewUrl $generatePreviewUrl,
    ) {}

    public function show(int $id): Response
    {
        $this->auth->requireAuth();
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null, $this->auth);
        $this->authorization->require('content.preview', (int) $site['id']);
        $lang = AdminApiContract::language($this->request, $this->sites, $site);
        $revisionId = isset($this->request->query['revision_id']) ? (int) $this->request->query['revision_id'] : null;
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
            'preview_url' => $this->generatePreviewUrl->execute($id, (int) ($aggregate['revision']['id'] ?? $revisionId ?? 0), $lang, (int) $site['id'], (string) ($site['matched_request_base_path'] ?? $site['matched_base_path'] ?? '')),
            'preview' => $aggregate['preview'] ?? [],
        ], 'admin.entries.preview.v1', AdminApiContract::meta($site, $lang));
    }
}
