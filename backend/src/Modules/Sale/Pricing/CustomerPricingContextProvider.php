<?php

declare(strict_types=1);

namespace App\Modules\Sale\Pricing;

interface CustomerPricingContextProvider
{
    public function context(int $siteId, ?int $contactId, ?int $companyId, ?int $iamUserId = null): CustomerPricingContext;
}
