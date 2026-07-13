<?php

declare(strict_types=1);

namespace App\Modules\Sale\Payments;

use App\Core\Database;
use App\Modules\Sale\Exceptions\SalePaymentException;

final class PaymentProviderRegistry
{
    /** @var array<string,PaymentProvider> */
    private array $providers = [];
    /** @var array<string,mixed> */
    private array $paymentConfig = [];

    /** @param list<PaymentProvider>|null $providers */
    public function __construct(?array $providers = null, ?Database $database = null, ?string $sandboxSecret = null, ?string $environment = null, array $paymentConfig = [])
    {
        $this->paymentConfig = $paymentConfig;
        foreach ($providers ?? $this->defaultProviders($database, $sandboxSecret, $environment, $paymentConfig) as $provider) {
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

    /** @return array<string,mixed> */
    public function realProviderStatus(): array
    {
        $stripe=is_array($this->paymentConfig['stripe']??null)?$this->paymentConfig['stripe']:[];
        $selected=($this->paymentConfig['real_provider']??'')==='stripe_checkout';
        return ['provider'=>'stripe_checkout','selected'=>$selected,'environment'=>(string)($stripe['environment']??'test'),
            'connected'=>in_array('stripe_checkout',$this->keys(),true),'sdk_available'=>class_exists(\Stripe\StripeClient::class),
            'secret_configured'=>trim((string)($stripe['secret_key']??''))!=='','webhook_secret_configured'=>($stripe['webhook_secrets']??[])!==[],
            'last_verified_at'=>null,'webhook_url'=>rtrim((string)($this->paymentConfig['public_base_url']??''),'/').'/api/v1/sale/payments/webhooks/stripe_checkout'];
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
    private function defaultProviders(?Database $database, ?string $sandboxSecret, ?string $environment, array $paymentConfig): array
    {
        $providers = [
            new LocalPaymentProvider('cash'),
            new ManualPaymentProvider(),
            new LocalPaymentProvider('external_terminal'),
            new BankTransferPaymentProvider(),
        ];
        $environment = strtolower(trim((string) ($environment ?? (function_exists('env') ? env('APP_ENV', 'production') : 'production'))));
        if ($database !== null && !in_array($environment, ['production','prod'], true)) {
            $secret = trim((string) ($sandboxSecret ?? getenv('SALE_SANDBOX_WEBHOOK_SECRET') ?: 'sandbox-development-secret-change-me'));
            $providers[] = new SandboxPaymentProvider($database, $secret);
            $providers[] = new DeterministicTestPaymentProvider($database, hash('sha256', $secret . '|deterministic-test'));
        }
        $stripe = is_array($paymentConfig['stripe'] ?? null) ? $paymentConfig['stripe'] : [];
        if (($paymentConfig['real_provider'] ?? '') === 'stripe_checkout' && ($stripe['enabled'] ?? false) === true
            && trim((string)($stripe['secret_key'] ?? '')) !== '' && ($stripe['webhook_secrets'] ?? []) !== []
            && trim((string)($paymentConfig['public_base_url']??'')) !== ''
            && class_exists(\Stripe\StripeClient::class)) {
            $providers[] = new StripeCheckoutPaymentProvider(
                new OfficialStripeGateway((string)$stripe['secret_key']), array_values(array_map('strval',$stripe['webhook_secrets'])),
                (string)($paymentConfig['public_base_url']??''), (string)($stripe['environment']??'test'),
                max(1,(int)($stripe['signature_tolerance']??300)), (string)($stripe['api_version']??'')
            );
        }
        return $providers;
    }
}
