<?php

declare(strict_types=1);

namespace App\Application\PublicApi;

use App\Core\Database;
use App\Core\ErrorCode;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Sale\Exceptions\SaleBusinessException;
use App\Modules\Sale\Exceptions\SaleInventoryException;
use App\Modules\Sale\Exceptions\SalePaymentException;
use App\Modules\Sale\Exceptions\SaleValidationException;
use App\Modules\Sale\Repositories\SaleCartRepository;
use App\Modules\Sale\Repositories\SaleChannelRepository;
use App\Modules\Sale\Repositories\SaleOrderRepository;
use App\Modules\Sale\Services\SaleCartService;
use App\Modules\Sale\Services\SaleCheckoutService;
use App\Modules\Sale\Services\SaleGuestCheckoutService;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Repository\SiteRepository;
use InvalidArgumentException;
use Throwable;

final class PublicSaleApiHandler
{
    private PublicApiResponder $responder;

    public function __construct(
        private readonly Request $request,
        private readonly SiteRepository $sites,
        private readonly SaleDatabaseConnection $sale,
        private readonly SaleChannelRepository $channels,
        private readonly SaleCartRepository $carts,
        private readonly SaleOrderRepository $orders,
        private readonly SaleCartService $cartService,
        private readonly SaleCheckoutService $checkout,
        private readonly SaleGuestCheckoutService $guestCheckout,
    ) {
        $this->responder = new PublicApiResponder();
    }

    public function bootstrap(string $code): Response
    {
        [$site, $languageCode] = $this->context();
        try {
            $channel = $this->publicChannel((int) $site['id'], $code);

            return $this->json([
                'channel' => $this->channelPayload($channel),
                'cart' => ['enabled' => true, 'token_transport' => 'opaque_token'],
                'checkout' => ['enabled' => true, 'idempotency_required' => true],
            ], 'public.sale.channels.bootstrap.v1', $site, $languageCode);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function storeCart(string $code): Response
    {
        [$site, $languageCode] = $this->context();

        try {
            $channel = $this->publicChannel((int) $site['id'], $code);
            $token = $this->newCartToken();
            $cart = $this->cartService->createCart((int) $site['id'], (int) $channel['id'], [
                'customer_snapshot' => [],
                'billing_address' => [],
                'shipping_address' => [],
            ]);
            $this->db()->run(
                'UPDATE sale_carts SET cart_token_hash = ?, expires_at = datetime(\'now\', \'+30 days\'), updated_at = CURRENT_TIMESTAMP WHERE id = ?',
                [$this->tokenHash($token), (int) $cart['id']]
            );
            return $this->json(['cart' => $this->cartPayload($this->carts->cartWithLines((int) $cart['id']), true, $token)], 'public.sale.cart.show.v1', $site, $languageCode, 201);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function cart(string $code, string $token): Response
    {
        [$site, $languageCode] = $this->context();

        try {
            $channel = $this->publicChannel((int) $site['id'], $code);
            $cart = $this->cartByToken($channel, $token);
            return $this->json(['cart' => $this->cartPayload($cart, true)], 'public.sale.cart.show.v1', $site, $languageCode);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function addLine(string $code, string $token): Response
    {
        [$site, $languageCode] = $this->context();

        try {
            $channel = $this->publicChannel((int) $site['id'], $code);
            $cart = $this->cartByToken($channel, $token);
            $payload = $this->payload();
            $result = $this->cartService->addLine((int) $cart['id'], (int) ($payload['business_variant_id'] ?? $payload['variant_id'] ?? 0), (int) ($payload['quantity'] ?? 1), [
                'idempotency_key' => $this->idempotencyKey($payload),
            ]);
            return $this->json([
                'cart' => $this->cartPayload($this->carts->cartWithLines((int) $cart['id']), true),
                'line' => $this->linePayload($result['line'] ?? []),
            ], 'public.sale.cart.lines.store.v1', $site, $languageCode, 201);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function updateLine(string $code, string $token, string|int $line_id): Response
    {
        [$site, $languageCode] = $this->context();

        try {
            $channel = $this->publicChannel((int) $site['id'], $code);
            $cart = $this->cartByToken($channel, $token);
            $line = $this->cartService->updateLineQuantity((int) $cart['id'], $this->id($line_id), (int) ($this->payload()['quantity'] ?? 1));
            return $this->json([
                'cart' => $this->cartPayload($this->carts->cartWithLines((int) $cart['id']), true),
                'line' => $this->linePayload($line),
            ], 'public.sale.cart.lines.update.v1', $site, $languageCode);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function deleteLine(string $code, string $token, string|int $line_id): Response
    {
        [$site, $languageCode] = $this->context();

        try {
            $channel = $this->publicChannel((int) $site['id'], $code);
            $cart = $this->cartByToken($channel, $token);
            $this->cartService->deleteLine((int) $cart['id'], $this->id($line_id));
            return $this->json([
                'deleted' => true,
                'cart' => $this->cartPayload($this->carts->cartWithLines((int) $cart['id']), true),
            ], 'public.sale.cart.lines.delete.v1', $site, $languageCode);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function checkout(string $code): Response
    {
        [$site, $languageCode] = $this->context();

        try {
            $channel = $this->publicChannel((int) $site['id'], $code);
            $payload = $this->payload();
            $token = trim((string) ($payload['cart_token'] ?? $payload['token'] ?? ''));
            $cart = $this->cartByToken($channel, $token, true);
            $idempotencyKey = $this->requiredIdempotencyKey($payload);
            if ((string) $cart['status'] === 'active') {
                $this->guestCheckout->update((int) $cart['id'], $payload, true);
            }
            $order = $this->checkout->placeOrder((int) $cart['id'], [
                'idempotency_key' => $idempotencyKey,
                'source' => 'ecommerce',
                'request_fingerprint' => hash('sha256', json_encode($this->checkoutRequestPayload($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'),
            ]);
            return $this->json(['order' => $this->orderPayload($this->orders->orderWithLines((int) $order['id']))], 'public.sale.checkout.v1', $site, $languageCode, 201);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function updateCheckout(string $code, string $token): Response
    {
        [$site, $languageCode] = $this->context();
        try {
            $channel = $this->publicChannel((int) $site['id'], $code);
            $cart = $this->cartByToken($channel, $token);
            $result = $this->guestCheckout->update((int) $cart['id'], $this->payload());
            return $this->json([
                'cart' => $this->cartPayload($result['cart'], true),
                'price_changed' => $result['price_changed'],
            ], 'public.sale.checkout.update.v1', $site, $languageCode);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function abandonCart(string $code, string $token): Response
    {
        [$site, $languageCode] = $this->context();
        try {
            $channel = $this->publicChannel((int) $site['id'], $code);
            $cart = $this->cartByToken($channel, $token);
            $this->guestCheckout->abandon((int) $cart['id']);
            return $this->json(['abandoned' => true], 'public.sale.cart.abandon.v1', $site, $languageCode);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    /** @return array{0:array<string,mixed>,1:string} */
    private function context(): array
    {
        $site = $this->sites->resolveCurrentSite((string) ($this->request->server['HTTP_HOST'] ?? ''), $this->request->path);
        $languageCode = strtolower(trim((string) ($this->request->query['lang'] ?? $site['default_language_code'] ?? 'fr')));
        if (!preg_match('/^[a-z]{2}(?:-[a-z0-9]{2,8})?$/i', $languageCode)) {
            $languageCode = (string) ($site['default_language_code'] ?? 'fr');
        }
        return [$site, $languageCode];
    }

    /** @param array<string,mixed> $site */
    private function json(array $data, string $contract, array $site, string $languageCode, int $status = 200): Response
    {
        return $this->responder->success($data, $contract, [
            'site_id' => (int) $site['id'],
            'language_code' => $languageCode,
            'source' => 'sale_public_ecommerce',
        ], $status, ['Cache-Control' => 'no-store']);
    }

    /** @return array<string,mixed> */
    private function publicChannel(int $siteId, string $code): array
    {
        $code = $this->code($code);
        $channel = $this->db()->one(
            'SELECT * FROM sale_channels WHERE site_id = ? AND code = ? AND channel_type = \'ecommerce\' AND status = \'active\' AND is_public = 1 LIMIT 1',
            [$siteId, $code]
        );
        if ($channel === null) {
            throw new SaleValidationException('sale.public_channel_not_found');
        }
        return $channel;
    }

    /** @param array<string,mixed> $channel @return array<string,mixed> */
    private function cartByToken(array $channel, string $token, bool $allowConverted = false): array
    {
        $token = trim($token);
        if (!preg_match('/^[A-Za-z0-9_-]{32,128}$/', $token)) {
            throw new SaleValidationException('sale.cart_token_invalid');
        }
        $statuses = $allowConverted ? '(\'active\',\'converted\')' : '(\'active\')';
        $cart = $this->db()->one(
            'SELECT * FROM sale_carts
             WHERE channel_id = ? AND cart_token_hash = ? AND status IN ' . $statuses . '
             LIMIT 1',
            [(int) $channel['id'], $this->tokenHash($token)]
        );
        if ($cart === null) {
            throw new SaleValidationException('sale.cart_not_found');
        }
        if ((string) $cart['status'] === 'active' && $cart['expires_at'] !== null && (string) $cart['expires_at'] <= gmdate('Y-m-d H:i:s')) {
            $this->guestCheckout->expire((int) $cart['id']);
            throw new SaleValidationException('sale.cart_not_found');
        }
        $cart['lines'] = $this->carts->lines((int) $cart['id']);
        return $cart;
    }

    private function db(): Database
    {
        $db = $this->sale->database();
        if ($db === null) {
            throw new SaleBusinessException('sale.database_unavailable');
        }
        return $db;
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

    /** @param array<string,mixed> $payload */
    private function idempotencyKey(array $payload): ?string
    {
        $header = $this->request->header('Idempotency-Key');
        return trim((string) ($payload['idempotency_key'] ?? $header ?? '')) ?: null;
    }

    /** @param array<string,mixed> $payload */
    private function requiredIdempotencyKey(array $payload): string
    {
        $key = $this->idempotencyKey($payload);
        if ($key === null || preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $key) !== 1) {
            throw new SaleValidationException('sale.checkout.idempotency_key_required');
        }
        return $key;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function checkoutRequestPayload(array $payload): array
    {
        $pick = static function (mixed $value, array $keys): array {
            if (!is_array($value)) {
                return [];
            }
            $out = [];
            foreach ($keys as $key) {
                if (array_key_exists($key, $value)) {
                    $out[$key] = is_string($value[$key]) ? trim($value[$key]) : $value[$key];
                }
            }
            return $out;
        };
        return [
            'identity' => $pick($payload['identity'] ?? [], ['email','first_name','last_name','phone']),
            'billing_address' => $pick($payload['billing_address'] ?? [], ['line1','line2','postal_code','city','region','country_code']),
            'shipping_address' => $pick($payload['shipping_address'] ?? [], ['line1','line2','postal_code','city','region','country_code']),
            'shipping_same_as_billing' => ($payload['shipping_same_as_billing'] ?? false) === true,
            'shipping_method' => $pick($payload['shipping_method'] ?? [], ['code']),
            'payment' => $pick($payload['payment'] ?? $payload['payment_method'] ?? [], ['code']),
            'terms_accepted' => ($payload['terms_accepted'] ?? false) === true,
            'marketing_consent' => is_bool($payload['marketing_consent'] ?? null) ? $payload['marketing_consent'] : null,
        ];
    }

    private function id(string|int $id): int
    {
        $value = (int) $id;
        if ($value < 1) {
            throw new SaleValidationException('sale.id_invalid');
        }
        return $value;
    }

    private function code(string $code): string
    {
        $code = strtolower(trim($code));
        if ($code === '' || !preg_match('/^[a-z0-9_-]+$/', $code)) {
            throw new SaleValidationException('sale.channel_code_invalid');
        }
        return $code;
    }

    private function newCartToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function tokenHash(string $token): string
    {
        return hash('sha256', trim($token));
    }

    /** @param array<string,mixed> $channel @return array<string,mixed> */
    private function channelPayload(array $channel): array
    {
        return [
            'code' => (string) $channel['code'],
            'name' => (string) $channel['name'],
            'currency' => (string) $channel['currency'],
            'default_language' => (string) $channel['default_language'],
            'tax_mode' => (string) $channel['tax_mode'],
        ];
    }

    /** @param array<string,mixed> $cart @return array<string,mixed> */
    private function cartPayload(array $cart, bool $includeLines, ?string $token = null): array
    {
        $payload = [
            'id' => (int) $cart['id'],
            'status' => (string) $cart['status'],
            'currency' => (string) $cart['currency'],
            'subtotal_minor' => (int) $cart['subtotal_minor'],
            'discount_total_minor' => (int) $cart['discount_total_minor'],
            'tax_total_minor' => (int) $cart['tax_total_minor'],
            'grand_total_minor' => (int) $cart['grand_total_minor'],
            'expires_at' => $cart['expires_at'] ?? null,
            'checkout_step' => (string) ($cart['checkout_step'] ?? 'cart'),
            'identity' => json_decode((string) ($cart['customer_snapshot_json'] ?? '{}'), true) ?: [],
            'billing_address' => json_decode((string) ($cart['billing_address_json'] ?? '{}'), true) ?: [],
            'shipping_address' => json_decode((string) ($cart['shipping_address_json'] ?? '{}'), true) ?: [],
            'shipping_method' => json_decode((string) ($cart['shipping_method_snapshot_json'] ?? '{}'), true) ?: [],
            'payment_method' => json_decode((string) ($cart['payment_method_snapshot_json'] ?? '{}'), true) ?: [],
            'terms_accepted' => (bool) ($cart['terms_accepted'] ?? false),
            'marketing_consent' => ($cart['marketing_consent'] ?? null) === null ? null : (bool) $cart['marketing_consent'],
        ];
        if ($token !== null) {
            $payload['token'] = $token;
        }
        if ($includeLines) {
            $payload['lines'] = array_map(fn(array $line): array => $this->linePayload($line), $cart['lines'] ?? []);
        }
        return $payload;
    }

    /** @param array<string,mixed> $line @return array<string,mixed> */
    private function linePayload(array $line): array
    {
        return [
            'id' => (int) ($line['id'] ?? 0),
            'business_product_id' => (int) ($line['business_product_id'] ?? 0),
            'business_variant_id' => (int) ($line['business_variant_id'] ?? 0),
            'sku' => $line['sku'] ?? null,
            'barcode' => $line['barcode'] ?? null,
            'product_name' => (string) ($line['product_name'] ?? ''),
            'variant_name' => $line['variant_name'] ?? null,
            'quantity' => (int) ($line['quantity'] ?? 0),
            'unit_price_minor' => (int) ($line['unit_price_minor'] ?? 0),
            'regular_unit_price_minor' => (int) ($line['regular_unit_price_minor'] ?? 0),
            'currency' => (string) ($line['currency'] ?? 'CHF'),
            'tax_rate_basis_points' => (int) ($line['tax_rate_basis_points'] ?? 0),
            'tax_included' => (bool) ($line['tax_included'] ?? true),
            'line_subtotal_minor' => (int) ($line['line_subtotal_minor'] ?? 0),
            'line_discount_minor' => (int) ($line['line_discount_minor'] ?? 0),
            'line_tax_minor' => (int) ($line['line_tax_minor'] ?? 0),
            'line_total_minor' => (int) ($line['line_total_minor'] ?? 0),
        ];
    }

    /** @param array<string,mixed> $order @return array<string,mixed> */
    private function orderPayload(array $order): array
    {
        return [
            'id' => (int) $order['id'],
            'order_number' => (string) $order['order_number'],
            'source' => (string) $order['source'],
            'status' => (string) $order['status'],
            'payment_status' => (string) $order['payment_status'],
            'currency' => (string) $order['currency'],
            'subtotal_minor' => (int) $order['subtotal_minor'],
            'discount_total_minor' => (int) $order['discount_total_minor'],
            'tax_total_minor' => (int) $order['tax_total_minor'],
            'grand_total_minor' => (int) $order['grand_total_minor'],
            'lines' => array_map(fn(array $line): array => $this->linePayload($line), $order['lines'] ?? []),
            'placed_at' => $order['placed_at'] ?? null,
            'checkout' => [
                'shipping_method' => json_decode((string) ($order['shipping_method_snapshot_json'] ?? '{}'), true) ?: [],
                'payment_method' => json_decode((string) ($order['payment_method_snapshot_json'] ?? '{}'), true) ?: [],
                'terms_accepted' => (bool) ($order['terms_accepted'] ?? false),
                'marketing_consent' => ($order['marketing_consent'] ?? null) === null ? null : (bool) $order['marketing_consent'],
            ],
        ];
    }

    private function domainError(Throwable $e): Response
    {
        if ($e instanceof SaleValidationException) {
            if (in_array($e->getMessage(), ['sale.public_channel_not_found', 'sale.cart_not_found', 'sale.cart_line_not_found'], true)) {
                return Response::error(ErrorCode::PUBLIC_CONTENT_NOT_FOUND, 'Ressource Vente publique introuvable.', 404, ['sale' => [$e->getMessage()]], ['Cache-Control' => 'no-store']);
            }
            return Response::validation(['sale' => [$e->getMessage()]], 'Donnée Vente invalide.', 422, ['Cache-Control' => 'no-store']);
        }
        if ($e instanceof SaleInventoryException || $e instanceof SalePaymentException || $e instanceof SaleBusinessException || $e instanceof InvalidArgumentException) {
            return Response::validation(['sale' => [$e->getMessage()]], 'Donnée Vente invalide.', 422, ['Cache-Control' => 'no-store']);
        }
        throw $e;
    }
}
