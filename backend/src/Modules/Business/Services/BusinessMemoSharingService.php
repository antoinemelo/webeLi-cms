<?php

declare(strict_types=1);

namespace App\Modules\Business\Services;

use App\Modules\Business\Repositories\BusinessMemoRepository;
use InvalidArgumentException;

final class BusinessMemoSharingService
{
    public function __construct(private readonly BusinessMemoRepository $memos) {}

    public function createMemo(int $siteId, array $payload, int $authorIamUserId, ?int $actorId = null): array
    {
        return $this->memos->create($siteId, $payload, $authorIamUserId, $actorId);
    }

    public function shareInternally(int $siteId, int $memoId, array $iamUserIds, int $actorId): array
    {
        $shares = [];
        foreach ($iamUserIds as $iamUserId) {
            $shares[] = $this->memos->shareWithIamUser($siteId, $memoId, (int) $iamUserId, $actorId);
        }
        return $shares;
    }

    public function createPublicShare(int $siteId, int $memoId, int $actorId, bool $hasPermission, ?string $label = null, ?string $expiresAt = null): array
    {
        if (!$hasPermission) {
            throw new InvalidArgumentException('business.memo_share_public_forbidden');
        }
        $token = bin2hex(random_bytes(24));
        $share = $this->memos->createPublicShare($siteId, $memoId, hash('sha256', $token), $actorId, $label, $expiresAt);
        return ['share' => $share, 'token' => $token];
    }

    public function revokeShare(int $siteId, int $shareId, int $actorId): bool
    {
        return $this->memos->revokeShare($siteId, $shareId, $actorId);
    }
}
