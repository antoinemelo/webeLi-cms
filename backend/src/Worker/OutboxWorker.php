<?php

declare(strict_types=1);

namespace App\Worker;

use App\Core\Logger;
use App\Repository\SystemJobRepository;
use App\Service\OutboxService;
use App\Service\Webhook\WebhookDispatcher;

final class OutboxWorker
{
    /** @var callable|null */
    private $consumer;

    public function __construct(
        private readonly OutboxService $outbox,
        private readonly Logger $logger,
        private readonly ?SystemJobRepository $jobs = null,
        private readonly ?WebhookDispatcher $webhooks = null,
        ?callable $consumer = null,
        private readonly string $consumerKey = 'core.outbox.worker',
    ) {
        $this->consumer = $consumer;
    }

    public function run(int $limit = 25, int $maxAttempts = 5): int
    {
        if ($this->webhooks !== null) {
            $result = $this->webhooks->run($limit, $maxAttempts);
            $count = (int) $result['scheduled'] + (int) $result['delivered'];
            $this->jobs?->touch('outbox.worker', 'ok', 'Webhook deliveries scheduled=' . $result['scheduled'] . ', delivered=' . $result['delivered']);
            return $count;
        }

        $count = 0;
        $events = $this->outbox->claimBatch($limit, $maxAttempts);
        foreach ($events as $event) {
            $context = $this->logContext($event);
            try {
                $this->assertValidEnvelope($event);
                $eventId = (string) $event['event_id'];
                if ($this->outbox->wasConsumed($eventId, $this->consumerKey)) {
                    $this->logger->info('outbox.idempotent_skip', $context + ['consumer' => $this->consumerKey]);
                    $this->outbox->markProcessed((int) $event['id']);
                    $count++;
                    continue;
                }
                $result = $this->consume($event);
                $this->outbox->recordConsumption($eventId, $this->consumerKey, $result);
                $this->logger->info('outbox.processed', $context + ['consumer' => $this->consumerKey]);
                $this->outbox->markProcessed((int) $event['id']);
                $count++;
            } catch (\Throwable $e) {
                $attempts = (int) ($event['attempts'] ?? 1);
                $this->outbox->markFailed((int) $event['id'], $e->getMessage(), $attempts, $maxAttempts, $e instanceof \InvalidArgumentException ? 'invalid_payload' : 'consumer_error');
                $this->logger->error('outbox.failed', $context + [
                    'attempts' => $attempts,
                    'max_attempts' => $maxAttempts,
                    'error_type' => $e instanceof \InvalidArgumentException ? 'invalid_payload' : 'consumer_error',
                    'message' => mb_substr($e->getMessage(), 0, 300),
                ]);
            }
        }
        $this->jobs?->touch('outbox.worker', 'ok', 'Processed ' . $count . ' event(s)');
        return $count;
    }

    /** @param array<string,mixed> $event @return array<string,mixed> */
    private function consume(array $event): array
    {
        if ($this->consumer !== null) {
            $result = ($this->consumer)($event);
            return is_array($result) ? $result : ['result' => 'ok'];
        }
        return ['result' => 'logged'];
    }

    /** @param array<string,mixed> $event */
    private function assertValidEnvelope(array $event): void
    {
        foreach (['event_id', 'event_type', 'schema_version', 'occurred_at', 'correlation_id', 'aggregate_type', 'payload_json'] as $key) {
            if (!isset($event[$key]) || trim((string) $event[$key]) === '') {
                throw new \InvalidArgumentException('outbox.invalid_envelope_missing_' . $key);
            }
        }
        $payload = json_decode((string) $event['payload_json'], true);
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('outbox.invalid_payload_json');
        }
    }

    /** @param array<string,mixed> $event @return array<string,mixed> */
    private function logContext(array $event): array
    {
        return [
            'request_id' => \App\Core\Response::requestId(),
            'event_row_id' => (int) ($event['id'] ?? 0),
            'event_id' => (string) ($event['event_id'] ?? ''),
            'event_type' => (string) ($event['event_type'] ?? ($event['topic'] ?? '')),
            'correlation_id' => (string) ($event['correlation_id'] ?? ''),
            'causation_id' => $event['causation_id'] ?? null,
            'aggregate_type' => (string) ($event['aggregate_type'] ?? ''),
            'aggregate_id' => $event['aggregate_id'] ?? null,
        ];
    }
}
