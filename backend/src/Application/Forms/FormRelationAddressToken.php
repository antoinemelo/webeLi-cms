<?php

declare(strict_types=1);

namespace App\Application\Forms;

final class FormRelationAddressToken
{
    public function __construct(private readonly string $secret) {}

    public function issue(int $siteId, string $formKey, string $type, int $id, int $ttlSeconds = 604800): string
    {
        if ($siteId < 1 || $id < 1 || !in_array($type, ['contact', 'company'], true) || trim($formKey) === '') {
            throw new \InvalidArgumentException('forms.relation_address_invalid');
        }
        $payload = json_encode([
            'v' => 1, 'site_id' => $siteId, 'form_key' => trim($formKey), 'type' => $type,
            'id' => $id, 'expires_at' => time() + max(300, min(2592000, $ttlSeconds)),
        ], JSON_UNESCAPED_SLASHES) ?: '{}';
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        return $encoded . '.' . hash_hmac('sha256', $encoded, $this->key());
    }

    /** @return array{type:string,id:int}|null */
    public function verify(string $token, int $siteId, string $formKey): ?array
    {
        [$encoded, $signature] = array_pad(explode('.', trim($token), 2), 2, '');
        if ($encoded === '' || $signature === '' || !hash_equals(hash_hmac('sha256', $encoded, $this->key()), $signature)) return null;
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        $payload = is_string($decoded) ? json_decode($decoded, true) : null;
        if (!is_array($payload) || (int) ($payload['v'] ?? 0) !== 1 || (int) ($payload['site_id'] ?? 0) !== $siteId
            || (string) ($payload['form_key'] ?? '') !== $formKey || (int) ($payload['expires_at'] ?? 0) < time()
            || !in_array($payload['type'] ?? '', ['contact', 'company'], true) || (int) ($payload['id'] ?? 0) < 1) return null;
        return ['type' => (string) $payload['type'], 'id' => (int) $payload['id']];
    }

    private function key(): string
    {
        return trim($this->secret) !== '' ? $this->secret : 'change-this-form-relation-key';
    }
}
