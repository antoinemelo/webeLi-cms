<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Application\Configuration\MultisiteRepository;
use App\Core\Request;
use App\Core\Response;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;
use InvalidArgumentException;

final class MultisiteApiController
{
    public function __construct(
        private readonly Request $request,
        private readonly SiteRepository $sites,
        private readonly AuthRepository $auth,
        private readonly Authorization $authorization,
        private readonly MultisiteRepository $multisite,
    ) {}

    public function index(): Response
    {
        $site = $this->requireMainSite('settings.read');
        return Response::success($this->multisite->overview(), 'admin.multisite.v1', AdminApiContract::meta($site, (string) ($site['default_language_code'] ?? 'fr')));
    }

    public function store(): Response
    {
        $site = $this->requireMainSite('settings.manage');
        $payload = AdminApiContract::dataPayload($this->request, true);
        try {
            $created = $this->multisite->create($payload, isset($this->auth->user()['id']) ? (int) $this->auth->user()['id'] : null);
        } catch (InvalidArgumentException $e) {
            return $this->validationFromException($e);
        }

        return Response::success(['site' => $created, 'multisite' => $this->multisite->overview()], 'admin.multisite.v1', AdminApiContract::meta($site, (string) ($site['default_language_code'] ?? 'fr'), ['created_site_id' => $created['id'] ?? null]));
    }

    public function destroy(int $id): Response
    {
        $site = $this->requireMainSite('settings.manage');
        try {
            $result = $this->multisite->delete($id);
        } catch (InvalidArgumentException $e) {
            return $this->validationFromException($e);
        }

        return Response::success(['result' => $result, 'multisite' => $this->multisite->overview()], 'admin.multisite.v1', AdminApiContract::meta($site, (string) ($site['default_language_code'] ?? 'fr'), ['deleted_site_id' => $id]));
    }

    /** @return array<string,mixed> */
    private function requireMainSite(string $permission): array
    {
        $this->auth->requireAuth();
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null, $this->auth);
        if (($site['site_key'] ?? '') !== 'main') {
            throw new InvalidArgumentException('La configuration multisite se pilote depuis le site principal.');
        }
        $this->authorization->require($permission, (int) $site['id']);
        return $site;
    }

    private function validationFromException(InvalidArgumentException $e): Response
    {
        $decoded = json_decode($e->getMessage(), true);
        if (is_array($decoded) && isset($decoded['fields']) && is_array($decoded['fields'])) {
            return Response::validation($decoded['fields'], 'La configuration multisite contient des erreurs.');
        }
        return Response::validation(['multisite' => [$e->getMessage()]], 'Action multisite impossible.');
    }
}
