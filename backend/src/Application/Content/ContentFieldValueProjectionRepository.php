<?php

declare(strict_types=1);

namespace App\Application\Content;

use App\Domain\Schema\ContentType;

/**
 * Projection secondaire des champs éditoriaux.
 *
 * Contrat volontairement limité : cette table n'est jamais la vérité publique.
 * La vérité éditoriale reste le document_json de la révision ; le runtime public
 * lit uniquement les projections publiées reconstruites depuis content_entry_publications.
 */
interface ContentFieldValueProjectionRepository
{
    /** @param array<string,mixed> $document */
    public function replaceDraftIndexForRevision(
        int $entryId,
        int $localizationId,
        int $contentTypeId,
        ContentType $contentType,
        string $languageCode,
        int $revisionId,
        array $document
    ): void;
}
