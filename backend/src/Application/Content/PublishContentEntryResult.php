<?php

declare(strict_types=1);

namespace App\Application\Content;

/**
 * Résultat applicatif explicite du scénario PublishContentEntry.
 *
 * Depuis la stratégie atomique V1, projections_to_rebuild doit rester vide :
 * les projections critiques sont déjà prêtes quand la réponse est retournée.
 */
final class PublishContentEntryResult
{
    /** @param list<string> $events @param list<string> $projectionsToRebuild @param list<string> $criticalProjectionsReady */
    public function __construct(
        public readonly int $entryId,
        public readonly int $publishedRevisionId,
        public readonly string $languageCode,
        public readonly ?int $previousPublishedRevisionId,
        public readonly int $publishedByUserId,
        public readonly string $publishedAt,
        public readonly string $entryStatus,
        public readonly string $workflowState,
        public readonly array $events = [],
        public readonly array $projectionsToRebuild = [],
        public readonly array $criticalProjectionsReady = [],
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'entry_id' => $this->entryId,
            'published_revision_id' => $this->publishedRevisionId,
            'language_code' => $this->languageCode,
            'previous_published_revision_id' => $this->previousPublishedRevisionId,
            'published_by_user_id' => $this->publishedByUserId,
            'published_at' => $this->publishedAt,
            'entry_status' => $this->entryStatus,
            'workflow_state' => $this->workflowState,
            'events' => $this->events,
            'projections_to_rebuild' => $this->projectionsToRebuild,
            'critical_projections_ready' => $this->criticalProjectionsReady,
            'publication_atomic' => true,
            'projections' => [
                'snapshot' => in_array('public_content_snapshots', $this->criticalProjectionsReady, true) ? 'rebuilt' : 'not_rebuilt',
                'routes' => in_array('routes', $this->criticalProjectionsReady, true) ? 'rebuilt' : 'not_rebuilt',
                'seo' => in_array('seo_metadata', $this->criticalProjectionsReady, true) ? 'rebuilt' : 'not_rebuilt',
                'search' => in_array('search_documents', $this->criticalProjectionsReady, true) ? 'rebuilt' : 'not_rebuilt',
            ],
        ];
    }
}
