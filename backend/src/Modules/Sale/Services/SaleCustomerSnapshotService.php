<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Core\Database;
use App\Modules\Sale\Contracts\CustomerSnapshotPort;
use RuntimeException;

final class SaleCustomerSnapshotService
{
    public function __construct(
        private readonly SaleDatabaseConnection $sale,
        private readonly CustomerSnapshotPort $relations
    ) {}

    /** @return array<string,mixed>|null */
    public function snapshot(int $siteId, ?int $companyId = null, ?int $contactId = null): ?array
    {
        $snapshot = $this->relations->snapshot($siteId, $companyId, $contactId);
        if ($snapshot === null) {
            return null;
        }
        $this->storeCustomerRef($snapshot);
        return $snapshot;
    }

    /** @param array<string,mixed> $snapshot */
    private function storeCustomerRef(array $snapshot): void
    {
        $db = $this->database();
        $payload = [
            'site_id' => (int) $snapshot['site_id'],
            'company_id' => $snapshot['company_id'] ?? null,
            'contact_id' => $snapshot['contact_id'] ?? null,
            'display_name' => (string) $snapshot['display_name'],
            'email' => $snapshot['email'] ?? null,
            'phone' => $snapshot['phone'] ?? null,
            'billing_address_json' => $this->json((array) ($snapshot['billing_address'] ?? [])),
            'shipping_address_json' => $this->json((array) ($snapshot['shipping_address'] ?? [])),
        ];
        $existing = $db->one(
            'SELECT id FROM sale_customer_refs
             WHERE site_id = :site_id
               AND ((company_id = :company_id) OR (company_id IS NULL AND :company_id IS NULL))
               AND ((contact_id = :contact_id) OR (contact_id IS NULL AND :contact_id IS NULL))
             LIMIT 1',
            [
                'site_id' => $payload['site_id'],
                'company_id' => $payload['company_id'],
                'contact_id' => $payload['contact_id'],
            ]
        );
        if ($existing) {
            $db->run(
                'UPDATE sale_customer_refs
                 SET display_name = :display_name,
                     email = :email,
                     phone = :phone,
                     billing_address_json = :billing_address_json,
                     shipping_address_json = :shipping_address_json,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id',
                $payload + ['id' => (int) $existing['id']]
            );
            return;
        }

        $db->run(
            'INSERT INTO sale_customer_refs(
                site_id, company_id, contact_id, display_name, email, phone, billing_address_json, shipping_address_json
             ) VALUES(
                :site_id, :company_id, :contact_id, :display_name, :email, :phone, :billing_address_json, :shipping_address_json
             )',
            $payload
        );
    }

    private function database(): Database
    {
        $db = $this->sale->database();
        if ($db === null) {
            throw new RuntimeException('Base Vente indisponible.');
        }
        return $db;
    }

    /** @param array<string,mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
