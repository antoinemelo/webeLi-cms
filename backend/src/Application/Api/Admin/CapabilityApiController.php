<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Application\Capability\CapabilityExecutor;
use App\Application\Capability\CapabilityRegistry;
use App\Core\Request;
use App\Core\Response;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;

final class CapabilityApiController
{
    public function __construct(
        private readonly Request $request,
        private readonly SiteRepository $sites,
        private readonly AuthRepository $auth,
        private readonly Authorization $authorization,
        private readonly CapabilityRegistry $registry,
        private readonly CapabilityExecutor $executor,
    ) {}

    public function index(): Response
    {
        $this->auth->requireAuth();
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null, $this->auth);
        $this->authorization->require('fields.read', (int) $site['id']);

        $items = [];
        foreach ($this->registry->all() as $definition) {
            if (!$this->auth->hasPermission($definition->permission, (int) $site['id'])) {
                continue;
            }
            $items[] = $definition->toArray();
        }

        return Response::success([
            'capabilities' => $items,
            'count' => count($items),
        ], 'admin.capabilities.index.v1', AdminApiContract::meta($site, AdminApiContract::language($this->request, $this->sites, $site)));
    }

    public function dryRun(string $key): Response
    {
        return $this->execute($key, 'dry_run');
    }

    public function apply(string $key): Response
    {
        return $this->execute($key, 'apply');
    }

    private function execute(string $key, string $mode): Response
    {
        $this->auth->requireAuth();
        $input = AdminApiContract::dataPayload($this->request, false);
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($input['site_id']) ? (int) $input['site_id'] : null, $this->auth);
        $definition = $this->registry->get($key);
        if ($definition !== null) {
            $this->authorization->require($definition->permission, (int) $site['id']);
        }

        $payload = is_array($input['input'] ?? null) ? $input['input'] : $input;
        $confirmed = (bool) ($input['confirm'] ?? $input['confirmed'] ?? false);
        $result = $this->executor->execute($key, $payload, $mode, (int) $site['id'], $confirmed);
        $status = $result->ok ? 200 : match ($result->status) {
            'forbidden' => 403,
            'not_found' => 404,
            'validation_failed' => 422,
            'confirmation_required' => 409,
            default => 400,
        };

        return Response::success($result->toArray(), 'admin.capabilities.action.v1', AdminApiContract::meta($site, AdminApiContract::language($this->request, $this->sites, $site)), $status);
    }
}
