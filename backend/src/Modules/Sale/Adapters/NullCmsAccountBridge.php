<?php

declare(strict_types=1);

namespace App\Modules\Sale\Adapters;

use App\Modules\Sale\Contracts\CmsAccountBridge;

/** @deprecated Compatibility alias for the test-only NullCustomerIdentityBridge. */
final class NullCmsAccountBridge extends NullCustomerIdentityBridge implements CmsAccountBridge {}
