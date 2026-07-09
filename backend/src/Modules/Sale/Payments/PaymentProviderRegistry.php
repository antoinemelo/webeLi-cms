<?php

declare(strict_types=1);

namespace App\Modules\Sale\Payments;

use App\Modules\Sale\Exceptions\SalePaymentException;

final class PaymentProviderRegistry
{
    /** @var array<string,PaymentProvider> */
    private array $providers = [];

    /** @param list<PaymentProvider>|null $providers */
    public function __construct(?array $providers = null)
    {
        foreach ($providers ?? $this->defaultProviders() as $provider) {
            $this->providers[$provider->key()] = $provider;
        }
    }

    public function get(string $key): PaymentProvider
    {
        $key = $this->normalize($key);
        if (!isset($this->providers[$key])) {
            throw new SalePaymentException('sale.payment_provider_unsupported');
        }
        return $this->providers[$key];
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->providers);
    }

    public function normalize(string $key): string
    {
        $key = strtolower(trim($key));
        return match ($key) {
            '', 'manual' => 'manual_card',
            'card' => 'manual_card',
            'terminal' => 'external_terminal',
            default => $key,
        };
    }

    /** @return list<PaymentProvider> */
    private function defaultProviders(): array
    {
        return [
            new LocalPaymentProvider('cash'),
            new LocalPaymentProvider('manual_card'),
            new LocalPaymentProvider('external_terminal'),
            new LocalPaymentProvider('bank_transfer'),
            new LocalPaymentProvider('test'),
        ];
    }
}
