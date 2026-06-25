<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Minimal RFC 6238 TOTP implementation without external dependency.
 * Secrets are Base32 encoded and stored encrypted-at-rest through sodium when available.
 */
final class TotpService
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function recoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        }
        return $codes;
    }

    public static function hashRecoveryCodes(array $codes): string
    {
        $hashes = array_map(static fn(string $code): string => password_hash(self::normalizeCode($code), PASSWORD_DEFAULT), $codes);
        return json_encode($hashes, JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    /** @return array{ok:bool,hashes:string,used_code?:string} */
    public static function verifyRecoveryCode(string $code, ?string $hashesJson): array
    {
        $normalized = self::normalizeCode($code);
        $hashes = json_decode((string) $hashesJson, true);
        if ($normalized === '' || !is_array($hashes)) {
            return ['ok' => false, 'hashes' => is_string($hashesJson) ? $hashesJson : '[]'];
        }
        foreach ($hashes as $index => $hash) {
            if (is_string($hash) && password_verify($normalized, $hash)) {
                unset($hashes[$index]);
                return ['ok' => true, 'hashes' => json_encode(array_values($hashes), JSON_UNESCAPED_SLASHES) ?: '[]', 'used_code' => $normalized];
            }
        }
        return ['ok' => false, 'hashes' => json_encode(array_values($hashes), JSON_UNESCAPED_SLASHES) ?: '[]'];
    }

    public static function currentCode(string $secret, ?int $timestamp = null): string
    {
        return self::code($secret, $timestamp ?? time());
    }

    public static function verifyCode(string $secret, string $code, int $window = 1, ?int $timestamp = null): bool
    {
        $code = self::normalizeCode($code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $timestamp = $timestamp ?? time();
        for ($i = -$window; $i <= $window; $i++) {
            $expected = self::code($secret, $timestamp + ($i * 30));
            if (hash_equals($expected, $code)) {
                return true;
            }
        }
        return false;
    }

    public static function encryptSecret(string $secret, string $appKey = ''): string
    {
        if ($secret === '') {
            return '';
        }
        if (function_exists('sodium_crypto_secretbox')) {
            $key = hash('sha256', $appKey !== '' ? $appKey : self::fallbackKey(), true);
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($secret, $nonce, $key);
            return 'sodium:' . base64_encode($nonce . $cipher);
        }
        if (function_exists('openssl_encrypt')) {
            $key = hash('sha256', $appKey !== '' ? $appKey : self::fallbackKey(), true);
            $iv = random_bytes(16);
            $cipher = openssl_encrypt($secret, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
            if (is_string($cipher)) {
                return 'openssl:' . base64_encode($iv . $cipher);
            }
        }
        throw new \RuntimeException('TOTP_SECRET_ENCRYPTION_UNAVAILABLE');
    }

    public static function decryptSecret(?string $protected, string $appKey = ''): string
    {
        $protected = (string) $protected;
        if ($protected === '') {
            return '';
        }
        if (str_starts_with($protected, 'sodium:') && function_exists('sodium_crypto_secretbox_open')) {
            $raw = base64_decode(substr($protected, 7), true);
            if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
                return '';
            }
            $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $key = hash('sha256', $appKey !== '' ? $appKey : self::fallbackKey(), true);
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
            return is_string($plain) ? $plain : '';
        }
        if (str_starts_with($protected, 'openssl:') && function_exists('openssl_decrypt')) {
            $raw = base64_decode(substr($protected, 8), true);
            if ($raw === false || strlen($raw) <= 16) {
                return '';
            }
            $iv = substr($raw, 0, 16);
            $cipher = substr($raw, 16);
            $key = hash('sha256', $appKey !== '' ? $appKey : self::fallbackKey(), true);
            $plain = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
            return is_string($plain) ? $plain : '';
        }
        return '';
    }

    public static function otpauthUri(string $issuer, string $account, string $secret): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=6&period=30';
    }

    private static function code(string $secret, int $timestamp): string
    {
        $counter = intdiv($timestamp, 30);
        $key = self::base32Decode($secret);
        $binCounter = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac('sha1', $binCounter, $key, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $truncated = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;
        return str_pad((string) ($truncated % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $encoded;
    }

    private static function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret) ?? '');
        $bits = '';
        for ($i = 0, $l = strlen($secret); $i < $l; $i++) {
            $pos = strpos(self::BASE32_ALPHABET, $secret[$i]);
            if ($pos !== false) {
                $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
            }
        }
        $data = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $data .= chr(bindec($byte));
            }
        }
        return $data;
    }

    private static function normalizeCode(string $code): string
    {
        return preg_replace('/\D+/', '', $code) ?? '';
    }

    private static function fallbackKey(): string
    {
        return __DIR__ . '|am-cms-native-totp';
    }
}
