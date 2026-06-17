<?php

declare(strict_types=1);

namespace App\StaticExport;

/**
 * Port de lecture pour l'export statique.
 *
 * L'implémentation lit uniquement les projections publiques existantes : routes,
 * public_content_snapshots, seo_metadata, redirects, tombstones,
 * search_documents, médias publics, menus et paramètres site/langue.
 */
interface StaticExportSourceContract
{
    /** @return list<StaticExportRoute> */
    public function listExportableRoutes(?string $siteKey = null, ?string $languageCode = null, ?string $routePath = null): array;

    /** @return array<string,mixed>|null */
    public function getPublicSnapshot(StaticExportRoute $route): ?array;

    /** @return array<string,mixed> */
    public function getSeoMetadata(StaticExportRoute $route): array;

    /** @return list<array<string,mixed>> */
    public function listRedirects(?string $siteKey = null): array;

    /** @return list<array<string,mixed>> */
    public function listTombstones(?string $siteKey = null): array;

    /** @return list<array<string,mixed>> */
    public function listSearchDocuments(?string $siteKey = null, ?string $languageCode = null): array;

    /** @return list<array<string,mixed>> */
    public function listPublicMediaAssets(?string $siteKey = null): array;

    /** @return list<array<string,mixed>> */
    public function listMenus(?string $siteKey = null, ?string $languageCode = null): array;

    /** @return list<array<string,mixed>> */
    public function listSiteLanguageSettings(?string $siteKey = null): array;
}
