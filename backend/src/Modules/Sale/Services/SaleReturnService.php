<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Modules\Sale\Exceptions\SaleValidationException;

final class SaleReturnService
{
    public function __construct(
        private readonly SaleDatabaseConnection $connection,
        private readonly SaleStateMachineService $states,
        private readonly SaleInventoryService $inventory
    ) {}

    /** @param list<array{order_line_id:int,quantity:int,restock?:bool,reason?:string}> $lines @return array<string,mixed> */
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
        return $db->transaction(function () use ($orderId, $lines, $reason, $actorId, $idempotencyKey, $requestHash, $db): array {
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
                $db->run(
                    'INSERT INTO sale_return_lines(return_id,order_line_id,quantity,reason,restock) VALUES(?,?,?,?,?)',
                    [$returnId, (int) $orderLine['id'], $quantity, $line['reason'] ?? null, !array_key_exists('restock', $line) || (bool) $line['restock'] ? 1 : 0]
                );
            }
            $correlationId = SaleStateMachineService::correlationId();
            $this->states->recordInitial((int) $order['site_id'], 'return', $returnId, 'requested', $correlationId, $actorId, $reason);
            return $this->withLines($returnId) + ['replayed' => false, 'correlation_id' => $correlationId];
        });
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
                        $this->inventory->restockReturn((int) $order['site_id'], (int) $orderLine['business_variant_id'], (int) $line['quantity'], $orderLine['sku'] ?? null, $returnId, $reason, $actorId);
                    }
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

    private function db(): \App\Core\Database
    {
        return $this->connection->database() ?? throw new SaleValidationException('sale.database_unavailable');
    }
}
