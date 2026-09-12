<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Modules\Sale\Exceptions\SaleValidationException;

final class SaleReturnService
{
    public function __construct(
        private readonly SaleDatabaseConnection $connection,
        private readonly SaleStateMachineService $states,
        private readonly SaleInventoryService $inventory,
        private readonly ?SaleEventService $events = null
    ) {}

    /** @param list<array{order_line_id:int,quantity:int,restock?:bool,stock_disposition?:string,reason?:string,components?:list<array{business_variant_id:int,quantity:int}>}> $lines @return array<string,mixed> */
    public function request(int $orderId, array $lines, ?string $reason = null, ?int $actorId = null, ?string $idempotencyKey = null): array
    {
        if ($lines === []) {
            throw new SaleValidationException('sale.return_lines_required');
        }
        $db = $this->db();
        $requestHash = hash('sha256', json_encode([$orderId, $lines, $reason], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');
        if ($idempotencyKey !== null && trim($idempotencyKey) !== '') {
            $existing = $db->one('SELECT * FROM sale_returns WHERE order_id=? AND idempotency_key=?', [$orderId, trim($idempotencyKey)]);
            if ($existing !== null) {
                if (!hash_equals((string) ($existing['request_hash'] ?? ''), $requestHash)) {
                    throw new SaleValidationException('sale.return_idempotency_conflict');
                }
                return $this->withLines((int) $existing['id']) + ['replayed' => true];
            }
        }
        $result = $db->transaction(function () use ($orderId, $lines, $reason, $actorId, $idempotencyKey, $requestHash, $db): array {
            $order = $db->one('SELECT * FROM sale_orders WHERE id=?', [$orderId]);
            if ($order === null || (string) $order['status'] === 'cancelled') {
                throw new SaleValidationException('sale.return_order_invalid');
            }
            $number = 'RET-' . (string) $order['order_number'] . '-' . substr(hash('sha256', $requestHash), 0, 8);
            $db->run(
                'INSERT INTO sale_returns(order_id,return_number,status,reason,idempotency_key,request_hash,created_by_iam_user_id) VALUES(?,?,\'requested\',?,?,?,?)',
                [$orderId, $number, $reason, $idempotencyKey === null ? null : trim($idempotencyKey), $requestHash, $actorId]
            );
            $returnId = (int) $db->lastInsertId();
            foreach ($lines as $line) {
                $orderLine = $db->one('SELECT id,quantity,returned_quantity FROM sale_order_lines WHERE id=? AND order_id=?', [(int) $line['order_line_id'], $orderId]);
                $quantity = (int) $line['quantity'];
                if ($orderLine === null || $quantity < 1 || (int) $orderLine['returned_quantity'] + $quantity > (int) $orderLine['quantity']) {
                    throw new SaleValidationException('sale.return_quantity_invalid');
                }
                $disposition=(string)($line['stock_disposition']??'sellable');
                if(!in_array($disposition,['sellable','quarantine','non_sellable'],true)) throw new SaleValidationException('sale.return_stock_disposition_invalid');
                $db->run(
                    'INSERT INTO sale_return_lines(return_id,order_line_id,quantity,reason,restock,stock_disposition,component_returns_json) VALUES(?,?,?,?,?,?,?)',
                    [$returnId, (int) $orderLine['id'], $quantity, $line['reason'] ?? null, !array_key_exists('restock', $line) || (bool) $line['restock'] ? 1 : 0, $disposition, json_encode(array_values((array) ($line['components'] ?? [])), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]
                );
            }
            $correlationId = SaleStateMachineService::correlationId();
            $this->states->recordInitial((int) $order['site_id'], 'return', $returnId, 'requested', $correlationId, $actorId, $reason);
            $result = $this->withLines($returnId) + ['replayed' => false, 'correlation_id' => $correlationId];
            if ($this->events !== null) {
                $this->events->emit((int) $order['site_id'], 'sale.return.created', 'return', $returnId, [
                    'site_id' => (int) $order['site_id'], 'order_id' => $orderId, 'return_id' => $returnId,
                    'return_number' => (string) $result['return_number'], 'reason' => $reason, 'iam_user_id' => $actorId,
                ], $actorId, $correlationId);
            }
            return $result;
        });
        return $result;
    }

    /** @return array<string,mixed> */
    public function transition(int $returnId, string $status, ?int $actorId = null, ?string $reason = null): array
    {
        $db = $this->db();
        return $db->transaction(function () use ($returnId, $status, $actorId, $reason, $db): array {
            $return = $this->withLines($returnId);
            $correlationId = SaleStateMachineService::correlationId();
            $this->states->transition('return', $returnId, $status, $actorId, $reason, $correlationId);
            if ($status === 'completed') {
                $order = $db->one('SELECT * FROM sale_orders WHERE id=?', [(int) $return['order_id']]);
                $restocks = [];
                foreach ($return['lines'] as $line) {
                    $orderLine = $db->one('SELECT * FROM sale_order_lines WHERE id=?', [(int) $line['order_line_id']]);
                    if ($orderLine === null) {
                        throw new SaleValidationException('sale.return_order_line_not_found');
                    }
                    $db->run(
                        'UPDATE sale_order_lines SET returned_quantity=returned_quantity+? WHERE id=? AND returned_quantity+?<=quantity',
                        [(int) $line['quantity'], (int) $orderLine['id'], (int) $line['quantity']]
                    );
                    if ((int) ($db->one('SELECT changes() AS count')['count'] ?? 0) !== 1) {
                        throw new SaleValidationException('sale.return_concurrent_quantity_conflict');
                    }
                    if ((bool) $line['restock']) {
                        foreach ($this->returnRestocks($orderLine, $line) as $restock) {
                            $variantId = (int) $restock['business_variant_id'];
                            $disposition=(string)($line['stock_disposition']??'sellable');$key=$disposition.':'.$variantId;
                            $restocks[$key] ??= ['business_variant_id'=>$variantId,'quantity' => 0, 'sku' => $restock['sku'] ?? null,'disposition'=>$disposition];
                            $restocks[$key]['quantity'] += (int) $restock['quantity'];
                        }
                    }
                }
                foreach ($restocks as $restock) {
                    if($restock['disposition']==='sellable'){$this->inventory->restockReturn((int)$order['site_id'],(int)$restock['business_variant_id'],(int)$restock['quantity'],$restock['sku'],$returnId,$reason,$actorId);continue;}
                    $locationId=$this->dispositionLocation((int)$order['site_id'],(string)$restock['disposition']);
                    $this->inventory->adjust((int)$order['site_id'],(int)$restock['business_variant_id'],(int)$restock['quantity'],$restock['sku'],'Return '.$returnId.' to '.$restock['disposition'],$actorId,$locationId,'return','return:'.$returnId.':'.$restock['disposition'].':'.$restock['business_variant_id']);
                }
            }
            return $this->withLines($returnId) + ['correlation_id' => $correlationId];
        });
    }

    /** @return array<string,mixed> */
    private function withLines(int $returnId): array
    {
        $row = $this->db()->one('SELECT * FROM sale_returns WHERE id=?', [$returnId]);
        if ($row === null) {
            throw new SaleValidationException('sale.return_not_found');
        }
        $row['lines'] = $this->db()->all('SELECT * FROM sale_return_lines WHERE return_id=? ORDER BY id', [$returnId]);
        return $row;
    }

    /** @param array<string,mixed> $orderLine @param array<string,mixed> $returnLine @return list<array{business_variant_id:int,quantity:int,sku:?string}> */
    private function returnRestocks(array $orderLine, array $returnLine): array
    {
        $metadata = json_decode((string) ($orderLine['snapshot_json'] ?? '{}'), true);
        $snapshot = is_array($metadata) && is_array($metadata['snapshot'] ?? null) ? $metadata['snapshot'] : [];
        $strategy = (string) ($snapshot['bundle_stock_strategy'] ?? match ((string) ($snapshot['bundle_stock_mode'] ?? '')) { 'components' => 'COMPONENT_DERIVED', 'none' => 'NON_STOCKED', default => 'OWN_STOCK' });
        if ((string) ($orderLine['product_type'] ?? '') !== 'bundle' || $strategy === 'OWN_STOCK') {
            return [['business_variant_id' => (int) $orderLine['business_variant_id'], 'quantity' => (int) $returnLine['quantity'], 'sku' => $orderLine['sku'] ?? null]];
        }
        if ($strategy === 'NON_STOCKED') return [];
        $plan = array_values(array_filter((array) ($snapshot['bundle_inventory_plan'] ?? []), 'is_array'));
        $requestedComponents = json_decode((string) ($returnLine['component_returns_json'] ?? '[]'), true);
        if (is_array($requestedComponents) && $requestedComponents !== []) {
            if (($snapshot['bundle_component_return_policy'] ?? 'BUNDLE_ONLY') !== 'COMPONENTS_ALLOWED') {
                throw new SaleValidationException('sale.bundle_component_return_forbidden');
            }
            $allowed = array_fill_keys(array_map(static fn(array $leaf): int => (int) ($leaf['business_variant_id'] ?? 0), $plan), true);
            $result = [];
            foreach ($requestedComponents as $component) {
                $variantId = (int) ($component['business_variant_id'] ?? 0);
                $quantity = (int) ($component['quantity'] ?? 0);
                if ($variantId < 1 || $quantity < 1 || !isset($allowed[$variantId])) throw new SaleValidationException('sale.bundle_component_return_invalid');
                $result[] = ['business_variant_id' => $variantId, 'quantity' => $quantity, 'sku' => null];
            }
            return $result;
        }
        $result = [];
        foreach ($plan as $leaf) {
            if (!((bool) ($leaf['track_stock'] ?? false))) continue;
            $quantity = (int) ceil((float) ($leaf['quantity_per_bundle'] ?? 1) * (int) $returnLine['quantity']);
            if ($quantity > 0) $result[] = ['business_variant_id' => (int) $leaf['business_variant_id'], 'quantity' => $quantity, 'sku' => $leaf['sku'] ?? null];
        }
        return $result;
    }

    private function db(): \App\Core\Database
    {
        return $this->connection->database() ?? throw new SaleValidationException('sale.database_unavailable');
    }

    private function dispositionLocation(int $siteId,string $disposition):int
    {
        $row=$this->db()->one("SELECT id FROM sale_stock_locations WHERE site_id=? AND location_type=? AND status='active' ORDER BY id LIMIT 1",[$siteId,$disposition]);
        if($row!==null)return (int)$row['id'];
        $name=$disposition==='quarantine'?'Quarantaine':'Non vendable';
        $this->db()->run('INSERT INTO sale_stock_locations(site_id,code,name,location_type,status) VALUES(?,?,?,?,\'active\')',[$siteId,$disposition,$name,$disposition]);
        return $this->db()->lastInsertId();
    }
}
