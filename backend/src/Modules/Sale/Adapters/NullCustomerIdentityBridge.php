<?php

declare(strict_types=1);

namespace App\Modules\Sale\Adapters;

use App\Modules\Sale\Contracts\CustomerIdentityBridge;

/** Test-only fallback used when IAM or CRM is deliberately unavailable. */
class NullCustomerIdentityBridge implements CustomerIdentityBridge
{
    public function customerForAccount(int $siteId, int $iamUserId): ?array { return null; }

    public function createOrUpdateProfile(int $siteId, array $profile): array
    {
        return $profile + ['site_id' => $siteId, 'profile_created' => false, 'fallback' => true];
    }
}
