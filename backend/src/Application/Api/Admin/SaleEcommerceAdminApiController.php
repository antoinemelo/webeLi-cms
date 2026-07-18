<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Application\Commerce\ShopConfigurationService;
use App\Core\ApiException;
use App\Core\Database;
use App\Core\ErrorCode;
use App\Core\Request;
use App\Core\Response;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;
use App\Security\Csrf;
use InvalidArgumentException;
use RuntimeException;

/** Configuration des sites e-commerce rattachée au domaine Ventes. */
final class SaleEcommerceAdminApiController
{
    public function __construct(
        private readonly Request $request,
        private readonly SiteRepository $sites,
        private readonly AuthRepository $auth,
        private readonly Authorization $authorization,
        private readonly Database $core,
        private readonly ShopConfigurationService $shops,
    ) {}

    public function shops(): Response
    {
        $this->auth->requireAuth();
        $site = $this->siteFromQuery();
        $this->requireAny(['sale.settings.manage','content.read'], (int) $site['id']);
        $languageCode = AdminApiContract::language($this->request, $this->sites, $site);

        $siteRows = $this->authorizedSiteLanguages();
        $channelsBySite = [];
        foreach ($siteRows as $row) {
            $siteId = (int) $row['id'];
            $channelsBySite[$siteId] ??= $this->shops->channels($siteId);
        }

        $rows = [];
        foreach ($siteRows as $row) {
            $siteId = (int) $row['id'];
            $locale = (string) $row['language_code'];
            $config = $this->shops->configuration($siteId, $locale);
            $channel = $this->channelById($channelsBySite[$siteId] ?? [], (int) ($config['channel_id'] ?? 0));
            $previewPath = $this->localizedShopPath((string) ($row['url_prefix'] ?? ''), (string) ($config['route_path'] ?? '/shop'));
            $rows[] = $config + [
                'site_key' => (string) $row['site_key'],
                'site_name' => (string) $row['name'],
                'language_name' => (string) ($row['native_name'] ?: $row['language_name']),
                'is_default_language' => (int) $row['is_default'] === 1,
                'channel_name' => $channel['name'] ?? null,
                'channel_code' => $channel['code'] ?? null,
                'channels' => $channelsBySite[$siteId] ?? [],
                'studio_route' => '/contents/pages/system-shop?site_id=' . $siteId . '&language_code=' . rawurlencode($locale),
                'preview_path' => !empty($config['public_visible']) ? $previewPath : null,
                'expected_preview_path' => $previewPath,
                'page_system_ready' => !empty($config['is_initialized']),
                'menu_ready' => !empty($config['public_visible']),
                'cart_ready' => !empty($config['public_visible']) && !empty($config['cart_visible']),
                'projections_ready' => !empty($config['last_rebuild_at']),
                'permissions' => $this->permissionContract($siteId),
            ];
        }

        return Response::success([
            'shops' => $rows,
            'shop_activation' => ['permission'=>'sale.settings.manage','available'=>true,'lot'=>'39'],
        ], 'admin.sale.ecommerce.shops.index.v1', [
            'site_id' => (int) $site['id'],
            'language' => $languageCode,
        ]);
    }

    public function show(int $siteId, string $locale): Response
    {
        $this->auth->requireAuth();
        $site = $this->site($siteId);
        $this->requireAny(['sale.settings.manage','content.read'], $siteId);
        $locale = AdminApiContract::language($this->request, $this->sites, $site, ['language_code'=>$locale]);
        return Response::success(
            $this->shops->configuration($siteId, $locale) + [
                'channels'=>$this->shops->channels($siteId),
                'themes'=>$this->themes(),
                'permissions'=>$this->permissionContract($siteId),
            ],
            'admin.sale.ecommerce.shops.show.v1',
            AdminApiContract::meta($site, $locale)
        );
    }

    public function save(int $siteId, string $locale): Response
    {
        $this->auth->requireAuth();
        $site = $this->site($siteId);
        $this->authorization->require('content.revisions.save', $siteId);
        $this->requireCsrf();
        $input = AdminApiContract::dataPayload($this->request, true);
        $locale = AdminApiContract::language($this->request, $this->sites, $site, ['language_code'=>$locale]);
        return $this->serviceResponse(
            fn(): array => $this->shops->saveDraft($siteId,$locale,$input,$this->actorId()),
            'admin.sale.ecommerce.shops.save_draft.v1',$site,$locale,200
        );
    }

    public function publish(int $siteId, string $locale): Response
    {
        $this->auth->requireAuth();
        $site = $this->site($siteId);
        $this->authorization->require('content.publish', $siteId);
        $this->requireCsrf();
        $locale = AdminApiContract::language($this->request, $this->sites, $site, ['language_code'=>$locale]);
        return $this->serviceResponse(
            fn(): array => $this->shops->publish($siteId,$locale,$this->actorId()),
            'admin.sale.ecommerce.shops.publish.v1',$site,$locale
        );
    }

    public function activate(int $siteId, string $locale): Response
    {
        return $this->activationAction($siteId,$locale,false);
    }

    public function repair(int $siteId, string $locale): Response
    {
        return $this->activationAction($siteId,$locale,true);
    }

    public function deactivate(int $siteId, string $locale): Response
    {
        $this->auth->requireAuth();
        $site = $this->site($siteId);
        $this->authorization->require('sale.settings.manage', $siteId);
        $this->requireCsrf();
        $locale = AdminApiContract::language($this->request, $this->sites, $site, ['language_code'=>$locale]);
        return $this->serviceResponse(
            fn(): array => $this->shops->deactivate($siteId,$locale,$this->actorId()),
            'admin.sale.ecommerce.shops.deactivate.v1',$site,$locale
        );
    }

    private function activationAction(int $siteId, string $locale, bool $repair): Response
    {
        $this->auth->requireAuth();
        $site = $this->site($siteId);
        $this->authorization->require('sale.settings.manage', $siteId);
        $this->requireCsrf();
        $locale = AdminApiContract::language($this->request, $this->sites, $site, ['language_code'=>$locale]);
        return $this->serviceResponse(
            fn(): array => $this->shops->activate($siteId,$locale,$this->actorId(),$repair),
            $repair ? 'admin.sale.ecommerce.shops.repair.v1' : 'admin.sale.ecommerce.shops.activate.v1',
            $site,$locale
        );
    }

    /** @param callable():array<string,mixed> $callback */
    private function serviceResponse(callable $callback, string $contract, array $site, string $locale, int $status = 200): Response
    {
        try {
            return Response::success($callback(),$contract,AdminApiContract::meta($site,$locale),$status);
        } catch (InvalidArgumentException $e) {
            return Response::validation(['shop'=>[$this->message($e->getMessage())]]);
        } catch (RuntimeException $e) {
            throw new ApiException('SHOP_ACTIVATION_FAILED',$this->message($e->getMessage()),409,['reason'=>'projection_rebuild_failed']);
        }
    }

    /** @return list<array<string,mixed>> */
    private function authorizedSiteLanguages(): array
    {
        $rows = $this->core->all(
            'SELECT s.id,s.site_key,s.name,s.default_language_code,sl.language_code,sl.is_default,sl.url_prefix,
                    l.name AS language_name,l.native_name
             FROM sites s JOIN site_languages sl ON sl.site_id=s.id AND sl.is_active=1
             JOIN languages l ON l.code=sl.language_code AND l.is_active=1
             WHERE s.is_active=1 ORDER BY s.id,sl.sort_order,sl.language_code'
        );
        $allowed = $this->auth->authorizedSiteIds();
        return array_values(array_filter($rows,function(array $row) use ($allowed): bool {
            $siteId = (int) $row['id'];
            if ($allowed !== [] && !in_array($siteId,$allowed,true)) return false;
            return $this->auth->hasPermission('sale.settings.manage',$siteId)
                || $this->auth->hasPermission('content.read',$siteId);
        }));
    }

    /** @return array<string,mixed> */
    private function siteFromQuery(): array
    {
        return AdminApiContract::siteContext($this->request,$this->sites,isset($this->request->query['site_id'])?(int)$this->request->query['site_id']:null,$this->auth);
    }

    /** @return array<string,mixed> */
    private function site(int $siteId): array
    {
        return AdminApiContract::siteContext($this->request,$this->sites,$siteId,$this->auth);
    }

    /** @param list<string> $permissions */
    private function requireAny(array $permissions, int $siteId): void
    {
        foreach ($permissions as $permission) if ($this->auth->hasPermission($permission,$siteId)) return;
        throw new ApiException(ErrorCode::AUTHZ_FORBIDDEN,ErrorCode::message(ErrorCode::AUTHZ_FORBIDDEN),403,['site_id'=>$siteId]);
    }

    /** @return array<string,bool> */
    private function permissionContract(int $siteId): array
    {
        return [
            'read'=>$this->auth->hasPermission('sale.settings.manage',$siteId)||$this->auth->hasPermission('content.read',$siteId),
            'edit'=>$this->auth->hasPermission('content.revisions.save',$siteId),
            'publish'=>$this->auth->hasPermission('content.publish',$siteId),
            'activate'=>$this->auth->hasPermission('sale.settings.manage',$siteId),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function themes(): array
    {
        return array_map(static fn(array $row):array=>['key'=>(string)$row['theme_key'],'name'=>(string)$row['name']],$this->core->all('SELECT theme_key,name FROM themes WHERE is_active=1 ORDER BY is_default DESC,name'));
    }

    /** @param list<array<string,mixed>> $channels @return array<string,mixed>|null */
    private function channelById(array $channels, int $channelId): ?array
    {
        foreach ($channels as $channel) if ((int)$channel['channel_id']===$channelId) return $channel;
        return null;
    }

    private function localizedShopPath(string $prefix, string $route): string
    {
        $path = rtrim($prefix,'/') . $route;
        return url_path($path !== '' ? $path : '/shop');
    }

    private function actorId(): int { return (int)($this->auth->user()['id']??0); }

    private function requireCsrf(): void
    {
        if (!Csrf::verifyHeader($this->request->header(AdminApiContract::HEADER_CSRF))) {
            throw new ApiException(ErrorCode::CSRF_TOKEN_REJECTED,ErrorCode::message(ErrorCode::CSRF_TOKEN_REJECTED),403);
        }
    }

    private function message(string $code): string
    {
        return match($code) {
            'shop.title_required'=>'Le titre de la boutique est obligatoire.',
            'shop.currency_invalid'=>'La devise doit utiliser un code ISO à trois lettres.',
            'shop.channel_invalid'=>'Le canal sélectionné n’appartient pas à ce site.',
            'shop.channel_not_public'=>'Le canal doit être public et actif avant l’activation.',
            'shop.theme_invalid'=>'Le thème sélectionné est indisponible.',
            'shop.publish_required'=>'Publiez d’abord la configuration Studio.',
            'shop.configuration_missing'=>'Initialisez d’abord la page système Boutique.',
            'shop.language_not_enabled'=>'Cette langue n’est pas active pour le site.',
            'shop.activation_failed'=>'L’activation a échoué. La boutique reste masquée et peut être réparée.',
            default=>'La configuration E-Commerce est invalide.',
        };
    }
}
