<?php

declare(strict_types=1);

namespace App\Modules\Business\Services;

use App\Modules\Business\Repositories\BusinessConsentRepository;
use App\Modules\Business\Repositories\BusinessMessagingRepository;
use InvalidArgumentException;

final class BusinessMessagingOutboxService
{
    public function __construct(
        private readonly BusinessConsentRepository $consents,
        private readonly BusinessMessagingRepository $messages,
    ) {}

    public function prepareMessage(int $siteId, int $contactId, string $channel, array $payload, ?int $actorId = null): array
    {
        if (!$this->consents->hasOptIn($contactId, $channel)) {
            throw new InvalidArgumentException('business.messaging_consent_required');
        }
        $primaryChannel = $this->consents->primaryChannel($contactId, $channel);
        if ($primaryChannel === null) {
            throw new InvalidArgumentException('business.messaging_channel_missing');
        }
        $payload['contact_id'] = $contactId;
        $payload['channel'] = $channel;
        $payload['recipient_value'] = $primaryChannel['normalized_value'];
        return $this->messages->createOutbox($siteId, $payload, $actorId);
    }
}
