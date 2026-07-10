<?php

declare(strict_types=1);

namespace App\Modules\Sale\Adapters;

use App\Modules\Sale\Contracts\CrmActivitySink;

final class NullCrmActivitySink implements CrmActivitySink
{
    /** @param array<string,mixed> $event */
    public function recordSaleEvent(array $event): void {}
}
