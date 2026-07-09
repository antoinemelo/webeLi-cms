<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Core\Database;
use InvalidArgumentException;

final class SaleImportExportReportService
{
    private const EXPORT_DELIMITER = ';';
    private const MAX_IMPORT_BYTES = 1048576;
    private const STOCK_IMPORT_HEADERS = ['business_variant_id', 'sku', 'quantity_delta', 'on_hand_quantity', 'reason'];

    public function __construct(
        private readonly SaleDatabaseConnection $connection,
        private readonly SaleInventoryService $inventory,
    ) {}

    /** @param array<string,mixed> $filters */
    public function ordersCsv(int $siteId, array $filters = []): string
    {
        $rows = [[
            'id', 'order_number', 'channel_id', 'source', 'status', 'payment_status', 'currency',
            'subtotal_minor', 'discount_total_minor', 'tax_total_minor', 'shipping_total_minor',
            'grand_total_minor', 'paid_total_minor', 'refunded_total_minor', 'placed_at', 'created_at',
        ]];
        [$where, $params] = $this->orderWhere($siteId, $filters, 'o');
        foreach ($this->db()->all(
            'SELECT o.* FROM sale_orders o ' . $where . ' ORDER BY o.placed_at DESC, o.id DESC',
            $params
        ) as $order) {
            $rows[] = [
                $order['id'] ?? '',
                $order['order_number'] ?? '',
                $order['channel_id'] ?? '',
                $order['source'] ?? '',
                $order['status'] ?? '',
                $order['payment_status'] ?? '',
                $order['currency'] ?? '',
                $order['subtotal_minor'] ?? 0,
                $order['discount_total_minor'] ?? 0,
                $order['tax_total_minor'] ?? 0,
                $order['shipping_total_minor'] ?? 0,
                $order['grand_total_minor'] ?? 0,
                $order['paid_total_minor'] ?? 0,
                $order['refunded_total_minor'] ?? 0,
                $order['placed_at'] ?? '',
                $order['created_at'] ?? '',
            ];
        }
        return $this->csv($rows);
    }

    /** @param array<string,mixed> $filters */
    public function orderLinesCsv(int $siteId, array $filters = [], bool $includePurchasePrices = false): string
    {
        $rows = [[
            'order_id', 'order_number', 'line_number', 'business_product_id', 'business_variant_id',
            'sku', 'product_name', 'variant_name', 'product_type', 'quantity', 'unit_price_minor',
            'regular_unit_price_minor', 'unit_purchase_price_minor', 'currency', 'tax_rate_basis_points',
            'tax_included', 'line_subtotal_minor', 'line_discount_minor', 'line_tax_minor', 'line_total_minor',
        ]];
        [$where, $params] = $this->orderWhere($siteId, $filters, 'o');
        foreach ($this->db()->all(
            'SELECT l.*, o.order_number
             FROM sale_order_lines l
             INNER JOIN sale_orders o ON o.id = l.order_id
             ' . $where . '
             ORDER BY o.placed_at DESC, o.id DESC, l.line_number ASC',
            $params
        ) as $line) {
            $rows[] = [
                $line['order_id'] ?? '',
                $line['order_number'] ?? '',
                $line['line_number'] ?? '',
                $line['business_product_id'] ?? '',
                $line['business_variant_id'] ?? '',
                $line['sku'] ?? '',
                $line['product_name'] ?? '',
                $line['variant_name'] ?? '',
                $line['product_type'] ?? '',
                $line['quantity'] ?? 0,
                $line['unit_price_minor'] ?? 0,
                $line['regular_unit_price_minor'] ?? 0,
                $includePurchasePrices ? ($line['unit_purchase_price_minor'] ?? '') : '',
                $line['currency'] ?? '',
                $line['tax_rate_basis_points'] ?? 0,
                $line['tax_included'] ?? 1,
                $line['line_subtotal_minor'] ?? 0,
                $line['line_discount_minor'] ?? 0,
                $line['line_tax_minor'] ?? 0,
                $line['line_total_minor'] ?? 0,
            ];
        }
        return $this->csv($rows);
    }

    /** @param array<string,mixed> $filters */
    public function paymentsCsv(int $siteId, array $filters = []): string
    {
        $rows = [[
            'id', 'order_id', 'order_number', 'transaction_type', 'status', 'amount_minor',
            'currency', 'provider_transaction_id', 'error_code', 'error_message', 'processed_at', 'created_at',
        ]];
        [$where, $params] = $this->orderWhere($siteId, $filters, 'o');
        foreach ($this->db()->all(
            'SELECT t.*, o.order_number
             FROM sale_payment_transactions t
             INNER JOIN sale_orders o ON o.id = t.order_id
             ' . $where . '
             ORDER BY t.created_at DESC, t.id DESC',
            $params
        ) as $payment) {
            $rows[] = [
                $payment['id'] ?? '',
                $payment['order_id'] ?? '',
                $payment['order_number'] ?? '',
                $payment['transaction_type'] ?? '',
                $payment['status'] ?? '',
                $payment['amount_minor'] ?? 0,
                $payment['currency'] ?? '',
                $payment['provider_transaction_id'] ?? '',
                $payment['error_code'] ?? '',
                $payment['error_message'] ?? '',
                $payment['processed_at'] ?? '',
                $payment['created_at'] ?? '',
            ];
        }
        return $this->csv($rows);
    }

    /** @param array<string,mixed> $filters */
    public function posSessionsCsv(int $siteId, array $filters = []): string
    {
        $rows = [[
            'id', 'register_id', 'register_code', 'register_name', 'status', 'opening_cash_minor',
            'expected_cash_minor', 'counted_cash_minor', 'difference_minor', 'currency', 'opened_at', 'closed_at',
        ]];
        [$where, $params] = $this->posSessionWhere($siteId, $filters);
        foreach ($this->db()->all(
            'SELECT s.*, r.code AS register_code, r.name AS register_name
             FROM sale_cash_sessions s
             INNER JOIN sale_pos_registers r ON r.id = s.register_id
             ' . $where . '
             ORDER BY s.opened_at DESC, s.id DESC',
            $params
        ) as $session) {
            $rows[] = [
                $session['id'] ?? '',
                $session['register_id'] ?? '',
                $session['register_code'] ?? '',
                $session['register_name'] ?? '',
                $session['status'] ?? '',
                $session['opening_cash_minor'] ?? 0,
                $session['expected_cash_minor'] ?? 0,
                $session['counted_cash_minor'] ?? '',
                $session['difference_minor'] ?? 0,
                $session['currency'] ?? '',
                $session['opened_at'] ?? '',
                $session['closed_at'] ?? '',
            ];
        }
        return $this->csv($rows);
    }

    /** @param array<string,mixed> $filters */
    public function stockMovementsCsv(int $siteId, array $filters = []): string
    {
        $rows = [[
            'id', 'inventory_item_id', 'business_variant_id', 'sku', 'movement_type', 'quantity',
            'reference_type', 'reference_id', 'reason', 'created_by_iam_user_id', 'created_at',
        ]];
        [$where, $params] = $this->stockMovementWhere($siteId, $filters);
        foreach ($this->db()->all(
            'SELECT m.*, i.business_variant_id, i.sku
             FROM sale_stock_movements m
             INNER JOIN sale_inventory_items i ON i.id = m.inventory_item_id
             ' . $where . '
             ORDER BY m.created_at DESC, m.id DESC',
            $params
        ) as $movement) {
            $rows[] = [
                $movement['id'] ?? '',
                $movement['inventory_item_id'] ?? '',
                $movement['business_variant_id'] ?? '',
                $movement['sku'] ?? '',
                $movement['movement_type'] ?? '',
                $movement['quantity'] ?? 0,
                $movement['reference_type'] ?? '',
                $movement['reference_id'] ?? '',
                $movement['reason'] ?? '',
                $movement['created_by_iam_user_id'] ?? '',
                $movement['created_at'] ?? '',
            ];
        }
        return $this->csv($rows);
    }

    /** @param array<string,mixed> $filters */
    public function returnsRefundsCsv(int $siteId, array $filters = []): string
    {
        $rows = [[
            'record_type', 'id', 'order_id', 'order_number', 'status', 'amount_minor', 'currency',
            'reason', 'created_by_iam_user_id', 'created_at', 'processed_at',
        ]];
        [$where, $params] = $this->orderWhere($siteId, $filters, 'o');
        foreach ($this->db()->all(
            'SELECT r.id, r.order_id, o.order_number, r.status, NULL AS amount_minor, o.currency,
                    r.reason, r.created_by_iam_user_id, r.created_at, r.completed_at AS processed_at
             FROM sale_returns r
             INNER JOIN sale_orders o ON o.id = r.order_id
             ' . $where . '
             ORDER BY r.created_at DESC, r.id DESC',
            $params
        ) as $return) {
            $rows[] = ['return', $return['id'] ?? '', $return['order_id'] ?? '', $return['order_number'] ?? '', $return['status'] ?? '', '', $return['currency'] ?? '', $return['reason'] ?? '', $return['created_by_iam_user_id'] ?? '', $return['created_at'] ?? '', $return['processed_at'] ?? ''];
        }
        foreach ($this->db()->all(
            'SELECT f.id, f.order_id, o.order_number, f.status, f.amount_minor, f.currency,
                    f.reason, f.created_by_iam_user_id, f.created_at, f.processed_at
             FROM sale_refunds f
             INNER JOIN sale_orders o ON o.id = f.order_id
             ' . $where . '
             ORDER BY f.created_at DESC, f.id DESC',
            $params
        ) as $refund) {
            $rows[] = ['refund', $refund['id'] ?? '', $refund['order_id'] ?? '', $refund['order_number'] ?? '', $refund['status'] ?? '', $refund['amount_minor'] ?? 0, $refund['currency'] ?? '', $refund['reason'] ?? '', $refund['created_by_iam_user_id'] ?? '', $refund['created_at'] ?? '', $refund['processed_at'] ?? ''];
        }
        return $this->csv($rows);
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function importStockCsv(int $siteId, string $csv, array $options, ?int $actorId = null): array
    {
        if (strlen($csv) > self::MAX_IMPORT_BYTES) {
            throw new InvalidArgumentException('sale.import_csv_too_large');
        }
        if (preg_match('//u', $csv) !== 1) {
            throw new InvalidArgumentException('sale.import_csv_utf8_required');
        }
        $dryRun = filter_var($options['dry_run'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $dryRun = $dryRun !== false;
        $confirm = filter_var($options['confirm_import'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$dryRun && !$confirm) {
            throw new InvalidArgumentException('sale.import_confirm_required');
        }
        [$headers, $rows] = $this->parseCsv($csv, $this->detectDelimiter($csv));
        $this->assertStockHeaders($headers);
        $report = [
            'dry_run' => $dryRun,
            'rows_total' => count($rows),
            'valid_rows' => 0,
            'adjusted' => 0,
            'skipped' => 0,
            'errors' => [],
            'rows' => [],
            'writes_performed' => false,
        ];
        $plans = [];
        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $data = $this->normalizeRow($headers, $row);
            try {
                $plan = $this->planStockImportRow($siteId, $data);
                $plans[] = $plan;
                $report['valid_rows']++;
                $report['rows'][] = ['line' => $line, 'status' => 'valid', 'business_variant_id' => $plan['business_variant_id'], 'quantity_delta' => $plan['quantity_delta']];
            } catch (InvalidArgumentException $e) {
                $report['skipped']++;
                $report['errors'][] = ['line' => $line, 'message' => $e->getMessage()];
                $report['rows'][] = ['line' => $line, 'status' => 'error', 'errors' => [$e->getMessage()]];
            }
        }
        if ($report['errors'] !== [] || $dryRun) {
            return $report;
        }
        foreach ($plans as $plan) {
            $this->inventory->adjust($siteId, (int) $plan['business_variant_id'], (int) $plan['quantity_delta'], $plan['sku'], $plan['reason'], $actorId);
            $report['adjusted']++;
            $report['writes_performed'] = true;
        }
        return $report;
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function dailyReport(int $siteId, array $filters = []): array
    {
        $date = $this->reportDate($filters);
        $row = $this->db()->one(
            'SELECT COUNT(*) AS orders_count,
                    COALESCE(SUM(grand_total_minor), 0) AS sales_minor,
                    COALESCE(SUM(paid_total_minor), 0) AS paid_minor,
                    COALESCE(SUM(refunded_total_minor), 0) AS refunded_minor
             FROM sale_orders
             WHERE site_id = ? AND date(COALESCE(placed_at, created_at)) = ? AND status <> "cancelled"',
            [$siteId, $date]
        ) ?? [];
        return [
            'date' => $date,
            'orders_count' => (int) ($row['orders_count'] ?? 0),
            'sales_minor' => (int) ($row['sales_minor'] ?? 0),
            'paid_minor' => (int) ($row['paid_minor'] ?? 0),
            'refunded_minor' => (int) ($row['refunded_minor'] ?? 0),
            'by_channel' => $this->salesByChannel($siteId, ['date' => $date]),
            'by_payment_method' => $this->salesByPaymentMethod($siteId, ['date' => $date]),
        ];
    }

    /** @param array<string,mixed> $filters @return list<array<string,mixed>> */
    public function salesByChannel(int $siteId, array $filters = []): array
    {
        [$where, $params] = $this->orderWhere($siteId, $filters, 'o');
        return $this->db()->all(
            'SELECT c.id AS channel_id, c.code AS channel_code, c.name AS channel_name, c.channel_type,
                    COUNT(o.id) AS orders_count,
                    COALESCE(SUM(o.grand_total_minor), 0) AS sales_minor,
                    COALESCE(SUM(o.paid_total_minor), 0) AS paid_minor,
                    COALESCE(SUM(o.refunded_total_minor), 0) AS refunded_minor
             FROM sale_orders o
             INNER JOIN sale_channels c ON c.id = o.channel_id
             ' . $where . '
             GROUP BY c.id, c.code, c.name, c.channel_type
             ORDER BY sales_minor DESC, c.code ASC',
            $params
        );
    }

    /** @param array<string,mixed> $filters @return list<array<string,mixed>> */
    public function salesByPaymentMethod(int $siteId, array $filters = []): array
    {
        [$where, $params] = $this->orderWhere($siteId, $filters, 'o');
        return $this->db()->all(
            'SELECT COALESCE(json_extract(t.provider_payload_json, "$.provider"), "unknown") AS provider_key,
                    t.transaction_type, t.status,
                    COUNT(t.id) AS transactions_count,
                    COALESCE(SUM(t.amount_minor), 0) AS amount_minor,
                    t.currency
             FROM sale_payment_transactions t
             INNER JOIN sale_orders o ON o.id = t.order_id
             ' . $where . '
             GROUP BY provider_key, t.transaction_type, t.status, t.currency
             ORDER BY amount_minor DESC, provider_key ASC',
            $params
        );
    }

    /** @param array<string,mixed> $filters @return list<array<string,mixed>> */
    public function stockReport(int $siteId, array $filters = []): array
    {
        $onlyLow = filter_var($filters['low_only'] ?? false, FILTER_VALIDATE_BOOLEAN);
        return $this->db()->all(
            'SELECT business_variant_id, sku, tracked, on_hand_quantity, reserved_quantity, available_quantity, updated_at
             FROM sale_inventory_items
             WHERE site_id = ?' . ($onlyLow ? ' AND tracked = 1 AND available_quantity <= 0' : '') . '
             ORDER BY available_quantity ASC, updated_at DESC, id DESC',
            [$siteId]
        );
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function refundsReport(int $siteId, array $filters = []): array
    {
        [$where, $params] = $this->orderWhere($siteId, $filters, 'o');
        $items = $this->db()->all(
            'SELECT f.*, o.order_number
             FROM sale_refunds f
             INNER JOIN sale_orders o ON o.id = f.order_id
             ' . $where . '
             ORDER BY f.created_at DESC, f.id DESC',
            $params
        );
        return [
            'items' => $items,
            'count' => count($items),
            'amount_minor' => array_sum(array_map(static fn(array $row): int => (int) $row['amount_minor'], $items)),
        ];
    }

    private function db(): Database
    {
        $db = $this->connection->database();
        if ($db === null) {
            throw new InvalidArgumentException('sale.database_unavailable');
        }
        return $db;
    }

    /** @param array<string,mixed> $filters @return array{0:string,1:array<string,mixed>} */
    private function orderWhere(int $siteId, array $filters, string $alias): array
    {
        $clauses = [$alias . '.site_id = :site_id'];
        $params = ['site_id' => $siteId];
        foreach (['channel_id', 'status', 'payment_status', 'source'] as $key) {
            if (($filters[$key] ?? '') !== '') {
                $clauses[] = $alias . '.' . $key . ' = :' . $key;
                $params[$key] = $key === 'channel_id' ? (int) $filters[$key] : (string) $filters[$key];
            }
        }
        $date = trim((string) ($filters['date'] ?? ''));
        if ($date !== '') {
            $clauses[] = 'date(COALESCE(' . $alias . '.placed_at, ' . $alias . '.created_at)) = :date';
            $params['date'] = $date;
        }
        foreach (['date_from' => '>=', 'date_to' => '<='] as $key => $operator) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value !== '') {
                $clauses[] = 'date(COALESCE(' . $alias . '.placed_at, ' . $alias . '.created_at)) ' . $operator . ' :' . $key;
                $params[$key] = $value;
            }
        }
        return ['WHERE ' . implode(' AND ', $clauses), $params];
    }

    /** @param array<string,mixed> $filters @return array{0:string,1:array<string,mixed>} */
    private function posSessionWhere(int $siteId, array $filters): array
    {
        $clauses = ['r.site_id = :site_id'];
        $params = ['site_id' => $siteId];
        if (($filters['register_id'] ?? '') !== '') {
            $clauses[] = 'r.id = :register_id';
            $params['register_id'] = (int) $filters['register_id'];
        }
        $date = trim((string) ($filters['date'] ?? ''));
        if ($date !== '') {
            $clauses[] = 'date(s.opened_at) = :date';
            $params['date'] = $date;
        }
        return ['WHERE ' . implode(' AND ', $clauses), $params];
    }

    /** @param array<string,mixed> $filters @return array{0:string,1:array<string,mixed>} */
    private function stockMovementWhere(int $siteId, array $filters): array
    {
        $clauses = ['i.site_id = :site_id'];
        $params = ['site_id' => $siteId];
        if (($filters['movement_type'] ?? '') !== '') {
            $clauses[] = 'm.movement_type = :movement_type';
            $params['movement_type'] = (string) $filters['movement_type'];
        }
        return ['WHERE ' . implode(' AND ', $clauses), $params];
    }

    /** @param array<string,string> $row @return array<string,mixed> */
    private function planStockImportRow(int $siteId, array $row): array
    {
        $variantId = (int) ($row['business_variant_id'] ?? 0);
        if ($variantId < 1) {
            throw new InvalidArgumentException('sale.import_business_variant_id_required');
        }
        $sku = trim((string) ($row['sku'] ?? ''));
        $reason = trim((string) ($row['reason'] ?? 'CSV stock import'));
        $deltaCell = trim((string) ($row['quantity_delta'] ?? ''));
        $targetCell = trim((string) ($row['on_hand_quantity'] ?? ''));
        if ($deltaCell === '' && $targetCell === '') {
            throw new InvalidArgumentException('sale.import_quantity_required');
        }
        $delta = $deltaCell !== '' ? $this->intCell($deltaCell, 'sale.import_quantity_delta_invalid') : null;
        if ($delta === null) {
            $target = $this->intCell($targetCell, 'sale.import_on_hand_quantity_invalid');
            $current = (int) ($this->db()->one(
                'SELECT on_hand_quantity FROM sale_inventory_items WHERE site_id = ? AND business_variant_id = ? LIMIT 1',
                [$siteId, $variantId]
            )['on_hand_quantity'] ?? 0);
            $delta = $target - $current;
        }
        if ($delta === 0) {
            throw new InvalidArgumentException('sale.import_quantity_delta_zero');
        }
        return [
            'business_variant_id' => $variantId,
            'sku' => $sku !== '' ? $sku : null,
            'quantity_delta' => $delta,
            'reason' => $reason !== '' ? $reason : 'CSV stock import',
        ];
    }

    private function intCell(string $value, string $error): int
    {
        if (!preg_match('/^-?\d+$/', trim($value))) {
            throw new InvalidArgumentException($error);
        }
        return (int) $value;
    }

    /** @return array{0:list<string>,1:list<list<string>>} */
    private function parseCsv(string $csv, string $delimiter): array
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new InvalidArgumentException('sale.import_csv_read_failed');
        }
        fwrite($handle, $this->stripBom($csv));
        rewind($handle);
        $headers = fgetcsv($handle, 0, $delimiter, '"', '');
        if (!is_array($headers) || $headers === []) {
            fclose($handle);
            throw new InvalidArgumentException('sale.import_csv_header_required');
        }
        $headers = array_map(fn(mixed $value): string => $this->headerKey((string) $value), $headers);
        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if (!is_array($row) || $this->emptyRow($row)) {
                continue;
            }
            $rows[] = array_map(static fn(mixed $value): string => trim((string) $value), $row);
        }
        fclose($handle);
        return [$headers, $rows];
    }

    /** @param list<string> $headers */
    private function assertStockHeaders(array $headers): void
    {
        $known = array_fill_keys(self::STOCK_IMPORT_HEADERS, true);
        foreach ($headers as $header) {
            if (!isset($known[$header])) {
                throw new InvalidArgumentException('sale.import_unknown_header_' . $header);
            }
        }
        if (!in_array('business_variant_id', $headers, true)) {
            throw new InvalidArgumentException('sale.import_business_variant_id_header_required');
        }
    }

    /** @param list<string> $headers @param list<string> $row @return array<string,string> */
    private function normalizeRow(array $headers, array $row): array
    {
        $data = [];
        foreach ($headers as $index => $header) {
            $data[$header] = trim((string) ($row[$index] ?? ''));
        }
        return $data;
    }

    /** @param list<list<mixed>> $rows */
    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn(mixed $value): string => $this->safeCell($value), $row), self::EXPORT_DELIMITER, '"', '');
        }
        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);
        return $csv;
    }

    private function safeCell(mixed $value): string
    {
        $text = (string) ($value ?? '');
        return preg_match('/^[=+\-@]/', $text) ? "'" . $text : $text;
    }

    private function reportDate(array $filters): string
    {
        $date = trim((string) ($filters['date'] ?? ''));
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : gmdate('Y-m-d');
    }

    private function detectDelimiter(string $csv): string
    {
        $line = strtok($this->stripBom($csv), "\r\n") ?: '';
        return substr_count($line, ';') >= substr_count($line, ',') ? ';' : ',';
    }

    private function stripBom(string $csv): string
    {
        return str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv;
    }

    private function headerKey(string $header): string
    {
        return str_replace([' ', '-'], '_', strtolower(trim($this->stripBom($header))));
    }

    /** @param list<mixed> $row */
    private function emptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }
        return true;
    }
}
