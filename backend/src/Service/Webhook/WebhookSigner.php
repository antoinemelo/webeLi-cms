<?php

declare(strict_types=1);

namespace App\Service\Webhook;

final class WebhookSigner
{
    public static function sign(string $body, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    public static function verify(string $body, string $secret, string $signature): bool
    {
        return hash_equals(self::sign($body, $secret), $signature);
    }
}
