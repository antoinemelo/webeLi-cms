<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Modules\Sale\Exceptions\SaleValidationException;
use App\Modules\Sale\Payments\PaymentProviderRegistry;

final class SalePaymentMethodService
{
    public function __construct(
        private readonly SaleDatabaseConnection $connection,
        private readonly PaymentProviderRegistry $providers,
    ) {}

    /** @return list<array<string,mixed>> */
    public function availableMethods(int $siteId, int $channelId, string $language, string $currency, int $amountMinor): array
    {
        $rows = $this->db()->all(
            "SELECT * FROM sale_payment_methods
             WHERE site_id=? AND (channel_id=? OR channel_id IS NULL) AND status='active' AND archived_at IS NULL AND is_public=1
               AND (currency IS NULL OR currency=?)
               AND (min_amount_minor IS NULL OR min_amount_minor<=?)
               AND (max_amount_minor IS NULL OR max_amount_minor>=?)
             ORDER BY sort_order,code",
            [$siteId, $channelId, strtoupper($currency), $amountMinor, $amountMinor]
        );
        $methods = [];
        foreach ($rows as $row) {
            try {
                $contract = $this->providers->contract((string) ($row['provider_key'] ?? ''));
            } catch (\Throwable) {
                continue;
            }
            $methods[] = $this->payload($row, $language, $contract->capabilities());
        }
        return $methods;
    }

    /** @return array<string,mixed> */
    public function requireAvailable(int $siteId, int $channelId, string $language, string $currency, int $amountMinor, string $code): array
    {
        $code = strtolower(trim($code));
        foreach ($this->availableMethods($siteId, $channelId, $language, $currency, $amountMinor) as $method) {
            if ((string) $method['code'] === $code) {
                return $method;
            }
        }
        throw new SaleValidationException('sale.checkout.payment_method_unavailable');
    }

    /** @param array<string,mixed> $row @param array<string,bool> $capabilities @return array<string,mixed> */
    private function payload(array $row, string $language, array $capabilities): array
    {
        $config = json_decode((string) ($row['config_json'] ?? '{}'), true);
        $config = is_array($config) ? $config : [];
        $english = str_starts_with(strtolower($language), 'en');
        $label = trim((string) ($row[$english ? 'label_en' : 'label_fr'] ?? '')) ?: (string) $row['name'];
        $description = trim((string) ($row[$english ? 'description_en' : 'description_fr'] ?? ''));
        return [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'label' => $label,
            'description' => $description,
            'mode' => (string) ($config['public_mode'] ?? ($capabilities['online'] ? 'redirect' : 'offline')),
            'next_action' => (string) ($config['next_action'] ?? ($capabilities['online'] ? 'redirect' : 'await_confirmation')),
            'recoverable' => ($config['recoverable'] ?? true) === true,
            'capabilities' => [
                'online' => (bool) ($capabilities['online'] ?? false),
                'authorize' => (bool) ($capabilities['authorize'] ?? false),
                'capture' => (bool) ($capabilities['capture'] ?? false),
                'refund' => (bool) ($capabilities['refund'] ?? false),
            ],
            'provider_key' => (string) $row['provider_key'],
            'contract_version' => (string) $row['contract_version'],
        ];
    }

    private function db(): \App\Core\Database
    {
        return $this->connection->database() ?? throw new SaleValidationException('sale.database_unavailable');
    }
}
