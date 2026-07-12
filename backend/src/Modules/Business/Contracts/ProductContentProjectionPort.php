<?php

declare(strict_types=1);

namespace App\Modules\Business\Contracts;

interface ProductContentProjectionPort
{
    public function refreshProduct(int $siteId, int $productId): void;

    public function deactivateProduct(int $siteId, int $productId): void;

    public function assertProductCanBeDeleted(int $siteId, int $productId): void;
}
