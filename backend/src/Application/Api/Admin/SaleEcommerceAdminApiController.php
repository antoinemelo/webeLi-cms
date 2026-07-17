<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;

/** Configuration des sites e-commerce rattachée au domaine Ventes. */
final class SaleEcommerceAdminApiController
{
    public function __construct(
        private readonly Request $request,
        private readonly SiteRepository $sites,
        private readonly AuthRepository $auth,
        private readonly Authorization $authorization,
        private readonly Database $core,
        private readonly SaleDatabaseConnection $sale,
    ) {}

    public function shops(): Response
    {
        $this->auth->requireAuth();
        $site = AdminApiContract::siteContext(
            $this->request,
            $this->sites,
            isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null,
            $this->auth
        );
        $this->authorization->require('sale.settings.manage', (int) $site['id']);
        $languageCode = AdminApiContract::language($this->request, $this->sites, $site);

        $siteRows = $this->core->all(
            'SELECT s.id, s.site_key, s.name, s.default_language_code,
                    sl.language_code, sl.is_default, sl.url_prefix,
                    l.name AS language_name, l.native_name
             FROM sites s
             JOIN site_languages sl ON sl.site_id = s.id AND sl.is_active = 1
             JOIN languages l ON l.code = sl.language_code AND l.is_active = 1
             WHERE s.is_active = 1
             ORDER BY s.id, sl.sort_order, sl.language_code'
        );
        $authorizedSiteIds = $this->auth->authorizedSiteIds();
        if ($authorizedSiteIds !== []) {
            $siteRows = array_values(array_filter(
                $siteRows,
                static fn(array $row): bool => in_array((int) $row['id'], $authorizedSiteIds, true)
            ));
        }

        $storefronts = $this->core->tableExists('cms_sales_channel_storefronts')
            ? $this->core->all("SELECT channel_id, site_id, route_prefix, status FROM cms_sales_channel_storefronts WHERE status = 'active'")
            : [];
        $storefrontByChannel = [];
        foreach ($storefronts as $storefront) {
            $storefrontByChannel[(int) $storefront['channel_id']] = $storefront;
        }

        $channelsBySite = [];
        $saleDb = $this->sale->database();
        if ($saleDb !== null && $saleDb->tableExists('sale_channels')) {
            $channels = $saleDb->all(
                "SELECT id, site_id, code, name, default_language, status, is_public
                 FROM sale_channels
                 WHERE channel_type = 'ecommerce' AND status <> 'archived'
                 ORDER BY site_id, is_default DESC, id"
            );
            foreach ($channels as $channel) {
                $channelsBySite[(int) $channel['site_id']][] = $channel;
            }
        }

        $shops = [];
        foreach ($siteRows as $row) {
            $siteId = (int) $row['id'];
            $locale = (string) $row['language_code'];
            $channel = $this->channelForLocale($channelsBySite[$siteId] ?? [], $locale);
            $mapping = $channel ? ($storefrontByChannel[(int) $channel['id']] ?? null) : null;
            $publicDetected = $channel !== null
                && (int) ($channel['is_public'] ?? 0) === 1
                && (string) ($channel['status'] ?? '') === 'active'
                && $mapping !== null;
            $shops[] = [
                'site_id' => $siteId,
                'site_key' => (string) $row['site_key'],
                'site_name' => (string) $row['name'],
                'language_code' => $locale,
                'language_name' => (string) ($row['native_name'] ?: $row['language_name']),
                'is_default_language' => (int) $row['is_default'] === 1,
                'status' => $publicDetected ? 'existing_public_configuration' : 'not_active',
                'public_channel_detected' => $publicDetected,
                'channel_name' => $channel['name'] ?? null,
                'route_prefix' => $mapping['route_prefix'] ?? null,
                'studio_route' => '/studio',
                'preview_path' => $publicDetected ? (string) ($mapping['route_prefix'] ?? '/shop') : null,
                'shop_activation_available' => false,
            ];
        }

        return Response::success([
            'shops' => $shops,
            'shop_activation' => [
                'permission' => 'sale.settings.manage',
                'available' => false,
                'planned_lot' => '39',
            ],
        ], 'admin.sale.ecommerce.shops.index.v1', [
            'site_id' => (int) $site['id'],
            'language' => $languageCode,
        ]);
    }

    /** @param list<array<string,mixed>> $channels */
    private function channelForLocale(array $channels, string $locale): ?array
    {
        foreach ($channels as $channel) {
            if ((string) ($channel['default_language'] ?? '') === $locale) return $channel;
        }
        return $channels[0] ?? null;
    }
}
