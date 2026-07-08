<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Core\ErrorCode;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Sale\Exceptions\SaleBusinessException;
use App\Modules\Sale\Exceptions\SaleInventoryException;
use App\Modules\Sale\Exceptions\SalePaymentException;
use App\Modules\Sale\Exceptions\SaleValidationException;
use App\Modules\Sale\Repositories\SaleCartRepository;
use App\Modules\Sale\Repositories\SaleChannelRepository;
use App\Modules\Sale\Repositories\SaleInventoryRepository;
use App\Modules\Sale\Repositories\SaleOrderRepository;
use App\Modules\Sale\Repositories\SalePaymentRepository;
use App\Modules\Sale\Services\SaleCatalogSnapshotService;
use App\Modules\Sale\Services\SaleCartService;
use App\Modules\Sale\Services\SaleCheckoutService;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Modules\Sale\Services\SaleOrderService;
use App\Modules\Sale\Services\SalePaymentService;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;
use InvalidArgumentException;
use Throwable;

final class SaleAdminApiController
{
    public function __construct(
        private readonly Request $request,
        private readonly SiteRepository $sites,
        private readonly AuthRepository $auth,
        private readonly Authorization $authorization,
        private readonly SaleDatabaseConnection $sale,
        private readonly SaleChannelRepository $channels,
        private readonly SaleCartRepository $carts,
        private readonly SaleOrderRepository $orders,
        private readonly SalePaymentRepository $payments,
        private readonly SaleInventoryRepository $inventory,
        private readonly SaleCatalogSnapshotService $catalogSnapshots,
        private readonly SaleCartService $cartService,
        private readonly SaleCheckoutService $checkout,
        private readonly SalePaymentService $paymentService,
        private readonly SaleOrderService $orderService,
    ) {}

    public function schema(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.read');
        return $this->ok([
            'module' => 'sale',
            'scope' => 'admin',
            'headless_public' => false,
            'resources' => ['channels', 'carts', 'orders', 'payments', 'pos', 'stock', 'reports'],
            'idempotent_actions' => ['cart.add_line', 'checkout.place_order', 'payment.capture', 'pos.complete_sale'],
        ], 'admin.sale.schema.v1', $site, $languageCode);
    }

    public function posBootstrap(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.pos.use');
        $siteId = (int) $site['id'];
        $registers = $this->db()->all('SELECT * FROM sale_pos_registers WHERE site_id = ? AND status = "active" ORDER BY code ASC', [$siteId]);
        $channels = $this->db()->all('SELECT * FROM sale_channels WHERE site_id = ? AND channel_type = "pos" ORDER BY code ASC', [$siteId]);
        $session = $this->db()->one(
            'SELECT s.* FROM sale_cash_sessions s INNER JOIN sale_pos_registers r ON r.id = s.register_id WHERE r.site_id = ? AND s.status IN ("open","closing") ORDER BY s.id DESC LIMIT 1',
            [$siteId]
        );
        return $this->ok([
            'site_id' => $siteId,
            'channels' => $channels,
            'registers' => $registers,
            'active_session' => $session,
            'payment_methods' => $this->payments->methods($siteId),
        ], 'admin.sale.pos.bootstrap.v1', $site, $languageCode);
    }

    public function posCatalog(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.pos.use');
        $result = $this->posSellables((int) $site['id'], [
            'q' => $this->request->query['q'] ?? '',
            'limit' => $this->limit(),
            'offset' => $this->offset(),
        ]);
        return $this->ok(['variants' => $result['items'], 'pagination' => $this->pagination($result)], 'admin.sale.pos.catalog.v1', $site, $languageCode);
    }

    public function posVariants(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.pos.use');
        $filters = [
            'sku' => $this->request->query['sku'] ?? '',
            'barcode' => $this->request->query['barcode'] ?? '',
            'q' => $this->request->query['q'] ?? '',
            'limit' => $this->limit(),
            'offset' => $this->offset(),
        ];
        $result = $this->posSellables((int) $site['id'], $filters);
        return $this->ok(['variants' => $result['items'], 'pagination' => $this->pagination($result)], 'admin.sale.pos.variants.v1', $site, $languageCode);
    }

    public function dashboard(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.read');
        $db = $this->db();
        $siteId = (int) $site['id'];
        return $this->ok([
            'orders' => $this->count('sale_orders', $siteId),
            'active_carts' => (int) ($db->one('SELECT COUNT(*) AS count FROM sale_carts WHERE site_id = ? AND status = "active"', [$siteId])['count'] ?? 0),
            'paid_total_minor' => (int) ($db->one('SELECT COALESCE(SUM(paid_total_minor), 0) AS total FROM sale_orders WHERE site_id = ?', [$siteId])['total'] ?? 0),
            'today_sales_minor' => (int) ($db->one('SELECT COALESCE(SUM(grand_total_minor), 0) AS total FROM sale_orders WHERE site_id = ? AND date(placed_at) = date("now") AND status <> "cancelled"', [$siteId])['total'] ?? 0),
            'recent_orders' => $db->all('SELECT * FROM sale_orders WHERE site_id = ? ORDER BY placed_at DESC, id DESC LIMIT 6', [$siteId]),
            'recent_payments' => $db->all(
                'SELECT t.*, o.order_number
                 FROM sale_payment_transactions t
                 INNER JOIN sale_orders o ON o.id = t.order_id
                 WHERE o.site_id = ?
                 ORDER BY t.created_at DESC, t.id DESC
                 LIMIT 6',
                [$siteId]
            ),
            'open_cash_sessions' => $db->all(
                'SELECT s.*, r.name AS register_name, r.code AS register_code
                 FROM sale_cash_sessions s
                 INNER JOIN sale_pos_registers r ON r.id = s.register_id
                 WHERE r.site_id = ? AND s.status IN ("open","closing")
                 ORDER BY s.opened_at DESC, s.id DESC',
                [$siteId]
            ),
            'channels' => $this->count('sale_channels', $siteId),
        ], 'admin.sale.dashboard.v1', $site, $languageCode);
    }

    public function channels(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.settings.manage');
        $result = $this->channels->list((int) $site['id'], $this->request->query, $this->limit(), $this->offset());
        return $this->ok(['channels' => $result['items'], 'pagination' => $this->pagination($result)], 'admin.sale.channels.index.v1', $site, $languageCode);
    }

    public function storeChannel(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.settings.manage');
        try {
            $channel = $this->channels->create((int) $site['id'], $this->payload(), $this->actorId());
            return $this->ok(['channel' => $channel, 'message' => 'Canal Vente créé.'], 'admin.sale.channels.show.v1', $site, $languageCode, 201);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function showChannel(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('sale.settings.manage');
        try {
            return $this->ok(['channel' => $this->channels->requireChannel((int) $site['id'], $this->id($id))], 'admin.sale.channels.show.v1', $site, $languageCode);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function updateChannel(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('sale.settings.manage');
        try {
            $channel = $this->channels->update((int) $site['id'], $this->id($id), $this->payload(), $this->actorId());
            return $this->ok(['channel' => $channel, 'message' => 'Canal Vente mis à jour.'], 'admin.sale.channels.show.v1', $site, $languageCode);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function archiveChannel(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('sale.settings.manage');
        try {
            $channel = $this->channels->archive((int) $site['id'], $this->id($id), $this->actorId());
            return $this->ok(['channel' => $channel, 'archived' => true], 'admin.sale.channels.archive.v1', $site, $languageCode);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function carts(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.orders.read');
        $result = $this->carts->list((int) $site['id'], $this->request->query, $this->limit(), $this->offset());
        return $this->ok(['carts' => $result['items'], 'pagination' => $this->pagination($result)], 'admin.sale.carts.index.v1', $site, $languageCode);
    }

    public function storeCart(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.orders.manage');
        try {
            $payload = $this->payload();
            $cart = $this->cartService->createCart((int) $site['id'], (int) ($payload['channel_id'] ?? 0), $payload + ['iam_user_id' => $this->actorId()]);
            return $this->ok(['cart' => $cart], 'admin.sale.carts.show.v1', $site, $languageCode, 201);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function showCart(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('sale.orders.read');
        try {
            $cart = $this->carts->cartWithLines($this->id($id));
            $this->ensureSite((int) $site['id'], (int) $cart['site_id']);
            return $this->ok(['cart' => $cart], 'admin.sale.carts.show.v1', $site, $languageCode);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function addCartLine(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('sale.orders.manage');
        try {
            $payload = $this->payload();
            $cart = $this->carts->requireCart($this->id($id));
            $this->ensureSite((int) $site['id'], (int) $cart['site_id']);
            $result = $this->cartService->addLine($this->id($id), (int) ($payload['business_variant_id'] ?? $payload['variant_id'] ?? 0), (int) ($payload['quantity'] ?? 1), [
                'idempotency_key' => $this->idempotencyKey($payload),
                'iam_user_id' => $this->actorId(),
            ]);
            return $this->ok($result, 'admin.sale.carts.lines.store.v1', $site, $languageCode, 201);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function updateCartLine(string|int $id, string|int $line_id): Response
    {
        [$site, $languageCode] = $this->authorize('sale.orders.manage');
        try {
            $cart = $this->carts->requireCart($this->id($id));
            $this->ensureSite((int) $site['id'], (int) $cart['site_id']);
            $line = $this->carts->updateLineQuantity($this->id($id), $this->id($line_id), (int) ($this->payload()['quantity'] ?? 1));
            return $this->ok(['line' => $line, 'cart' => $this->carts->requireCart($this->id($id))], 'admin.sale.carts.lines.update.v1', $site, $languageCode);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function deleteCartLine(string|int $id, string|int $line_id): Response
    {
        [$site, $languageCode] = $this->authorize('sale.orders.manage');
        try {
            $cart = $this->carts->requireCart($this->id($id));
            $this->ensureSite((int) $site['id'], (int) $cart['site_id']);
            $this->carts->deleteLine($this->id($id), $this->id($line_id));
            return $this->ok(['deleted' => true, 'cart' => $this->carts->requireCart($this->id($id))], 'admin.sale.carts.lines.delete.v1', $site, $languageCode);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function recalculateCart(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('sale.orders.manage');
        try {
            $cart = $this->carts->requireCart($this->id($id));
            $this->ensureSite((int) $site['id'], (int) $cart['site_id']);
            $totals = $this->carts->recalculateTotals($this->id($id));
            return $this->ok(['totals' => $totals, 'cart' => $this->carts->requireCart($this->id($id))], 'admin.sale.carts.recalculate.v1', $site, $languageCode);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function checkoutCart(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('sale.orders.manage');
        try {
            $cart = $this->carts->requireCart($this->id($id));
            $this->ensureSite((int) $site['id'], (int) $cart['site_id']);
            $payload = $this->payload();
            $order = $this->checkout->placeOrder($this->id($id), [
                'idempotency_key' => $this->idempotencyKey($payload),
                'source' => $payload['source'] ?? 'admin',
                'iam_user_id' => $this->actorId(),
            ]);
            return $this->ok(['order' => $order], 'admin.sale.carts.checkout.v1', $site, $languageCode, 201);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function orders(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.orders.read');
        $result = $this->orders->list((int) $site['id'], $this->request->query, $this->limit(), $this->offset());
        return $this->ok(['orders' => $result['items'], 'pagination' => $this->pagination($result)], 'admin.sale.orders.index.v1', $site, $languageCode);
    }

    public function showOrder(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('sale.orders.read');
        try {
            $order = $this->orders->orderWithLines($this->id($id));
            $this->ensureSite((int) $site['id'], (int) $order['site_id']);
            return $this->ok(['order' => $order], 'admin.sale.orders.show.v1', $site, $languageCode);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function storeOrder(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.orders.manage');
        try {
            $payload = $this->payload();
            $cart = $this->cartService->createCart((int) $site['id'], (int) ($payload['channel_id'] ?? 0), $payload + ['iam_user_id' => $this->actorId()]);
            foreach (($payload['lines'] ?? []) as $line) {
                if (is_array($line)) {
                    $this->cartService->addLine((int) $cart['id'], (int) ($line['business_variant_id'] ?? $line['variant_id'] ?? 0), (int) ($line['quantity'] ?? 1), ['iam_user_id' => $this->actorId()]);
                }
            }
            $order = $this->checkout->placeOrder((int) $cart['id'], ['source' => $payload['source'] ?? 'admin', 'iam_user_id' => $this->actorId()]);
            return $this->ok(['order' => $order], 'admin.sale.orders.show.v1', $site, $languageCode, 201);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function updateOrder(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('sale.orders.manage');
        try {
            $order = $this->orders->requireOrder($this->id($id));
            $this->ensureSite((int) $site['id'], (int) $order['site_id']);
            $payload = $this->payload();
            if (isset($payload['status']) && in_array($payload['status'], ['placed', 'confirmed', 'completed'], true)) {
                $this->db()->run('UPDATE sale_orders SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?', [(string) $payload['status'], $this->id($id)]);
            }
            return $this->ok(['order' => $this->orders->orderWithLines($this->id($id))], 'admin.sale.orders.show.v1', $site, $languageCode);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function cancelOrder(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('sale.orders.manage');
        try {
            $order = $this->orders->requireOrder($this->id($id));
            $this->ensureSite((int) $site['id'], (int) $order['site_id']);
            return $this->ok(['order' => $this->orderService->cancelOrder($this->id($id), $this->actorId(), $this->payload()['reason'] ?? null)], 'admin.sale.orders.cancel.v1', $site, $languageCode);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function orderEvents(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('sale.orders.read');
        try {
            $order = $this->orders->requireOrder($this->id($id));
            $this->ensureSite((int) $site['id'], (int) $order['site_id']);
            return $this->ok(['events' => $this->orders->events($this->id($id))], 'admin.sale.orders.events.v1', $site, $languageCode);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function orderReceipt(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('sale.orders.read');
        try {
            $order = $this->orders->requireOrder($this->id($id));
            $this->ensureSite((int) $site['id'], (int) $order['site_id']);
            $receipt = $this->db()->one('SELECT * FROM sale_receipts WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$this->id($id)]);
            return $this->ok(['receipt' => $receipt], 'admin.sale.orders.receipt.v1', $site, $languageCode);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function payments(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.payments.read');
        $items = $this->db()->all(
            'SELECT t.* FROM sale_payment_transactions t INNER JOIN sale_orders o ON o.id = t.order_id WHERE o.site_id = ? ORDER BY t.id DESC LIMIT ? OFFSET ?',
            [(int) $site['id'], $this->limit(), $this->offset()]
        );
        return $this->ok(['payments' => $items], 'admin.sale.payments.index.v1', $site, $languageCode);
    }

    public function paymentMethods(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.payments.read');
        return $this->ok(['payment_methods' => $this->payments->methods((int) $site['id'])], 'admin.sale.payment_methods.index.v1', $site, $languageCode);
    }

    public function storePaymentMethod(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.payments.manage');
        try {
            return $this->ok(['payment_method' => $this->payments->createMethod((int) $site['id'], $this->payload())], 'admin.sale.payment_methods.store.v1', $site, $languageCode, 201);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function storeOrderPayment(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('sale.payments.manage');
        try {
            $order = $this->orders->requireOrder($this->id($id));
            $this->ensureSite((int) $site['id'], (int) $order['site_id']);
            $payload = $this->payload();
            return $this->ok($this->paymentService->recordManualPayment($this->id($id), (int) ($payload['amount_minor'] ?? 0), $this->actorId()), 'admin.sale.orders.payments.store.v1', $site, $languageCode, 201);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function orderPayments(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('sale.payments.read');
        try {
            $order = $this->orders->requireOrder($this->id($id));
            $this->ensureSite((int) $site['id'], (int) $order['site_id']);
            return $this->ok(['payments' => $this->payments->orderPayments($this->id($id))], 'admin.sale.orders.payments.index.v1', $site, $languageCode);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function refundPayment(string|int $transaction_id): Response
    {
        [$site, $languageCode] = $this->authorize('sale.refunds.manage');
        try {
            $tx = $this->db()->one('SELECT t.*, o.site_id FROM sale_payment_transactions t INNER JOIN sale_orders o ON o.id = t.order_id WHERE t.id = ?', [$this->id($transaction_id)]);
            if ($tx === null) {
                throw new SalePaymentException('sale.payment_transaction_not_found');
            }
            $this->ensureSite((int) $site['id'], (int) $tx['site_id']);
            $amount = (int) ($this->payload()['amount_minor'] ?? $tx['amount_minor']);
            $this->db()->run(
                'INSERT INTO sale_refunds(order_id, payment_transaction_id, refund_number, status, amount_minor, currency, reason, created_by_iam_user_id)
                 VALUES(?, ?, ?, "pending", ?, ?, ?, ?)',
                [(int) $tx['order_id'], (int) $tx['id'], 'REF-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3)), $amount, (string) $tx['currency'], $this->payload()['reason'] ?? null, $this->actorId()]
            );
            return $this->ok(['refund' => $this->db()->one('SELECT * FROM sale_refunds WHERE id = ?', [(int) $this->db()->lastInsertId()])], 'admin.sale.payments.refund.v1', $site, $languageCode, 201);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function posRegisters(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.pos.use');
        return $this->ok(['registers' => $this->db()->all('SELECT * FROM sale_pos_registers WHERE site_id = ? ORDER BY code ASC', [(int) $site['id']])], 'admin.sale.pos.registers.v1', $site, $languageCode);
    }

    public function openCashSession(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.cash.manage');
        try {
            $payload = $this->payload();
            $registerId = (int) ($payload['register_id'] ?? $this->defaultRegisterId((int) $site['id']));
            $register = $this->db()->one('SELECT * FROM sale_pos_registers WHERE id = ? AND site_id = ? AND status = "active"', [$registerId, (int) $site['id']]);
            if ($register === null) {
                throw new SaleValidationException('sale.pos_register_not_found');
            }
            $this->db()->run(
                'INSERT INTO sale_cash_sessions(register_id, opened_by_iam_user_id, opening_cash_minor, expected_cash_minor, currency)
                 VALUES(?, ?, ?, ?, ?)',
                [$registerId, $this->actorId(), max(0, (int) ($payload['opening_cash_minor'] ?? 0)), max(0, (int) ($payload['opening_cash_minor'] ?? 0)), (string) ($register['currency'] ?? 'CHF')]
            );
            $sessionId = (int) $this->db()->lastInsertId();
            $this->db()->run(
                'INSERT INTO sale_cash_movements(cash_session_id, movement_type, amount_minor, currency, reason, created_by_iam_user_id)
                 VALUES(?, "opening", ?, ?, "session opening", ?)',
                [$sessionId, max(0, (int) ($payload['opening_cash_minor'] ?? 0)), (string) ($register['currency'] ?? 'CHF'), $this->actorId()]
            );
            return $this->ok(['session' => $this->cashSession($sessionId)], 'admin.sale.pos.sessions.open.v1', $site, $languageCode, 201);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function closeCashSession(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('sale.cash.manage');
        try {
            $payload = $this->payload();
            $session = $this->cashSession($this->id($id));
            $register = $this->db()->one('SELECT * FROM sale_pos_registers WHERE id = ? AND site_id = ?', [(int) $session['register_id'], (int) $site['id']]);
            if ($register === null) {
                throw new SaleValidationException('sale.pos_session_not_found');
            }
            $counted = max(0, (int) ($payload['counted_cash_minor'] ?? $session['expected_cash_minor']));
            $difference = $counted - (int) $session['expected_cash_minor'];
            $this->db()->run(
                'UPDATE sale_cash_sessions
                 SET status = "closed", closed_by_iam_user_id = ?, counted_cash_minor = ?,
                     difference_minor = ?, closed_at = CURRENT_TIMESTAMP, notes = ?
                 WHERE id = ?',
                [$this->actorId(), $counted, $difference, $payload['notes'] ?? null, (int) $session['id']]
            );
            $this->db()->run(
                'INSERT INTO sale_cash_movements(cash_session_id, movement_type, amount_minor, currency, reason, created_by_iam_user_id)
                 VALUES(?, "closing", ?, ?, "session closing", ?)',
                [(int) $session['id'], $counted, (string) $session['currency'], $this->actorId()]
            );
            return $this->ok(['session' => $this->cashSession((int) $session['id'])], 'admin.sale.pos.sessions.close.v1', $site, $languageCode);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function posStoreCart(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.pos.use');
        try {
            $payload = $this->payload();
            $channelId = (int) ($payload['channel_id'] ?? $this->defaultPosChannelId((int) $site['id']));
            $cart = $this->cartService->createCart((int) $site['id'], $channelId, $payload + ['iam_user_id' => $this->actorId()]);
            return $this->ok(['cart' => $cart], 'admin.sale.pos.carts.store.v1', $site, $languageCode, 201);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function posAddCartLine(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('sale.pos.use');
        try {
            $payload = $this->payload();
            $cart = $this->carts->requireCart($this->id($id));
            $this->ensureSite((int) $site['id'], (int) $cart['site_id']);
            $result = $this->cartService->addLine($this->id($id), (int) ($payload['business_variant_id'] ?? $payload['variant_id'] ?? 0), (int) ($payload['quantity'] ?? 1), [
                'idempotency_key' => $this->idempotencyKey($payload),
                'iam_user_id' => $this->actorId(),
            ]);
            return $this->ok($result, 'admin.sale.pos.carts.lines.store.v1', $site, $languageCode, 201);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function posUpdateCartLine(string|int $id, string|int $line_id): Response
    {
        [$site, $languageCode] = $this->authorize('sale.pos.use');
        try {
            $cart = $this->carts->requireCart($this->id($id));
            $this->ensureSite((int) $site['id'], (int) $cart['site_id']);
            $line = $this->carts->updateLineQuantity($this->id($id), $this->id($line_id), (int) ($this->payload()['quantity'] ?? 1));
            return $this->ok(['line' => $line, 'cart' => $this->carts->requireCart($this->id($id))], 'admin.sale.pos.carts.lines.update.v1', $site, $languageCode);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function posCheckout(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.pos.use');
        try {
            $payload = $this->payload();
            $sessionId = (int) ($payload['cash_session_id'] ?? $payload['session_id'] ?? 0);
            $paymentMethod = (string) ($payload['payment_method'] ?? 'cash');
            if ($paymentMethod === 'cash') {
                $session = $this->cashSession($sessionId);
                if ((string) $session['status'] !== 'open') {
                    throw new SaleValidationException('sale.cash_session_required');
                }
                $register = $this->db()->one('SELECT * FROM sale_pos_registers WHERE id = ? AND site_id = ?', [(int) $session['register_id'], (int) $site['id']]);
                if ($register === null) {
                    throw new SaleValidationException('sale.cash_session_required');
                }
            }
            $cart = $this->carts->requireCart((int) ($payload['cart_id'] ?? 0));
            $this->ensureSite((int) $site['id'], (int) $cart['site_id']);
            $order = $this->checkout->placeOrder((int) $cart['id'], [
                'idempotency_key' => $this->idempotencyKey($payload),
                'source' => 'pos',
                'iam_user_id' => $this->actorId(),
            ]);
            $freshOrder = $this->orders->requireOrder((int) $order['id']);
            $dueMinor = max(0, (int) $freshOrder['grand_total_minor'] - (int) $freshOrder['paid_total_minor']);
            $paid = $dueMinor > 0
                ? $this->paymentService->recordManualPayment((int) $order['id'], $dueMinor, $this->actorId())
                : ['order' => $freshOrder, 'transaction' => null];
            if ($paymentMethod === 'cash') {
                if ($dueMinor > 0) {
                    $this->db()->run(
                        'UPDATE sale_cash_sessions SET expected_cash_minor = expected_cash_minor + ? WHERE id = ?',
                        [$dueMinor, $sessionId]
                    );
                    $this->db()->run(
                        'INSERT INTO sale_cash_movements(cash_session_id, movement_type, amount_minor, currency, order_id, reason, created_by_iam_user_id)
                         VALUES(?, "cash_sale", ?, ?, ?, "POS checkout", ?)',
                        [$sessionId, $dueMinor, (string) $order['currency'], (int) $order['id'], $this->actorId()]
                    );
                }
            }
            return $this->ok(['order' => $paid['order'], 'transaction' => $paid['transaction'], 'receipt' => $this->receiptPayload((int) $order['id'])], 'admin.sale.pos.checkout.v1', $site, $languageCode, 201);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function posOrderReceipt(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('sale.pos.use');
        try {
            $order = $this->orders->requireOrder($this->id($id));
            $this->ensureSite((int) $site['id'], (int) $order['site_id']);
            return $this->ok(['receipt' => $this->receiptPayload($this->id($id))], 'admin.sale.pos.receipt.v1', $site, $languageCode);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function stock(): Response { return $this->stockItems(); }

    public function stockItems(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.stock.read');
        $result = $this->inventory->listItems((int) $site['id'], $this->limit(), $this->offset());
        return $this->ok(['items' => $result['items'], 'pagination' => $this->pagination($result)], 'admin.sale.stock.items.v1', $site, $languageCode);
    }

    public function stockAdjustments(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.stock.manage');
        try {
            $payload = $this->payload();
            $item = $this->inventory->adjust((int) $site['id'], (int) ($payload['business_variant_id'] ?? $payload['variant_id'] ?? 0), (int) ($payload['quantity_delta'] ?? 0), $payload['sku'] ?? null, $payload['reason'] ?? null, $this->actorId());
            return $this->ok(['item' => $item], 'admin.sale.stock.adjustments.v1', $site, $languageCode, 201);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function stockMovements(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.stock.read');
        $result = $this->inventory->movements((int) $site['id'], $this->limit(), $this->offset());
        return $this->ok(['movements' => $result['items'], 'pagination' => $this->pagination($result)], 'admin.sale.stock.movements.v1', $site, $languageCode);
    }

    public function returns(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.orders.read');
        return $this->ok(['returns' => $this->db()->all('SELECT r.* FROM sale_returns r INNER JOIN sale_orders o ON o.id = r.order_id WHERE o.site_id = ? ORDER BY r.id DESC', [(int) $site['id']])], 'admin.sale.returns.index.v1', $site, $languageCode);
    }

    public function settings(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.settings.manage');
        return $this->ok(['settings' => $this->db()->all('SELECT * FROM sale_settings WHERE site_id = ? ORDER BY setting_key ASC', [(int) $site['id']])], 'admin.sale.settings.v1', $site, $languageCode);
    }

    public function dailyReport(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.reports.read');
        return $this->ok(['report' => ['date' => gmdate('Y-m-d'), 'orders' => $this->count('sale_orders', (int) $site['id'])]], 'admin.sale.reports.daily.v1', $site, $languageCode);
    }

    public function ordersReport(): Response { return $this->orders(); }

    public function posSessionsReport(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.reports.read');
        return $this->ok(['sessions' => $this->db()->all(
            'SELECT s.* FROM sale_cash_sessions s INNER JOIN sale_pos_registers r ON r.id = s.register_id WHERE r.site_id = ? ORDER BY s.id DESC',
            [(int) $site['id']]
        )], 'admin.sale.reports.pos_sessions.v1', $site, $languageCode);
    }

    /** @return array{0:array<string,mixed>,1:string} */
    private function authorize(string $permission): array
    {
        $this->auth->requireAuth();
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null, $this->auth);
        $this->authorization->require($permission, (int) $site['id']);
        return [$site, AdminApiContract::language($this->request, $this->sites, $site)];
    }

    private function db(): \App\Core\Database
    {
        $db = $this->sale->database();
        if ($db === null) {
            throw new SaleBusinessException('sale.database_unavailable');
        }
        return $db;
    }

    /** @param array<string,mixed> $site */
    private function ok(array $data, string $contract, array $site, string $languageCode, int $status = 200): Response
    {
        return Response::success($data, $contract, ['site_id' => (int) $site['id'], 'language' => $languageCode], $status);
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        $json = $this->request->json();
        if ($json === [] && $this->request->post !== []) {
            $json = $this->request->post;
        }
        $data = $json['data'] ?? $json;
        return is_array($data) ? $data : [];
    }

    private function actorId(): int
    {
        return (int) ($this->auth->user()['id'] ?? 0);
    }

    private function id(string|int $id): int
    {
        $value = (int) $id;
        if ($value < 1) {
            throw new SaleValidationException('sale.id_invalid');
        }
        return $value;
    }

    private function limit(): int { return max(1, min(100, (int) ($this->request->query['limit'] ?? 50))); }

    private function offset(): int { return max(0, (int) ($this->request->query['offset'] ?? 0)); }

    /** @param array<string,mixed> $result @return array<string,int|bool> */
    private function pagination(array $result): array
    {
        return [
            'limit' => (int) $result['limit'],
            'offset' => (int) $result['offset'],
            'total' => (int) $result['total'],
            'has_more' => (bool) $result['has_more'],
        ];
    }

    private function ensureSite(int $expectedSiteId, int $actualSiteId): void
    {
        if ($expectedSiteId !== $actualSiteId) {
            throw new SaleValidationException('sale.resource_not_found');
        }
    }

    /** @param array<string,mixed> $payload */
    private function idempotencyKey(array $payload): ?string
    {
        $header = $this->request->header('Idempotency-Key');
        return trim((string) ($payload['idempotency_key'] ?? $header ?? '')) ?: null;
    }

    private function count(string $table, int $siteId): int
    {
        if (!preg_match('/^sale_[a-z_]+$/', $table)) {
            return 0;
        }
        return (int) ($this->db()->one('SELECT COUNT(*) AS count FROM ' . $table . ' WHERE site_id = ?', [$siteId])['count'] ?? 0);
    }

    /** @return array{items:list<array<string,mixed>>,limit:int,offset:int,total:int,has_more:bool} */
    private function posSellables(int $siteId, array $filters): array
    {
        return $this->catalogSnapshots->searchSellableVariants($siteId, $filters + ['channel' => 'pos']);
    }

    private function defaultPosChannelId(int $siteId): int
    {
        $row = $this->db()->one('SELECT id FROM sale_channels WHERE site_id = ? AND channel_type = "pos" ORDER BY status = "active" DESC, id ASC LIMIT 1', [$siteId]);
        if ($row === null) {
            throw new SaleValidationException('sale.pos_channel_not_found');
        }
        return (int) $row['id'];
    }

    private function defaultRegisterId(int $siteId): int
    {
        $register = $this->db()->one('SELECT id FROM sale_pos_registers WHERE site_id = ? AND status = "active" ORDER BY id ASC LIMIT 1', [$siteId]);
        if ($register !== null) {
            return (int) $register['id'];
        }
        $channelId = $this->defaultPosChannelId($siteId);
        $this->db()->run(
            'INSERT INTO sale_pos_registers(site_id, channel_id, code, name, status, location_name)
             VALUES(?, ?, "main", "Caisse principale", "active", "Principal")',
            [$siteId, $channelId]
        );
        return (int) $this->db()->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function cashSession(int $sessionId): array
    {
        $session = $this->db()->one('SELECT * FROM sale_cash_sessions WHERE id = ? LIMIT 1', [$sessionId]);
        if ($session === null) {
            throw new SaleValidationException('sale.cash_session_not_found');
        }
        return $session;
    }

    /** @return array<string,mixed> */
    private function receiptPayload(int $orderId): array
    {
        $order = $this->orders->orderWithLines($orderId);
        return [
            'order_id' => $orderId,
            'order_number' => $order['order_number'] ?? null,
            'currency' => $order['currency'] ?? 'CHF',
            'grand_total_minor' => (int) ($order['grand_total_minor'] ?? 0),
            'paid_total_minor' => (int) ($order['paid_total_minor'] ?? 0),
            'lines' => $order['lines'] ?? [],
            'issued_at' => gmdate('Y-m-d H:i:s'),
            'printable_text' => sprintf("Vente %s\nTotal: %.2f %s", (string) ($order['order_number'] ?? $orderId), ((int) ($order['grand_total_minor'] ?? 0)) / 100, (string) ($order['currency'] ?? 'CHF')),
        ];
    }

    private function domainError(Throwable $e): Response
    {
        if ($e instanceof SaleValidationException || $e instanceof SaleInventoryException || $e instanceof SalePaymentException || $e instanceof SaleBusinessException || $e instanceof InvalidArgumentException) {
            return Response::validation(['sale' => [$e->getMessage()]], 'Donnée Vente invalide.');
        }
        throw $e;
    }
}
