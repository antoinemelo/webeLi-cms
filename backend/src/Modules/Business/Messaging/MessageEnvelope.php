<?php

declare(strict_types=1);

namespace App\Modules\Business\Messaging;

final class MessageEnvelope
{
    /** @param array<string,mixed> $payload @param array<string,mixed> $providerConfig */
    public function __construct(
        public readonly int $outboxId,
        public readonly int $siteId,
        public readonly string $channel,
        public readonly string $recipientValue,
        public readonly ?string $subject,
        public readonly string $bodyText,
        public readonly ?string $bodyHtml,
        public readonly array $payload = [],
        public readonly array $providerConfig = [],
    ) {}

    /** @param array<string,mixed> $row @param array<string,mixed> $providerConfig */
    public static function fromOutbox(array $row, array $providerConfig = []): self
    {
        $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true);
        return new self(
            (int) ($row['id'] ?? 0),
            (int) ($row['site_id'] ?? 0),
            (string) ($row['channel'] ?? ''),
            (string) ($row['recipient_value'] ?? ''),
            isset($row['subject']) ? (string) $row['subject'] : null,
            (string) ($row['body_text'] ?? ''),
            isset($row['body_html']) ? (string) $row['body_html'] : null,
            is_array($payload) ? $payload : [],
            $providerConfig,
        );
    }
}
