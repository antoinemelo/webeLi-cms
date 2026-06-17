<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Stateless, signed preview tokens.
 *
 * The token is intentionally self-contained so preview rendering does not need
 * to persist public sessions or expose unpublished content through a public API.
 */
final class PreviewSigner
{
    public function __construct(
        private readonly string $secret,
        private readonly int $ttlMinutes = 60,
    ) {}

    /** @return array{entry_id:int,revision_id:int,site_id:int,language_code:string,expires_at:int,nonce:string} */
    public function claims(int $entryId, int $revisionId, int $siteId, string $languageCode): array
    {
        return [
            'entry_id' => $entryId,
            'revision_id' => $revisionId,
            'site_id' => $siteId,
            'language_code' => $languageCode,
            'expires_at' => time() + ($this->ttlMinutes * 60),
            'nonce' => bin2hex(random_bytes(12)),
        ];
    }

    public function create(int $entryId, int $revisionId = 0, int $siteId = 0, string $languageCode = ''): string
    {
        return $this->encode($this->claims($entryId, $revisionId, $siteId, $languageCode));
    }

    /** @param array<string,mixed> $claims */
    public function encode(array $claims): string
    {
        $payload = [
            'purpose' => 'content_preview',
            'v' => 2,
            'entry_id' => max(1, (int) ($claims['entry_id'] ?? 0)),
            'revision_id' => max(0, (int) ($claims['revision_id'] ?? 0)),
            'site_id' => max(0, (int) ($claims['site_id'] ?? 0)),
            'language_code' => strtolower(trim((string) ($claims['language_code'] ?? ''))),
            'expires_at' => max(0, (int) ($claims['expires_at'] ?? 0)),
            'nonce' => preg_replace('/[^a-f0-9]/', '', strtolower((string) ($claims['nonce'] ?? ''))) ?: bin2hex(random_bytes(12)),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $json, $this->normalizedSecret());
        return $this->b64($json) . '.' . $signature;
    }

    /**
     * Backward-compatible boolean validation. Prefer validateClaims().
     */
    public function validate(string $token, int $entryId, int $revisionId = 0, int $siteId = 0, string $languageCode = ''): bool
    {
        $claims = $this->validateClaims($token);
        if ($claims === null) {
            return false;
        }
        if ((int) $claims['entry_id'] !== $entryId || (int) $claims['revision_id'] !== $revisionId) {
            return false;
        }
        if ($siteId > 0 && (int) $claims['site_id'] !== $siteId) {
            return false;
        }
        if ($languageCode !== '' && (string) $claims['language_code'] !== strtolower($languageCode)) {
            return false;
        }
        return true;
    }

    /** @return array{entry_id:int,revision_id:int,site_id:int,language_code:string,expires_at:int,nonce:string}|null */
    public function validateClaims(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $claims = str_contains($token, '.') ? $this->decodeV2($token) : $this->decodeLegacy($token);
        if ($claims === null) {
            return null;
        }
        if ((int) $claims['expires_at'] < time()) {
            return null;
        }
        return $claims;
    }

    /** @return array{entry_id:int,revision_id:int,site_id:int,language_code:string,expires_at:int,nonce:string}|null */
    private function decodeV2(string $token): ?array
    {
        [$encoded, $signature] = array_pad(explode('.', $token, 2), 2, '');
        if ($encoded === '' || $signature === '') {
            return null;
        }
        $json = $this->unb64($encoded);
        if ($json === null || !hash_equals(hash_hmac('sha256', $json, $this->normalizedSecret()), $signature)) {
            return null;
        }
        $payload = json_decode($json, true);
        if (!is_array($payload) || ($payload['purpose'] ?? '') !== 'content_preview') {
            return null;
        }
        return [
            'entry_id' => (int) ($payload['entry_id'] ?? 0),
            'revision_id' => (int) ($payload['revision_id'] ?? 0),
            'site_id' => (int) ($payload['site_id'] ?? 0),
            'language_code' => strtolower((string) ($payload['language_code'] ?? '')),
            'expires_at' => (int) ($payload['expires_at'] ?? 0),
            'nonce' => (string) ($payload['nonce'] ?? ''),
        ];
    }

    /** @return array{entry_id:int,revision_id:int,site_id:int,language_code:string,expires_at:int,nonce:string}|null */
    private function decodeLegacy(string $token): ?array
    {
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }
        $parts = explode('|', $decoded);
        if (count($parts) !== 4) {
            return null;
        }
        [$entryId, $revisionId, $expiresAt, $signature] = $parts;
        $payload = $entryId . '|' . $revisionId . '|' . $expiresAt;
        if (!hash_equals(hash_hmac('sha256', $payload, $this->normalizedSecret()), $signature)) {
            return null;
        }
        return [
            'entry_id' => (int) $entryId,
            'revision_id' => (int) $revisionId,
            'site_id' => 0,
            'language_code' => '',
            'expires_at' => (int) $expiresAt,
            'nonce' => '',
        ];
    }

    private function normalizedSecret(): string
    {
        $secret = trim($this->secret);
        $isWeakDefault = $secret === '' || $secret === 'change-this-preview-key';
        $env = strtolower((string) (function_exists('env') ? env('APP_ENV', 'production') : ($_ENV['APP_ENV'] ?? 'production')));

        if ($isWeakDefault) {
            return $this->fallbackSecret($env);
        }

        if (strlen($secret) < 32 && in_array($env, ['production', 'prod'], true)) {
            throw new \RuntimeException('PREVIEW_SIGNING_KEY_TOO_SHORT');
        }

        return $secret;
    }

    private function fallbackSecret(string $env): string
    {
        $isProduction = in_array($env, ['production', 'prod'], true);

        if (!$isProduction) {
            return hash('sha256', __DIR__ . '|' . (string) (function_exists('base_path') ? base_path() : getcwd()));
        }

        $basePath = (string) (function_exists('base_path') ? base_path() : getcwd());
        $secretDir = rtrim($basePath, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'security';
        $secretFile = $secretDir . DIRECTORY_SEPARATOR . 'preview_signing.key';

        if (is_file($secretFile)) {
            $stored = trim((string) file_get_contents($secretFile));
            if (strlen($stored) >= 64 && preg_match('/^[a-f0-9]+$/i', $stored) === 1) {
                return $stored;
            }
        }

        if (!is_dir($secretDir) && !@mkdir($secretDir, 0770, true) && !is_dir($secretDir)) {
            throw new \RuntimeException('PREVIEW_SIGNING_KEY_REQUIRED');
        }

        $secret = bin2hex(random_bytes(32));
        if (@file_put_contents($secretFile, $secret . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('PREVIEW_SIGNING_KEY_REQUIRED');
        }
        @chmod($secretFile, 0660);

        return $secret;
    }

    private function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function unb64(string $value): ?string
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }
}
