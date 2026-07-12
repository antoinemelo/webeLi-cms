<?php

declare(strict_types=1);

namespace App\Modules\Sale\Repositories;

final class SaleReceiptRepository extends SaleRepositoryBase
{
    /** @return array<string,mixed>|null */
    public function issued(int $orderId, string $language): ?array
    {
        return $this->rawDatabase()->one(
            'SELECT * FROM sale_receipts WHERE order_id=? AND language=? AND status=\'issued\' ORDER BY id DESC LIMIT 1',
            [$orderId, $language]
        );
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    public function issue(int $orderId, string $number, string $type, string $language, string $text, string $html, array $snapshot, ?int $actorId): array
    {
        $this->rawDatabase()->run(
            'INSERT OR IGNORE INTO sale_receipts(order_id,receipt_number,receipt_type,status,html_snapshot,text_snapshot,language,operator_iam_user_id,snapshot_json,issued_at)
             VALUES(?,?,?,\'issued\',?,?,?,?,?,CURRENT_TIMESTAMP)',
            [$orderId, $number, $type, $html, $text, $language, $actorId, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}']
        );
        return $this->rawDatabase()->one('SELECT * FROM sale_receipts WHERE order_id=? AND receipt_number=?', [$orderId, $number]) ?? [];
    }
}
