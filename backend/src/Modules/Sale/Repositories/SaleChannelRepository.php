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
            $where[] = 'channel_type = :channel_type';
            $params['channel_type'] = trim((string) ($filters['type'] ?? $filters['channel_type']));
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int) ($this->rawDatabase()->one('SELECT COUNT(*) AS count FROM sale_channels WHERE ' . $sqlWhere, $params)['count'] ?? 0);
        $items = $this->rawDatabase()->all(
            'SELECT * FROM sale_channels WHERE ' . $sqlWhere . ' ORDER BY code ASC LIMIT :limit OFFSET :offset',
            $params + ['limit' => max(1, min(100, $limit)), 'offset' => max(0, $offset)]
        );
        return ['items' => $items, 'limit' => $limit, 'offset' => $offset, 'total' => $total, 'has_more' => ($offset + $limit) < $total];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function create(int $siteId, array $payload, ?int $actorId = null): array
    {
        $this->rawDatabase()->run(
            'INSERT INTO sale_channels(
                site_id, code, name, channel_type, status, currency, default_language,
                tax_mode, price_tax_included, is_public, created_by_iam_user_id
             ) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $siteId,
                $this->code((string) ($payload['code'] ?? '')),
                trim((string) ($payload['name'] ?? '')),
                $this->channelType((string) ($payload['channel_type'] ?? $payload['type'] ?? 'admin')),
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
             SET name = ?, channel_type = ?, status = ?, currency = ?, default_language = ?,
                 tax_mode = ?, price_tax_included = ?, is_public = ?, updated_by_iam_user_id = ?,
                 updated_at = CURRENT_TIMESTAMP
             WHERE site_id = ? AND id = ?',
            [
                trim((string) ($payload['name'] ?? $current['name'])),
                $this->channelType((string) ($payload['channel_type'] ?? $payload['type'] ?? $current['channel_type'])),
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
        return $row;
    }

    public function channelCodeForCatalog(array $channel): string
    {
        $type = (string) ($channel['channel_type'] ?? 'admin');
        return in_array($type, ['admin', 'pos', 'ecommerce'], true) ? $type : 'admin';
    }

    private function code(string $code): string
    {
        $code = strtolower(trim($code));
        if ($code === '' || !preg_match('/^[a-z0-9_-]+$/', $code)) {
            throw new SaleValidationException('sale.channel_code_invalid');
        }
        return $code;
    }

    private function channelType(string $type): string
    {
        if (!in_array($type, ['ecommerce', 'pos', 'admin'], true)) {
            throw new SaleValidationException('sale.channel_type_invalid');
        }
        return $type;
    }

    private function status(string $status): string
    {
        if (!in_array($status, ['draft', 'active', 'archived'], true)) {
            throw new SaleValidationException('sale.channel_status_invalid');
        }
        return $status;
    }
}
