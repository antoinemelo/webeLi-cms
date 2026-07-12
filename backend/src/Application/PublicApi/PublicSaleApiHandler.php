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
use App\Modules\Sale\Services\SaleCustomerAccountService;
use App\Modules\Sale\Services\SaleFulfillmentService;
use App\Modules\Sale\Services\SalesChannelResolverService;
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
        private readonly ?SaleCustomerAccountService $customerAccounts = null,
        private readonly ?SaleFulfillmentService $fulfillment = null,
        private readonly ?SalesChannelResolverService $channelResolver = null,
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
                'fulfillment_methods' => $this->fulfillment?->availableMethods((int) $site['id'], $languageCode) ?? [],
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
                'cart_kind' => 'web',
                'locale' => $languageCode,
                'customer_snapshot' => [],
                'billing_address' => [],
                'shipping_address' => [],
            ]);
            $this->carts->attachPublicToken((int)$cart['id'],$this->tokenHash($token),gmdate('Y-m-d H:i:s',time()+2592000));
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
            $payload['language'] = $languageCode;
            $sellableId = (int) ($payload['sellable_id'] ?? 0);
            if ($sellableId < 1) {
                throw new SaleValidationException('sale.sellable_id_required');
            }
            $result = $this->cartService->addLine((int) $cart['id'], $sellableId, (int) ($payload['quantity'] ?? 1), [
                'idempotency_key' => $this->idempotencyKey($payload),
                'expected_version' => isset($payload['expected_version']) ? (int)$payload['expected_version'] : null,
                'options' => $payload['options'] ?? [],
                'personalization' => $payload['personalization'] ?? [],
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
            $payload=$this->payload();
            $line = $this->cartService->updateLineQuantity((int) $cart['id'], $this->id($line_id), (int) ($payload['quantity'] ?? 1), isset($payload['expected_version'])?(int)$payload['expected_version']:null);
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
            $payload=$this->payload();
            $this->cartService->deleteLine((int) $cart['id'], $this->id($line_id),isset($payload['expected_version'])?(int)$payload['expected_version']:null);
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
            $payload['language'] = $languageCode;
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
            $data = ['order' => $this->orderPayload($this->orders->orderWithLines((int) $order['id']))];
            if ($this->customerAccounts !== null) {
                $data['account_creation'] = $this->customerAccounts->issueClaimProof((int) $order['id']);
            }
            return $this->json($data, 'public.sale.checkout.v1', $site, $languageCode, 201);
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
            $payload = $this->payload();
            $payload['language'] = $languageCode;
            $result = $this->guestCheckout->update((int) $cart['id'], $payload);
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
        try {
            $channel = $this->channelResolver?->storefront($siteId, $code)
                ?? $this->channels->requireByCode($siteId, $code);
        } catch (Throwable) {
            throw new SaleValidationException('sale.public_channel_not_found');
        }
        if (($channel['type'] ?? $channel['channel_kind'] ?? null) !== 'storefront'
            || ($channel['status'] ?? null) !== 'active'
            || (int) ($channel['is_public'] ?? 0) !== 1) {
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
             WHERE channel_id = ? AND cart_kind=\'web\' AND public_token_hash = ? AND status IN ' . $statuses . '
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
            'channel_id' => (int) $channel['id'],
            'site_id' => (int) $channel['site_id'],
            'code' => (string) $channel['code'],
            'type' => (string) ($channel['type'] ?? $channel['channel_kind'] ?? 'storefront'),
            'name' => (string) $channel['name'],
            'default_currency' => (string) $channel['currency'],
            'default_locale' => (string) $channel['default_language'],
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
            'cart_id' => (int) $cart['id'],
            'cart_kind' => (string)($cart['cart_kind']??'web'),
            'channel_id' => (int)$cart['channel_id'],
            'locale' => (string)($cart['locale']??'fr'),
            'version' => (int)($cart['version']??0),
            'calculation_version' => (int)($cart['calculation_version']??1),
            'status' => (string) $cart['status'],
            'currency' => (string) $cart['currency'],
            'subtotal_minor' => (int) $cart['subtotal_minor'],
            'discount_total_minor' => (int) $cart['discount_total_minor'],
            'tax_total_minor' => (int) $cart['tax_total_minor'],
            'shipping_total_minor' => (int) ($cart['shipping_total_minor'] ?? 0),
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
            'sellable_id' => (int) ($line['sellable_id'] ?? $line['business_variant_id'] ?? 0),
            'sku' => $line['sku'] ?? null,
            'barcode' => $line['barcode'] ?? null,
            'product_name' => (string) ($line['product_name'] ?? ''),
            'variant_name' => $line['variant_name'] ?? null,
            'quantity' => (int) ($line['quantity'] ?? 0),
            'unit_price_minor' => (int) ($line['unit_price_minor'] ?? 0),
            'regular_unit_price_minor' => (int) ($line['regular_unit_price_minor'] ?? 0),
            'currency' => (string) ($line['currency'] ?? 'CHF'),
            'tax_rate_basis_points' => (int) ($line['tax_rate_basis_points'] ?? 0),
            'tax_class_code' => (string) ($line['tax_class_code'] ?? 'standard'),
            'tax_included' => (bool) ($line['tax_included'] ?? true),
            'line_subtotal_minor' => (int) ($line['line_subtotal_minor'] ?? 0),
            'line_discount_minor' => (int) ($line['line_discount_minor'] ?? 0),
            'line_tax_minor' => (int) ($line['line_tax_minor'] ?? 0),
            'line_total_minor' => (int) ($line['line_total_minor'] ?? 0),
            'options' => json_decode((string)($line['options_json']??'{}'),true)?:[],
            'personalization' => json_decode((string)($line['personalization_json']??'{}'),true)?:[],
            'fulfillment_class'=>(string)($line['fulfillment_class']??'shipping'),
            'availability_state'=>(string)($line['availability_state']??'available'),
            'calculation_version'=>(int)($line['calculation_version']??1),
            'previous_unit_price_minor'=>isset($line['previous_unit_price_minor'])?(int)$line['previous_unit_price_minor']:null,
            'price_changed_at'=>$line['price_changed_at']??null,
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
            'shipping_total_minor' => (int) ($order['shipping_total_minor'] ?? 0),
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
            if ($e->getMessage() === 'sale.cart_version_conflict') {
                return Response::error(ErrorCode::REVISION_CONFLICT, 'Le panier a été modifié par une autre requête.', 409, ['sale' => [$e->getMessage()]], ['Cache-Control' => 'no-store']);
            }
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
