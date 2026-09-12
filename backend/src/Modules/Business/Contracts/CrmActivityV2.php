<?php

declare(strict_types=1);

namespace App\Modules\Business\Contracts;

use InvalidArgumentException;

/** Non-sensitive, versioned activity contract shared by event subscribers. */
final readonly class CrmActivityV2
{
    public const VERSION = 2;
    public const CONTRACT_VERSION = 'crm.activity.v2';

    /** @param array<string,mixed> $provenance @param array<string,mixed> $metadata */
    public function __construct(
        public int $siteId,
        public string $type,
        public string $occurredAt,
        public string $channel,
        public ?int $channelId,
        public ?string $languageCode,
        public ?int $contactId,
        public ?int $companyId,
        public int $sourceEventId,
        public int $sourceOutboxId,
        public string $sourceType,
        public string $sourceId,
        public string $sourceEventType,
        public string $sourceAggregateType,
        public int $sourceAggregateId,
        public ?string $sourceReference,
        public string $summary,
        public string $status,
        public string $resolutionStrategy,
        public array $provenance = [],
        public array $metadata = [],
        public ?string $retentionUntil = null,
    ) {
        if ($siteId < 1 || $sourceEventId < 1 || $sourceOutboxId < 1 || $sourceAggregateId < 1) {
            throw new InvalidArgumentException('business.sale_activity_identity_invalid');
        }
        if (!in_array($channel, ['web', 'pos', 'admin', 'unknown'], true)
            || !in_array($resolutionStrategy, ['explicit_event', 'event_correlation', 'manual', 'pending'], true)
            || trim($type) === '' || trim($summary) === '' || trim($sourceType) === '' || trim($sourceId) === '') {
            throw new InvalidArgumentException('business.sale_activity_invalid');
        }
        if ($resolutionStrategy === 'pending' && ($contactId !== null || $companyId !== null)) {
            throw new InvalidArgumentException('business.sale_activity_pending_link_invalid');
        }
    }
}
