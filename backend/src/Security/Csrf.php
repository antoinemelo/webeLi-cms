<?php

declare(strict_types=1);

namespace App\Security;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }

    public static function verify(?string $token): bool
    {
        return is_string($token)
            && isset($_SESSION['_csrf_token'])
            && is_string($_SESSION['_csrf_token'])
            && hash_equals($_SESSION['_csrf_token'], $token);
    }

    public static function verifyHeader(?string $token): bool
    {
        return self::verify($token);
    }

    public static function rotate(): void
    {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
}
