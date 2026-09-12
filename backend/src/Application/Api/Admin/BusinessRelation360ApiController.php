<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Forms\FormRelationAddressToken;
use App\Core\ErrorCode;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Business\Services\BusinessRelation360Service;
use App\Modules\Business\Services\FormSubmissionRelationProjectionService;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;
use InvalidArgumentException;
use Throwable;

final class BusinessRelation360ApiController
{
    public function __construct(
        private readonly Request $request,
        private readonly SiteRepository $sites,
        private readonly AuthRepository $auth,
        private readonly Authorization $authorization,
        private readonly BusinessRelation360Service $relation360,
        private readonly FormSubmissionRelationProjectionService $formActivities,
        private readonly FormRelationAddressToken $relationTokens,
    ) {}

    public function show(string $type, string|int $id): Response
    {
        [$site, $language] = $this->authorize('business.crm.read');
        try {
            $data = $this->relation360->view((int) $site['id'], $this->type($type), $this->id($id), $this->auth->hasPermission('business.consent.read', (int) $site['id']));
            return Response::success($data, 'admin.business.relations.360.v1', $this->meta($site, $language));
        } catch (InvalidArgumentException $e) {
            return $this->domainError($e, $id);
        } catch (Throwable) {
            try {
                $data = $this->relation360->degradedView((int) $site['id'], $this->type($type), $this->id($id), false, 'sale');
                return Response::success($data, 'admin.business.relations.360.v1', $this->meta($site, $language) + ['degraded' => true]);
            } catch (Throwable) {
                return Response::error(ErrorCode::INTERNAL_SERVER_ERROR, 'La fiche Relation est momentanément indisponible.', 503);
            }
        }
    }

    public function storeTask(string $type, string|int $id): Response
    {
        [$site, $language] = $this->authorize('business.crm.manage');
        try {
            $task = $this->relation360->createTask((int) $site['id'], $this->type($type), $this->id($id), $this->payload(), $this->actorId());
            return Response::success(['task' => $task], 'admin.business.relations.tasks.store.v1', $this->meta($site, $language), 201);
        } catch (InvalidArgumentException $e) { return $this->domainError($e, $id); }
    }

    public function updateRoles(string $type, string|int $id): Response
    {
        [$site, $language] = $this->authorize('business.crm.manage');
        $payload = $this->payload();
        try {
            $roles = $this->relation360->replaceRoles(
                (int) $site['id'], $this->type($type), $this->id($id),
                is_array($payload['roles'] ?? null) ? $payload['roles'] : [], $this->actorId()
            );
            return Response::success(['roles' => $roles], 'admin.business.relations.roles.update.v1', $this->meta($site, $language));
        } catch (InvalidArgumentException $e) { return $this->domainError($e, $id); }
    }

    public function issueFormAddress(string $type, string|int $id): Response
    {
        [$site, $language] = $this->authorize('business.crm.manage');
        $payload = $this->payload();
        $formKey = trim((string) ($payload['form_key'] ?? ''));
        try {
            $relationType = $this->type($type);
            $relationId = $this->id($id);
            $this->relation360->assertExists((int) $site['id'], $relationType, $relationId);
            $token = $this->relationTokens->issue((int) $site['id'], $formKey, $relationType, $relationId, (int) ($payload['ttl_seconds'] ?? 604800));
            return Response::success([
                'token' => $token,
                'form_key' => $formKey,
                'usage' => ['field' => '_relation_token', 'transport' => 'top_level_submission_payload'],
            ], 'admin.business.relations.form_address.v1', $this->meta($site, $language), 201);
        } catch (InvalidArgumentException $e) { return $this->domainError($e, $id); }
    }

    public function pendingFormLinks(): Response
    {
        [$site, $language] = $this->authorizeAdvanced('business.form_links.review');
        return Response::success(['items' => $this->formActivities->pending((int) $site['id'], (int) ($this->request->query['limit'] ?? 100))], 'admin.business.form_links.pending.v1', $this->meta($site, $language));
    }

    public function decideFormLink(string|int $id): Response
    {
        [$site, $language] = $this->authorizeAdvanced('business.form_links.review');
        $payload = $this->payload();
        try {
            $activity = $this->formActivities->decide(
                (int) $site['id'], $this->id($id), (string) ($payload['decision'] ?? ''),
                isset($payload['relation_type']) ? (string) $payload['relation_type'] : null,
                isset($payload['relation_id']) ? (int) $payload['relation_id'] : null,
                $this->actorId(), (string) ($payload['reason'] ?? '')
            );
            return Response::success(['activity' => $activity], 'admin.business.form_links.decision.v1', $this->meta($site, $language));
        } catch (InvalidArgumentException $e) { return $this->domainError($e, $id); }
    }

    /** @return array{0:array<string,mixed>,1:string} */
    private function authorize(string $permission): array
    {
        $this->auth->requireAuth();
        $site = $this->sites->resolveCurrentSite($this->request->server['HTTP_HOST'] ?? '');
        $this->authorization->require($permission, (int) $site['id']);
        return [$site, (string) ($this->request->query['content_language_code'] ?? $site['default_language_code'] ?? 'fr')];
    }

    private function authorizeAdvanced(string $permission): array
    {
        [$site, $language] = $this->authorize('business.advanced_tools.manage');
        $this->authorization->require($permission, (int) $site['id']);
        return [$site, $language];
    }

    private function type(string $type): string
    {
        if (!in_array($type, ['contact', 'company'], true)) throw new InvalidArgumentException('business.relation_type_invalid');
        return $type;
    }
    private function id(string|int $id): int { $value = (int) $id; if ($value < 1) throw new InvalidArgumentException('business.id_invalid'); return $value; }
    private function actorId(): int { return (int) ($this->auth->user()['id'] ?? 0); }
    private function payload(): array { $json = $this->request->json(); $data = $json['data'] ?? $json; return is_array($data) ? $data : []; }
    private function meta(array $site, string $language): array { return ['site_id' => (int) $site['id'], 'language_code' => $language]; }
    private function domainError(InvalidArgumentException $e, string|int $id): Response
    {
        if ($e->getMessage() === 'business.relation_not_found') return Response::error(ErrorCode::ROUTE_NOT_FOUND, 'Relation introuvable.', 404, ['id' => (string) $id]);
        return Response::validation(['relation' => [$e->getMessage()]], 'Données Relation invalides.');
    }
}
