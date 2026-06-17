<?php

declare(strict_types=1);

namespace App\Application\VisualEditing;

use App\Core\Database;

final class BlockEditingLockRepository
{
    private const TTL_SECONDS = 120;

    /** @var array<int,string> */
    private array $userEmailCache = [];

    public function __construct(
        private readonly Database $db,
        private readonly Database $iamDb,
    ) {}

    /** @return array<string,mixed> */
    public function acquire(int $entryId, string $languageCode, string $blockId, int $userId, string $userLabel, ?string $token = null): array
    {
        $this->purgeExpired();
        $resourceType = $this->resourceType($languageCode, $blockId);
        $existing = $this->activeRow($entryId, $resourceType);
        $now = now_utc();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + self::TTL_SECONDS);

        if ($existing && (int) ($existing['locked_by_iam_user_id'] ?? 0) !== $userId) {
            return $this->rowPayload($existing, $languageCode, $blockId, $userId) + ['acquired' => false];
        }

        $lockToken = $token && preg_match('/^[A-Za-z0-9._:-]{16,160}$/', $token) ? $token : bin2hex(random_bytes(24));
        if ($existing) {
            $this->db->run(
                'UPDATE revision_locks SET lock_token = :token, expires_at = :expires_at WHERE id = :id',
                ['token' => $lockToken, 'expires_at' => $expiresAt, 'id' => (int) $existing['id']]
            );
        } else {
            $this->db->run(
                'INSERT INTO revision_locks(resource_type, resource_id, locked_by_iam_user_id, lock_token, expires_at, created_at) VALUES(:resource_type, :resource_id, :user_id, :token, :expires_at, :created_at)',
                ['resource_type' => $resourceType, 'resource_id' => $entryId, 'user_id' => $userId, 'token' => $lockToken, 'expires_at' => $expiresAt, 'created_at' => $now]
            );
        }

        $row = $this->activeRow($entryId, $resourceType) ?: [
            'locked_by_iam_user_id' => $userId,
            'lock_token' => $lockToken,
            'expires_at' => $expiresAt,
        ];
        return $this->rowPayload($row, $languageCode, $blockId, $userId, $userLabel) + ['acquired' => true];
    }

    public function release(int $entryId, string $languageCode, string $blockId, int $userId, ?string $token = null): void
    {
        $params = ['resource_type' => $this->resourceType($languageCode, $blockId), 'resource_id' => $entryId, 'user_id' => $userId];
        $sql = 'DELETE FROM revision_locks WHERE resource_type = :resource_type AND resource_id = :resource_id AND locked_by_iam_user_id = :user_id';
        if ($token) {
            $sql .= ' AND lock_token = :token';
            $params['token'] = $token;
        }
        $this->db->run($sql, $params);
    }

    /** @return list<array<string,mixed>> */
    public function listForEntry(int $entryId, string $languageCode, int $currentUserId): array
    {
        $this->purgeExpired();
        $prefix = $this->resourcePrefix($languageCode) . '%';
        $rows = $this->db->all(
            'SELECT * FROM revision_locks WHERE resource_id = :entry_id AND resource_type LIKE :prefix AND expires_at > :now ORDER BY created_at ASC',
            ['entry_id' => $entryId, 'prefix' => $prefix, 'now' => now_utc()]
        );
        $items = [];
        foreach ($rows as $row) {
            $blockId = $this->blockIdFromResourceType((string) ($row['resource_type'] ?? ''), $languageCode);
            if ($blockId === '') {
                continue;
            }
            $items[] = $this->rowPayload($row, $languageCode, $blockId, $currentUserId);
        }
        return $items;
    }

    /** @return array<string,mixed>|null */
    public function conflictingLock(int $entryId, string $languageCode, string $blockId, int $userId, ?string $token = null): ?array
    {
        $this->purgeExpired();
        $row = $this->activeRow($entryId, $this->resourceType($languageCode, $blockId));
        if (!$row) {
            return null;
        }
        if ((int) ($row['locked_by_iam_user_id'] ?? 0) === $userId) {
            if (!$token || hash_equals((string) ($row['lock_token'] ?? ''), $token)) {
                return null;
            }
        }
        return $this->rowPayload($row, $languageCode, $blockId, $userId);
    }

    public function purgeExpired(): void
    {
        $this->db->run("DELETE FROM revision_locks WHERE resource_type LIKE 'visual_block:%' AND expires_at <= :now", ['now' => now_utc()]);
    }

    private function activeRow(int $entryId, string $resourceType): ?array
    {
        return $this->db->one(
            'SELECT * FROM revision_locks WHERE resource_type = :resource_type AND resource_id = :resource_id AND expires_at > :now ORDER BY created_at DESC LIMIT 1',
            ['resource_type' => $resourceType, 'resource_id' => $entryId, 'now' => now_utc()]
        );
    }

    private function resourcePrefix(string $languageCode): string
    {
        return 'visual_block:' . strtolower(trim($languageCode)) . ':';
    }

    private function resourceType(string $languageCode, string $blockId): string
    {
        return $this->resourcePrefix($languageCode) . rawurlencode($blockId);
    }

    private function blockIdFromResourceType(string $resourceType, string $languageCode): string
    {
        $prefix = $this->resourcePrefix($languageCode);
        if (!str_starts_with($resourceType, $prefix)) {
            return '';
        }
        return rawurldecode(substr($resourceType, strlen($prefix)));
    }

    /** @return array<string,mixed> */
    private function rowPayload(array $row, string $languageCode, string $blockId, int $currentUserId, string $currentUserLabel = ''): array
    {
        $ownerId = (int) ($row['locked_by_iam_user_id'] ?? 0);
        $isCurrentUser = $ownerId === $currentUserId;
        return [
            'block_id' => $blockId,
            'language_code' => strtolower($languageCode),
            'locked_by_iam_user_id' => $ownerId,
            'locked_by_label' => $this->userLockLabel($ownerId, $isCurrentUser ? $currentUserLabel : ''),
            'is_current_user' => $isCurrentUser,
            'lock_token' => $isCurrentUser ? (string) ($row['lock_token'] ?? '') : '',
            'expires_at' => (string) ($row['expires_at'] ?? ''),
            'ttl_seconds' => self::TTL_SECONDS,
        ];
    }

    private function userLockLabel(int $userId, string $currentUserLabel = ''): string
    {
        if ($currentUserLabel !== '' && filter_var($currentUserLabel, FILTER_VALIDATE_EMAIL)) {
            return $currentUserLabel;
        }

        $email = $this->userEmail($userId);
        if ($email !== '') {
            return $email;
        }

        if ($currentUserLabel !== '') {
            return $currentUserLabel;
        }

        return 'utilisateur inconnu';
    }

    private function userEmail(int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }
        if (array_key_exists($userId, $this->userEmailCache)) {
            return $this->userEmailCache[$userId];
        }
        $row = $this->iamDb->one('SELECT email FROM iam_users WHERE id = :id LIMIT 1', ['id' => $userId]);
        $email = trim((string) ($row['email'] ?? ''));
        $this->userEmailCache[$userId] = $email;
        return $email;
    }
}
