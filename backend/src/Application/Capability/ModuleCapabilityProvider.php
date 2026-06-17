<?php

declare(strict_types=1);

namespace App\Application\Capability;

/**
 * Optional module-side contract. A module may expose controlled actions without
 * coupling the core to any AI/provider/business logic.
 */
interface ModuleCapabilityProvider
{
    /** @return list<array<string,mixed>|CapabilityDefinition> */
    public function capabilities(): array;
}
