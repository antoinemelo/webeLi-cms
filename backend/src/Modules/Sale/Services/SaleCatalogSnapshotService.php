<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Core\Database;
use App\Modules\Business\Services\BusinessCatalogSellableReadService;
use InvalidArgumentException;
use RuntimeException;

final class SaleCatalogSnapshotService
{
    public function __construct(
        private readonly SaleDatabaseConnection $sale,
        private readonly BusinessCatalogSellableReadService $sellables
    ) {}

    /** @return array<string,mixed> */
    public function snapshotForVariant(int $siteId, int $businessVariantId, string $channel = 'admin', bool $requireSellable = true): array
    {
        $snapshot = $this->sellables->variantSnapshot($siteId, $businessVariantId, $channel, true);
        if ($requireSellable && !((bool) ($snapshot['is_sellable'] ?? false))) {
            throw new InvalidArgumentException('sale.catalog.variant_not_sellable');
        }
        $this->storeVariantRef($snapshot);
        return $snapshot;
    }

    /** @return array<string,mixed> */
    public function publicPayload(array $snapshot): array
    {
        return $this->sellables->publicPayload($snapshot);
    }

    /** @param array<string,mixed> $filters @return array{items:list<array<string,mixed>>,limit:int,offset:int,total:int,has_more:bool} */
    public function searchSellableVariants(int $siteId, array $filters = []): array
    {
        return $this->sellables->searchSellableVariants($siteId, $filters);
    }

    /** @param array<string,mixed> $snapshot */
    private function storeVariantRef(array $snapshot): void
    {
        $db = $this->database();
        $payload = [
            'site_id' => (int) $snapshot['site_id'],
            'business_product_id' => (int) $snapshot['business_product_id'],
            'business_variant_id' => (int) $snapshot['business_variant_id'],
            'sku' => $snapshot['sku'] ?? null,
            'barcode' => $snapshot['barcode'] ?? null,
            'product_name' => (string) $snapshot['product_name'],
            'variant_name' => $snapshot['variant_name'] ?? null,
            'product_type' => (string) $snapshot['product_type'],
            'track_stock' => (int) (bool) ($snapshot['track_stock'] ?? false),
            'tax_class_id' => $snapshot['tax_class_id'] ?? null,
            'last_snapshot_json' => $this->json($snapshot),
        ];

        $existing = $db->one(
            'SELECT id FROM sale_catalog_variant_refs WHERE site_id = :site_id AND business_variant_id = :business_variant_id LIMIT 1',
            ['site_id' => $payload['site_id'], 'business_variant_id' => $payload['business_variant_id']]
        );
        if ($existing) {
            $updatePayload = $payload;
            unset($updatePayload['site_id'], $updatePayload['business_variant_id']);
            $db->run(
                'UPDATE sale_catalog_variant_refs
                 SET business_product_id = :business_product_id,
                     sku = :sku,
                     barcode = :barcode,
                     product_name = :product_name,
                     variant_name = :variant_name,
                     product_type = :product_type,
                     track_stock = :track_stock,
                     tax_class_id = :tax_class_id,
                     last_snapshot_json = :last_snapshot_json,
                     synced_at = CURRENT_TIMESTAMP,
                     archived_at = NULL
                 WHERE id = :id',
                $updatePayload + ['id' => (int) $existing['id']]
            );
            return;
        }

        $db->run(
            'INSERT INTO sale_catalog_variant_refs(
                site_id, business_product_id, business_variant_id, sku, barcode,
                product_name, variant_name, product_type, track_stock, tax_class_id, last_snapshot_json
             ) VALUES(
                :site_id, :business_product_id, :business_variant_id, :sku, :barcode,
                :product_name, :variant_name, :product_type, :track_stock, :tax_class_id, :last_snapshot_json
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
