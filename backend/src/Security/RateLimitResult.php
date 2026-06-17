<?php

declare(strict_types=1);

namespace App\Security;

final class RateLimitResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly int $limit,
        public readonly int $windowSeconds,
        public readonly int $remaining,
        public readonly int $retryAfterSeconds,
        public readonly int $resetAt,
    ) {}
}
