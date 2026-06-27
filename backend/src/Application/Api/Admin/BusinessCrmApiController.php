<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Core\ErrorCode;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Business\Repositories\BusinessCompanyRepository;
use App\Modules\Business\Repositories\BusinessConsentRepository;
use App\Modules\Business\Repositories\BusinessContactRepository;
use App\Modules\Business\Repositories\BusinessMemoRepository;
use App\Modules\Business\Repositories\BusinessTagRepository;
use App\Modules\Business\Services\BusinessConsentService;
use App\Modules\Business\Services\BusinessCrmService;
use App\Modules\Business\Services\BusinessCsvService;
use App\Modules\Business\Services\BusinessMemoSharingService;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;
use InvalidArgumentException;

final class BusinessCrmApiController
{
    public function __construct(
        private readonly Request $request,
        private readonly SiteRepository $sites,
        private readonly AuthRepository $auth,
        private readonly Authorization $authorization,
        private readonly BusinessCrmService $crm,
        private readonly BusinessCompanyRepository $companies,
        private readonly BusinessContactRepository $contacts,
        private readonly BusinessTagRepository $tags,
        private readonly BusinessMemoRepository $memos,
        private readonly BusinessConsentRepository $consents,
        private readonly BusinessConsentService $consentService,
        private readonly BusinessMemoSharingService $memoSharing,
        private readonly BusinessCsvService $csv,
    ) {}

    public function companies(): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.read');
        $result = $this->companies->list((int) $site['id'], $this->q(), $this->queryString('status'), $this->limit(), $this->offset(), $this->includeArchived(), $this->queryString('tag'));
        return Response::success($this->archivedFilter(['companies' => $result['items'], 'pagination' => $this->pagination($result)]), 'admin.business.companies.index.v1', $this->meta($site, $languageCode));
    }

    public function exportCompaniesCsv(): Response
    {
        [$site] = $this->authorize('business.crm.manage');
        return new Response(200, $this->csv->companiesCsv((int) $site['id'], $this->request->query), [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="business-companies.csv"',
        ]);
    }

    public function storeCompany(): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.manage');
        try {
            $company = $this->crm->createCompany((int) $site['id'], $this->payload(), $this->actorId());
            return Response::success(['company' => $company, 'message' => 'Entreprise créée.'], 'admin.business.companies.show.v1', $this->meta($site, $languageCode), 201);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function showCompany(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.read');
        $company = $this->companies->find((int) $site['id'], $this->id($id), true);
        if (!$company) {
            return $this->notFound('Entreprise introuvable.', $id);
        }
        return Response::success(['company' => $company], 'admin.business.companies.show.v1', $this->meta($site, $languageCode));
    }

    public function updateCompany(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.manage');
        try {
            $company = $this->crm->updateCompany((int) $site['id'], $this->id($id), $this->payload(), $this->actorId());
            if (!$company) {
                return $this->notFound('Entreprise introuvable.', $id);
            }
            return Response::success(['company' => $company, 'message' => 'Entreprise mise à jour.'], 'admin.business.companies.show.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function archiveCompany(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.manage');
        $this->crm->archiveCompany((int) $site['id'], $this->id($id), $this->actorId());
        return Response::success(['archived' => true, 'id' => $this->id($id), 'message' => 'Entreprise archivée.'], 'admin.business.companies.archive.v1', $this->meta($site, $languageCode));
    }

    public function contacts(): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.read');
        $companyId = isset($this->request->query['company_id']) ? (int) $this->request->query['company_id'] : null;
        $result = $this->contacts->list((int) $site['id'], $this->q(), $companyId, $this->limit(), $this->offset(), $this->includeArchived(), $this->queryString('status'), $this->queryString('tag'));
        return Response::success($this->archivedFilter(['contacts' => $result['items'], 'pagination' => $this->pagination($result)]), 'admin.business.contacts.index.v1', $this->meta($site, $languageCode));
    }

    public function exportContactsCsv(): Response
    {
        [$site] = $this->authorize('business.crm.manage');
        return new Response(200, $this->csv->contactsCsv((int) $site['id'], $this->request->query), [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="business-contacts.csv"',
        ]);
    }

    public function importContactsCsv(): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.manage');
        try {
            $file = $this->uploadedCsv();
            $report = $this->csv->importContactsCsv((int) $site['id'], file_get_contents($file) ?: '', $this->payload(), $this->actorId());
            return Response::success($report, 'admin.business.contacts.import_csv.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function storeContact(): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.manage');
        try {
            $contact = $this->crm->createContact((int) $site['id'], $this->payload(), $this->actorId());
            return Response::success(['contact' => $contact, 'message' => 'Contact créé.'], 'admin.business.contacts.show.v1', $this->meta($site, $languageCode), 201);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function showContact(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.read');
        $contact = $this->contacts->find((int) $site['id'], $this->id($id), true);
        if (!$contact) {
            return $this->notFound('Contact introuvable.', $id);
        }
        return Response::success(['contact' => $contact], 'admin.business.contacts.show.v1', $this->meta($site, $languageCode));
    }

    public function updateContact(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.manage');
        try {
            $contact = $this->crm->updateContact((int) $site['id'], $this->id($id), $this->payload(), $this->actorId());
            if (!$contact) {
                return $this->notFound('Contact introuvable.', $id);
            }
            return Response::success(['contact' => $contact, 'message' => 'Contact mis à jour.'], 'admin.business.contacts.show.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function archiveContact(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.manage');
        $this->crm->archiveContact((int) $site['id'], $this->id($id), $this->actorId());
        return Response::success(['archived' => true, 'id' => $this->id($id), 'message' => 'Contact archivé.'], 'admin.business.contacts.archive.v1', $this->meta($site, $languageCode));
    }

    public function tags(): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.read');
        $tags = $this->tags->list((int) $site['id'], $this->includeArchived());
        return Response::success($this->archivedFilter(['tags' => $tags]), 'admin.business.tags.index.v1', $this->meta($site, $languageCode));
    }

    public function storeTag(): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.manage');
        try {
            $tag = $this->tags->create((int) $site['id'], $this->payload(), $this->actorId());
            return Response::success(['tag' => $tag, 'message' => 'Tag créé.'], 'admin.business.tags.show.v1', $this->meta($site, $languageCode), 201);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function updateTag(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.manage');
        try {
            $tag = $this->tags->update((int) $site['id'], $this->id($id), $this->payload(), $this->actorId());
            if (!$tag) {
                return $this->notFound('Tag introuvable.', $id);
            }
            return Response::success(['tag' => $tag, 'message' => 'Tag mis à jour.'], 'admin.business.tags.show.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function deleteTag(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.manage');
        $this->tags->archive((int) $site['id'], $this->id($id), $this->actorId());
        return Response::success(['deleted' => true, 'archived' => true, 'id' => $this->id($id)], 'admin.business.tags.delete.v1', $this->meta($site, $languageCode));
    }

    public function storeTagLink(): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.manage');
        try {
            $payload = $this->payload();
            [$tagId, $targetType, $targetId] = $this->tagLinkPayload($payload);
            $this->assertTarget($site, $targetType, $targetId);
            $targetType === 'company'
                ? $this->tags->linkCompany($tagId, $targetId, $this->actorId())
                : $this->tags->linkContact($tagId, $targetId, $this->actorId());
            return Response::success(['linked' => true, 'tag_id' => $tagId, 'target_type' => $targetType, 'target_id' => $targetId], 'admin.business.tag_links.write.v1', $this->meta($site, $languageCode), 201);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function deleteTagLink(): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.manage');
        try {
            [$tagId, $targetType, $targetId] = $this->tagLinkPayload($this->payload());
            $this->assertTarget($site, $targetType, $targetId);
            $targetType === 'company'
                ? $this->tags->unlinkCompany($tagId, $targetId)
                : $this->tags->unlinkContact($tagId, $targetId);
            return Response::success(['deleted' => true, 'tag_id' => $tagId, 'target_type' => $targetType, 'target_id' => $targetId], 'admin.business.tag_links.delete.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function memos(): Response
    {
        [$site, $languageCode] = $this->authorize('business.memo.read');
        $filters = [
            'q' => $this->q(),
            'company_id' => isset($this->request->query['company_id']) ? (int) $this->request->query['company_id'] : null,
            'contact_id' => isset($this->request->query['contact_id']) ? (int) $this->request->query['contact_id'] : null,
        ];
        $filters = array_filter($filters, static fn(mixed $value): bool => $value !== null && $value !== '');
        $result = $this->memos->list((int) $site['id'], $filters, $this->limit(), $this->offset(), $this->includeArchived());
        return Response::success($this->archivedFilter(['memos' => $result['items'], 'pagination' => $this->pagination($result)]), 'admin.business.memos.index.v1', $this->meta($site, $languageCode));
    }

    public function storeMemo(): Response
    {
        [$site, $languageCode] = $this->authorize('business.memo.manage');
        try {
            $memo = $this->memos->create((int) $site['id'], $this->payload(), $this->actorId(), $this->actorId());
            return Response::success(['memo' => $memo, 'message' => 'Mémo créé.'], 'admin.business.memos.show.v1', $this->meta($site, $languageCode), 201);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function showMemo(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.memo.read');
        $memo = $this->memos->find((int) $site['id'], $this->id($id), true);
        if (!$memo) {
            return $this->notFound('Mémo introuvable.', $id);
        }
        return Response::success(['memo' => $memo], 'admin.business.memos.show.v1', $this->meta($site, $languageCode));
    }

    public function updateMemo(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.memo.manage');
        try {
            $memo = $this->memos->update((int) $site['id'], $this->id($id), $this->payload(), $this->actorId());
            if (!$memo) {
                return $this->notFound('Mémo introuvable.', $id);
            }
            return Response::success(['memo' => $memo, 'message' => 'Mémo mis à jour.'], 'admin.business.memos.show.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function archiveMemo(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.memo.manage');
        $this->memos->archive((int) $site['id'], $this->id($id), $this->actorId());
        return Response::success(['archived' => true, 'id' => $this->id($id), 'message' => 'Mémo archivé.'], 'admin.business.memos.archive.v1', $this->meta($site, $languageCode));
    }

    public function memoComments(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.memo.read');
        try {
            return Response::success(['comments' => $this->memos->comments((int) $site['id'], $this->id($id), $this->includeArchived())], 'admin.business.memo_comments.index.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException) {
            return $this->notFound('Mémo introuvable.', $id);
        }
    }

    public function memoShares(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.memo.share');
        try {
            return Response::success(['shares' => $this->safeShares($this->memos->sharesForMemo((int) $site['id'], $this->id($id)))], 'admin.business.memo_shares.index.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException) {
            return $this->notFound('Mémo introuvable.', $id);
        }
    }

    public function storeMemoShare(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.memo.share');
        try {
            $payload = $this->payload();
            $userIds = [];
            if (isset($payload['iam_user_ids']) && is_array($payload['iam_user_ids'])) {
                $userIds = array_map('intval', $payload['iam_user_ids']);
            } elseif (isset($payload['iam_user_id'])) {
                $userIds = [(int) $payload['iam_user_id']];
            }
            $userIds = array_values(array_filter(array_unique($userIds), static fn(int $value): bool => $value > 0));
            if ($userIds === []) {
                throw new InvalidArgumentException('business.memo_share_user_required');
            }
            $shares = $this->memoSharing->shareInternally((int) $site['id'], $this->id($id), $userIds, $this->actorId());
            $this->audit('business.memo_share.internal_created', 'crm_memo', $this->id($id), ['shared_with_count' => count($shares), 'site_id' => (int) $site['id']]);
            return Response::success(['shares' => $this->safeShares($shares), 'message' => 'Partage interne créé.'], 'admin.business.memo_shares.write.v1', $this->meta($site, $languageCode), 201);
        } catch (InvalidArgumentException $e) {
            return $e->getMessage() === 'business.memo_not_found' ? $this->notFound('Mémo introuvable.', $id) : $this->validation($e);
        }
    }

    public function deleteMemoShare(string|int $id, string|int $shareId): Response
    {
        [$site, $languageCode] = $this->authorize('business.memo.share');
        try {
            $knownShareIds = array_map(static fn(array $share): int => (int) ($share['id'] ?? 0), $this->memos->sharesForMemo((int) $site['id'], $this->id($id)));
            if (!in_array($this->id($shareId), $knownShareIds, true)) {
                return $this->notFound('Partage introuvable.', $shareId);
            }
            $this->memoSharing->revokeShare((int) $site['id'], $this->id($shareId), $this->actorId());
            $this->audit('business.memo_share.revoked', 'crm_memo', $this->id($id), ['share_id' => $this->id($shareId), 'site_id' => (int) $site['id']]);
            return Response::success(['revoked' => true, 'share_id' => $this->id($shareId), 'message' => 'Partage révoqué.'], 'admin.business.memo_shares.delete.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function createPublicMemoShare(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.memo.share');
        try {
            $payload = $this->payload();
            $result = $this->memoSharing->createPublicShare(
                (int) $site['id'],
                $this->id($id),
                $this->actorId(),
                true,
                isset($payload['label']) ? (string) $payload['label'] : null,
                isset($payload['expires_at']) ? (string) $payload['expires_at'] : null,
            );
            $share = $this->safeShare($result['share']);
            $publicPath = '/business/memos/share/' . $result['token'];
            $this->audit('business.memo_share.public_created', 'crm_memo', $this->id($id), ['share_id' => (int) ($share['id'] ?? 0), 'expires_at' => $share['expires_at'] ?? null, 'site_id' => (int) $site['id']]);
            return Response::success([
                'share' => $share,
                'public_token' => $result['token'],
                'public_path' => $publicPath,
                'message' => 'Lien public créé. Copiez le token maintenant : il ne sera plus affiché.',
            ], 'admin.business.memo_public_share.create.v1', $this->meta($site, $languageCode), 201);
        } catch (InvalidArgumentException $e) {
            return $e->getMessage() === 'business.memo_not_found' ? $this->notFound('Mémo introuvable.', $id) : $this->validation($e);
        }
    }

    public function deletePublicMemoShare(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.memo.share');
        try {
            $count = $this->memos->revokePublicSharesForMemo((int) $site['id'], $this->id($id), $this->actorId());
            $this->audit('business.memo_share.public_revoked', 'crm_memo', $this->id($id), ['revoked_count' => $count, 'site_id' => (int) $site['id']]);
            return Response::success(['revoked' => true, 'revoked_count' => $count, 'message' => 'Lien public révoqué.'], 'admin.business.memo_public_share.delete.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $e->getMessage() === 'business.memo_not_found' ? $this->notFound('Mémo introuvable.', $id) : $this->validation($e);
        }
    }

    public function storeMemoComment(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.memo.manage');
        try {
            $comment = $this->memos->addComment((int) $site['id'], $this->id($id), $this->actorId(), (string) ($this->payload()['body'] ?? ''));
            return Response::success(['comment' => $comment, 'message' => 'Commentaire ajouté.'], 'admin.business.memo_comments.show.v1', $this->meta($site, $languageCode), 201);
        } catch (InvalidArgumentException $e) {
            return $e->getMessage() === 'business.memo_not_found' ? $this->notFound('Mémo introuvable.', $id) : $this->validation($e);
        }
    }

    public function contactConsents(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.read');
        $contactId = $this->id($id);
        if (!$this->contacts->find((int) $site['id'], $contactId, true)) {
            return $this->notFound('Contact introuvable.', $id);
        }
        return Response::success([
            'contact_id' => $contactId,
            'channels' => $this->consents->channels($contactId),
            'consents' => $this->consents->consentsForContact($contactId),
        ], 'admin.business.contacts.consents.index.v1', $this->meta($site, $languageCode));
    }

    public function updateContactConsent(string|int $id, string $channel): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.manage');
        $contactId = $this->id($id);
        if (!$this->contacts->find((int) $site['id'], $contactId, true)) {
            return $this->notFound('Contact introuvable.', $id);
        }
        try {
            $payload = $this->payload();
            $channelRow = null;
            if (isset($payload['value']) && trim((string) $payload['value']) !== '') {
                $channelRow = $this->consentService->setChannel($contactId, $channel, (string) $payload['value'], (bool) ($payload['is_primary'] ?? true), (bool) ($payload['is_verified'] ?? false), $this->actorId());
            }
            $consent = $this->consentService->setConsent(
                $contactId,
                $channel,
                (string) ($payload['consent_status'] ?? $payload['status'] ?? 'unknown'),
                (string) ($payload['source'] ?? 'manual'),
                isset($payload['evidence']) ? (string) $payload['evidence'] : null,
                $this->actorId()
            );
            return Response::success(['channel' => $channelRow, 'consent' => $consent, 'message' => 'Consentement mis à jour.'], 'admin.business.contacts.consents.show.v1', $this->meta($site, $languageCode));
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

    private function actorId(): int
    {
        return (int) ($this->auth->user()['id'] ?? 0);
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

    private function uploadedCsv(): string
    {
        $file = $this->request->files['file'] ?? $this->request->files['csv'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('business.csv_file_required');
        }
        $path = (string) ($file['tmp_name'] ?? '');
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('business.csv_file_unreadable');
        }
        if ((int) ($file['size'] ?? filesize($path) ?: 0) > 1048576) {
            throw new InvalidArgumentException('business.csv_file_too_large');
        }
        return $path;
    }

    private function q(): string
    {
        return trim((string) ($this->request->query['q'] ?? ''));
    }

    private function queryString(string $key): string
    {
        return trim((string) ($this->request->query[$key] ?? ''));
    }

    private function includeArchived(): bool
    {
        return in_array((string) ($this->request->query['archived'] ?? '0'), ['1', 'all'], true);
    }

    private function archivedOnly(): bool
    {
        return (string) ($this->request->query['archived'] ?? '0') === '1';
    }

    /** @param array<string,mixed> $payload */
    private function archivedFilter(array $payload): array
    {
        if (!$this->archivedOnly()) {
            return $payload;
        }
        foreach (['companies', 'contacts', 'tags', 'memos'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $payload[$key] = array_values(array_filter($payload[$key], static fn(array $row): bool => ($row['archived_at'] ?? null) !== null));
            }
        }
        return $payload;
    }

    private function limit(): int
    {
        return max(1, min(200, (int) ($this->request->query['limit'] ?? 50)));
    }

    private function offset(): int
    {
        return max(0, (int) ($this->request->query['offset'] ?? 0));
    }

    private function id(string|int $id): int
    {
        return max(0, (int) $id);
    }

    /** @param array{limit:int,offset:int} $result */
    private function pagination(array $result): array
    {
        return ['limit' => (int) $result['limit'], 'offset' => (int) $result['offset']];
    }

    /** @param array<string,mixed> $site */
    private function meta(array $site, string $languageCode): array
    {
        return AdminApiContract::meta($site, $languageCode);
    }

    private function validation(InvalidArgumentException $e): Response
    {
        return Response::validation(['business' => [$e->getMessage()]], 'Donnée CRM invalide.');
    }

    /** @param list<array<string,mixed>> $shares @return list<array<string,mixed>> */
    private function safeShares(array $shares): array
    {
        return array_map(fn(array $share): array => $this->safeShare($share), $shares);
    }

    /** @param array<string,mixed> $share @return array<string,mixed> */
    private function safeShare(array $share): array
    {
        unset($share['public_token_hash']);
        return $share;
    }

    private function audit(string $action, string $resourceType, int $resourceId, array $context = []): void
    {
        $this->auth->audit($this->actorId() ?: null, $action, $resourceType, $resourceId, $context);
    }

    private function notFound(string $message, string|int $id): Response
    {
        return Response::error(ErrorCode::ROUTE_NOT_FOUND, $message, 404, ['id' => (string) $id]);
    }

    /** @param array<string,mixed> $payload @return array{0:int,1:string,2:int} */
    private function tagLinkPayload(array $payload): array
    {
        $tagId = (int) ($payload['tag_id'] ?? 0);
        $targetType = (string) ($payload['target_type'] ?? '');
        $targetId = (int) ($payload['target_id'] ?? $payload['company_id'] ?? $payload['contact_id'] ?? 0);
        if ($tagId < 1 || !in_array($targetType, ['company', 'contact'], true) || $targetId < 1) {
            throw new InvalidArgumentException('business.tag_link_invalid');
        }
        return [$tagId, $targetType, $targetId];
    }

    /** @param array<string,mixed> $site */
    private function assertTarget(array $site, string $targetType, int $targetId): void
    {
        $exists = $targetType === 'company'
            ? $this->companies->find((int) $site['id'], $targetId) !== null
            : $this->contacts->find((int) $site['id'], $targetId) !== null;
        if (!$exists) {
            throw new InvalidArgumentException('business.tag_link_target_not_found');
        }
    }
}
