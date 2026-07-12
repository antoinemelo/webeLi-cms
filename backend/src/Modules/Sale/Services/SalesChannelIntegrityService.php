<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Core\Database;
use App\Modules\Business\Services\BusinessDatabaseConnection;

/** Cross-database diagnostic coordinator; modules continue to own their local configuration. */
final class SalesChannelIntegrityService
{
    public function __construct(
        private readonly SaleDatabaseConnection $sale,
        private readonly Database $core,
        private readonly BusinessDatabaseConnection $business,
    ) {}

    /** @return array{valid:bool,issues:list<array<string,mixed>>,counts:array<string,int>} */
    public function validate(?int $siteId = null): array
    {
        $sale = $this->sale->database();
        if ($sale === null) {
            return ['valid' => false, 'issues' => [['code' => 'sale_database_unavailable']], 'counts' => ['channels' => 0, 'issues' => 1]];
        }
        $where = $siteId === null ? '' : ' WHERE site_id=?';
        $params = $siteId === null ? [] : [$siteId];
        $rows = $sale->all('SELECT * FROM sale_channels' . $where, $params);
        $channels = [];
        $issues = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $channels[$id] = $row;
            if ($row['status'] === 'active' && (trim((string) $row['currency']) === '' || trim((string) $row['default_language']) === '')) {
                $issues[] = ['code' => 'channel_locale_or_currency_missing', 'channel_id' => $id];
            }
        }
        foreach ([
            ['db' => $this->core, 'table' => 'cms_sales_channel_storefronts', 'kind' => 'storefront'],
            ['db' => $this->business->database(), 'table' => 'business_sales_channel_configs', 'kind' => null],
            ['db' => $sale, 'table' => 'sale_channel_checkout_configs', 'kind' => null],
            ['db' => $sale, 'table' => 'sale_inventory_channel_configs', 'kind' => null],
        ] as $source) {
            if (!$source['db'] instanceof Database || !$source['db']->tableExists($source['table'])) {
                continue;
            }
            $extra = $source['table'] === 'business_sales_channel_configs' ? ',default_currency,status' : ',status';
            $localRows = $source['db']->all('SELECT channel_id,site_id' . $extra . ' FROM ' . $source['table'] . ($siteId === null ? '' : ' WHERE site_id=?'), $params);
            foreach ($localRows as $local) {
                $id = (int) $local['channel_id'];
                if (!isset($channels[$id])) {
                    $issues[] = ['code' => 'unknown_channel_reference', 'source' => $source['table'], 'channel_id' => $id];
                } elseif ((int) $local['site_id'] !== (int) $channels[$id]['site_id']) {
                    $issues[] = ['code' => 'channel_site_mismatch', 'source' => $source['table'], 'channel_id' => $id];
                } elseif ($source['kind'] !== null && $channels[$id]['channel_kind'] !== $source['kind']) {
                    $issues[] = ['code' => 'channel_kind_mismatch', 'source' => $source['table'], 'channel_id' => $id];
                } elseif (($local['status'] ?? null) === 'active' && $channels[$id]['status'] !== 'active') {
                    $issues[] = ['code' => 'channel_inactive_reference', 'source' => $source['table'], 'channel_id' => $id];
                } elseif (isset($local['default_currency']) && $local['default_currency'] !== $channels[$id]['currency']) {
                    $issues[] = ['code' => 'channel_currency_mismatch', 'source' => $source['table'], 'channel_id' => $id];
                }
            }
        }
        foreach ($sale->all("SELECT r.id,r.site_id,r.channel_id,r.stock_location_id,l.status AS location_status FROM sale_pos_registers r LEFT JOIN sale_stock_locations l ON l.id=r.stock_location_id WHERE r.status='active'" . ($siteId === null ? '' : ' AND r.site_id=?'), $params) as $register) {
            $channel = $channels[(int) $register['channel_id']] ?? null;
            if ($channel === null || $channel['channel_kind'] !== 'pos' || (int) $channel['site_id'] !== (int) $register['site_id']) {
                $issues[] = ['code' => 'pos_channel_invalid', 'register_id' => (int) $register['id']];
            }
            if ($register['stock_location_id'] === null || $register['location_status'] !== 'active') {
                $issues[] = ['code' => 'pos_stock_location_invalid', 'register_id' => (int) $register['id']];
            }
        }
        foreach (['sale_carts', 'sale_orders'] as $table) {
            foreach ($sale->all("SELECT id,site_id,channel_id,currency FROM {$table}" . ($siteId === null ? '' : ' WHERE site_id=?'), $params) as $record) {
                $channel = $channels[(int) $record['channel_id']] ?? null;
                if ($channel === null) {
                    $issues[] = ['code' => 'transaction_channel_unknown', 'source' => $table, 'id' => (int) $record['id']];
                } elseif ((int) $channel['site_id'] !== (int) $record['site_id'] || $channel['currency'] !== $record['currency']) {
                    $issues[] = ['code' => 'transaction_channel_mismatch', 'source' => $table, 'id' => (int) $record['id']];
                }
            }
        }
        return ['valid' => $issues === [], 'issues' => $issues, 'counts' => ['channels' => count($channels), 'issues' => count($issues)]];
    }
}
