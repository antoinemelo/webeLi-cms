<?php

declare(strict_types=1);

namespace App\Modules\Business\Repositories;

final class BusinessContactRepository extends BusinessRepositoryBase
{
    /** @return array{items:list<array<string,mixed>>,limit:int,offset:int} */
    public function list(int $siteId, string $q = '', ?int $companyId = null, int $limit = 50, int $offset = 0, bool $includeArchived = false, string $status = '', string $tag = ''): array
    {
        $siteId = $this->requireSiteId($siteId);
        $limit = $this->limit($limit);
        $offset = $this->offset($offset);
        $where = ['c.site_id = :site_id'];
        $params = ['site_id' => $siteId];
        if (!$includeArchived) {
            $where[] = 'c.archived_at IS NULL';
        }
        if ($companyId !== null) {
            $where[] = 'c.company_id = :company_id';
            $params['company_id'] = $companyId;
        }
        if (trim($status) !== '') {
            $where[] = 'c.status = :status';
            $params['status'] = $this->crmStatus($status);
        }
        if (trim($q) !== '') {
            $where[] = '(c.normalized_name LIKE :q OR c.email LIKE :q OR c.mobile LIKE :q OR c.phone LIKE :q)';
            $params['q'] = '%' . strtolower(trim($q)) . '%';
        }
        if (trim($tag) !== '') {
            $where[] = "EXISTS (
                SELECT 1 FROM business_tag_links tl
                JOIN business_tags t ON t.id = tl.tag_id
                WHERE tl.target_type = 'contact'
                  AND tl.contact_id = c.id
                  AND t.site_id = c.site_id
                  AND t.archived_at IS NULL
                  AND (t.tag_key = :tag OR t.label = :tag)
            )";
            $params['tag'] = trim($tag);
        }
        $rows = $this->database()->all(
            'SELECT c.*, co.name AS company_name FROM business_contacts c JOIN business_companies co ON co.id = c.company_id WHERE ' . implode(' AND ', $where) . ' ORDER BY c.normalized_name, c.id LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );
        return ['items' => array_map(fn(array $row): array => $this->castRow($row), $rows), 'limit' => $limit, 'offset' => $offset];
    }

    public function find(int $siteId, int $id, bool $includeArchived = false): ?array
    {
        $row = $this->database()->one(
            'SELECT c.*, co.name AS company_name FROM business_contacts c JOIN business_companies co ON co.id = c.company_id
             WHERE c.site_id = :site_id AND c.id = :id' . ($includeArchived ? '' : ' AND c.archived_at IS NULL') . ' LIMIT 1',
            ['site_id' => $this->requireSiteId($siteId), 'id' => $id]
        );
        return $row ? $this->castRow($row) : null;
    }

    public function activeByIamUser(int $siteId, int $iamUserId, ?int $exceptContactId = null): ?array
    {
        $params = ['site_id' => $this->requireSiteId($siteId), 'iam_user_id' => $iamUserId];
        $where = 'site_id = :site_id AND iam_user_id = :iam_user_id AND archived_at IS NULL';
        if ($exceptContactId !== null) {
            $where .= ' AND id <> :except_id';
            $params['except_id'] = $exceptContactId;
        }
        $row = $this->database()->one('SELECT * FROM business_contacts WHERE ' . $where . ' LIMIT 1', $params);
        return $row ? $this->castRow($row) : null;
    }

    public function create(int $siteId, array $payload, ?int $actorId = null): array
    {
        $siteId = $this->requireSiteId($siteId);
        $companyId = (int) ($payload['company_id'] ?? 0);
        if ($companyId < 1) {
            throw new \InvalidArgumentException('business.contact_company_required');
        }
        $displayName = $this->displayName($payload);
        $iamUserId = isset($payload['iam_user_id']) && $payload['iam_user_id'] !== '' ? (int) $payload['iam_user_id'] : null;
        if ($iamUserId !== null && $this->activeByIamUser($siteId, $iamUserId) !== null) {
            throw new \InvalidArgumentException('business.contact_iam_user_already_linked');
        }
        $this->database()->run(
            'INSERT INTO business_contacts(site_id, company_id, iam_user_id, first_name, last_name, display_name, normalized_name, status, preferred_language, email, phone, mobile, job_title, notes, created_by_iam_user_id, updated_by_iam_user_id)
             VALUES(:site_id, :company_id, :iam_user_id, :first_name, :last_name, :display_name, :normalized_name, :status, :preferred_language, :email, :phone, :mobile, :job_title, :notes, :actor, :actor)',
            [
                'site_id' => $siteId,
                'company_id' => $companyId,
                'iam_user_id' => $iamUserId,
                'first_name' => $this->nullableText($payload['first_name'] ?? null, 'first_name', 120),
                'last_name' => $this->nullableText($payload['last_name'] ?? null, 'last_name', 120),
                'display_name' => $displayName,
                'normalized_name' => $this->normalizedName($displayName),
                'status' => $this->crmStatus($payload['status'] ?? 'prospect'),
                'preferred_language' => $this->nullableText($payload['preferred_language'] ?? null, 'preferred_language', 12),
                'email' => $this->email($payload['email'] ?? null),
                'phone' => $this->phone($payload['phone'] ?? null),
                'mobile' => $this->phone($payload['mobile'] ?? null, 'mobile'),
                'job_title' => $this->nullableText($payload['job_title'] ?? null, 'job_title', 180),
                'notes' => $this->nullableText($payload['notes'] ?? '', 'notes', 5000) ?? '',
                'actor' => $actorId,
            ]
        );
        return $this->find($siteId, $this->database()->lastInsertId()) ?? [];
    }

    public function update(int $siteId, int $id, array $payload, ?int $actorId = null): ?array
    {
        $current = $this->find($siteId, $id);
        if ($current === null) {
            return null;
        }
        $companyId = isset($payload['company_id']) && $payload['company_id'] !== '' ? (int) $payload['company_id'] : (int) $current['company_id'];
        if ($companyId < 1) {
            throw new \InvalidArgumentException('business.contact_company_required');
        }
        $iamUserId = array_key_exists('iam_user_id', $payload)
            ? ($payload['iam_user_id'] === null || $payload['iam_user_id'] === '' ? null : (int) $payload['iam_user_id'])
            : ($current['iam_user_id'] ?? null);
        if ($iamUserId !== null && $this->activeByIamUser($siteId, $iamUserId, $id) !== null) {
            throw new \InvalidArgumentException('business.contact_iam_user_already_linked');
        }
        $merged = $payload + $current;
        $displayName = $this->displayName($merged);
        $this->database()->run(
            'UPDATE business_contacts SET company_id = :company_id, iam_user_id = :iam_user_id, first_name = :first_name, last_name = :last_name,
                display_name = :display_name, normalized_name = :normalized_name, status = :status, preferred_language = :preferred_language,
                email = :email, phone = :phone, mobile = :mobile, job_title = :job_title, notes = :notes,
                updated_by_iam_user_id = :actor, updated_at = CURRENT_TIMESTAMP
             WHERE site_id = :site_id AND id = :id AND archived_at IS NULL',
            [
                'site_id' => $this->requireSiteId($siteId),
                'id' => $id,
                'company_id' => $companyId,
                'iam_user_id' => $iamUserId,
                'first_name' => $this->nullableText($merged['first_name'] ?? null, 'first_name', 120),
                'last_name' => $this->nullableText($merged['last_name'] ?? null, 'last_name', 120),
                'display_name' => $displayName,
                'normalized_name' => $this->normalizedName($displayName),
                'status' => $this->crmStatus($merged['status'] ?? 'prospect'),
                'preferred_language' => $this->nullableText($merged['preferred_language'] ?? null, 'preferred_language', 12),
                'email' => $this->email($merged['email'] ?? null),
                'phone' => $this->phone($merged['phone'] ?? null),
                'mobile' => $this->phone($merged['mobile'] ?? null, 'mobile'),
                'job_title' => $this->nullableText($merged['job_title'] ?? null, 'job_title', 180),
                'notes' => $this->nullableText($merged['notes'] ?? '', 'notes', 5000) ?? '',
                'actor' => $actorId,
            ]
        );
        return $this->find($siteId, $id);
    }

    public function archive(int $siteId, int $id, ?int $actorId = null): bool
    {
        $this->database()->run(
            'UPDATE business_contacts SET archived_at = COALESCE(archived_at, CURRENT_TIMESTAMP), updated_by_iam_user_id = :actor, updated_at = CURRENT_TIMESTAMP WHERE site_id = :site_id AND id = :id',
            ['site_id' => $this->requireSiteId($siteId), 'id' => $id, 'actor' => $actorId]
        );
        return true;
    }

    public function restore(int $siteId, int $id, ?int $actorId = null): bool
    {
        $siteId = $this->requireSiteId($siteId);
        $current = $this->find($siteId, $id, true);
        if ($current === null) {
            return false;
        }
        $company = $this->database()->one(
            'SELECT id FROM business_companies WHERE site_id = :site_id AND id = :company_id AND archived_at IS NULL LIMIT 1',
            ['site_id' => $siteId, 'company_id' => (int) ($current['company_id'] ?? 0)]
        );
        if ($company === null) {
            throw new \InvalidArgumentException('business.contact_company_archived');
        }
        $iamUserId = isset($current['iam_user_id']) && $current['iam_user_id'] !== null ? (int) $current['iam_user_id'] : null;
        if ($iamUserId !== null && $this->activeByIamUser($siteId, $iamUserId, $id) !== null) {
            throw new \InvalidArgumentException('business.contact_iam_user_already_linked');
        }
        $this->database()->run(
            'UPDATE business_contacts SET archived_at = NULL, updated_by_iam_user_id = :actor, updated_at = CURRENT_TIMESTAMP WHERE site_id = :site_id AND id = :id',
            ['site_id' => $siteId, 'id' => $id, 'actor' => $actorId]
        );
        return $this->find($siteId, $id) !== null;
    }

    public function deleteArchived(int $siteId, int $id): bool
    {
        $siteId = $this->requireSiteId($siteId);
        $current = $this->find($siteId, $id, true);
        if ($current === null) {
            return false;
        }
        if (($current['archived_at'] ?? null) === null) {
            throw new \InvalidArgumentException('business.contact_must_be_archived_before_delete');
        }

        $this->database()->transaction(function () use ($siteId, $id): void {
            $this->database()->run(
                "DELETE FROM business_activity_log
                 WHERE site_id = :site_id
                   AND ((entity_type = 'business_contact' AND entity_id = :id) OR related_contact_id = :id)",
                ['site_id' => $siteId, 'id' => $id]
            );
            $this->database()->run(
                'DELETE FROM business_contacts WHERE site_id = :site_id AND id = :id AND archived_at IS NOT NULL',
                ['site_id' => $siteId, 'id' => $id]
            );
        });

        return $this->find($siteId, $id, true) === null;
    }

    private function displayName(array $payload): string
    {
        $explicit = $this->nullableText($payload['display_name'] ?? null, 'display_name', 255);
        if ($explicit !== null) {
            return $explicit;
        }
        $name = trim((string) ($payload['first_name'] ?? '') . ' ' . (string) ($payload['last_name'] ?? ''));
        return $this->text($name, 'display_name', 255);
    }
}
