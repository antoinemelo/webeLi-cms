<?php

declare(strict_types=1);

use App\Core\App;

require_once __DIR__ . '/runtime.php';

return new App(require __DIR__ . '/config.php');
