<?php

declare(strict_types=1);

namespace App\Modules\Sale\Payments;

use App\Modules\Sale\Exceptions\SalePaymentException;

/** Client serveur minimal pour la Merchant API officielle Revolut. */
final class OfficialRevolutGateway implements RevolutGateway
{
    public function __construct(
        private readonly string $secretKey,
        private readonly string $environment = 'sandbox',
        private readonly string $apiVersion = '2026-04-20',
        private readonly int $timeoutSeconds = 15,
    ) {
        if ($secretKey === '' || preg_match('/[\r\n]/', $secretKey) === 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $apiVersion) !== 1) {
            throw new SalePaymentException('sale.payment_provider_configuration_invalid');
        }
    }

    public function createOrder(array $params, string $idempotencyKey): array
    {
        return $this->request('POST', '/api/orders', $params, $idempotencyKey);
    }

    public function updateOrder(string $id, array $params): array
    {
        return $this->request('PATCH', '/api/orders/' . rawurlencode($this->id($id)), $params);
    }

    public function retrieveOrder(string $id): array
    {
        return $this->request('GET', '/api/orders/' . rawurlencode($this->id($id)));
    }

    public function cancelOrder(string $id): array
    {
        return $this->request('POST', '/api/orders/' . rawurlencode($this->id($id)) . '/cancel');
    }

    /** @param array<string,mixed>|null $body @return array<string,mixed> */
    private function request(string $method, string $path, ?array $body = null, string $idempotencyKey = ''): array
    {
        if (!function_exists('curl_init')) {
            throw new SalePaymentException('sale.payment_provider_transport_unavailable');
        }
        $base = $this->environment === 'production'
            ? 'https://merchant.revolut.com'
            : 'https://sandbox-merchant.revolut.com';
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->secretKey,
            'Content-Type: application/json',
            'Revolut-Api-Version: ' . $this->apiVersion,
            'User-Agent: DEC-CMS-Revolut/1.0',
        ];
        if ($idempotencyKey !== '') {
            if (preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $idempotencyKey) !== 1) {
                $idempotencyKey = hash('sha256', $idempotencyKey);
            }
            $headers[] = 'Idempotency-Key: ' . substr($idempotencyKey, 0, 64);
        }
        $curl = curl_init($base . $path);
        if ($curl === false) {
            throw new SalePaymentException('sale.payment_provider_transport_unavailable');
        }
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeoutSeconds),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($body !== null) {
            $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $options[CURLOPT_POSTFIELDS] = $encoded;
        }
        curl_setopt_array($curl, $options);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $failed = $raw === false;
        curl_close($curl);
        if ($failed || $status < 200 || $status >= 300) {
            throw new SalePaymentException('sale.payment_provider_failed');
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new SalePaymentException('sale.payment_provider_payload_invalid');
        }
        return $decoded;
    }

    private function id(string $id): string
    {
        $id = trim($id);
        if (preg_match('/^[a-f0-9-]{32,40}$/i', $id) !== 1) {
            throw new SalePaymentException('sale.payment_provider_state_not_found');
        }
        return $id;
    }
}
