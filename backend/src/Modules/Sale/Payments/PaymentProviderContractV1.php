<?php

declare(strict_types=1);

namespace App\Modules\Sale\Payments;

use App\Modules\Sale\Exceptions\SalePaymentException;

/**
 * Unique canonical boundary between Sale and a payment provider.
 *
 * The wrapped PaymentProvider methods are kept as a compatibility port for
 * existing extensions; application services only use this vocabulary.
 */
final class PaymentProviderContractV1
{
    public const VERSION = 'sale.payment_provider.v1';

    public function __construct(private readonly PaymentProvider $provider) {}

    public function key(): string { return $this->provider->key(); }
    public function version(): string { return self::VERSION; }

    /** @return array<string,bool> */
    public function capabilities(): array
    {
        return [
            'create_payment_session' => $this->provider->supports('create_intent'),
            'update_payment_session' => $this->provider instanceof OnlinePaymentProvider && $this->provider->supports('read_state'),
            'authorize' => $this->provider->supports('record_payment'),
            'capture' => $this->provider->supports('capture'),
            'cancel' => $this->provider->supports('void'),
            'refund' => $this->provider->supports('refund'),
            'webhook' => $this->provider instanceof OnlinePaymentProvider && $this->provider->supports('webhook'),
            'reconcile' => $this->provider instanceof OnlinePaymentProvider && $this->provider->supports('read_state'),
            'online' => $this->provider instanceof OnlinePaymentProvider,
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function createPaymentSession(array $payload): array
    {
        $this->requireCapability('create_payment_session');
        return $this->provider->createIntent($payload);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function updatePaymentSession(array $payload): array
    {
        $provider = $this->onlineProvider('update_payment_session');
        return $provider->readState($payload);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function authorize(array $payload): array
    {
        $this->requireCapability('authorize');
        return $this->provider->recordPayment($payload);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function capture(array $payload): array
    {
        $this->requireCapability('capture');
        return $this->provider->capture($payload);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function cancel(array $payload): array
    {
        $this->requireCapability('cancel');
        return $this->provider->void($payload);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function refund(array $payload): array
    {
        $this->requireCapability('refund');
        return $this->provider->refund($payload);
    }

    /** @param array<string,string> $headers */
    public function verifyWebhookSignature(string $rawBody, array $headers): void
    {
        $this->onlineProvider('webhook')->verifyWebhookSignature($rawBody, $headers);
    }

    /** @param array<string,string> $headers @return array<string,mixed> */
    public function parseWebhook(string $rawBody, array $headers): array
    {
        return $this->onlineProvider('webhook')->parseWebhook($rawBody, $headers);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function reconcile(array $payload): array
    {
        $provider = $this->onlineProvider('reconcile');
        return $provider->readState($payload);
    }

    private function onlineProvider(string $capability): OnlinePaymentProvider
    {
        $this->requireCapability($capability);
        if (!$this->provider instanceof OnlinePaymentProvider || $this->provider->contractVersion() !== self::VERSION) {
            throw new SalePaymentException('sale.payment_provider_contract_unsupported');
        }
        return $this->provider;
    }

    private function requireCapability(string $capability): void
    {
        if (($this->capabilities()[$capability] ?? false) !== true) {
            throw new SalePaymentException('sale.payment_provider_operation_unsupported');
        }
    }
}
