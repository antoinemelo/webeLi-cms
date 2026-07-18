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
use App\Modules\Sale\Services\SaleOnlinePaymentService;
use App\Modules\Sale\Services\SalePaymentMethodService;
use App\Modules\Sale\Payments\PaymentProviderRegistry;
use App\Modules\Sale\Services\SaleCustomerAccountService;
use App\Modules\Sale\Services\SaleFulfillmentService;
use App\Modules\Sale\Services\SalesChannelResolverService;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Modules\Sale\Services\SaleDeferredPaymentService;
use App\Modules\Sale\Services\SaleGiftCardService;
use App\Repository\SiteRepository;
use App\Application\Business\StorefrontProjectionRepository;
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
        private readonly ?SaleOnlinePaymentService $onlinePayments = null,
        private readonly ?SalePaymentMethodService $paymentMethods = null,
        private readonly ?SaleDeferredPaymentService $deferredPayments = null,
        private readonly ?StorefrontProjectionRepository $storefront = null,
        private readonly ?SaleGiftCardService $giftCards = null,
    ) {
        $this->responder = new PublicApiResponder();
    }

    public function bootstrap(string $code): Response
    {
        [$site, $languageCode] = $this->context();
        try {
            $channel = $this->publicChannel((int) $site['id'], $code);

            $paymentMethods = $this->paymentMethodResolver()->availableMethods(
                (int) $site['id'], (int) $channel['id'], $languageCode, (string) $channel['currency'],
                max(1, (int) ($this->request->query['amount_minor'] ?? 1))
            );
            $paymentMethods = array_map(static function (array $method): array {
                unset($method['provider_key']);
                foreach (array_keys($method) as $key) { if (str_starts_with((string) $key, '_')) unset($method[$key]); }
                return $method;
            }, $paymentMethods);
            return $this->json([
                'channel' => $this->channelPayload($channel),
                'cart' => ['enabled' => true, 'token_transport' => 'opaque_token'],
                'checkout' => [
                    'enabled' => true,
                    'idempotency_required' => true,
                    'payment_timing' => ['prepaid', 'when_available'],
                    'on_order_policy' => [
                        'default_price_policy' => 'frozen',
                        'requires_expected_availability' => true,
                        'requires_explicit_terms_acceptance' => true,
                        'invoice_before_payment' => false,
                    ],
                    'gift_cards' => ['enabled'=>$this->giftCards !== null,'maximum_per_order'=>1,'gift_card_products_excluded'=>true],
                ],
                'fulfillment_methods' => $this->fulfillment?->availableMethods((int) $site['id'], $languageCode) ?? [],
                'payment_methods' => $paymentMethods,
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
            $review = $this->cartService->revalidateForDisplay((int) $cart['id']);
            return $this->json([
                'cart' => $this->cartPayload($review['cart'], true),
                'changes' => $review['changes'],
            ], 'public.sale.cart.show.v1', $site, $languageCode);
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
                $cart = $this->carts->cartWithLines((int) $cart['id']);
            }
            $paymentCode = strtolower(trim((string) (($payload['payment']['code'] ?? $payload['payment_method']['code'] ?? ''))));
            $resolvedPayment = $this->paymentMethodResolver()->requireAvailable(
                (int) $site['id'], (int) $channel['id'], $languageCode, (string) $cart['currency'],
                (int) $cart['grand_total_minor'], $paymentCode
            );
            $createSession = (bool) ($resolvedPayment['create_session'] ?? false);
            $orderPolicy = is_array($payload['order_policy'] ?? null) ? $payload['order_policy'] : [];
            $deferredOnAvailability = (string) ($orderPolicy['payment_timing'] ?? 'prepaid') === 'when_available';
            if ($deferredOnAvailability && (($orderPolicy['terms_accepted'] ?? false) !== true || trim((string) ($orderPolicy['expected_availability_at'] ?? '')) === '')) {
                throw new SaleValidationException('sale.deferred_payment_terms_required');
            }
            $order = $this->checkout->placeOrder((int) $cart['id'], [
                'idempotency_key' => $idempotencyKey,
                'source' => 'ecommerce',
                'defer_inventory_until_payment' => $deferredOnAvailability || (bool) ($resolvedPayment['defer_order_until_payment'] ?? false),
                'payment_reservation_ttl_seconds' => 1800,
                'request_fingerprint' => hash('sha256', json_encode($this->checkoutRequestPayload($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'),
                'gift_card_code' => trim((string)($payload['gift_card']['code'] ?? '')),
                'order_policy' => $orderPolicy,
            ]);
            $data = ['order' => $this->orderPayload($this->orders->orderWithLines((int) $order['id']))];
            if(isset($order['gift_card'])) $data['gift_card']=$order['gift_card'];
            if ($deferredOnAvailability) {
                if ($this->deferredPayments === null) throw new SalePaymentException('sale.deferred_payment_unavailable');
                $data['deferred_payment'] = $this->deferredPayments->configure((int) $order['id'], [
                    'mode' => (int) ($orderPolicy['deposit_minor'] ?? 0) > 0 ? 'deposit_balance' : 'deferred_availability',
                    'price_policy' => (string) ($orderPolicy['price_policy'] ?? 'frozen'),
                    'deposit_minor' => (int) ($orderPolicy['deposit_minor'] ?? 0),
                    'expected_availability_at' => (string) $orderPolicy['expected_availability_at'],
                    'payment_window_seconds' => (int) ($orderPolicy['payment_window_seconds'] ?? 604800),
                    'provider_key' => (string) $resolvedPayment['provider_key'],
                    'idempotency_key' => $idempotencyKey . '-deferred',
                    'accepted_at' => gmdate('Y-m-d H:i:s'),
                ]);
            } elseif ($createSession && (int)$order['paid_total_minor'] < (int)$order['grand_total_minor']) {
                if ($this->onlinePayments === null) {
                    throw new SalePaymentException('sale.online_payment_unavailable');
                }
                $providerKey = (string) $resolvedPayment['provider_key'];
                $data['payment'] = $this->onlinePayments->createIntentForOrder((int) $order['id'], $providerKey, [
                    'idempotency_key' => $idempotencyKey,
                    'return_url' => isset($payload['return_url']) ? (string) $payload['return_url'] : null,
                    'cancel_url' => isset($payload['cancel_url']) ? (string) $payload['cancel_url'] : null,
                    'language' => $languageCode,
                    'scenario' => (string) ($payload['payment']['scenario'] ?? ''),
                    'provider_config' => $resolvedPayment['_provider_config'] ?? [],
                    'ttl_seconds' => (int) ($resolvedPayment['_provider_config']['ttl_seconds'] ?? 1800),
                ]);
            }
            if ($this->customerAccounts !== null) {
                $data['account_creation'] = $this->customerAccounts->issueClaimProof((int) $order['id']);
            }
            return $this->json($data, 'public.sale.checkout.v1', $site, $languageCode, 201);
        } catch (Throwable $e) {
            return $this->domainError($e);
        }
    }

    public function validateGiftCard(string $code): Response
    {
        [$site,$languageCode]=$this->context();
        try{
            $channel=$this->publicChannel((int)$site['id'],$code);$payload=$this->payload();
            $token=trim((string)($payload['cart_token']??$payload['token']??''));$cart=$this->cartByToken($channel,$token);
            $service=$this->giftCards??throw new SaleValidationException('sale.gift_card_unavailable');
            $requester=(string)($this->request->server['REMOTE_ADDR']??'unknown').'|'.substr((string)($this->request->header('User-Agent')??''),0,160);
            $result=$service->validatePublic((int)$site['id'],(string)$cart['currency'],(string)($payload['code']??''),(int)$cart['grand_total_minor'],$requester);
            return $this->json(['gift_card'=>$result],'public.sale.gift_card.validate.v1',$site,$languageCode);
        }catch(Throwable $e){return $this->domainError($e);}
    }

    public function claimGiftCard(string $code): Response
    {
        [$site,$languageCode]=$this->context();
        try{$this->publicChannel((int)$site['id'],$code);$payload=$this->payload();$result=($this->giftCards??throw new SaleValidationException('sale.gift_card_unavailable'))->claim((string)($payload['claim_token']??''),(int)$site['id']);return $this->json(['gift_card'=>$result],'public.sale.gift_card.claim.v1',$site,$languageCode);}
        catch(Throwable $e){return $this->domainError($e);}
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

    public function retryPayment(string $code,string $token): Response
    {
        [$site,$languageCode]=$this->context();
        try {
            $channel=$this->publicChannel((int)$site['id'],$code); $cart=$this->cartByToken($channel,$token,true);
            $orderId=(int)($cart['converted_order_id']??0); if($orderId<1) throw new SaleValidationException('sale.payment_retry_unavailable');
            $order=$this->orders->requireOrder($orderId); if((string)$order['status']!=='pending_payment') throw new SaleValidationException('sale.payment_retry_unavailable');
            $payload=$this->payload(); $paymentCode=strtolower(trim((string)($payload['payment']['code']??'')));
            $method=$this->paymentMethodResolver()->requireAvailable((int)$site['id'],(int)$channel['id'],$languageCode,(string)$order['currency'],(int)$order['grand_total_minor'],$paymentCode);
            if(!($method['create_session']??false)) throw new SaleValidationException('sale.payment_retry_unavailable');
            $payment=($this->onlinePayments??throw new SalePaymentException('sale.online_payment_unavailable'))->createIntentForOrder($orderId,(string)$method['provider_key'],[
                'idempotency_key'=>$this->requiredIdempotencyKey($payload),'language'=>$languageCode,'scenario'=>(string)($payload['payment']['scenario']??''),'provider_config'=>$method['_provider_config']??[],
                'ttl_seconds'=>(int)($method['_provider_config']['ttl_seconds']??1800),
                'return_url'=>$payload['return_url']??null,'cancel_url'=>$payload['cancel_url']??null,
            ]);
            return $this->json(['order'=>$this->orderPayload($this->orders->orderWithLines($orderId)),'payment'=>$payment],'public.sale.payment.retry.v1',$site,$languageCode,201);
        } catch(Throwable $e){return $this->domainError($e);}
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

    public function paymentReturn(): Response
    {
        [$site, $languageCode] = $this->context();
        try {
            $service = $this->onlinePayments ?? throw new SalePaymentException('sale.online_payment_unavailable');
            $result = $service->browserReturn((string) ($this->request->query['provider'] ?? ''), (string) ($this->request->query['reference'] ?? ''), $languageCode);
            return $this->json($result, 'public.sale.payment.return.v1', $site, $languageCode);
        } catch (Throwable $e) { return $this->domainError($e); }
    }

    public function sandbox(string $reference): Response
    {
        [$site, $languageCode] = $this->context();
        return $this->json([
            'provider' => 'sandbox', 'reference' => trim($reference),
            'outcomes' => ['success','authorize','decline','abandon','timeout'],
            'card_fields' => false,
        ], 'public.sale.payment.sandbox.v1', $site, $languageCode);
    }

    public function simulateSandbox(string $reference): Response
    {
        [$site, $languageCode] = $this->context();
        try {
            $payload = $this->payload();
            $service = $this->onlinePayments ?? throw new SalePaymentException('sale.online_payment_unavailable');
            $result = $service->simulateSandbox(
                trim($reference), (string) ($payload['sandbox_token'] ?? ''), (string) ($payload['outcome'] ?? ''),
                isset($payload['amount_minor']) ? (int) $payload['amount_minor'] : null,
                ($payload['deliver_webhook'] ?? true) === true
            );
            return $this->json($result, 'public.sale.payment.sandbox.simulate.v1', $site, $languageCode);
        } catch (Throwable $e) { return $this->domainError($e); }
    }

    public function simulateDeterministicTest(string $reference): Response
    {
        [$site,$languageCode]=$this->context();
        try {
            $payload=$this->payload();
            $result=($this->onlinePayments??throw new SalePaymentException('sale.online_payment_unavailable'))->simulateDeterministicTest(trim($reference),(string)($payload['test_token']??''),(string)($payload['outcome']??''),($payload['deliver_webhook']??true)===true);
            return $this->json($result,'public.sale.payment.test.simulate.v1',$site,$languageCode);
        } catch (Throwable $e) { return $this->domainError($e); }
    }

    public function paymentWebhook(string $provider): Response
    {
        [$site, $languageCode] = $this->context();
        try {
            $service = $this->onlinePayments ?? throw new SalePaymentException('sale.online_payment_unavailable');
            $result = $service->processWebhook(strtolower(trim($provider)), $this->request->rawBody(), [
                'x-sale-signature' => (string) ($this->request->header('X-Sale-Signature') ?? ''),
                'stripe-signature' => (string) ($this->request->header('Stripe-Signature') ?? ''),
                'revolut-signature' => (string) ($this->request->header('Revolut-Signature') ?? ''),
                'revolut-request-timestamp' => (string) ($this->request->header('Revolut-Request-Timestamp') ?? ''),
            ]);
            return $this->json($result, 'public.sale.payment.webhook.v1', $site, $languageCode);
        } catch (Throwable $e) { return $this->domainError($e); }
    }

    /** @return array{0:array<string,mixed>,1:string} */
    private function context(): array
    {
        $site = $this->sites->resolveCurrentSite((string) ($this->request->server['HTTP_HOST'] ?? ''));
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
        if ($this->storefront !== null) {
            $site = $this->sites->resolveCurrentSite((string) ($this->request->server['HTTP_HOST'] ?? ''));
            $languageCode = strtolower(trim((string) ($this->request->query['lang'] ?? $site['default_language_code'] ?? 'fr')));
            $shop = $this->storefront->activeShop($siteId, $languageCode);
            if ($shop === null || (string) ($shop['channel_code'] ?? '') !== $code) {
                throw new SaleValidationException('sale.public_channel_not_found');
            }
        }
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

    private function paymentMethodResolver(): SalePaymentMethodService
    {
        return $this->paymentMethods ?? new SalePaymentMethodService(
            $this->sale,
            new PaymentProviderRegistry(null, $this->sale->database(), null, (string) (function_exists('env') ? env('APP_ENV', 'production') : 'production'))
        );
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
            'order_policy' => $pick($payload['order_policy'] ?? [], [
                'payment_timing', 'price_policy', 'deposit_minor', 'expected_availability_at',
                'payment_window_seconds', 'terms_accepted',
            ]),
            'gift_card' => trim((string)($payload['gift_card']['code']??'')) === '' ? null : ['fingerprint'=>hash('sha256',strtoupper(preg_replace('/[^A-Z0-9]/','',(string)$payload['gift_card']['code'])??''))],
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
            $reservationBySellable = [];
            $database = $this->sale->database();
            if ($database !== null) {
                foreach ($database->all(
                    "SELECT COALESCE(r.bundle_parent_sellable_id,i.sellable_id) AS cart_sellable_id,SUM(r.quantity) AS physical_quantity,MIN(r.expires_at) AS expires_at
                     FROM sale_stock_reservations r INNER JOIN sale_inventory_items i ON i.id=r.inventory_item_id
                     WHERE r.cart_id=? AND r.status IN ('active','confirmed') GROUP BY COALESCE(r.bundle_parent_sellable_id,i.sellable_id)",
                    [(int) $cart['id']]
                ) as $row) {
                    $reservationBySellable[(int) $row['cart_sellable_id']] = ['physical_quantity' => (int) $row['physical_quantity'], 'backorder_quantity' => 0, 'expires_at' => $row['expires_at']];
                }
                foreach ($database->all(
                    "SELECT COALESCE(b.bundle_parent_sellable_id,i.sellable_id) AS cart_sellable_id,SUM(b.quantity) AS backorder_quantity,MAX(b.delivery_lead_time_days) AS delivery_lead_time_days,MIN(b.expires_at) AS expires_at
                     FROM sale_stock_backorders b INNER JOIN sale_inventory_items i ON i.id=b.inventory_item_id
                     WHERE b.cart_id=? AND b.status IN ('active','confirmed') GROUP BY COALESCE(b.bundle_parent_sellable_id,i.sellable_id)",
                    [(int) $cart['id']]
                ) as $row) {
                    $key = (int) $row['cart_sellable_id'];
                    $reservationBySellable[$key] ??= ['physical_quantity' => 0, 'backorder_quantity' => 0, 'expires_at' => $row['expires_at']];
                    $reservationBySellable[$key]['backorder_quantity'] = (int) $row['backorder_quantity'];
                    $reservationBySellable[$key]['delivery_lead_time_days'] = (int) $row['delivery_lead_time_days'];
                    $reservationBySellable[$key]['expires_at'] = $reservationBySellable[$key]['expires_at'] ?? $row['expires_at'];
                }
            }
            $payload['lines'] = array_map(function (array $line) use ($reservationBySellable): array {
                $sellableId = (int) ($line['sellable_id'] ?? $line['business_variant_id'] ?? 0);
                return $this->linePayload($line, $reservationBySellable[$sellableId] ?? null);
            }, $cart['lines'] ?? []);
        }
        return $payload;
    }

    /** @param array<string,mixed> $line @return array<string,mixed> */
    private function linePayload(array $line, ?array $reservation = null): array
    {
        $state = (string) ($line['availability_state'] ?? 'available');
        $metadata = json_decode((string) ($line['metadata_json'] ?? '{}'), true);
        $snapshot = is_array($metadata['snapshot'] ?? null) ? $metadata['snapshot'] : [];
        $mainAsset = is_array($snapshot['main_asset'] ?? null) ? $snapshot['main_asset'] : [];
        $availability = match ($state) {
            'backorder' => ['status' => 'backorder', 'label' => 'Sur commande', 'is_orderable' => true],
            'unavailable', 'contact_us' => ['status' => 'unavailable', 'label' => 'Indisponible', 'is_orderable' => false],
            default => ['status' => 'in_stock', 'label' => 'En stock', 'is_orderable' => true],
        };
        return [
            'id' => (int) ($line['id'] ?? 0),
            'business_product_id' => (int) ($line['business_product_id'] ?? 0),
            'business_variant_id' => (int) ($line['business_variant_id'] ?? 0),
            'sellable_id' => (int) ($line['sellable_id'] ?? $line['business_variant_id'] ?? 0),
            'sku' => $line['sku'] ?? null,
            'barcode' => $line['barcode'] ?? null,
            'product_name' => (string) ($line['product_name'] ?? ''),
            'variant_name' => $line['variant_name'] ?? null,
            'media_url' => (string) ($snapshot['main_media_url'] ?? $mainAsset['url'] ?? ''),
            'media_alt' => (string) ($mainAsset['alt_text'] ?? ''),
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
            'availability_state'=>$state,
            'availability'=>$availability + ['contract' => 'sale.inventory.availability.v1'],
            'reservation'=>$reservation,
            'recovery_options'=>['reduce_quantity','choose_variant','backorder','remove_line'],
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
            if ($e->getMessage() === 'sale.gift_card_rate_limited') {
                return Response::error(ErrorCode::RATE_LIMIT_EXCEEDED, 'Trop de tentatives. Réessayez plus tard.', 429, [], ['Cache-Control'=>'no-store','Retry-After'=>'600']);
            }
            if ($e->getMessage() === 'sale.cart_version_conflict') {
                return Response::error(ErrorCode::REVISION_CONFLICT, 'Le panier a été modifié par une autre requête.', 409, ['sale' => [$e->getMessage()]], ['Cache-Control' => 'no-store']);
            }
            if (in_array($e->getMessage(), ['sale.public_channel_not_found', 'sale.cart_not_found', 'sale.cart_line_not_found'], true)) {
                return Response::error(ErrorCode::PUBLIC_CONTENT_NOT_FOUND, 'Ressource Vente publique introuvable.', 404, ['sale' => [$e->getMessage()]], ['Cache-Control' => 'no-store']);
            }
            return Response::validation(['sale' => [$e->getMessage()]], 'Donnée Vente invalide.', 422, ['Cache-Control' => 'no-store']);
        }
        if ($e instanceof SaleInventoryException && in_array($e->getMessage(), ['sale.stock_insufficient','sale.stock_reservation_expired','sale.stock_reservation_quantity_conflict'], true)) {
            return Response::error(ErrorCode::VALIDATION_FAILED, 'La disponibilité a changé pour une variante du panier.', 409, $e->context() + [
                'reason' => $e->getMessage(),
                'recovery_options' => ['reduce_quantity','choose_variant','backorder','remove_line'],
                'cart_preserved' => true,
            ], ['Cache-Control' => 'no-store']);
        }
        if ($e instanceof SaleInventoryException || $e instanceof SalePaymentException || $e instanceof SaleBusinessException || $e instanceof InvalidArgumentException) {
            return Response::validation(['sale' => [$e->getMessage()]], 'Donnée Vente invalide.', 422, ['Cache-Control' => 'no-store']);
        }
        throw $e;
    }
}
