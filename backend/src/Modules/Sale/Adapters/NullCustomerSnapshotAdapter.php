<?php

declare(strict_types=1);

namespace App\Modules\Sale\Adapters;

use App\Modules\Sale\Contracts\CustomerSnapshotPort;

final class NullCustomerSnapshotAdapter implements CustomerSnapshotPort
{
    /** @return array<string,mixed>|null */
    public function snapshot(int $siteId, ?int $companyId = null, ?int $contactId = null): ?array
    {
        return null;
    }
}
