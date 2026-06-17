<?php

declare(strict_types=1);

namespace App\Application\PublicApi;

use App\Core\Request;
use App\Core\Response;
use App\Application\Search\PublicSearchReadRepository;
use App\Repository\SiteRepository;

final class PublicSearchApiHandler
{
    public function __construct(
        private readonly Request $request,
        private readonly PublicSearchReadRepository $content,
        private readonly SiteRepository $sites,
    ) {}

    public function index(): Response
    {
        $site = $this->sites->resolveCurrentSite($this->request->server['HTTP_HOST'] ?? '');
        $languageCode = (string) ($this->request->query['lang'] ?? $site['default_language_code'] ?? 'fr');
        $q = trim((string) $this->request->input('q', ''));
        $filters = [
            'type' => trim((string) ($this->request->query['type'] ?? '')),
            'taxonomy' => trim((string) ($this->request->query['taxonomy'] ?? '')),
            'term' => trim((string) ($this->request->query['term'] ?? '')),
            'limit' => (int) ($this->request->query['limit'] ?? 20),
        ];
        return Response::success(
            $q === '' ? [] : $this->content->searchDocuments($q, $languageCode, (int) $site['id'], $filters),
            'public.search.index.v1',
            ['site_id' => (int) $site['id'], 'language_code' => $languageCode, 'query' => $q, 'filters' => $filters]
        );
    }
}
