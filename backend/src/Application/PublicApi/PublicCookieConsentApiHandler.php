<?php

declare(strict_types=1);

namespace App\Application\PublicApi;

use App\Application\Cookies\CookieConsentRepository;
use App\Core\Request;
use App\Core\Response;
use App\Repository\SiteRepository;

final class PublicCookieConsentApiHandler
{
    public function __construct(
        private readonly Request $request,
        private readonly CookieConsentRepository $cookies,
        private readonly SiteRepository $sites,
    ) {}

    public function config(): Response
    {
        $site = $this->site();
        $language = $this->language($site);
        $languages = $this->sites->getLanguages((int)$site['id']);
        $this->cookies->ensureSiteDefaults((int)$site['id'], $languages, (string)($site['default_language_code'] ?? 'fr'));
        return Response::success(
            $this->cookies->publicConfig((int)$site['id'], $language, (string)($site['default_language_code'] ?? 'fr')),
            'public.cookies.config.v1',
            ['site_id' => (int)$site['id'], 'language_code' => $language]
        );
    }

    public function log(): Response
    {
        $site = $this->site();
        $language = $this->language($site);
        $payload = $this->payload();
        $this->cookies->logConsent(
            (int)$site['id'],
            $language,
            $payload,
            (string)($this->request->server['REMOTE_ADDR'] ?? ''),
            (string)($this->request->server['HTTP_USER_AGENT'] ?? '')
        );
        $this->cookies->pruneLogs((int)$site['id']);
        return Response::success(['logged' => true], 'public.cookies.log.v1', ['site_id' => (int)$site['id'], 'language_code' => $language]);
    }

    private function site(): array
    {
        return $this->sites->resolveCurrentSite((string)($this->request->server['HTTP_HOST'] ?? ''));
    }

    private function language(array $site): string
    {
        return strtolower((string)($this->request->query['lang'] ?? $this->request->query['language_code'] ?? $site['default_language_code'] ?? 'fr'));
    }

    private function payload(): array
    {
        $json = $this->request->json();
        $data = $json['data'] ?? $json;
        return is_array($data) ? $data : [];
    }
}
