<?php

declare(strict_types=1);

namespace App\Modules\Business\Services;

use App\Modules\Business\Repositories\BusinessConsentRepository;
use App\Modules\Business\Repositories\BusinessMessagingRepository;
use InvalidArgumentException;

final class BusinessMessagingOutboxService
{
    private const CHANNELS = ['email', 'whatsapp', 'telegram'];

    public function __construct(
        private readonly BusinessConsentRepository $consents,
        private readonly BusinessMessagingRepository $messages,
    ) {}

    /** @return array<string,mixed> */
    public function previewMessage(int $contactId, string $channel): array
    {
        $channel = $this->channel($channel);
        $primaryChannel = $this->consents->primaryChannel($contactId, $channel);
        $consent = $this->consents->consentForContact($contactId, $channel);
        $consentStatus = (string) ($consent['consent_status'] ?? 'unknown');

        return [
            'contact_id' => $contactId,
            'channel' => $channel,
            'recipient_value' => $primaryChannel['normalized_value'] ?? null,
            'has_channel' => $primaryChannel !== null,
            'consent_status' => $consentStatus,
            'has_consent' => $consentStatus === 'opt_in',
            'can_send' => $primaryChannel !== null && $consentStatus === 'opt_in',
            'reason' => $primaryChannel === null ? 'business.messaging_channel_missing' : ($consentStatus === 'opt_in' ? null : 'business.messaging_consent_required'),
        ];
    }

    public function prepareMessage(int $siteId, int $contactId, string $channel, array $payload, ?int $actorId = null): array
    {
        $channel = $this->channel($channel);
        $primaryChannel = $this->consents->primaryChannel($contactId, $channel);
        if ($primaryChannel === null) {
            throw new InvalidArgumentException('business.messaging_channel_missing');
        }
        if (!$this->consents->hasOptIn($contactId, $channel)) {
            throw new InvalidArgumentException('business.messaging_consent_required');
        }
        $payload['contact_id'] = $contactId;
        $payload['channel'] = $channel;
        $payload['recipient_value'] = $primaryChannel['normalized_value'];
        return $this->messages->createOutbox($siteId, $payload, $actorId);
    }

    private function channel(string $value): string
    {
        $channel = trim($value);
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException('business.channel_invalid');
        }
        return $channel;
    }
}
