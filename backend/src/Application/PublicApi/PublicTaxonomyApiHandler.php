<?php

declare(strict_types=1);

namespace App\Application\PublicApi;

use App\Core\Request;
use App\Core\Response;
use App\Repository\SiteRepository;
use App\Repository\TaxonomyRepository;

final class PublicTaxonomyApiHandler
{
    public function __construct(
        private readonly Request $request,
        private readonly TaxonomyRepository $taxonomies,
        private readonly SiteRepository $sites,
    ) {}

    public function index(string $taxonomy): Response
    {
        $site = $this->sites->resolveCurrentSite($this->request->server['HTTP_HOST'] ?? '');
        $siteId = (int) $site['id'];
        $languageCode = (string) ($this->request->query['lang'] ?? $site['default_language_code'] ?? 'fr');
        $taxonomyKey = trim($taxonomy);

        $taxonomyRow = null;
        foreach ($this->taxonomies->listTaxonomies($siteId) as $row) {
            if ((string) ($row['taxonomy_key'] ?? '') === $taxonomyKey) {
                $taxonomyRow = $row;
                break;
            }
        }

        if (!$taxonomyRow) {
            return Response::error('PUBLIC_TAXONOMY_NOT_FOUND', 'La taxonomie demandée est introuvable.', 404, [
                'taxonomy_key' => $taxonomyKey,
                'site_id' => $siteId,
                'language_code' => $languageCode,
            ]);
        }

        $terms = array_values(array_filter(
            $this->taxonomies->listTermsForSite($siteId, $languageCode),
            static fn (array $term): bool => (string) ($term['taxonomy_key'] ?? '') === $taxonomyKey,
        ));

        return Response::success([
            'taxonomy' => $taxonomyRow,
            'terms' => $terms,
        ], 'public.taxonomies.index.v1', [
            'site_id' => $siteId,
            'language_code' => $languageCode,
            'taxonomy_key' => $taxonomyKey,
        ]);
    }
}
