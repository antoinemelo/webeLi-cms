<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Core\ErrorCode;
use App\Core\Request;
use App\Core\Response;
use App\Mail\MailerInterface;
use App\Modules\Sale\Exceptions\SaleBusinessException;
use App\Modules\Sale\Exceptions\SaleInventoryException;
use App\Modules\Sale\Exceptions\SalePaymentException;
use App\Modules\Sale\Exceptions\SaleValidationException;
use App\Modules\Sale\Repositories\SaleCartRepository;
use App\Modules\Sale\Repositories\SaleChannelRepository;
use App\Modules\Sale\Repositories\SaleOrderRepository;
use App\Modules\Sale\Repositories\SalePaymentRepository;
use App\Modules\Sale\Repositories\SaleReceiptRepository;
use App\Modules\Sale\Services\SaleCatalogExportService;
use App\Modules\Sale\Services\SaleCatalogSnapshotService;
use App\Modules\Sale\Services\SaleCartService;
use App\Modules\Sale\Services\SaleCheckoutService;
use App\Modules\Sale\Services\SalesChannelIntegrityService;
use App\Modules\Sale\Services\SalesChannelResolverService;
use App\Modules\Sale\Services\SaleCustomerAccountService;
use App\Modules\Sale\Services\SaleFulfillmentService;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Modules\Sale\Services\SaleEventService;
use App\Modules\Sale\Services\SaleIdempotencyService;
use App\Modules\Sale\Services\SaleImportExportReportService;
use App\Modules\Sale\Services\SaleInventoryService;
use App\Modules\Sale\Services\SaleInventoryReconciliationService;
use App\Modules\Sale\Services\SaleOrderService;
use App\Modules\Sale\Services\SalePaymentService;
use App\Modules\Sale\Services\SaleReceiptService;
use App\Modules\Sale\Services\SaleReturnService;
use App\Modules\Sale\Services\SaleOrderTimelineService;
use App\Modules\Sale\Services\SaleStateMachineService;
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
        private readonly SaleInventoryService $inventory,
        private readonly SaleCatalogSnapshotService $catalogSnapshots,
        private readonly SaleCatalogExportService $catalogExport,
        private readonly SaleCartService $cartService,
        private readonly SaleCheckoutService $checkout,
        private readonly SalePaymentService $paymentService,
        private readonly SaleOrderService $orderService,
        private readonly SaleEventService $events,
        private readonly SaleImportExportReportService $importExportReports,
        private readonly SaleIdempotencyService $idempotency,
        private readonly MailerInterface $mailer,
        private readonly ?SaleReceiptService $receiptService = null,
        private readonly ?SaleReturnService $returnService = null,
        private readonly ?SaleOrderTimelineService $timelineService = null,
        private readonly ?SaleCustomerAccountService $customerAccounts = null,
        private readonly ?SaleFulfillmentService $fulfillment = null,
        private readonly ?SalesChannelResolverService $channelResolver = null,
        private readonly ?SalesChannelIntegrityService $channelIntegrity = null,
        private readonly ?SaleInventoryReconciliationService $inventoryReconciliation = null,
    ) {}

    public function schema(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.read');
        return $this->ok([
            'module' => 'sale',
            'scope' => 'admin',
            'headless_public' => false,
            'resources' => ['channels', 'carts', 'orders', 'payments', 'pos', 'stock', 'reports', 'ai_contexts'],
            'idempotent_actions' => ['cart.add_line', 'checkout.place_order', 'payment.capture', 'pos.complete_sale', 'refund.create'],
        ], 'admin.sale.schema.v1', $site, $languageCode);
    }

    public function posBootstrap(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.pos.use');
        $siteId = (int) $site['id'];
        $registers = $this->db()->all('SELECT * FROM sale_pos_registers WHERE site_id = ? AND status = \'active\' ORDER BY code ASC', [$siteId]);
        $channels = $this->db()->all('SELECT * FROM sale_channels WHERE site_id = ? AND channel_type = \'pos\' ORDER BY code ASC', [$siteId]);
        $session = $this->db()->one(
            'SELECT s.* FROM sale_cash_sessions s INNER JOIN sale_pos_registers r ON r.id = s.register_id WHERE r.site_id = ? AND s.status IN (\'open\',\'closing\') ORDER BY s.id DESC LIMIT 1',
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

    public function exportCatalogPdf(): Response
    {
        [$site] = $this->authorize('sale.read');
        try {
            return new Response(200, $this->catalogExport->exportPdf((int) $site['id'], $this->request->query), [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="sale-catalog.pdf"',
            ]);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function exportOrdersCsv(): Response
    {
        [$site] = $this->authorize('sale.reports.read');
        return $this->csvResponse($this->importExportReports->ordersCsv((int) $site['id'], $this->request->query), 'sale-orders.csv');
    }

    public function exportOrderLinesCsv(): Response
    {
        [$site] = $this->authorize('sale.reports.read');
        $includePurchase = $this->auth->hasPermission('sale.settings.manage', (int) $site['id'])
            && in_array((string) ($this->request->query['include_purchase'] ?? '0'), ['1', 'true'], true);
        return $this->csvResponse($this->importExportReports->orderLinesCsv((int) $site['id'], $this->request->query, $includePurchase), 'sale-order-lines.csv');
    }

    public function exportPaymentsCsv(): Response
    {
        [$site] = $this->authorize('sale.reports.read');
        return $this->csvResponse($this->importExportReports->paymentsCsv((int) $site['id'], $this->request->query), 'sale-payments.csv');
    }

    public function exportPosSessionsCsv(): Response
    {
        [$site] = $this->authorize('sale.reports.read');
        return $this->csvResponse($this->importExportReports->posSessionsCsv((int) $site['id'], $this->request->query), 'sale-pos-sessions.csv');
    }

    public function exportStockMovementsCsv(): Response
    {
        [$site] = $this->authorize('sale.stock.read');
        return $this->csvResponse($this->importExportReports->stockMovementsCsv((int) $site['id'], $this->request->query), 'sale-stock-movements.csv');
    }

    public function exportReturnsRefundsCsv(): Response
    {
        [$site] = $this->authorize('sale.reports.read');
        return $this->csvResponse($this->importExportReports->returnsRefundsCsv((int) $site['id'], $this->request->query), 'sale-returns-refunds.csv');
    }

    public function previewStockImport(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.stock.manage');
        try {
            $payload = $this->payload();
            $import = $this->importExportReports->importStockCsv((int) $site['id'], (string) ($payload['csv'] ?? ''), array_replace($payload, ['dry_run' => true]), $this->actorId());
            return $this->ok(['import' => $import], 'admin.sale.import.stock.preview.v1', $site, $languageCode);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function applyStockImport(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.stock.manage');
        try {
            $payload = $this->payload();
            $import = $this->importExportReports->importStockCsv((int) $site['id'], (string) ($payload['csv'] ?? ''), array_replace($payload, ['dry_run' => false, 'confirm_import' => true]), $this->actorId());
            return $this->ok(['import' => $import], 'admin.sale.import.stock.apply.v1', $site, $languageCode);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function posVariants(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.pos.use');
        $filters = [
            'sku' => $this->request->query['sku'] ?? '',
            'barcode' => $this->request->query['barcode'] ?? '',
            'product_type' => $this->request->query['product_type'] ?? '',
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
            'active_carts' => (int) ($db->one('SELECT COUNT(*) AS count FROM sale_carts WHERE site_id = ? AND status = \'active\'', [$siteId])['count'] ?? 0),
            'paid_total_minor' => (int) ($db->one('SELECT COALESCE(SUM(paid_total_minor), 0) AS total FROM sale_orders WHERE site_id = ?', [$siteId])['total'] ?? 0),
            'today_sales_minor' => (int) ($db->one('SELECT COALESCE(SUM(grand_total_minor), 0) AS total FROM sale_orders WHERE site_id = ? AND date(placed_at) = date(\'now\') AND status <> \'cancelled\'', [$siteId])['total'] ?? 0),
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
                 WHERE r.site_id = ? AND s.status IN (\'open\',\'closing\')
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

    public function resolveChannel(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.channels.manage');
        try {
            if ($this->channelResolver === null) {
                throw new SaleValidationException('sale.channel_resolver_unavailable');
            }
            $context = (string) ($this->request->query['context'] ?? 'storefront');
            $channelId = isset($this->request->query['channel_id']) ? $this->id($this->request->query['channel_id']) : null;
            $resolved = match ($context) {
                'storefront' => $this->channelResolver->storefront((int) $site['id'], isset($this->request->query['code']) ? (string) $this->request->query['code'] : null),
                'headless' => $this->channelResolver->headless((int) $site['id'], $channelId, isset($this->request->query['code']) ? (string) $this->request->query['code'] : null),
                'admin' => $this->channelResolver->admin((int) $site['id'], $channelId),
                'pos' => $this->channelResolver->pos((int) $site['id'], isset($this->request->query['register_id']) ? $this->id($this->request->query['register_id']) : null),
                default => throw new SaleValidationException('sale.channel_context_invalid'),
            };
            return $this->ok(['context' => $context, 'resolved' => $resolved], 'admin.sale.channels.resolve.v1', $site, $languageCode);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function channelIntegrity(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.channels.manage');
        $result = $this->channelIntegrity?->validate((int) $site['id']) ?? ['valid' => false, 'issues' => [['code' => 'validator_unavailable']]];
        return $this->ok(['integrity' => $result], 'admin.sale.channels.integrity.v1', $site, $languageCode);
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
            $cart = $this->cartService->createCart((int) $site['id'], (int) ($payload['channel_id'] ?? 0), $payload + ['iam_user_id' => $this->actorId(),'cart_kind'=>'admin']);
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
            $result = $this->cartService->addLine($this->id($id), (int) ($payload['sellable_id'] ?? $payload['business_variant_id'] ?? $payload['variant_id'] ?? 0), (int) ($payload['quantity'] ?? 1), [
                'idempotency_key' => $this->idempotencyKey($payload),
                'iam_user_id' => $this->actorId(),
                'expected_version'=>$payload['expected_version']??null,'options'=>$payload['options']??[],'personalization'=>$payload['personalization']??[],
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
            $payload=$this->payload();
            $line = $this->cartService->updateLineQuantity($this->id($id), $this->id($line_id), (int) ($payload['quantity'] ?? 1),isset($payload['expected_version'])?(int)$payload['expected_version']:null);
            return $this->ok(['line' => $line, 'cart' => $this->carts->cartWithLines($this->id($id))], 'admin.sale.carts.lines.update.v1', $site, $languageCode);
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
            $payload=$this->payload(); $this->cartService->deleteLine($this->id($id), $this->id($line_id),isset($payload['expected_version'])?(int)$payload['expected_version']:null);
            return $this->ok(['deleted' => true, 'cart' => $this->carts->cartWithLines($this->id($id))], 'admin.sale.carts.lines.delete.v1', $site, $languageCode);
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
            $payload=$this->payload(); $recalculated=$this->cartService->recalculate($this->id($id),isset($payload['expected_version'])?(int)$payload['expected_version']:null);
            return $this->ok(['cart'=>$recalculated], 'admin.sale.carts.recalculate.v1', $site, $languageCode);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function mergeCart(string|int $id): Response
    {
        [$site,$languageCode]=$this->authorize('sale.orders.manage');
        try {
            $payload=$this->payload(); $source=$this->carts->requireCart($this->id($id)); $this->ensureSite((int)$site['id'],(int)$source['site_id']);
            $cart=$this->cartService->mergeGuestIntoAccount($this->id($id),$this->id($payload['target_cart_id']??0),$this->id($payload['customer_ref_id']??0),isset($payload['expected_version'])?(int)$payload['expected_version']:null);
            return $this->ok(['cart'=>$cart,'merged_cart_id'=>$this->id($id)],'admin.sale.carts.merge.v1',$site,$languageCode);
        } catch (Throwable $e) { return $this->domainError($e); }
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
            if (isset($payload['status'])) {
                $target = (string) $payload['status'];
                if ($target === 'confirmed') {
                    $this->orderService->confirmOrder($this->id($id), $this->actorId(), $payload['reason'] ?? null);
                } elseif ($target === 'completed') {
                    $this->orderService->completeOrder($this->id($id), $this->actorId(), $payload['reason'] ?? null);
                } elseif ($target !== (string) $order['status']) {
                    throw new SaleValidationException('sale.order_transition_invalid');
                }
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
            return $this->ok(['receipt' => $this->receipt()->issue($this->id($id), $languageCode, $this->actorId())], 'admin.sale.orders.receipt.v1', $site, $languageCode);
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
            return $this->ok($this->paymentService->recordManualPayment($this->id($id), (int) ($payload['amount_minor'] ?? 0), $this->actorId(), [
                'idempotency_key' => $this->idempotencyKey($payload),
                'payment_method' => $payload['payment_method'] ?? $payload['provider_key'] ?? 'manual_card',
                'provider_key' => $payload['provider_key'] ?? null,
                'source' => 'admin',
            ]), 'admin.sale.orders.payments.store.v1', $site, $languageCode, 201);
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
            $payload = $this->payload();
            $tx = $this->payments->requireTransactionWithOrder($this->id($transaction_id));
            $this->ensureSite((int) $site['id'], (int) $tx['site_id']);
            return $this->ok($this->paymentService->refundPayment($this->id($transaction_id), (int) ($payload['amount_minor'] ?? $tx['amount_minor']), isset($payload['reason']) ? (string) $payload['reason'] : null, $this->actorId(), $this->idempotencyKey($payload)), 'admin.sale.payments.refund.v1', $site, $languageCode, 201);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function correctOrderPayment(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('sale.payments.manage');
        try {
            $order = $this->orders->requireOrder($this->id($id));
            $this->ensureSite((int) $site['id'], (int) $order['site_id']);
            $payload = $this->payload();
            return $this->ok($this->paymentService->recordCorrection(
                $this->id($id),
                (int) ($payload['amount_delta_minor'] ?? 0),
                (string) ($payload['reason'] ?? ''),
                (string) ($this->idempotencyKey($payload) ?? ''),
                $this->actorId(),
                isset($payload['payment_transaction_id']) ? (int) $payload['payment_transaction_id'] : null
            ), 'admin.sale.orders.payments.correction.v1', $site, $languageCode, 201);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function voidPaymentIntent(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('sale.payments.manage');
        try {
            $intent = $this->payments->requireIntentWithOrder($this->id($id));
            $this->ensureSite((int) $site['id'], (int) $intent['order_site_id']);
            return $this->ok($this->paymentService->voidIntent($this->id($id), $this->actorId()), 'admin.sale.payment_intents.void.v1', $site, $languageCode);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function orderTimeline(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('sale.orders.read');
        try {
            $order = $this->orders->requireOrder($this->id($id));
            $this->ensureSite((int) $site['id'], (int) $order['site_id']);
            return $this->ok(['timeline' => $this->timeline()->timeline($this->id($id), $languageCode)], 'admin.sale.orders.timeline.v1', $site, $languageCode);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function reconcileOrderCustomer(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('sale.orders.manage');
        try {
            $order = $this->orders->requireOrder($this->id($id));
            $this->ensureSite((int) $site['id'], (int) $order['site_id']);
            $payload = $this->payload();
            $updated = $this->orderService->reconcileCustomer($this->id($id), isset($payload['company_id']) ? (int) $payload['company_id'] : null, isset($payload['contact_id']) ? (int) $payload['contact_id'] : null, $this->actorId(), $payload['reason'] ?? null);
            return $this->ok(['order' => $updated], 'admin.sale.orders.customer_reconciliation.v1', $site, $languageCode);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function storeReturn(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('sale.returns.manage');
        try {
            $order = $this->orders->requireOrder($this->id($id));
            $this->ensureSite((int) $site['id'], (int) $order['site_id']);
            $payload = $this->payload();
            return $this->ok(['return' => $this->returnsService()->request($this->id($id), is_array($payload['lines'] ?? null) ? $payload['lines'] : [], $payload['reason'] ?? null, $this->actorId(), $this->idempotencyKey($payload))], 'admin.sale.orders.returns.store.v1', $site, $languageCode, 201);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function transitionReturn(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('sale.returns.manage');
        try {
            $payload = $this->payload();
            $row = $this->db()->one('SELECT o.site_id FROM sale_returns r INNER JOIN sale_orders o ON o.id=r.order_id WHERE r.id=?', [$this->id($id)]);
            if ($row === null) {
                throw new SaleValidationException('sale.return_not_found');
            }
            $this->ensureSite((int) $site['id'], (int) $row['site_id']);
            return $this->ok(['return' => $this->returnsService()->transition($this->id($id), (string) ($payload['status'] ?? ''), $this->actorId(), $payload['reason'] ?? null)], 'admin.sale.returns.transition.v1', $site, $languageCode);
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
            $register = $this->db()->one('SELECT * FROM sale_pos_registers WHERE id = ? AND site_id = ? AND status = \'active\'', [$registerId, (int) $site['id']]);
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
                 VALUES(?, \'opening\', ?, ?, \'session opening\', ?)',
                [$sessionId, max(0, (int) ($payload['opening_cash_minor'] ?? 0)), (string) ($register['currency'] ?? 'CHF'), $this->actorId()]
            );
            $session = $this->cashSession($sessionId);
            $this->events->emit((int) $site['id'], 'sale.pos.session.opened', 'pos_session', $sessionId, [
                'site_id' => (int) $site['id'],
                'cash_session_id' => $sessionId,
                'register_id' => (int) $session['register_id'],
                'opening_cash_minor' => (int) $session['opening_cash_minor'],
                'currency' => (string) $session['currency'],
                'opened_by_iam_user_id' => $this->actorId(),
            ], $this->actorId());
            return $this->ok(['session' => $session], 'admin.sale.pos.sessions.open.v1', $site, $languageCode, 201);
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
                 SET status = \'closed\', closed_by_iam_user_id = ?, counted_cash_minor = ?,
                     difference_minor = ?, closed_at = CURRENT_TIMESTAMP, notes = ?
                 WHERE id = ?',
                [$this->actorId(), $counted, $difference, $payload['notes'] ?? null, (int) $session['id']]
            );
            $this->db()->run(
                'INSERT INTO sale_cash_movements(cash_session_id, movement_type, amount_minor, currency, reason, created_by_iam_user_id)
                 VALUES(?, \'closing\', ?, ?, \'session closing\', ?)',
                [(int) $session['id'], $counted, (string) $session['currency'], $this->actorId()]
            );
            $closedSession = $this->cashSession((int) $session['id']);
            $this->events->emit((int) $site['id'], 'sale.pos.session.closed', 'pos_session', (int) $closedSession['id'], [
                'site_id' => (int) $site['id'],
                'cash_session_id' => (int) $closedSession['id'],
                'register_id' => (int) $closedSession['register_id'],
                'counted_cash_minor' => (int) ($closedSession['counted_cash_minor'] ?? 0),
                'expected_cash_minor' => (int) $closedSession['expected_cash_minor'],
                'difference_minor' => (int) $closedSession['difference_minor'],
                'currency' => (string) $closedSession['currency'],
                'closed_by_iam_user_id' => $this->actorId(),
                'notes' => $closedSession['notes'] ?? null,
            ], $this->actorId());
            return $this->ok(['session' => $closedSession], 'admin.sale.pos.sessions.close.v1', $site, $languageCode);
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
            $activeSession = $this->db()->one("SELECT s.id FROM sale_cash_sessions s INNER JOIN sale_pos_registers r ON r.id=s.register_id WHERE r.site_id=? AND s.status IN ('open','closing') ORDER BY s.id DESC LIMIT 1", [(int) $site['id']]);
            $cart = $this->cartService->createCart((int) $site['id'], $channelId, $payload + ['iam_user_id' => $this->actorId(),'cart_kind'=>'pos','register_session_id'=>$payload['cash_session_id']??$payload['register_session_id']??$activeSession['id']??null]);
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
            $result = $this->cartService->addLine($this->id($id), (int) ($payload['sellable_id'] ?? $payload['business_variant_id'] ?? $payload['variant_id'] ?? 0), (int) ($payload['quantity'] ?? 1), [
                'idempotency_key' => $this->idempotencyKey($payload),
                'iam_user_id' => $this->actorId(),
                'expected_version'=>$payload['expected_version']??null,
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
            $payload=$this->payload(); $line = $this->cartService->updateLineQuantity($this->id($id), $this->id($line_id), (int) ($payload['quantity'] ?? 1),isset($payload['expected_version'])?(int)$payload['expected_version']:null);
            return $this->ok(['line' => $line, 'cart' => $this->carts->cartWithLines($this->id($id))], 'admin.sale.pos.carts.lines.update.v1', $site, $languageCode);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function posDeleteCartLine(string|int $id, string|int $line_id): Response
    {
        [$site, $languageCode] = $this->authorize('sale.pos.use');
        try {
            $cart = $this->carts->requireCart($this->id($id));
            $this->ensureSite((int) $site['id'], (int) $cart['site_id']);
            $payload=$this->payload(); $this->cartService->deleteLine($this->id($id), $this->id($line_id),isset($payload['expected_version'])?(int)$payload['expected_version']:null);
            return $this->ok(['deleted' => true, 'cart' => $this->carts->cartWithLines($this->id($id))], 'admin.sale.pos.carts.lines.delete.v1', $site, $languageCode);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function posSetCartAdjustment(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('sale.pos.use');
        try {
            $cart = $this->carts->requireCart($this->id($id));
            $this->ensureSite((int) $site['id'], (int) $cart['site_id']);
            return $this->ok(['cart' => $this->carts->setManualCartAdjustment($this->id($id), $this->payload())], 'admin.sale.pos.carts.adjustments.store.v1', $site, $languageCode);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function posCheckout(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.pos.use');
        try {
            $payload = $this->payload();
            $request = [
                'cart_id' => (int) ($payload['cart_id'] ?? 0),
                'cash_session_id' => (int) ($payload['cash_session_id'] ?? $payload['session_id'] ?? 0),
                'payment_method' => (string) ($payload['payment_method'] ?? 'cash'),
                'amount_minor' => (int) ($payload['amount_minor'] ?? 0),
            ];
            $data = $this->idempotency->run((int) $site['id'], 'pos.complete_sale', $this->idempotencyKey($payload), $request, function () use ($site, $payload, $languageCode): array {
                $sessionId = (int) ($payload['cash_session_id'] ?? $payload['session_id'] ?? 0);
                $paymentMethod = (string) ($payload['payment_method'] ?? 'cash');
                $session = $this->cashSession($sessionId);
                if ((string) $session['status'] !== 'open') {
                    throw new SaleValidationException('sale.cash_session_required');
                }
                $register = $this->db()->one('SELECT * FROM sale_pos_registers WHERE id = ? AND site_id = ?', [(int) $session['register_id'], (int) $site['id']]);
                if ($register === null || (int) ($register['stock_location_id'] ?? 0) < 1) {
                    throw new SaleValidationException('sale.pos_stock_location_required');
                }
                $cart = $this->carts->requireCart((int) ($payload['cart_id'] ?? 0));
                $this->ensureSite((int) $site['id'], (int) $cart['site_id']);
                if (!empty($cart['register_session_id']) && (int) $cart['register_session_id'] !== $sessionId) {
                    throw new SaleValidationException('sale.pos_cart_session_mismatch');
                }
                $this->db()->run('UPDATE sale_carts SET register_session_id=? WHERE id=?', [$sessionId, (int) $cart['id']]);
                $order = $this->checkout->placeOrder((int) $cart['id'], [
                    'idempotency_key' => $this->idempotencyKey($payload),
                    'source' => 'pos',
                    'iam_user_id' => $this->actorId(),
                ]);
                $freshOrder = $this->orders->requireOrder((int) $order['id']);
                $dueMinor = max(0, (int) $freshOrder['grand_total_minor'] - (int) $freshOrder['paid_total_minor']);
                $requestedPaymentMinor = array_key_exists('amount_minor', $payload) ? max(0, (int) $payload['amount_minor']) : $dueMinor;
                $paymentMinor = min($dueMinor, $requestedPaymentMinor);
                $paid = $paymentMinor > 0
                    ? $this->paymentService->recordManualPayment((int) $order['id'], $paymentMinor, $this->actorId(), [
                        'idempotency_key' => $this->idempotencyKey($payload),
                        'payment_method' => $paymentMethod,
                        'source' => 'pos',
                    ])
                    : ['order' => $freshOrder, 'transaction' => null];
                if ($paymentMethod === 'cash') {
                    if ($paymentMinor > 0) {
                        $this->db()->run(
                            'UPDATE sale_cash_sessions SET expected_cash_minor = expected_cash_minor + ? WHERE id = ?',
                            [$paymentMinor, $sessionId]
                        );
                        $this->db()->run(
                            'INSERT INTO sale_cash_movements(cash_session_id, movement_type, amount_minor, currency, order_id, reason, created_by_iam_user_id)
                             VALUES(?, \'cash_sale\', ?, ?, ?, \'POS checkout\', ?)',
                            [$sessionId, $paymentMinor, (string) $order['currency'], (int) $order['id'], $this->actorId()]
                        );
                    }
                }
                return ['order' => $paid['order'], 'transaction' => $paid['transaction'], 'receipt' => $this->receipt()->issue((int) $order['id'], $languageCode, $this->actorId())];
            });
            return $this->ok($data, 'admin.sale.pos.checkout.v1', $site, $languageCode, 201);
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
            return $this->ok(['receipt' => $this->receipt()->issue($this->id($id), $languageCode, $this->actorId())], 'admin.sale.pos.receipt.v1', $site, $languageCode);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function posEmailReceipt(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('sale.pos.use');
        try {
            $orderId = $this->id($id);
            $order = $this->orders->requireOrder($orderId);
            $this->ensureSite((int) $site['id'], (int) $order['site_id']);

            $payload = $this->payload();
            $email = strtolower(trim((string) ($payload['email'] ?? '')));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new SaleValidationException('sale.receipt_email_invalid');
            }

            $receipt = $this->receipt()->issue($orderId, $languageCode, $this->actorId());
            $reference = (string) ($receipt['receipt_number'] ?? $order['order_number']);
            $subject = ($languageCode === 'en' ? 'Receipt ' : 'Ticket de caisse ') . $reference;
            $text = (string) ($receipt['printable_text'] ?? '');
            $html = '<pre style="font-family: Courier New, monospace; font-size: 13px; line-height: 1.35; white-space: pre-wrap;">'
                . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</pre>';

            $sent = $this->mailer->send($email, $subject, $text, $html);
            $logged = false;
            if (!$sent) {
                $logged = $this->logReceiptEmail($email, $subject, $text, $html);
            }
            if (!$sent && !$logged) {
                throw new SaleBusinessException('sale.receipt_email_failed');
            }

            return $this->ok(['sent' => $sent, 'logged' => $logged, 'email' => $email], 'admin.sale.pos.receipt.email.v1', $site, $languageCode);
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
            $type = (string) ($payload['movement_type'] ?? 'adjustment');
            $delta = (int) ($payload['quantity_delta'] ?? 0);
            if ($type === 'receipt') $delta = abs($delta);
            if ($type === 'issue') $delta = -abs($delta);
            $item = $this->inventory->adjust((int) $site['id'], (int) ($payload['sellable_id'] ?? $payload['business_variant_id'] ?? $payload['variant_id'] ?? 0), $delta, $payload['sku'] ?? null, $payload['reason'] ?? null, $this->actorId(), isset($payload['location_id']) ? (int) $payload['location_id'] : null, $type, $this->idempotencyKey($payload));
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

    public function transferStock(): Response
    {
        [$site,$languageCode]=$this->authorize('sale.stock.manage');
        try {
            $payload=$this->payload();
            $result=$this->inventory->transfer((int)$site['id'],(int)($payload['sellable_id']??$payload['business_variant_id']??0),(int)($payload['quantity']??0),(int)($payload['from_location_id']??0),(int)($payload['to_location_id']??0),(string)($payload['transfer_key']??$this->idempotencyKey($payload)??''),$this->actorId());
            return $this->ok(['transfer'=>$result],'admin.sale.stock.transfers.v1',$site,$languageCode,201);
        } catch (Throwable $e) { return $this->domainError($e); }
    }

    public function reconcileInventory(): Response
    {
        [$site,$languageCode]=$this->authorize('sale.stock.manage');
        try {
            if ($this->inventoryReconciliation===null) throw new SaleBusinessException('sale.inventory_reconciliation_unavailable');
            $payload=$this->payload();
            $report=$this->inventoryReconciliation->run((int)$site['id'],($payload['repair_derived']??true)===true,$this->actorId());
            return $this->ok(['reconciliation'=>$report],'admin.sale.stock.reconciliation.v1',$site,$languageCode,201);
        } catch (Throwable $e) { return $this->domainError($e); }
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

    public function fulfillmentConfiguration(): Response
    {
        [$site,$languageCode]=$this->authorize('sale.settings.manage');
        return $this->ok($this->fulfillment?->configuration((int)$site['id']) ?? ['zones'=>[],'methods'=>[]], 'admin.sale.fulfillment.configuration.v1',$site,$languageCode);
    }

    public function saveFulfillmentMethod(): Response
    {
        [$site,$languageCode]=$this->authorize('sale.settings.manage');
        try {
            if ($this->fulfillment===null) throw new SaleBusinessException('sale.fulfillment_unavailable');
            return $this->ok(['method'=>$this->fulfillment->saveMethod((int)$site['id'],$this->payload())], 'admin.sale.fulfillment.methods.store.v1',$site,$languageCode,201);
        } catch(Throwable $e) { return $this->domainError($e); }
    }

    public function saveFulfillmentZone(): Response
    {
        [$site,$languageCode]=$this->authorize('sale.settings.manage');
        try {
            if($this->fulfillment===null) throw new SaleBusinessException('sale.fulfillment_unavailable');
            return $this->ok(['zone'=>$this->fulfillment->saveZone((int)$site['id'],$this->payload())],'admin.sale.fulfillment.zones.store.v1',$site,$languageCode,201);
        } catch(Throwable $e) { return $this->domainError($e); }
    }

    public function taxesReport(): Response
    {
        [$site,$languageCode]=$this->authorize('sale.reports.read');
        return $this->ok(['taxes'=>$this->importExportReports->taxesReport((int)$site['id'],$this->request->query)],'admin.sale.reports.taxes.v1',$site,$languageCode);
    }

    public function fulfillmentReport(): Response
    {
        [$site,$languageCode]=$this->authorize('sale.reports.read');
        return $this->ok(['fulfillment'=>$this->importExportReports->fulfillmentReport((int)$site['id'],$this->request->query)],'admin.sale.reports.fulfillment.v1',$site,$languageCode);
    }

    public function mergeCustomerAccounts(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.customer_accounts.manage');
        try {
            if ($this->customerAccounts === null) {
                throw new SaleBusinessException('sale.customer_accounts_unavailable');
            }
            $payload = $this->payload();
            $audit = $this->customerAccounts->mergeAccounts(
                (int) $site['id'],
                (int) ($payload['source_iam_user_id'] ?? 0),
                (int) ($payload['target_iam_user_id'] ?? 0),
                $this->actorId(),
                (string) ($payload['reason'] ?? '')
            );
            return $this->ok(['merge' => $audit], 'admin.sale.customer_accounts.merge.v1', $site, $languageCode, 201);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function dailyReport(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.reports.read');
        return $this->ok(['report' => $this->importExportReports->dailyReport((int) $site['id'], $this->request->query)], 'admin.sale.reports.daily.v1', $site, $languageCode);
    }

    public function ordersReport(): Response { return $this->orders(); }

    public function channelsReport(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.reports.read');
        return $this->ok(['channels' => $this->importExportReports->salesByChannel((int) $site['id'], $this->request->query)], 'admin.sale.reports.channels.v1', $site, $languageCode);
    }

    public function paymentMethodsReport(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.reports.read');
        return $this->ok(['payment_methods' => $this->importExportReports->salesByPaymentMethod((int) $site['id'], $this->request->query)], 'admin.sale.reports.payment_methods.v1', $site, $languageCode);
    }

    public function posSessionsReport(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.reports.read');
        return $this->ok(['sessions' => $this->db()->all(
            'SELECT s.* FROM sale_cash_sessions s INNER JOIN sale_pos_registers r ON r.id = s.register_id WHERE r.site_id = ? ORDER BY s.id DESC',
            [(int) $site['id']]
        )], 'admin.sale.reports.pos_sessions.v1', $site, $languageCode);
    }

    public function stockReport(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.reports.read');
        return $this->ok(['stock' => $this->importExportReports->stockReport((int) $site['id'], $this->request->query)], 'admin.sale.reports.stock.v1', $site, $languageCode);
    }

    public function refundsReport(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.reports.read');
        return $this->ok(['refunds' => $this->importExportReports->refundsReport((int) $site['id'], $this->request->query)], 'admin.sale.reports.refunds.v1', $site, $languageCode);
    }

    public function aiSchema(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.reports.read');
        return $this->ok([
            'external_ai_allowed' => false,
            'contexts' => \App\Modules\Sale\Contracts\SaleAiContextContracts::contexts(),
        ], 'admin.sale.ai.schema.v1', $site, $languageCode);
    }

    public function aiOrderSummaryContext(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('sale.orders.read');
        try {
            $orderId = $this->id($id);
            $order = $this->orders->requireOrder($orderId);
            $this->ensureSite((int) $site['id'], (int) $order['site_id']);
            return $this->ok([
                'external_ai_allowed' => false,
                'context_type' => 'sale.ai.order_summary',
                'order' => $this->aiOrderRow($order),
                'lines' => $this->db()->all(
                    'SELECT line_number, business_product_id, business_variant_id, sku, product_name, variant_name, product_type,
                            quantity, unit_price_minor, regular_unit_price_minor, currency, tax_rate_basis_points,
                            tax_included, line_subtotal_minor, line_discount_minor, line_tax_minor, line_total_minor
                     FROM sale_order_lines WHERE order_id = ? ORDER BY line_number ASC',
                    [$orderId]
                ),
                'payments' => $this->aiOrderPayments($orderId),
                'refunds' => $this->db()->all('SELECT id, refund_number, status, amount_minor, currency, reason, created_at, processed_at FROM sale_refunds WHERE order_id = ? ORDER BY id ASC', [$orderId]),
                'events' => $this->db()->all('SELECT event_type, payload_json, created_at FROM sale_events WHERE aggregate_type = \'order\' AND aggregate_id = ? ORDER BY id ASC', [$orderId]),
            ], 'admin.sale.ai.order_summary_context.v1', $site, $languageCode);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function aiPosDaySummaryContext(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.reports.read');
        $siteId = (int) $site['id'];
        $date = $this->reportDate();
        $registerId = (int) ($this->request->query['register_id'] ?? 0);
        $registerSql = $registerId > 0 ? ' AND r.id = :register_id' : '';
        $params = ['site_id' => $siteId, 'date' => $date] + ($registerId > 0 ? ['register_id' => $registerId] : []);
        $sessions = $this->db()->all(
            'SELECT s.*, r.code AS register_code, r.name AS register_name
             FROM sale_cash_sessions s
             INNER JOIN sale_pos_registers r ON r.id = s.register_id
             WHERE r.site_id = :site_id AND date(s.opened_at) = :date' . $registerSql . '
             ORDER BY s.opened_at ASC, s.id ASC',
            $params
        );
        $orders = $this->db()->all(
            'SELECT o.id, o.order_number, o.channel_id, o.status, o.payment_status, o.currency,
                    o.grand_total_minor, o.paid_total_minor, o.refunded_total_minor, o.placed_at
             FROM sale_orders o
             WHERE o.site_id = :site_id AND o.source = \'pos\' AND date(o.placed_at) = :date
             ORDER BY o.placed_at ASC, o.id ASC',
            ['site_id' => $siteId, 'date' => $date]
        );
        $payments = $this->db()->all(
            'SELECT t.id, t.order_id, t.transaction_type, t.status, t.amount_minor, t.currency, t.created_at, o.order_number
             FROM sale_payment_transactions t
             INNER JOIN sale_orders o ON o.id = t.order_id
             WHERE o.site_id = :site_id AND o.source = \'pos\' AND date(t.created_at) = :date
             ORDER BY t.created_at ASC, t.id ASC',
            ['site_id' => $siteId, 'date' => $date]
        );
        return $this->ok([
            'external_ai_allowed' => false,
            'context_type' => 'sale.ai.pos_day_summary',
            'date' => $date,
            'sessions' => $sessions,
            'orders' => $orders,
            'payments' => $payments,
            'totals' => [
                'orders_count' => count($orders),
                'sessions_count' => count($sessions),
                'sales_minor' => array_sum(array_map(static fn(array $row): int => (int) $row['grand_total_minor'], $orders)),
                'paid_minor' => array_sum(array_map(static fn(array $row): int => (int) $row['paid_total_minor'], $orders)),
                'refunds_minor' => array_sum(array_map(static fn(array $row): int => (int) $row['refunded_total_minor'], $orders)),
            ],
        ], 'admin.sale.ai.pos_day_summary_context.v1', $site, $languageCode);
    }

    public function aiCustomerSalesAnalysisContext(string $type, string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('sale.orders.read');
        $type = strtolower(trim($type));
        if (!in_array($type, ['company', 'contact'], true)) {
            return Response::validation(['type' => ['sale.ai_customer_type_invalid']]);
        }
        $siteId = (int) $site['id'];
        $customerId = $this->id($id);
        $column = $type === 'company' ? 'customer_company_id' : 'customer_contact_id';
        $orders = $this->db()->all(
            'SELECT id, order_number, source, status, payment_status, currency, grand_total_minor, paid_total_minor,
                    refunded_total_minor, placed_at, customer_snapshot_json
             FROM sale_orders
             WHERE site_id = ? AND ' . $column . ' = ?
             ORDER BY placed_at DESC, id DESC LIMIT 100',
            [$siteId, $customerId]
        );
        $orderIds = array_map(static fn(array $row): int => (int) $row['id'], $orders);
        return $this->ok([
            'external_ai_allowed' => false,
            'context_type' => 'sale.ai.customer_sales_analysis',
            'customer' => ['type' => $type, 'id' => $customerId, 'snapshot' => $this->customerSnapshotSummary($orders[0]['customer_snapshot_json'] ?? '{}')],
            'orders' => array_map(fn(array $row): array => $this->aiOrderRow($row), $orders),
            'payments' => $this->aiPaymentsForOrders($orderIds),
            'refunds' => $this->aiRefundsForOrders($orderIds),
            'totals' => [
                'orders_count' => count($orders),
                'sales_minor' => array_sum(array_map(static fn(array $row): int => (int) $row['grand_total_minor'], $orders)),
                'paid_minor' => array_sum(array_map(static fn(array $row): int => (int) $row['paid_total_minor'], $orders)),
                'refunds_minor' => array_sum(array_map(static fn(array $row): int => (int) $row['refunded_total_minor'], $orders)),
            ],
        ], 'admin.sale.ai.customer_sales_analysis_context.v1', $site, $languageCode);
    }

    public function aiUnpaidOrdersContext(): Response
    {
        [$site, $languageCode] = $this->authorize('sale.reports.read');
        $siteId = (int) $site['id'];
        $daysOverdue = max(0, (int) ($this->request->query['days_overdue'] ?? 0));
        $channelId = (int) ($this->request->query['channel_id'] ?? 0);
        $params = ['site_id' => $siteId, 'date_limit' => gmdate('Y-m-d', time() - ($daysOverdue * 86400))];
        $channelSql = '';
        if ($channelId > 0) {
            $channelSql = ' AND channel_id = :channel_id';
            $params['channel_id'] = $channelId;
        }
        $orders = $this->db()->all(
            'SELECT id, order_number, channel_id, source, status, payment_status, currency, grand_total_minor,
                    paid_total_minor, refunded_total_minor, placed_at
             FROM sale_orders
             WHERE site_id = :site_id
               AND status <> \'cancelled\'
               AND grand_total_minor > paid_total_minor
               AND date(COALESCE(placed_at, created_at)) <= :date_limit' . $channelSql . '
             ORDER BY placed_at ASC, id ASC LIMIT 200',
            $params
        );
        return $this->ok([
            'external_ai_allowed' => false,
            'context_type' => 'sale.ai.unpaid_orders_detection',
            'as_of' => gmdate('Y-m-d H:i:s'),
            'days_overdue' => $daysOverdue,
            'orders' => array_map(fn(array $row): array => $this->aiOrderRow($row), $orders),
            'totals' => [
                'orders_count' => count($orders),
                'remaining_due_minor' => array_sum(array_map(static fn(array $row): int => max(0, (int) $row['grand_total_minor'] - (int) $row['paid_total_minor']), $orders)),
            ],
        ], 'admin.sale.ai.unpaid_orders_context.v1', $site, $languageCode);
    }

    private function reportDate(): string
    {
        $date = trim((string) ($this->request->query['date'] ?? ''));
        if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            return $date;
        }
        return gmdate('Y-m-d');
    }

    /** @param array<string,mixed> $order @return array<string,mixed> */
    private function aiOrderRow(array $order): array
    {
        return [
            'id' => (int) ($order['id'] ?? 0),
            'order_number' => (string) ($order['order_number'] ?? ''),
            'channel_id' => isset($order['channel_id']) ? (int) $order['channel_id'] : null,
            'source' => (string) ($order['source'] ?? ''),
            'status' => (string) ($order['status'] ?? ''),
            'payment_status' => (string) ($order['payment_status'] ?? ''),
            'currency' => (string) ($order['currency'] ?? 'CHF'),
            'grand_total_minor' => (int) ($order['grand_total_minor'] ?? 0),
            'paid_total_minor' => (int) ($order['paid_total_minor'] ?? 0),
            'refunded_total_minor' => (int) ($order['refunded_total_minor'] ?? 0),
            'remaining_due_minor' => max(0, (int) ($order['grand_total_minor'] ?? 0) - (int) ($order['paid_total_minor'] ?? 0)),
            'placed_at' => $order['placed_at'] ?? null,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function aiOrderPayments(int $orderId): array
    {
        return $this->db()->all(
            'SELECT id, payment_intent_id, order_id, transaction_type, status, amount_minor, currency,
                    provider_transaction_id, error_code, error_message, processed_at, created_at
             FROM sale_payment_transactions WHERE order_id = ? ORDER BY id ASC',
            [$orderId]
        );
    }

    /** @param list<int> $orderIds @return list<array<string,mixed>> */
    private function aiPaymentsForOrders(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        return $this->db()->all(
            'SELECT id, order_id, transaction_type, status, amount_minor, currency,
                    provider_transaction_id, error_code, error_message, processed_at, created_at
             FROM sale_payment_transactions WHERE order_id IN (' . $placeholders . ') ORDER BY created_at DESC, id DESC',
            $orderIds
        );
    }

    /** @param list<int> $orderIds @return list<array<string,mixed>> */
    private function aiRefundsForOrders(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        return $this->db()->all(
            'SELECT id, order_id, payment_transaction_id, refund_number, status, amount_minor, currency,
                    reason, created_at, processed_at
             FROM sale_refunds WHERE order_id IN (' . $placeholders . ') ORDER BY created_at DESC, id DESC',
            $orderIds
        );
    }

    private function customerSnapshotSummary(mixed $snapshotJson): array
    {
        $snapshot = json_decode((string) $snapshotJson, true);
        if (!is_array($snapshot)) {
            return [];
        }
        return [
            'display_name' => $snapshot['display_name'] ?? $snapshot['name'] ?? null,
            'company_name' => $snapshot['company_name'] ?? null,
            'preferred_language' => $snapshot['preferred_language'] ?? $snapshot['language'] ?? null,
            'country' => $snapshot['country'] ?? $snapshot['billing_country'] ?? null,
        ];
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

    private function csvResponse(string $csv, string $filename): Response
    {
        return new Response(200, $csv, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
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
        $location=$this->db()->one("SELECT r.stock_location_id FROM sale_cash_sessions s INNER JOIN sale_pos_registers r ON r.id=s.register_id WHERE r.site_id=? AND s.status IN ('open','closing') ORDER BY s.id DESC LIMIT 1",[$siteId]);
        return $this->catalogSnapshots->searchSellableVariants($siteId, $filters + ['channel' => 'pos','stock_location_id'=>$location['stock_location_id']??null]);
    }

    private function defaultPosChannelId(int $siteId): int
    {
        $row = $this->db()->one('SELECT id FROM sale_channels WHERE site_id = ? AND channel_type = \'pos\' ORDER BY status = \'active\' DESC, id ASC LIMIT 1', [$siteId]);
        if ($row === null) {
            throw new SaleValidationException('sale.pos_channel_not_found');
        }
        return (int) $row['id'];
    }

    private function defaultRegisterId(int $siteId): int
    {
        $register = $this->db()->one('SELECT id FROM sale_pos_registers WHERE site_id = ? AND status = \'active\' ORDER BY id ASC LIMIT 1', [$siteId]);
        if ($register !== null) {
            return (int) $register['id'];
        }
        $channelId = $this->defaultPosChannelId($siteId);
        $location = $this->db()->one("SELECT stock_location_id FROM sale_inventory_channel_configs WHERE channel_id=? AND status='active'", [$channelId]);
        if ($location === null) {
            $location = $this->db()->one("SELECT id AS stock_location_id FROM sale_stock_locations WHERE site_id=? AND status='active' ORDER BY location_type='main' DESC,id ASC LIMIT 1", [$siteId]);
        }
        $this->db()->run(
            'INSERT INTO sale_pos_registers(site_id, channel_id, code, name, status, location_name, stock_location_id)
             VALUES(?, ?, \'main\', \'Caisse principale\', \'active\', \'Principal\', ?)',
            [$siteId, $channelId, $location['stock_location_id'] ?? null]
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

    private function logReceiptEmail(string $to, string $subject, string $text, ?string $html): bool
    {
        $path = \base_path('storage/logs/mail.log');
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $payload = [
            'created_at' => date('Y-m-d H:i:s'),
            'to' => $to,
            'subject' => $subject,
            'text' => $text,
            'html' => $html,
            'transport' => 'sale_pos_receipt_fallback',
        ];
        return file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
    }

    private function receipt(): SaleReceiptService
    {
        return $this->receiptService ?? new SaleReceiptService($this->orders, $this->payments, new SaleReceiptRepository($this->sale));
    }

    private function returnsService(): SaleReturnService
    {
        return $this->returnService ?? new SaleReturnService($this->sale, new SaleStateMachineService($this->db()), $this->inventory);
    }

    private function timeline(): SaleOrderTimelineService
    {
        return $this->timelineService ?? new SaleOrderTimelineService($this->sale);
    }

    private function domainError(Throwable $e): Response
    {
        if ($e instanceof SaleValidationException && $e->getMessage() === 'sale.cart_version_conflict') {
            return Response::error(ErrorCode::REVISION_CONFLICT, 'Le panier a été modifié par une autre requête.', 409, ['sale' => [$e->getMessage()]]);
        }
        if ($e instanceof SaleValidationException || $e instanceof SaleInventoryException || $e instanceof SalePaymentException || $e instanceof SaleBusinessException || $e instanceof InvalidArgumentException) {
            return Response::validation(['sale' => [$e->getMessage()]], 'Donnée Vente invalide.');
        }
        throw $e;
    }
}
