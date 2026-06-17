<?php

declare(strict_types=1);

namespace App\Application\Frontend;

use App\Core\Response;

final class HomeController extends FrontendPageController
{
    public function index(): Response
    {
        return $this->handlePublicRequest();
    }
}
