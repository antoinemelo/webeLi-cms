<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Service\OutboxService;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;
use App\StaticExport\SqlStaticExportSource;
use App\StaticExport\StaticAssetCollector;
use App\StaticExport\StaticExportManifestWriter;
use App\StaticExport\StaticExportReleaseRepository;
use App\StaticExport\StaticExportRenderer;
use App\StaticExport\StaticExportRouteCollector;
use App\StaticExport\StaticExportService;
use App\StaticExport\StaticFormRenderer;
use App\EditorialPackage\EditorialExportService;
use App\EditorialPackage\EditorialImportArchiveInspector;
use App\EditorialPackage\EditorialImportInspectionException;
use App\EditorialPackage\EditorialImportPlanner;
use App\EditorialPackage\EditorialImportExecutor;
use App\EditorialPackage\EditorialImportHistoryRepository;
use App\EditorialPackage\EditorialImportStagingService;
use App\EditorialPackage\EditorialPackageJson;
use App\Application\Content\PublishContentEntry;
use App\Application\Media\GenerateMediaVariants;
use App\Application\Media\Storage\StorageDriverFactory;
use App\Application\Forms\FormRepository;
use App\Application\Frontend\ResolvePublicRoute;

final class ImportsExportsApiController
{
    private const CONTRACT = 'admin.imports_exports.v1';

    public function __construct(
        private readonly Request $request,
        private readonly array $config,
        private readonly Database $coreDb,
        private readonly ?Database $formsDb,
        private readonly SiteRepository $sites,
        private readonly AuthRepository $auth,
        private readonly Authorization $authorization,
        private readonly ResolvePublicRoute $resolver,
        private readonly OutboxService $outbox,
        private readonly PublishContentEntry $publisher,
    ) {}

    public function index(): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->requireAnyPermission(['imports_exports.read', 'imports_exports.write', 'imports_exports.manage'], (int) $site['id']);
        $languageCode = AdminApiContract::language($this->request, $this->sites, $site);
        $repo = $this->releaseRepository();
        (new EditorialImportStagingService())->purgeExpired();
        $siteKey=(string)($site['site_key']??$site['handle']??'');
        $history = $repo->list((int) ($this->request->query['limit'] ?? 12), $siteKey);

        $canWrite = $this->hasAnyPermission(['imports_exports.write', 'imports_exports.manage'], (int) $site['id']);
        $canManage = $this->auth->hasPermission('imports_exports.manage', (int) $site['id']);
        if (!$canWrite) {
            $history = array_map([$this, 'readonlyReleaseSummary'], $history);
        }

        return Response::success([
            'label' => 'Imports / Exports',
            'placement' => 'Production éditoriale > Imports / Exports',
            'default_trigger' => 'manual',
            'statuses' => ['pending', 'running', 'succeeded', 'partial', 'failed', 'skipped'],
            'last_export' => $canWrite ? $repo->latest($siteKey) : null,
            'history' => $history,
            'import_history' => array_map($canWrite ? static fn(array $row): array => $row : [$this, 'readonlyImportSummary'], (new EditorialImportHistoryRepository())->list(20, (int)$site['id'])),
            'suggestions' => $canWrite ? $this->suggestions($site, $languageCode) : null,
            'configuration' => $canWrite ? $this->configuration() : null,
            'import' => $canWrite ? [
                'endpoint' => '/admin/api/imports-exports/imports/inspect',
                'execute_endpoint' => '/admin/api/imports-exports/imports/execute',
                'accepted_format' => 'Editorial Package',
                'format_version' => '1.0',
                'dry_run_only' => false,
                'archive_retained' => false,
            ] : null,
            'capabilities' => [
                'read_history' => true,
                'write' => $canWrite,
                'manage' => $canManage,
                'import_available' => $canWrite,
            ],
            'actions' => $canWrite ? [
                ['key' => 'page', 'label' => 'Exporter cette page', 'method' => 'POST', 'endpoint' => '/admin/api/imports-exports/static/actions/page'],
                ['key' => 'language', 'label' => 'Exporter la langue', 'method' => 'POST', 'endpoint' => '/admin/api/imports-exports/static/actions/language'],
                ['key' => 'site', 'label' => 'Exporter le site', 'method' => 'POST', 'endpoint' => '/admin/api/imports-exports/static/actions/site'],
                ['key' => 'rerun', 'label' => 'Relancer le dernier export', 'method' => 'POST', 'endpoint' => '/admin/api/imports-exports/static/actions/rerun'],
            ] : [],
            'security' => [
                'release_root' => 'storage/exports/static/<release_id>/',
                'artifacts' => ['static-export.zip', 'editorial-export.zip'],
                'public_payload' => 'storage/exports/static/<release_id>/public/',
                'storage_is_not_public' => true,
            ],
            'rollback' => [
                'static_only' => true,
                'static_rollback_is_editorial_rollback' => false,
                'implemented' => false,
                'message' => 'Rollback statique préparé par historique filesystem; il ne modifie jamais les révisions éditoriales.',
            ],
        ], self::CONTRACT, AdminApiContract::meta($site, $languageCode));
    }

    public function inspectImport(): Response
    {
        $this->auth->requireAuth();
        $site = $this->site($this->request->post);
        $this->requireAnyPermission(['imports_exports.write', 'imports_exports.manage'], (int) $site['id']);
        $file = $this->request->files['archive'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return Response::validation(['archive' => ['Sélectionnez un ZIP Editorial Package valide.']]);
        }
        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return Response::error('VALIDATION_FAILED', 'Archive uploadée invalide.', 422);
        }
        $limits = (array) ($this->config['editorial_import'] ?? []);
        $inspector = new EditorialImportArchiveInspector(
            maxArchiveBytes: max(1048576, (int) ($limits['max_archive_bytes'] ?? 52428800)),
            maxFiles: max(10, (int) ($limits['max_files'] ?? 2000)),
            maxUncompressedBytes: max(1048576, (int) ($limits['max_uncompressed_bytes'] ?? 268435456)),
        );
        try {
            $inspection = $inspector->inspect($tmpName, (string) ($file['name'] ?? ''));
            $plan = (new EditorialImportPlanner($this->coreDb))->plan(
                (string) $inspection['root'],
                (int) $site['id'],
                (string) ($site['site_key'] ?? $site['handle'] ?? ''),
            );
            $user = $this->auth->user();
            $staging = new EditorialImportStagingService();
            $plan['archive_retained']=true;
            $plan['token_expires_in']=1800;
            $token = $staging->retain((string) $inspection['root'], (int) ($user['id'] ?? 0), (int) $site['id'], $plan);
            ($inspection['cleanup'])();
            $languageCode = AdminApiContract::language($this->request, $this->sites, $site, $this->request->post);
            return Response::success([
                'message' => 'Archive éditoriale valide. Plan calculé sans écriture; paquet temporairement préparé pour exécution.',
                'plan' => $plan,
                'import_token' => $token,
                'token_expires_in' => 1800,
            ], self::CONTRACT, AdminApiContract::meta($site, $languageCode));
        } catch (EditorialImportInspectionException $e) {
            $details = $e->errors();
            $message = $e->getMessage() . ($details !== [] ? ' ' . $details[0] : '');
            return Response::error('VALIDATION_FAILED', $message, 422, ['errors' => $details]);
        } catch (\Throwable $e) {
            return Response::error('VALIDATION_FAILED', 'Inspection éditoriale impossible.', 422, ['reason' => $e->getMessage()]);
        }
    }


    public function executeImport(): Response
    {
        $this->auth->requireAuth();
        $payload = AdminApiContract::dataPayload($this->request, false);
        $site = $this->site($payload);
        $this->requireAnyPermission(['imports_exports.write', 'imports_exports.manage'], (int) $site['id']);
        $mode = (string) ($payload['mode'] ?? 'create');
        $token = (string) ($payload['import_token'] ?? '');
        if (!in_array($mode, ['create','upsert','replace'], true)) {
            return Response::validation(['mode' => ['Mode autorisé : create, upsert ou replace.']]);
        }
        $user = $this->auth->user();
        $userId = (int) ($user['id'] ?? 0);
        $staging = new EditorialImportStagingService();
        $importId = gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
        $history = new EditorialImportHistoryRepository();
        $root = '';
        $plan = [];
        try {
            $staged = $staging->claim($token, $userId, (int) $site['id']);
            $root = (string) $staged['root'];
            $plan = (array) $staged['plan'];
            $manifest = EditorialPackageJson::decode((string) file_get_contents($root . '/editorial-package.json'));
            $result = (new EditorialImportExecutor($this->coreDb, $this->publisher, new GenerateMediaVariants($this->coreDb), new StorageDriverFactory($this->coreDb)))->execute(
                $root, $plan, $mode, (int) $site['id'], (string) ($site['site_key'] ?? $site['handle'] ?? ''), $userId, $importId
            );
        } catch (\Throwable $e) {
            $report=['import_id'=>$importId,'status'=>'failed','mode'=>$mode,'site_id'=>(int)$site['id'],'user_id'=>$userId,'errors'=>[$e->getMessage()],'finished_at'=>gmdate('c')];
            try {$history->write($importId, ['import_id'=>$importId,'mode'=>$mode,'target_site_id'=>(int)$site['id']], $plan, $report);} catch (\Throwable) {}
            if ($root !== '') { $staging->release($token, $userId, (int) $site['id'], $e->getMessage()); }
            (new Logger(base_path('storage/logs/editorial-import.log')))->error('editorial_import.failed', ['import_id'=>$importId,'site_id'=>(int)$site['id'],'user_id'=>$userId,'mode'=>$mode,'exception'=>get_class($e),'reason'=>$e->getMessage()]);
            try{$this->outbox->push('editorial_import.failed', ['import_id'=>$importId,'site_id'=>(int)$site['id'],'mode'=>$mode,'error_code'=>'EDITORIAL_IMPORT_FAILED']);}catch(\Throwable){}
            return Response::error('VALIDATION_FAILED','Import éditorial annulé; aucune modification partielle conservée.',422,['reason'=>$e->getMessage(),'import_id'=>$importId]);
        }

        // À ce stade, la transaction métier est validée. Les opérations de traçabilité
        // ne doivent jamais transformer un import réussi en faux rollback.
        $postCommitWarnings=[];
        try{$staging->complete($token, $userId, (int) $site['id']);$root='';}catch(\Throwable $e){$postCommitWarnings[]='Nettoyage du paquet temporaire incomplet : '.$e->getMessage();}
        try{$history->write($importId, $manifest + ['import_id'=>$importId,'mode'=>$mode,'target_site_id'=>(int)$site['id']], $plan, $result);}catch(\Throwable $e){$postCommitWarnings[]='Historique filesystem non écrit : '.$e->getMessage();}
        try{(new Logger(base_path('storage/logs/editorial-import.log')))->info('editorial_import.succeeded', ['import_id'=>$importId,'site_id'=>(int)$site['id'],'user_id'=>$userId,'mode'=>$mode,'created'=>count((array)$result['contents_created']),'updated'=>count((array)$result['contents_updated'])]);}catch(\Throwable $e){$postCommitWarnings[]='Journal technique non écrit : '.$e->getMessage();}
        try{$this->outbox->push('editorial_import.succeeded', ['import_id'=>$importId,'site_id'=>(int)$site['id'],'mode'=>$mode,'created'=>count((array)$result['contents_created']),'updated'=>count((array)$result['contents_updated'])]);}catch(\Throwable $e){$postCommitWarnings[]='Événement outbox non écrit : '.$e->getMessage();}
        if($postCommitWarnings!==[]){$result['warnings']=array_values(array_merge((array)($result['warnings']??[]),$postCommitWarnings));}
        return Response::success(['message'=>'Import éditorial terminé.','report'=>$result], self::CONTRACT, AdminApiContract::meta($site, AdminApiContract::language($this->request,$this->sites,$site,$payload)));
    }

    public function deleteImportHistory(string $importId): Response
    {
        $this->auth->requireAuth(); $site=$this->site();
        $this->authorization->require('imports_exports.manage',(int)$site['id']);
        $history=new EditorialImportHistoryRepository();
        $row=$history->find($importId);
        if($row===null || (int)($row['site_id']??0)!==(int)$site['id']){return Response::error('ROUTE_NOT_FOUND','Historique d’import introuvable.',404);}
        if(!$history->delete($importId)){return Response::error('INTERNAL_SERVER_ERROR','Suppression incomplète de l’historique d’import.',500);}
        return Response::success(['message'=>'Historique d’import supprimé.','import_id'=>$importId],self::CONTRACT,AdminApiContract::meta($site,AdminApiContract::language($this->request,$this->sites,$site)));
    }

    public function exportPage(): Response { return $this->runExport('page'); }
    public function exportLanguage(): Response { return $this->runExport('language'); }
    public function exportSite(): Response { return $this->runExport('site'); }
    public function exportAllLanguages(): Response { return $this->runExport('site'); }
    public function rerun(): Response { return $this->runExport('rerun'); }

    public function manifest(string $releaseId): Response
    {
        return $this->jsonFile($releaseId, 'manifest');
    }

    public function report(string $releaseId): Response
    {
        return $this->jsonFile($releaseId, 'report');
    }

    public function zip(string $releaseId): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->requireAnyPermission(['imports_exports.write', 'imports_exports.manage'], (int) $site['id']);
        if(!$this->releaseBelongsToSite($releaseId,$site)){return Response::error('ROUTE_NOT_FOUND','Release introuvable.',404);}
        $path = $this->releaseRepository()->filePath($releaseId, 'zip');
        if ($path === null) {
            return Response::error('ROUTE_NOT_FOUND', 'Archive statique introuvable.', 404);
        }
        return new Response(200, (string) file_get_contents($path), [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="static-export-' . $releaseId . '.zip"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function editorialZip(string $releaseId): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->requireAnyPermission(['imports_exports.write', 'imports_exports.manage'], (int) $site['id']);
        if(!$this->releaseBelongsToSite($releaseId,$site)){return Response::error('ROUTE_NOT_FOUND','Release introuvable.',404);}
        $path = $this->releaseRepository()->filePath($releaseId, 'editorial_zip');
        if ($path === null) {
            return Response::error('ROUTE_NOT_FOUND', 'Archive éditoriale introuvable.', 404);
        }
        return new Response(200, (string) file_get_contents($path), [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="editorial-export-' . $releaseId . '.zip"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function delete(string $releaseId): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->authorization->require('imports_exports.manage', (int) $site['id']);
        if(!$this->releaseBelongsToSite($releaseId,$site)){return Response::error('ROUTE_NOT_FOUND','Release introuvable.',404);}
        if (!$this->releaseRepository()->delete($releaseId)) {
            return Response::error('ROUTE_NOT_FOUND', 'Release statique introuvable ou suppression refusée.', 404);
        }
        $languageCode = AdminApiContract::language($this->request, $this->sites, $site);
        return Response::success([
            'message' => 'Release statique supprimée.',
            'release_id' => $releaseId,
        ], self::CONTRACT, AdminApiContract::meta($site, $languageCode));
    }

    private function runExport(string $action): Response
    {
        $this->auth->requireAuth();
        $payload = AdminApiContract::dataPayload($this->request, false);
        $site = $this->site($payload);
        $this->requireAnyPermission(['imports_exports.write', 'imports_exports.manage'], (int) $site['id']);
        $languageCode = AdminApiContract::language($this->request, $this->sites, $site, $payload);
        $repo = $this->releaseRepository();

        $options = [
            'site' => (string) ($site['site_key'] ?? $site['handle'] ?? ''),
            'lang' => $languageCode,
            'all_languages' => false,
            'route' => null,
            'dry_run' => false,
            'create_zip' => true,
            'trigger' => 'manual_backoffice_' . $action,
        ];

        if ($action === 'page') {
            $route = trim((string) ($payload['route'] ?? $payload['path'] ?? ''));
            if ($route === '') {
                return Response::validation(['route' => ['Indiquez le chemin public de la page à exporter, par exemple /about.']]);
            }
            $options['route'] = $route;
        } elseif ($action === 'site' || $action === 'all_languages') {
            $options['lang'] = null;
            $options['all_languages'] = true;
        } elseif ($action === 'rerun') {
            $latest = $repo->latest((string)($site['site_key']??$site['handle']??''));
            if (!$latest) {
                return Response::validation(['release' => ['Aucun export précédent à relancer.']]);
            }
            $latestMode = (string) ($latest['mode'] ?? '');
            $latestLanguages = array_values(array_filter((array) ($latest['languages'] ?? [])));
            $options['site'] = (string) ($latest['site'] ?? $options['site']);
            $options['lang'] = count($latestLanguages) === 1 ? (string) $latestLanguages[0] : null;
            $options['all_languages'] = false;
            $options['route'] = null;

            if ($latestMode === 'route') {
                $route = trim((string) ($latest['route'] ?? ''));
                if ($route === '') {
                    return Response::validation(['release' => ['Le dernier export était une page, mais son chemin n’est pas disponible dans le manifest/rapport.']]);
                }
                $options['route'] = '/' . ltrim($route, '/');
            } elseif (in_array($latestMode, ['site', 'all_languages'], true)) {
                $options['lang'] = null;
                $options['all_languages'] = true;
            }
        }

        $result = $this->exporter()->export($options);
        $manifest = (array) ($result['manifest'] ?? []);
        $releaseId = (string) ($manifest['release_id'] ?? '');
        if ($releaseId !== '' && empty($manifest['errors'])) {
            $this->outbox->push('static_export.succeeded', [
                'release_id' => $releaseId,
                'site_id' => (int) $site['id'],
                'site' => $options['site'],
                'languages' => $manifest['languages'] ?? [],
                'mode' => $manifest['mode'] ?? $action,
                'manual' => true,
            ]);
        }

        return Response::success([
            'message' => empty($manifest['errors']) ? 'Exports statique et éditorial terminés.' : 'Export terminé avec erreurs ou artefact partiel.',
            'status' => (string) ($manifest['status'] ?? (empty($manifest['errors']) ? 'succeeded' : 'failed')),
            'release' => $releaseId !== '' ? $this->releaseRepository()->find($releaseId) : null,
            'manifest' => $manifest,
            'report' => $result['report'] ?? [],
        ], self::CONTRACT, AdminApiContract::meta($site, $languageCode));
    }


    private function releaseRepository(): StaticExportReleaseRepository
    {
        return new StaticExportReleaseRepository('', admin_url_path('/admin/api'));
    }

    private function jsonFile(string $releaseId, string $kind): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->requireAnyPermission(['imports_exports.write', 'imports_exports.manage'], (int) $site['id']);
        $repo = $this->releaseRepository();
        if(!$this->releaseBelongsToSite($releaseId,$site)){return Response::error('ROUTE_NOT_FOUND','Release introuvable.',404);}
        $data = $kind === 'manifest' ? $repo->manifest($releaseId) : $repo->report($releaseId);
        if ($data === null) {
            return Response::error('ROUTE_NOT_FOUND', 'Fichier d’export statique introuvable.', 404);
        }
        return Response::success($data, self::CONTRACT, AdminApiContract::meta($site, AdminApiContract::language($this->request, $this->sites, $site)));
    }

    /** @param array<string,mixed> $payload */
    private function site(array $payload = []): array
    {
        $siteId = isset($payload['site_id']) ? (int) $payload['site_id'] : (isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null);
        return AdminApiContract::siteContext($this->request, $this->sites, $siteId, $this->auth);
    }

    /** @return array<string,mixed> */
    private function configuration(): array
    {
        return [
            'after_publication' => 'none',
            'available_after_publication_modes' => ['none', 'page', 'language', 'site'],
            'default' => 'manual_only',
            'webhook_after_success' => 'prepared_outbox_event_static_export.succeeded',
            'source' => 'filesystem_mvp_no_sql_table',
            'editorial_import' => [
                'dry_run_only' => false,
                'max_archive_bytes' => (int) (($this->config['editorial_import']['max_archive_bytes'] ?? 52428800)),
                'max_files' => (int) (($this->config['editorial_import']['max_files'] ?? 2000)),
                'max_uncompressed_bytes' => (int) (($this->config['editorial_import']['max_uncompressed_bytes'] ?? 268435456)),
            ],
        ];
    }


    /**
     * Suggestions légères pour aider l'éditeur à choisir une portée d'export.
     * Source: projections publiques existantes, jamais les brouillons.
     *
     * @param array<string,mixed> $site
     * @return array<string,mixed>
     */
    private function suggestions(array $site, ?string $languageCode): array
    {
        $source = new SqlStaticExportSource($this->coreDb);
        $siteId = (int) ($site['id'] ?? 0);
        $siteKey = (string) ($site['site_key'] ?? $site['handle'] ?? '');
        $routes = $source->listPublishedRouteSuggestions($siteId > 0 ? $siteId : null, $siteKey, $languageCode, 120);

        // Si le contexte de langue admin est plus restrictif que l'état réel du
        // site, on garde une interface utile en proposant les routes publiées
        // du site toutes langues confondues plutôt qu'un écran vide.
        $fallbackUsed = false;
        if ($routes === [] && $languageCode !== null) {
            $routes = $source->listPublishedRouteSuggestions($siteId > 0 ? $siteId : null, $siteKey, null, 120);
            $fallbackUsed = $routes !== [];
        }

        $languages = [];
        foreach ($source->listSiteLanguageSettings($siteKey) as $row) {
            $languages[] = [
                'code' => (string) ($row['language_code'] ?? ''),
                'label' => (string) ($row['language_code'] ?? ''),
                'url_prefix' => (string) ($row['url_prefix'] ?? ''),
                'default' => (int) ($row['is_default'] ?? 0) === 1,
            ];
        }

        return [
            'routes' => $routes,
            'languages' => $languages,
            'current_site' => $siteKey,
            'current_site_id' => $siteId,
            'current_language' => $languageCode,
            'fallback_all_languages' => $fallbackUsed,
            'empty_message' => 'Aucune route publique publiée trouvée pour ce contexte. Vérifiez que les projections publiques ont été générées après publication.',
        ];
    }

    /** @param list<string> $permissions */
    private function requireAnyPermission(array $permissions, int $siteId): void
    {
        if (!$this->hasAnyPermission($permissions, $siteId)) {
            $this->authorization->require($permissions[0], $siteId);
        }
    }

    /** @param list<string> $permissions */
    private function hasAnyPermission(array $permissions, int $siteId): bool
    {
        foreach ($permissions as $permission) {
            if ($this->auth->hasPermission($permission, $siteId)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $release @return array<string,mixed> */
    private function readonlyReleaseSummary(array $release): array
    {
        unset(
            $release['links'],
            $release['manifest_path'],
            $release['report_path'],
            $release['zip_path'],
            $release['editorial_zip_path'],
            $release['artifacts'],
            $release['release_dir'],
            $release['output_path']
        );
        return $release;
    }

    /** @param array<string,mixed> $site */
    private function releaseBelongsToSite(string $releaseId, array $site): bool
    {
        $release=$this->releaseRepository()->find($releaseId);
        $expected=(string)($site['site_key']??$site['handle']??'');
        return $release!==null && $expected!=='' && hash_equals($expected,(string)($release['site']??''));
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function readonlyImportSummary(array $row): array
    {
        return [
            'import_id'=>$row['import_id']??null,
            'status'=>$row['status']??null,
            'mode'=>$row['mode']??null,
            'site_id'=>$row['site_id']??null,
            'finished_at'=>$row['finished_at']??null,
            'contents_created'=>count((array)($row['contents_created']??[])),
            'contents_updated'=>count((array)($row['contents_updated']??[])),
            'contents_ignored'=>count((array)($row['contents_ignored']??[])),
            'errors_count'=>count((array)($row['errors']??[])),
            'warnings_count'=>count((array)($row['warnings']??[])),
        ];
    }

    private function exporter(): StaticExportService
    {
        $source = new SqlStaticExportSource($this->coreDb);
        return new StaticExportService(
            $source,
            new StaticExportRouteCollector($source),
            new StaticExportRenderer($this->config, $this->resolver, $this->sites),
            new StaticAssetCollector(),
            new StaticExportManifestWriter(),
            $this->formsDb !== null ? new StaticFormRenderer(new FormRepository($this->formsDb)) : null,
            new EditorialExportService($this->coreDb, storageFactory: new StorageDriverFactory($this->coreDb)),
        );
    }
}
