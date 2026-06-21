<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;
use App\Module\ModuleLifecycleService;
use App\Module\ModuleNavigationRegistry;
use App\Module\ModuleContractRegistry;
use App\Security\Authorization;
use App\Security\Csrf;

final class AdminContextApiController
{
    private const AUTHORIZATION_MODE = 'authenticated_only_context';

    public function __construct(
        private readonly array $config,
        private readonly Request $request,
        private readonly Database $db,
        private readonly SiteRepository $sites,
        private readonly AuthRepository $auth,
        private readonly Authorization $authorization,
        private readonly ModuleLifecycleService $modules,
        private readonly ModuleNavigationRegistry $moduleNavigation,
        private readonly ModuleContractRegistry $moduleContracts,
    ) {}

    public function show(): Response
    {
        $this->auth->requireAuth();
        // Authenticated-only endpoint: it exposes the current context and capabilities
        // so a signed-in user with no role can open a session without receiving 403.
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null, $this->auth);
        $languageCode = AdminApiContract::language($this->request, $this->sites, $site);
        $uiLanguageCode = $this->uiLanguageCode((int) $site['id']);

        $permissions = $this->auth->permissions((int) $site['id']);
        $priorityPermissions = [
            'content.read',
            'content.create',
            'content.update',
            'content.revisions.save',
            'content.revisions.restore',
            'content.revisions.prune',
            'content.publish',
            'imports_exports.read',
            'imports_exports.write',
            'imports_exports.manage',
            'content.unpublish',
            'content.archive',
            'content.delete',
            'content.preview',
            'blueprints.read',
            'media.read',
            'media.upload',
            'taxonomy.read',
            'menu.read',
            'settings.read',
            'settings.manage',
            'users.read',
            'users.manage',
            'roles.read',
            'roles.manage',
            'sessions.read',
            'sessions.manage',
            'audit.read',
            'security.tokens.read',
            'security.tokens.manage',
            'security.webhooks.read',
            'security.webhooks.manage',
            'security.cors.read',
            'security.cors.manage',
            'users.email_2fa.manage',
        ];

        return Response::success([
            'api' => [
                'contract_version' => AdminApiContract::VERSION,
                'contract_header' => AdminApiContract::HEADER_CONTRACT_VERSION,
                'csrf' => AdminApiContract::csrfRequirement(),
                'response_envelope' => ['success' => 'data + meta', 'error' => 'error + meta'],
            ],
            'site' => $this->siteContract($site),
            'available_sites' => $this->availableSiteContracts($site),
            'content_languages' => $this->languageContracts($this->sites->getLanguages((int) $site['id']), $languageCode),
            'current_content_language_code' => $languageCode,
            // Backward-compatible aliases kept during the admin API v1 transition.
            'languages' => $this->languageContracts($this->sites->getLanguages((int) $site['id']), $languageCode),
            'current_language_code' => $languageCode,
            'ui' => [
                'language_code' => $uiLanguageCode,
                'available_languages' => $this->uiLanguageContracts($uiLanguageCode),
            ],
            'user' => $this->userContract(),
            'permissions' => $permissions,
            'capabilities' => AdminApiContract::permissionsBlock($this->auth, (int) $site['id'], array_values(array_unique(array_merge($priorityPermissions, ['modules.read', 'modules.manage'])))),
            'site_roles' => $this->auth->siteRoles(),
            'authorized_site_ids' => $this->auth->authorizedSiteIds(),
            'current_site_access' => $this->auth->canAccessSite((int) $site['id']),
            'csrf_token' => Csrf::token(),
            'features' => [
                'multisite' => true,
                'multilingual' => true,
                'media' => true,
                'docs' => true,
                'menus' => true,
                'taxonomies' => true,
                'forms' => (bool) ($this->config['cms']['features']['forms'] ?? false),
                'modules' => true,
            ],
            'modules' => $this->modulesBlock(),
            'endpoints' => array_merge($this->priorityEndpoints(), $this->moduleContracts->endpoints()),
        ], 'admin.context.v1', AdminApiContract::meta($site, $languageCode));
    }


    /** @return array<string,mixed> */
    private function modulesBlock(): array
    {
        $modules = $this->modules->list();
        $installed = array_values(array_filter($modules, static fn(array $module): bool => (bool) ($module['installed'] ?? false)));
        $active = array_values(array_filter($modules, static fn(array $module): bool => (bool) ($module['enabled'] ?? false)));
        $permissions = [];
        $alerts = [];
        foreach ($active as $module) {
            foreach (($module['permissions'] ?? []) as $permission) {
                if (is_string($permission) && $permission !== '') {
                    $permissions[] = $permission;
                }
            }
            foreach (($module['alerts'] ?? []) as $alert) {
                if (is_string($alert) && $alert !== '') {
                    $alerts[] = ['module_key' => (string) ($module['key'] ?? ''), 'message' => $alert];
                }
            }
        }

        return [
            'installed' => $installed,
            'active' => $active,
            'features' => array_reduce($active, static function (array $carry, array $module): array {
                $carry[(string) ($module['key'] ?? '')] = $module['features'] ?? [];
                return $carry;
            }, []),
            'permissions' => array_values(array_unique($permissions)),
            'navigation' => $this->moduleNavigation->entries(),
            'endpoints' => $this->moduleContracts->endpoints(),
            'contracts' => $this->moduleContracts->contracts(),
            'dependency_alerts' => $alerts,
        ];
    }


    private function uiLanguageCode(int $siteId): string
    {
        $requested = (string) ($this->request->query['ui_language_code'] ?? '');
        if (in_array($requested, ['fr', 'en'], true)) {
            return $requested;
        }

        $row = $this->db->one('SELECT value_json FROM site_settings WHERE site_id = :site_id AND namespace = :namespace AND setting_key = :setting_key LIMIT 1', [
            'site_id' => $siteId,
            'namespace' => 'backoffice',
            'setting_key' => 'defaults',
        ]);
        $settings = $row ? json_decode((string) $row['value_json'], true) : [];
        $configured = is_array($settings) ? (string) ($settings['admin_ui_language_code'] ?? 'fr') : 'fr';
        return in_array($configured, ['fr', 'en'], true) ? $configured : 'fr';
    }

    /** @return list<array<string,mixed>> */
    private function uiLanguageContracts(string $currentLanguageCode): array
    {
        return array_map(static fn(array $language): array => [
            'code' => $language['code'],
            'name' => $language['name'],
            'is_current' => $language['code'] === $currentLanguageCode,
        ], [
            ['code' => 'fr', 'name' => 'Français'],
            ['code' => 'en', 'name' => 'English'],
        ]);
    }



    /** @param array<string,mixed> $currentSite @return list<array<string,mixed>> */
    private function availableSiteContracts(array $currentSite): array
    {
        $allowed = $this->auth->authorizedSiteIds();
        $params = [];
        $where = 's.is_active = 1';
        if ($allowed !== []) {
            $placeholders = [];
            foreach ($allowed as $index => $siteId) {
                $key = 'site_id_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $siteId;
            }
            $where .= ' AND s.id IN (' . implode(',', $placeholders) . ')';
        }

        $rows = $this->db->all(
            "SELECT s.id, s.site_key, s.name, s.default_language_code,
                    COALESCE(current_domain.id, primary_domain.id) AS domain_id,
                    COALESCE(current_domain.host, primary_domain.host, '') AS host,
                    COALESCE(current_domain.base_path, primary_domain.base_path, '') AS base_path,
                    COALESCE(current_domain.scheme, primary_domain.scheme, 'https') AS scheme
             FROM sites s
             LEFT JOIN site_domains primary_domain ON primary_domain.id = (
                 SELECT sd.id FROM site_domains sd
                 WHERE sd.site_id = s.id AND sd.is_active = 1
                 ORDER BY sd.is_primary DESC, length(sd.base_path) ASC, sd.id ASC
                 LIMIT 1
             )
             LEFT JOIN site_domains current_domain ON current_domain.id = (
                 SELECT sd.id FROM site_domains sd
                 WHERE sd.site_id = s.id AND sd.is_active = 1 AND lower(sd.host) = lower(:current_host)
                 ORDER BY length(sd.base_path) DESC, sd.is_primary DESC, sd.id ASC
                 LIMIT 1
             )
             WHERE {$where}
             ORDER BY s.name, s.id",
            ['current_host' => $this->normalizedHost()] + $params
        );

        return array_map(function (array $row) use ($currentSite): array {
            $requestBasePath = $this->requestBasePath((string) ($row['base_path'] ?? ''));
            $adminPath = admin_url_path_for_site($requestBasePath, '/admin/app');
            $publicPath = url_path($requestBasePath === '' ? '/' : $requestBasePath . '/');
            $scheme = (string) ($row['scheme'] ?? 'https');
            $host = $this->hostWithoutPort((string) ($row['host'] ?? ''));
            $domainBase = $this->normalizeBasePath((string) ($row['base_path'] ?? ''));

            return [
                'id' => (int) $row['id'],
                'site_key' => (string) $row['site_key'],
                'name' => (string) $row['name'],
                'host' => $host,
                'base_path' => $domainBase,
                'request_base_path' => $requestBasePath,
                'default_language_code' => (string) $row['default_language_code'],
                'admin_path' => $adminPath,
                'public_path' => $publicPath,
                'public_url' => rtrim($scheme . '://' . $host . $domainBase, '/'),
                'is_current' => (int) $row['id'] === (int) ($currentSite['id'] ?? 0),
            ];
        }, $rows);
    }

    private function normalizedHost(): string
    {
        return $this->hostWithoutPort((string) ($this->request->server['HTTP_HOST'] ?? ''));
    }

    private function hostWithoutPort(string $host): string
    {
        $host = strtolower(trim($host));
        if (str_contains($host, ':')) {
            return explode(':', $host, 2)[0];
        }
        return $host;
    }

    private function normalizeBasePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $path === '/') {
            return '';
        }
        return '/' . trim($path, '/');
    }

    private function requestBasePath(string $domainBasePath): string
    {
        $basePath = $this->normalizeBasePath($domainBasePath);
        $appBasePath = $this->normalizeBasePath(app_base_path());
        if ($appBasePath !== '' && ($basePath === $appBasePath || str_starts_with($basePath, $appBasePath . '/'))) {
            $basePath = substr($basePath, strlen($appBasePath)) ?: '';
        }
        return $this->normalizeBasePath($basePath);
    }

    /** @param array<string,mixed> $site @return array<string,mixed> */
    private function siteContract(array $site): array
    {
        $domainBase = $this->normalizeBasePath((string) ($site['matched_base_path'] ?? $site['base_path'] ?? ''));
        $requestBasePath = $this->requestBasePath($domainBase);
        $publicPath = url_path($requestBasePath === '' ? '/' : $requestBasePath . '/');
        $publicUrl = rtrim((string) ($site['current_base_url'] ?? $site['base_url'] ?? ''), '/');

        return [
            'id' => (int) ($site['id'] ?? 0),
            'site_key' => (string) ($site['site_key'] ?? ''),
            'name' => (string) ($site['name'] ?? ''),
            'host' => (string) ($site['matched_host'] ?? $site['host'] ?? ''),
            'base_path' => $domainBase,
            'request_base_path' => $requestBasePath,
            'default_language_code' => (string) ($site['default_language_code'] ?? ''),
            'is_active' => (bool) ($site['is_active'] ?? true),
            'public_path' => $publicPath,
            'public_url' => $publicUrl,
        ];
    }

    /** @param list<array<string,mixed>> $languages @return list<array<string,mixed>> */
    private function languageContracts(array $languages, string $currentLanguageCode): array
    {
        return array_map(static fn(array $language): array => [
            'code' => (string) ($language['language_code'] ?? $language['code'] ?? ''),
            'name' => (string) ($language['name'] ?? $language['label'] ?? ''),
            'url_prefix' => (string) ($language['url_prefix'] ?? ''),
            'is_default' => (bool) ($language['is_default'] ?? false),
            'is_enabled' => (bool) ($language['is_enabled'] ?? true),
            'is_current' => (string) ($language['language_code'] ?? $language['code'] ?? '') === $currentLanguageCode,
        ], $languages);
    }

    /** @return list<array<string,string>> */
    private function priorityEndpoints(): array
    {
        return [
            ['key' => 'admin.context', 'method' => 'GET', 'path' => '/admin/api/context'],
            ['key' => 'admin.configuration.show', 'method' => 'GET', 'path' => '/admin/api/configuration'],
            ['key' => 'admin.configuration.update', 'method' => 'PATCH', 'path' => '/admin/api/configuration'],
            ['key' => 'admin.security.index', 'method' => 'GET', 'path' => '/admin/api/security'],
            ['key' => 'admin.security.tokens.store', 'method' => 'POST', 'path' => '/admin/api/security/tokens'],
            ['key' => 'admin.security.tokens.test', 'method' => 'POST', 'path' => '/admin/api/security/tokens/test'],
            ['key' => 'admin.security.tokens.update', 'method' => 'PATCH', 'path' => '/admin/api/security/tokens/{id}'],
            ['key' => 'admin.security.tokens.delete', 'method' => 'DELETE', 'path' => '/admin/api/security/tokens/{id}'],
            ['key' => 'admin.security.webhooks.store', 'method' => 'POST', 'path' => '/admin/api/security/webhooks'],
            ['key' => 'admin.security.webhooks.deliveries', 'method' => 'GET', 'path' => '/admin/api/security/webhooks/{id}/deliveries'],
            ['key' => 'admin.security.webhooks.ping', 'method' => 'POST', 'path' => '/admin/api/security/webhooks/{id}/ping'],
            ['key' => 'admin.security.webhook_deliveries.cleanup', 'method' => 'DELETE', 'path' => '/admin/api/security/webhooks/deliveries/cleanup'],
            ['key' => 'admin.security.webhooks.update', 'method' => 'PATCH', 'path' => '/admin/api/security/webhooks/{id}'],
            ['key' => 'admin.security.webhooks.delete', 'method' => 'DELETE', 'path' => '/admin/api/security/webhooks/{id}'],
            ['key' => 'admin.security.cors.update', 'method' => 'PATCH', 'path' => '/admin/api/security/cors/{siteId}'],
            ['key' => 'admin.profile.show', 'method' => 'GET', 'path' => '/admin/api/profile'],
            ['key' => 'admin.docs.index', 'method' => 'GET', 'path' => '/admin/api/docs'],
            ['key' => 'admin.docs.show', 'method' => 'GET', 'path' => '/admin/api/docs/{id}'],
            ['key' => 'admin.modules.index', 'method' => 'GET', 'path' => '/admin/api/modules'],
            ['key' => 'admin.modules.show', 'method' => 'GET', 'path' => '/admin/api/modules/{key}'],
            ['key' => 'admin.modules.install', 'method' => 'POST', 'path' => '/admin/api/modules/{key}/install'],
            ['key' => 'admin.modules.enable', 'method' => 'POST', 'path' => '/admin/api/modules/{key}/enable'],
            ['key' => 'admin.modules.disable', 'method' => 'POST', 'path' => '/admin/api/modules/{key}/disable'],
            ['key' => 'admin.modules.migrate', 'method' => 'POST', 'path' => '/admin/api/modules/{key}/migrate'],
            ['key' => 'admin.modules.health', 'method' => 'GET', 'path' => '/admin/api/modules/{key}/health'],
            ['key' => 'admin.profile.change_password', 'method' => 'PATCH', 'path' => '/admin/api/profile/password'],
            ['key' => 'admin.profile.update', 'method' => 'PATCH', 'path' => '/admin/api/profile'],
            ['key' => 'admin.iam.users.index', 'method' => 'GET', 'path' => '/admin/api/iam/users'],
            ['key' => 'admin.iam.users.store', 'method' => 'POST', 'path' => '/admin/api/iam/users'],
            ['key' => 'admin.iam.users.show', 'method' => 'GET', 'path' => '/admin/api/iam/users/{id}'],
            ['key' => 'admin.iam.users.update', 'method' => 'PATCH', 'path' => '/admin/api/iam/users/{id}'],
            ['key' => 'admin.iam.users.activate', 'method' => 'POST', 'path' => '/admin/api/iam/users/{id}/activate'],
            ['key' => 'admin.iam.users.deactivate', 'method' => 'POST', 'path' => '/admin/api/iam/users/{id}/deactivate'],
            ['key' => 'admin.iam.users.reset_password', 'method' => 'POST', 'path' => '/admin/api/iam/users/{id}/reset-password'],
            ['key' => 'admin.iam.users.destroy', 'method' => 'DELETE', 'path' => '/admin/api/iam/users/{id}'],
            ['key' => 'admin.iam.roles.index', 'method' => 'GET', 'path' => '/admin/api/iam/roles'],
            ['key' => 'admin.iam.roles.store', 'method' => 'POST', 'path' => '/admin/api/iam/roles'],
            ['key' => 'admin.iam.roles.update', 'method' => 'PATCH', 'path' => '/admin/api/iam/roles/{id}'],
            ['key' => 'admin.iam.permissions.index', 'method' => 'GET', 'path' => '/admin/api/iam/permissions'],
            ['key' => 'admin.iam.sessions.index', 'method' => 'GET', 'path' => '/admin/api/iam/sessions'],
            ['key' => 'admin.iam.sessions.revoke', 'method' => 'DELETE', 'path' => '/admin/api/iam/sessions/{id}'],
            ['key' => 'admin.iam.audit.index', 'method' => 'GET', 'path' => '/admin/api/iam/audit-logs'],
            ['key' => 'admin.entries.index', 'method' => 'GET', 'path' => '/admin/api/entries'],
            ['key' => 'admin.entries.show', 'method' => 'GET', 'path' => '/admin/api/entries/{id}'],
            ['key' => 'admin.entries.save_draft.create', 'method' => 'POST', 'path' => '/admin/api/entries'],
            ['key' => 'admin.entries.save_draft.update', 'method' => 'PATCH', 'path' => '/admin/api/entries/{id}'],
            ['key' => 'admin.entries.publish', 'method' => 'POST', 'path' => '/admin/api/entries/{id}/publish'],
            ['key' => 'admin.entries.unpublish', 'method' => 'POST', 'path' => '/admin/api/entries/{id}/unpublish'],
            ['key' => 'admin.entries.archive', 'method' => 'POST', 'path' => '/admin/api/entries/{id}/archive'],
            ['key' => 'admin.entries.destroy', 'method' => 'DELETE', 'path' => '/admin/api/entries/{id}'],
            ['key' => 'admin.entries.preview', 'method' => 'GET', 'path' => '/admin/api/entries/{id}/preview'],
            ['key' => 'admin.content_types.index', 'method' => 'GET', 'path' => '/admin/api/content-types'],
            ['key' => 'admin.content_types.editor_schema', 'method' => 'GET', 'path' => '/admin/api/content-types/{type}/editor-schema'],
            ['key' => 'admin.taxonomies.index', 'method' => 'GET', 'path' => '/admin/api/taxonomies'],
            ['key' => 'admin.taxonomies.terms', 'method' => 'GET', 'path' => '/admin/api/taxonomy-terms'],
            ['key' => 'admin.menus.index', 'method' => 'GET', 'path' => '/admin/api/menus'],
            ['key' => 'admin.menus.show', 'method' => 'GET', 'path' => '/admin/api/menus/{key}'],
            ['key' => 'admin.seo.audit', 'method' => 'GET', 'path' => '/admin/api/seo/audit'],
            ['key' => 'admin.media.index', 'method' => 'GET', 'path' => '/admin/api/media'],
            ['key' => 'admin.media.upload', 'method' => 'POST', 'path' => '/admin/api/media'],
            ['key' => 'admin.media.folders.index', 'method' => 'GET', 'path' => '/admin/api/media/folders'],
            ['key' => 'admin.media.variant_presets.index', 'method' => 'GET', 'path' => '/admin/api/media/variant-presets'],
            ['key' => 'admin.media.folders.store', 'method' => 'POST', 'path' => '/admin/api/media/folders'],
            ['key' => 'admin.media.folders.update', 'method' => 'PATCH', 'path' => '/admin/api/media/folders/{id}'],
            ['key' => 'admin.media.unused', 'method' => 'GET', 'path' => '/admin/api/media/unused'],
            ['key' => 'admin.media.show', 'method' => 'GET', 'path' => '/admin/api/media/{id}'],
            ['key' => 'admin.media.update_metadata', 'method' => 'PATCH', 'path' => '/admin/api/media/{id}'],
            ['key' => 'admin.media.generate_variants', 'method' => 'POST', 'path' => '/admin/api/media/{id}/variants'],
            ['key' => 'admin.media.attach', 'method' => 'POST', 'path' => '/admin/api/media/{id}/attach'],
            ['key' => 'admin.media.delete_safe', 'method' => 'DELETE', 'path' => '/admin/api/media/{id}'],
        ];
    }
    /** @return array{id:int,email:string,name:string} */
    private function userContract(): array
    {
        $user = $this->auth->user() ?? [];
        $profile = $this->auth->currentUserProfile();
        $profileName = trim(((string) ($profile['first_name'] ?? '')) . ' ' . ((string) ($profile['last_name'] ?? '')));
        $name = trim((string) ($user['name'] ?? ''));
        if ($name === '') {
            $name = $profileName;
        }

        return [
            'id' => (int) ($user['id'] ?? $profile['id'] ?? 0),
            'email' => (string) ($user['email'] ?? $profile['email'] ?? ''),
            'name' => $name !== '' ? $name : (string) ($user['email'] ?? $profile['email'] ?? ''),
        ];
    }

}
