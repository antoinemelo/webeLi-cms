<?php

declare(strict_types=1);

namespace App\Modules\Business\Messaging;

use App\Core\Logger;

final class LogOnlyMessagingProvider implements MessagingProvider
{
    public function __construct(private readonly string $channel = 'email', private readonly ?Logger $logger = null) {}

    public function key(): string { return 'log_only'; }
    public function channel(): string { return $this->channel; }

    public function send(MessageEnvelope $message): MessageSendResult
    {
        $this->logger?->info('business.messaging.log_only', [
            'outbox_id' => $message->outboxId,
            'site_id' => $message->siteId,
            'channel' => $message->channel,
            'recipient_hash' => hash('sha256', $message->recipientValue),
            'subject' => $message->subject,
        ]);
        return MessageSendResult::sent('log-' . $message->outboxId, ['provider' => 'log_only']);
    }

    public function validateConfiguration(array $config): array
    {
        return [];
    }
}
