<?php

declare(strict_types=1);

namespace App\Modules\Sale\Repositories;

use App\Modules\Sale\Exceptions\SaleValidationException;

final class SalePosRepository extends SaleRepositoryBase
{
    /** @return list<array<string,mixed>> */
    public function registers(int $siteId, bool $activeOnly = false): array
    {
        $status = $activeOnly ? " AND r.status='active'" : '';
        $rows = $this->rawDatabase()->all(
            'SELECT r.*,c.code AS channel_code,c.channel_type,l.code AS stock_location_code,l.name AS stock_location_name
             FROM sale_pos_registers r
             INNER JOIN sale_channels c ON c.id=r.channel_id
             LEFT JOIN sale_stock_locations l ON l.id=r.stock_location_id
             WHERE r.site_id=?' . $status . ' ORDER BY r.code ASC',
            [$siteId]
        );
        foreach ($rows as &$row) {
            $row['payment_methods'] = $this->allowedPaymentMethods((int) $row['id']);
        }
        unset($row);
        return $rows;
    }

    /** @return array<string,mixed> */
    public function requireRegister(int $registerId, int $siteId): array
    {
        $row = $this->rawDatabase()->one(
            "SELECT r.*,c.channel_type,l.status AS stock_location_status
             FROM sale_pos_registers r
             INNER JOIN sale_channels c ON c.id=r.channel_id
             LEFT JOIN sale_stock_locations l ON l.id=r.stock_location_id
             WHERE r.id=? AND r.site_id=? AND r.status='active'",
            [$registerId, $siteId]
        );
        if ($row === null) {
            throw new SaleValidationException('sale.pos_register_not_found');
        }
        if ((string) $row['channel_type'] !== 'pos') {
            throw new SaleValidationException('sale.pos_register_channel_invalid');
        }
        if ((int) ($row['stock_location_id'] ?? 0) < 1 || (string) ($row['stock_location_status'] ?? '') !== 'active') {
            throw new SaleValidationException('sale.pos_stock_location_required');
        }
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function allowedPaymentMethods(int $registerId): array
    {
        $register = $this->rawDatabase()->one('SELECT site_id,channel_id FROM sale_pos_registers WHERE id=?', [$registerId]);
        if ($register === null) {
            return [];
        }
        $configured = $this->rawDatabase()->all(
            "SELECT m.* FROM sale_payment_methods m
             INNER JOIN sale_pos_register_payment_methods rpm ON rpm.payment_method_id=m.id
             WHERE rpm.register_id=? AND m.status='active' AND m.archived_at IS NULL
               AND m.method_type IN ('cash','manual_card','external_terminal') ORDER BY m.code ASC",
            [$registerId]
        );
        if ($configured !== []) {
            return $configured;
        }
        return $this->rawDatabase()->all(
            "SELECT * FROM sale_payment_methods
             WHERE site_id=? AND (channel_id IS NULL OR channel_id=?) AND status='active' AND archived_at IS NULL
               AND method_type IN ('cash','manual_card','external_terminal') ORDER BY code ASC",
            [(int) $register['site_id'], (int) $register['channel_id']]
        );
    }

    /** @param list<int> $paymentMethodIds @return array<string,mixed> */
    public function configureRegister(int $registerId, int $siteId, array $payload, array $paymentMethodIds): array
    {
        $register = $this->requireRegister($registerId, $siteId);
        return $this->rawDatabase()->transaction(function () use ($register, $siteId, $payload, $paymentMethodIds): array {
            $currency = strtoupper(trim((string) ($payload['currency'] ?? $register['currency'])));
            $locale = strtolower(trim((string) ($payload['locale'] ?? $register['locale'])));
            if (!preg_match('/^[A-Z]{3}$/', $currency) || !in_array($locale, ['fr', 'en'], true)) {
                throw new SaleValidationException('sale.pos_register_context_invalid');
            }
            $this->rawDatabase()->run(
                'UPDATE sale_pos_registers SET currency=?,locale=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [$currency, $locale, (int) $register['id']]
            );
            if (array_key_exists('payment_method_ids', $payload)) {
                if ($paymentMethodIds === []) {
                    throw new SaleValidationException('sale.pos_payment_method_required');
                }
                $this->rawDatabase()->run('DELETE FROM sale_pos_register_payment_methods WHERE register_id=?', [(int) $register['id']]);
                foreach (array_values(array_unique($paymentMethodIds)) as $methodId) {
                    $method = $this->rawDatabase()->one(
                        "SELECT id FROM sale_payment_methods WHERE id=? AND site_id=? AND status='active' AND method_type IN ('cash','manual_card','external_terminal')",
                        [$methodId, $siteId]
                    );
                    if ($method === null) {
                        throw new SaleValidationException('sale.pos_payment_method_not_allowed');
                    }
                    $this->rawDatabase()->run(
                        'INSERT INTO sale_pos_register_payment_methods(register_id,payment_method_id) VALUES(?,?)',
                        [(int) $register['id'], $methodId]
                    );
                }
            }
            foreach ($this->registers($siteId) as $configured) {
                if ((int) $configured['id'] === (int) $register['id']) {
                    return $configured;
                }
            }
            return $this->requireRegister((int) $register['id'], $siteId);
        });
    }

    /** @return array<string,mixed>|null */
    public function activeSession(int $siteId, ?int $registerId = null): ?array
    {
        $filter = $registerId === null ? '' : ' AND r.id=:register_id';
        return $this->rawDatabase()->one(
            "SELECT s.*,r.code AS register_code,r.name AS register_name
             FROM sale_cash_sessions s INNER JOIN sale_pos_registers r ON r.id=s.register_id
             WHERE r.site_id=:site_id AND s.status IN ('open','closing'){$filter}
             ORDER BY s.id DESC LIMIT 1",
            ['site_id' => $siteId] + ($registerId === null ? [] : ['register_id' => $registerId])
        );
    }

    /** @return array<string,mixed> */
    public function requireSession(int $sessionId, ?int $siteId = null): array
    {
        $siteFilter = $siteId === null ? '' : ' AND r.site_id=:site_id';
        $row = $this->rawDatabase()->one(
            "SELECT s.*,r.site_id,r.code AS register_code,r.name AS register_name
             FROM sale_cash_sessions s INNER JOIN sale_pos_registers r ON r.id=s.register_id
             WHERE s.id=:id{$siteFilter} LIMIT 1",
            ['id' => $sessionId] + ($siteId === null ? [] : ['site_id' => $siteId])
        );
        if ($row === null) {
            throw new SaleValidationException('sale.cash_session_not_found');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    public function openSession(array $register, ?int $deviceId, int $actorId, int $openingMinor): array
    {
        if ($deviceId !== null) {
            $device = $this->rawDatabase()->one(
                "SELECT id FROM sale_pos_devices WHERE id=? AND register_id=? AND status='active'",
                [$deviceId, (int) $register['id']]
            );
            if ($device === null) {
                throw new SaleValidationException('sale.pos_device_not_found');
            }
        }
        return $this->rawDatabase()->transaction(function () use ($register, $deviceId, $actorId, $openingMinor): array {
            $this->rawDatabase()->run(
                'INSERT INTO sale_cash_sessions(register_id,channel_id,stock_location_id,device_id,opened_by_iam_user_id,opening_cash_minor,expected_cash_minor,currency,locale)
                 VALUES(?,?,?,?,?,?,?,?,?)',
                [(int) $register['id'], (int) $register['channel_id'], (int) $register['stock_location_id'], $deviceId, $actorId, $openingMinor, $openingMinor, (string) $register['currency'], (string) $register['locale']]
            );
            $sessionId = (int) $this->rawDatabase()->lastInsertId();
            $this->recordMovement($sessionId, 'opening', $openingMinor, (string) $register['currency'], 'session opening', $actorId);
            return $this->requireSession($sessionId, (int) $register['site_id']);
        });
    }

    /** @return array<string,mixed> */
    public function closeSession(int $sessionId, int $siteId, int $actorId, int $countedMinor, ?string $justification, ?string $notes): array
    {
        $session = $this->requireSession($sessionId, $siteId);
        if ((string) $session['status'] !== 'open') {
            throw new SaleValidationException('sale.cash_session_not_open');
        }
        $difference = $countedMinor - (int) $session['expected_cash_minor'];
        if ($difference !== 0 && trim((string) $justification) === '') {
            throw new SaleValidationException('sale.cash_difference_justification_required');
        }
        return $this->rawDatabase()->transaction(function () use ($session, $actorId, $countedMinor, $difference, $justification, $notes, $siteId): array {
            $this->rawDatabase()->run(
                "UPDATE sale_cash_sessions SET status='closed',closed_by_iam_user_id=?,counted_cash_minor=?,difference_minor=?,difference_justification=?,closed_at=CURRENT_TIMESTAMP,notes=? WHERE id=? AND status='open'",
                [$actorId, $countedMinor, $difference, trim((string) $justification) ?: null, $notes, (int) $session['id']]
            );
            $this->recordMovement((int) $session['id'], 'closing', $countedMinor, (string) $session['currency'], 'session closing', $actorId);
            return $this->requireSession((int) $session['id'], $siteId);
        });
    }

    public function recordMovement(int $sessionId, string $type, int $amountMinor, string $currency, string $reason, int $actorId, ?int $orderId = null): void
    {
        $this->rawDatabase()->run(
            'INSERT INTO sale_cash_movements(cash_session_id,movement_type,amount_minor,currency,reason,order_id,created_by_iam_user_id) VALUES(?,?,?,?,?,?,?)',
            [$sessionId, $type, $amountMinor, $currency, $reason, $orderId, $actorId]
        );
    }

    public function applyCashDelta(int $sessionId, int $siteId, string $type, int $deltaMinor, string $reason, int $actorId): array
    {
        $session = $this->requireSession($sessionId, $siteId);
        if ((string) $session['status'] !== 'open' || !in_array($type, ['cash_in', 'cash_out', 'correction'], true) || $deltaMinor === 0 || trim($reason) === '') {
            throw new SaleValidationException('sale.cash_movement_invalid');
        }
        if ($type === 'cash_in') $deltaMinor = abs($deltaMinor);
        if ($type === 'cash_out') $deltaMinor = -abs($deltaMinor);
        $nextExpected = (int) $session['expected_cash_minor'] + $deltaMinor;
        if ($nextExpected < 0) {
            throw new SaleValidationException('sale.cash_expected_negative');
        }
        $this->rawDatabase()->transaction(function () use ($session, $type, $deltaMinor, $reason, $actorId): void {
            $this->rawDatabase()->run('UPDATE sale_cash_sessions SET expected_cash_minor=expected_cash_minor+? WHERE id=?', [$deltaMinor, (int) $session['id']]);
            $this->recordMovement((int) $session['id'], $type, $deltaMinor, (string) $session['currency'], trim($reason), $actorId);
        });
        return $this->requireSession($sessionId, $siteId);
    }

    public function attachOrderContext(int $orderId, array $session, int $operatorId): void
    {
        $this->rawDatabase()->run(
            'UPDATE sale_orders SET stock_location_id=?,pos_register_id=?,pos_device_id=?,pos_session_id=?,pos_operator_iam_user_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND source=\'pos\'',
            [(int) $session['stock_location_id'], (int) $session['register_id'], $session['device_id'] ?? null, (int) $session['id'], $operatorId, $orderId]
        );
    }

    public function logReceiptAction(int $receiptId, string $action, ?int $sessionId, int $actorId, ?string $reason = null): void
    {
        $this->rawDatabase()->run(
            'INSERT INTO sale_receipt_actions(receipt_id,action_type,pos_session_id,operator_iam_user_id,reason) VALUES(?,?,?,?,?)',
            [$receiptId, $action, $sessionId, $actorId, trim((string) $reason) ?: null]
        );
    }
}
