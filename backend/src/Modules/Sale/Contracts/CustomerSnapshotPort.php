<?php

declare(strict_types=1);

namespace App\Modules\Sale\Contracts;

interface CustomerSnapshotPort
{
    /** @return array<string,mixed>|null */
    public function snapshot(int $siteId, ?int $companyId = null, ?int $contactId = null): ?array;
}
