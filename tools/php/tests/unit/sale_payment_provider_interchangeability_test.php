<?php

declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Sale\Exceptions\SalePaymentException;
use App\Modules\Sale\Payments\BankTransferPaymentProvider;
use App\Modules\Sale\Payments\DeterministicTestPaymentProvider;
use App\Modules\Sale\Payments\ManualPaymentProvider;
use App\Modules\Sale\Payments\PaymentProvider;
use App\Modules\Sale\Payments\PaymentProviderContractV1;
use App\Modules\Sale\Payments\PaymentProviderRegistry;
use App\Modules\Sale\Payments\RevolutCheckoutPaymentProvider;
use App\Modules\Sale\Payments\RevolutGateway;
use App\Modules\Sale\Payments\StripeCheckoutPaymentProvider;
use App\Modules\Sale\Payments\StripeGateway;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Modules\Sale\Services\SalePaymentMethodService;

final class InterchangeableStripeGateway implements StripeGateway
{
    public function createCheckoutSession(array $params, string $idempotencyKey): array { return ['id' => 'cs_gate', 'url' => 'https://checkout.stripe.test/gate', 'status' => 'open', 'payment_status' => 'unpaid']; }
    public function retrieveCheckoutSession(string $id): array { return ['id' => $id, 'status' => 'complete', 'payment_status' => 'paid', 'amount_total' => 1200, 'currency' => 'chf', 'payment_intent' => 'pi_gate']; }
    public function expireCheckoutSession(string $id): array { return ['id' => $id, 'status' => 'expired']; }
    public function capturePaymentIntent(string $id, array $params, string $idempotencyKey): array { return ['id' => 'cap_' . $idempotencyKey, 'status' => 'succeeded']; }
    public function createRefund(array $params, string $idempotencyKey): array { return ['id' => 'ref_' . $idempotencyKey, 'status' => 'succeeded']; }
    public function verifyWebhook(string $rawBody, string $signature, string $secret, int $tolerance): array
    {
        if ($signature !== 'valid-stripe-signature' || $secret !== 'stripe-gate-secret') throw new RuntimeException('invalid signature');
        return json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    }
}

final class InterchangeableRevolutGateway implements RevolutGateway
{
    /** @var array<string,mixed> */
    private array $order = ['id' => '6516e61c-d279-a454-a837-bc52ce55ed49', 'state' => 'completed', 'amount' => 1200, 'currency' => 'CHF', 'updated_at' => '2026-07-13T12:00:00Z', 'payments' => [['id' => 'rev_payment_gate']]];
    public function createOrder(array $params, string $idempotencyKey): array { return array_replace($this->order, ['state' => 'pending', 'checkout_url' => 'https://checkout.revolut.test/gate']); }
    public function updateOrder(string $id, array $params): array { return $this->order + $params; }
    public function retrieveOrder(string $id): array { return $this->order; }
    public function cancelOrder(string $id): array { return array_replace($this->order, ['state' => 'cancelled']); }
}

$h = new TestHarness();
[$dir, $path, $db] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/sale.sql');

try {
    $stripe = new StripeCheckoutPaymentProvider(new InterchangeableStripeGateway(), ['stripe-gate-secret'], 'https://shop.example.test', 'test');
    $revolutSecret = 'revolut-gate-secret';
    $revolut = new RevolutCheckoutPaymentProvider(new InterchangeableRevolutGateway(), [$revolutSecret], 'https://shop.example.test');
    $providers = [
        'test' => new DeterministicTestPaymentProvider($db, 'deterministic-gate-secret'),
        'manual_card' => new ManualPaymentProvider(),
        'bank_transfer' => new BankTransferPaymentProvider(),
        'stripe_checkout' => $stripe,
        'revolut_checkout' => $revolut,
    ];
    $channelId = (int) ($db->one("SELECT id FROM sale_channels WHERE code='web-main'")['id'] ?? 0);
    $db->run("INSERT INTO sale_orders(site_id,channel_id,order_number,source,status,payment_status,currency,grand_total_minor) VALUES(1,?,'GATE-PROVIDER-1','ecommerce','pending_payment','pending','CHF',1200)", [$channelId]);
    $gateOrderId = (int) $db->lastInsertId();
    $db->run("INSERT INTO sale_payment_intents(site_id,channel_id,order_id,provider_key,status,amount_minor,currency) VALUES(1,?,?,'test','requires_payment',1200,'CHF')", [$channelId, $gateOrderId]);
    $testIntentId = (int) $db->lastInsertId();
    $registry = new PaymentProviderRegistry(array_values($providers));
    $expected = [
        'test' => [false, true, true, true, true, true, true],
        'manual_card' => [true, true, true, true, true, false, false],
        'bank_transfer' => [true, true, true, true, true, false, false],
        'stripe_checkout' => [false, true, true, true, true, true, true],
        'revolut_checkout' => [false, false, false, false, false, true, true],
    ];
    $fields = ['authorize', 'delayed_capture', 'partial_capture', 'refund', 'partial_refund', 'webhook', 'reconcile'];
    $sessions = [];
    foreach ($providers as $key => $provider) {
        $contract = $registry->contract($key);
        $h->assertSame(PaymentProviderContractV1::VERSION, $contract->version(), $key . ' uses the canonical provider contract');
        foreach ($fields as $index => $field) {
            $h->assertSame($expected[$key][$index], $contract->capabilities()[$field] ?? null, $key . ' explicitly declares ' . $field);
        }
        $sessions[$key] = $contract->createPaymentSession([
            'intent_id' => $key === 'test' ? $testIntentId : array_search($key, array_keys($providers), true) + 1,
            'order_id' => 100 + array_search($key, array_keys($providers), true),
            'amount_minor' => 1200, 'currency' => 'CHF', 'idempotency_key' => 'gate-' . $key,
            'scenario' => $key === 'test' ? 'authorize_then_capture' : null,
        ]);
        foreach (['provider_key', 'status', 'provider_reference'] as $resultField) {
            $h->assertTrue(array_key_exists($resultField, $sessions[$key]), $key . ' create result exposes ' . $resultField);
        }
    }

    foreach (['manual_card', 'bank_transfer'] as $key) {
        $contract = $registry->contract($key);
        $authorized = $contract->authorize(['order_id' => 200, 'intent_id' => 20, 'amount_minor' => 1200, 'currency' => 'CHF', 'idempotency_key' => 'authorize-' . $key]);
        $captured = $contract->capture(['order_id' => 200, 'intent_id' => 20, 'amount_minor' => 600, 'currency' => 'CHF', 'idempotency_key' => 'capture-' . $key]);
        $refunded = $contract->refund(['order_id' => 200, 'intent_id' => 20, 'amount_minor' => 300, 'currency' => 'CHF', 'idempotency_key' => 'refund-' . $key]);
        $h->assertSame('succeeded', $authorized['status'] ?? null, $key . ' authorization succeeds through the common operation');
        $h->assertSame('succeeded', $captured['status'] ?? null, $key . ' partial capture succeeds through the common operation');
        $h->assertSame('succeeded', $refunded['status'] ?? null, $key . ' partial refund succeeds through the common operation');
    }

    foreach (['test', 'stripe_checkout'] as $key) {
        $contract = $registry->contract($key);
        $reference = (string) $sessions[$key]['provider_reference'];
        $intentId = $key === 'test' ? $testIntentId : 4;
        $captured = $contract->capture(['intent_id' => $intentId, 'provider_reference' => $reference, 'amount_minor' => 600, 'currency' => 'CHF', 'idempotency_key' => 'capture-' . $key]);
        $refunded = $contract->refund(['intent_id' => $intentId, 'provider_reference' => $reference, 'amount_minor' => 300, 'currency' => 'CHF', 'idempotency_key' => 'refund-' . $key]);
        $remote = $contract->reconcile(['intent_id' => $intentId, 'provider_reference' => $reference]);
        $h->assertTrue(in_array((string) ($captured['status'] ?? ''), ['succeeded', 'captured'], true), $key . ' capture uses the same normalized success vocabulary');
        $h->assertSame('succeeded', $refunded['status'] ?? null, $key . ' refund uses the same normalized success vocabulary');
        $h->assertSame(1200, $remote['amount_minor'] ?? null, $key . ' reconciliation exposes the common amount field');
    }
    $revolutState = $registry->contract('revolut_checkout')->reconcile(['provider_reference' => $sessions['revolut_checkout']['provider_reference']]);
    $h->assertSame('captured', $revolutState['status'] ?? null, 'Revolut reconciliation uses the same captured state');
    $h->expectException(
        fn() => $registry->contract('revolut_checkout')->capture(['provider_reference' => $sessions['revolut_checkout']['provider_reference'], 'amount_minor' => 100]),
        SalePaymentException::class,
        'unsupported Revolut delayed capture is rejected by capability before provider details leak'
    );

    $stripeEvent = ['id' => 'evt_gate', 'type' => 'checkout.session.completed', 'created' => time(), 'data' => ['object' => ['id' => 'cs_gate', 'payment_status' => 'paid', 'amount_total' => 1200, 'currency' => 'chf', 'payment_intent' => 'pi_gate']]];
    $stripeRaw = json_encode($stripeEvent, JSON_UNESCAPED_SLASHES) ?: '{}';
    $stripe->verifyWebhookSignature($stripeRaw, ['stripe-signature' => 'valid-stripe-signature']);
    $stripeFirst = $stripe->parseWebhook($stripeRaw, []);
    $stripe->verifyWebhookSignature($stripeRaw, ['stripe-signature' => 'valid-stripe-signature']);
    $stripeDuplicate = $stripe->parseWebhook($stripeRaw, []);
    $h->assertSame($stripeFirst['event_id'] ?? null, $stripeDuplicate['event_id'] ?? null, 'Stripe duplicate delivery retains a stable event id');
    $h->expectException(fn() => $stripe->verifyWebhookSignature($stripeRaw, ['stripe-signature' => 'invalid']), SalePaymentException::class, 'Stripe invalid signature is normalized');

    $revolutEvent = ['id' => 'rev_evt_gate', 'event' => 'ORDER_COMPLETED', 'order_id' => $sessions['revolut_checkout']['provider_reference']];
    $revolutRaw = json_encode($revolutEvent, JSON_UNESCAPED_SLASHES) ?: '{}';
    $timestamp = (string) ((int) floor(microtime(true) * 1000));
    $signature = 'v1=' . hash_hmac('sha256', 'v1.' . $timestamp . '.' . $revolutRaw, $revolutSecret);
    $headers = ['revolut-signature' => $signature, 'revolut-request-timestamp' => $timestamp];
    $revolut->verifyWebhookSignature($revolutRaw, $headers);
    $revolutFirst = $revolut->parseWebhook($revolutRaw, []);
    $revolut->verifyWebhookSignature($revolutRaw, $headers);
    $revolutDuplicate = $revolut->parseWebhook($revolutRaw, []);
    $h->assertSame($revolutFirst['event_id'] ?? null, $revolutDuplicate['event_id'] ?? null, 'Revolut duplicate delivery retains a stable event id');
    $h->expectException(fn() => $revolut->verifyWebhookSignature($revolutRaw, ['revolut-signature' => 'v1=invalid', 'revolut-request-timestamp' => $timestamp]), SalePaymentException::class, 'Revolut invalid signature is normalized');

    $methods = new SalePaymentMethodService(new SaleDatabaseConnection($path), $registry);
    $available = array_column($methods->availableMethods(1, $channelId, 'fr', 'CHF', 1200), null, 'provider_key');
    $publicShape = static fn(array $method): array => array_keys($method);
    $h->assertSame($publicShape($available['stripe_checkout']), $publicShape($available['revolut_checkout']), 'real providers expose the exact same Shop method structure');
    $h->assertSame('redirect', $available['stripe_checkout']['next_action'] ?? null, 'Stripe uses the generic redirect action');
    $h->assertSame('redirect', $available['revolut_checkout']['next_action'] ?? null, 'Revolut uses the generic redirect action');

    $controllerSources = file_get_contents(__DIR__ . '/../../../../backend/src/Application/Api/Admin/SaleAdminApiController.php')
        . file_get_contents(__DIR__ . '/../../../../backend/src/Application/PublicApi/PublicSaleApiHandler.php');
    $h->assertTrue(!str_contains($controllerSources, 'stripe_checkout') && !str_contains($controllerSources, 'revolut_checkout'), 'controllers never select or branch on a real provider');
    $shopSource = (string) file_get_contents(__DIR__ . '/../../../../frontend/theme-default/assets/js/guest-checkout.js');
    $h->assertTrue(!str_contains($shopSource, 'stripe_checkout') && !str_contains($shopSource, 'revolut_checkout'), 'Shop layout and behavior contain no provider-specific branch');
} finally {
    $db = null;
    test_remove_tree($dir);
}

exit($h->finish('UNIT Sale payment provider interchangeability gate'));
