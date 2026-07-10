<?php

declare(strict_types=1);

namespace App\Modules\Business\Messaging;

final class WhatsAppCloudApiProvider implements MessagingProvider
{
    public function key(): string { return 'whatsapp_cloud'; }
    public function channel(): string { return 'whatsapp'; }

    public function send(MessageEnvelope $message): MessageSendResult
    {
        $errors = $this->validateConfiguration($message->providerConfig);
        if ($errors !== []) {
            return MessageSendResult::failed(implode('; ', $errors), ['provider' => 'whatsapp_cloud']);
        }
        if (!preg_match('/^\+[0-9]{6,20}$/', $message->recipientValue)) {
            return MessageSendResult::failed('business.messaging.whatsapp_recipient_must_be_e164', ['provider' => 'whatsapp_cloud']);
        }
        return MessageSendResult::failed('business.messaging.whatsapp_cloud_not_implemented', ['provider' => 'whatsapp_cloud']);
    }

    public function validateConfiguration(array $config): array
    {
        $enabled = filter_var($config['enabled'] ?? getenv('BUSINESS_WHATSAPP_ENABLED') ?: false, FILTER_VALIDATE_BOOL);
        if (!$enabled) {
            return ['business.messaging.whatsapp_disabled'];
        }
        foreach (['phone_number_id', 'access_token'] as $key) {
            if (trim((string) ($config[$key] ?? '')) === '') {
                return ['business.messaging.whatsapp_' . $key . '_missing'];
            }
        }
        return [];
    }
}
