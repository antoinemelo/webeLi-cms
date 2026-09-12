<?php

declare(strict_types=1);

namespace App\Modules\Business\Services;

use App\Application\Forms\FormSubmissionActivitySink;
use App\Core\Database;
use InvalidArgumentException;

final class FormSubmissionRelationProjectionService implements FormSubmissionActivitySink
{
    public function __construct(private readonly Database $business) {}

    public function recordFormSubmission(array $event): void
    {
        $siteId = (int) ($event['site_id'] ?? 0);
        $formId = (int) ($event['form_id'] ?? 0);
        $submissionId = (int) ($event['submission_id'] ?? 0);
        if ($siteId < 1 || $formId < 1 || $submissionId < 1) {
            throw new InvalidArgumentException('business.form_activity_invalid');
        }
        [$companyId, $contactId, $strategy, $evidence, $candidateCount] = $this->resolve($siteId, $event);
        $this->business->run(
            "INSERT INTO crm_form_submission_activities(
                site_id,form_id,form_key,form_name,submission_id,submission_status,occurred_at,
                related_company_id,related_contact_id,resolution_strategy,resolution_evidence_json,
                candidate_count,safe_summary,retention_until
             ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON CONFLICT(site_id,form_id,submission_id,contract_version) DO UPDATE SET
                submission_status=excluded.submission_status,
                related_company_id=CASE WHEN crm_form_submission_activities.resolution_strategy IN ('manual','dismissed','postponed') THEN crm_form_submission_activities.related_company_id ELSE excluded.related_company_id END,
                related_contact_id=CASE WHEN crm_form_submission_activities.resolution_strategy IN ('manual','dismissed','postponed') THEN crm_form_submission_activities.related_contact_id ELSE excluded.related_contact_id END,
                resolution_strategy=CASE WHEN crm_form_submission_activities.resolution_strategy IN ('manual','dismissed','postponed') THEN crm_form_submission_activities.resolution_strategy ELSE excluded.resolution_strategy END,
                resolution_evidence_json=CASE WHEN crm_form_submission_activities.resolution_strategy IN ('manual','dismissed','postponed') THEN crm_form_submission_activities.resolution_evidence_json ELSE excluded.resolution_evidence_json END,
                candidate_count=excluded.candidate_count,updated_at=CURRENT_TIMESTAMP",
            [
                $siteId, $formId, (string) ($event['form_key'] ?? ('form-' . $formId)),
                $event['form_name'] ?? null, $submissionId, (string) ($event['status'] ?? 'received'),
                (string) ($event['occurred_at'] ?? gmdate('Y-m-d H:i:s')), $companyId, $contactId,
                $strategy, $this->json($evidence), $candidateCount,
                'Formulaire ' . ((string) ($event['form_name'] ?? $event['form_key'] ?? ('#' . $formId))) . ' reçu',
                $event['retention_until'] ?? null,
            ]
        );
    }

    /** @return array{0:?int,1:?int,2:string,3:array<string,mixed>,4:int} */
    private function resolve(int $siteId, array $event): array
    {
        $address = is_array($event['addressed_relation'] ?? null) ? $event['addressed_relation'] : [];
        $type = (string) ($address['type'] ?? '');
        $id = (int) ($address['id'] ?? 0);
        if (in_array($type, ['contact', 'company'], true) && $id > 0 && $this->relationExists($siteId, $type, $id)) {
            $companyId = $type === 'company' ? $id : $this->companyId($siteId, $id);
            return [$companyId, $type === 'contact' ? $id : null, 'explicit', ['proof' => 'signed_relation_address', 'relation_type' => $type], 1];
        }

        $email = strtolower(trim((string) ($event['verified_email'] ?? '')));
        if ($email !== '') {
            $rows = $this->business->all(
                "SELECT c.id,c.company_id FROM business_contacts c
                 JOIN crm_contact_channels ch ON ch.contact_id=c.id
                 WHERE c.site_id=? AND c.archived_at IS NULL AND ch.channel='email'
                   AND ch.normalized_value=? AND ch.is_verified=1 AND ch.archived_at IS NULL",
                [$siteId, $email]
            );
            if (count($rows) === 1) {
                $companyId = (int) ($rows[0]['company_id'] ?? 0);
                return [$companyId > 0 ? $companyId : null, (int) $rows[0]['id'], 'verified_email', ['proof' => 'unique_verified_crm_email'], 1];
            }
            return [null, null, 'pending', ['proof' => 'verified_email_ambiguous', 'candidate_count' => count($rows)], count($rows)];
        }
        return [null, null, 'pending', ['proof' => 'no_reliable_identity', 'ignored' => ['name', 'unverified_email', 'unnormalized_phone']], 0];
    }

    /** @return list<array<string,mixed>> */
    public function forRelation(int $siteId, string $type, int $id): array
    {
        $column = $type === 'company' ? 'related_company_id' : 'related_contact_id';
        return $this->business->all(
            "SELECT id,form_id,form_key,form_name,submission_id,submission_status,occurred_at,
                    resolution_strategy,safe_summary,retention_until
             FROM crm_form_submission_activities WHERE site_id=? AND {$column}=?
             ORDER BY occurred_at DESC,id DESC",
            [$siteId, $id]
        );
    }

    /** @return list<array<string,mixed>> */
    public function pending(int $siteId, int $limit = 100): array
    {
        return $this->business->all(
            "SELECT id,form_id,form_key,form_name,submission_id,submission_status,occurred_at,
                    candidate_count,safe_summary,resolution_evidence_json
             FROM crm_form_submission_activities WHERE site_id=? AND resolution_strategy='pending'
             ORDER BY occurred_at DESC,id DESC LIMIT " . max(1, min(200, $limit)),
            [$siteId]
        );
    }

    public function decide(int $siteId, int $activityId, string $decision, ?string $type, ?int $id, int $actorId, string $reason): array
    {
        if (!in_array($decision, ['link', 'unlink', 'postpone'], true) || $actorId < 1 || trim($reason) === '') {
            throw new InvalidArgumentException('business.form_link_decision_invalid');
        }
        $row = $this->business->one('SELECT * FROM crm_form_submission_activities WHERE site_id=? AND id=?', [$siteId, $activityId]);
        if ($row === null) throw new InvalidArgumentException('business.form_activity_not_found');
        $companyId = null; $contactId = null; $strategy = $decision === 'unlink' ? 'dismissed' : 'postponed';
        if ($decision === 'link') {
            if (!in_array($type, ['contact', 'company'], true) || ($id ?? 0) < 1 || !$this->relationExists($siteId, (string) $type, (int) $id)) {
                throw new InvalidArgumentException('business.form_link_relation_invalid');
            }
            $contactId = $type === 'contact' ? (int) $id : null;
            $companyId = $type === 'company' ? (int) $id : $this->companyId($siteId, (int) $id);
            $strategy = 'manual';
        }
        $this->business->transaction(function () use ($row, $activityId, $companyId, $contactId, $decision, $reason, $actorId, $strategy): void {
            $this->business->run(
                'INSERT INTO crm_form_submission_link_audit(activity_id,previous_company_id,previous_contact_id,company_id,contact_id,decision,reason,decided_by_iam_user_id) VALUES(?,?,?,?,?,?,?,?)',
                [$activityId, $row['related_company_id'], $row['related_contact_id'], $companyId, $contactId, $decision, trim($reason), $actorId]
            );
            $this->business->run(
                'UPDATE crm_form_submission_activities SET related_company_id=?,related_contact_id=?,resolution_strategy=?,resolution_evidence_json=?,linked_by_iam_user_id=?,linked_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [$companyId, $contactId, $strategy, $this->json(['proof' => 'operator_decision', 'decision' => $decision]), $actorId, $activityId]
            );
        });
        return $this->business->one('SELECT * FROM crm_form_submission_activities WHERE id=?', [$activityId]) ?? [];
    }

    private function relationExists(int $siteId, string $type, int $id): bool
    {
        $table = $type === 'contact' ? 'business_contacts' : 'business_companies';
        return $this->business->one("SELECT id FROM {$table} WHERE site_id=? AND id=? AND archived_at IS NULL", [$siteId, $id]) !== null;
    }

    private function companyId(int $siteId, int $contactId): ?int
    {
        $companyId = (int) ($this->business->one('SELECT company_id FROM business_contacts WHERE site_id=? AND id=?', [$siteId, $contactId])['company_id'] ?? 0);
        return $companyId > 0 ? $companyId : null;
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
