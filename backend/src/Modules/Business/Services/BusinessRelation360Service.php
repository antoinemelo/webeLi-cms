<?php

declare(strict_types=1);

namespace App\Modules\Business\Services;

use App\Core\Database;
use App\Modules\Business\Repositories\BusinessActivityRepository;
use App\Modules\Business\Repositories\BusinessRelationReadRepository;
use InvalidArgumentException;

final class BusinessRelation360Service
{
    public function __construct(
        private readonly Database $business,
        private readonly BusinessRelationReadRepository $relations,
        private readonly BusinessActivityRepository $activity,
        private readonly FormSubmissionRelationProjectionService $forms,
    ) {}

    /** @return array<string,mixed> */
    public function view(int $siteId, string $type, int $id, bool $includeConsents): array
    {
        if (!in_array($type, ['contact', 'company'], true) || $id < 1) throw new InvalidArgumentException('business.relation_type_invalid');
        $relation = $this->relations->find($siteId, $type, $id);
        if ($relation === null) throw new InvalidArgumentException('business.relation_not_found');
        $activity = $this->activity->relationActivity($siteId, $type, $id, 200, 0);
        $timeline = array_values(array_filter($activity['items'], static fn(array $row): bool => $includeConsents || ($row['kind'] ?? '') !== 'consent'));
        $forms = $this->forms->forRelation($siteId, $type, $id);
        $tasks = $this->tasks($siteId, $type, $id);
        $roles = $this->roles($siteId, $type, $id, (string) ($relation['status'] ?? 'other'));
        $sale = $this->saleSections($timeline, $type, $id);
        $next = $tasks[0] ?? $this->derivedNextAction($relation, $sale, $forms);
        return [
            'relation' => array_merge($relation, [
                'roles' => $roles,
                'responsible_iam_user_id' => $tasks[0]['assigned_to_iam_user_id'] ?? null,
                'identity_linked' => $type === 'contact' && (int) ($relation['iam_user_id'] ?? 0) > 0,
            ]),
            'next_action' => $next,
            'alerts' => $this->alerts($relation, $sale, $forms),
            'timeline' => $timeline,
            'orders' => $sale['orders'],
            'financial' => $sale['financial'],
            'fulfillments' => $sale['fulfillments'],
            'forms' => $forms,
            'consents_available' => $includeConsents && $type === 'contact',
            'projection_health' => ['sale' => 'available', 'forms' => 'available', 'degraded' => false],
            'ownership' => [
                'relation' => 'business_crm', 'orders' => 'sale', 'financial' => 'sale',
                'fulfillments' => 'sale', 'forms' => 'core_forms',
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function degradedView(int $siteId, string $type, int $id, bool $includeConsents, string $failedProjection): array
    {
        $relation = $this->relations->find($siteId, $type, $id);
        if ($relation === null) throw new InvalidArgumentException('business.relation_not_found');
        return [
            'relation' => array_merge($relation, ['roles' => $this->roles($siteId, $type, $id, (string) ($relation['status'] ?? 'other'))]),
            'next_action' => $this->derivedNextAction($relation, ['orders' => [], 'financial' => [], 'fulfillments' => []], []),
            'alerts' => [['level' => 'warning', 'message' => 'Une projection est momentanément indisponible. Les ventes restent accessibles dans Ventes.']],
            'timeline' => [], 'orders' => [], 'financial' => [], 'fulfillments' => [], 'forms' => [],
            'consents_available' => $includeConsents && $type === 'contact',
            'projection_health' => ['sale' => $failedProjection === 'sale' ? 'unavailable' : 'available', 'forms' => $failedProjection === 'forms' ? 'unavailable' : 'available', 'degraded' => true],
            'ownership' => ['relation' => 'business_crm', 'orders' => 'sale', 'financial' => 'sale', 'fulfillments' => 'sale', 'forms' => 'core_forms'],
        ];
    }

    public function assertExists(int $siteId, string $type, int $id): void
    {
        if (!in_array($type, ['contact', 'company'], true) || $id < 1) throw new InvalidArgumentException('business.relation_type_invalid');
        if ($this->relations->find($siteId, $type, $id) === null) throw new InvalidArgumentException('business.relation_not_found');
    }

    /** @return list<array<string,mixed>> */
    private function roles(int $siteId, string $type, int $id, string $fallback): array
    {
        $column = $type === 'contact' ? 'contact_id' : 'company_id';
        $rows = $this->business->all("SELECT role_key,source FROM business_relation_roles WHERE site_id=? AND relation_type=? AND {$column}=? ORDER BY role_key", [$siteId, $type, $id]);
        if ($rows !== []) return $rows;
        $role = in_array($fallback, ['prospect', 'client', 'supplier'], true) ? $fallback : 'other';
        return [['role_key' => $role, 'source' => 'system']];
    }

    /** @return list<array<string,mixed>> */
    private function tasks(int $siteId, string $type, int $id): array
    {
        $column = $type === 'contact' ? 'contact_id' : 'company_id';
        return $this->business->all(
            "SELECT id,title,due_at,status,priority,assigned_to_iam_user_id,created_at FROM business_relation_tasks
             WHERE site_id=? AND relation_type=? AND {$column}=? AND status='open'
             ORDER BY CASE priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END,COALESCE(due_at,'9999-12-31'),id",
            [$siteId, $type, $id]
        );
    }

    /** @return array<string,mixed> */
    public function createTask(int $siteId, string $type, int $id, array $payload, int $actorId): array
    {
        if ($this->relations->find($siteId, $type, $id) === null) throw new InvalidArgumentException('business.relation_not_found');
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') throw new InvalidArgumentException('business.relation_task_title_required');
        $priority = in_array($payload['priority'] ?? '', ['low', 'normal', 'high'], true) ? (string) $payload['priority'] : 'normal';
        $this->business->run(
            'INSERT INTO business_relation_tasks(site_id,relation_type,contact_id,company_id,title,due_at,priority,assigned_to_iam_user_id,created_by_iam_user_id) VALUES(?,?,?,?,?,?,?,?,?)',
            [$siteId, $type, $type === 'contact' ? $id : null, $type === 'company' ? $id : null, substr($title, 0, 240), $payload['due_at'] ?? null, $priority, (int) ($payload['assigned_to_iam_user_id'] ?? 0) ?: null, $actorId]
        );
        return $this->business->one('SELECT * FROM business_relation_tasks WHERE id=?', [$this->business->lastInsertId()]) ?? [];
    }

    /** @param list<string> $roles @return list<array<string,mixed>> */
    public function replaceRoles(int $siteId, string $type, int $id, array $roles, int $actorId): array
    {
        if ($this->relations->find($siteId, $type, $id) === null) throw new InvalidArgumentException('business.relation_not_found');
        $roles = array_values(array_unique(array_map(static fn(mixed $role): string => strtolower(trim((string) $role)), $roles)));
        if ($roles === [] || array_diff($roles, ['prospect', 'client', 'supplier', 'partner', 'other']) !== []) {
            throw new InvalidArgumentException('business.relation_roles_invalid');
        }
        $column = $type === 'contact' ? 'contact_id' : 'company_id';
        $this->business->transaction(function () use ($siteId, $type, $id, $actorId, $roles, $column): void {
            $this->business->run("DELETE FROM business_relation_roles WHERE site_id=? AND relation_type=? AND {$column}=?", [$siteId, $type, $id]);
            foreach ($roles as $role) {
                $this->business->run(
                    'INSERT INTO business_relation_roles(site_id,relation_type,contact_id,company_id,role_key,source,created_by_iam_user_id) VALUES(?,?,?,?,?,?,?)',
                    [$siteId, $type, $type === 'contact' ? $id : null, $type === 'company' ? $id : null, $role, 'operator', $actorId]
                );
            }
        });
        return $this->roles($siteId, $type, $id, 'other');
    }

    /** @return array{orders:list<array<string,mixed>>,financial:list<array<string,mixed>>,fulfillments:list<array<string,mixed>>} */
    private function saleSections(array $timeline, string $type, int $id): array
    {
        $result = ['orders' => [], 'financial' => [], 'fulfillments' => []];
        foreach ($timeline as $row) {
            if (($row['kind'] ?? '') !== 'sale') continue;
            $action = (string) ($row['action'] ?? '');
            $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
            $orderId = (int) ($metadata['order_id'] ?? $row['entity_id'] ?? 0);
            $reference = (string) ($metadata['source_reference'] ?? ('#' . $orderId));
            $entry = [
                'id' => (int) ($row['id'] ?? 0), 'order_id' => $orderId, 'reference' => $reference,
                'summary' => (string) ($row['summary'] ?? ''), 'status' => (string) ($metadata['status'] ?? ''),
                'source' => (string) ($metadata['channel'] ?? 'unknown'), 'occurred_at' => $row['created_at'] ?? null,
                'owner_link' => '/sale/orders?order_id=' . $orderId . '&return_to=' . rawurlencode('/business/relations?relation_type=' . $type . '&relation_id=' . $id),
            ];
            if (str_contains($action, 'payment') || str_contains($action, 'refund') || str_contains($action, 'gift_card') || str_contains($action, 'invoice') || str_contains($action, 'credit_note')) $result['financial'][] = $entry;
            elseif (str_contains($action, 'fulfillment') || str_contains($action, 'return') || str_contains($action, 'shipment') || str_contains($action, 'delivery') || str_contains($action, 'pickup')) $result['fulfillments'][] = $entry;
            elseif (str_contains($action, 'order') || str_contains($action, 'cart')) $result['orders'][] = $entry;
        }
        foreach ($result as $key => $rows) {
            $seen = [];
            $result[$key] = array_values(array_filter($rows, static function (array $row) use (&$seen): bool {
                $fingerprint = $row['id'] . ':' . $row['summary'];
                if (isset($seen[$fingerprint])) return false;
                return $seen[$fingerprint] = true;
            }));
        }
        return $result;
    }

    private function derivedNextAction(array $relation, array $sale, array $forms): array
    {
        if (($sale['financial'] ?? []) !== [] && str_contains(strtolower((string) ($sale['financial'][0]['status'] ?? '')), 'fail')) {
            return ['title' => 'Vérifier le paiement refusé', 'source' => 'sale_projection', 'priority' => 'high'];
        }
        if ($forms !== []) return ['title' => 'Répondre à la dernière soumission', 'source' => 'forms_projection', 'priority' => 'normal'];
        if (trim((string) ($relation['primary_email'] ?? '')) === '' && trim((string) ($relation['phone'] ?? '')) === '') {
            return ['title' => 'Compléter les coordonnées', 'source' => 'crm', 'priority' => 'normal'];
        }
        return ['title' => 'Planifier un prochain contact', 'source' => 'crm', 'priority' => 'normal'];
    }

    /** @return list<array{level:string,message:string}> */
    private function alerts(array $relation, array $sale, array $forms): array
    {
        $alerts = [];
        if (($relation['email_consent_status'] ?? null) === 'opt_out') $alerts[] = ['level' => 'info', 'message' => 'Consentement marketing retiré ; le suivi transactionnel reste autorisé.'];
        if (($sale['financial'] ?? []) !== [] && str_contains(strtolower(json_encode($sale['financial']) ?: ''), 'fail')) $alerts[] = ['level' => 'warning', 'message' => 'Un paiement demande une vérification.'];
        if ($forms !== [] && ($forms[0]['submission_status'] ?? '') === 'received') $alerts[] = ['level' => 'info', 'message' => 'Une soumission récente attend une réponse.'];
        return $alerts;
    }
}
