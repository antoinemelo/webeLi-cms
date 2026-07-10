<?php

declare(strict_types=1);

namespace App\Modules\Business\Services;

use App\Core\Database;
use InvalidArgumentException;

final class BusinessProductAssetService
{
    private const ROLES = ['main', 'gallery', 'variant', 'thumbnail', 'document', 'technical_sheet', 'brand_logo', 'packaging', 'seo', 'internal'];
    private const CHANNELS = ['all', 'public', 'ecommerce', 'pos', 'catalogue', 'admin', 'pdf'];

    public function __construct(private readonly Database $db) {}

    /** @return array<string,mixed>|null */
    public function mainAssetForProduct(int $productId, ?int $variantId, string $channel): ?array
    {
        $productId = $this->requirePositive($productId, 'product_id');
        $channel = $this->channel($channel);
        $scopes = $this->channelScopes($channel);

        if ($variantId !== null) {
            $row = $this->findMainAsset($productId, $this->requirePositive($variantId, 'variant_id'), $channel, $scopes);
            if ($row !== null) {
                return $this->castAsset($row);
            }
        }

        $row = $this->findMainAsset($productId, null, $channel, $scopes);
        return $row === null ? null : $this->castAsset($row);
    }

    /** @param array<string,mixed> $filters @return list<array<string,mixed>> */
    public function listAssetsForProduct(int $productId, array $filters = []): array
    {
        $productId = $this->requirePositive($productId, 'product_id');
        $where = ['product_id = :product_id', 'archived_at IS NULL'];
        $params = ['product_id' => $productId];

        if (array_key_exists('variant_id', $filters)) {
            $variantId = $filters['variant_id'] === null ? null : $this->requirePositive((int) $filters['variant_id'], 'variant_id');
            if ((bool) ($filters['include_product_assets'] ?? true) && $variantId !== null) {
                $where[] = '(variant_id IS NULL OR variant_id = :variant_id)';
                $params['variant_id'] = $variantId;
            } elseif ($variantId === null) {
                $where[] = 'variant_id IS NULL';
            } else {
                $where[] = 'variant_id = :variant_id';
                $params['variant_id'] = $variantId;
            }
        }

        if (trim((string) ($filters['role'] ?? '')) !== '') {
            $where[] = 'role = :role';
            $params['role'] = $this->role((string) $filters['role']);
        }

        if (trim((string) ($filters['channel'] ?? '')) !== '') {
            $scopes = $this->channelScopes((string) $filters['channel']);
            $where[] = 'channel_scope IN (' . $this->placeholders($scopes) . ')';
            foreach ($scopes as $index => $scope) {
                $params['scope_' . $index] = $scope;
            }
        }

        if ((bool) ($filters['public_only'] ?? false)) {
            $where[] = 'is_public = 1';
        }

        $rows = $this->db->all(
            'SELECT *
             FROM business_product_assets
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY CASE WHEN variant_id IS NULL THEN 1 ELSE 0 END,
                      CASE role WHEN \'main\' THEN 0 WHEN \'variant\' THEN 1 WHEN \'thumbnail\' THEN 2 ELSE 3 END,
                      sort_order ASC, id ASC',
            $params
        );

        return array_map(fn(array $row): array => $this->castAsset($row), $rows);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function assignAsset(array $payload): array
    {
        $siteId = $this->requirePositive((int) ($payload['site_id'] ?? 0), 'site_id');
        $productId = $this->requirePositive((int) ($payload['product_id'] ?? 0), 'product_id');
        $variantId = isset($payload['variant_id']) && $payload['variant_id'] !== null ? $this->requirePositive((int) $payload['variant_id'], 'variant_id') : null;
        $mediaId = $this->requirePositive((int) ($payload['media_id'] ?? 0), 'media_id');
        $role = $this->role((string) ($payload['role'] ?? 'gallery'));
        $channelScope = $this->channel((string) ($payload['channel_scope'] ?? 'all'));

        $this->db->run(
            'INSERT INTO business_product_assets(
                site_id, product_id, variant_id, media_id, role, title, alt_text, caption,
                sort_order, is_public, channel_scope, created_by_iam_user_id, updated_by_iam_user_id
             ) VALUES(
                :site_id, :product_id, :variant_id, :media_id, :role, :title, :alt_text, :caption,
                :sort_order, :is_public, :channel_scope, :actor, :actor
             )',
            [
                'site_id' => $siteId,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'media_id' => $mediaId,
                'role' => $role,
                'title' => $this->nullableText($payload['title'] ?? null, 255),
                'alt_text' => $this->nullableText($payload['alt_text'] ?? null, 255),
                'caption' => $this->nullableText($payload['caption'] ?? null, 1000),
                'sort_order' => max(0, (int) ($payload['sort_order'] ?? 0)),
                'is_public' => (int) (bool) ($payload['is_public'] ?? false),
                'channel_scope' => $channelScope,
                'actor' => isset($payload['actor_iam_user_id']) ? (int) $payload['actor_iam_user_id'] : null,
            ]
        );

        return $this->asset((int) $this->db->lastInsertId());
    }

    public function archiveAsset(int $assetId): void
    {
        $this->db->run(
            'UPDATE business_product_assets SET archived_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
            [$this->requirePositive($assetId, 'asset_id')]
        );
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function updateAsset(int $assetId, array $payload): array
    {
        $current = $this->asset($this->requirePositive($assetId, 'asset_id'));
        $this->db->run(
            'UPDATE business_product_assets
             SET variant_id = :variant_id,
                 role = :role,
                 title = :title,
                 alt_text = :alt_text,
                 caption = :caption,
                 sort_order = :sort_order,
                 is_public = :is_public,
                 channel_scope = :channel_scope,
                 updated_by_iam_user_id = :actor,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            [
                'id' => $assetId,
                'variant_id' => array_key_exists('variant_id', $payload) ? ($payload['variant_id'] === null ? null : $this->requirePositive((int) $payload['variant_id'], 'variant_id')) : $current['variant_id'],
                'role' => $this->role((string) ($payload['role'] ?? $current['role'])),
                'title' => $this->nullableText($payload['title'] ?? $current['title'] ?? null, 255),
                'alt_text' => $this->nullableText($payload['alt_text'] ?? $current['alt_text'] ?? null, 255),
                'caption' => $this->nullableText($payload['caption'] ?? $current['caption'] ?? null, 1000),
                'sort_order' => max(0, (int) ($payload['sort_order'] ?? $current['sort_order'] ?? 0)),
                'is_public' => (int) (bool) ($payload['is_public'] ?? $current['is_public'] ?? false),
                'channel_scope' => $this->channel((string) ($payload['channel_scope'] ?? $current['channel_scope'] ?? 'all')),
                'actor' => isset($payload['actor_iam_user_id']) ? (int) $payload['actor_iam_user_id'] : ($current['updated_by_iam_user_id'] ?? null),
            ]
        );

        return $this->asset($assetId);
    }

    /** @return array<string,mixed> */
    private function asset(int $assetId): array
    {
        $row = $this->db->one('SELECT * FROM business_product_assets WHERE id = ? LIMIT 1', [$assetId]);
        if ($row === null) {
            throw new InvalidArgumentException('business.product_asset_not_found');
        }
        return $this->castAsset($row);
    }

    /** @param list<string> $scopes @return array<string,mixed>|null */
    private function findMainAsset(int $productId, ?int $variantId, string $channel, array $scopes): ?array
    {
        $params = [
            'product_id' => $productId,
            'exact_channel' => $channel,
        ];
        foreach ($scopes as $index => $scope) {
            $params['scope_' . $index] = $scope;
        }
        $variantSql = 'variant_id IS NULL';
        if ($variantId !== null) {
            $variantSql = 'variant_id = :variant_id';
            $params['variant_id'] = $variantId;
        }

        return $this->db->one(
            'SELECT *
             FROM business_product_assets
             WHERE product_id = :product_id
               AND ' . $variantSql . '
               AND role IN (\'main\', \'variant\', \'thumbnail\')
               AND channel_scope IN (' . $this->placeholders($scopes) . ')
               AND archived_at IS NULL
             ORDER BY CASE role WHEN \'main\' THEN 0 WHEN \'variant\' THEN 1 WHEN \'thumbnail\' THEN 2 ELSE 3 END,
                      CASE channel_scope WHEN :exact_channel THEN 0 WHEN \'all\' THEN 1 WHEN \'public\' THEN 2 ELSE 3 END,
                      sort_order ASC,
                      id ASC
             LIMIT 1',
            $params
        );
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function castAsset(array $row): array
    {
        $mediaId = (int) $row['media_id'];
        return [
            'id' => (int) $row['id'],
            'asset_id' => (int) $row['id'],
            'site_id' => (int) $row['site_id'],
            'product_id' => (int) $row['product_id'],
            'variant_id' => $row['variant_id'] === null ? null : (int) $row['variant_id'],
            'media_id' => $mediaId,
            'role' => (string) $row['role'],
            'title' => $row['title'] ?? null,
            'alt_text' => $row['alt_text'] ?? null,
            'caption' => $row['caption'] ?? null,
            'sort_order' => (int) $row['sort_order'],
            'is_public' => (bool) $row['is_public'],
            'channel_scope' => (string) $row['channel_scope'],
            'url' => '/media/' . $mediaId,
            'created_by_iam_user_id' => $row['created_by_iam_user_id'] === null ? null : (int) $row['created_by_iam_user_id'],
            'updated_by_iam_user_id' => $row['updated_by_iam_user_id'] === null ? null : (int) $row['updated_by_iam_user_id'],
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'archived_at' => $row['archived_at'] ?? null,
        ];
    }

    private function requirePositive(int $value, string $field): int
    {
        if ($value < 1) {
            throw new InvalidArgumentException('business.' . $field . '_invalid');
        }
        return $value;
    }

    private function role(string $role): string
    {
        $role = trim($role);
        if (!in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException('business.product_asset_role_invalid');
        }
        return $role;
    }

    private function channel(string $channel): string
    {
        $channel = trim($channel) === '' ? 'all' : trim($channel);
        $channel = $channel === 'quote' ? 'admin' : $channel;
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException('business.product_asset_channel_invalid');
        }
        return $channel;
    }

    /** @return list<string> */
    private function channelScopes(string $channel): array
    {
        $channel = $this->channel($channel === 'public' ? 'ecommerce' : $channel);
        $scopes = ['all', $channel];
        if ($channel === 'ecommerce') {
            $scopes[] = 'public';
        }
        if ($channel === 'pos') {
            $scopes[] = 'admin';
        }
        return array_values(array_unique($scopes));
    }

    /** @param list<string> $values */
    private function placeholders(array $values): string
    {
        return implode(',', array_map(static fn(int $index): string => ':scope_' . $index, array_keys($values)));
    }

    private function nullableText(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (strlen($text) > $max) {
            throw new InvalidArgumentException('business.product_asset_text_too_long');
        }
        return $text;
    }
}
