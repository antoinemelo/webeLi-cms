<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Business\Repositories\BusinessContactRepository;
use App\Modules\Business\Repositories\BusinessMessagingRepository;
use App\Modules\Business\Services\BusinessMessagingOutboxService;
use App\Modules\Business\Services\BusinessMessagingProviderManager;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;
use InvalidArgumentException;

final class BusinessMessagingApiController
{
    public function __construct(
        private readonly Request $request,
        private readonly SiteRepository $sites,
        private readonly AuthRepository $auth,
        private readonly Authorization $authorization,
        private readonly BusinessMessagingRepository $messages,
        private readonly BusinessMessagingOutboxService $outbox,
        private readonly BusinessMessagingProviderManager $providers,
        private readonly BusinessContactRepository $contacts,
    ) {}

    public function providers(): Response
    {
        [$site, $languageCode] = $this->authorize('business.messaging.admin');
        return Response::success($this->providers->providers((int) $site['id']), 'admin.business.messaging.providers.v1', $this->meta($site, $languageCode));
    }

    public function outbox(): Response
    {
        [$site, $languageCode] = $this->authorize('business.messaging.admin');
        $result = $this->messages->listOutbox((int) $site['id'], trim((string) ($this->request->query['status'] ?? '')), $this->limit(), $this->offset());
        return Response::success(['messages' => $result['items'], 'pagination' => ['limit' => $result['limit'], 'offset' => $result['offset']]], 'admin.business.messaging.outbox.v1', $this->meta($site, $languageCode));
    }

    public function sendTest(): Response
    {
        [$site, $languageCode] = $this->authorize('business.messaging.admin');
        try {
            $payload = $this->payload();
            $channel = (string) ($payload['channel'] ?? 'email');
            $providerKey = trim((string) ($payload['provider_key'] ?? ''));
            if ($providerKey !== '' && $providerKey !== 'log_only' && empty($payload['confirm_external_test'])) {
                throw new InvalidArgumentException('business.messaging_test_external_confirmation_required');
            }
            $message = $this->messages->createOutbox((int) $site['id'], [
                'channel' => $channel,
                'recipient_value' => (string) ($payload['recipient_value'] ?? $this->defaultTestRecipient($channel)),
                'subject' => (string) ($payload['subject'] ?? 'Test Business messaging'),
                'body_text' => (string) ($payload['body_text'] ?? 'Message de test Business.'),
                'payload' => ['test' => true, 'source' => 'admin.send-test'],
                'status' => 'pending',
            ], $this->actorId());
            $result = $this->providers->sendOutboxMessage((int) $site['id'], (int) $message['id'], $providerKey !== '' && $providerKey !== 'log_only' ? $providerKey : null);
            return Response::success(['message' => $result['message'], 'send_result' => $result['result']], 'admin.business.messaging.send_test.v1', $this->meta($site, $languageCode), 201);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function sendContactMessage(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.messaging.send');
        $contactId = max(0, (int) $id);
        if ($this->contacts->find((int) $site['id'], $contactId) === null) {
            return Response::error('ROUTE_NOT_FOUND', 'Contact introuvable.', 404, ['id' => (string) $id]);
        }
        try {
            $payload = $this->payload();
            $message = $this->outbox->prepareMessage((int) $site['id'], $contactId, (string) ($payload['channel'] ?? 'email'), [
                'subject' => $payload['subject'] ?? null,
                'body_text' => (string) ($payload['body_text'] ?? ''),
                'body_html' => $payload['body_html'] ?? null,
                'payload' => ['source' => 'admin.contact-message'],
                'status' => 'pending',
            ], $this->actorId());
            $result = $this->providers->sendOutboxMessage((int) $site['id'], (int) $message['id'], isset($payload['provider_key']) ? (string) $payload['provider_key'] : null);
            return Response::success(['message' => $result['message'], 'send_result' => $result['result']], 'admin.business.contacts.messages.send.v1', $this->meta($site, $languageCode), 201);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    /** @return array{0:array<string,mixed>,1:string} */
    private function authorize(string $permission): array
    {
        $this->auth->requireAuth();
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null, $this->auth);
        $this->authorization->require($permission, (int) $site['id']);
        return [$site, AdminApiContract::language($this->request, $this->sites, $site)];
    }

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

    private function limit(): int
    {
        return max(1, min(200, (int) ($this->request->query['limit'] ?? 50)));
    }

    private function offset(): int
    {
        return max(0, (int) ($this->request->query['offset'] ?? 0));
    }

    /** @param array<string,mixed> $site */
    private function meta(array $site, string $languageCode): array
    {
        return AdminApiContract::meta($site, $languageCode);
    }

    private function validation(InvalidArgumentException $e): Response
    {
        return Response::validation(['business' => [$e->getMessage()]], 'Donnée messaging invalide.');
    }

    private function defaultTestRecipient(string $channel): string
    {
        return match ($channel) {
            'whatsapp' => '+41000000000',
            'telegram' => 'test_chat',
            default => 'test@example.test',
        };
    }
}
