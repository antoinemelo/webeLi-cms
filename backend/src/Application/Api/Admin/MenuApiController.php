<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Core\ErrorCode;
use App\Core\Request;
use App\Core\Response;
use App\Repository\AuthRepository;
use App\Repository\MenuRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;

final class MenuApiController
{
    public function __construct(
        private readonly Request $request,
        private readonly MenuRepository $menus,
        private readonly SiteRepository $sites,
        private readonly AuthRepository $auth,
        private readonly Authorization $authorization,
    ) {}

    public function index(): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->authorization->require('menu.read', (int) $site['id']);
        return Response::success($this->menus->listMenus((int) $site['id']), 'admin.menus.index.v1', $this->meta($site));
    }

    public function show(string $key): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->authorization->require('menu.read', (int) $site['id']);
        $lang = $this->language($site);
        $menu = $this->menus->menuWithTree((int) $site['id'], $key, $lang);
        if (!$menu) {
            return $this->notFound($key, $site, $lang);
        }
        return Response::success($menu, 'admin.menus.show.v1', $this->meta($site, $lang));
    }

    public function store(): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->authorization->require('menu.manage', (int) $site['id']);
        try {
            $menu = $this->menus->createMenu((int) $site['id'], $this->payload());
            return Response::success($menu, 'admin.menus.write.v1', $this->meta($site));
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['menu' => [$e->getMessage()]], 'Menu invalide.');
        }
    }

    public function update(string $key): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->authorization->require('menu.manage', (int) $site['id']);
        try {
            $menu = $this->menus->updateMenu((int) $site['id'], $key, $this->payload());
            if (!$menu) {
                return $this->notFound($key, $site, $this->language($site));
            }
            return Response::success($menu, 'admin.menus.write.v1', $this->meta($site));
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['menu' => [$e->getMessage()]], 'Menu invalide.');
        }
    }

    public function replaceItems(string $key): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->authorization->require('menu.manage', (int) $site['id']);
        $lang = $this->language($site);
        $payload = $this->payload();
        $items = $payload['items'] ?? [];
        if (!is_array($items)) {
            return Response::validation(['items' => ['La liste items est obligatoire.']], 'Menu invalide.');
        }
        try {
            $menu = $this->menus->replaceItems((int) $site['id'], $key, $lang, $items);
            if (!$menu) {
                return $this->notFound($key, $site, $lang);
            }
            return Response::success($menu, 'admin.menus.items.replace.v1', $this->meta($site, $lang));
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['items' => [$e->getMessage()]], 'Menu invalide.');
        }
    }

    public function destroy(string $key): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->authorization->require('menu.manage', (int) $site['id']);
        if (!$this->menus->deleteMenu((int) $site['id'], $key)) {
            return $this->notFound($key, $site, $this->language($site));
        }
        return Response::success(['deleted' => true, 'menu_key' => $key], 'admin.menus.delete.v1', $this->meta($site));
    }

    private function site(): array
    {
        $payload = in_array($this->request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? $this->payload() : [];
        $siteId = isset($payload['site_id']) ? (int) $payload['site_id'] : (isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null);
        return AdminApiContract::siteContext($this->request, $this->sites, $siteId, $this->auth);
    }

    private function language(array $site): string
    {
        return (string) ($this->request->query['lang'] ?? $site['default_language_code'] ?? 'fr');
    }

    private function payload(): array
    {
        $json = $this->request->json();
        $data = $json['data'] ?? $json;
        return is_array($data) ? $data : [];
    }

    private function meta(array $site, ?string $language = null): array
    {
        return ['site_id' => (int) $site['id'], 'language_code' => $language ?? (string) ($site['default_language_code'] ?? 'fr')];
    }

    private function notFound(string $key, array $site, string $language): Response
    {
        return Response::error(ErrorCode::MENU_NOT_FOUND, ErrorCode::message(ErrorCode::MENU_NOT_FOUND), ErrorCode::httpStatus(ErrorCode::MENU_NOT_FOUND), [
            'menu_key' => $key,
            'site_id' => (int) $site['id'],
            'language_code' => $language,
        ]);
    }
}
