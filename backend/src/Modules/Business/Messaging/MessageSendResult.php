<?php

declare(strict_types=1);

namespace App\Modules\Business\Messaging;

final class MessageSendResult
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        public readonly bool $success,
        public readonly string $status,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $error = null,
        public readonly array $payload = [],
    ) {}

    /** @param array<string,mixed> $payload */
    public static function sent(?string $providerMessageId = null, array $payload = []): self
    {
        return new self(true, 'sent', $providerMessageId, null, $payload);
    }

    /** @param array<string,mixed> $payload */
    public static function failed(string $error, array $payload = []): self
    {
        return new self(false, 'failed', null, $error, $payload);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'status' => $this->status,
            'provider_message_id' => $this->providerMessageId,
            'error' => $this->error,
            'payload' => $this->payload,
        ];
    }
}
