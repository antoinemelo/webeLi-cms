<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Business\Repositories\BusinessActivityRepository;
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
        private readonly BusinessActivityRepository $activity,
    ) {}

    public function providers(): Response
    {
        [$site, $languageCode] = $this->authorize('business.messaging.admin');
        return Response::success($this->providers->providers((int) $site['id']), 'admin.business.messaging.providers.v1', $this->meta($site, $languageCode));
    }

    public function storeProvider(): Response
    {
        [$site, $languageCode] = $this->authorize('business.messaging.admin');
        try {
            $provider = $this->messages->saveProvider((int) $site['id'], $this->payload(), $this->actorId());
            return Response::success(['provider' => $this->safeProvider($provider), 'message' => 'Provider enregistré.'], 'admin.business.messaging.providers.store.v1', $this->meta($site, $languageCode), 201);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function updateProvider(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.messaging.admin');
        try {
            $payload = $this->payload();
            $payload['id'] = max(0, (int) $id);
            $provider = $this->messages->saveProvider((int) $site['id'], $payload, $this->actorId());
            return Response::success(['provider' => $this->safeProvider($provider), 'message' => 'Provider mis à jour.'], 'admin.business.messaging.providers.update.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function deleteProvider(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.messaging.admin');
        $providerId = max(0, (int) $id);
        if ($providerId < 1 || $this->messages->findProvider((int) $site['id'], $providerId) === null) {
            return Response::error('ROUTE_NOT_FOUND', 'Provider introuvable.', 404, ['id' => (string) $id]);
        }
        $this->messages->deleteProvider((int) $site['id'], $providerId);
        return Response::success(['deleted' => true, 'id' => $providerId, 'message' => 'Provider supprimé.'], 'admin.business.messaging.providers.delete.v1', $this->meta($site, $languageCode));
    }

    public function outbox(): Response
    {
        [$site, $languageCode] = $this->authorize('business.messaging.admin');
        $result = $this->messages->listOutbox(
            (int) $site['id'],
            trim((string) ($this->request->query['status'] ?? '')),
            $this->limit(),
            $this->offset(),
            [
                'channel' => trim((string) ($this->request->query['channel'] ?? '')),
                'q' => trim((string) ($this->request->query['q'] ?? '')),
            ]
        );
        return Response::success(['messages' => $result['items'], 'pagination' => ['limit' => $result['limit'], 'offset' => $result['offset']]], 'admin.business.messaging.outbox.v1', $this->meta($site, $languageCode));
    }

    public function messages(): Response
    {
        [$site, $languageCode] = $this->authorize('business.messaging.admin');
        $result = $this->messages->listOutbox(
            (int) $site['id'],
            trim((string) ($this->request->query['status'] ?? '')),
            $this->limit(),
            $this->offset(),
            [
                'channel' => trim((string) ($this->request->query['channel'] ?? '')),
                'q' => trim((string) ($this->request->query['q'] ?? '')),
            ]
        );
        return Response::success(['messages' => $result['items'], 'pagination' => ['limit' => $result['limit'], 'offset' => $result['offset']]], 'admin.business.messages.index.v1', $this->meta($site, $languageCode));
    }

    public function showMessage(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.messaging.admin');
        $message = $this->messages->findOutbox((int) $site['id'], max(0, (int) $id));
        if ($message === null) {
            return Response::error('ROUTE_NOT_FOUND', 'Message introuvable.', 404, ['id' => (string) $id]);
        }
        return Response::success(['message' => $message, 'events' => $this->messages->deliveryEvents((int) $message['id'])], 'admin.business.messages.show.v1', $this->meta($site, $languageCode));
    }

    public function storeMessage(): Response
    {
        return $this->sendMessage();
    }

    public function relationMessages(string $type, string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.messaging.send');
        try {
            $result = $this->messages->relationMessages((int) $site['id'], $type, max(0, (int) $id), $this->limit(), $this->offset());
            return Response::success(['messages' => $result['items'], 'pagination' => ['limit' => $result['limit'], 'offset' => $result['offset']]], 'admin.business.relations.messages.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function previewMessage(): Response
    {
        [$site, $languageCode] = $this->authorize('business.messaging.send');
        try {
            $payload = $this->payload();
            $contactId = $this->contactIdFromPayload((int) $site['id'], $payload);
            $channel = (string) ($payload['channel'] ?? 'email');
            $preview = $this->outbox->previewMessage($contactId, $channel);
            return Response::success([
                'preview' => $preview,
                'providers' => $this->providersForChannel((int) $site['id'], (string) $preview['channel']),
            ], 'admin.business.messages.preview.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
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

    public function testMessage(): Response
    {
        return $this->sendTest();
    }

    public function sendContactMessage(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.messaging.send');
        $contactId = max(0, (int) $id);
        if ($this->contacts->find((int) $site['id'], $contactId) === null) {
            return Response::error('ROUTE_NOT_FOUND', 'Contact introuvable.', 404, ['id' => (string) $id]);
        }
        return $this->sendForContact((int) $site['id'], $languageCode, $contactId, null, 'admin.business.contacts.messages.send.v1');
    }

    public function sendRelationMessage(string $type, string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.messaging.send');
        if ($type !== 'contact') {
            return Response::validation(['business' => ['business.relation_messages_require_contact']], 'Les messages directs sont disponibles pour les personnes.');
        }
        $contactId = max(0, (int) $id);
        if ($this->contacts->find((int) $site['id'], $contactId) === null) {
            return Response::error('ROUTE_NOT_FOUND', 'Relation introuvable.', 404, ['id' => (string) $id]);
        }
        return $this->sendForContact((int) $site['id'], $languageCode, $contactId, null, 'admin.business.relations.messages.send.v1');
    }

    public function sendMessage(): Response
    {
        [$site, $languageCode] = $this->authorize('business.messaging.send');
        try {
            $payload = $this->payload();
            return $this->sendForContact((int) $site['id'], $languageCode, $this->contactIdFromPayload((int) $site['id'], $payload), $payload, 'admin.business.messages.send.v1');
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    /** @param array<string,mixed>|null $payload */
    private function sendForContact(int $siteId, string $languageCode, int $contactId, ?array $payload = null, string $contract = 'admin.business.messages.send.v1'): Response
    {
        try {
            $payload ??= $this->payload();
            $message = $this->outbox->prepareMessage($siteId, $contactId, (string) ($payload['channel'] ?? 'email'), [
                'subject' => $payload['subject'] ?? null,
                'body_text' => (string) ($payload['body_text'] ?? ''),
                'body_html' => $payload['body_html'] ?? null,
                'payload' => ['source' => 'admin.contact-message'],
                'status' => 'pending',
            ], $this->actorId());
            $result = $this->providers->sendOutboxMessage($siteId, (int) $message['id'], isset($payload['provider_key']) ? (string) $payload['provider_key'] : null);
            $contact = $this->contacts->find($siteId, $contactId, true);
            $status = (string) ($result['message']['status'] ?? 'pending');
            $this->activity->log($siteId, $this->actorId(), 'crm_message_outbox', (int) $message['id'], isset($contact['company_id']) ? (int) $contact['company_id'] : null, $contactId, 'business.message.' . $status, 'Message ' . (string) ($message['channel'] ?? '') . ' ' . $this->messageStatusLabel($status) . '.', ['status' => $status, 'channel' => $message['channel'] ?? null]);
            return Response::success(['message' => $result['message'], 'send_result' => $result['result']], $contract, $this->meta(['id' => $siteId], $languageCode), 201);
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
        return Response::validation(['business' => [$e->getMessage()]], $this->validationMessage($e));
    }

    private function defaultTestRecipient(string $channel): string
    {
        return match ($channel) {
            'whatsapp' => '+41000000000',
            'telegram' => 'test_chat',
            default => 'runtime@example.test',
        };
    }

    /** @param array<string,mixed> $payload */
    private function contactIdFromPayload(int $siteId, array $payload): int
    {
        $contactId = max(0, (int) ($payload['contact_id'] ?? 0));
        $relationType = (string) ($payload['relation_type'] ?? '');
        $relationId = max(0, (int) ($payload['relation_id'] ?? 0));
        if ($contactId < 1 && $relationType === 'contact') {
            $contactId = $relationId;
        }
        if ($contactId < 1) {
            throw new InvalidArgumentException('business.contact_id_invalid');
        }
        if ($this->contacts->find($siteId, $contactId) === null) {
            throw new InvalidArgumentException('business.contact_not_found');
        }
        return $contactId;
    }

    /** @return array<string,mixed> */
    private function providersForChannel(int $siteId, string $channel): array
    {
        $all = $this->providers->providers($siteId);
        return [
            'runtime' => array_values(array_filter($all['runtime'] ?? [], static fn(array $provider): bool => ($provider['channel'] ?? '') === $channel)),
            'configured' => array_values(array_filter($all['configured'] ?? [], static fn(array $provider): bool => ($provider['channel'] ?? '') === $channel)),
        ];
    }

    /** @param array<string,mixed> $provider @return array<string,mixed> */
    private function safeProvider(array $provider): array
    {
        unset($provider['secret_ref']);
        if (isset($provider['config']) && is_array($provider['config'])) {
            foreach (array_keys($provider['config']) as $key) {
                if (str_contains((string) $key, 'token') || str_contains((string) $key, 'secret')) {
                    $provider['config'][$key] = '***';
                }
            }
        }
        return $provider;
    }

    private function validationMessage(InvalidArgumentException $e): string
    {
        return match ($e->getMessage()) {
            'business.messaging_consent_required' => 'Consentement messaging requis pour ce canal.',
            'business.messaging_channel_missing' => 'Aucun canal destinataire disponible pour ce contact.',
            'business.contact_not_found' => 'Contact introuvable.',
            default => 'Donnée messaging invalide.',
        };
    }

    private function messageStatusLabel(string $status): string
    {
        return match ($status) {
            'sent' => 'envoyé',
            'failed' => 'en échec',
            'queued' => 'mis en file',
            'skipped' => 'ignoré',
            default => 'préparé',
        };
    }
}
