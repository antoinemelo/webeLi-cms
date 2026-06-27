<?php

declare(strict_types=1);

namespace App\Modules\Business\Services;

use App\Modules\Business\Repositories\BusinessCompanyRepository;
use App\Modules\Business\Repositories\BusinessConsentRepository;
use App\Modules\Business\Repositories\BusinessContactRepository;
use App\Modules\Business\Repositories\BusinessTagRepository;
use InvalidArgumentException;

final class BusinessCsvService
{
    private const EXPORT_DELIMITER = ';';
    private const MAX_IMPORT_BYTES = 1048576;
    private const CONTACT_IMPORT_HEADERS = [
        'company_name', 'first_name', 'last_name', 'display_name', 'email', 'phone', 'mobile', 'status', 'language',
        'email_consent', 'whatsapp_consent', 'telegram_consent', 'tags',
    ];

    public function __construct(
        private readonly BusinessCompanyRepository $companies,
        private readonly BusinessContactRepository $contacts,
        private readonly BusinessTagRepository $tags,
        private readonly BusinessConsentRepository $consents,
    ) {}

    /** @param array<string,mixed> $filters */
    public function companiesCsv(int $siteId, array $filters): string
    {
        $rows = [[
            'id', 'name', 'email', 'phone', 'website_url', 'status', 'tags', 'created_at', 'updated_at',
        ]];
        $companies = $this->companies->list(
            $siteId,
            $this->str($filters['q'] ?? ''),
            $this->str($filters['status'] ?? ''),
            10000,
            0,
            $this->includeArchived($filters),
            $this->str($filters['tag'] ?? ''),
        )['items'];
        foreach ($companies as $company) {
            $rows[] = [
                $company['id'] ?? '',
                $company['name'] ?? '',
                $company['email'] ?? '',
                $company['phone'] ?? '',
                $company['website_url'] ?? '',
                $company['status'] ?? '',
                implode(',', $this->tags->labelsForTarget($siteId, 'company', (int) ($company['id'] ?? 0))),
                $company['created_at'] ?? '',
                $company['updated_at'] ?? '',
            ];
        }
        return $this->csv($rows);
    }

    /** @param array<string,mixed> $filters */
    public function contactsCsv(int $siteId, array $filters): string
    {
        $companyId = isset($filters['company_id']) && $filters['company_id'] !== '' ? (int) $filters['company_id'] : null;
        $rows = [[
            'id', 'company_name', 'first_name', 'last_name', 'display_name', 'email', 'phone', 'mobile',
            'status', 'language', 'tags', 'email_consent', 'whatsapp_consent', 'telegram_consent',
            'created_at', 'updated_at',
        ]];
        $contacts = $this->contacts->list(
            $siteId,
            $this->str($filters['q'] ?? ''),
            $companyId,
            10000,
            0,
            $this->includeArchived($filters),
            $this->str($filters['status'] ?? ''),
            $this->str($filters['tag'] ?? ''),
        )['items'];
        foreach ($contacts as $contact) {
            $contactId = (int) ($contact['id'] ?? 0);
            $rows[] = [
                $contactId,
                $contact['company_name'] ?? '',
                $contact['first_name'] ?? '',
                $contact['last_name'] ?? '',
                $contact['display_name'] ?? '',
                $contact['email'] ?? '',
                $contact['phone'] ?? '',
                $contact['mobile'] ?? '',
                $contact['status'] ?? '',
                $contact['preferred_language'] ?? '',
                implode(',', $this->tags->labelsForTarget($siteId, 'contact', $contactId)),
                $this->consentStatus($contactId, 'email'),
                $this->consentStatus($contactId, 'whatsapp'),
                $this->consentStatus($contactId, 'telegram'),
                $contact['created_at'] ?? '',
                $contact['updated_at'] ?? '',
            ];
        }
        return $this->csv($rows);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function importContactsCsv(int $siteId, string $csv, array $options, ?int $actorId = null): array
    {
        if (strlen($csv) > self::MAX_IMPORT_BYTES) {
            throw new InvalidArgumentException('business.csv_file_too_large');
        }
        if (!$this->isUtf8($csv)) {
            throw new InvalidArgumentException('business.csv_utf8_required');
        }
        $dryRun = filter_var($options['dry_run'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $dryRun = $dryRun !== false;
        $confirmImport = filter_var($options['confirm_import'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$dryRun && !$confirmImport) {
            throw new InvalidArgumentException('business.import_confirm_required');
        }
        $createCompanies = filter_var($options['create_companies'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $updateExisting = filter_var($options['update_existing'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $delimiter = $this->detectDelimiter($csv);
        [$headers, $rows] = $this->parseCsv($csv, $delimiter);
        $this->assertKnownHeaders($headers);

        $report = [
            'dry_run' => $dryRun,
            'create_companies' => $createCompanies,
            'update_existing' => $updateExisting,
            'delimiter' => $delimiter,
            'rows_total' => count($rows),
            'valid_rows' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
            'rows' => [],
            'writes_performed' => false,
        ];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $normalized = $this->normalizeRow($headers, $row);
            $rowReport = ['line' => $line, 'status' => 'valid', 'action' => 'create', 'errors' => []];
            try {
                $plan = $this->planContactImportRow($siteId, $normalized, $createCompanies, $updateExisting, $dryRun, $actorId);
                $rowReport['action'] = $plan['action'];
                $rowReport['company_name'] = $plan['company_name'];
                $rowReport['display_name'] = $plan['payload']['display_name'];
                $report['valid_rows']++;
                if (!$dryRun) {
                    $contact = $plan['action'] === 'update'
                        ? $this->contacts->update($siteId, (int) $plan['contact_id'], $plan['payload'], $actorId)
                        : $this->contacts->create($siteId, $plan['payload'], $actorId);
                    if (!$contact) {
                        throw new InvalidArgumentException('business.contact_import_write_failed');
                    }
                    $this->applyImportSideEffects($siteId, (int) ($contact['id'] ?? 0), $normalized, $actorId);
                    $report[$plan['action'] === 'update' ? 'updated' : 'created']++;
                    $report['writes_performed'] = true;
                }
            } catch (InvalidArgumentException $e) {
                $rowReport['status'] = 'error';
                $rowReport['errors'][] = $e->getMessage();
                $report['errors'][] = ['line' => $line, 'message' => $e->getMessage()];
                $report['skipped']++;
            }
            $report['rows'][] = $rowReport;
        }

        return $report;
    }

    /** @return array{0:list<string>,1:list<list<string>>} */
    private function parseCsv(string $csv, string $delimiter): array
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new InvalidArgumentException('business.csv_read_failed');
        }
        fwrite($handle, $this->stripBom($csv));
        rewind($handle);
        $headers = fgetcsv($handle, 0, $delimiter, '"', '');
        if (!is_array($headers) || $headers === []) {
            fclose($handle);
            throw new InvalidArgumentException('business.csv_header_required');
        }
        $headers = array_map(fn(mixed $value): string => $this->headerKey((string) $value), $headers);
        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if (!is_array($row) || $this->emptyRow($row)) {
                continue;
            }
            $rows[] = array_map(static fn(mixed $value): string => trim((string) $value), $row);
        }
        fclose($handle);
        return [$headers, $rows];
    }

    /** @param list<string> $headers */
    private function assertKnownHeaders(array $headers): void
    {
        $known = array_fill_keys(array_merge(['id'], self::CONTACT_IMPORT_HEADERS), true);
        foreach ($headers as $header) {
            if (!isset($known[$header])) {
                throw new InvalidArgumentException('business.csv_unknown_header_' . $header);
            }
        }
        foreach (['display_name', 'email'] as $required) {
            if (!in_array($required, $headers, true)) {
                throw new InvalidArgumentException('business.csv_header_' . $required . '_required');
            }
        }
    }

    /** @param list<string> $headers @param list<string> $row @return array<string,string> */
    private function normalizeRow(array $headers, array $row): array
    {
        $data = [];
        foreach ($headers as $index => $header) {
            $data[$header] = trim((string) ($row[$index] ?? ''));
        }
        return $data;
    }

    /** @param array<string,string> $row @return array<string,mixed> */
    private function planContactImportRow(int $siteId, array $row, bool $createCompanies, bool $updateExisting, bool $dryRun, ?int $actorId): array
    {
        $displayName = trim($row['display_name'] ?? '');
        $email = strtolower(trim($row['email'] ?? ''));
        if ($displayName === '') {
            throw new InvalidArgumentException('business.display_name_required');
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('business.email_invalid');
        }
        $status = $row['status'] !== '' ? $row['status'] : 'prospect';
        if (!in_array($status, ['prospect', 'client', 'supplier', 'former_client', 'other'], true)) {
            throw new InvalidArgumentException('business.status_invalid');
        }
        $companyName = trim($row['company_name'] ?? '');
        $company = $companyName === '' ? $this->findCompanyByName($siteId, 'Individus') : $this->findCompanyByName($siteId, $companyName);
        if ($company === null && $companyName === '') {
            $company = $dryRun ? ['id' => 0, 'name' => 'Individus'] : $this->companies->ensureSystemIndividualsCompany($siteId, $actorId);
        }
        if ($company === null && $createCompanies) {
            $company = $dryRun
                ? ['id' => 0, 'name' => $companyName !== '' ? $companyName : 'Individus']
                : $this->companies->create($siteId, ['name' => $companyName !== '' ? $companyName : 'Individus', 'status' => 'prospect'], $actorId);
        }
        if ($company === null) {
            throw new InvalidArgumentException('business.import_company_not_found');
        }
        $existing = $this->findContactByEmail($siteId, $email);
        if ($existing !== null && !$updateExisting) {
            throw new InvalidArgumentException('business.import_contact_exists');
        }
        $payload = [
            'company_id' => (int) $company['id'],
            'first_name' => $row['first_name'] ?? '',
            'last_name' => $row['last_name'] ?? '',
            'display_name' => $displayName,
            'email' => $email,
            'phone' => $row['phone'] ?? '',
            'mobile' => $row['mobile'] ?? '',
            'status' => $status,
            'preferred_language' => strtolower(trim($row['language'] ?? '')),
        ];
        return [
            'action' => $existing !== null ? 'update' : 'create',
            'contact_id' => $existing['id'] ?? null,
            'company_name' => $company['name'] ?? $companyName,
            'payload' => $payload,
        ];
    }

    /** @param array<string,string> $row */
    private function applyImportSideEffects(int $siteId, int $contactId, array $row, ?int $actorId): void
    {
        if ($contactId < 1) {
            return;
        }
        foreach (['email', 'whatsapp', 'telegram'] as $channel) {
            $status = trim($row[$channel . '_consent'] ?? '');
            if ($status !== '') {
                $this->consents->upsertConsent($contactId, $channel, $this->normalizeConsent($status), 'import', 'CSV import', $actorId);
            }
        }
        foreach ($this->splitTags($row['tags'] ?? '') as $label) {
            $tag = $this->tags->create($siteId, ['label' => $label], $actorId);
            if (isset($tag['id'])) {
                $this->tags->linkContact((int) $tag['id'], $contactId, $actorId);
            }
        }
    }

    private function findCompanyByName(int $siteId, string $name): ?array
    {
        $result = $this->companies->list($siteId, $name, '', 50, 0, false);
        $needle = strtolower(trim($name));
        foreach ($result['items'] as $company) {
            if (strtolower(trim((string) ($company['name'] ?? ''))) === $needle) {
                return $company;
            }
        }
        return null;
    }

    private function findContactByEmail(int $siteId, string $email): ?array
    {
        $result = $this->contacts->list($siteId, $email, null, 50, 0, false);
        foreach ($result['items'] as $contact) {
            if (strtolower((string) ($contact['email'] ?? '')) === strtolower($email)) {
                return $contact;
            }
        }
        return null;
    }

    private function consentStatus(int $contactId, string $channel): string
    {
        return (string) ($this->consents->consentForContact($contactId, $channel)['consent_status'] ?? 'unknown');
    }

    /** @param list<list<mixed>> $rows */
    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn(mixed $value): string => $this->safeCell($value), $row), self::EXPORT_DELIMITER, '"', '');
        }
        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);
        return $csv;
    }

    private function safeCell(mixed $value): string
    {
        $text = (string) ($value ?? '');
        return preg_match('/^[=+\-@]/', $text) ? "'" . $text : $text;
    }

    /** @param array<string,mixed> $filters */
    private function includeArchived(array $filters): bool
    {
        return in_array((string) ($filters['archived'] ?? '0'), ['1', 'all'], true);
    }

    private function detectDelimiter(string $csv): string
    {
        $line = strtok($this->stripBom($csv), "\r\n") ?: '';
        return substr_count($line, ';') >= substr_count($line, ',') ? ';' : ',';
    }

    private function stripBom(string $csv): string
    {
        return str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv;
    }

    private function headerKey(string $header): string
    {
        $header = strtolower(trim($this->stripBom($header)));
        return str_replace([' ', '-'], '_', $header);
    }

    /** @param list<mixed> $row */
    private function emptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }
        return true;
    }

    private function isUtf8(string $value): bool
    {
        return preg_match('//u', $value) === 1;
    }

    private function normalizeConsent(string $status): string
    {
        $status = strtolower(trim($status));
        return match ($status) {
            'yes', 'true', '1', 'optin', 'opt-in', 'opt_in' => 'opt_in',
            'no', 'false', '0', 'optout', 'opt-out', 'opt_out' => 'opt_out',
            default => 'unknown',
        };
    }

    /** @return list<string> */
    private function splitTags(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,|]/', $value) ?: []), static fn(string $tag): bool => $tag !== ''));
    }

    private function str(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }
}
