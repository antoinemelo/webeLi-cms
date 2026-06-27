<?php

declare(strict_types=1);

namespace App\Modules\Business\Messaging;

use App\Mail\MailerInterface;

final class EmailMessagingProvider implements MessagingProvider
{
    public function __construct(private readonly MailerInterface $mailer) {}

    public function key(): string { return 'email'; }
    public function channel(): string { return 'email'; }

    public function send(MessageEnvelope $message): MessageSendResult
    {
        $errors = $this->validateConfiguration($message->providerConfig);
        if ($errors !== []) {
            return MessageSendResult::failed(implode('; ', $errors), ['provider' => 'email']);
        }
        if (!filter_var($message->recipientValue, FILTER_VALIDATE_EMAIL)) {
            return MessageSendResult::failed('business.messaging.email_recipient_invalid', ['provider' => 'email']);
        }
        $sent = $this->mailer->send($message->recipientValue, (string) ($message->subject ?: 'Message'), $message->bodyText, $message->bodyHtml);
        return $sent
            ? MessageSendResult::sent('mail-' . $message->outboxId, ['provider' => 'email'])
            : MessageSendResult::failed('business.messaging.email_send_failed', ['provider' => 'email']);
    }

    public function validateConfiguration(array $config): array
    {
        return [];
    }
}
