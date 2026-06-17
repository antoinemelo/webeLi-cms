<?php

declare(strict_types=1);

namespace App\Application\Routing;

use App\Domain\Routing\Redirect;

interface RedirectRepository
{
    public function deleteByResource(string $resourceType, int $resourceId): void;

    public function replace(Redirect $redirect): void;
}
