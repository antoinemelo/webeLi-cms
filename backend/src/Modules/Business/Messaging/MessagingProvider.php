<?php

declare(strict_types=1);

namespace App\Modules\Business\Messaging;

interface MessagingProvider
{
    public function key(): string;
    public function channel(): string;
    public function send(MessageEnvelope $message): MessageSendResult;

    /** @param array<string,mixed> $config @return list<string> */
    public function validateConfiguration(array $config): array;
}
