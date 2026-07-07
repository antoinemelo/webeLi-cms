<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Application\Iam\IamAdminRepository;
use App\Core\ErrorCode;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Business\Repositories\BusinessActivityRepository;
use App\Modules\Business\Repositories\BusinessCompanyRepository;
use App\Modules\Business\Repositories\BusinessConsentRepository;
use App\Modules\Business\Repositories\BusinessContactRepository;
use App\Modules\Business\Repositories\BusinessDashboardRepository;
use App\Modules\Business\Repositories\BusinessMemoRepository;
use App\Modules\Business\Repositories\BusinessRelationReadRepository;
use App\Modules\Business\Repositories\BusinessSearchRepository;
use App\Modules\Business\Repositories\BusinessTagRepository;
use App\Modules\Business\Services\BusinessConsentService;
use App\Modules\Business\Services\BusinessCrmService;
use App\Modules\Business\Services\BusinessCsvService;
use App\Modules\Business\Services\BusinessMemoSharingService;
use App\Modules\Business\Services\BusinessRelationSummaryService;
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
        private readonly BusinessActivityRepository $activity,
        private readonly BusinessCrmService $crm,
        private readonly BusinessCompanyRepository $companies,
        private readonly BusinessContactRepository $contacts,
        private readonly BusinessDashboardRepository $dashboard,
        private readonly BusinessRelationReadRepository $relations,
        private readonly BusinessSearchRepository $search,
        private readonly BusinessTagRepository $tags,
        private readonly BusinessMemoRepository $memos,
        private readonly BusinessConsentRepository $consents,
        private readonly BusinessConsentService $consentService,
        private readonly BusinessMemoSharingService $memoSharing,
        private readonly BusinessCsvService $csv,
        private readonly BusinessRelationSummaryService $relationSummary,
        private readonly IamAdminRepository $iam,
    ) {}

    public function schema(): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.read');
        return Response::success([
            'module' => 'business',
            'scope' => 'admin',
            'headless_public' => false,
            'entities' => [
                'relation' => [
                    'kinds' => ['company', 'contact'],
                    'fields' => ['type', 'id', 'display_name', 'status', 'tags', 'primary_email', 'primary_phone', 'company_id', 'updated_at'],
                    'excludes' => ['private_tokens', 'provider_secrets'],
                ],
                'company' => ['fields' => ['id', 'name', 'status', 'website', 'tags', 'created_at', 'updated_at']],
                'contact' => ['fields' => ['id', 'company_id', 'display_name', 'status', 'email', 'phone', 'mobile', 'tags', 'created_at', 'updated_at']],
                'memo' => ['fields' => ['id', 'company_id', 'contact_id', 'title', 'body', 'visibility', 'created_at', 'updated_at']],
                'activity' => ['fields' => ['id', 'kind', 'entity_type', 'entity_id', 'action', 'summary', 'created_at']],
                'message' => ['fields' => ['id', 'contact_id', 'channel', 'subject', 'status', 'created_at', 'sent_at']],
            ],
            'relations' => [
                ['from' => 'contact', 'to' => 'company', 'type' => 'belongs_to'],
                ['from' => 'memo', 'to' => 'company', 'type' => 'about_company'],
                ['from' => 'memo', 'to' => 'contact', 'type' => 'about_contact'],
                ['from' => 'activity', 'to' => 'relation', 'type' => 'tracks'],
                ['from' => 'message', 'to' => 'contact', 'type' => 'targets'],
            ],
            'actions' => [
                ['key' => 'relations.index', 'method' => 'GET', 'path' => '/admin/api/business/relations', 'permission' => 'business.crm.read'],
                ['key' => 'relations.show', 'method' => 'GET', 'path' => '/admin/api/business/relations/{type}/{id}', 'permission' => 'business.crm.read'],
                ['key' => 'relations.activity', 'method' => 'GET', 'path' => '/admin/api/business/relations/{type}/{id}/activity', 'permission' => 'business.crm.read'],
                ['key' => 'relations.memos', 'method' => 'GET', 'path' => '/admin/api/business/relations/{type}/{id}/memos', 'permission' => 'business.memo.read'],
                ['key' => 'relations.summary', 'method' => 'POST', 'path' => '/admin/api/business/relations/{type}/{id}/summary', 'permission' => 'business.crm.read'],
            ],
            'permissions' => [
                'business.crm.read',
                'business.crm.manage',
                'business.memo.read',
                'business.memo.manage',
                'business.memo.share',
                'business.messaging.send',
                'business.messaging.admin',
                'business.mailing.read',
                'business.mailing.manage',
            ],
            'ai' => [
                'summary' => [
                    'endpoint' => 'POST /admin/api/business/relations/{type}/{id}/summary',
                    'provider' => 'local_summary',
                    'external_call_by_default' => false,
                    'external_provider_configured' => false,
                ],
            ],
        ], 'admin.business.schema.v1', $this->meta($site, $languageCode));
    }

    public function relations(): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.read');
        try {
            $result = $this->relations->list((int) $site['id'], [
                'q' => $this->q(),
                'type' => $this->queryString('type'),
                'kind' => $this->queryString('kind'),
                'status' => $this->queryString('status'),
                'sort' => $this->queryString('sort') ?: 'activity_desc',
                'has_memos' => $this->queryBool('has_memos'),
                'has_shared_memos' => $this->queryBool('has_shared_memos'),
                'has_email' => $this->queryBool('has_email'),
                'has_phone' => $this->queryBool('has_phone'),
                'missing_email_consent' => $this->queryBool('missing_email_consent'),
                'linked_iam' => $this->queryBool('linked_iam'),
                'tag' => $this->queryString('tag'),
                'updated_after' => $this->queryString('updated_after'),
                'archived' => $this->queryString('archived'),
            ], $this->limit(), $this->offset());
            return Response::success(['relations' => $result['items'], 'pagination' => $this->pagination($result)], 'admin.business.relations.index.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function dashboard(): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.read');
        $siteId = (int) $site['id'];
        $dashboard = $this->dashboard->dashboard($siteId, [
            'include_memos' => $this->auth->hasPermission('business.memo.read', $siteId),
            'include_messages' => $this->auth->hasPermission('business.messaging.send', $siteId) || $this->auth->hasPermission('business.messaging.admin', $siteId),
        ]);
        return Response::success($dashboard, 'admin.business.dashboard.v1', $this->meta($site, $languageCode));
    }

    public function search(): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.read');
        $siteId = (int) $site['id'];
        $result = $this->search->search($siteId, $this->q(), [
            'limit' => $this->limit(),
            'include_memos' => $this->auth->hasPermission('business.memo.read', $siteId),
            'include_messages' => $this->auth->hasPermission('business.messaging.send', $siteId) || $this->auth->hasPermission('business.messaging.admin', $siteId),
        ]);
        return Response::success($result + ['query' => $this->q()], 'admin.business.search.v1', $this->meta($site, $languageCode));
    }

    public function showRelation(string $type, string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.read');
        try {
            $relation = $this->relations->find((int) $site['id'], $type, $this->id($id));
            if (!$relation) {
                return $this->notFound('Relation introuvable.', $id);
            }
            return Response::success(['relation' => $relation], 'admin.business.relations.show.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function relationActivity(string $type, string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.read');
        try {
            $result = $this->activity->relationActivity((int) $site['id'], $type, $this->id($id), $this->limit(), $this->offset());
            return Response::success(['activity' => $result['items'], 'pagination' => $this->pagination($result)], 'admin.business.relations.activity.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $e->getMessage() === 'business.relation_not_found' ? $this->notFound('Relation introuvable.', $id) : $this->validation($e);
        }
    }

    public function summarizeRelation(string $type, string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.read');
        try {
            $summary = $this->relationSummary->summarize((int) $site['id'], $this->relationType($type), $this->id($id));
            return Response::success($summary, 'admin.business.relations.summary.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $e->getMessage() === 'business.relation_not_found' ? $this->notFound('Relation introuvable.', $id) : $this->validation($e);
        }
    }

    public function storeRelation(): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.manage');
        $payload = $this->payload();
        $type = $this->relationTypeFromPayload($payload);
        try {
            if ($type === 'company') {
                $company = $this->crm->createCompany((int) $site['id'], $payload, $this->actorId());
                $this->recordActivity((int) $site['id'], 'business.company.created', 'Entreprise créée : ' . (string) $company['name'], 'business_company', (int) $company['id'], (int) $company['id'], null);
                return Response::success(['relation' => $this->relations->find((int) $site['id'], 'company', (int) $company['id']), 'company' => $company, 'message' => 'Relation organisation créée.'], 'admin.business.relations.store.v1', $this->meta($site, $languageCode), 201);
            }
            $contact = $this->crm->createContact((int) $site['id'], $payload, $this->actorId());
            $this->recordActivity((int) $site['id'], 'business.contact.created', 'Contact créé : ' . (string) $contact['display_name'], 'business_contact', (int) $contact['id'], isset($contact['company_id']) ? (int) $contact['company_id'] : null, (int) $contact['id']);
            return Response::success(['relation' => $this->relations->find((int) $site['id'], 'contact', (int) $contact['id']), 'contact' => $contact, 'message' => 'Relation personne créée.'], 'admin.business.relations.store.v1', $this->meta($site, $languageCode), 201);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function updateRelation(string $type, string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.manage');
        try {
            $relationType = $this->relationType($type);
            if ($relationType === 'company') {
                $company = $this->crm->updateCompany((int) $site['id'], $this->id($id), $this->payload(), $this->actorId());
                if (!$company) return $this->notFound('Relation introuvable.', $id);
                $this->recordActivity((int) $site['id'], 'business.company.updated', 'Entreprise mise à jour : ' . (string) $company['name'], 'business_company', (int) $company['id'], (int) $company['id'], null);
                return Response::success(['relation' => $this->relations->find((int) $site['id'], 'company', (int) $company['id']), 'company' => $company, 'message' => 'Relation organisation mise à jour.'], 'admin.business.relations.update.v1', $this->meta($site, $languageCode));
            }
            $contact = $this->crm->updateContact((int) $site['id'], $this->id($id), $this->payload(), $this->actorId());
            if (!$contact) return $this->notFound('Relation introuvable.', $id);
            $this->recordActivity((int) $site['id'], 'business.contact.updated', 'Contact mis à jour : ' . (string) $contact['display_name'], 'business_contact', (int) $contact['id'], isset($contact['company_id']) ? (int) $contact['company_id'] : null, (int) $contact['id']);
            return Response::success(['relation' => $this->relations->find((int) $site['id'], 'contact', (int) $contact['id']), 'contact' => $contact, 'message' => 'Relation personne mise à jour.'], 'admin.business.relations.update.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function archiveRelation(string $type, string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.manage');
        try {
            $relationType = $this->relationType($type);
            if ($relationType === 'company') {
                $this->crm->archiveCompany((int) $site['id'], $this->id($id), $this->actorId());
                $this->recordActivity((int) $site['id'], 'business.company.archived', 'Entreprise archivée.', 'business_company', $this->id($id), $this->id($id), null);
            } else {
                $this->crm->archiveContact((int) $site['id'], $this->id($id), $this->actorId());
                $this->recordActivity((int) $site['id'], 'business.contact.archived', 'Contact archivé.', 'business_contact', $this->id($id), null, $this->id($id));
            }
            return Response::success(['archived' => true, 'id' => $this->id($id), 'type' => $relationType, 'message' => 'Relation archivée.'], 'admin.business.relations.archive.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function deleteRelation(string $type, string|int $id): Response
    {
        $response = $this->archiveRelation($type, $id);
        if ($response->status() !== 200) {
            return $response;
        }
        [$site, $languageCode] = $this->authorize('business.crm.manage');
        return Response::success(['deleted' => false, 'archived' => true, 'id' => $this->id($id), 'type' => $this->relationType($type), 'message' => 'Suppression protégée : la relation a été archivée.'], 'admin.business.relations.delete.v1', $this->meta($site, $languageCode));
    }

    public function relationMemos(string $type, string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.memo.read');
        try {
            $result = $this->relations->memos((int) $site['id'], $this->relationType($type), $this->id($id), $this->limit(), $this->offset(), $this->includeArchived());
            return Response::success($this->archivedFilter(['memos' => $result['items'], 'pagination' => $this->pagination($result)]), 'admin.business.relations.memos.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $e->getMessage() === 'business.relation_not_found' ? $this->notFound('Relation introuvable.', $id) : $this->validation($e);
        }
    }

    public function storeRelationMemo(string $type, string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.memo.manage');
        try {
            $relationType = $this->relationType($type);
            if ($this->relations->find((int) $site['id'], $relationType, $this->id($id)) === null) {
                return $this->notFound('Relation introuvable.', $id);
            }
            $payload = $this->payload();
            if ($relationType === 'company') {
                $payload['company_id'] = $this->id($id);
            } else {
                $payload['contact_id'] = $this->id($id);
            }
            $memo = $this->memos->create((int) $site['id'], $payload, $this->actorId(), $this->actorId());
            $this->recordActivity((int) $site['id'], 'business.memo.created', 'Mémo créé : ' . (string) $memo['title'], 'crm_memo', (int) $memo['id'], isset($memo['company_id']) ? (int) $memo['company_id'] : null, isset($memo['contact_id']) ? (int) $memo['contact_id'] : null);
            return Response::success(['memo' => $memo, 'message' => 'Mémo créé pour la relation.'], 'admin.business.relations.memos.store.v1', $this->meta($site, $languageCode), 201);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function relationComments(string $type, string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.memo.read');
        try {
            return Response::success(['comments' => $this->relations->comments((int) $site['id'], $this->relationType($type), $this->id($id), $this->limit(), $this->offset(), $this->includeArchived())], 'admin.business.relations.comments.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $e->getMessage() === 'business.relation_not_found' ? $this->notFound('Relation introuvable.', $id) : $this->validation($e);
        }
    }

    public function availableIamUsers(): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.read');
        $exceptContactId = isset($this->request->query['contact_id']) ? max(0, (int) $this->request->query['contact_id']) : null;
        $includeAssigned = $this->queryBool('include_assigned') === true;
        $result = $this->iam->listUsers(['status' => 'active', 'limit' => $this->limit(), 'offset' => $this->offset()]);
        $users = [];
        foreach ($result['rows'] as $user) {
            $userId = (int) ($user['id'] ?? 0);
            if ($userId < 1 || (!$includeAssigned && $this->contacts->activeByIamUser((int) $site['id'], $userId, $exceptContactId) !== null)) {
                continue;
            }
            $users[] = [
                'id' => $userId,
                'email' => (string) ($user['email'] ?? ''),
                'name' => (string) ($user['name'] ?? $user['email'] ?? ''),
                'is_active' => (bool) ($user['is_active'] ?? false),
            ];
        }
        return Response::success(['users' => $users, 'pagination' => ['limit' => $this->limit(), 'offset' => $this->offset()]], 'admin.business.iam.available_users.v1', $this->meta($site, $languageCode));
    }

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

    public function exportCompanies(): Response
    {
        return $this->exportCompaniesCsv();
    }

    public function exportRelations(): Response
    {
        [$site] = $this->authorize('business.crm.manage');
        $result = $this->relations->list((int) $site['id'], [
            'q' => $this->q(),
            'type' => $this->queryString('type'),
            'status' => $this->queryString('status'),
            'sort' => $this->queryString('sort') ?: 'activity_desc',
            'archived' => $this->queryString('archived'),
        ], 10000, 0);
        $rows = [['type', 'id', 'display_name', 'company_name', 'email', 'phone', 'mobile', 'status', 'memo_count', 'shared_memo_count', 'linked_contacts_count', 'created_at', 'updated_at']];
        foreach ($result['items'] as $relation) {
            $rows[] = [
                $relation['type'] ?? $relation['relation_type'] ?? '',
                $relation['id'] ?? $relation['relation_id'] ?? '',
                $relation['display_name'] ?? '',
                $relation['company_name'] ?? '',
                $relation['primary_email'] ?? $relation['email'] ?? '',
                $relation['phone'] ?? '',
                $relation['mobile'] ?? '',
                $relation['status'] ?? '',
                $relation['memo_count'] ?? 0,
                $relation['shared_memo_count'] ?? 0,
                $relation['linked_contacts_count'] ?? 0,
                $relation['created_at'] ?? '',
                $relation['updated_at'] ?? '',
            ];
        }
        return $this->csvResponse('business-relations.csv', $rows);
    }

    public function storeCompany(): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.manage');
        try {
            $company = $this->crm->createCompany((int) $site['id'], $this->payload(), $this->actorId());
            $this->recordActivity((int) $site['id'], 'business.company.created', 'Entreprise créée : ' . (string) $company['name'], 'business_company', (int) $company['id'], (int) $company['id'], null);
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

    public function companyContacts(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.read');
        $companyId = $this->id($id);
        if (!$this->companies->find((int) $site['id'], $companyId, true)) {
            return $this->notFound('Entreprise introuvable.', $id);
        }
        $result = $this->contacts->list((int) $site['id'], $this->q(), $companyId, $this->limit(), $this->offset(), $this->includeArchived(), $this->queryString('status'), $this->queryString('tag'));
        return Response::success($this->archivedFilter(['contacts' => $result['items'], 'pagination' => $this->pagination($result)]), 'admin.business.companies.contacts.v1', $this->meta($site, $languageCode));
    }

    public function updateCompany(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.manage');
        try {
            $company = $this->crm->updateCompany((int) $site['id'], $this->id($id), $this->payload(), $this->actorId());
            if (!$company) {
                return $this->notFound('Entreprise introuvable.', $id);
            }
            $this->recordActivity((int) $site['id'], 'business.company.updated', 'Entreprise mise à jour : ' . (string) $company['name'], 'business_company', (int) $company['id'], (int) $company['id'], null);
            return Response::success(['company' => $company, 'message' => 'Entreprise mise à jour.'], 'admin.business.companies.show.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function archiveCompany(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.manage');
        $this->crm->archiveCompany((int) $site['id'], $this->id($id), $this->actorId());
        $this->recordActivity((int) $site['id'], 'business.company.archived', 'Entreprise archivée.', 'business_company', $this->id($id), $this->id($id), null);
        return Response::success(['archived' => true, 'id' => $this->id($id), 'message' => 'Entreprise archivée.'], 'admin.business.companies.archive.v1', $this->meta($site, $languageCode));
    }

    public function deleteCompany(string|int $id): Response
    {
        $response = $this->archiveCompany($id);
        if ($response->status() !== 200) {
            return $response;
        }
        [$site, $languageCode] = $this->authorize('business.crm.manage');
        return Response::success(['deleted' => false, 'archived' => true, 'id' => $this->id($id), 'message' => 'Suppression protégée : l’entreprise a été archivée.'], 'admin.business.companies.delete.v1', $this->meta($site, $languageCode));
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

    public function exportContacts(): Response
    {
        return $this->exportContactsCsv();
    }

    public function importContactsCsv(): Response
    {
        return $this->importContactsCsvWithContract('admin.business.contacts.import_csv.v1');
    }

    public function importRelations(): Response
    {
        return $this->importContactsCsvWithContract('admin.business.import.relations.v1');
    }

    public function importContacts(): Response
    {
        return $this->importContactsCsvWithContract('admin.business.import.contacts.v1');
    }

    public function importCompanies(): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.manage');
        try {
            $file = $this->uploadedCsv();
            $report = $this->importCompaniesCsv((int) $site['id'], file_get_contents($file) ?: '', $this->payload(), $this->actorId());
            return Response::success($report, 'admin.business.import.companies.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    private function importContactsCsvWithContract(string $contract): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.manage');
        try {
            $file = $this->uploadedCsv();
            $report = $this->csv->importContactsCsv((int) $site['id'], file_get_contents($file) ?: '', $this->payload(), $this->actorId());
            return Response::success($report, $contract, $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function storeContact(): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.manage');
        try {
            $contact = $this->crm->createContact((int) $site['id'], $this->payload(), $this->actorId());
            $this->recordActivity((int) $site['id'], 'business.contact.created', 'Contact créé : ' . (string) $contact['display_name'], 'business_contact', (int) $contact['id'], isset($contact['company_id']) ? (int) $contact['company_id'] : null, (int) $contact['id']);
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
            $this->recordActivity((int) $site['id'], 'business.contact.updated', 'Contact mis à jour : ' . (string) $contact['display_name'], 'business_contact', (int) $contact['id'], isset($contact['company_id']) ? (int) $contact['company_id'] : null, (int) $contact['id']);
            return Response::success(['contact' => $contact, 'message' => 'Contact mis à jour.'], 'admin.business.contacts.show.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function archiveContact(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.manage');
        $this->crm->archiveContact((int) $site['id'], $this->id($id), $this->actorId());
        $this->recordActivity((int) $site['id'], 'business.contact.archived', 'Contact archivé.', 'business_contact', $this->id($id), null, $this->id($id));
        return Response::success(['archived' => true, 'id' => $this->id($id), 'message' => 'Contact archivé.'], 'admin.business.contacts.archive.v1', $this->meta($site, $languageCode));
    }

    public function deleteContact(string|int $id): Response
    {
        $response = $this->archiveContact($id);
        if ($response->status() !== 200) {
            return $response;
        }
        [$site, $languageCode] = $this->authorize('business.crm.manage');
        return Response::success(['deleted' => false, 'archived' => true, 'id' => $this->id($id), 'message' => 'Suppression protégée : le contact a été archivé.'], 'admin.business.contacts.delete.v1', $this->meta($site, $languageCode));
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
        if (isset($this->request->query['relation_type'], $this->request->query['relation_id']) && (int) $this->request->query['relation_id'] > 0) {
            $relationType = $this->relationType((string) $this->request->query['relation_type']);
            if ($relationType === 'company') {
                $filters['company_relation_id'] = (int) $this->request->query['relation_id'];
                unset($filters['company_id'], $filters['contact_id']);
            } else {
                $filters['contact_id'] = (int) $this->request->query['relation_id'];
                unset($filters['company_id']);
            }
        }
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
            $this->recordActivity((int) $site['id'], 'business.memo.updated', 'Mémo mis à jour : ' . (string) $memo['title'], 'crm_memo', (int) $memo['id'], isset($memo['company_id']) ? (int) $memo['company_id'] : null, isset($memo['contact_id']) ? (int) $memo['contact_id'] : null);
            return Response::success(['memo' => $memo, 'message' => 'Mémo mis à jour.'], 'admin.business.memos.show.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function archiveMemo(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.memo.manage');
        $memo = $this->memos->find((int) $site['id'], $this->id($id), true);
        $this->memos->archive((int) $site['id'], $this->id($id), $this->actorId());
        if ($memo) {
            $this->recordActivity((int) $site['id'], 'business.memo.archived', 'Mémo archivé : ' . (string) $memo['title'], 'crm_memo', (int) $memo['id'], isset($memo['company_id']) ? (int) $memo['company_id'] : null, isset($memo['contact_id']) ? (int) $memo['contact_id'] : null);
        }
        return Response::success(['archived' => true, 'id' => $this->id($id), 'message' => 'Mémo archivé.'], 'admin.business.memos.archive.v1', $this->meta($site, $languageCode));
    }

    public function deleteMemo(string|int $id): Response
    {
        $response = $this->archiveMemo($id);
        if ($response->status() !== 200) {
            return $response;
        }
        [$site, $languageCode] = $this->authorize('business.memo.manage');
        return Response::success(['deleted' => false, 'archived' => true, 'id' => $this->id($id), 'message' => 'Suppression protégée : le mémo a été archivé.'], 'admin.business.memos.delete.v1', $this->meta($site, $languageCode));
    }

    public function exportMemos(): Response
    {
        [$site] = $this->authorize('business.memo.manage');
        $result = $this->memos->list((int) $site['id'], [
            'q' => $this->q(),
            'company_id' => isset($this->request->query['company_id']) ? (int) $this->request->query['company_id'] : null,
            'contact_id' => isset($this->request->query['contact_id']) ? (int) $this->request->query['contact_id'] : null,
        ], 10000, 0, $this->includeArchived());
        $rows = [['id', 'company_id', 'contact_id', 'title', 'visibility', 'created_at', 'updated_at', 'archived_at']];
        foreach ($result['items'] as $memo) {
            $rows[] = [
                $memo['id'] ?? '',
                $memo['company_id'] ?? '',
                $memo['contact_id'] ?? '',
                $memo['title'] ?? '',
                $memo['visibility'] ?? '',
                $memo['created_at'] ?? '',
                $memo['updated_at'] ?? '',
                $memo['archived_at'] ?? '',
            ];
        }
        return $this->csvResponse('business-memos.csv', $rows);
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

    public function updateMemoComment(string|int $id, string|int $commentId): Response
    {
        [$site, $languageCode] = $this->authorize('business.memo.manage');
        try {
            $comment = $this->memos->updateComment((int) $site['id'], $this->id($id), $this->id($commentId), (string) ($this->payload()['body'] ?? ''));
            if (!$comment) {
                return $this->notFound('Commentaire introuvable.', $commentId);
            }
            $memo = $this->memos->find((int) $site['id'], $this->id($id), true);
            if ($memo) {
                $this->recordActivity((int) $site['id'], 'business.memo_comment.updated', 'Commentaire mis à jour.', 'crm_memo_comment', (int) $comment['id'], isset($memo['company_id']) ? (int) $memo['company_id'] : null, isset($memo['contact_id']) ? (int) $memo['contact_id'] : null);
            }
            return Response::success(['comment' => $comment, 'message' => 'Commentaire mis à jour.'], 'admin.business.memo_comments.update.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $e->getMessage() === 'business.memo_not_found' ? $this->notFound('Mémo introuvable.', $id) : $this->validation($e);
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

    public function deleteMemoShareUser(string|int $id, string|int $iamUserId): Response
    {
        [$site, $languageCode] = $this->authorize('business.memo.share');
        try {
            $shareId = null;
            foreach ($this->memos->sharesForMemo((int) $site['id'], $this->id($id)) as $share) {
                if (($share['share_type'] ?? '') === 'iam_user' && (int) ($share['shared_with_iam_user_id'] ?? 0) === $this->id($iamUserId)) {
                    $shareId = (int) ($share['id'] ?? 0);
                    break;
                }
            }
            if ($shareId === null || $shareId < 1) {
                return $this->notFound('Partage introuvable.', $iamUserId);
            }
            $this->memoSharing->revokeShare((int) $site['id'], $shareId, $this->actorId());
            $this->audit('business.memo_share.user_revoked', 'crm_memo', $this->id($id), ['share_id' => $shareId, 'shared_with_iam_user_id' => $this->id($iamUserId), 'site_id' => (int) $site['id']]);
            return Response::success(['revoked' => true, 'share_id' => $shareId, 'iam_user_id' => $this->id($iamUserId), 'message' => 'Partage utilisateur révoqué.'], 'admin.business.memo_shares.users.delete.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function updateMemoShare(string|int $id, string|int $shareId): Response
    {
        [$site, $languageCode] = $this->authorize('business.memo.share');
        try {
            $payload = $this->payload();
            $share = $this->memos->updatePublicShare(
                (int) $site['id'],
                $this->id($id),
                $this->id($shareId),
                array_key_exists('label', $payload) ? (string) $payload['label'] : null,
                isset($payload['expires_at']) ? (string) $payload['expires_at'] : null,
                $this->actorId()
            );
            if ($share === null) {
                return $this->notFound('Partage introuvable.', $shareId);
            }
            $safeShare = $this->safeShare($share);
            $this->audit('business.memo_share.public_updated', 'crm_memo', $this->id($id), ['share_id' => $this->id($shareId), 'expires_at' => $safeShare['expires_at'] ?? null, 'site_id' => (int) $site['id']]);
            return Response::success(['share' => $safeShare, 'message' => 'Lien public mis à jour.'], 'admin.business.memo_shares.update.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $e->getMessage() === 'business.memo_not_found' ? $this->notFound('Mémo introuvable.', $id) : $this->validation($e);
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

    public function consents(): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.read');
        try {
            $result = $this->consents->list((int) $site['id'], $this->limit(), $this->offset(), $this->queryString('channel'), $this->queryString('status'));
            return Response::success(['consents' => $result['items'], 'pagination' => $this->pagination($result)], 'admin.business.consents.index.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function storeConsent(): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.manage');
        try {
            $payload = $this->payload();
            $contactId = $this->id($payload['contact_id'] ?? 0);
            if (!$this->contacts->find((int) $site['id'], $contactId, true)) {
                return $this->notFound('Contact introuvable.', $contactId);
            }
            $consent = $this->consentService->setConsent(
                $contactId,
                (string) ($payload['channel'] ?? 'email'),
                (string) ($payload['consent_status'] ?? $payload['status'] ?? 'unknown'),
                (string) ($payload['source'] ?? 'manual'),
                isset($payload['evidence']) ? (string) $payload['evidence'] : null,
                $this->actorId()
            );
            return Response::success(['consent' => $consent, 'message' => 'Consentement enregistré.'], 'admin.business.consents.store.v1', $this->meta($site, $languageCode), 201);
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
    }

    public function updateConsent(string|int $id): Response
    {
        [$site, $languageCode] = $this->authorize('business.crm.manage');
        try {
            $payload = $this->payload();
            $consent = $this->consents->updateById(
                (int) $site['id'],
                $this->id($id),
                (string) ($payload['consent_status'] ?? $payload['status'] ?? 'unknown'),
                (string) ($payload['source'] ?? 'manual'),
                isset($payload['evidence']) ? (string) $payload['evidence'] : null,
                $this->actorId()
            );
            if ($consent === null) {
                return $this->notFound('Consentement introuvable.', $id);
            }
            return Response::success(['consent' => $consent, 'message' => 'Consentement mis à jour.'], 'admin.business.consents.update.v1', $this->meta($site, $languageCode));
        } catch (InvalidArgumentException $e) {
            return $this->validation($e);
        }
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

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function importCompaniesCsv(int $siteId, string $csv, array $options, ?int $actorId): array
    {
        if (strlen($csv) > 1048576) {
            throw new InvalidArgumentException('business.csv_file_too_large');
        }
        if (!preg_match('//u', $csv)) {
            throw new InvalidArgumentException('business.csv_utf8_required');
        }
        $dryRun = filter_var($options['dry_run'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $dryRun = $dryRun !== false;
        if (!$dryRun && !filter_var($options['confirm_import'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw new InvalidArgumentException('business.import_confirm_required');
        }
        $delimiter = str_contains(strtok($csv, "\r\n") ?: '', ';') ? ';' : ',';
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new InvalidArgumentException('business.csv_read_failed');
        }
        fwrite($handle, $csv);
        rewind($handle);
        $headers = fgetcsv($handle, 0, $delimiter, '"', '');
        if (!is_array($headers) || $headers === []) {
            fclose($handle);
            throw new InvalidArgumentException('business.csv_header_required');
        }
        $headers = array_map(static fn(mixed $value): string => strtolower(trim((string) $value)), $headers);
        if (!in_array('name', $headers, true)) {
            fclose($handle);
            throw new InvalidArgumentException('business.csv_header_name_required');
        }
        $allowed = array_fill_keys(['name', 'email', 'phone', 'website_url', 'website', 'status', 'notes'], true);
        foreach ($headers as $header) {
            if (!isset($allowed[$header])) {
                fclose($handle);
                throw new InvalidArgumentException('business.csv_unknown_header_' . $header);
            }
        }
        $report = ['dry_run' => $dryRun, 'delimiter' => $delimiter, 'rows_total' => 0, 'valid_rows' => 0, 'created' => 0, 'skipped' => 0, 'errors' => [], 'rows' => [], 'writes_performed' => false];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if (!is_array($row) || implode('', array_map('trim', $row)) === '') {
                continue;
            }
            $report['rows_total']++;
            $line = $report['rows_total'] + 1;
            $data = [];
            foreach ($headers as $index => $header) {
                $data[$header] = trim((string) ($row[$index] ?? ''));
            }
            $data['website_url'] = $data['website_url'] ?? $data['website'] ?? null;
            $rowReport = ['line' => $line, 'status' => 'valid', 'action' => 'create', 'errors' => [], 'name' => $data['name'] ?? ''];
            try {
                if (($data['name'] ?? '') === '') {
                    throw new InvalidArgumentException('business.name_required');
                }
                if (!$dryRun) {
                    $this->companies->create($siteId, $data, $actorId);
                    $report['created']++;
                    $report['writes_performed'] = true;
                }
                $report['valid_rows']++;
            } catch (InvalidArgumentException $e) {
                $rowReport['status'] = 'error';
                $rowReport['errors'][] = $e->getMessage();
                $report['errors'][] = ['line' => $line, 'message' => $e->getMessage()];
                $report['skipped']++;
            }
            $report['rows'][] = $rowReport;
        }
        fclose($handle);
        return $report;
    }

    /** @param list<list<mixed>> $rows */
    private function csvResponse(string $filename, array $rows): Response
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return Response::error(ErrorCode::INTERNAL_SERVER_ERROR, 'Export CSV impossible.', 500);
        }
        foreach ($rows as $row) {
            fputcsv($handle, array_map(static fn(mixed $value): string => (string) $value, $row), ';', '"', '');
        }
        rewind($handle);
        $body = stream_get_contents($handle);
        fclose($handle);
        return new Response(200, is_string($body) ? $body : '', [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function q(): string
    {
        return trim((string) ($this->request->query['q'] ?? ''));
    }

    private function queryString(string $key): string
    {
        return trim((string) ($this->request->query[$key] ?? ''));
    }

    private function queryBool(string $key): ?bool
    {
        if (!array_key_exists($key, $this->request->query) || $this->request->query[$key] === '') {
            return null;
        }
        return in_array(strtolower((string) $this->request->query[$key]), ['1', 'true', 'yes', 'on'], true);
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

    private function relationType(string $type): string
    {
        $type = trim($type);
        if ($type === 'individual') {
            return 'contact';
        }
        if (!in_array($type, ['contact', 'company'], true)) {
            throw new InvalidArgumentException('business.relation_type_invalid');
        }
        return $type;
    }

    /** @param array<string,mixed> $payload */
    private function relationTypeFromPayload(array $payload): string
    {
        $type = trim((string) ($payload['type'] ?? $payload['kind'] ?? 'contact'));
        return $this->relationType($type);
    }

    /** @param array{limit:int,offset:int,total?:int} $result */
    private function pagination(array $result): array
    {
        $pagination = ['limit' => (int) $result['limit'], 'offset' => (int) $result['offset']];
        if (array_key_exists('total', $result)) {
            $pagination['total'] = (int) $result['total'];
        }
        return $pagination;
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

    private function recordActivity(int $siteId, string $action, string $summary, string $entityType, int $entityId, ?int $companyId, ?int $contactId, array $metadata = []): void
    {
        $this->activity->log($siteId, $this->actorId() ?: null, $entityType, $entityId, $companyId, $contactId, $action, $summary, $metadata);
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
