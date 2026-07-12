<?php

declare(strict_types=1);

namespace App\Modules\Sale\Repositories;

use App\Modules\Sale\Exceptions\SaleValidationException;

final class SaleChannelRepository extends SaleRepositoryBase
{
    /** @return array{items:list<array<string,mixed>>,limit:int,offset:int,total:int,has_more:bool} */
    public function list(int $siteId, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = ['site_id = :site_id'];
        $params = ['site_id' => $siteId];
        if (trim((string) ($filters['q'] ?? '')) !== '') {
            $where[] = '(code LIKE :q OR name LIKE :q)';
            $params['q'] = '%' . trim((string) $filters['q']) . '%';
        }
        if (trim((string) ($filters['status'] ?? '')) !== '') {
            $where[] = 'status = :status';
            $params['status'] = trim((string) $filters['status']);
        }
        if (trim((string) ($filters['type'] ?? $filters['channel_type'] ?? '')) !== '') {
            $where[] = 'channel_kind = :channel_kind';
            $params['channel_kind'] = $this->channelKind((string) ($filters['type'] ?? $filters['channel_type']));
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int) ($this->rawDatabase()->one('SELECT COUNT(*) AS count FROM sale_channels WHERE ' . $sqlWhere, $params)['count'] ?? 0);
        $items = array_map($this->contractRow(...), $this->rawDatabase()->all(
            'SELECT * FROM sale_channels WHERE ' . $sqlWhere . ' ORDER BY code ASC LIMIT :limit OFFSET :offset',
            $params + ['limit' => max(1, min(100, $limit)), 'offset' => max(0, $offset)]
        ));
        return ['items' => $items, 'limit' => $limit, 'offset' => $offset, 'total' => $total, 'has_more' => ($offset + $limit) < $total];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function create(int $siteId, array $payload, ?int $actorId = null): array
    {
        $this->rawDatabase()->run(
            'INSERT INTO sale_channels(
                site_id, code, name, channel_type, channel_kind, is_default, status, currency, default_language,
                tax_mode, price_tax_included, is_public, created_by_iam_user_id
             ) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $siteId,
                $this->code((string) ($payload['code'] ?? '')),
                trim((string) ($payload['name'] ?? '')),
                $this->legacyType((string) ($payload['channel_type'] ?? $payload['type'] ?? 'admin')),
                $this->channelKind((string) ($payload['type'] ?? $payload['channel_type'] ?? 'admin')),
                (int) (bool) ($payload['is_default'] ?? false),
                $this->status((string) ($payload['status'] ?? 'draft')),
                strtoupper((string) ($payload['currency'] ?? 'CHF')),
                strtolower((string) ($payload['default_language'] ?? 'fr')),
                $payload['tax_mode'] ?? 'tax_included',
                (int) ($payload['price_tax_included'] ?? 1),
                (int) (bool) ($payload['is_public'] ?? false),
                $actorId,
            ]
        );
        return $this->requireChannel($siteId, (int) $this->rawDatabase()->lastInsertId());
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function update(int $siteId, int $channelId, array $payload, ?int $actorId = null): array
    {
        $current = $this->requireChannel($siteId, $channelId);
        $this->rawDatabase()->run(
            'UPDATE sale_channels
             SET name = ?, channel_type = ?, channel_kind = ?, is_default = ?, status = ?, currency = ?, default_language = ?,
                 tax_mode = ?, price_tax_included = ?, is_public = ?, updated_by_iam_user_id = ?,
                 updated_at = CURRENT_TIMESTAMP
             WHERE site_id = ? AND id = ?',
            [
                trim((string) ($payload['name'] ?? $current['name'])),
                $this->legacyType((string) ($payload['channel_type'] ?? $payload['type'] ?? $current['type'])),
                $this->channelKind((string) ($payload['type'] ?? $payload['channel_type'] ?? $current['type'])),
                (int) (bool) ($payload['is_default'] ?? (bool) $current['is_default']),
                $this->status((string) ($payload['status'] ?? $current['status'])),
                strtoupper((string) ($payload['currency'] ?? $current['currency'])),
                strtolower((string) ($payload['default_language'] ?? $current['default_language'])),
                $payload['tax_mode'] ?? $current['tax_mode'],
                (int) ($payload['price_tax_included'] ?? $current['price_tax_included']),
                (int) (bool) ($payload['is_public'] ?? (bool) $current['is_public']),
                $actorId,
                $siteId,
                $channelId,
            ]
        );
        return $this->requireChannel($siteId, $channelId);
    }

    public function archive(int $siteId, int $channelId, ?int $actorId = null): array
    {
        $this->requireChannel($siteId, $channelId);
        $this->rawDatabase()->run(
            'UPDATE sale_channels
             SET status = \'archived\', archived_at = CURRENT_TIMESTAMP, updated_by_iam_user_id = ?, updated_at = CURRENT_TIMESTAMP
             WHERE site_id = ? AND id = ?',
            [$actorId, $siteId, $channelId]
        );
        return $this->requireChannel($siteId, $channelId);
    }

    /** @return array<string,mixed> */
    public function requireChannel(int $siteId, int $channelId): array
    {
        $row = $this->rawDatabase()->one(
            'SELECT * FROM sale_channels WHERE site_id = ? AND id = ? LIMIT 1',
            [$siteId, $channelId]
        );
        if ($row === null) {
            throw new SaleValidationException('sale.channel_not_found');
        }
        return $this->contractRow($row);
    }

    /** @return array<string,mixed> */
    public function requireByCode(int $siteId, string $code): array
    {
        $row = $this->rawDatabase()->one('SELECT * FROM sale_channels WHERE site_id = ? AND code = ? LIMIT 1', [$siteId, $code]);
        if ($row === null) {
            throw new SaleValidationException('sale.channel_not_found');
        }
        return $this->contractRow($row);
    }

    public function channelCodeForCatalog(array $channel): string
    {
        return match ((string) ($channel['type'] ?? $channel['channel_kind'] ?? 'admin')) {
            'storefront' => 'ecommerce',
            'pos' => 'pos',
            'partner' => 'catalogue',
            default => 'admin',
        };
    }

    private function code(string $code): string
    {
        $code = strtolower(trim($code));
        if ($code === '' || !preg_match('/^[a-z0-9_-]+$/', $code)) {
            throw new SaleValidationException('sale.channel_code_invalid');
        }
        return $code;
    }

    private function channelKind(string $type): string
    {
        $type = $type === 'ecommerce' ? 'storefront' : $type;
        if (!in_array($type, ['storefront', 'pos', 'admin', 'partner'], true)) {
            throw new SaleValidationException('sale.channel_type_invalid');
        }
        return $type;
    }

    private function legacyType(string $type): string
    {
        return match ($this->channelKind($type)) {
            'storefront' => 'ecommerce',
            'pos' => 'pos',
            default => 'admin',
        };
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function contractRow(array $row): array
    {
        $kind = (string) ($row['channel_kind'] ?? match ((string) ($row['channel_type'] ?? 'admin')) {
            'ecommerce' => 'storefront', 'pos' => 'pos', default => 'admin',
        });
        $row['channel_id'] = (int) $row['id'];
        $row['type'] = $kind;
        $row['default_locale'] = (string) $row['default_language'];
        $row['default_currency'] = (string) $row['currency'];
        return $row;
    }

    private function status(string $status): string
    {
        if (!in_array($status, ['draft', 'active', 'archived'], true)) {
            throw new SaleValidationException('sale.channel_status_invalid');
        }
        return $status;
    }
}
