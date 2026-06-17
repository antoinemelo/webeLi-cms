<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Core\ErrorCode;
use App\Core\Request;
use App\Core\Response;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;
use App\Repository\TaxonomyRepository;
use App\Security\Authorization;

final class TaxonomyApiController
{
    public function __construct(
        private readonly Request $request,
        private readonly SiteRepository $sites,
        private readonly TaxonomyRepository $taxonomies,
        private readonly AuthRepository $auth,
        private readonly Authorization $authorization,
    ) {}

    public function index(): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->authorization->require('taxonomy.read', (int) $site['id']);
        return Response::success($this->taxonomies->listTaxonomies((int) $site['id']), 'admin.taxonomies.index.v1', $this->meta($site));
    }

    public function store(): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->authorization->require('taxonomy.manage', (int) $site['id']);
        try {
            return Response::success($this->taxonomies->createTaxonomy((int) $site['id'], $this->payload()), 'admin.taxonomies.write.v1', $this->meta($site));
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['taxonomy' => [$e->getMessage()]], 'Taxonomie invalide.');
        }
    }

    public function update(string $key): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->authorization->require('taxonomy.manage', (int) $site['id']);
        try {
            $taxonomy = $this->taxonomies->updateTaxonomy((int) $site['id'], $key, $this->payload());
            if (!$taxonomy) {
                return $this->notFound($key, $site);
            }
            return Response::success($taxonomy, 'admin.taxonomies.write.v1', $this->meta($site));
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['taxonomy' => [$e->getMessage()]], 'Taxonomie invalide.');
        }
    }

    public function destroy(string $key): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->authorization->require('taxonomy.manage', (int) $site['id']);
        if (!$this->taxonomies->deleteTaxonomy((int) $site['id'], $key)) {
            return $this->notFound($key, $site);
        }
        return Response::success(['deleted' => true, 'taxonomy_key' => $key], 'admin.taxonomies.delete.v1', $this->meta($site));
    }

    public function terms(): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->authorization->require('taxonomy.read', (int) $site['id']);
        $lang = $this->language($site);
        $taxonomy = trim((string) ($this->request->query['taxonomy'] ?? ''));
        $terms = $this->taxonomies->listTermsForSite((int) $site['id'], $lang);
        if ($taxonomy !== '') {
            $terms = array_values(array_filter($terms, static fn(array $term): bool => (string) ($term['taxonomy_key'] ?? '') === $taxonomy));
        }
        return Response::success($terms, 'admin.taxonomies.terms.v1', $this->meta($site, $lang));
    }

    public function storeTerm(string $key): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->authorization->require('taxonomy.manage', (int) $site['id']);
        $lang = $this->language($site);
        try {
            $term = $this->taxonomies->createTerm((int) $site['id'], $key, $lang, $this->payload());
            if (!$term) {
                return $this->notFound($key, $site);
            }
            return Response::success($term, 'admin.taxonomies.terms.write.v1', $this->meta($site, $lang));
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['term' => [$e->getMessage()]], 'Terme invalide.');
        }
    }

    public function updateTerm(string $key, int $id): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->authorization->require('taxonomy.manage', (int) $site['id']);
        $lang = $this->language($site);
        try {
            $term = $this->taxonomies->updateTerm((int) $site['id'], $key, $id, $lang, $this->payload());
            if (!$term) {
                return Response::error(ErrorCode::TAXONOMY_TERM_NOT_FOUND, ErrorCode::message(ErrorCode::TAXONOMY_TERM_NOT_FOUND), 404, ['taxonomy_key' => $key, 'term_id' => $id]);
            }
            return Response::success($term, 'admin.taxonomies.terms.write.v1', $this->meta($site, $lang));
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['term' => [$e->getMessage()]], 'Terme invalide.');
        }
    }

    public function destroyTerm(string $key, int $id): Response
    {
        $this->auth->requireAuth();
        $site = $this->site();
        $this->authorization->require('taxonomy.manage', (int) $site['id']);
        if (!$this->taxonomies->deleteTerm((int) $site['id'], $key, $id)) {
            return Response::error(ErrorCode::TAXONOMY_TERM_NOT_FOUND, ErrorCode::message(ErrorCode::TAXONOMY_TERM_NOT_FOUND), 404, ['taxonomy_key' => $key, 'term_id' => $id]);
        }
        return Response::success(['deleted' => true, 'taxonomy_key' => $key, 'term_id' => $id], 'admin.taxonomies.terms.delete.v1', $this->meta($site));
    }

    private function site(): array
    {
        $payload = in_array($this->request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? $this->payload() : [];
        $siteId = isset($payload['site_id']) ? (int) $payload['site_id'] : (isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null);
        return AdminApiContract::siteContext($this->request, $this->sites, $siteId, $this->auth);
    }

    private function language(array $site): string
    {
        return (string) ($this->request->query['lang'] ?? $site['default_language_code'] ?? 'fr');
    }

    private function payload(): array
    {
        $json = $this->request->json();
        $data = $json['data'] ?? $json;
        return is_array($data) ? $data : [];
    }

    private function meta(array $site, ?string $language = null): array
    {
        return ['site_id' => (int) $site['id'], 'language_code' => $language ?? (string) ($site['default_language_code'] ?? 'fr')];
    }

    private function notFound(string $key, array $site): Response
    {
        return Response::error(ErrorCode::TAXONOMY_NOT_FOUND, ErrorCode::message(ErrorCode::TAXONOMY_NOT_FOUND), 404, ['taxonomy_key' => $key, 'site_id' => (int) $site['id']]);
    }
}
