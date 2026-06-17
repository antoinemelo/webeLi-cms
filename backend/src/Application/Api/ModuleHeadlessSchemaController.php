<?php

declare(strict_types=1);

namespace App\Application\Api;

use App\Core\ErrorCode;
use App\Core\Request;
use App\Core\Response;
use App\Module\ModuleBlueprintGovernanceService;
use App\Repository\SiteRepository;

final class ModuleHeadlessSchemaController
{
    public function __construct(
        private readonly Request $request,
        private readonly SiteRepository $sites,
        private readonly ModuleBlueprintGovernanceService $blueprints,
    ) {}

    public function schema(string $module, string $resource): Response
    {
        [$site, $languageCode] = $this->context();
        try {
            $schema = $this->blueprints->resourceSchema($module, $resource, true);
        } catch (\Throwable) {
            return Response::error(ErrorCode::ROUTE_NOT_FOUND, 'Ressource headless module introuvable.', 404, [
                'module' => $module,
                'resource' => $resource,
            ]);
        }

        $headless = is_array($schema['headless'] ?? null) ? $schema['headless'] : [];
        $capabilities = is_array($schema['capabilities'] ?? null) ? $schema['capabilities'] : [];
        if (!(bool) ($headless['enabled'] ?? $capabilities['headless'] ?? false)) {
            return Response::error(ErrorCode::ROUTE_NOT_FOUND, 'Ressource non exposée en headless.', 404, [
                'module' => $module,
                'resource' => $resource,
            ]);
        }

        return Response::success(['schema' => $schema], 'public.module_resource_schema.v1', [
            'site_id' => (int) ($site['id'] ?? 0),
            'site_key' => (string) ($site['site_key'] ?? ''),
            'language_code' => $languageCode,
            'module' => $module,
            'resource' => $resource,
            'source' => 'module_blueprint_provider',
        ], 200, ['Cache-Control' => 'public, max-age=60']);
    }

    /** @return array{0:array<string,mixed>,1:string} */
    private function context(): array
    {
        $siteId = isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null;
        $site = $siteId !== null ? ($this->sites->findActiveSite($siteId) ?? $this->sites->resolveCurrentSite()) : $this->sites->resolveCurrentSite();
        $language = (string) ($this->request->query['language_code'] ?? $this->request->query['lang'] ?? $site['default_language_code'] ?? 'fr');
        return [$site, $language];
    }
}
