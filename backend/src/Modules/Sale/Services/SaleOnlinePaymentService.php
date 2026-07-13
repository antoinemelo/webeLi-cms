<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Modules\Sale\Exceptions\SalePaymentException;
use App\Modules\Sale\Payments\PaymentProviderRegistry;
use App\Modules\Sale\Payments\SandboxPaymentProvider;
use App\Modules\Sale\Payments\PaymentProviderContractV1;
use App\Modules\Sale\Payments\DeterministicTestPaymentProvider;
use App\Modules\Sale\Repositories\SaleOrderRepository;
use App\Modules\Sale\Repositories\SalePaymentRepository;
use Throwable;

final class SaleOnlinePaymentService
{
    public function __construct(
        private readonly SaleDatabaseConnection $connection,
        private readonly SalePaymentRepository $payments,
        private readonly SaleOrderRepository $orders,
        private readonly SaleInventoryService $inventory,
        private readonly SaleStateMachineService $states,
        private readonly PaymentProviderRegistry $providers,
        private readonly ?Logger $logger = null,
    ) {}

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function createIntentForOrder(int $orderId, string $providerKey, array $options = []): array
    {
        $language = (string) ($options['language'] ?? 'fr');
        $order = $this->orders->requireOrder($orderId);
        if ((string) $order['status'] !== 'pending_payment' || (string) $order['payment_status'] !== 'pending') {
            throw new SalePaymentException('sale.online_payment_order_not_pending');
        }
        $provider = $this->providers->contract($providerKey);
        if ($provider->version() !== PaymentProviderContractV1::VERSION || !($provider->capabilities()['create_payment_session'] ?? false)) {
            throw new SalePaymentException('sale.payment_provider_contract_unsupported');
        }
        $returnUrl = $this->safeRedirectUrl($options['return_url'] ?? null);
        $cancelUrl = $this->safeRedirectUrl($options['cancel_url'] ?? null);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + max(300, (int) ($options['ttl_seconds'] ?? 1800)));
        $existing = $this->db()->one(
            "SELECT * FROM sale_payment_intents WHERE order_id=? AND provider_key=? AND status NOT IN ('failed','cancelled','expired') ORDER BY id DESC LIMIT 1",
            [$orderId, $provider->key()]
        );
        if ($existing !== null) {
            return $this->publicIntent($existing, null, true, $language);
        }
        $intent = $this->db()->transaction(function () use ($order, $orderId, $provider, $options, $expiresAt, $returnUrl, $cancelUrl): array {
            $created = $this->payments->createIntent(
                (int) $order['site_id'], (int) $order['channel_id'], $orderId, $provider->key(),
                (int) $order['grand_total_minor'], (string) $order['currency'],
                isset($options['idempotency_key']) ? (string) $options['idempotency_key'] : null,
                ['source' => 'ecommerce', 'card_data_stored' => false]
            );
            $this->db()->run(
                'UPDATE sale_payment_intents SET contract_version=?,return_url=?,cancel_url=?,expires_at=? WHERE id=?',
                [$provider->version(), $returnUrl, $cancelUrl, $expiresAt, (int) $created['id']]
            );
            $this->db()->run(
                "INSERT INTO sale_payment_attempts(payment_intent_id,attempt_number,status,metadata_json) VALUES(?,1,'created',?)",
                [(int) $created['id'], $this->json(['channel' => 'ecommerce'])]
            );
            return $this->db()->one('SELECT * FROM sale_payment_intents WHERE id=?', [(int) $created['id']]) ?? $created;
        });

        // L'appel externe ne garde jamais de transaction SQLite ouverte.
        try {
            $result = $provider->createPaymentSession([
                'intent_id' => (int) $intent['id'], 'order_id' => $orderId,
                'amount_minor' => (int) $order['grand_total_minor'], 'currency' => (string) $order['currency'],
                'return_url' => $returnUrl, 'cancel_url' => $cancelUrl,
                'idempotency_key' => $options['idempotency_key'] ?? null,
                'scenario' => trim((string) ($options['scenario'] ?? '')) ?: null,
                'provider_config' => is_array($options['provider_config'] ?? null) ? $options['provider_config'] : [],
            ]);
        } catch (Throwable $e) {
            $this->db()->transaction(function () use ($intent, $order, $e): void {
                $this->db()->run("UPDATE sale_payment_intents SET status='failed',last_provider_status='create_failed',updated_at=CURRENT_TIMESTAMP,version=version+1 WHERE id=?", [(int) $intent['id']]);
                $this->db()->run("UPDATE sale_payment_attempts SET status='failed',error_code='provider_create_failed',error_message=?,finished_at=CURRENT_TIMESTAMP WHERE payment_intent_id=? AND attempt_number=1", [$this->safeError($e->getMessage()), (int) $intent['id']]);
                $this->db()->run("UPDATE sale_orders SET payment_status='failed',updated_at=CURRENT_TIMESTAMP WHERE id=?", [(int) $order['id']]);
                if ($order['source_cart_id'] !== null) {
                    $this->inventory->releaseCartReservations((int) $order['source_cart_id'], 'online payment provider creation failed');
                }
                if ((string) $order['status'] === 'pending_payment') {
                    $this->states->transition('order', (int) $order['id'], 'cancelled', null, 'online payment provider creation failed');
                }
            });
            $this->metric((int) $order['site_id'], 'payment.intent.create_failed', 'critical', (int) $intent['id'], ['provider' => $provider->key()]);
            throw new SalePaymentException('sale.payment_provider_failed');
        }
        return $this->db()->transaction(function () use ($order, $provider, $intent, $result, $language): array {
            $status = (string) ($result['status'] ?? 'requires_action');
            $action = array_filter([
                'instructions' => is_array($result['instructions'] ?? null) ? $result['instructions'] : null,
                'test_mode' => ($result['test_mode'] ?? false) === true,
                'scenario' => $result['scenario'] ?? null,
            ], static fn(mixed $value): bool => $value !== null && $value !== false);
            $this->db()->run(
                'UPDATE sale_payment_intents SET intent_reference=?,status=?,checkout_url=?,public_action_json=?,last_provider_status=?,provider_synced_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP,version=version+1 WHERE id=?',
                [$result['provider_reference'] ?? null, $status, $result['checkout_url'] ?? null, $this->json($action), $status, (int) $intent['id']]
            );
            $attemptStatus = match ($status) { 'captured' => 'succeeded', 'failed' => 'failed', 'cancelled' => 'cancelled', 'expired' => 'timed_out', default => ($result['checkout_url'] ?? null) ? 'redirected' : 'pending' };
            $this->db()->run(
                'UPDATE sale_payment_attempts SET status=?,provider_reference=?,finished_at=CASE WHEN ? IN (\'succeeded\',\'failed\',\'cancelled\',\'timed_out\') THEN CURRENT_TIMESTAMP ELSE NULL END WHERE payment_intent_id=? AND attempt_number=1',
                [$attemptStatus, $result['provider_reference'] ?? null, $attemptStatus, (int) $intent['id']]
            );
            $this->metric((int) $order['site_id'], 'payment.intent.created', 'info', (int) $intent['id'], ['provider' => $provider->key()]);
            $fresh = $this->db()->one('SELECT * FROM sale_payment_intents WHERE id=?', [(int) $intent['id']]) ?? $intent;
            $eventType = trim((string) ($result['event_type'] ?? ''));
            if ($eventType !== '') {
                $this->applyProviderEvent($fresh, [
                    'event_id' => 'provider_create_' . (int) $intent['id'] . '_' . $status,
                    'type' => $eventType, 'provider_reference' => (string) ($result['provider_reference'] ?? ''),
                    'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'), 'amount_minor' => in_array($status, ['authorized','captured'], true) ? (int) $intent['amount_minor'] : 0,
                    'currency' => (string) $intent['currency'], 'provider_transaction_id' => (string) ($result['provider_transaction_id'] ?? 'provider_create_' . (int) $intent['id']),
                    'data' => ['status' => $status, 'captured_minor' => $status === 'captured' ? (int) $intent['amount_minor'] : 0],
                ]);
                $fresh = $this->db()->one('SELECT * FROM sale_payment_intents WHERE id=?', [(int) $intent['id']]) ?? $fresh;
            }
            $token = isset($result['sandbox_token']) ? (string) $result['sandbox_token'] : (isset($result['test_token']) ? (string) $result['test_token'] : null);
            $public = $this->publicIntent($fresh, $token, false, $language);
            if (isset($result['test_token'])) $public['test_token'] = (string) $result['test_token'];
            return $public;
        });
    }

    /** @param array<string,string> $headers @return array<string,mixed> */
    public function processWebhook(string $providerKey, string $rawBody, array $headers): array
    {
        try {
            $provider = $this->providers->contract($providerKey);
            $provider->verifyWebhookSignature($rawBody, $headers);
            $event = $provider->parseWebhook($rawBody, $headers);
        } catch (Throwable $e) {
            $this->logger?->warning('sale.payment.webhook_rejected', ['provider' => $providerKey, 'reason' => $this->safeError($e->getMessage())]);
            throw $e;
        }
        $intent = $this->db()->one(
            'SELECT * FROM sale_payment_intents WHERE provider_key=? AND intent_reference=? LIMIT 1',
            [$provider->key(), (string) $event['provider_reference']]
        );
        if ($intent === null) {
            throw new SalePaymentException('sale.payment_webhook_intent_not_found');
        }
        $existing = $this->db()->one(
            'SELECT * FROM sale_payment_webhook_events WHERE provider_key=? AND provider_event_id=?',
            [$provider->key(), (string) $event['event_id']]
        );
        if ($existing !== null) {
            $this->metric((int) $intent['site_id'], 'payment.webhook.duplicate', 'warning', (int) $intent['id'], ['provider' => $provider->key()]);
            return ['processed' => true, 'duplicate' => true, 'event_id' => (string) $event['event_id']];
        }
        return $this->db()->transaction(function () use ($provider, $event, $intent): array {
            $this->db()->run(
                'INSERT OR IGNORE INTO sale_payment_webhook_events(site_id,provider_key,provider_event_id,event_type,provider_reference,provider_occurred_at,payload_json)
                 VALUES(?,?,?,?,?,?,?)',
                [(int) $intent['site_id'], $provider->key(), (string) $event['event_id'], (string) $event['type'], (string) $event['provider_reference'], (string) $event['occurred_at'], $this->json($this->redact($event))]
            );
            if ((int) ($this->db()->one('SELECT changes() AS count')['count'] ?? 0) !== 1) {
                $this->metric((int) $intent['site_id'], 'payment.webhook.duplicate', 'warning', (int) $intent['id'], ['provider' => $provider->key()]);
                return ['processed' => true, 'duplicate' => true, 'event_id' => (string) $event['event_id']];
            }
            $webhookId = (int) $this->db()->lastInsertId();
            if ($intent['last_provider_event_at'] !== null && (string) $event['occurred_at'] < (string) $intent['last_provider_event_at']) {
                $this->db()->run("UPDATE sale_payment_webhook_events SET processing_status='ignored_out_of_order',processed_at=CURRENT_TIMESTAMP WHERE id=?", [$webhookId]);
                $this->metric((int) $intent['site_id'], 'payment.webhook.out_of_order', 'warning', (int) $intent['id'], ['event_type' => (string) $event['type']]);
                return ['processed' => true, 'ignored_out_of_order' => true, 'event_id' => (string) $event['event_id']];
            }
            $result = $this->applyProviderEvent($intent, $event);
            $this->db()->run("UPDATE sale_payment_webhook_events SET processing_status='processed',processed_at=CURRENT_TIMESTAMP WHERE id=?", [$webhookId]);
            $this->metric((int) $intent['site_id'], 'payment.webhook.processed', 'info', (int) $intent['id'], ['event_type' => (string) $event['type']]);
            return ['processed' => true, 'duplicate' => false, 'event_id' => (string) $event['event_id']] + $result;
        });
    }

    /** @return array<string,mixed> */
    public function browserReturn(string $providerKey, string $reference, string $language = 'fr'): array
    {
        $intent = $this->requireIntentByReference($providerKey, $reference);
        $provider = $this->providers->contract($providerKey);
        $providerState = $provider->updatePaymentSession(['intent_id' => (int) $intent['id'], 'provider_reference' => $reference]);
        $this->metric((int) $intent['site_id'], 'payment.browser_return.observed', 'info', (int) $intent['id'], ['provider_status' => (string) $providerState['status']]);
        return [
            'intent' => $this->publicIntent($intent, null, false, $language),
            'provider_status' => (string) $providerState['status'],
            'local_status' => (string) $intent['status'],
            'awaiting_webhook' => !in_array((string) $intent['status'], ['captured','failed','cancelled','expired'], true),
            'payment_proof' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function simulateSandbox(string $reference, string $token, string $outcome, ?int $amountMinor, bool $deliverWebhook): array
    {
        $provider = $this->providers->get('sandbox');
        if (!$provider instanceof SandboxPaymentProvider) {
            throw new SalePaymentException('sale.payment_sandbox_unavailable');
        }
        $generated = $provider->simulate($reference, $token, $outcome, $amountMinor);
        $webhook = null;
        if ($deliverWebhook) {
            $webhook = $this->processWebhook('sandbox', $generated['body'], ['x-sale-signature' => $generated['signature']]);
        }
        return [
            'provider_status' => (string) ($generated['event']['data']['status'] ?? 'unknown'),
            'webhook_delivered' => $deliverWebhook,
            'webhook' => $webhook,
            'return_url' => '/api/v1/sale/payments/return?provider=sandbox&reference=' . rawurlencode($reference),
        ];
    }

    /** @return array<string,mixed> */
    public function simulateDeterministicTest(string $reference, string $token, string $outcome, bool $deliverWebhook): array
    {
        $provider = $this->providers->get('test');
        if (!$provider instanceof DeterministicTestPaymentProvider) throw new SalePaymentException('sale.payment_test_provider_unavailable');
        $generated = $provider->simulate($reference,$token,$outcome);
        $deliver = $deliverWebhook && $generated['deliver_webhook'];
        $webhook = null;
        if ($deliver) {
            if ($outcome === 'out_of_order_webhook') {
                $newer = $provider->simulate($reference, $token, 'authorize');
                $this->processWebhook('test', $newer['body'], ['x-sale-signature' => $newer['signature']]);
            }
            $webhook = $this->processWebhook('test',$generated['body'],['x-sale-signature'=>$generated['signature']]);
            if ($outcome === 'duplicate_webhook') {
                $webhook['duplicate_delivery'] = $this->processWebhook('test',$generated['body'],['x-sale-signature'=>$generated['signature']]);
            }
        }
        return ['test_mode'=>true,'provider_status'=>$generated['event']['data']['status']??'unknown','webhook_delivered'=>$deliver,'webhook'=>$webhook];
    }

    /** @return array<string,mixed> */
    public function reconcile(int $siteId, ?int $intentId = null, ?int $actorId = null): array
    {
        $intents = $intentId !== null
            ? [$this->payments->requireIntentWithOrder($intentId)]
            : $this->db()->all("SELECT * FROM sale_payment_intents WHERE site_id=? AND status IN ('requires_action','authorized','partially_captured','captured') ORDER BY id", [$siteId]);
        $results = [];
        foreach ($intents as $intent) {
            if ((int) $intent['site_id'] !== $siteId) {
                throw new SalePaymentException('sale.payment_intent_not_found');
            }
            try {
                $provider = $this->providers->contract((string) $intent['provider_key']);
                $state = $provider->reconcile([
                    'intent_id' => (int) $intent['id'], 'provider_reference' => (string) $intent['intent_reference'],
                ]);
                $findings = [];
                $status = 'consistent';
                $providerCaptured = (int) ($state['captured_minor'] ?? 0);
                $localCaptured = (int) $intent['captured_minor'];
                if ($providerCaptured > $localCaptured) {
                    $findings[] = 'provider_capture_missing_locally';
                    $event = [
                        'event_id' => 'reconcile_' . (int) $intent['id'] . '_' . substr(hash('sha256', $providerCaptured . '|' . ($state['provider_synced_at'] ?? '')), 0, 16),
                        'type' => 'payment.captured', 'provider_reference' => (string) $intent['intent_reference'],
                        'occurred_at' => (string) ($state['provider_synced_at'] ?? gmdate('Y-m-d H:i:s')),
                        'amount_minor' => $providerCaptured - $localCaptured, 'currency' => (string) $intent['currency'],
                        'provider_transaction_id' => 'reconcile_capture_' . (int) $intent['id'] . '_' . $providerCaptured,
                        'data' => ['status' => (string) $state['status'], 'captured_minor' => $providerCaptured],
                    ];
                    $this->db()->transaction(fn() => $this->applyProviderEvent($intent, $event));
                    $status = 'repaired';
                } elseif ($localCaptured > $providerCaptured) {
                    $findings[] = 'local_capture_without_provider_confirmation';
                    $status = 'attention_required';
                    $this->metric($siteId, 'payment.reconciliation.divergence', 'critical', (int) $intent['id'], ['kind' => $findings[0]]);
                } elseif (in_array((string) $state['status'], ['failed','cancelled','expired'], true)
                    && !in_array((string) $intent['status'], ['failed','cancelled','expired'], true)) {
                    $findings[] = 'provider_terminal_state_missing_locally';
                    $event = [
                        'event_id' => 'reconcile_terminal_' . (int) $intent['id'] . '_' . (string) $state['status'],
                        'type' => 'payment.' . ((string) $state['status'] === 'cancelled' ? 'cancelled' : ((string) $state['status'] === 'expired' ? 'expired' : 'failed')),
                        'provider_reference' => (string) $intent['intent_reference'], 'occurred_at' => (string) ($state['provider_synced_at'] ?? gmdate('Y-m-d H:i:s')),
                        'amount_minor' => 0, 'currency' => (string) $intent['currency'], 'provider_transaction_id' => '',
                        'data' => ['status' => (string) $state['status']],
                    ];
                    $this->db()->transaction(fn() => $this->applyProviderEvent($intent, $event));
                    $status = 'repaired';
                }
                $this->recordReconciliation($siteId, (int) $intent['id'], $status, (string) $state['status'], (string) $intent['status'], $findings, $actorId);
                $results[] = ['intent_id' => (int) $intent['id'], 'status' => $status, 'findings' => $findings];
            } catch (Throwable $e) {
                $this->recordReconciliation($siteId, (int) $intent['id'], 'failed', null, (string) $intent['status'], [$this->safeError($e->getMessage())], $actorId);
                $results[] = ['intent_id' => (int) $intent['id'], 'status' => 'failed'];
            }
        }
        return ['results' => $results, 'checked' => count($results)];
    }

    public function expireDue(?int $siteId = null): int
    {
        $params = [gmdate('Y-m-d H:i:s')];
        $siteSql = '';
        if ($siteId !== null) { $siteSql = ' AND site_id=?'; $params[] = $siteId; }
        $intents = $this->db()->all("SELECT * FROM sale_payment_intents WHERE status IN ('requires_payment','requires_action','authorized','partially_captured') AND expires_at IS NOT NULL AND expires_at<=?" . $siteSql, $params);
        foreach ($intents as $intent) {
            $this->db()->transaction(fn() => $this->applyProviderEvent($intent, [
                'event_id' => 'local_expiry_' . (int) $intent['id'], 'type' => 'payment.expired',
                'provider_reference' => (string) $intent['intent_reference'], 'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'amount_minor' => 0, 'currency' => (string) $intent['currency'], 'provider_transaction_id' => '', 'data' => ['status' => 'expired'],
            ]));
        }
        return count($intents);
    }

    /** @return array<string,mixed> */
    public function observability(int $siteId): array
    {
        return [
            'metrics' => $this->db()->all('SELECT metric_key,severity,COUNT(*) AS count,MAX(created_at) AS last_seen_at FROM sale_payment_observability WHERE site_id=? GROUP BY metric_key,severity ORDER BY metric_key', [$siteId]),
            'alerts' => $this->db()->all("SELECT * FROM sale_payment_observability WHERE site_id=? AND severity IN ('warning','critical') ORDER BY id DESC LIMIT 50", [$siteId]),
            'recent_reconciliations' => $this->db()->all('SELECT * FROM sale_payment_reconciliation_runs WHERE site_id=? ORDER BY id DESC LIMIT 50', [$siteId]),
        ];
    }

    /** @param array<string,mixed> $filters @return list<array<string,mixed>> */
    public function adminSessions(int $siteId, string $language, array $filters = [], int $limit = 100, int $offset = 0): array
    {
        return array_map(fn(array $session): array => $this->adminSessionPayload($session, $language), $this->payments->paymentSessions($siteId, $filters, $limit, $offset));
    }

    /** @return array<string,mixed> */
    public function adminSession(int $siteId, int $intentId, string $language): array
    {
        $session = $this->payments->paymentSessionDetail($siteId, $intentId);
        $payload = $this->adminSessionPayload($session, $language);
        $timeline = [];
        foreach ($session['attempts'] as $item) { $timeline[] = ['kind' => 'attempt', 'status' => $item['status'], 'at' => $item['started_at'], 'detail' => $item['error_code'] ?? null]; }
        foreach ($session['transactions'] as $item) { $timeline[] = ['kind' => $item['transaction_type'], 'status' => $item['status'], 'at' => $item['created_at'], 'amount_minor' => (int) $item['amount_minor']]; }
        foreach ($session['refunds'] as $item) { $timeline[] = ['kind' => 'refund', 'status' => $item['status'], 'at' => $item['created_at'], 'amount_minor' => (int) $item['amount_minor']]; }
        foreach ($session['provider_events'] as $item) { $timeline[] = ['kind' => 'provider_event', 'status' => $item['processing_status'], 'at' => $item['provider_occurred_at'], 'detail' => $item['event_type']]; }
        foreach ($session['reconciliations'] as $item) { $timeline[] = ['kind' => 'reconciliation', 'status' => $item['status'], 'at' => $item['created_at']]; }
        usort($timeline, static fn(array $a, array $b): int => strcmp((string) $a['at'], (string) $b['at']));
        return $payload + [
            'captures' => array_values(array_filter($session['transactions'], static fn(array $item): bool => in_array((string) $item['transaction_type'], ['payment','capture'], true))),
            'refunds' => $session['refunds'],
            'timeline' => $timeline,
            'technical' => [
                'contract_version' => (string) $session['contract_version'],
                'provider_key' => (string) $session['provider_key'],
                'provider_reference' => $session['intent_reference'],
                'last_provider_status' => $session['last_provider_status'],
                'provider_synced_at' => $session['provider_synced_at'],
                'attempts' => $session['attempts'],
                'provider_events' => $session['provider_events'],
                'reconciliations' => $session['reconciliations'],
            ],
        ];
    }

    /** @param array<string,mixed> $intent @param array<string,mixed> $event @return array<string,mixed> */
    private function applyProviderEvent(array $intent, array $event): array
    {
        $intent = $this->db()->one('SELECT * FROM sale_payment_intents WHERE id=?', [(int) $intent['id']]) ?? $intent;
        $order = $this->orders->requireOrder((int) $intent['order_id']);
        $type = (string) $event['type'];
        $providerStatus = (string) (($event['data']['status'] ?? null) ?: match ($type) {
            'payment.authorized' => 'authorized', 'payment.captured' => 'captured', 'payment.cancelled' => 'cancelled',
            'payment.expired' => 'expired', default => 'failed',
        });
        if ($type === 'payment.authorized') {
            $amount = min((int) $intent['amount_minor'], max(0, (int) $event['amount_minor']));
            if ((int) $intent['authorized_minor'] < $amount) {
                $this->payments->recordTransaction((int) $order['id'], $amount, (string) $intent['currency'], 'authorization', [
                    'payment_intent_id' => (int) $intent['id'], 'status' => 'succeeded', 'allocate' => false,
                    'provider_transaction_id' => $event['provider_transaction_id'] ?: null,
                    'provider_payload' => ['provider' => (string) $intent['provider_key'], 'event_id' => (string) $event['event_id']],
                ]);
            }
            $this->setIntent((int) $intent['id'], 'authorized', $providerStatus, (string) $event['occurred_at'], ['authorized_minor' => $amount]);
            $this->db()->run("UPDATE sale_orders SET payment_status='authorized',updated_at=CURRENT_TIMESTAMP WHERE id=?", [(int) $order['id']]);
        } elseif ($type === 'payment.captured') {
            $target = (int) ($event['data']['captured_minor'] ?? 0);
            if ($target < 1) { $target = (int) $intent['captured_minor'] + max(0, (int) $event['amount_minor']); }
            $target = min((int) $intent['amount_minor'], $target);
            $delta = max(0, $target - (int) $intent['captured_minor']);
            if ($delta > 0) {
                $this->payments->recordTransaction((int) $order['id'], $delta, (string) $intent['currency'], 'capture', [
                    'payment_intent_id' => (int) $intent['id'], 'status' => 'succeeded',
                    'provider_transaction_id' => $event['provider_transaction_id'] ?: null,
                    'provider_payload' => ['provider' => (string) $intent['provider_key'], 'event_id' => (string) $event['event_id']],
                ]);
            }
            $status = $target >= (int) $intent['amount_minor'] ? 'captured' : 'partially_captured';
            $this->setIntent((int) $intent['id'], $status, $providerStatus, (string) $event['occurred_at'], ['authorized_minor' => max((int) $intent['authorized_minor'], $target), 'captured_minor' => $target]);
            $order = $this->orders->updatePaidTotal((int) $order['id'], $this->payments->allocatedTotal((int) $order['id']));
            if ($status === 'captured' && (string) $order['status'] === 'pending_payment') {
                $this->inventory->consumeCartReservations((int) $order['source_cart_id'], (int) $order['id']);
                $order = $this->states->transition('order', (int) $order['id'], 'confirmed', null, 'provider payment captured');
                $this->db()->run('UPDATE sale_orders SET placed_at=COALESCE(placed_at,CURRENT_TIMESTAMP) WHERE id=?', [(int) $order['id']]);
            }
        } elseif (in_array($type, ['payment.failed','payment.cancelled','payment.expired'], true)) {
            $status = match ($type) { 'payment.cancelled' => 'cancelled', 'payment.expired' => 'expired', default => 'failed' };
            $this->setIntent((int) $intent['id'], $status, $providerStatus, (string) $event['occurred_at']);
            $retryable = $type === 'payment.failed';
            $this->db()->run("UPDATE sale_orders SET payment_status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?", [$retryable ? 'pending' : 'failed', (int) $order['id']]);
            if (!$retryable && (int) $order['paid_total_minor'] === 0 && (string) $order['status'] === 'pending_payment') {
                if ($order['source_cart_id'] !== null) {
                    $this->inventory->releaseCartReservations((int) $order['source_cart_id'], 'online payment ' . $status);
                }
                $this->states->transition('order', (int) $order['id'], 'cancelled', null, 'online payment ' . $status);
            }
        } else {
            throw new SalePaymentException('sale.payment_webhook_event_unsupported');
        }
        $attemptStatus = match ($type) { 'payment.captured' => 'succeeded', 'payment.failed' => 'failed', 'payment.cancelled' => 'cancelled', 'payment.expired' => 'timed_out', default => 'pending' };
        $this->db()->run('UPDATE sale_payment_attempts SET status=?,finished_at=CASE WHEN ? IN (\'succeeded\',\'failed\',\'cancelled\',\'timed_out\') THEN CURRENT_TIMESTAMP ELSE finished_at END WHERE payment_intent_id=? AND attempt_number=1', [$attemptStatus, $attemptStatus, (int) $intent['id']]);
        return ['intent' => $this->publicIntent($this->db()->one('SELECT * FROM sale_payment_intents WHERE id=?', [(int) $intent['id']]) ?? $intent)];
    }

    /** @param array<string,int> $amounts */
    private function setIntent(int $intentId, string $status, string $providerStatus, string $occurredAt, array $amounts = []): void
    {
        $this->db()->run(
            'UPDATE sale_payment_intents SET status=?,last_provider_status=?,last_provider_event_at=?,provider_synced_at=CURRENT_TIMESTAMP,
             authorized_minor=COALESCE(?,authorized_minor),captured_minor=COALESCE(?,captured_minor),updated_at=CURRENT_TIMESTAMP,version=version+1 WHERE id=?',
            [$status, $providerStatus, $occurredAt, $amounts['authorized_minor'] ?? null, $amounts['captured_minor'] ?? null, $intentId]
        );
    }

    /** @return array<string,mixed> */
    private function requireIntentByReference(string $providerKey, string $reference): array
    {
        $intent = $this->db()->one('SELECT * FROM sale_payment_intents WHERE provider_key=? AND intent_reference=?', [strtolower(trim($providerKey)), trim($reference)]);
        if ($intent === null) { throw new SalePaymentException('sale.payment_intent_not_found'); }
        return $intent;
    }

    /** @param array<string,mixed> $intent @return array<string,mixed> */
    private function publicIntent(array $intent, ?string $sandboxToken = null, bool $replayed = false, string $language = 'fr'): array
    {
        $result = [
            'id' => (int) $intent['id'], 'order_id' => (int) $intent['order_id'], 'provider' => (string) $intent['provider_key'],
            'contract' => (string) ($intent['contract_version'] ?? 'sale.payment_provider.v1'), 'reference' => $intent['intent_reference'] ?? null,
            'status' => (string) $intent['status'], 'amount_minor' => (int) $intent['amount_minor'], 'currency' => (string) $intent['currency'],
            'authorized_minor' => (int) ($intent['authorized_minor'] ?? 0), 'captured_minor' => (int) ($intent['captured_minor'] ?? 0),
            'refunded_minor' => (int) ($intent['refunded_minor'] ?? 0), 'checkout_url' => $intent['checkout_url'] ?? null,
            'expires_at' => $intent['expires_at'] ?? null, 'replayed' => $replayed,
            'state' => $this->statePresentation((string) $intent['status'], $language),
        ];
        $action = json_decode((string) ($intent['public_action_json'] ?? '{}'), true);
        if (is_array($action)) $result += $action;
        if ($sandboxToken !== null) { $result['sandbox_token'] = $sandboxToken; }
        return $result;
    }

    /** @param array<string,mixed> $session @return array<string,mixed> */
    private function adminSessionPayload(array $session, string $language): array
    {
        $customer = json_decode((string) ($session['customer_snapshot_json'] ?? '{}'), true);
        $action = json_decode((string) ($session['public_action_json'] ?? '{}'), true);
        $action = is_array($action) ? $action : [];
        return [
            'id' => (int) $session['id'], 'order_id' => (int) $session['order_id'], 'order_number' => (string) $session['order_number'],
            'amount_minor' => (int) $session['amount_minor'], 'currency' => (string) $session['currency'],
            'status' => (string) $session['status'], 'state' => $this->statePresentation((string) $session['status'], $language),
            'provider' => (string) $session['provider_key'], 'reference' => $session['intent_reference'],
            'authorized_minor' => (int) $session['authorized_minor'], 'captured_minor' => (int) $session['captured_minor'], 'refunded_minor' => (int) $session['refunded_minor'],
            'order_status' => (string) $session['order_status'], 'order_payment_status' => (string) $session['order_payment_status'],
            'customer' => is_array($customer) ? ['name' => trim((string) (($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''))), 'email' => $customer['email'] ?? null] : [],
            'created_at' => $session['created_at'], 'expires_at' => $session['expires_at'],
            'instructions' => is_array($action['instructions'] ?? null) ? $action['instructions'] : null,
            'test_mode' => ($action['test_mode'] ?? false) === true,
            'scenario' => $action['scenario'] ?? null,
        ];
    }

    /** @return array{code:string,label:string,severity:string,recoverable:bool,next_action:string,next_action_label:string} */
    private function statePresentation(string $status, string $language): array
    {
        $english = str_starts_with(strtolower($language), 'en');
        $values = [
            'requires_payment' => ['Paiement à initier','Payment to initiate','info',true,'start_payment','Initier le paiement','Start payment'],
            'requires_action' => ['Action client requise','Customer action required','warning',true,'resume_payment','Reprendre le paiement','Resume payment'],
            'authorized' => ['Paiement autorisé','Payment authorized','info',true,'capture','Capturer le paiement','Capture payment'],
            'partially_captured' => ['Paiement partiel','Partially paid','warning',true,'complete_payment','Compléter le paiement','Complete payment'],
            'captured' => ['Paiement reçu','Payment received','success',false,'none','Aucune action','No action'],
            'cancelled' => ['Paiement annulé','Payment cancelled','neutral',true,'choose_another_method','Choisir un autre moyen','Choose another method'],
            'failed' => ['Paiement refusé','Payment failed','danger',true,'retry_or_choose_another_method','Réessayer ou changer de moyen','Retry or choose another method'],
            'expired' => ['Session expirée','Session expired','warning',true,'restart_payment','Redémarrer le paiement','Restart payment'],
        ];
        $value = $values[$status] ?? [$status, $status, 'neutral', false, 'none', 'Aucune action', 'No action'];
        return ['code' => $status, 'label' => $english ? $value[1] : $value[0], 'severity' => $value[2], 'recoverable' => $value[3], 'next_action' => $value[4], 'next_action_label' => $english ? $value[6] : $value[5]];
    }

    /** @param list<string> $findings */
    private function recordReconciliation(int $siteId, int $intentId, string $status, ?string $providerStatus, string $localStatus, array $findings, ?int $actorId): void
    {
        $this->db()->run(
            'INSERT INTO sale_payment_reconciliation_runs(site_id,payment_intent_id,trigger_kind,status,provider_status,local_status,findings_json,created_by_iam_user_id) VALUES(?,?,\'manual\',?,?,?,?,?)',
            [$siteId, $intentId, $status, $providerStatus, $localStatus, $this->json($findings), $actorId]
        );
    }

    /** @param array<string,mixed> $dimensions */
    private function metric(int $siteId, string $key, string $severity, ?int $intentId, array $dimensions): void
    {
        $this->db()->run('INSERT INTO sale_payment_observability(site_id,metric_key,severity,payment_intent_id,dimensions_json) VALUES(?,?,?,?,?)', [$siteId, $key, $severity, $intentId, $this->json($this->redact($dimensions))]);
        $this->logger?->info('sale.payment.metric', ['site_id' => $siteId, 'metric' => $key, 'severity' => $severity, 'payment_intent_id' => $intentId]);
    }

    private function db(): Database { return $this->connection->database() ?? throw new SalePaymentException('sale.database_unavailable'); }
    private function json(mixed $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'; }
    private function safeError(string $message): string { return mb_substr(preg_replace('/\b(?:\d[ -]*?){12,19}\b/', '[redacted]', $message) ?: 'payment error', 0, 240); }

    private function safeRedirectUrl(mixed $value): ?string
    {
        $url = trim((string) $value);
        if ($url === '') { return null; }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (strlen($url) > 2048 || !in_array($scheme, ['http','https'], true) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new SalePaymentException('sale.payment_return_url_invalid');
        }
        return $url;
    }

    private function redact(mixed $value): mixed
    {
        if (!is_array($value)) { return $value; }
        $clean = [];
        foreach ($value as $key => $item) {
            if (preg_match('/(?:pan|card(?:_?number)?|cvc|cvv|expiry|secret)/i', (string) $key) === 1) { $clean[$key] = '[redacted]'; continue; }
            $clean[$key] = is_array($item) ? $this->redact($item) : (is_string($item) ? $this->safeError($item) : $item);
        }
        return $clean;
    }
}
