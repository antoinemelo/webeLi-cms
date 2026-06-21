<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Application\Blueprint\BlueprintRepository;
use App\Application\Blueprint\GetBlueprintEditorSchema;
use App\Core\ErrorCode;
use App\Core\Request;
use App\Core\Response;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;

final class BlueprintApiController
{
    public function __construct(
        private readonly Request $request,
        private readonly AuthRepository $auth,
        private readonly SiteRepository $sites,
        private readonly Authorization $authorization,
        private readonly BlueprintRepository $blueprints,
        private readonly GetBlueprintEditorSchema $editorSchema,
    ) {}

    public function index(): Response
    {
        $this->auth->requireAuth();
        $siteId = $this->requireForSite('blueprints.read');
        $resourceType = $this->request->input('resource_type');
        return Response::success(
            $this->blueprints->list(is_string($resourceType) ? $resourceType : null, $siteId),
            'admin.blueprints.index.v1'
        );
    }

    public function model(): Response
    {
        $this->auth->requireAuth();
        $siteId = $this->requireForSite('blueprints.read');
        return Response::success($this->blueprints->modelOverview($siteId), 'admin.blueprints.model.v1');
    }


    public function fieldTypes(): Response
    {
        $this->auth->requireAuth();
        $this->requireForSite('blueprints.read');
        return Response::success($this->blueprints->fieldTypes(), 'admin.field_types.index.v1');
    }

    public function design(string $key): Response
    {
        $this->auth->requireAuth();
        $siteId = $this->requireForSite('blueprints.read');
        $resourceType = $this->request->input('resource_type');
        try {
            return Response::success($this->blueprints->design($key, is_string($resourceType) ? $resourceType : null, $siteId), 'admin.blueprints.design.v1');
        } catch (\RuntimeException) {
            return $this->notFound($key);
        }
    }

    public function saveDesign(string $key): Response
    {
        $this->auth->requireAuth();
        $payload = $this->payload();
        $siteId = $this->requireForSite('blueprints.manage', $payload);
        $payload['site_id'] = $siteId;
        try {
            return Response::success($this->blueprints->saveDesign($key, $payload, $siteId), 'admin.blueprints.design.v1');
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['blueprint' => [$e->getMessage()]]);
        }
    }

    public function destroy(string $key): Response
    {
        $this->auth->requireAuth();
        $siteId = $this->requireForSite('blueprints.manage');
        $resourceType = $this->request->input('resource_type');
        try {
            return Response::success($this->blueprints->deleteBlueprint($key, is_string($resourceType) ? $resourceType : null, $siteId), 'admin.blueprints.delete.v1');
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['blueprint' => [$e->getMessage()]]);
        } catch (\RuntimeException) {
            return $this->notFound($key);
        }
    }

    public function fieldsets(): Response
    {
        $this->auth->requireAuth();
        $this->requireForSite('blueprints.read');
        return Response::success($this->blueprints->fieldsets(), 'admin.fieldsets.index.v1');
    }

    public function showFieldset(string $key): Response
    {
        $this->auth->requireAuth();
        $this->requireForSite('blueprints.read');
        try {
            return Response::success($this->blueprints->fieldset($key), 'admin.fieldsets.show.v1');
        } catch (\RuntimeException) {
            return Response::error(ErrorCode::CONTENT_TYPE_NOT_FOUND, 'Fieldset introuvable.', ErrorCode::httpStatus(ErrorCode::CONTENT_TYPE_NOT_FOUND), ['fieldset_key' => $key]);
        }
    }

    public function storeFieldset(): Response
    {
        $this->auth->requireAuth();
        $this->requireForSite('blueprints.manage');
        try {
            return Response::success($this->blueprints->saveFieldset(null, $this->payload()), 'admin.fieldsets.write.v1', [], 201);
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['fieldset' => [$e->getMessage()]]);
        }
    }

    public function updateFieldset(string $key): Response
    {
        $this->auth->requireAuth();
        $this->requireForSite('blueprints.manage');
        try {
            return Response::success($this->blueprints->saveFieldset($key, $this->payload()), 'admin.fieldsets.write.v1');
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['fieldset' => [$e->getMessage()]]);
        }
    }

    public function destroyFieldset(string $key): Response
    {
        $this->auth->requireAuth();
        $this->requireForSite('blueprints.manage');
        try {
            return Response::success($this->blueprints->deleteFieldset($key), 'admin.fieldsets.delete.v1');
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['fieldset' => [$e->getMessage()]]);
        } catch (\RuntimeException) {
            return Response::error(ErrorCode::CONTENT_TYPE_NOT_FOUND, 'Fieldset introuvable.', ErrorCode::httpStatus(ErrorCode::CONTENT_TYPE_NOT_FOUND), ['fieldset_key' => $key]);
        }
    }

    public function show(string $key): Response
    {
        $this->auth->requireAuth();
        $siteId = $this->requireForSite('blueprints.read');
        $blueprint = $this->blueprints->findByKey($key, null, $siteId);
        if (!$blueprint) {
            return $this->notFound($key);
        }
        return Response::success($blueprint, 'admin.blueprints.show.v1');
    }

    public function versions(string $key): Response
    {
        $this->auth->requireAuth();
        $siteId = $this->requireForSite('blueprints.read');
        $versions = $this->blueprints->versions($key, null, $siteId);
        if ($versions === [] && !$this->blueprints->findByKey($key, null, $siteId)) {
            return $this->notFound($key);
        }
        return Response::success($versions, 'admin.blueprints.versions.v1');
    }

    public function editorSchema(string $key): Response
    {
        $this->auth->requireAuth();
        $site = AdminApiContract::siteContext($this->request, $this->sites, $this->optionalInt($this->request->input('site_id')), $this->auth);
        $siteId = (int) $site['id'];
        if (!$this->auth->hasPermission('blueprints.read', $siteId) && !$this->auth->hasPermission('content.read', $siteId)) {
            $this->authorization->require('blueprints.read', $siteId);
        }
        try {
            return Response::success($this->editorSchema->execute($key, $siteId), 'admin.blueprints.editor_schema.v1', AdminApiContract::meta($site, (string) ($site['default_language_code'] ?? 'fr')));
        } catch (\RuntimeException) {
            return $this->notFound($key);
        }
    }

    public function store(): Response
    {
        $this->auth->requireAuth();
        $payload = $this->payload();
        $siteId = $this->requireForSite('blueprints.manage', $payload);
        $payload['site_id'] = $siteId;
        return Response::success($this->blueprints->createBlueprint($payload), 'admin.blueprints.write.v1', [], 201);
    }

    public function storeVersion(string $key): Response
    {
        $this->auth->requireAuth();
        $payload = $this->normalizeVersionPayload($this->payload());
        $siteId = $this->requireForSite('blueprints.manage', $payload);
        $payload['site_id'] = $siteId;
        try {
            return Response::success($this->blueprints->createVersion($key, $payload, $siteId), 'admin.blueprints.versions.write.v1', [], 201);
        } catch (\RuntimeException) {
            return $this->notFound($key);
        }
    }

    public function activate(string $key): Response
    {
        $this->auth->requireAuth();
        $payload = $this->payload();
        $version = isset($payload['version']) ? (int) $payload['version'] : (int) $this->request->input('version', 0);
        if ($version <= 0) {
            return Response::validation(['version' => ['version est obligatoire et doit être un entier positif.']]);
        }
        $siteId = $this->requireForSite('blueprints.manage', $payload);
        try {
            return Response::success($this->blueprints->activate($key, $version, $siteId), 'admin.blueprints.activate.v1');
        } catch (\RuntimeException) {
            return $this->notFound($key);
        }
    }


    /** @return array<string,mixed> */
    private function payload(): array
    {
        $json = $this->request->json();
        $data = $json['data'] ?? $json;
        return is_array($data) ? $data : [];
    }

    private function optionalInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }

    /** @param array<string,mixed> $payload */
    private function requireForSite(string $permission, array $payload = []): int
    {
        $requestedSiteId = $this->optionalInt($payload['site_id'] ?? $this->request->input('site_id'));
        $site = AdminApiContract::siteContext($this->request, $this->sites, $requestedSiteId, $this->auth);
        $siteId = (int) $site['id'];
        $this->authorization->require($permission, $siteId);
        return $siteId;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function normalizeVersionPayload(array $payload): array
    {
        foreach ([
            'schema', 'ui_schema', 'validation', 'seo_policy', 'routing_policy',
            'workflow_policy', 'translation_policy', 'permissions_policy',
        ] as $shortKey) {
            $jsonKey = $shortKey . '_json';
            if (array_key_exists($shortKey, $payload) && !array_key_exists($jsonKey, $payload)) {
                $payload[$jsonKey] = $payload[$shortKey];
            }
        }
        return $payload;
    }

    private function notFound(string $key): Response
    {
        return Response::error(
            ErrorCode::CONTENT_TYPE_NOT_FOUND,
            'Blueprint introuvable ou sans version active.',
            ErrorCode::httpStatus(ErrorCode::CONTENT_TYPE_NOT_FOUND),
            ['blueprint_key' => $key]
        );
    }
}
