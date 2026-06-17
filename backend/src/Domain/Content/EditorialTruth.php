<?php

declare(strict_types=1);

namespace App\Domain\Content;

/**
 * Contrat imposé de vérité éditoriale.
 *
 * Règles non négociables :
 * - toute modification éditoriale passe par SaveContentDraft ;
 * - toute mise en ligne passe par PublishContentEntry ;
 * - le front public ne lit jamais la révision de travail ni les champs de brouillon ;
 * - la projection publique est reconstruite exclusivement depuis
 *   content_entry_publications.published_revision_id par langue, puis gelée dans public_content_snapshots ;
 * - content_entry_localizations est un espace de travail éditorial, pas une vérité publique;
 * - content_entry_field_values est un index secondaire de brouillon, pas une vérité publique.
 */
final class EditorialTruth
{
    public const WRITE_DRAFT_USE_CASE = 'SaveContentDraft';
    public const PUBLISH_USE_CASE = 'PublishContentEntry';
    public const PUBLIC_REVISION_POINTER = 'content_entry_publications.published_revision_id';
    public const WORKING_REVISION_POINTER = 'content_entry_working_revisions.working_revision_id';
    public const PUBLIC_PROJECTION_SOURCE = 'published_revision_document';
    public const DRAFT_WORKSPACE_TABLE = 'content_entry_localizations';
    public const DRAFT_FIELD_INDEX_TABLE = 'content_entry_field_values';
    public const DRAFT_FIELD_INDEX_SCOPE = 'draft_index';

    public static function assertPublishedPointer(?int $revisionId, int $entryId): void
    {
        if (!$revisionId) {
            throw new \RuntimeException(sprintf(
                'Vérité éditoriale invalide : l’entrée %d n’a pas de publication par langue.',
                $entryId
            ));
        }
    }

    public static function assertPublicRevision(?ContentRevision $revision, int $entryId): void
    {
        if (!$revision || !$revision->id || !$revision->isForEntry($entryId) || !$revision->isPublished()) {
            throw new \RuntimeException(sprintf(
                'Vérité éditoriale invalide : la projection publique de l’entrée %d doit partir d’une révision publiée.',
                $entryId
            ));
        }
    }

    private function __construct() {}
}
