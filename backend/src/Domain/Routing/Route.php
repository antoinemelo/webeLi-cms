<?php

declare(strict_types=1);

namespace App\Domain\Routing;

final class Route
{
    public function __construct(
        public readonly string $languageCode,
        public readonly string $resourceType,
        public readonly int $resourceId,
        public readonly string $fullPath,
        public readonly bool $isCanonical = true
    ) {}
}
