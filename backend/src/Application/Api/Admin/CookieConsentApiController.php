<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Cookies\CookieConsentRepository;
use App\Core\ErrorCode;
use App\Core\Request;
use App\Core\RuntimeCachePurger;
use App\Core\Response;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;
use InvalidArgumentException;

final class CookieConsentApiController
{
    public function __construct(
        private readonly Request $request,
        private readonly CookieConsentRepository $cookies,
        private readonly SiteRepository $sites,
        private readonly AuthRepository $auth,
        private readonly Authorization $authorization,
    ) {}

    public function index(): Response
    {
        $this->auth->requireAuth();
        $this->authorization->require('cookies.read');
        $site = $this->site();
        $language = $this->language($site);
        $this->cookies->ensureSiteDefaults((int)$site['id'], $this->sites->getLanguages((int)$site['id']), (string)($site['default_language_code'] ?? 'fr'));
        return Response::success($this->cookies->adminState((int)$site['id'], $language, (string)($site['default_language_code'] ?? 'fr')), 'admin.cookies.index.v1', $this->meta($site));
    }

    public function updateSettings(): Response
    {
        return $this->write(fn(array $site, string $language) => $this->cookies->updateSetting((int)$site['id'], $this->payload(), $language));
    }

    public function storeCategory(): Response
    {
        return $this->write(fn(array $site, string $language) => $this->cookies->saveCategory((int)$site['id'], null, $this->payload(), $language), 201);
    }

    public function updateCategory(string|int $id): Response
    {
        return $this->write(fn(array $site, string $language) => $this->cookies->saveCategory((int)$site['id'], $this->id($id), $this->payload(), $language));
    }

    public function destroyCategory(string|int $id): Response
    {
        $this->auth->requireAuth();
        $this->authorization->require('cookies.manage');
        $site = $this->site();
        try {
            $this->cookies->deleteCategory((int)$site['id'], $this->id($id));
            $this->purgeRuntimeCache();
            return Response::success(['deleted' => true], 'admin.cookies.delete.v1', $this->meta($site));
        } catch (InvalidArgumentException $e) { return $this->validation($e); }
    }

    public function storeService(): Response
    {
        return $this->write(fn(array $site, string $language) => $this->cookies->saveService((int)$site['id'], null, $this->payload(), $language), 201);
    }

    public function updateService(string|int $id): Response
    {
        return $this->write(fn(array $site, string $language) => $this->cookies->saveService((int)$site['id'], $this->id($id), $this->payload(), $language));
    }

    public function destroyService(string|int $id): Response
    {
        $this->auth->requireAuth();
        $this->authorization->require('cookies.manage');
        $site = $this->site();
        $this->cookies->deleteService((int)$site['id'], $this->id($id));
        $this->purgeRuntimeCache();
        return Response::success(['deleted' => true], 'admin.cookies.delete.v1', $this->meta($site));
    }

    public function storeBinding(): Response
    {
        return $this->write(fn(array $site) => $this->cookies->saveBinding((int)$site['id'], null, $this->payload()), 201);
    }

    public function updateBinding(string|int $id): Response
    {
        return $this->write(fn(array $site) => $this->cookies->saveBinding((int)$site['id'], $this->id($id), $this->payload()));
    }

    public function destroyBinding(string|int $id): Response
    {
        $this->auth->requireAuth();
        $this->authorization->require('cookies.manage');
        $site = $this->site();
        $this->cookies->deleteBinding((int)$site['id'], $this->id($id));
        $this->purgeRuntimeCache();
        return Response::success(['deleted' => true], 'admin.cookies.delete.v1', $this->meta($site));
    }


    public function clearLogs(): Response
    {
        $this->auth->requireAuth();
        $this->authorization->require('cookies.manage');
        $site = $this->site();
        $language = $this->language($site);
        $this->cookies->clearLogs((int)$site['id']);
        return Response::success($this->cookies->adminState((int)$site['id'], $language, (string)($site['default_language_code'] ?? 'fr')), 'admin.cookies.delete.v1', $this->meta($site));
    }

    private function write(callable $callback, int $status = 200): Response
    {
        $this->auth->requireAuth();
        $this->authorization->require('cookies.manage');
        $site = $this->site();
        $language = $this->language($site);
        try {
            $result = $callback($site, $language);
            $this->purgeRuntimeCache();
            return Response::success($result, 'admin.cookies.write.v1', $this->meta($site), $status);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    private function purgeRuntimeCache(): void
    {
        RuntimeCachePurger::purgeTwigCache();
    }

    private function validation(InvalidArgumentException $e): Response
    {
        return Response::validation(['cookies' => [$e->getMessage()]], 'Configuration cookies invalide.');
    }

    private function site(): array { return $this->sites->resolveCurrentSite((string)($this->request->server['HTTP_HOST'] ?? '')); }
    private function language(array $site): string { return strtolower((string)($this->request->query['lang'] ?? $this->request->query['content_language_code'] ?? $site['default_language_code'] ?? 'fr')); }
    private function payload(): array { $json = $this->request->json(); $data = $json['data'] ?? $json; return is_array($data) ? $data : []; }
    private function meta(array $site): array { return ['site_id' => (int)$site['id'], 'language_code' => $this->language($site)]; }
    private function id(string|int $id): int { $value = (int)$id; if ($value <= 0) { throw new InvalidArgumentException('Identifiant invalide.'); } return $value; }
}
