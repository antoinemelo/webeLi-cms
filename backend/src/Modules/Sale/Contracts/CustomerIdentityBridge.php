<?php

declare(strict_types=1);

namespace App\Modules\Sale\Contracts;

/** Explicit bridge between a Sale transactional customer, IAM account and CRM relation. */
interface CustomerIdentityBridge
{
    /** @return array<string,mixed>|null */
    public function customerForAccount(int $siteId, int $iamUserId): ?array;

    /** @param array<string,mixed> $profile @return array<string,mixed> */
    public function createOrUpdateProfile(int $siteId, array $profile): array;
}
