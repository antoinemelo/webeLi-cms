<?php

declare(strict_types=1);

namespace App\Application\Frontend;

use App\Core\Response;

final class RouteResolutionController extends FrontendPageController
{
    public function show(string $path): Response
    {
        return $this->handlePublicRequest();
    }
}
