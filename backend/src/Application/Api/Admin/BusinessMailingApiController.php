<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Core\ErrorCode;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Business\Repositories\BusinessContactRepository;
use App\Modules\Business\Repositories\BusinessMailingRepository;
use App\Modules\Business\Services\BusinessMailingService;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;
use InvalidArgumentException;

final class BusinessMailingApiController
{
    public function __construct(
        private readonly Request $request,
        private readonly SiteRepository $sites,
        private readonly AuthRepository $auth,
        private readonly Authorization $authorization,
        private readonly BusinessMailingRepository $mailing,
        private readonly BusinessMailingService $service,
        private readonly BusinessContactRepository $contacts,
    ) {}

    public function lists(): Response
    {
        [$site, $languageCode] = $this->authorize('business.mailing.read');
        $result = $this->mailing->lists((int) $site['id'], $this->limit(), $this->offset(), $this->includeArchived());
        return Response::success(['lists' => $result['items'], 'pagination' => $this->pagination($result)], 'admin.business.mailing.lists.index.v1', $this->meta($site, $languageCode));
    }

    public function storeList(): Response
    {
        [$site, $languageCode] = $this->authorize('business.mailing.manage');
        try {
            $list = $this->mailing->createList((int) $site['id'], $this->payload(), $this->actorId());
            return Response::success(['list' => $list, 'message' => 'Liste créée.'], 'admin.business.mailing.lists.show.v1', $this->meta($site, $languageCode), 201);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function showList(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.mailing.read');
        $list = $this->mailing->findList((int) $site['id'], $this->id($id));
        if (!$list) {
            return $this->notFound('Liste introuvable.', $id);
        }
        return Response::success(['list' => $list, 'members' => $this->mailing->members((int) $site['id'], $this->id($id))], 'admin.business.mailing.lists.show.v1', $this->meta($site, $languageCode));
    }

    public function updateList(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.mailing.manage');
        try {
            $list = $this->mailing->updateList((int) $site['id'], $this->id($id), $this->payload(), $this->actorId());
            if (!$list) {
                return $this->notFound('Liste introuvable.', $id);
            }
            return Response::success(['list' => $list, 'message' => 'Liste mise à jour.'], 'admin.business.mailing.lists.show.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function addMember(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.mailing.manage');
        try {
            $contactId = (int) ($this->payload()['contact_id'] ?? 0);
            if ($this->contacts->find((int) $site['id'], $contactId) === null) {
                return $this->notFound('Contact introuvable.', $contactId);
            }
            $member = $this->mailing->addMember((int) $site['id'], $this->id($id), $contactId, $this->actorId(), hash('sha256', bin2hex(random_bytes(24))));
            return Response::success(['member' => $member, 'message' => 'Contact ajouté à la liste.'], 'admin.business.mailing.members.write.v1', $this->meta($site, $languageCode), 201);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function removeMember(string|int $id, string|int $contactId): Response
    {
        [$site, $languageCode] = $this->authorize('business.mailing.manage');
        try {
            $this->mailing->removeMember((int) $site['id'], $this->id($id), $this->id($contactId), $this->actorId());
            return Response::success(['removed' => true, 'contact_id' => $this->id($contactId)], 'admin.business.mailing.members.delete.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function campaigns(): Response
    {
        [$site, $languageCode] = $this->authorize('business.mailing.read');
        $result = $this->mailing->campaigns((int) $site['id'], $this->limit(), $this->offset());
        return Response::success(['campaigns' => $result['items'], 'pagination' => $this->pagination($result)], 'admin.business.mailing.campaigns.index.v1', $this->meta($site, $languageCode));
    }

    public function storeCampaign(): Response
    {
        [$site, $languageCode] = $this->authorize('business.mailing.manage');
        try {
            $campaign = $this->mailing->createCampaign((int) $site['id'], $this->payload(), $this->actorId());
            return Response::success(['campaign' => $campaign, 'message' => 'Campagne créée en brouillon.'], 'admin.business.mailing.campaigns.show.v1', $this->meta($site, $languageCode), 201);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function showCampaign(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.mailing.read');
        $campaign = $this->mailing->findCampaign((int) $site['id'], $this->id($id));
        if (!$campaign) {
            return $this->notFound('Campagne introuvable.', $id);
        }
        return Response::success(['campaign' => $campaign], 'admin.business.mailing.campaigns.show.v1', $this->meta($site, $languageCode));
    }

    public function updateCampaign(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.mailing.manage');
        try {
            $campaign = $this->mailing->updateCampaign((int) $site['id'], $this->id($id), $this->payload(), $this->actorId());
            if (!$campaign) {
                return $this->notFound('Campagne introuvable.', $id);
            }
            return Response::success(['campaign' => $campaign, 'message' => 'Campagne mise à jour.'], 'admin.business.mailing.campaigns.show.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function previewRecipients(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.mailing.read');
        try {
            return Response::success($this->service->previewRecipients((int) $site['id'], $this->id($id)), 'admin.business.mailing.campaigns.preview_recipients.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function enqueueCampaign(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.mailing.manage');
        try {
            return Response::success($this->service->enqueueCampaign((int) $site['id'], $this->id($id), $this->actorId()), 'admin.business.mailing.campaigns.enqueue.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function cancelCampaign(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.mailing.manage');
        try {
            return Response::success($this->service->cancelCampaign((int) $site['id'], $this->id($id), $this->actorId()), 'admin.business.mailing.campaigns.cancel.v1', $this->meta($site, $languageCode));
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

    private function actorId(): int { return (int) ($this->auth->user()['id'] ?? 0); }
    private function id(string|int $id): int { return max(0, (int) $id); }
    private function limit(): int { return max(1, min(200, (int) ($this->request->query['limit'] ?? 50))); }
    private function offset(): int { return max(0, (int) ($this->request->query['offset'] ?? 0)); }
    private function includeArchived(): bool { return in_array((string) ($this->request->query['archived'] ?? '0'), ['1', 'all'], true); }
    private function pagination(array $result): array { return ['limit' => (int) $result['limit'], 'offset' => (int) $result['offset']]; }
    private function meta(array $site, string $languageCode): array { return AdminApiContract::meta($site, $languageCode); }
    private function validation(InvalidArgumentException $e): Response { return Response::validation(['business' => [$e->getMessage()]], 'Donnée mailing invalide.'); }
    private function notFound(string $message, string|int $id): Response { return Response::error(ErrorCode::ROUTE_NOT_FOUND, $message, 404, ['id' => (string) $id]); }
}
