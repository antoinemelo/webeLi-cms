<?php

declare(strict_types=1);

namespace App\Modules\Business\Contracts;

use InvalidArgumentException;

final readonly class CrmSaleActivityV1
{
    public const VERSION = 1;

    /** @param array<string,mixed> $metadata */
    public function __construct(
        public int $siteId,
        public string $type,
        public string $occurredAt,
        public string $channel,
        public ?int $contactId,
        public ?int $companyId,
        public int $sourceEventId,
        public int $sourceOutboxId,
        public string $sourceEventType,
        public string $sourceAggregateType,
        public int $sourceAggregateId,
        public ?string $sourceReference,
        public string $summary,
        public string $status,
        public string $resolutionStrategy,
        public array $metadata = [],
    ) {
        if ($siteId < 1 || $sourceEventId < 1 || $sourceOutboxId < 1 || $sourceAggregateId < 1) {
            throw new InvalidArgumentException('business.sale_activity_identity_invalid');
        }
        if (!in_array($channel, ['web', 'pos', 'admin', 'unknown'], true)
            || !in_array($resolutionStrategy, ['explicit_order', 'iam_account_link', 'manual', 'anonymous'], true)
            || trim($type) === '' || trim($summary) === '') {
            throw new InvalidArgumentException('business.sale_activity_invalid');
        }
        if ($resolutionStrategy === 'anonymous' && ($contactId !== null || $companyId !== null)) {
            throw new InvalidArgumentException('business.sale_activity_anonymous_link_invalid');
        }
    }
}
