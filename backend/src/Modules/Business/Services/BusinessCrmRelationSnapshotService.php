<?php

declare(strict_types=1);

namespace App\Modules\Business\Services;

use App\Modules\Business\Repositories\BusinessCompanyRepository;
use App\Modules\Business\Repositories\BusinessContactRepository;
use InvalidArgumentException;

final class BusinessCrmRelationSnapshotService
{
    public function __construct(
        private readonly BusinessCompanyRepository $companies,
        private readonly BusinessContactRepository $contacts
    ) {}

    /** @return array<string,mixed>|null */
    public function snapshot(int $siteId, ?int $companyId = null, ?int $contactId = null): ?array
    {
        $siteId = $this->requireSiteId($siteId);
        $companyId = $companyId !== null && $companyId > 0 ? $companyId : null;
        $contactId = $contactId !== null && $contactId > 0 ? $contactId : null;
        if ($companyId === null && $contactId === null) {
            return null;
        }

        $contact = null;
        if ($contactId !== null) {
            $contact = $this->contacts->find($siteId, $contactId);
            if ($contact === null) {
                throw new InvalidArgumentException('business.crm.contact_not_found');
            }
            $contactCompanyId = isset($contact['company_id']) ? (int) $contact['company_id'] : null;
            if ($companyId !== null && $contactCompanyId !== $companyId) {
                throw new InvalidArgumentException('business.crm.contact_company_mismatch');
            }
            $companyId = $contactCompanyId;
        }

        $company = null;
        if ($companyId !== null) {
            $company = $this->companies->find($siteId, $companyId);
            if ($company === null) {
                throw new InvalidArgumentException('business.crm.company_not_found');
            }
        }

        $address = $this->address($company['address_json'] ?? null);
        $displayName = (string) ($contact['display_name'] ?? $company['name'] ?? '');
        if (trim($displayName) === '') {
            throw new InvalidArgumentException('business.crm.display_name_missing');
        }

        return [
            'site_id' => $siteId,
            'company_id' => $companyId,
            'contact_id' => $contactId,
            'display_name' => $displayName,
            'email' => $this->nullableString($contact['email'] ?? $company['email'] ?? null),
            'phone' => $this->nullableString($contact['mobile'] ?? $contact['phone'] ?? $company['phone'] ?? null),
            'billing_address' => $address,
            'shipping_address' => $address,
            'language' => $this->nullableString($contact['preferred_language'] ?? null),
            'crm_status' => (string) ($contact['status'] ?? $company['status'] ?? 'prospect'),
            'metadata' => [
                'company_name' => $company['name'] ?? null,
                'contact_company_id' => $contact['company_id'] ?? null,
                'contact_iam_user_id' => $contact['iam_user_id'] ?? null,
            ],
        ];
    }

    private function requireSiteId(int $siteId): int
    {
        if ($siteId < 1) {
            throw new InvalidArgumentException('business.site_id_invalid');
        }
        return $siteId;
    }

    /** @return array<string,mixed> */
    private function address(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }
}
