<?php

declare(strict_types=1);

namespace App\Modules\Sale\Payments;

/** Contrat provider asynchrone versionné, sans rupture du port paiement local. */
interface OnlinePaymentProvider extends PaymentProvider
{
    public function contractVersion(): string;

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function readState(array $payload): array;

    /** @param array<string,string> $headers */
    public function verifyWebhookSignature(string $rawBody, array $headers): void;

    /** @param array<string,string> $headers @return array<string,mixed> */
    public function parseWebhook(string $rawBody, array $headers): array;
}
