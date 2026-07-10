<?php

declare(strict_types=1);

namespace App\Modules\Business\Repositories;

final class BusinessTagRepository extends BusinessRepositoryBase
{
    /** @return list<array<string,mixed>> */
    public function list(int $siteId, bool $includeArchived = false): array
    {
        $rows = $this->database()->all(
            'SELECT * FROM business_tags WHERE site_id = :site_id' . ($includeArchived ? '' : ' AND archived_at IS NULL') . ' ORDER BY label',
            ['site_id' => $this->requireSiteId($siteId)]
        );
        return array_map(fn(array $row): array => $this->castRow($row), $rows);
    }

    public function create(int $siteId, array $payload, ?int $actorId = null): array
    {
        $label = $this->text($payload['label'] ?? null, 'label', 120);
        $key = $this->tagKey($payload['tag_key'] ?? $label);
        $this->database()->run(
            'INSERT INTO business_tags(site_id, tag_key, label, color, created_by_iam_user_id, updated_by_iam_user_id)
             VALUES(:site_id, :tag_key, :label, :color, :actor, :actor)
             ON CONFLICT(site_id, tag_key) DO UPDATE SET label = excluded.label, color = excluded.color, updated_by_iam_user_id = excluded.updated_by_iam_user_id, updated_at = CURRENT_TIMESTAMP, archived_at = NULL',
            [
                'site_id' => $this->requireSiteId($siteId),
                'tag_key' => $key,
                'label' => $label,
                'color' => $this->nullableText($payload['color'] ?? null, 'color', 24),
                'actor' => $actorId,
            ]
        );
        $row = $this->database()->one('SELECT * FROM business_tags WHERE site_id = :site_id AND tag_key = :tag_key', ['site_id' => $siteId, 'tag_key' => $key]);
        return $row ? $this->castRow($row) : [];
    }

    public function update(int $siteId, int $id, array $payload, ?int $actorId = null): ?array
    {
        $row = $this->database()->one('SELECT * FROM business_tags WHERE site_id = :site_id AND id = :id AND archived_at IS NULL', [
            'site_id' => $this->requireSiteId($siteId),
            'id' => $id,
        ]);
        if (!$row) {
            return null;
        }
        $label = $this->text($payload['label'] ?? $row['label'], 'label', 120);
        $this->database()->run(
            'UPDATE business_tags SET label = :label, color = :color, updated_by_iam_user_id = :actor, updated_at = CURRENT_TIMESTAMP WHERE site_id = :site_id AND id = :id',
            [
                'site_id' => $siteId,
                'id' => $id,
                'label' => $label,
                'color' => $this->nullableText($payload['color'] ?? $row['color'] ?? null, 'color', 24),
                'actor' => $actorId,
            ]
        );
        $updated = $this->database()->one('SELECT * FROM business_tags WHERE site_id = :site_id AND id = :id', ['site_id' => $siteId, 'id' => $id]);
        return $updated ? $this->castRow($updated) : null;
    }

    public function archive(int $siteId, int $id, ?int $actorId = null): bool
    {
        $this->database()->run(
            'UPDATE business_tags SET archived_at = COALESCE(archived_at, CURRENT_TIMESTAMP), updated_by_iam_user_id = :actor, updated_at = CURRENT_TIMESTAMP WHERE site_id = :site_id AND id = :id',
            ['site_id' => $this->requireSiteId($siteId), 'id' => $id, 'actor' => $actorId]
        );
        return true;
    }

    public function linkCompany(int $tagId, int $companyId, ?int $actorId = null): void
    {
        $this->database()->run(
            "INSERT OR IGNORE INTO business_tag_links(tag_id, target_type, company_id, created_by_iam_user_id) VALUES(:tag_id, 'company', :company_id, :actor)",
            ['tag_id' => $tagId, 'company_id' => $companyId, 'actor' => $actorId]
        );
    }

    public function linkContact(int $tagId, int $contactId, ?int $actorId = null): void
    {
        $this->database()->run(
            "INSERT OR IGNORE INTO business_tag_links(tag_id, target_type, contact_id, created_by_iam_user_id) VALUES(:tag_id, 'contact', :contact_id, :actor)",
            ['tag_id' => $tagId, 'contact_id' => $contactId, 'actor' => $actorId]
        );
    }

    public function unlinkCompany(int $tagId, int $companyId): void
    {
        $this->database()->run(
            "DELETE FROM business_tag_links WHERE tag_id = :tag_id AND target_type = 'company' AND company_id = :company_id",
            ['tag_id' => $tagId, 'company_id' => $companyId]
        );
    }

    public function unlinkContact(int $tagId, int $contactId): void
    {
        $this->database()->run(
            "DELETE FROM business_tag_links WHERE tag_id = :tag_id AND target_type = 'contact' AND contact_id = :contact_id",
            ['tag_id' => $tagId, 'contact_id' => $contactId]
        );
    }

    /** @return list<string> */
    public function labelsForTarget(int $siteId, string $targetType, int $targetId): array
    {
        if (!in_array($targetType, ['company', 'contact'], true) || $targetId < 1) {
            return [];
        }
        $column = $targetType === 'company' ? 'company_id' : 'contact_id';
        $rows = $this->database()->all(
            "SELECT t.label
             FROM business_tags t
             JOIN business_tag_links tl ON tl.tag_id = t.id
             WHERE t.site_id = :site_id
               AND t.archived_at IS NULL
               AND tl.target_type = :target_type
               AND tl.$column = :target_id
             ORDER BY t.label",
            ['site_id' => $this->requireSiteId($siteId), 'target_type' => $targetType, 'target_id' => $targetId]
        );
        return array_values(array_filter(array_map(static fn(array $row): string => (string) ($row['label'] ?? ''), $rows)));
    }

    private function tagKey(mixed $value): string
    {
        $key = strtolower(trim((string) $value));
        $key = preg_replace('/[^a-z0-9_-]+/', '_', $key) ?? '';
        $key = trim($key, '_-');
        if ($key === '') {
            throw new \InvalidArgumentException('business.tag_key_invalid');
        }
        return substr($key, 0, 80);
    }
}
