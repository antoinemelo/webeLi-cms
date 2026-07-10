<?php

declare(strict_types=1);

namespace App\Modules\Sale\Adapters;

use App\Modules\Sale\Contracts\CmsAccountBridge;

final class NullCmsAccountBridge implements CmsAccountBridge
{
    /** @return array<string,mixed>|null */
    public function customerForAccount(int $siteId, int $iamUserId): ?array
    {
        return null;
    }

    /** @param array<string,mixed> $profile @return array<string,mixed> */
    public function createOrUpdateProfile(int $siteId, array $profile): array
    {
        return $profile + ['site_id' => $siteId, 'profile_created' => false];
    }
}
