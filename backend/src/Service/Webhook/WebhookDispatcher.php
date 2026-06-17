<?php

declare(strict_types=1);

namespace App\Service\Webhook;

use App\Application\Webhook\WebhookRepository;
use App\Core\Logger;
use App\Service\OutboxService;

final class WebhookDispatcher
{
    public function __construct(
        private readonly OutboxService $outbox,
        private readonly WebhookRepository $webhooks,
        private readonly Logger $logger,
        private readonly ?WebhookHttpClient $http = null,
    ) {}

    public function scheduleFromOutbox(int $limit = 25, int $maxAttempts = 5): int
    {
        $scheduled = 0;
        foreach ($this->outbox->claimBatch($limit, $maxAttempts) as $event) {
            $eventId = (int) $event['id'];
            try {
                $topic = (string) $event['topic'];
                $payload = json_decode((string) $event['payload_json'], true) ?: [];
                $siteId = isset($payload['site_id']) ? (int) $payload['site_id'] : null;
                foreach ($this->webhooks->matchingEndpoints($topic, $siteId) as $endpoint) {
                    $deliveryId = $this->deliveryId($eventId, (int) $endpoint['id']);
                    $this->webhooks->createDelivery((int) $endpoint['id'], $eventId, $topic, $this->buildPayload($eventId, $topic, $payload, $deliveryId), $deliveryId);
                    $scheduled++;
                }
                $this->outbox->markProcessed($eventId);
                $this->logger->info('webhook.outbox_scheduled', ['event_id' => $eventId, 'topic' => $topic, 'deliveries' => $scheduled]);
            } catch (\Throwable $e) {
                $attempts = ((int) ($event['attempts'] ?? 0)) + 1;
                $this->outbox->markFailed($eventId, $e->getMessage(), $attempts);
                $this->logger->error('webhook.outbox_failed', ['event_id' => $eventId, 'attempts' => $attempts, 'message' => $e->getMessage()]);
            }
        }
        return $scheduled;
    }

    public function deliverDue(int $limit = 25): int
    {
        $sent = 0;
        $http = $this->http ?? new WebhookHttpClient();
        foreach ($this->webhooks->claimDueDeliveries($limit) as $delivery) {
            $body = (string) $delivery['payload_json'];
            $topic = (string) $delivery['event_topic'];
            $deliveryId = (string) $delivery['delivery_id'];
            $secret = (string) $delivery['secret'];
            $headers = [
                'X-AMCMS-Signature' => WebhookSigner::sign($body, $secret),
                'X-AMCMS-Event' => $topic,
                'X-AMCMS-Delivery' => $deliveryId,
            ];

            $response = ['status' => null, 'body' => ''];
            try {
                $response = $http->postJson((string) $delivery['url'], $body, $headers);
                $status = (int) ($response['status'] ?? 0);
                $responseBody = (string) ($response['body'] ?? '');
                if ($status < 200 || $status >= 300) {
                    throw new \RuntimeException('Webhook endpoint returned HTTP ' . $status);
                }
                $this->webhooks->markSucceeded((int) $delivery['id'], $status, $responseBody);
                $this->logger->info('webhook.delivered', ['delivery_id' => $deliveryId, 'event' => $topic, 'status' => $status]);
                $sent++;
            } catch (\Throwable $e) {
                $status = isset($response['status']) ? (int) $response['status'] : null;
                $responseBody = isset($response['body']) ? (string) $response['body'] : '';
                $this->webhooks->markFailed((int) $delivery['id'], $e->getMessage(), $status, $responseBody, (int) $delivery['max_attempts']);
                $this->logger->error('webhook.delivery_failed', ['delivery_id' => $deliveryId, 'event' => $topic, 'message' => $e->getMessage(), 'status' => $status]);
            }
        }
        return $sent;
    }

    public function run(int $limit = 25, int $maxAttempts = 5): array
    {
        $scheduled = $this->scheduleFromOutbox($limit, $maxAttempts);
        $delivered = $this->deliverDue($limit);
        return ['scheduled' => $scheduled, 'delivered' => $delivered];
    }

    private function deliveryId(int $eventId, int $webhookId): string
    {
        return 'wh_' . $eventId . '_' . $webhookId . '_' . bin2hex(random_bytes(6));
    }

    private function buildPayload(int $eventId, string $topic, array $data, string $deliveryId): array
    {
        return [
            'id' => $deliveryId,
            'event_id' => $eventId,
            'event' => $topic,
            'occurred_at' => now_utc(),
            'data' => $data,
        ];
    }
}
