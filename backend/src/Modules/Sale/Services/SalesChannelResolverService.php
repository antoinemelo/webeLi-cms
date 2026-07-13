<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Core\Database;
use App\Modules\Sale\Exceptions\SaleValidationException;
use App\Modules\Sale\Repositories\SaleChannelRepository;

/** Resolves a canonical SalesChannel without reading another module's private configuration. */
final class SalesChannelResolverService
{
    public function __construct(
        private readonly SaleChannelRepository $channels,
        private readonly SaleDatabaseConnection $sale,
        private readonly Database $core,
    ) {}

    /** @return array<string,mixed> */
    public function storefront(int $siteId, ?string $code = null): array
    {
        if ($code !== null && trim($code) !== '') {
            return $this->assertUsable($this->channels->requireByCode($siteId, trim($code)), 'storefront');
        }
        $config = $this->core->one(
            "SELECT channel_id FROM cms_sales_channel_storefronts WHERE site_id=? AND is_default=1 AND status='active' ORDER BY channel_id LIMIT 1",
            [$siteId]
        );
        if ($config === null) {
            throw new SaleValidationException('sale.channel_storefront_default_missing');
        }
        return $this->assertUsable($this->channels->requireChannel($siteId, (int) $config['channel_id']), 'storefront');
    }

    /** @return array<string,mixed> */
    public function headless(int $siteId, ?int $channelId = null, ?string $code = null): array
    {
        if ($channelId !== null) {
            return $this->assertUsable($this->channels->requireChannel($siteId, $channelId));
        }
        return $this->storefront($siteId, $code);
    }

    /** @return array<string,mixed> */
    public function admin(int $siteId, ?int $channelId = null): array
    {
        if ($channelId !== null) {
            return $this->assertUsable($this->channels->requireChannel($siteId, $channelId), 'admin');
        }
        return $this->defaultOfKind($siteId, 'admin');
    }

    /** @return array{channel:array<string,mixed>,register:array<string,mixed>,stock_location:array<string,mixed>} */
    public function pos(int $siteId, ?int $registerId = null): array
    {
        $db = $this->sale->database();
        if ($db === null) {
            throw new SaleValidationException('sale.database_unavailable');
        }
        $where = $registerId === null ? '' : ' AND r.id=:register_id';
        $params = ['site_id' => $siteId] + ($registerId === null ? [] : ['register_id' => $registerId]);
        $row = $db->one(
            "SELECT r.*,l.code AS stock_location_code,l.name AS stock_location_name,l.status AS stock_location_status
             FROM sale_pos_registers r LEFT JOIN sale_stock_locations l ON l.id=r.stock_location_id
             WHERE r.site_id=:site_id AND r.status='active'{$where} ORDER BY r.id LIMIT 1",
            $params
        );
        if ($row === null) {
            throw new SaleValidationException('sale.pos_register_not_found');
        }
        if ($row['stock_location_id'] === null || $row['stock_location_status'] !== 'active') {
            throw new SaleValidationException('sale.pos_stock_location_invalid');
        }
        $channel = $this->assertUsable($this->channels->requireChannel($siteId, (int) $row['channel_id']), 'pos');
        return [
            'channel' => $channel,
            'register' => ['id' => (int) $row['id'], 'code' => $row['code'], 'name' => $row['name']],
            'stock_location' => ['id' => (int) $row['stock_location_id'], 'code' => $row['stock_location_code'], 'name' => $row['stock_location_name']],
        ];
    }

    /** @return array<string,mixed> */
    private function defaultOfKind(int $siteId, string $kind): array
    {
        $result = $this->channels->list($siteId, ['status' => 'active', 'type' => $kind], 100, 0);
        foreach ($result['items'] as $channel) {
            if ((int) ($channel['is_default'] ?? 0) === 1) {
                return $this->assertUsable($channel, $kind);
            }
        }
        throw new SaleValidationException('sale.channel_default_missing');
    }

    /** @param array<string,mixed> $channel @return array<string,mixed> */
    private function assertUsable(array $channel, ?string $kind = null): array
    {
        if (($channel['status'] ?? null) !== 'active') {
            throw new SaleValidationException('sale.channel_inactive');
        }
        if ($kind !== null && ($channel['type'] ?? null) !== $kind) {
            throw new SaleValidationException('sale.channel_type_mismatch');
        }
        return $channel;
    }
}
