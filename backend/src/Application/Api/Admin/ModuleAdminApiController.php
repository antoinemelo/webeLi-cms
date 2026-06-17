<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Core\Request;
use App\Core\Response;
use App\Module\ModuleLifecycleService;
use App\Module\ModuleBlueprintGovernanceService;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;

final class ModuleAdminApiController
{
    private const CONTRACT = 'admin.modules_api.v1';

    public function __construct(
        private readonly Request $request,
        private readonly SiteRepository $sites,
        private readonly AuthRepository $auth,
        private readonly Authorization $authorization,
        private readonly ModuleLifecycleService $modules,
        private readonly ModuleBlueprintGovernanceService $blueprints,
    ) {}

    public function index(): Response
    {
        [$site, $languageCode] = $this->authorize('modules.read');
        return Response::success(['modules' => $this->modules->list()], self::CONTRACT, AdminApiContract::meta($site, $languageCode));
    }

    public function show(string $key): Response
    {
        [$site, $languageCode] = $this->authorize('modules.read');
        return Response::success(['module' => $this->modules->show($key)], self::CONTRACT, AdminApiContract::meta($site, $languageCode));
    }

    public function install(string $key): Response
    {
        [$site, $languageCode] = $this->authorize('modules.manage');
        return Response::success(['module' => $this->modules->install($key)], self::CONTRACT, AdminApiContract::meta($site, $languageCode));
    }

    public function enable(string $key): Response
    {
        [$site, $languageCode] = $this->authorize('modules.manage');
        return Response::success(['module' => $this->modules->enable($key)], self::CONTRACT, AdminApiContract::meta($site, $languageCode));
    }

    public function disable(string $key): Response
    {
        [$site, $languageCode] = $this->authorize('modules.manage');
        return Response::success(['module' => $this->modules->disable($key)], self::CONTRACT, AdminApiContract::meta($site, $languageCode));
    }

    public function migrate(string $key): Response
    {
        [$site, $languageCode] = $this->authorize('modules.manage');
        return Response::success($this->modules->migrate($key), self::CONTRACT, AdminApiContract::meta($site, $languageCode));
    }


    public function blueprints(string $key): Response
    {
        [$site, $languageCode] = $this->authorize('blueprints.read');
        return Response::success(
            ['blueprints' => $this->blueprints->moduleBlueprints($key, false)],
            'admin.module_blueprints.v1',
            AdminApiContract::meta($site, $languageCode)
        );
    }

    public function resourceSchema(string $key, string $resource): Response
    {
        [$site, $languageCode] = $this->authorizeAny(['blueprints.read', $key . '.read']);
        return Response::success(
            ['schema' => $this->blueprints->resourceSchema($key, $resource, false)],
            'admin.module_resource_schema.v1',
            AdminApiContract::meta($site, $languageCode)
        );
    }

    public function health(string $key): Response
    {
        [$site, $languageCode] = $this->authorize('modules.read');
        return Response::success(['health' => $this->modules->health($key)], self::CONTRACT, AdminApiContract::meta($site, $languageCode));
    }


    /** @param list<string> $permissions @return array{0:array<string,mixed>,1:string} */
    private function authorizeAny(array $permissions): array
    {
        $this->auth->requireAuth();
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null, $this->auth);
        foreach ($permissions as $permission) {
            if ($this->auth->hasPermission($permission, (int) $site['id'])) {
                return [$site, AdminApiContract::language($this->request, $this->sites, $site)];
            }
        }
        $this->authorization->require($permissions[0] ?? 'blueprints.read', (int) $site['id']);
        return [$site, AdminApiContract::language($this->request, $this->sites, $site)];
    }

    /** @return array{0:array<string,mixed>,1:string} */
    private function authorize(string $permission): array
    {
        $this->auth->requireAuth();
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null, $this->auth);
        $this->authorization->require($permission, (int) $site['id']);
        return [$site, AdminApiContract::language($this->request, $this->sites, $site)];
    }
}
