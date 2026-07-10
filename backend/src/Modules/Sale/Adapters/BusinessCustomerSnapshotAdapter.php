<?php

declare(strict_types=1);

namespace App\Modules\Sale\Adapters;

use App\Modules\Business\Services\BusinessCrmRelationSnapshotService;
use App\Modules\Sale\Contracts\CustomerSnapshotPort;

final class BusinessCustomerSnapshotAdapter implements CustomerSnapshotPort
{
    public function __construct(private readonly BusinessCrmRelationSnapshotService $relations) {}

    /** @return array<string,mixed>|null */
    public function snapshot(int $siteId, ?int $companyId = null, ?int $contactId = null): ?array
    {
        return $this->relations->snapshot($siteId, $companyId, $contactId);
    }
}
