<?php

declare(strict_types=1);

namespace App\Security;

final class SessionManager
{
    public static function start(array $config): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secure = self::isHttps();
        $name = (string) ($config['app']['session_name'] ?? 'amcms');
        session_name($name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => app_base_path() ?: '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        if ($secure) {
            ini_set('session.cookie_secure', '1');
        }
        session_start();

        $_SESSION['_created_at'] ??= time();
        $_SESSION['_last_activity_at'] ??= time();
    }

    public static function rotate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $_SESSION['_created_at'] = time();
            $_SESSION['_last_activity_at'] = time();
        }
    }

    public static function enforceIdleTimeout(int $seconds): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE || $seconds <= 0) {
            return true;
        }
        $last = (int) ($_SESSION['_last_activity_at'] ?? time());
        if (time() - $last > $seconds) {
            self::destroy();
            return false;
        }
        $_SESSION['_last_activity_at'] = time();
        return true;
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
    }

    private static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }
}
