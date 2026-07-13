<?php

declare(strict_types=1);

namespace App\Modules\Business\Contracts;

interface ProductContentSourcePort
{
    /** @return array<string,mixed>|null */
    public function productSnapshot(int $siteId, int $productId, string $locale): ?array;
}
