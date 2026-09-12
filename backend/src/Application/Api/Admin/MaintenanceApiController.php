<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Application\Maintenance\DependencyInventoryService;
use App\Infrastructure\Maintenance\StableUpdateCatalogService;
use App\Infrastructure\Maintenance\StableUpdateException;
use App\Infrastructure\Maintenance\StableUpdateService;
use App\Application\Maintenance\VersionInventoryService;
use App\Application\Publication\PublishedProjectionPipeline;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\RuntimeCachePurger;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;

final class MaintenanceApiController
{
    private const CONTRACT = 'admin.maintenance.v1';

    public function __construct(
        private readonly Request $request,
        private readonly Database $coreDb,
        private readonly Database $iamDb,
        private readonly SiteRepository $sites,
        private readonly AuthRepository $auth,
        private readonly Authorization $authorization,
        private readonly PublishedProjectionPipeline $projectionPipeline,
        private readonly VersionInventoryService $versions,
        private readonly DependencyInventoryService $dependencies,
        private readonly StableUpdateCatalogService $stableUpdateCatalogs,
        private readonly StableUpdateService $stableUpdates,
    ) {}

    public function index(): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->authorization->require('maintenance.manage', (int) $site['id']);
        $languageCode = AdminApiContract::language($this->request, $this->sites, $site);

        $siteId = (int) $site['id'];

        $stableUpdates = $this->stableUpdateCatalogs->status();
        unset($stableUpdates['catalog']);

        return Response::success([
            'status' => $this->status($siteId, $languageCode),
            'audit_logs' => $this->auditLogs(),
            'runtime_logs' => $this->runtimeLogs(),
            'versions' => $this->versions->maintenancePayload(),
            'dependencies' => $this->dependencies->maintenancePayload(true),
            'stable_updates' => $stableUpdates,
            'actions' => $this->actions(),
        ], self::CONTRACT, AdminApiContract::meta($site, $languageCode));
    }

    public function clearCache(): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->authorization->require('maintenance.manage', (int) $site['id']);
        $languageCode = AdminApiContract::language($this->request, $this->sites, $site);

        $deleted = $this->clearDirectory(base_path('storage/cache'), ['.gitkeep', '.gitignore', 'index.html', 'index.php']);
        RuntimeCachePurger::purgeTwigCache();
        $this->audit('maintenance.cache_cleared', 'cache', null, ['deleted_files' => $deleted]);

        return Response::success([
            'message' => sprintf('Cache vidé: %d fichier(s) supprimé(s).', $deleted),
            'deleted_files' => $deleted,
            'status' => $this->status((int) $site['id'], $languageCode),
        ], self::CONTRACT, AdminApiContract::meta($site, $languageCode));
    }

    public function refreshDependencies(): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->authorization->require('maintenance.manage', (int) $site['id']);
        $languageCode = AdminApiContract::language($this->request, $this->sites, $site);

        return Response::success([
            'message' => 'Affichage des versions actuellement installées mis à jour.',
            'dependencies' => $this->dependencies->maintenancePayload(true, true),
        ], self::CONTRACT, AdminApiContract::meta($site, $languageCode));
    }

    public function refreshStableUpdates(): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->authorization->require('maintenance.manage', (int) $site['id']);
        $languageCode = AdminApiContract::language($this->request, $this->sites, $site);

        $updates = $this->stableUpdateCatalogs->status(true);
        unset($updates['catalog']);
        return Response::success([
            'message' => 'Catalogue des mises à jour stables actualisé.',
            'stable_updates' => $updates,
        ], self::CONTRACT, AdminApiContract::meta($site, $languageCode));
    }

    public function applyStableUpdate(): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->authorization->require('maintenance.manage', (int) $site['id']);
        $languageCode = AdminApiContract::language($this->request, $this->sites, $site);

        $type = trim((string) $this->request->input('component_type', ''));
        $key = trim((string) $this->request->input('component_key', ''));
        $version = trim((string) $this->request->input('expected_version', ''));
        $fingerprint = trim((string) $this->request->input('catalog_fingerprint', ''));
        if (!in_array($type, ['core', 'module'], true)
            || preg_match('/^[a-z][a-z0-9-]{1,63}$/', $key) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $version) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1) {
            return Response::validation([
                'update' => ['La publication stable doit être vérifiée à nouveau.'],
            ]);
        }

        try {
            @set_time_limit(240);
            ignore_user_abort(true);
            $result = $this->stableUpdates->apply($type, $key, $version, $fingerprint);
            $this->audit('maintenance.stable_update_applied', 'application_component', null, [
                'component_type' => $type,
                'component_key' => $key,
                'version' => $version,
                'backup_path' => $result['backup_path'] ?? null,
            ]);
            return Response::success($result, self::CONTRACT, AdminApiContract::meta($site, $languageCode));
        } catch (StableUpdateException $exception) {
            return Response::error('api.error', $exception->getMessage(), 409);
        } catch (\Throwable) {
            return Response::error('api.error', 'La mise à jour a échoué. Consultez les journaux et sauvegardes.', 500);
        }
    }

    public function reindexSearch(): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->authorization->require('maintenance.manage', (int) $site['id']);
        $languageCode = AdminApiContract::language($this->request, $this->sites, $site);

        $siteId = (int) $site['id'];
        $this->coreDb->run('DELETE FROM search_documents WHERE site_id = :site_id', ['site_id' => $siteId]);

        $entries = $this->coreDb->all(
            "SELECT DISTINCT ce.id
             FROM content_entries ce
             JOIN content_entry_publications cep ON cep.entry_id = ce.id AND cep.site_id = ce.site_id AND cep.workflow_status = 'published'
             JOIN revisions rv ON rv.id = cep.published_revision_id AND rv.workflow_status = 'published'
             WHERE ce.site_id = :site_id AND ce.status = 'published' AND ce.is_active = 1
             ORDER BY ce.id",
            ['site_id' => $siteId]
        );

        $rebuilt = 0;
        $languages = [];
        foreach ($entries as $row) {
            $result = $this->projectionPipeline->rebuildEntry((int) $row['id']);
            if ($result !== []) {
                $rebuilt++;
                foreach ($result as $lang) {
                    $languages[$lang] = true;
                }
            }
        }

        $this->rebuildFtsIndex();
        $this->audit('maintenance.search_reindexed', 'search_documents', null, ['entries' => $rebuilt, 'languages' => array_keys($languages)]);

        return Response::success([
            'message' => sprintf('Index de recherche reconstruit: %d entrée(s) publiée(s).', $rebuilt),
            'rebuilt_entries' => $rebuilt,
            'languages' => array_keys($languages),
            'status' => $this->status((int) $site['id'], $languageCode),
        ], self::CONTRACT, AdminApiContract::meta($site, $languageCode));
    }

    public function clearAuditLogs(): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->authorization->require('maintenance.manage', (int) $site['id']);
        $languageCode = AdminApiContract::language($this->request, $this->sites, $site);

        $before = (int) ($this->iamDb->one('SELECT COUNT(*) AS c FROM iam_audit_logs')['c'] ?? 0);
        $actor = (int) ($this->auth->user()['id'] ?? 0) ?: null;
        $this->iamDb->run('DELETE FROM iam_audit_logs');
        $this->audit('maintenance.audit_logs_cleared', 'iam_audit_logs', null, ['deleted_rows' => $before, 'actor_user_id_before_clear' => $actor]);

        return Response::success([
            'message' => sprintf('Journal d’audit effacé: %d ligne(s) supprimée(s).', $before),
            'deleted_rows' => $before,
            'audit_logs' => $this->auditLogs(),
            'status' => $this->status((int) $site['id'], $languageCode),
        ], self::CONTRACT, AdminApiContract::meta($site, $languageCode));
    }

    public function clearRuntimeLogs(): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->authorization->require('maintenance.manage', (int) $site['id']);
        $languageCode = AdminApiContract::language($this->request, $this->sites, $site);

        $deleted = $this->clearDirectory(base_path('storage/logs'), ['.gitkeep', '.gitignore', 'index.html', 'index.php']);
        $this->audit('maintenance.runtime_logs_cleared', 'runtime_logs', null, ['deleted_files' => $deleted]);

        return Response::success([
            'message' => sprintf('Logs runtime effacés: %d fichier(s) supprimé(s).', $deleted),
            'deleted_files' => $deleted,
            'runtime_logs' => $this->runtimeLogs(),
            'status' => $this->status((int) $site['id'], $languageCode),
        ], self::CONTRACT, AdminApiContract::meta($site, $languageCode));
    }

    private function site(): array
    {
        return AdminApiContract::siteContext($this->request, $this->sites, isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null, $this->auth);
    }

    private function status(int $siteId, string $languageCode): array
    {
        $published = (int) ($this->coreDb->one(
            "SELECT COUNT(DISTINCT ce.id) AS c FROM content_entries ce JOIN content_entry_publications cep ON cep.entry_id = ce.id AND cep.workflow_status = 'published' WHERE ce.site_id = :site_id AND ce.status = 'published'",
            ['site_id' => $siteId]
        )['c'] ?? 0);
        $searchDocuments = (int) ($this->coreDb->one('SELECT COUNT(*) AS c FROM search_documents WHERE site_id = :site_id', ['site_id' => $siteId])['c'] ?? 0);
        $searchDocumentsLanguage = (int) ($this->coreDb->one('SELECT COUNT(*) AS c FROM search_documents WHERE site_id = :site_id AND language_code = :language_code', ['site_id' => $siteId, 'language_code' => $languageCode])['c'] ?? 0);
        $auditRows = (int) ($this->iamDb->one('SELECT COUNT(*) AS c FROM iam_audit_logs')['c'] ?? 0);

        return [
            'cache' => $this->directoryStats(base_path('storage/cache')),
            'runtime_logs' => $this->directoryStats(base_path('storage/logs')),
            'audit_rows' => $auditRows,
            'published_entries' => $published,
            'search_documents' => $searchDocuments,
            'search_documents_current_language' => $searchDocumentsLanguage,
        ];
    }

    private function auditLogs(): array
    {
        $limit = max(1, min(200, (int) ($this->request->query['limit'] ?? 100)));
        return $this->iamDb->all(
            'SELECT l.id, l.actor_user_id, u.email AS actor_email, l.action_key, l.resource_type, l.resource_id, l.context_json, l.ip_address, l.user_agent, l.created_at
             FROM iam_audit_logs l
             LEFT JOIN iam_users u ON u.id = l.actor_user_id
             ORDER BY l.created_at DESC, l.id DESC
             LIMIT ' . $limit
        );
    }

    private function runtimeLogs(): array
    {
        $dir = base_path('storage/logs');
        if (!is_dir($dir)) {
            return [];
        }
        $items = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (!is_file($path)) {
                continue;
            }
            $items[] = [
                'name' => $name,
                'size_bytes' => filesize($path) ?: 0,
                'updated_at' => gmdate('Y-m-d H:i:s', filemtime($path) ?: time()),
                'tail' => $this->tail($path, 25),
            ];
        }
        usort($items, static fn(array $a, array $b): int => strcmp((string) $b['updated_at'], (string) $a['updated_at']));
        return array_slice($items, 0, 20);
    }

    private function directoryStats(string $path): array
    {
        if (!is_dir($path)) {
            return ['exists' => false, 'files' => 0, 'size_bytes' => 0, 'writable' => false];
        }
        $files = 0;
        $size = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if ($item->isFile() && !str_starts_with($item->getFilename(), '.')) {
                $files++;
                $size += (int) $item->getSize();
            }
        }
        return ['exists' => true, 'files' => $files, 'size_bytes' => $size, 'writable' => is_writable($path)];
    }

    /** @param list<string> $keep */
    private function clearDirectory(string $path, array $keep): int
    {
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
            return 0;
        }
        $root = realpath($path);
        if ($root === false) {
            return 0;
        }
        $deleted = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $name = $item->getFilename();
            if (in_array($name, $keep, true)) {
                continue;
            }
            $itemPath = $item->getPathname();
            if ($item->isDir()) {
                @rmdir($itemPath);
                continue;
            }
            if (@unlink($itemPath)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    private function rebuildFtsIndex(): void
    {
        try {
            $this->coreDb->run("INSERT INTO search_documents_fts(search_documents_fts) VALUES('rebuild')");
        } catch (\Throwable) {
            // FTS5 may be unavailable in very constrained local SQLite builds. The
            // canonical search_documents table remains rebuilt by projections.
        }
    }

    /** @return list<string> */
    private function tail(string $path, int $lines): array
    {
        $content = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($content)) {
            return [];
        }
        return array_slice($content, -$lines);
    }

    private function audit(string $action, ?string $resourceType = null, ?int $resourceId = null, array $context = []): void
    {
        $this->auth->audit((int) ($this->auth->user()['id'] ?? 0) ?: null, $action, $resourceType, $resourceId, $context);
    }

    private function actions(): array
    {
        return [
            'refresh_dependencies' => ['method' => 'POST', 'path' => '/admin/api/maintenance/dependencies/refresh'],
            'refresh_stable_updates' => ['method' => 'POST', 'path' => '/admin/api/maintenance/updates/stable/refresh'],
            'apply_stable_update' => ['method' => 'POST', 'path' => '/admin/api/maintenance/updates/stable/apply'],
            'clear_cache' => ['method' => 'POST', 'path' => '/admin/api/maintenance/cache/clear'],
            'reindex_search' => ['method' => 'POST', 'path' => '/admin/api/maintenance/search/reindex'],
            'clear_audit_logs' => ['method' => 'DELETE', 'path' => '/admin/api/maintenance/audit-logs'],
            'clear_runtime_logs' => ['method' => 'DELETE', 'path' => '/admin/api/maintenance/runtime-logs'],
        ];
    }
}
