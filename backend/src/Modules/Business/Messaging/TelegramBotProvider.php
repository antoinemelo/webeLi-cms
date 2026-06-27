<?php

declare(strict_types=1);

namespace App\Modules\Business\Messaging;

final class TelegramBotProvider implements MessagingProvider
{
    public function key(): string { return 'telegram_bot'; }
    public function channel(): string { return 'telegram'; }

    public function send(MessageEnvelope $message): MessageSendResult
    {
        $errors = $this->validateConfiguration($message->providerConfig);
        if ($errors !== []) {
            return MessageSendResult::failed(implode('; ', $errors), ['provider' => 'telegram_bot']);
        }
        if (!preg_match('/^-?[0-9]{5,32}$/', $message->recipientValue) && !preg_match('/^[a-zA-Z0-9_]{5,64}$/', $message->recipientValue)) {
            return MessageSendResult::failed('business.messaging.telegram_chat_id_required', ['provider' => 'telegram_bot']);
        }
        return MessageSendResult::failed('business.messaging.telegram_bot_not_implemented', ['provider' => 'telegram_bot']);
    }

    public function validateConfiguration(array $config): array
    {
        $enabled = filter_var($config['enabled'] ?? getenv('BUSINESS_TELEGRAM_ENABLED') ?: false, FILTER_VALIDATE_BOOL);
        if (!$enabled) {
            return ['business.messaging.telegram_disabled'];
        }
        if (trim((string) ($config['bot_token'] ?? '')) === '') {
            return ['business.messaging.telegram_bot_token_missing'];
        }
        return [];
    }
}
