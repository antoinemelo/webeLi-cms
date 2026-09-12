<?php

declare(strict_types=1);

namespace App\Modules\Sale\Payments;

use Stripe\StripeClient;
use Stripe\Webhook;

final class OfficialStripeGateway implements StripeGateway
{
    private readonly StripeClient $client;

    public function __construct(string $secretKey)
    {
        $this->client = new StripeClient($secretKey);
    }

    public function createCheckoutSession(array $params, string $idempotencyKey): array
    {
        return $this->client->checkout->sessions->create($params, ['idempotency_key' => $idempotencyKey])->toArray();
    }

    public function retrieveCheckoutSession(string $id): array
    {
        return $this->client->checkout->sessions->retrieve($id, [])->toArray();
    }

    public function expireCheckoutSession(string $id): array
    {
        return $this->client->checkout->sessions->expire($id, [])->toArray();
    }

    public function capturePaymentIntent(string $id, array $params, string $idempotencyKey): array
    {
        return $this->client->paymentIntents->capture($id, $params, ['idempotency_key' => $idempotencyKey])->toArray();
    }

    public function createRefund(array $params, string $idempotencyKey): array
    {
        return $this->client->refunds->create($params, ['idempotency_key' => $idempotencyKey])->toArray();
    }

    public function verifyWebhook(string $rawBody, string $signature, string $secret, int $tolerance): array
    {
        return Webhook::constructEvent($rawBody, $signature, $secret, $tolerance)->toArray();
    }
}
