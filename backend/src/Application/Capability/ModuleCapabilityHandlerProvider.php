<?php

declare(strict_types=1);

namespace App\Application\Capability;

interface ModuleCapabilityHandlerProvider
{
    /** @return array<string,callable> */
    public function capabilityHandlers(): array;
}
