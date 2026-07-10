<?php

declare(strict_types=1);

namespace App\Modules\Business\Services;

use App\Modules\Business\Repositories\BusinessConsentRepository;
use InvalidArgumentException;

final class BusinessConsentService
{
    public function __construct(private readonly BusinessConsentRepository $consents) {}

    public function setChannel(int $contactId, string $channel, string $value, bool $isPrimary = true, bool $isVerified = false, ?int $actorId = null): array
    {
        [$channel, $value, $normalized] = $this->normalizeChannel($channel, $value);
        return $this->consents->upsertChannel($contactId, $channel, $value, $normalized, $isPrimary, $isVerified, $actorId);
    }

    public function setConsent(int $contactId, string $channel, string $status, string $source = 'manual', ?string $evidence = null, ?int $actorId = null): array
    {
        return $this->consents->upsertConsent($contactId, $channel, $status, $source, $evidence, $actorId);
    }

    public function hasConsent(int $contactId, string $channel): bool
    {
        return $this->consents->hasOptIn($contactId, $channel);
    }

    /** @return array{0:string,1:string,2:string} */
    public function normalizeChannel(string $channel, string $value): array
    {
        $channel = strtolower(trim($channel));
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('business.channel_value_required');
        }
        if ($channel === 'email') {
            $normalized = strtolower($value);
            if (!filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('business.email_invalid');
            }
            return [$channel, $normalized, $normalized];
        }
        if ($channel === 'whatsapp') {
            $normalized = preg_replace('/[^0-9+]/', '', $value) ?? '';
            if (!preg_match('/^\+?[0-9]{6,20}$/', $normalized)) {
                throw new InvalidArgumentException('business.whatsapp_invalid');
            }
            return [$channel, $value, $normalized];
        }
        if ($channel === 'telegram') {
            $normalized = strtolower(ltrim($value, '@'));
            if (!preg_match('/^[a-z0-9_]{5,64}$/', $normalized)) {
                throw new InvalidArgumentException('business.telegram_invalid');
            }
            return [$channel, $value, $normalized];
        }
        throw new InvalidArgumentException('business.channel_invalid');
    }
}
