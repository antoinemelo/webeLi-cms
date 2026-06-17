<?php

declare(strict_types=1);

namespace App\Application\Publication;

/**
 * Port d'écriture cohérente des projections publiques critiques.
 *
 * Il sert à remplacer ou retirer le lot atomique
 * public_content_snapshots/routes/seo_metadata/search_documents lors de la
 * publication, de la dépublication ou d'un rebuild. Ce port n'est pas le port
 * de lecture cible de Jamstack ; l'export statique devra lire les projections
 * via un adaptateur dédié pour éviter de mélanger écriture et export.
 */
interface PublishedProjectionStore
{
    /** @return list<array<string,mixed>> */
    public function listRoutesForEntry(int $entryId): array;

    public function pathIsAvailable(int $siteId, string $languageCode, string $path, string $resourceType, int $resourceId): bool;

    public function replaceCriticalProjection(PublishedProjection $projection, string $fallbackTitle = ''): void;

    /**
     * Vérifie qu'une langue publiée possède exactement le même jeu public
     * critique sur routes, seo_metadata, search_documents et snapshots.
     *
     * @return list<string> messages d'erreur; liste vide si le set est cohérent
     */
    public function verifyCriticalProjection(PublishedProjection $projection): array;

    public function removeCriticalProjection(string $resourceType, int $resourceId, ?string $languageCode = null): void;

    public function clearEntryProjectionArtifacts(string $resourceType, int $resourceId): void;

    /** @param list<array<string,mixed>> $oldRoutes */
    public function createTombstonesForRemovedRoutes(int $siteId, string $resourceType, int $resourceId, ?string $languageCode, array $oldRoutes, ?string $reason = null): void;

    /** @param list<array<string,mixed>> $oldRoutes @param array<string,string> $pathsByLanguage */
    public function createRedirectsForPathChanges(array $oldRoutes, array $pathsByLanguage): void;
}
