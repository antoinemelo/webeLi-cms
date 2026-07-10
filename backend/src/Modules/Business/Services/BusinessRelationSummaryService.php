<?php

declare(strict_types=1);

namespace App\Modules\Business\Services;

use App\Modules\Business\Repositories\BusinessActivityRepository;
use App\Modules\Business\Repositories\BusinessRelationReadRepository;
use InvalidArgumentException;

final class BusinessRelationSummaryService
{
    public function __construct(
        private readonly BusinessRelationReadRepository $relations,
        private readonly BusinessActivityRepository $activity,
    ) {}

    /** @return array<string,mixed> */
    public function summarize(int $siteId, string $type, int $id): array
    {
        $relation = $this->relations->find($siteId, $type, $id);
        if ($relation === null) {
            throw new InvalidArgumentException('business.relation_not_found');
        }

        $memos = $this->relations->memos($siteId, $type, $id, 8, 0, false);
        try {
            $activity = $this->activity->relationActivity($siteId, $type, $id, 12, 0);
        } catch (InvalidArgumentException $e) {
            if ($e->getMessage() !== 'business.relation_not_found') {
                throw $e;
            }
            $activity = ['items' => [], 'limit' => 12, 'offset' => 0, 'total' => 0];
        }
        $memoItems = $memos['items'];
        $activityItems = $activity['items'];

        $highlights = $this->highlights($relation, $memoItems, $activityItems);
        $nextActions = $this->nextActions($relation, $memoItems, $activityItems);

        return [
            'provider' => 'local_summary',
            'external_call' => false,
            'generated_at' => function_exists('now_utc') ? now_utc() : gmdate('Y-m-d H:i:s'),
            'relation' => $this->relationReference($relation),
            'summary' => implode("\n", array_map(static fn(string $line): string => '- ' . $line, $highlights)),
            'highlights' => $highlights,
            'next_actions' => $nextActions,
            'sources' => [
                'memos_count' => count($memoItems),
                'activity_count' => count($activityItems),
                'memo_ids' => array_values(array_map(static fn(array $memo): int => (int) ($memo['id'] ?? 0), $memoItems)),
                'activity_ids' => array_values(array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $activityItems)),
            ],
            'limits' => [
                'mode' => 'extractive_local',
                'external_ai_configured' => false,
                'external_ai_called' => false,
            ],
        ];
    }

    /** @param array<string,mixed> $relation */
    private function relationReference(array $relation): array
    {
        return [
            'type' => (string) ($relation['type'] ?? ''),
            'id' => (int) ($relation['id'] ?? 0),
            'display_name' => (string) ($relation['display_name'] ?? $relation['name'] ?? ''),
            'status' => (string) ($relation['status'] ?? ''),
            'company_id' => isset($relation['company_id']) ? (int) $relation['company_id'] : null,
        ];
    }

    /**
     * @param array<string,mixed> $relation
     * @param list<array<string,mixed>> $memos
     * @param list<array<string,mixed>> $activity
     * @return list<string>
     */
    private function highlights(array $relation, array $memos, array $activity): array
    {
        $displayName = (string) ($relation['display_name'] ?? $relation['name'] ?? 'Relation');
        $type = (string) ($relation['type'] ?? 'relation');
        $status = trim((string) ($relation['status'] ?? ''));
        $lines = [sprintf('%s %s%s.', $type === 'company' ? 'Organisation' : 'Contact', $displayName, $status !== '' ? ' (' . $status . ')' : '')];

        $email = trim((string) ($relation['primary_email'] ?? $relation['email'] ?? ''));
        $phone = trim((string) ($relation['primary_phone'] ?? $relation['phone'] ?? $relation['mobile'] ?? ''));
        if ($email !== '' || $phone !== '') {
            $lines[] = 'Canaux disponibles : ' . implode(', ', array_values(array_filter([$email !== '' ? 'e-mail' : '', $phone !== '' ? 'telephone' : '']))) . '.';
        }

        foreach (array_slice($memos, 0, 2) as $memo) {
            $title = trim((string) ($memo['title'] ?? ''));
            $excerpt = $this->excerpt((string) ($memo['body'] ?? ''));
            if ($title !== '') {
                $lines[] = 'Memo recent : ' . $title . ($excerpt !== '' ? ' - ' . $excerpt : '') . '.';
            }
        }

        foreach (array_slice($activity, 0, 2) as $row) {
            $summary = $this->excerpt((string) ($row['summary'] ?? ''));
            if ($summary !== '') {
                $lines[] = 'Activite recente : ' . $summary . '.';
            }
        }

        return array_slice(array_values(array_unique($lines)), 0, 5);
    }

    /**
     * @param array<string,mixed> $relation
     * @param list<array<string,mixed>> $memos
     * @param list<array<string,mixed>> $activity
     * @return list<string>
     */
    private function nextActions(array $relation, array $memos, array $activity): array
    {
        $actions = [];
        $emailConsent = (string) ($relation['email_consent_status'] ?? '');
        if (trim((string) ($relation['primary_email'] ?? $relation['email'] ?? '')) !== '' && !in_array($emailConsent, ['opt_in', 'granted'], true)) {
            $actions[] = 'Verifier le consentement e-mail avant tout envoi.';
        }
        if ($memos === []) {
            $actions[] = 'Ajouter un premier memo de contexte.';
        }
        if ($activity === []) {
            $actions[] = 'Planifier une premiere action suivie dans le CRM.';
        }
        if ($actions === []) {
            $actions[] = 'Relire les dernieres activites avant la prochaine prise de contact.';
        }
        return $actions;
    }

    private function excerpt(string $text, int $limit = 160): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
        if ($clean === '') {
            return '';
        }
        if (strlen($clean) <= $limit) {
            return $clean;
        }
        return rtrim(substr($clean, 0, $limit - 1)) . '...';
    }
}
