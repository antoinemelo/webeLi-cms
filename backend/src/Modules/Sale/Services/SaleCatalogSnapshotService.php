<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Core\Database;
use App\Modules\Sale\Contracts\SellableCatalogPort;
use InvalidArgumentException;
use RuntimeException;

final class SaleCatalogSnapshotService
{
    public function __construct(
        private readonly SaleDatabaseConnection $sale,
        private readonly SellableCatalogPort $sellables
    ) {}

    /** @param array<string,mixed> $pricingContext @return array<string,mixed> */
    public function snapshotForVariant(int $siteId, int $businessVariantId, string $channel = 'admin', bool $requireSellable = true, array $pricingContext = []): array
    {
        $snapshot = $this->sellables->getSellableVariantSnapshot($siteId, $businessVariantId, $pricingContext + [
            'channel' => $channel,
            'include_purchase_price' => true,
            'include_internal_fields' => true,
        ]);
        $snapshot = $this->applyInventorySnapshot($siteId, $snapshot, isset($pricingContext['stock_location_id']) ? (int) $pricingContext['stock_location_id'] : null);
        if ($requireSellable && !((bool) ($snapshot['is_sellable'] ?? false))) {
            throw new InvalidArgumentException('sale.catalog.variant_not_sellable');
        }
        $this->storeVariantRef($snapshot);
        return $snapshot;
    }

    /** @return array<string,mixed> */
    public function publicPayload(array $snapshot): array
    {
        $payload = $this->sellables->publicPayload($snapshot);
        unset($payload['stock_quantity'], $payload['stock_reserved'], $payload['available_quantity']);
        if (is_array($payload['metadata'] ?? null)) {
            unset($payload['metadata']['stock_quantity'], $payload['metadata']['stock_reserved'], $payload['metadata']['available_quantity']);
        }
        $availability = is_array($payload['availability'] ?? null) ? $payload['availability'] : [];
        $payload['availability'] = [
            'contract' => 'sale.inventory.availability.v1',
            'status' => (string) ($availability['status'] ?? 'unavailable'),
            'label' => (string) ($availability['label'] ?? ''),
            'is_orderable' => (bool) ($availability['is_orderable'] ?? false),
            'delivery_lead_time_days' => $availability['delivery_lead_time_days'] ?? null,
            'last_available' => ($availability['last_available'] ?? false) === true,
        ];
        return $payload;
    }

    /** @param array<string,mixed> $filters @return array{items:list<array<string,mixed>>,limit:int,offset:int,total:int,has_more:bool} */
    public function searchSellableVariants(int $siteId, array $filters = []): array
    {
        $result = $this->sellables->searchSellableVariants($siteId, $filters);
        $result['items'] = $this->applyInventorySnapshots($siteId, $result['items'], isset($filters['stock_location_id']) ? (int) $filters['stock_location_id'] : null);
        return $result;
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    private function applyInventorySnapshot(int $siteId, array $snapshot, ?int $locationId = null): array
    {
        $variantId = (int) ($snapshot['business_variant_id'] ?? 0);
        if ($variantId < 1) {
            return $snapshot;
        }
        $row = $this->database()->one(
            'SELECT business_variant_id,
                    MAX(tracked) AS tracked,
                    MAX(allow_backorder) AS allow_backorder,
                    MAX(backorder_delivery_days) AS backorder_delivery_days,
                    COALESCE(SUM(on_hand_quantity), 0) AS stock_quantity,
                    COALESCE(SUM(reserved_quantity), 0) AS stock_reserved,
                    COALESCE(SUM(available_quantity), 0) AS available_quantity
             FROM sale_inventory_items
             WHERE site_id = ? AND business_variant_id = ?' . ($locationId === null ? '' : ' AND stock_location_id = ?') . '
             GROUP BY business_variant_id',
            $locationId === null ? [$siteId, $variantId] : [$siteId, $variantId, $locationId]
        );
        return $row === null ? $snapshot : $this->withInventory($snapshot, $row);
    }

    /** @param list<array<string,mixed>> $items @return list<array<string,mixed>> */
    private function applyInventorySnapshots(int $siteId, array $items, ?int $locationId = null): array
    {
        $variantIds = [];
        foreach ($items as $item) {
            $variantId = (int) ($item['business_variant_id'] ?? 0);
            if ($variantId > 0) {
                $variantIds[$variantId] = $variantId;
            }
        }
        if ($variantIds === []) {
            return $items;
        }

        $placeholders = implode(',', array_fill(0, count($variantIds), '?'));
        $rows = $this->database()->all(
            'SELECT business_variant_id,
                    MAX(tracked) AS tracked,
                    MAX(allow_backorder) AS allow_backorder,
                    MAX(backorder_delivery_days) AS backorder_delivery_days,
                    COALESCE(SUM(on_hand_quantity), 0) AS stock_quantity,
                    COALESCE(SUM(reserved_quantity), 0) AS stock_reserved,
                    COALESCE(SUM(available_quantity), 0) AS available_quantity
             FROM sale_inventory_items
             WHERE site_id = ? AND business_variant_id IN (' . $placeholders . ')' . ($locationId === null ? '' : ' AND stock_location_id = ?') . '
             GROUP BY business_variant_id',
            array_merge([$siteId], array_values($variantIds), $locationId === null ? [] : [$locationId])
        );
        $byVariant = [];
        foreach ($rows as $row) {
            $byVariant[(int) $row['business_variant_id']] = $row;
        }

        foreach ($items as $index => $item) {
            $variantId = (int) ($item['business_variant_id'] ?? 0);
            if (isset($byVariant[$variantId])) {
                $items[$index] = $this->withInventory($item, $byVariant[$variantId]);
            }
        }
        return $items;
    }

    /** @param array<string,mixed> $snapshot @param array<string,mixed> $inventory @return array<string,mixed> */
    private function withInventory(array $snapshot, array $inventory): array
    {
        $stockQuantity = (int) ($inventory['stock_quantity'] ?? 0);
        $stockReserved = (int) ($inventory['stock_reserved'] ?? 0);
        $availableQuantity = (int) ($inventory['available_quantity'] ?? 0);
        $snapshot['track_stock'] = ((int) ($inventory['tracked'] ?? 0)) === 1;
        $snapshot['stock_quantity'] = $stockQuantity;
        $snapshot['stock_reserved'] = $stockReserved;
        $snapshot['available_quantity'] = $availableQuantity;
        $metadata = $snapshot['metadata'] ?? [];
        if (!is_array($metadata)) {
            $metadata = [];
        }
        $metadata['stock_quantity'] = $stockQuantity;
        $metadata['stock_reserved'] = $stockReserved;
        $metadata['available_quantity'] = $availableQuantity;
        $metadata['inventory_source'] = 'sale_transactional';
        $snapshot['metadata'] = $metadata;
        $allowBackorder = (bool) ($inventory['allow_backorder'] ?? $snapshot['allow_backorder'] ?? $metadata['allow_backorder'] ?? false);
        $days = max(1, (int) ($inventory['backorder_delivery_days'] ?? $snapshot['backorder_delivery_days'] ?? $metadata['backorder_delivery_days'] ?? 7));
        $snapshot['availability'] = !$snapshot['track_stock']
            ? ['status' => 'deliverable', 'label' => 'Livrable', 'is_orderable' => true, 'delivery_lead_time_days' => null, 'last_available' => false]
            : ($availableQuantity > 0
                ? ['status' => 'in_stock', 'label' => 'En stock', 'is_orderable' => true, 'delivery_lead_time_days' => null, 'last_available' => $availableQuantity === 1]
                : ($allowBackorder
                    ? ['status' => 'backorder', 'label' => 'Livraison sous ' . $days . ' jours', 'is_orderable' => true, 'delivery_lead_time_days' => $days, 'last_available' => false]
                    : ['status' => 'unavailable', 'label' => 'Indisponible', 'is_orderable' => false, 'delivery_lead_time_days' => null, 'last_available' => false]));
        return $snapshot;
    }

    /** @param array<string,mixed> $snapshot */
    private function storeVariantRef(array $snapshot): void
    {
        $db = $this->database();
        $payload = [
            'site_id' => (int) $snapshot['site_id'],
            'business_product_id' => (int) $snapshot['business_product_id'],
            'business_variant_id' => (int) $snapshot['business_variant_id'],
            'sellable_id' => (int) ($snapshot['sellable_id'] ?? $snapshot['business_variant_id']),
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
            'SELECT id FROM sale_catalog_variant_refs WHERE site_id = :site_id AND sellable_id = :sellable_id LIMIT 1',
            ['site_id' => $payload['site_id'], 'sellable_id' => $payload['sellable_id']]
        );
        if ($existing) {
            $updatePayload = $payload;
            unset($updatePayload['site_id'], $updatePayload['business_variant_id'], $updatePayload['sellable_id']);
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
                site_id, business_product_id, business_variant_id, sellable_id, sku, barcode,
                product_name, variant_name, product_type, track_stock, tax_class_id, last_snapshot_json
             ) VALUES(
                :site_id, :business_product_id, :business_variant_id, :sellable_id, :sku, :barcode,
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
