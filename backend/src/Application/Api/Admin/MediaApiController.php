<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Application\Media\AttachMediaToEntry;
use App\Application\Media\DeleteMediaSafely;
use App\Application\Media\GenerateMediaVariants;
use App\Application\Media\ListUnusedMedia;
use App\Application\Media\MediaFolderRepository;
use App\Application\Media\MediaHygieneReport;
use App\Application\Media\MediaSettingsRepository;
use App\Application\Media\UpdateMediaMetadata;
use App\Application\Media\UploadMediaAsset;
use App\Application\Media\Storage\MediaUrlGenerator;
use App\Core\Database;
use App\Core\ErrorCode;
use App\Core\Request;
use App\Core\Logger;
use App\Core\Response;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;

final class MediaApiController
{
    private GenerateMediaVariants $generateMediaVariants;
    private UploadMediaAsset $uploadMediaAsset;
    private UpdateMediaMetadata $updateMediaMetadata;
    private AttachMediaToEntry $attachMediaToEntry;
    private ListUnusedMedia $listUnusedMedia;
    private DeleteMediaSafely $deleteMediaSafely;
    private MediaFolderRepository $mediaFolders;
    private MediaHygieneReport $mediaHygiene;
    private MediaSettingsRepository $mediaSettings;
    private MediaUrlGenerator $mediaUrls;

    public function __construct(
        private readonly Request $request,
        private readonly Database $db,
        private readonly SiteRepository $sites,
        private readonly AuthRepository $auth,
        private readonly Authorization $authorization,
        private readonly Logger $logger,
    ) {
        $this->generateMediaVariants = new GenerateMediaVariants($db);
        $this->uploadMediaAsset = new UploadMediaAsset($db, $this->generateMediaVariants);
        $this->updateMediaMetadata = new UpdateMediaMetadata($db);
        $this->attachMediaToEntry = new AttachMediaToEntry($db);
        $this->listUnusedMedia = new ListUnusedMedia($db);
        $this->deleteMediaSafely = new DeleteMediaSafely($db);
        $this->mediaFolders = new MediaFolderRepository($db);
        $this->mediaHygiene = new MediaHygieneReport($db);
        $this->mediaSettings = new MediaSettingsRepository($db);
        $this->mediaUrls = new MediaUrlGenerator($db);
    }

    public function index(): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->authorization->require('media.read', (int) $site['id']);
        $lang = (string) ($this->request->query['lang'] ?? $site['default_language_code'] ?? 'fr');
        $limit = max(1, min(200, (int) ($this->request->query['limit'] ?? 80)));
        $status = (string) ($this->request->query['status'] ?? 'ready');
        $type = trim((string) ($this->request->query['type'] ?? ''));
        $q = trim((string) ($this->request->query['q'] ?? ''));
        $params = ['site_id' => (int) $site['id'], 'language' => $lang];
        $where = ['ma.site_id = :site_id'];
        if ($status !== 'all') { $where[] = 'ma.lifecycle_status = :status'; $params['status'] = $status; }
        if ($type !== '' && in_array($type, ['image','video','audio','document','binary'], true)) { $where[] = 'ma.media_type = :type'; $params['type'] = $type; }
        if ($q !== '') { $where[] = '(ma.filename LIKE :q OR ma.original_filename LIKE :q OR ma.mime_type LIKE :q OR mf.name LIKE :q OR COALESCE(mal.alt_text, "") LIKE :q OR COALESCE(mal.caption, "") LIKE :q)'; $params['q'] = '%' . $q . '%'; }
        $rows = $this->db->all(
            'SELECT ma.*, mf.folder_key, mf.name AS folder_name, mf.parent_id AS folder_parent_id,
                    mal.alt_text, mal.caption, mal.title,
                    (SELECT COUNT(*) FROM media_usages mu WHERE mu.media_id = ma.id AND mu.site_id = ma.site_id) AS usage_count
             FROM media_assets ma
             JOIN media_folders mf ON mf.id = ma.folder_id AND mf.site_id = ma.site_id
             LEFT JOIN media_asset_localizations mal ON mal.media_id = ma.id AND mal.language_code = :language
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY ma.created_at DESC, ma.id DESC
             LIMIT ' . $limit,
            $params
        );
        return Response::success(['assets' => array_map(fn(array $row): array => $this->decorateAsset($row), $rows)], 'admin.media.index.v1', ['site_id' => (int) $site['id'], 'language_code' => $lang, 'limit' => $limit]);
    }

    public function folders(): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->authorization->require('media.read', (int) $site['id']);
        $rows = $this->db->all(
            'SELECT mf.*, COUNT(ma.id) AS asset_count
             FROM media_folders mf
             LEFT JOIN media_assets ma ON ma.folder_id = mf.id AND ma.lifecycle_status != \'deleted\'
             WHERE mf.site_id = :site_id
             GROUP BY mf.id
             ORDER BY mf.parent_id IS NOT NULL, mf.parent_id, mf.sort_order, mf.name',
            ['site_id' => (int) $site['id']]
        );
        return Response::success($rows, 'admin.media.folders.index.v1', ['site_id' => (int) $site['id']]);
    }

    public function variantPresets(): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->authorization->require('media.read', (int) $site['id']);
        $this->generateMediaVariants->ensureNativePresets((int) $site['id']);
        $rows = $this->db->all('SELECT * FROM media_variant_presets WHERE site_id = :site_id ORDER BY is_active DESC, sort_order, preset_key', ['site_id' => (int) $site['id']]);
        return Response::success($rows, 'admin.media.variant_presets.index.v1', ['site_id' => (int) $site['id']]);
    }

    public function storeFolder(): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->authorization->require('media.update', (int) $site['id']);
        $payload = AdminApiContract::dataPayload($this->request, true);
        try {
            $folder = $this->mediaFolders->ensureFolder((int) $site['id'], (string) ($payload['folder_key'] ?? ''), isset($payload['name']) ? (string) $payload['name'] : null, isset($payload['parent_id']) ? (int) $payload['parent_id'] : null);
            return Response::success($folder, 'admin.media.folders.store.v1', ['site_id' => (int) $site['id']], 201);
        } catch (\InvalidArgumentException $e) { return Response::validation(['folder' => [$e->getMessage()]]); }
    }

    public function updateFolder(int $id): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->authorization->require('media.update', (int) $site['id']);
        $payload = AdminApiContract::dataPayload($this->request, true);
        try {
            $this->mediaFolders->requireFolder((int) $site['id'], $id);
            if (isset($payload['parent_id']) && (int) $payload['parent_id'] > 0) { $this->mediaFolders->assertFolderBelongsToSite((int) $site['id'], (int) $payload['parent_id']); }
            $sets = ['updated_at = CURRENT_TIMESTAMP']; $params = ['id' => $id, 'site_id' => (int) $site['id']];
            if (array_key_exists('name', $payload)) { $name = trim((string) $payload['name']); if ($name === '') { return Response::validation(['name' => ['Le nom du dossier est requis.']]); } $sets[] = 'name = :name'; $params['name'] = $name; }
            if (array_key_exists('parent_id', $payload)) { $parentId = (int) $payload['parent_id']; if ($parentId === $id) { return Response::validation(['parent_id' => ['Un dossier ne peut pas être son propre parent.']]); } $sets[] = 'parent_id = :parent_id'; $params['parent_id'] = $parentId > 0 ? $parentId : null; }
            if (array_key_exists('sort_order', $payload)) { $sets[] = 'sort_order = :sort_order'; $params['sort_order'] = (int) $payload['sort_order']; }
            $this->db->run('UPDATE media_folders SET ' . implode(', ', $sets) . ' WHERE id = :id AND site_id = :site_id', $params);
            return Response::success($this->mediaFolders->requireFolder((int) $site['id'], $id), 'admin.media.folders.update.v1', ['site_id' => (int) $site['id']]);
        } catch (\InvalidArgumentException $e) { return Response::validation(['folder' => [$e->getMessage()]]); }
    }


    public function hygiene(): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->authorization->require('media.read', (int) $site['id']);
        $lang = (string) ($this->request->query['lang'] ?? $site['default_language_code'] ?? 'fr');
        $limit = max(1, min(200, (int) ($this->request->query['limit'] ?? 60)));
        return Response::success(
            $this->mediaHygiene->execute((int) $site['id'], $lang, $limit),
            'admin.media.hygiene.v1',
            ['site_id' => (int) $site['id'], 'language_code' => $lang, 'limit' => $limit]
        );
    }

    public function settings(): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->authorization->require('media.read', (int) $site['id']);
        return Response::success(
            $this->mediaSettings->forSite((int) $site['id']),
            'admin.media.settings.v1',
            ['site_id' => (int) $site['id']]
        );
    }

    public function show(int $id): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->authorization->require('media.read', (int) $site['id']);
        $lang = (string) ($this->request->query['lang'] ?? $site['default_language_code'] ?? 'fr');
        $asset = $this->db->one(
            'SELECT ma.*, mf.folder_key, mf.name AS folder_name, mf.parent_id AS folder_parent_id,
                    mal.alt_text, mal.caption, mal.title, mal.is_alt_verified
             FROM media_assets ma
             JOIN media_folders mf ON mf.id = ma.folder_id AND mf.site_id = ma.site_id
             LEFT JOIN media_asset_localizations mal ON mal.media_id = ma.id AND mal.language_code = :language
             WHERE ma.id = :id AND ma.site_id = :site_id
             LIMIT 1',
            ['id' => $id, 'site_id' => (int) $site['id'], 'language' => $lang]
        );
        if (!$asset) { return Response::error(ErrorCode::MEDIA_NOT_FOUND, ErrorCode::message(ErrorCode::MEDIA_NOT_FOUND), ErrorCode::httpStatus(ErrorCode::MEDIA_NOT_FOUND), ['media_id' => $id, 'site_id' => (int) $site['id']]); }
        $variants = $this->db->all('SELECT mav.*, mvp.preset_key AS expected_preset_key, mvp.mode AS expected_mode, mvp.quality AS expected_quality FROM media_asset_variants mav LEFT JOIN media_variant_presets mvp ON mvp.id = mav.preset_id WHERE mav.media_id = :id ORDER BY mav.variant_key', ['id' => $id]);
        return Response::success([
            'asset' => $this->decorateAsset($asset, $variants),
            'localizations' => $this->db->all('SELECT * FROM media_asset_localizations WHERE media_id = :id ORDER BY language_code', ['id' => $id]),
            'variants' => array_map(fn(array $row): array => $this->decorateVariant($row, (int) $site['id'], (string) ($asset['storage_disk'] ?? 'local')), $variants),
            'variant_presets' => $this->db->all('SELECT * FROM media_variant_presets WHERE site_id = :site_id AND is_active = 1 ORDER BY sort_order, preset_key', ['site_id' => (int) $site['id']]),
            'usages' => $this->db->all(
                'SELECT mu.*, ce.entry_key, cel.title AS entry_title, cel.draft_full_path AS entry_path
                 FROM media_usages mu
                 LEFT JOIN content_entries ce ON ce.id = mu.resource_id AND mu.resource_type = \'content_entry\'
                 LEFT JOIN content_entry_localizations cel ON cel.entry_id = ce.id AND cel.language_code = mu.language_code
                 WHERE mu.media_id = :id AND mu.site_id = :site_id AND mu.language_code = :language
                 ORDER BY mu.updated_at DESC, mu.id DESC',
                ['id' => $id, 'site_id' => (int) $site['id'], 'language' => $lang]
            ),
        ], 'admin.media.show.v1', ['site_id' => (int) $site['id'], 'language_code' => $lang]);
    }

    public function store(): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->authorization->require('media.upload', (int) $site['id']);
        $file = $this->request->files['file'] ?? $this->request->files['media'] ?? null;
        if (!is_array($file)) { return Response::validation(['file' => ['Un fichier est requis.']]); }
        $metadata = $this->request->post; unset($metadata['csrf_token']);
        if (isset($metadata['metadata_json']) && is_string($metadata['metadata_json'])) { $decoded = json_decode($metadata['metadata_json'], true); if (is_array($decoded)) { $metadata = array_replace($metadata, $decoded); } }
        try {
            $result = $this->uploadMediaAsset->execute((int) $site['id'], $file, $metadata, $this->currentUserId());
            return Response::success($result, 'admin.media.upload.v1', ['site_id' => (int) $site['id']], $result['status'] === 'duplicate' ? 200 : 201);
        } catch (\InvalidArgumentException $e) {
            $this->logUploadFailure($site, $file, $e, 'validation');
            return Response::validation(['media' => [$e->getMessage()]]);
        } catch (\Throwable $e) {
            $this->logUploadFailure($site, $file, $e, 'runtime');
            return Response::error(
                ErrorCode::MEDIA_UPLOAD_FAILED,
                ErrorCode::message(ErrorCode::MEDIA_UPLOAD_FAILED),
                ErrorCode::httpStatus(ErrorCode::MEDIA_UPLOAD_FAILED),
                [
                    'reason' => $this->safeReason($e),
                    'stage' => $e instanceof \RuntimeException ? 'upload' : 'unexpected',
                    'request_id' => Response::requestId(),
                ]
            );
        }
    }

    public function update(int $id): Response
    {
        $this->auth->requireAuth(); $site = $this->site(); $this->authorization->require('media.update', (int) $site['id']);
        try {
            $result = $this->updateMediaMetadata->execute((int) $site['id'], $id, AdminApiContract::dataPayload($this->request, true), $this->currentUserId());
            return Response::success($result, 'admin.media.update_metadata.v1', ['site_id' => (int) $site['id']]);
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['media' => [$e->getMessage()]]);
        } catch (\Throwable $e) {
            $this->logMetadataFailure($site, $id, $e);
            return Response::error(
                ErrorCode::INTERNAL_SERVER_ERROR,
                ErrorCode::message(ErrorCode::INTERNAL_SERVER_ERROR),
                ErrorCode::httpStatus(ErrorCode::INTERNAL_SERVER_ERROR),
                [
                    'reason' => $this->safeReason($e),
                    'request_id' => Response::requestId(),
                ]
            );
        }
    }

    public function generateVariants(int $id): Response
    {
        $this->auth->requireAuth(); $site = $this->site(); $this->authorization->require('media.update', (int) $site['id']);
        try { return Response::success($this->generateMediaVariants->execute((int) $site['id'], $id), 'admin.media.generate_variants.v1', ['site_id' => (int) $site['id']]); }
        catch (\InvalidArgumentException $e) { return Response::validation(['media' => [$e->getMessage()]]); }
    }

    public function attach(int $id): Response
    {
        $this->auth->requireAuth(); $site = $this->site(); $this->authorization->require('media.update', (int) $site['id']);
        try { return Response::success($this->attachMediaToEntry->execute((int) $site['id'], $id, AdminApiContract::dataPayload($this->request, true)), 'admin.media.attach.v1', ['site_id' => (int) $site['id']]); }
        catch (\InvalidArgumentException $e) { return Response::validation(['media' => [$e->getMessage()]]); }
    }

    public function unused(): Response
    {
        $this->auth->requireAuth(); $site = $this->site(); $this->authorization->require('media.read', (int) $site['id']); $limit = max(1, min(500, (int) ($this->request->query['limit'] ?? 100)));
        return Response::success($this->listUnusedMedia->execute((int) $site['id'], $limit), 'admin.media.unused.v1', ['site_id' => (int) $site['id'], 'limit' => $limit]);
    }

    public function destroy(int $id): Response
    {
        $this->auth->requireAuth(); $site = $this->site(); $this->authorization->require('media.delete', (int) $site['id']);
        $payload = $this->request->json(); if (isset($payload['data']) && is_array($payload['data'])) { $payload = $payload['data']; }
        $force = filter_var($payload['force'] ?? $this->request->input('force', false), FILTER_VALIDATE_BOOL);
        try { return Response::success($this->deleteMediaSafely->execute((int) $site['id'], $id, $force), 'admin.media.delete_safe.v1', ['site_id' => (int) $site['id']]); }
        catch (\InvalidArgumentException $e) { return Response::validation(['media' => [$e->getMessage()]]); }
    }

    /** @param array<string,mixed> $asset @param list<array<string,mixed>> $variants */
    private function decorateAsset(array $asset, array $variants = []): array
    {
        $siteId = (int) ($asset['site_id'] ?? 0);
        $disk = (string) ($asset['storage_disk'] ?? 'local');
        $asset['public_url'] = $this->publicUrl((string) ($asset['public_path'] ?? $asset['path'] ?? ''), $siteId, $disk);
        if ($variants === []) { $variants = $this->db->all('SELECT * FROM media_asset_variants WHERE media_id = :id ORDER BY variant_key', ['id' => (int) $asset['id']]); }
        $decorated = array_map(fn(array $row): array => $this->decorateVariant($row, $siteId, $disk), $variants);
        $asset['variants'] = $decorated;
        $asset['responsive_sets'] = $this->responsiveSets($decorated);
        $thumb = $this->variantByPreference($decorated, ['content_480', 'thumb', 'content_768', 'original']);
        $asset['thumbnail_url'] = $thumb['public_url'] ?? $asset['public_url'];
        return $asset;
    }

    /** @param list<array<string,mixed>> $variants @return array<string,list<array<string,mixed>>> */
    private function responsiveSets(array $variants): array
    {
        $sets = ['content' => [], 'hero' => [], 'open_graph' => []];
        foreach ($variants as $variant) {
            $key = (string) ($variant['variant_key'] ?? '');
            if (str_starts_with($key, 'content_')) { $sets['content'][] = $variant; }
            if (str_starts_with($key, 'hero_')) { $sets['hero'][] = $variant; }
            if ($key === 'og_1200x630' || str_starts_with($key, 'open_graph_')) { $sets['open_graph'][] = $variant; }
        }
        foreach ($sets as $name => $items) {
            usort($items, fn(array $a, array $b): int => ((int) ($a['width'] ?? 0)) <=> ((int) ($b['width'] ?? 0)));
            $sets[$name] = $items;
        }
        return $sets;
    }

    /** @param list<array<string,mixed>> $variants @param list<string> $keys @return array<string,mixed> */
    private function variantByPreference(array $variants, array $keys): array
    {
        foreach ($keys as $key) {
            foreach ($variants as $variant) {
                if (($variant['variant_key'] ?? '') === $key) { return $variant; }
            }
        }
        return [];
    }

    /** @param array<string,mixed> $variant */
    private function decorateVariant(array $variant, int $siteId, string $disk): array
    {
        $variant['public_url'] = $this->publicUrl((string) ($variant['path'] ?? ''), $siteId, $disk);
        return $variant;
    }

    private function publicUrl(string $relativePath, int $siteId, string $disk): string
    {
        return $this->mediaUrls->publicUrl($relativePath, $siteId, $disk);
    }



    /** @param array<string,mixed> $site */
    private function logMetadataFailure(array $site, int $mediaId, \Throwable $e): void
    {
        $this->logger->error('media.metadata_update_failed', [
            'request_id' => Response::requestId(),
            'site_id' => (int) ($site['id'] ?? 0),
            'user_id' => $this->currentUserId(),
            'media_id' => $mediaId,
            'reason' => $this->safeReason($e),
            'exception' => $e::class,
        ]);
    }

    /** @param array<string,mixed> $site @param array<string,mixed> $file */
    private function logUploadFailure(array $site, array $file, \Throwable $e, string $stage): void
    {
        $this->logger->error('media.upload_failed', [
            'request_id' => Response::requestId(),
            'stage' => $stage,
            'site_id' => (int) ($site['id'] ?? 0),
            'user_id' => $this->currentUserId(),
            'filename' => isset($file['name']) ? basename((string) $file['name']) : null,
            'size' => isset($file['size']) ? (int) $file['size'] : null,
            'upload_error' => $file['error'] ?? null,
            'reason' => $this->safeReason($e),
            'exception' => $e::class,
        ]);
    }

    private function safeReason(\Throwable $e): string
    {
        $message = trim($e->getMessage());
        if ($message === '') { return $e::class; }
        return mb_substr(preg_replace('/[^A-Z0-9_:.\/-]+/i', ' ', $message) ?: $message, 0, 180);
    }

    /** @return array<string,mixed> */
    private function site(): array {
        $payload = [];
        if (in_array($this->request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && str_contains($this->request->contentType(), 'application/json')) {
            $json = $this->request->json();
            $payload = is_array($json['data'] ?? null) ? $json['data'] : (is_array($json) ? $json : []);
        }
        $siteId = isset($payload['site_id']) ? (int) $payload['site_id'] : (isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null);
        return AdminApiContract::siteContext($this->request, $this->sites, $siteId, $this->auth);
    }
    private function currentUserId(): ?int { $user = $this->auth->user(); return is_array($user) && isset($user['id']) ? (int) $user['id'] : null; }
}
