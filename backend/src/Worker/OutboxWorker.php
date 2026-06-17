<?php

declare(strict_types=1);

namespace App\Worker;

use App\Core\Logger;
use App\Repository\SystemJobRepository;
use App\Service\OutboxService;
use App\Service\Webhook\WebhookDispatcher;

final class OutboxWorker
{
    public function __construct(
        private readonly OutboxService $outbox,
        private readonly Logger $logger,
        private readonly ?SystemJobRepository $jobs = null,
        private readonly ?WebhookDispatcher $webhooks = null,
    ) {}

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
            try {
                $payload = json_decode((string) $event['payload_json'], true) ?: [];
                $this->logger->info('outbox.processed', ['topic' => $event['topic'], 'event_id' => $event['id'], 'payload' => $payload]);
                $this->outbox->markProcessed((int) $event['id']);
                $count++;
            } catch (\Throwable $e) {
                $attempts = ((int) ($event['attempts'] ?? 0)) + 1;
                $this->outbox->markFailed((int) $event['id'], $e->getMessage(), $attempts);
                $this->logger->error('outbox.failed', ['event_id' => $event['id'], 'attempts' => $attempts, 'message' => $e->getMessage()]);
            }
        }
        $this->jobs?->touch('outbox.worker', 'ok', 'Processed ' . $count . ' event(s)');
        return $count;
    }
}
