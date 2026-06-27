<?php

declare(strict_types=1);

namespace App\Modules\Business\Repositories;

final class BusinessCompanyRepository extends BusinessRepositoryBase
{
    /** @return array{items:list<array<string,mixed>>,limit:int,offset:int} */
    public function list(int $siteId, string $q = '', string $status = '', int $limit = 50, int $offset = 0, bool $includeArchived = false, string $tag = ''): array
    {
        $siteId = $this->requireSiteId($siteId);
        $limit = $this->limit($limit);
        $offset = $this->offset($offset);
        $where = ['site_id = :site_id'];
        $params = ['site_id' => $siteId];
        if (!$includeArchived) {
            $where[] = 'archived_at IS NULL';
        }
        if (trim($status) !== '') {
            $where[] = 'status = :status';
            $params['status'] = $this->crmStatus($status);
        }
        if (trim($q) !== '') {
            $where[] = '(normalized_name LIKE :q OR email LIKE :q OR phone LIKE :q)';
            $params['q'] = '%' . strtolower(trim($q)) . '%';
        }
        if (trim($tag) !== '') {
            $where[] = "EXISTS (
                SELECT 1 FROM business_tag_links tl
                JOIN business_tags t ON t.id = tl.tag_id
                WHERE tl.target_type = 'company'
                  AND tl.company_id = business_companies.id
                  AND t.site_id = business_companies.site_id
                  AND t.archived_at IS NULL
                  AND (t.tag_key = :tag OR t.label = :tag)
            )";
            $params['tag'] = trim($tag);
        }
        $rows = $this->database()->all(
            'SELECT * FROM business_companies WHERE ' . implode(' AND ', $where) . ' ORDER BY is_system DESC, normalized_name, id LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );
        return ['items' => array_map(fn(array $row): array => $this->castRow($row), $rows), 'limit' => $limit, 'offset' => $offset];
    }

    public function find(int $siteId, int $id, bool $includeArchived = false): ?array
    {
        $where = 'site_id = :site_id AND id = :id' . ($includeArchived ? '' : ' AND archived_at IS NULL');
        $row = $this->database()->one('SELECT * FROM business_companies WHERE ' . $where . ' LIMIT 1', [
            'site_id' => $this->requireSiteId($siteId),
            'id' => $id,
        ]);
        return $row ? $this->castRow($row) : null;
    }

    public function create(int $siteId, array $payload, ?int $actorId = null): array
    {
        $siteId = $this->requireSiteId($siteId);
        $name = $this->text($payload['name'] ?? null, 'name', 180);
        $kind = (string) ($payload['company_kind'] ?? 'organization');
        if (!in_array($kind, ['organization', 'system_individuals'], true)) {
            throw new \InvalidArgumentException('business.company_kind_invalid');
        }
        $this->database()->run(
            'INSERT INTO business_companies(site_id, name, normalized_name, company_kind, status, email, phone, website_url, address_json, notes, is_system, created_by_iam_user_id, updated_by_iam_user_id)
             VALUES(:site_id, :name, :normalized_name, :company_kind, :status, :email, :phone, :website_url, :address_json, :notes, :is_system, :actor, :actor)',
            [
                'site_id' => $siteId,
                'name' => $name,
                'normalized_name' => $this->normalizedName($name),
                'company_kind' => $kind,
                'status' => $this->crmStatus($payload['status'] ?? ($kind === 'system_individuals' ? 'other' : 'prospect')),
                'email' => $this->email($payload['email'] ?? null),
                'phone' => $this->phone($payload['phone'] ?? null),
                'website_url' => $this->nullableText($payload['website_url'] ?? null, 'website_url', 255),
                'address_json' => $this->json($payload['address'] ?? $payload['address_json'] ?? []),
                'notes' => $this->nullableText($payload['notes'] ?? '', 'notes', 5000) ?? '',
                'is_system' => $this->boolInt($payload['is_system'] ?? ($kind === 'system_individuals')),
                'actor' => $actorId,
            ]
        );
        return $this->find($siteId, $this->database()->lastInsertId()) ?? [];
    }

    public function update(int $siteId, int $id, array $payload, ?int $actorId = null): ?array
    {
        if (!$this->find($siteId, $id)) {
            return null;
        }
        $current = $this->find($siteId, $id, true) ?? [];
        $name = $this->text($payload['name'] ?? $current['name'] ?? null, 'name', 180);
        $this->database()->run(
            'UPDATE business_companies SET name = :name, normalized_name = :normalized_name, status = :status, email = :email, phone = :phone,
                website_url = :website_url, address_json = :address_json, notes = :notes, updated_by_iam_user_id = :actor, updated_at = CURRENT_TIMESTAMP
             WHERE site_id = :site_id AND id = :id',
            [
                'site_id' => $this->requireSiteId($siteId),
                'id' => $id,
                'name' => $name,
                'normalized_name' => $this->normalizedName($name),
                'status' => $this->crmStatus($payload['status'] ?? $current['status'] ?? 'prospect'),
                'email' => $this->email($payload['email'] ?? $current['email'] ?? null),
                'phone' => $this->phone($payload['phone'] ?? $current['phone'] ?? null),
                'website_url' => $this->nullableText($payload['website_url'] ?? $current['website_url'] ?? null, 'website_url', 255),
                'address_json' => $this->json($payload['address'] ?? $payload['address_json'] ?? $current['address_json'] ?? []),
                'notes' => $this->nullableText($payload['notes'] ?? $current['notes'] ?? '', 'notes', 5000) ?? '',
                'actor' => $actorId,
            ]
        );
        return $this->find($siteId, $id);
    }

    public function archive(int $siteId, int $id, ?int $actorId = null): bool
    {
        $this->database()->run(
            'UPDATE business_companies SET archived_at = COALESCE(archived_at, CURRENT_TIMESTAMP), updated_by_iam_user_id = :actor, updated_at = CURRENT_TIMESTAMP WHERE site_id = :site_id AND id = :id AND is_system = 0',
            ['site_id' => $this->requireSiteId($siteId), 'id' => $id, 'actor' => $actorId]
        );
        return true;
    }

    public function ensureSystemIndividualsCompany(int $siteId, ?int $actorId = null): array
    {
        $siteId = $this->requireSiteId($siteId);
        $row = $this->database()->one(
            "SELECT * FROM business_companies WHERE site_id = :site_id AND company_kind = 'system_individuals' AND archived_at IS NULL LIMIT 1",
            ['site_id' => $siteId]
        );
        if ($row) {
            return $this->castRow($row);
        }
        return $this->create($siteId, [
            'name' => 'Individus',
            'company_kind' => 'system_individuals',
            'status' => 'other',
            'is_system' => true,
        ], $actorId);
    }

    private function json(mixed $value): string
    {
        if (is_string($value) && trim($value) !== '') {
            json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $value;
            }
        }
        return json_encode(is_array($value) ? $value : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
