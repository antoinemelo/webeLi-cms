<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Application\Configuration\ConfigurationRepository;
use App\Core\Request;
use App\Core\Response;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;

final class ConfigurationApiController
{
    public function __construct(
        private readonly Request $request,
        private readonly SiteRepository $sites,
        private readonly AuthRepository $auth,
        private readonly Authorization $authorization,
        private readonly ConfigurationRepository $configuration,
    ) {}

    public function show(): Response
    {
        $this->auth->requireAuth();
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null, $this->auth);
        $this->authorization->require('settings.read', (int) $site['id']);
        $languageCode = AdminApiContract::language($this->request, $this->sites, $site);
        $uiLanguageCode = $this->uiLanguageCode();

        return Response::success(
            $this->configuration->load((int) $site['id'], $languageCode, $uiLanguageCode),
            'admin.configuration.v1',
            AdminApiContract::meta($site, $languageCode)
        );
    }


    /** @param array<string,mixed> $payload */
    private function uiLanguageCode(array $payload = []): string
    {
        $code = (string) ($payload['ui_language_code'] ?? $this->request->query['ui_language_code'] ?? 'fr');
        return in_array($code, ['fr', 'en'], true) ? $code : 'fr';
    }

    public function update(): Response
    {
        $this->auth->requireAuth();
        $payload = AdminApiContract::dataPayload($this->request, true);
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($payload['site_id']) ? (int) $payload['site_id'] : (isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null), $this->auth);
        $this->authorization->require('settings.manage', (int) $site['id']);
        $languageCode = AdminApiContract::language($this->request, $this->sites, $site, $payload);
        $uiLanguageCode = $this->uiLanguageCode($payload);

        $groupKey = (string) ($payload['group_key'] ?? '');
        $values = $payload['values'] ?? [];
        if (!is_array($values)) {
            return Response::validation(['values' => ['values doit être un objet JSON.']]);
        }

        $validated = $this->configuration->validate($groupKey, $values, (int) $site['id'], $languageCode);
        if ($validated['fields'] !== []) {
            return Response::validation($validated['fields'], 'La configuration contient des erreurs.');
        }

        $user = $this->auth->user();
        $this->configuration->save($groupKey, $validated['values'], (int) $site['id'], isset($user['id']) ? (int) $user['id'] : null);

        return Response::success(
            $this->configuration->load((int) $site['id'], $languageCode, $uiLanguageCode),
            'admin.configuration.v1',
            AdminApiContract::meta($site, $languageCode, ['updated_group' => $groupKey])
        );
    }
}
