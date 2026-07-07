<?php

declare(strict_types=1);

namespace App\Modules\Business\Services;

use App\Modules\Business\Repositories\BusinessCompanyRepository;
use App\Modules\Business\Repositories\BusinessContactRepository;
use App\Modules\Business\Repositories\BusinessTagRepository;
use InvalidArgumentException;

final class BusinessCrmService
{
    public function __construct(
        private readonly BusinessCompanyRepository $companies,
        private readonly BusinessContactRepository $contacts,
        private readonly ?BusinessTagRepository $tags = null,
    ) {}

    public function createCompany(int $siteId, array $payload, ?int $actorId = null): array
    {
        return $this->companies->create($siteId, $payload, $actorId);
    }

    public function updateCompany(int $siteId, int $companyId, array $payload, ?int $actorId = null): ?array
    {
        return $this->companies->update($siteId, $companyId, $payload, $actorId);
    }

    public function createContact(int $siteId, array $payload, ?int $actorId = null): array
    {
        $companyId = isset($payload['company_id']) && $payload['company_id'] !== '' ? (int) $payload['company_id'] : 0;
        if ($companyId < 1) {
            $company = $this->companies->ensureSystemIndividualsCompany($siteId, $actorId);
            $payload['company_id'] = $company['id'];
        } elseif ($this->companies->find($siteId, $companyId) === null) {
            throw new InvalidArgumentException('business.contact_company_not_found');
        }
        return $this->contacts->create($siteId, $payload, $actorId);
    }

    public function updateContact(int $siteId, int $contactId, array $payload, ?int $actorId = null): ?array
    {
        if (isset($payload['company_id']) && $payload['company_id'] !== '' && $this->companies->find($siteId, (int) $payload['company_id']) === null) {
            throw new InvalidArgumentException('business.contact_company_not_found');
        }
        return $this->contacts->update($siteId, $contactId, $payload, $actorId);
    }

    /** @return array{items:list<array<string,mixed>>,limit:int,offset:int} */
    public function searchCompanies(int $siteId, string $query, int $limit = 50, int $offset = 0): array
    {
        return $this->companies->list($siteId, $query, '', $limit, $offset);
    }

    /** @return array{items:list<array<string,mixed>>,limit:int,offset:int} */
    public function searchContacts(int $siteId, string $query, int $limit = 50, int $offset = 0): array
    {
        return $this->contacts->list($siteId, $query, null, $limit, $offset);
    }

    public function archiveCompany(int $siteId, int $companyId, ?int $actorId = null): bool
    {
        return $this->companies->archive($siteId, $companyId, $actorId);
    }

    public function restoreCompany(int $siteId, int $companyId, ?int $actorId = null): bool
    {
        return $this->companies->restore($siteId, $companyId, $actorId);
    }

    public function deleteArchivedCompany(int $siteId, int $companyId): bool
    {
        return $this->companies->deleteArchived($siteId, $companyId);
    }

    public function archiveContact(int $siteId, int $contactId, ?int $actorId = null): bool
    {
        return $this->contacts->archive($siteId, $contactId, $actorId);
    }

    public function restoreContact(int $siteId, int $contactId, ?int $actorId = null): bool
    {
        return $this->contacts->restore($siteId, $contactId, $actorId);
    }

    public function deleteArchivedContact(int $siteId, int $contactId): bool
    {
        return $this->contacts->deleteArchived($siteId, $contactId);
    }

    public function tagCompany(int $siteId, int $companyId, array $tagPayload, ?int $actorId = null): array
    {
        if ($this->tags === null) {
            throw new InvalidArgumentException('business.tags_unavailable');
        }
        if ($this->companies->find($siteId, $companyId) === null) {
            throw new InvalidArgumentException('business.company_not_found');
        }
        $tag = $this->tags->create($siteId, $tagPayload, $actorId);
        $this->tags->linkCompany((int) $tag['id'], $companyId, $actorId);
        return $tag;
    }

    public function tagContact(int $siteId, int $contactId, array $tagPayload, ?int $actorId = null): array
    {
        if ($this->tags === null) {
            throw new InvalidArgumentException('business.tags_unavailable');
        }
        if ($this->contacts->find($siteId, $contactId) === null) {
            throw new InvalidArgumentException('business.contact_not_found');
        }
        $tag = $this->tags->create($siteId, $tagPayload, $actorId);
        $this->tags->linkContact((int) $tag['id'], $contactId, $actorId);
        return $tag;
    }
}
