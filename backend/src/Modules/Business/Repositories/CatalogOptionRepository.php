<?php

declare(strict_types=1);

namespace App\Modules\Business\Repositories;

final class CatalogOptionRepository extends BusinessRepositoryBase
{
    /** @return array{items:list<array<string,mixed>>,limit:int,offset:int,total:int} */
    public function list(int $siteId, int $limit = 50, int $offset = 0, bool $includeArchived = false): array
    {
        $where = ['site_id = :site_id'];
        $params = ['site_id' => $this->requireSiteId($siteId)];
        if (!$includeArchived) {
            $where[] = 'archived_at IS NULL';
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int) (($this->database()->one('SELECT COUNT(*) AS c FROM business_product_options WHERE ' . $sqlWhere, $params)['c'] ?? 0));
        $limit = $this->limit($limit);
        $offset = $this->offset($offset);
        $rows = $this->database()->all(
            'SELECT * FROM business_product_options WHERE ' . $sqlWhere . ' ORDER BY sort_order ASC, name ASC LIMIT :limit OFFSET :offset',
            $params + ['limit' => $limit, 'offset' => $offset]
        );
        return ['items' => array_map(fn(array $row): array => $this->withValues($row), $rows), 'limit' => $limit, 'offset' => $offset, 'total' => $total];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function create(int $siteId, array $payload, ?int $actorId = null): array
    {
        $name = $this->text($payload['name'] ?? null, 'name', 180);
        $code = $this->code($payload['code'] ?? $payload['option_key'] ?? $name, 'option_code', 80);
        $this->database()->run(
            'INSERT INTO business_product_options(site_id, code, name, type, sort_order, created_by_iam_user_id, updated_by_iam_user_id)
             VALUES(:site_id, :code, :name, :type, :sort_order, :actor, :actor)',
            [
                'site_id' => $this->requireSiteId($siteId),
                'code' => $code,
                'name' => $name,
                'type' => $this->choice($payload['type'] ?? 'select', ['select', 'text', 'color', 'number', 'duration'], 'option_type'),
                'sort_order' => max(0, (int) ($payload['sort_order'] ?? 0)),
                'actor' => $actorId,
            ]
        );
        return $this->find($siteId, $this->database()->lastInsertId()) ?? [];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed>|null */
    public function update(int $siteId, int $id, array $payload, ?int $actorId = null): ?array
    {
        $current = $this->find($siteId, $id, true);
        if ($current === null) {
            return null;
        }
        $this->database()->run(
            'UPDATE business_product_options
             SET code = :code, name = :name, type = :type, sort_order = :sort_order, updated_by_iam_user_id = :actor, updated_at = CURRENT_TIMESTAMP
             WHERE site_id = :site_id AND id = :id',
            [
                'site_id' => $this->requireSiteId($siteId),
                'id' => $id,
                'code' => $this->code($payload['code'] ?? $payload['option_key'] ?? $current['code'], 'option_code', 80),
                'name' => $this->text($payload['name'] ?? $current['name'], 'name', 180),
                'type' => $this->choice($payload['type'] ?? $current['type'], ['select', 'text', 'color', 'number', 'duration'], 'option_type'),
                'sort_order' => max(0, (int) ($payload['sort_order'] ?? $current['sort_order'] ?? 0)),
                'actor' => $actorId,
            ]
        );
        return $this->find($siteId, $id, true);
    }

    public function find(int $siteId, int $id, bool $includeArchived = false): ?array
    {
        $row = $this->database()->one(
            'SELECT * FROM business_product_options WHERE site_id = ? AND id = ?' . ($includeArchived ? '' : ' AND archived_at IS NULL') . ' LIMIT 1',
            [$this->requireSiteId($siteId), $id]
        );
        return $row ? $this->withValues($row) : null;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function addValue(int $siteId, int $optionId, array $payload, ?int $actorId = null): array
    {
        if ($this->find($siteId, $optionId) === null) {
            throw new \InvalidArgumentException('business.catalog.option_not_found');
        }
        $label = $this->text($payload['label'] ?? null, 'label', 180);
        $code = $this->code($payload['code'] ?? $payload['value_key'] ?? $label, 'option_value_code', 80);
        $this->database()->run(
            'INSERT INTO business_product_option_values(option_id, code, label, value, color_hex, sort_order, created_by_iam_user_id, updated_by_iam_user_id)
             VALUES(:option_id, :code, :label, :value, :color_hex, :sort_order, :actor, :actor)',
            [
                'option_id' => $optionId,
                'code' => $code,
                'label' => $label,
                'value' => $this->text($payload['value'] ?? $code, 'value', 180),
                'color_hex' => $this->nullableText($payload['color_hex'] ?? null, 'color_hex', 7),
                'sort_order' => max(0, (int) ($payload['sort_order'] ?? 0)),
                'actor' => $actorId,
            ]
        );
        return $this->value($this->database()->lastInsertId()) ?? [];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed>|null */
    public function updateValue(int $siteId, int $id, array $payload, ?int $actorId = null): ?array
    {
        $current = $this->valueForSite($siteId, $id);
        if ($current === null) {
            return null;
        }
        $this->database()->run(
            'UPDATE business_product_option_values
             SET code = :code, label = :label, value = :value, color_hex = :color_hex, sort_order = :sort_order,
                 updated_by_iam_user_id = :actor, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            [
                'id' => $id,
                'code' => $this->code($payload['code'] ?? $payload['value_key'] ?? $current['code'], 'option_value_code', 80),
                'label' => $this->text($payload['label'] ?? $current['label'], 'label', 180),
                'value' => $this->text($payload['value'] ?? $current['value'], 'value', 180),
                'color_hex' => $this->nullableText($payload['color_hex'] ?? $current['color_hex'] ?? null, 'color_hex', 7),
                'sort_order' => max(0, (int) ($payload['sort_order'] ?? $current['sort_order'] ?? 0)),
                'actor' => $actorId,
            ]
        );
        return $this->value($id);
    }

    public function archive(int $siteId, int $id, ?int $actorId = null): bool
    {
        $this->database()->run(
            'UPDATE business_product_options SET archived_at = COALESCE(archived_at, CURRENT_TIMESTAMP), updated_by_iam_user_id = ?, updated_at = CURRENT_TIMESTAMP WHERE site_id = ? AND id = ?',
            [$actorId, $this->requireSiteId($siteId), $id]
        );
        return true;
    }

    public function archiveValue(int $siteId, int $id, ?int $actorId = null): bool
    {
        $current = $this->valueForSite($siteId, $id);
        if ($current === null) {
            return false;
        }
        $this->database()->run(
            'UPDATE business_product_option_values SET archived_at = COALESCE(archived_at, CURRENT_TIMESTAMP), updated_by_iam_user_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
            [$actorId, $id]
        );
        return true;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function withValues(array $row): array
    {
        $option = $this->castRow($row);
        $option['values'] = array_map(fn(array $value): array => $this->castRow($value), $this->database()->all(
            'SELECT * FROM business_product_option_values WHERE option_id = ? AND archived_at IS NULL ORDER BY sort_order ASC, label ASC',
            [(int) $row['id']]
        ));
        return $option;
    }

    private function value(int $id): ?array
    {
        $row = $this->database()->one('SELECT * FROM business_product_option_values WHERE id = ? LIMIT 1', [$id]);
        return $row ? $this->castRow($row) : null;
    }

    private function valueForSite(int $siteId, int $id): ?array
    {
        $row = $this->database()->one(
            'SELECT ov.* FROM business_product_option_values ov INNER JOIN business_product_options o ON o.id = ov.option_id WHERE o.site_id = ? AND ov.id = ? LIMIT 1',
            [$this->requireSiteId($siteId), $id]
        );
        return $row ? $this->castRow($row) : null;
    }

    private function code(mixed $value, string $field, int $max): string
    {
        $code = strtolower(trim((string) $value));
        $code = preg_replace('/[^a-z0-9_-]+/', '_', $code) ?? '';
        $code = trim($code, '_-');
        if ($code === '') {
            throw new \InvalidArgumentException('business.catalog.' . $field . '_invalid');
        }
        return substr($code, 0, $max);
    }

    /** @param list<string> $allowed */
    private function choice(mixed $value, array $allowed, string $field): string
    {
        $choice = trim((string) $value);
        if (!in_array($choice, $allowed, true)) {
            throw new \InvalidArgumentException('business.catalog.' . $field . '_invalid');
        }
        return $choice;
    }
}
