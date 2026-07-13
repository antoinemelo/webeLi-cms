<?php

declare(strict_types=1);

namespace App\Modules\Sale\Payments;

use App\Core\Database;
use App\Modules\Sale\Exceptions\SalePaymentException;

final class PaymentProviderRegistry
{
    /** @var array<string,PaymentProvider> */
    private array $providers = [];

    /** @param list<PaymentProvider>|null $providers */
    public function __construct(?array $providers = null, ?Database $database = null, ?string $sandboxSecret = null)
    {
        foreach ($providers ?? $this->defaultProviders($database, $sandboxSecret) as $provider) {
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

    public function contract(string $key): PaymentProviderContractV1
    {
        return new PaymentProviderContractV1($this->get($key));
    }

    /** @return list<array{key:string,contract_version:string,capabilities:array<string,bool>}> */
    public function descriptors(): array
    {
        return array_map(function (string $key): array {
            $contract = $this->contract($key);
            return ['key' => $key, 'contract_version' => $contract->version(), 'capabilities' => $contract->capabilities()];
        }, $this->keys());
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
    private function defaultProviders(?Database $database, ?string $sandboxSecret): array
    {
        $providers = [
            new LocalPaymentProvider('cash'),
            new LocalPaymentProvider('manual_card'),
            new LocalPaymentProvider('external_terminal'),
            new LocalPaymentProvider('bank_transfer'),
            new LocalPaymentProvider('test'),
        ];
        if ($database !== null) {
            $secret = trim((string) ($sandboxSecret ?? getenv('SALE_SANDBOX_WEBHOOK_SECRET') ?: 'sandbox-development-secret-change-me'));
            $providers[] = new SandboxPaymentProvider($database, $secret);
        }
        return $providers;
    }
}
