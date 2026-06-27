<?php

declare(strict_types=1);

namespace App\Modules\Business\Repositories;

final class CatalogBrandRepository extends BusinessRepositoryBase
{
    /** @return array{items:list<array<string,mixed>>,limit:int,offset:int,total:int} */
    public function list(int $siteId, string $q = '', int $limit = 50, int $offset = 0, bool $includeArchived = false): array
    {
        $where = ['site_id = :site_id'];
        $params = ['site_id' => $this->requireSiteId($siteId)];
        if (!$includeArchived) {
            $where[] = 'archived_at IS NULL';
        }
        if (trim($q) !== '') {
            $where[] = '(name LIKE :q OR slug LIKE :q)';
            $params['q'] = '%' . trim($q) . '%';
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int) (($this->database()->one('SELECT COUNT(*) AS c FROM business_product_brands WHERE ' . $sqlWhere, $params)['c'] ?? 0));
        $limit = $this->limit($limit);
        $offset = $this->offset($offset);
        $rows = $this->database()->all(
            'SELECT * FROM business_product_brands WHERE ' . $sqlWhere . ' ORDER BY sort_order ASC, name ASC LIMIT :limit OFFSET :offset',
            $params + ['limit' => $limit, 'offset' => $offset]
        );
        return ['items' => array_map(fn(array $row): array => $this->castRow($row), $rows), 'limit' => $limit, 'offset' => $offset, 'total' => $total];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function create(int $siteId, array $payload, ?int $actorId = null): array
    {
        $name = $this->text($payload['name'] ?? null, 'name', 180);
        $slug = $this->slug($payload['slug'] ?? $name);
        $this->database()->run(
            'INSERT INTO business_product_brands(site_id, name, slug, description, website_url, logo_media_id, is_public, status, sort_order, created_by_iam_user_id, updated_by_iam_user_id)
             VALUES(:site_id, :name, :slug, :description, :website_url, :logo_media_id, :is_public, :status, :sort_order, :actor, :actor)',
            [
                'site_id' => $this->requireSiteId($siteId),
                'name' => $name,
                'slug' => $slug,
                'description' => $this->nullableText($payload['description'] ?? null, 'description', 2000),
                'website_url' => $this->nullableText($payload['website_url'] ?? null, 'website_url', 255),
                'logo_media_id' => $payload['logo_media_id'] ?? null,
                'is_public' => $this->boolInt($payload['is_public'] ?? true),
                'status' => $this->status($payload['status'] ?? 'active'),
                'sort_order' => max(0, (int) ($payload['sort_order'] ?? 0)),
                'actor' => $actorId,
            ]
        );
        return $this->find($siteId, $this->database()->lastInsertId()) ?? [];
    }

    public function find(int $siteId, int $id, bool $includeArchived = false): ?array
    {
        $row = $this->database()->one(
            'SELECT * FROM business_product_brands WHERE site_id = ? AND id = ?' . ($includeArchived ? '' : ' AND archived_at IS NULL') . ' LIMIT 1',
            [$this->requireSiteId($siteId), $id]
        );
        return $row ? $this->castRow($row) : null;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed>|null */
    public function update(int $siteId, int $id, array $payload, ?int $actorId = null): ?array
    {
        $current = $this->find($siteId, $id, true);
        if ($current === null) {
            return null;
        }
        $name = $this->text($payload['name'] ?? $current['name'], 'name', 180);
        $slug = $this->slug($payload['slug'] ?? $current['slug']);
        $this->database()->run(
            'UPDATE business_product_brands
             SET name = :name, slug = :slug, description = :description, website_url = :website_url, logo_media_id = :logo_media_id,
                 is_public = :is_public, status = :status, sort_order = :sort_order, updated_by_iam_user_id = :actor, updated_at = CURRENT_TIMESTAMP
             WHERE site_id = :site_id AND id = :id',
            [
                'site_id' => $this->requireSiteId($siteId),
                'id' => $id,
                'name' => $name,
                'slug' => $slug,
                'description' => $this->nullableText($payload['description'] ?? $current['description'] ?? null, 'description', 2000),
                'website_url' => $this->nullableText($payload['website_url'] ?? $current['website_url'] ?? null, 'website_url', 255),
                'logo_media_id' => $payload['logo_media_id'] ?? $current['logo_media_id'] ?? null,
                'is_public' => $this->boolInt($payload['is_public'] ?? $current['is_public'] ?? true),
                'status' => $this->status($payload['status'] ?? $current['status']),
                'sort_order' => max(0, (int) ($payload['sort_order'] ?? $current['sort_order'] ?? 0)),
                'actor' => $actorId,
            ]
        );
        return $this->find($siteId, $id, true);
    }

    public function archive(int $siteId, int $id, ?int $actorId = null): bool
    {
        $this->database()->run(
            'UPDATE business_product_brands SET status = "archived", archived_at = COALESCE(archived_at, CURRENT_TIMESTAMP), updated_by_iam_user_id = ?, updated_at = CURRENT_TIMESTAMP WHERE site_id = ? AND id = ?',
            [$actorId, $this->requireSiteId($siteId), $id]
        );
        return true;
    }

    private function slug(mixed $value): string
    {
        $slug = strtolower(trim((string) $value));
        $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-_');
        if ($slug === '') {
            throw new \InvalidArgumentException('business.catalog.slug_invalid');
        }
        return substr($slug, 0, 120);
    }

    private function status(mixed $value): string
    {
        $status = trim((string) $value);
        if (!in_array($status, ['active', 'archived'], true)) {
            throw new \InvalidArgumentException('business.catalog.brand_status_invalid');
        }
        return $status;
    }
}
