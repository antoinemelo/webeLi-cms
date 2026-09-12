<?php

declare(strict_types=1);

namespace App\Modules\Sale\Payments;

interface StripeGateway
{
    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function createCheckoutSession(array $params, string $idempotencyKey): array;
    /** @return array<string,mixed> */
    public function retrieveCheckoutSession(string $id): array;
    /** @return array<string,mixed> */
    public function expireCheckoutSession(string $id): array;
    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function capturePaymentIntent(string $id, array $params, string $idempotencyKey): array;
    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function createRefund(array $params, string $idempotencyKey): array;
    /** @return array<string,mixed> */
    public function verifyWebhook(string $rawBody, string $signature, string $secret, int $tolerance): array;
}
