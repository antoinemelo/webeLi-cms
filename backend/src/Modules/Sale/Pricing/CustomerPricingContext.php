<?php

declare(strict_types=1);

namespace App\Modules\Sale\Pricing;

final readonly class CustomerPricingContext
{
    /** @param list<string> $segments */
    public function __construct(
        public int $siteId,
        public ?int $contactId,
        public ?int $companyId,
        public array $segments,
        public bool $marketingAllowed,
    ) {}

    /** @return array{site_id:int,contact_id:?int,company_id:?int,segments:list<string>,marketing_allowed:bool} */
    public function toArray(): array
    {
        return [
            'site_id' => $this->siteId,
            'contact_id' => $this->contactId,
            'company_id' => $this->companyId,
            'segments' => $this->segments,
            'marketing_allowed' => $this->marketingAllowed,
        ];
    }
}
