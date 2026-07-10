<?php

declare(strict_types=1);

namespace App\Modules\Sale\Contracts;

interface CmsAccountBridge
{
    /** @return array<string,mixed>|null */
    public function customerForAccount(int $siteId, int $iamUserId): ?array;

    /** @param array<string,mixed> $profile @return array<string,mixed> */
    public function createOrUpdateProfile(int $siteId, array $profile): array;
}
