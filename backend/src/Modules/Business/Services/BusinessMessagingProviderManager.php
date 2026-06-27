<?php

declare(strict_types=1);

namespace App\Modules\Business\Services;

use App\Core\Logger;
use App\Mail\MailerInterface;
use App\Modules\Business\Messaging\EmailMessagingProvider;
use App\Modules\Business\Messaging\LogOnlyMessagingProvider;
use App\Modules\Business\Messaging\MessageEnvelope;
use App\Modules\Business\Messaging\MessageSendResult;
use App\Modules\Business\Messaging\MessagingProvider;
use App\Modules\Business\Messaging\TelegramBotProvider;
use App\Modules\Business\Messaging\WhatsAppCloudApiProvider;
use App\Modules\Business\Repositories\BusinessMessagingRepository;
use InvalidArgumentException;
use Throwable;

final class BusinessMessagingProviderManager
{
    /** @var array<string,MessagingProvider> */
    private array $providers;

    public function __construct(
        private readonly BusinessMessagingRepository $messages,
        private readonly MailerInterface $mailer,
        private readonly Logger $logger,
    ) {
        $this->providers = [
            'log_only:email' => new LogOnlyMessagingProvider('email', $logger),
            'log_only:whatsapp' => new LogOnlyMessagingProvider('whatsapp', $logger),
            'log_only:telegram' => new LogOnlyMessagingProvider('telegram', $logger),
            'email' => new EmailMessagingProvider($mailer),
            'whatsapp_cloud' => new WhatsAppCloudApiProvider(),
            'telegram_bot' => new TelegramBotProvider(),
        ];
    }

    /** @return array<string,mixed> */
    public function providers(int $siteId): array
    {
        $configured = $this->messages->providers($siteId);
        return [
            'runtime' => [
                $this->runtimeProvider('log_only', 'email', true, []),
                $this->runtimeProvider('log_only', 'whatsapp', true, []),
                $this->runtimeProvider('log_only', 'telegram', true, []),
                $this->runtimeProvider('email', 'email', true, []),
                $this->runtimeProvider('whatsapp_cloud', 'whatsapp', false, (new WhatsAppCloudApiProvider())->validateConfiguration($this->whatsappEnvConfig())),
                $this->runtimeProvider('telegram_bot', 'telegram', false, (new TelegramBotProvider())->validateConfiguration($this->telegramEnvConfig())),
            ],
            'configured' => $this->safeConfiguredProviders($configured),
        ];
    }

    /** @return array<string,mixed> */
    public function sendOutboxMessage(int $siteId, int $outboxId, ?string $providerKey = null): array
    {
        $outbox = $this->messages->findOutbox($siteId, $outboxId);
        if ($outbox === null) {
            throw new InvalidArgumentException('business.message_not_found');
        }
        if ((int) ($outbox['attempts'] ?? 0) >= (int) ($outbox['max_attempts'] ?? 3)) {
            $this->messages->addDeliveryEvent($outboxId, 'skipped', ['reason' => 'max_attempts_reached']);
            $updated = $this->messages->updateOutboxStatus($siteId, $outboxId, 'skipped', null, 'business.messaging.max_attempts_reached');
            return ['message' => $updated, 'result' => MessageSendResult::failed('business.messaging.max_attempts_reached')->toArray()];
        }

        [$provider, $providerConfig] = $this->resolveProvider($siteId, (string) $outbox['channel'], $providerKey);
        $this->messages->updateOutboxStatus($siteId, $outboxId, 'queued');
        $this->messages->addDeliveryEvent($outboxId, 'queued', ['provider' => $provider->key(), 'channel' => $provider->channel()]);

        try {
            $message = MessageEnvelope::fromOutbox($outbox, $providerConfig);
            $result = $provider->send($message);
        } catch (Throwable $e) {
            $result = MessageSendResult::failed('business.messaging.provider_exception', ['exception' => $e::class]);
            $this->logger->error('business.messaging.provider_exception', [
                'outbox_id' => $outboxId,
                'site_id' => $siteId,
                'provider' => $provider->key(),
                'exception' => $e::class,
            ]);
        }

        $status = $result->success ? 'sent' : 'failed';
        $updated = $this->messages->updateOutboxStatus($siteId, $outboxId, $status, $result->providerMessageId, $result->error);
        $this->messages->addDeliveryEvent($outboxId, $result->success ? 'sent' : 'failed', $result->payload, $result->providerMessageId, $result->error);

        return ['message' => $updated, 'result' => $result->toArray()];
    }

    /** @return array{0:MessagingProvider,1:array<string,mixed>} */
    private function resolveProvider(int $siteId, string $channel, ?string $providerKey): array
    {
        $configured = $providerKey !== null && trim($providerKey) !== ''
            ? $this->messages->providerByKey($siteId, $providerKey)
            : $this->messages->defaultProvider($siteId, $channel);
        if ($configured !== null) {
            $provider = $this->providerForType((string) ($configured['provider_type'] ?? 'null'), $channel);
            return [$provider, (array) ($configured['config'] ?? [])];
        }
        return [$this->providers['log_only:' . $channel] ?? new LogOnlyMessagingProvider($channel, $this->logger), []];
    }

    private function providerForType(string $type, string $channel): MessagingProvider
    {
        return match ($type) {
            'smtp' => $this->providers['email'],
            'whatsapp_cloud' => $this->providers['whatsapp_cloud'],
            'telegram_bot' => $this->providers['telegram_bot'],
            default => $this->providers['log_only:' . $channel] ?? new LogOnlyMessagingProvider($channel, $this->logger),
        };
    }

    /** @param list<array<string,mixed>> $providers @return list<array<string,mixed>> */
    private function safeConfiguredProviders(array $providers): array
    {
        return array_map(static function (array $provider): array {
            unset($provider['secret_ref']);
            if (isset($provider['config']) && is_array($provider['config'])) {
                foreach (array_keys($provider['config']) as $key) {
                    if (str_contains((string) $key, 'token') || str_contains((string) $key, 'secret')) {
                        $provider['config'][$key] = '***';
                    }
                }
            }
            return $provider;
        }, $providers);
    }

    /** @param list<string> $errors @return array<string,mixed> */
    private function runtimeProvider(string $key, string $channel, bool $enabled, array $errors): array
    {
        return ['key' => $key, 'channel' => $channel, 'enabled' => $enabled && $errors === [], 'errors' => $errors];
    }

    /** @return array<string,mixed> */
    private function whatsappEnvConfig(): array
    {
        return [
            'enabled' => getenv('BUSINESS_WHATSAPP_ENABLED') ?: 'false',
            'phone_number_id' => getenv('BUSINESS_WHATSAPP_PHONE_NUMBER_ID') ?: '',
            'access_token' => getenv('BUSINESS_WHATSAPP_ACCESS_TOKEN') ?: '',
            'api_version' => getenv('BUSINESS_WHATSAPP_API_VERSION') ?: '',
        ];
    }

    /** @return array<string,mixed> */
    private function telegramEnvConfig(): array
    {
        return [
            'enabled' => getenv('BUSINESS_TELEGRAM_ENABLED') ?: 'false',
            'bot_token' => getenv('BUSINESS_TELEGRAM_BOT_TOKEN') ?: '',
        ];
    }
}
