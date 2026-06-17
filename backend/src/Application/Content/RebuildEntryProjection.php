<?php

declare(strict_types=1);

namespace App\Application\Content;

use App\Application\Publication\PublishedProjectionPipeline;

/**
 * Adaptateur de compatibilité pour les appels existants au rebuild d'entrée.
 *
 * Il ne contient plus de logique métier de projection : la reconstruction passe
 * par PublishedProjectionPipeline, le même pipeline que la publication normale,
 * les imports post-seed et les réparations locales.
 */
final class RebuildEntryProjection
{
    public function __construct(private readonly PublishedProjectionPipeline $pipeline) {}

    /** @return list<string> langues reconstruites */
    public function execute(int $entryId): array
    {
        return $this->pipeline->rebuildEntry($entryId);
    }
}
