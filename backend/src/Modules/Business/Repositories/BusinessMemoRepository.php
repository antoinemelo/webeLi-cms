<?php

declare(strict_types=1);

namespace App\Modules\Business\Repositories;

use InvalidArgumentException;

final class BusinessMemoRepository extends BusinessRepositoryBase
{
    /** @return array{items:list<array<string,mixed>>,limit:int,offset:int} */
    public function list(int $siteId, array $filters = [], int $limit = 50, int $offset = 0, bool $includeArchived = false): array
    {
        $siteId = $this->requireSiteId($siteId);
        $limit = $this->limit($limit);
        $offset = $this->offset($offset);
        $where = ['site_id = :site_id'];
        $params = ['site_id' => $siteId];
        if (!$includeArchived) {
            $where[] = 'archived_at IS NULL';
        }
        if (isset($filters['company_id'])) {
            $where[] = 'company_id = :company_id';
            $params['company_id'] = (int) $filters['company_id'];
        }
        if (isset($filters['contact_id'])) {
            $where[] = 'contact_id = :contact_id';
            $params['contact_id'] = (int) $filters['contact_id'];
        }
        if (trim((string) ($filters['q'] ?? '')) !== '') {
            $where[] = '(title LIKE :q OR body LIKE :q)';
            $params['q'] = '%' . trim((string) $filters['q']) . '%';
        }
        $rows = $this->database()->all(
            'SELECT * FROM crm_memos WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );
        return ['items' => array_map(fn(array $row): array => $this->castRow($row), $rows), 'limit' => $limit, 'offset' => $offset];
    }

    public function find(int $siteId, int $id, bool $includeArchived = false): ?array
    {
        $row = $this->database()->one(
            'SELECT * FROM crm_memos WHERE site_id = :site_id AND id = :id' . ($includeArchived ? '' : ' AND archived_at IS NULL') . ' LIMIT 1',
            ['site_id' => $this->requireSiteId($siteId), 'id' => $id]
        );
        return $row ? $this->castRow($row) : null;
    }

    public function create(int $siteId, array $payload, int $authorIamUserId, ?int $actorId = null): array
    {
        $siteId = $this->requireSiteId($siteId);
        if ($authorIamUserId < 1) {
            throw new InvalidArgumentException('business.memo_author_required');
        }
        $companyId = isset($payload['company_id']) && $payload['company_id'] !== '' ? (int) $payload['company_id'] : null;
        $contactId = isset($payload['contact_id']) && $payload['contact_id'] !== '' ? (int) $payload['contact_id'] : null;
        if ($companyId === null && $contactId === null) {
            throw new InvalidArgumentException('business.memo_target_required');
        }
        $this->assertTargetsBelongToSite($siteId, $companyId, $contactId);
        $visibility = (string) ($payload['visibility'] ?? 'private');
        if (!in_array($visibility, ['private', 'internal', 'public_link'], true)) {
            throw new InvalidArgumentException('business.memo_visibility_invalid');
        }
        $this->database()->run(
            'INSERT INTO crm_memos(site_id, company_id, contact_id, author_iam_user_id, title, body, visibility, created_by_iam_user_id, updated_by_iam_user_id)
             VALUES(:site_id, :company_id, :contact_id, :author, :title, :body, :visibility, :actor, :actor)',
            [
                'site_id' => $siteId,
                'company_id' => $companyId,
                'contact_id' => $contactId,
                'author' => $authorIamUserId,
                'title' => $this->text($payload['title'] ?? null, 'title', 180),
                'body' => $this->nullableText($payload['body'] ?? '', 'body', 20000) ?? '',
                'visibility' => $visibility,
                'actor' => $actorId ?? $authorIamUserId,
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
        $merged = $payload + $current;
        $companyId = isset($merged['company_id']) && $merged['company_id'] !== '' ? (int) $merged['company_id'] : null;
        $contactId = isset($merged['contact_id']) && $merged['contact_id'] !== '' ? (int) $merged['contact_id'] : null;
        if ($companyId === null && $contactId === null) {
            throw new InvalidArgumentException('business.memo_target_required');
        }
        $this->assertTargetsBelongToSite($siteId, $companyId, $contactId);
        $visibility = (string) ($merged['visibility'] ?? 'private');
        if (!in_array($visibility, ['private', 'internal', 'public_link'], true)) {
            throw new InvalidArgumentException('business.memo_visibility_invalid');
        }
        $this->database()->run(
            'UPDATE crm_memos SET company_id = :company_id, contact_id = :contact_id, title = :title, body = :body, visibility = :visibility,
                updated_by_iam_user_id = :actor, updated_at = CURRENT_TIMESTAMP
             WHERE site_id = :site_id AND id = :id AND archived_at IS NULL',
            [
                'site_id' => $this->requireSiteId($siteId),
                'id' => $id,
                'company_id' => $companyId,
                'contact_id' => $contactId,
                'title' => $this->text($merged['title'] ?? null, 'title', 180),
                'body' => $this->nullableText($merged['body'] ?? '', 'body', 20000) ?? '',
                'visibility' => $visibility,
                'actor' => $actorId,
            ]
        );
        return $this->find($siteId, $id);
    }

    public function archive(int $siteId, int $id, ?int $actorId = null): bool
    {
        $this->database()->run(
            'UPDATE crm_memos SET archived_at = COALESCE(archived_at, CURRENT_TIMESTAMP), updated_by_iam_user_id = :actor, updated_at = CURRENT_TIMESTAMP WHERE site_id = :site_id AND id = :id',
            ['site_id' => $this->requireSiteId($siteId), 'id' => $id, 'actor' => $actorId]
        );
        return true;
    }

    public function shareWithIamUser(int $siteId, int $memoId, int $sharedWithIamUserId, int $actorId): array
    {
        $this->assertMemo($siteId, $memoId);
        if ($sharedWithIamUserId < 1 || $actorId < 1) {
            throw new InvalidArgumentException('business.memo_share_user_invalid');
        }
        $this->database()->run(
            "INSERT INTO crm_memo_shares(memo_id, share_type, shared_with_iam_user_id, created_by_iam_user_id)
             VALUES(:memo_id, 'iam_user', :shared_with, :actor)
             ON CONFLICT(memo_id, shared_with_iam_user_id) DO UPDATE SET revoked_at = NULL",
            ['memo_id' => $memoId, 'shared_with' => $sharedWithIamUserId, 'actor' => $actorId]
        );
        $row = $this->database()->one(
            "SELECT * FROM crm_memo_shares WHERE memo_id = :memo_id AND share_type = 'iam_user' AND shared_with_iam_user_id = :shared_with",
            ['memo_id' => $memoId, 'shared_with' => $sharedWithIamUserId]
        );
        return $row ? $this->castRow($row) : [];
    }

    /** @return list<array<string,mixed>> */
    public function sharesForMemo(int $siteId, int $memoId): array
    {
        $this->assertMemo($siteId, $memoId);
        $rows = $this->database()->all('SELECT * FROM crm_memo_shares WHERE memo_id = :memo_id ORDER BY id', ['memo_id' => $memoId]);
        return array_map(fn(array $row): array => $this->castRow($row), $rows);
    }

    public function createPublicShare(int $siteId, int $memoId, string $tokenHash, int $actorId, ?string $label = null, ?string $expiresAt = null): array
    {
        $this->assertMemo($siteId, $memoId);
        if ($actorId < 1 || strlen($tokenHash) < 32) {
            throw new InvalidArgumentException('business.memo_share_public_invalid');
        }
        $this->database()->run(
            "INSERT INTO crm_memo_shares(memo_id, share_type, public_token_hash, public_label, expires_at, created_by_iam_user_id)
             VALUES(:memo_id, 'public_link', :hash, :label, :expires_at, :actor)",
            [
                'memo_id' => $memoId,
                'hash' => $tokenHash,
                'label' => $this->nullableText($label, 'public_label', 180),
                'expires_at' => $this->nullableText($expiresAt, 'expires_at', 64),
                'actor' => $actorId,
            ]
        );
        $row = $this->database()->one('SELECT * FROM crm_memo_shares WHERE id = :id', ['id' => $this->database()->lastInsertId()]);
        return $row ? $this->castRow($row) : [];
    }

    public function revokeShare(int $siteId, int $shareId, int $actorId): bool
    {
        if ($actorId < 1) {
            throw new InvalidArgumentException('business.memo_share_actor_required');
        }
        $this->database()->run(
            'UPDATE crm_memo_shares SET revoked_at = COALESCE(revoked_at, CURRENT_TIMESTAMP)
             WHERE id = :share_id AND memo_id IN (SELECT id FROM crm_memos WHERE site_id = :site_id)',
            ['share_id' => $shareId, 'site_id' => $this->requireSiteId($siteId)]
        );
        return true;
    }

    public function revokePublicSharesForMemo(int $siteId, int $memoId, int $actorId): int
    {
        $this->assertMemo($siteId, $memoId);
        if ($actorId < 1) {
            throw new InvalidArgumentException('business.memo_share_actor_required');
        }
        $before = $this->database()->one(
            "SELECT COUNT(*) AS count FROM crm_memo_shares WHERE memo_id = :memo_id AND share_type = 'public_link' AND revoked_at IS NULL",
            ['memo_id' => $memoId]
        );
        $this->database()->run(
            "UPDATE crm_memo_shares SET revoked_at = COALESCE(revoked_at, CURRENT_TIMESTAMP)
             WHERE memo_id = :memo_id AND share_type = 'public_link' AND revoked_at IS NULL",
            ['memo_id' => $memoId]
        );
        return (int) ($before['count'] ?? 0);
    }

    public function publicShareByToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || strlen($token) < 32 || !preg_match('/^[A-Za-z0-9]+$/', $token)) {
            return null;
        }
        $hash = hash('sha256', $token);
        $row = $this->database()->one(
            "SELECT s.*, m.site_id, m.company_id, m.contact_id, m.title, m.body, m.visibility, m.created_at AS memo_created_at, m.updated_at AS memo_updated_at,
                    co.name AS company_name, c.display_name AS contact_name
             FROM crm_memo_shares s
             JOIN crm_memos m ON m.id = s.memo_id
             LEFT JOIN business_companies co ON co.id = m.company_id
             LEFT JOIN business_contacts c ON c.id = m.contact_id
             WHERE s.share_type = 'public_link'
               AND s.public_token_hash = :hash
               AND m.archived_at IS NULL
             LIMIT 1",
            ['hash' => $hash]
        );
        return $row ? $this->castRow($row) : null;
    }

    public function markPublicShareAccessed(int $shareId): void
    {
        $this->database()->run(
            'UPDATE crm_memo_shares SET last_accessed_at = CURRENT_TIMESTAMP, access_count = access_count + 1 WHERE id = :id',
            ['id' => $shareId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function comments(int $siteId, int $memoId, bool $includeArchived = false): array
    {
        $this->assertMemo($siteId, $memoId);
        $rows = $this->database()->all(
            'SELECT * FROM crm_memo_comments WHERE memo_id = :memo_id' . ($includeArchived ? '' : ' AND archived_at IS NULL') . ' ORDER BY created_at ASC, id ASC',
            ['memo_id' => $memoId]
        );
        return array_map(fn(array $row): array => $this->castRow($row), $rows);
    }

    public function addComment(int $siteId, int $memoId, int $authorIamUserId, string $body): array
    {
        $this->assertMemo($siteId, $memoId);
        if ($authorIamUserId < 1) {
            throw new InvalidArgumentException('business.memo_comment_author_required');
        }
        $this->database()->run(
            'INSERT INTO crm_memo_comments(memo_id, author_iam_user_id, body) VALUES(:memo_id, :author, :body)',
            [
                'memo_id' => $memoId,
                'author' => $authorIamUserId,
                'body' => $this->text($body, 'comment_body', 10000),
            ]
        );
        $row = $this->database()->one('SELECT * FROM crm_memo_comments WHERE id = :id', ['id' => $this->database()->lastInsertId()]);
        return $row ? $this->castRow($row) : [];
    }

    private function assertMemo(int $siteId, int $memoId): void
    {
        if ($this->find($siteId, $memoId) === null) {
            throw new InvalidArgumentException('business.memo_not_found');
        }
    }

    private function assertTargetsBelongToSite(int $siteId, ?int $companyId, ?int $contactId): void
    {
        if ($companyId !== null) {
            $company = $this->database()->one(
                'SELECT id FROM business_companies WHERE site_id = :site_id AND id = :id AND archived_at IS NULL LIMIT 1',
                ['site_id' => $siteId, 'id' => $companyId]
            );
            if ($company === null) {
                throw new InvalidArgumentException('business.memo_company_not_found');
            }
        }

        if ($contactId !== null) {
            $contact = $this->database()->one(
                'SELECT company_id FROM business_contacts WHERE site_id = :site_id AND id = :id AND archived_at IS NULL LIMIT 1',
                ['site_id' => $siteId, 'id' => $contactId]
            );
            if ($contact === null) {
                throw new InvalidArgumentException('business.memo_contact_not_found');
            }
            if ($companyId !== null && (int) ($contact['company_id'] ?? 0) !== $companyId) {
                throw new InvalidArgumentException('business.memo_target_mismatch');
            }
        }
    }
}
