<?php

declare(strict_types=1);

namespace App\Modules\Sale\Contracts;

interface CrmActivitySink
{
    /** @param array<string,mixed> $event */
    public function recordSaleEvent(array $event): void;
}
