<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sql;

use App\Application\Publication\PublishedProjection;
use App\Application\Publication\PublishedProjectionStore;
use App\Core\ApiException;
use App\Core\Database;
use App\Core\RuntimeCachePurger;
use App\Core\ErrorCode;
use App\Application\Content\BlockDocumentNormalizer;

final class SqlPublishedProjectionStore implements PublishedProjectionStore
{
    public function __construct(private readonly Database $db) {}

    public function listRoutesForEntry(int $entryId): array
    {
        return $this->db->all("SELECT * FROM routes WHERE resource_type = 'content_entry' AND resource_id = :entry_id", ['entry_id' => $entryId]);
    }

    public function pathIsAvailable(int $siteId, string $languageCode, string $path, string $resourceType, int $resourceId): bool
    {
        $row = $this->db->one('SELECT id FROM routes WHERE site_id = :site_id AND language_code = :language_code AND full_path = :path AND NOT (resource_type = :resource_type AND resource_id = :resource_id) LIMIT 1', [
            'site_id' => $siteId,
            'language_code' => $languageCode,
            'path' => $path,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
        ]);
        return $row === null;
    }

    public function replaceCriticalProjection(PublishedProjection $projection, string $fallbackTitle = ''): void
    {
        $this->db->transaction(function () use ($projection, $fallbackTitle): void {
            if (!$this->pathIsAvailable($projection->siteId, $projection->languageCode, $projection->path, $projection->resourceType, $projection->resourceId)) {
                throw new ApiException(ErrorCode::ROUTE_CONFLICT, ErrorCode::message(ErrorCode::ROUTE_CONFLICT), 409, ['site_id' => $projection->siteId, 'language_code' => $projection->languageCode, 'path' => $projection->path]);
            }

            $now = now_utc();
            $this->assertSourceRevisionIsPublished($projection);
            $this->releasePublicPathReservations($projection->siteId, $projection->languageCode, $projection->path, $now);

            // All critical public projections for this language are replaced as one unit.
            // The snapshot is written last, so a failure can never leave a snapshot
            // claiming a public set that routes/SEO/search did not finish producing.
            foreach (['routes', 'seo_metadata', 'search_documents', 'seo_audit_issues', 'public_content_snapshots'] as $table) {
                $this->db->run("DELETE FROM {$table} WHERE resource_type = :resource_type AND resource_id = :resource_id AND language_code = :language_code", [
                    'resource_type' => $projection->resourceType,
                    'resource_id' => $projection->resourceId,
                    'language_code' => $projection->languageCode,
                ]);
            }

            $this->db->run('INSERT INTO routes(site_id, language_code, resource_type, resource_id, route_type, slug, full_path, is_primary, is_canonical, status, source_published_revision_id, source_revision_checksum_sha256, created_at, updated_at) VALUES(:site_id, :language_code, :resource_type, :resource_id, :route_type, :slug, :full_path, 1, 1, :status, :source_published_revision_id, :source_revision_checksum_sha256, :created_at, :updated_at)', [
                'site_id' => $projection->siteId,
                'language_code' => $projection->languageCode,
                'resource_type' => $projection->resourceType,
                'resource_id' => $projection->resourceId,
                'route_type' => 'content',
                'slug' => $projection->slug(),
                'full_path' => $projection->path,
                'status' => 'active',
                'source_published_revision_id' => $projection->sourcePublishedRevisionId,
                'source_revision_checksum_sha256' => $projection->sourceRevisionChecksumSha256,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->db->run('INSERT INTO seo_metadata(site_id, resource_type, resource_id, language_code, meta_title, meta_description, meta_robots, canonical_url, og_title, og_description, og_image_media_id, twitter_title, twitter_description, twitter_image_media_id, hreflang_code, json_ld, seo_score, source_published_revision_id, source_revision_checksum_sha256, updated_at) VALUES(:site_id, :resource_type, :resource_id, :language_code, :meta_title, :meta_description, :meta_robots, :canonical_url, :og_title, :og_description, :og_image_media_id, :twitter_title, :twitter_description, :twitter_image_media_id, :hreflang_code, :json_ld, :seo_score, :source_published_revision_id, :source_revision_checksum_sha256, :updated_at)', [
                'site_id' => $projection->siteId,
                'resource_type' => $projection->resourceType,
                'resource_id' => $projection->resourceId,
                'language_code' => $projection->languageCode,
                'meta_title' => (string) $projection->seo->metaTitle,
                'meta_description' => (string) $projection->seo->metaDescription,
                'meta_robots' => (string) $projection->seo->metaRobots,
                'canonical_url' => (string) ($projection->seo->canonicalUrl ?: $projection->path),
                'og_title' => (string) ($projection->seo->ogTitle ?? $projection->seo->metaTitle),
                'og_description' => (string) ($projection->seo->ogDescription ?? $projection->seo->metaDescription),
                'og_image_media_id' => $projection->seo->ogImageMediaId,
                'twitter_title' => (string) ($projection->seo->twitterTitle ?? $projection->seo->ogTitle ?? $projection->seo->metaTitle),
                'twitter_description' => (string) ($projection->seo->twitterDescription ?? $projection->seo->ogDescription ?? $projection->seo->metaDescription),
                'twitter_image_media_id' => $projection->seo->twitterImageMediaId ?? $projection->seo->ogImageMediaId,
                'hreflang_code' => $projection->seo->hreflangCode ?? $projection->languageCode,
                'json_ld' => $projection->seo->jsonLd,
                'seo_score' => $projection->seo->computedScore(),
                'source_published_revision_id' => $projection->sourcePublishedRevisionId,
                'source_revision_checksum_sha256' => $projection->sourceRevisionChecksumSha256,
                'updated_at' => $now,
            ]);

            $this->insertSeoAuditIssues($projection, $now);

            // Le contrat de publication exige une ligne search_documents pour
            // chaque publication, meme si la page est noindex. L'exclusion des
            // resultats publics reste une decision de lecture basee sur
            // seo_metadata.meta_robots, pas une projection critique manquante.
            $this->db->run('INSERT INTO search_documents(site_id, resource_type, resource_id, language_code, path, title, summary, search_text, source_published_revision_id, source_revision_checksum_sha256, updated_at) VALUES(:site_id, :resource_type, :resource_id, :language_code, :path, :title, :summary, :search_text, :source_published_revision_id, :source_revision_checksum_sha256, :updated_at)', [
                'site_id' => $projection->siteId,
                'resource_type' => $projection->resourceType,
                'resource_id' => $projection->resourceId,
                'language_code' => $projection->languageCode,
                'path' => $projection->path,
                'title' => $projection->title() !== '' ? $projection->title() : $fallbackTitle,
                'summary' => (string) $projection->seo->metaDescription,
                'search_text' => $this->searchText($projection),
                'source_published_revision_id' => $projection->sourcePublishedRevisionId,
                'source_revision_checksum_sha256' => $projection->sourceRevisionChecksumSha256,
                'updated_at' => $now,
            ]);

            $this->db->run('INSERT INTO public_content_snapshots(site_id, language_code, resource_type, resource_id, route_path, title, slug, blocks_json, block_count, document_json, seo_json, source_published_revision_id, source_revision_checksum_sha256, published_at, projected_at) VALUES(:site_id, :language_code, :resource_type, :resource_id, :route_path, :title, :slug, :blocks_json, :block_count, :document_json, :seo_json, :source_published_revision_id, :source_revision_checksum_sha256, :published_at, :projected_at)', [
                'site_id' => $projection->siteId,
                'language_code' => $projection->languageCode,
                'resource_type' => $projection->resourceType,
                'resource_id' => $projection->resourceId,
                'route_path' => $projection->path,
                'title' => $projection->title() !== '' ? $projection->title() : $fallbackTitle,
                'slug' => $projection->slug(),
                'blocks_json' => $this->json(is_array($projection->document['blocks'] ?? null) ? $projection->document['blocks'] : []),
                'block_count' => count(is_array($projection->document['blocks'] ?? null) ? $projection->document['blocks'] : []),
                'document_json' => $this->json($projection->document),
                'seo_json' => $this->json($projection->seo->toArray()),
                'source_published_revision_id' => $projection->sourcePublishedRevisionId,
                'source_revision_checksum_sha256' => $projection->sourceRevisionChecksumSha256,
                'published_at' => $this->publishedAt($projection->sourcePublishedRevisionId),
                'projected_at' => $now,
            ]);
        });
        $this->purgeTwigCache();
    }



    private function insertSeoAuditIssues(PublishedProjection $projection, string $now): void
    {
        $issues = [];
        $title = trim((string) $projection->seo->metaTitle);
        $description = trim((string) $projection->seo->metaDescription);
        $robots = strtolower((string) $projection->seo->metaRobots);
        $canonical = trim((string) ($projection->seo->canonicalUrl ?: $projection->path));

        if ($title === '') { $issues[] = ['seo.title_missing', 'high', 'Titre SEO manquant : ajoutez un titre compréhensible et unique.']; }
        elseif (mb_strlen($title) < 25) { $issues[] = ['seo.title_short', 'medium', 'Titre SEO court : visez environ 30 à 60 caractères.']; }
        elseif (mb_strlen($title) > 60) { $issues[] = ['seo.title_long', 'low', 'Titre SEO long : il risque d’être tronqué dans les résultats.']; }

        if ($description === '') { $issues[] = ['seo.description_missing', 'high', 'Meta description manquante : ajoutez une synthèse utile pour l’éditeur, les moteurs et l’API.']; }
        elseif (mb_strlen($description) < 80) { $issues[] = ['seo.description_short', 'medium', 'Meta description courte : ajoutez le bénéfice ou le sujet principal de la page.']; }
        elseif (mb_strlen($description) > 170) { $issues[] = ['seo.description_long', 'low', 'Meta description longue : elle peut être tronquée.']; }

        if ($canonical === '' || ($canonical !== '/' && (!str_starts_with($canonical, '/') || str_contains($canonical, '//')))) {
            $issues[] = ['seo.canonical_invalid', 'high', 'Canonical invalide : la projection doit publier un chemin canonique local propre.'];
        }
        if (str_contains($robots, 'noindex')) {
            $issues[] = ['seo.robots_noindex', 'medium', 'La page est publiée en noindex : elle reste accessible mais exclue du sitemap et de la recherche publique.'];
        }
        if ($projection->seo->jsonLd !== null && trim($projection->seo->jsonLd) !== '') {
            json_decode((string) $projection->seo->jsonLd, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $issues[] = ['seo.json_ld_invalid', 'high', 'JSON-LD invalide : corrigez les données structurées ou supprimez le champ.'];
            }
        }

        foreach ($issues as [$code, $severity, $message]) {
            $this->db->run('INSERT INTO seo_audit_issues(site_id, resource_type, resource_id, language_code, issue_code, severity, message, is_resolved, detected_at) VALUES(:site_id, :resource_type, :resource_id, :language_code, :issue_code, :severity, :message, 0, :detected_at)', [
                'site_id' => $projection->siteId,
                'resource_type' => $projection->resourceType,
                'resource_id' => $projection->resourceId,
                'language_code' => $projection->languageCode,
                'issue_code' => $code,
                'severity' => $severity,
                'message' => $message,
                'detected_at' => $now,
            ]);
        }
    }

    /** @return list<string> */
    public function verifyCriticalProjection(PublishedProjection $projection): array
    {
        $errors = [];
        $params = [
            'site_id' => $projection->siteId,
            'language_code' => $projection->languageCode,
            'resource_type' => $projection->resourceType,
            'resource_id' => $projection->resourceId,
            'path' => $projection->path,
            'revision_id' => $projection->sourcePublishedRevisionId,
            'checksum' => $projection->sourceRevisionChecksumSha256,
        ];

        $published = $this->db->one(
            "SELECT cep.id
             FROM content_entry_publications cep
             JOIN revisions r ON r.id = cep.published_revision_id
             WHERE cep.site_id = :site_id
               AND cep.entry_id = :resource_id
               AND cep.language_code = :language_code
               AND cep.workflow_status = 'published'
               AND cep.published_revision_id = :revision_id
               AND r.resource_type = 'content_entry'
               AND r.resource_id = cep.entry_id
               AND r.language_code = cep.language_code
               AND r.workflow_status = 'published'
               AND r.checksum_sha256 = :checksum
             LIMIT 1",
            $this->only($params, ['site_id', 'language_code', 'resource_id', 'revision_id', 'checksum'])
        );
        if (!$published) {
            $errors[] = 'content_entry_publications ne pointe pas vers la révision publiée/checksum attendue.';
        }

        $checks = [
            'routes' => "SELECT COUNT(*) AS c FROM routes WHERE site_id = :site_id AND language_code = :language_code AND resource_type = :resource_type AND resource_id = :resource_id AND full_path = :path AND status = 'active' AND is_primary = 1 AND is_canonical = 1 AND source_published_revision_id = :revision_id AND source_revision_checksum_sha256 = :checksum AND :path = :path",
            'seo_metadata' => "SELECT COUNT(*) AS c FROM seo_metadata WHERE site_id = :site_id AND language_code = :language_code AND resource_type = :resource_type AND resource_id = :resource_id AND source_published_revision_id = :revision_id AND source_revision_checksum_sha256 = :checksum AND :path = :path",
            'search_documents' => "SELECT COUNT(*) AS c FROM search_documents WHERE site_id = :site_id AND language_code = :language_code AND resource_type = :resource_type AND resource_id = :resource_id AND path = :path AND source_published_revision_id = :revision_id AND source_revision_checksum_sha256 = :checksum AND :path = :path",
            'public_content_snapshots' => "SELECT COUNT(*) AS c FROM public_content_snapshots WHERE site_id = :site_id AND language_code = :language_code AND resource_type = :resource_type AND resource_id = :resource_id AND route_path = :path AND source_published_revision_id = :revision_id AND source_revision_checksum_sha256 = :checksum AND :path = :path",
        ];
        foreach ($checks as $table => $sql) {
            $row = $this->db->one($sql, $params);
            $count = (int) ($row['c'] ?? 0);
            if ($count !== 1) {
                $errors[] = sprintf('%s: attendu 1 ligne critique cohérente, obtenu %d.', $table, $count);
            }
        }

        $activeShadow = $this->db->one(
            "SELECT
                (SELECT COUNT(*) FROM redirects WHERE site_id = :site_id AND language_code = :language_code AND old_path = :path AND is_active = 1) AS redirects_count,
                (SELECT COUNT(*) FROM tombstones WHERE site_id = :site_id AND language_code = :language_code AND old_path = :path AND is_active = 1) AS tombstones_count",
            $this->only($params, ['site_id', 'language_code', 'path'])
        );
        if ((int) ($activeShadow['redirects_count'] ?? 0) > 0) {
            $errors[] = 'un redirect actif masque le chemin publié.';
        }
        if ((int) ($activeShadow['tombstones_count'] ?? 0) > 0) {
            $errors[] = 'un tombstone actif masque le chemin publié.';
        }

        return $errors;
    }

    public function removeCriticalProjection(string $resourceType, int $resourceId, ?string $languageCode = null): void
    {
        $params = ['resource_type' => $resourceType, 'resource_id' => $resourceId];
        $langSql = '';
        if ($languageCode !== null) {
            $langSql = ' AND language_code = :language_code';
            $params['language_code'] = $languageCode;
        }
        foreach (['routes', 'seo_metadata', 'search_documents', 'seo_audit_issues', 'public_content_snapshots'] as $table) {
            $this->db->run("DELETE FROM {$table} WHERE resource_type = :resource_type AND resource_id = :resource_id{$langSql}", $params);
        }
        $this->purgeTwigCache();
    }

    public function clearEntryProjectionArtifacts(string $resourceType, int $resourceId): void
    {
        $params = ['resource_type' => $resourceType, 'resource_id' => $resourceId];
        foreach (['routes', 'seo_metadata', 'search_documents', 'seo_audit_issues', 'public_content_snapshots', 'redirects', 'tombstones'] as $table) {
            $this->db->run("DELETE FROM {$table} WHERE resource_type = :resource_type AND resource_id = :resource_id", $params);
        }
        $this->purgeTwigCache();
    }

    public function createTombstonesForRemovedRoutes(int $siteId, string $resourceType, int $resourceId, ?string $languageCode, array $oldRoutes, ?string $reason = null): void
    {
        $now = now_utc();
        foreach ($oldRoutes as $route) {
            if ($languageCode !== null && (string) ($route['language_code'] ?? '') !== $languageCode) { continue; }
            $path = (string) ($route['full_path'] ?? '');
            if ($path === '') { continue; }
            $this->deactivateRedirectsForPath($siteId, (string) ($route['language_code'] ?? ''), $path, $now);
            $this->db->run('INSERT OR REPLACE INTO tombstones(site_id, language_code, old_path, resource_type, resource_id, replacement_path, gone_reason, gone_at, updated_at, is_active) VALUES(:site_id, :language_code, :old_path, :resource_type, :resource_id, NULL, :gone_reason, :gone_at, :updated_at, 1)', [
                'site_id' => $siteId,
                'language_code' => (string) ($route['language_code'] ?? ''),
                'old_path' => $path,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'gone_reason' => $reason ?? 'unpublished',
                'gone_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function createRedirectsForPathChanges(array $oldRoutes, array $pathsByLanguage): void
    {
        $now = now_utc();
        foreach ($oldRoutes as $route) {
            $language = (string) ($route['language_code'] ?? '');
            $oldPath = (string) ($route['full_path'] ?? '');
            $newPath = $pathsByLanguage[$language] ?? null;
            if ($oldPath === '' || $newPath === null || $oldPath === $newPath) { continue; }
            $this->deactivateTombstonesForPath((int) ($route['site_id'] ?? 0), $language, $oldPath, $now);
            $this->db->run('INSERT OR REPLACE INTO redirects(site_id, language_code, old_path, new_path, http_code, redirect_reason, resource_type, resource_id, is_active, created_at, updated_at) VALUES(:site_id, :language_code, :old_path, :new_path, 301, :reason, :resource_type, :resource_id, 1, :created_at, :updated_at)', [
                'site_id' => (int) ($route['site_id'] ?? 0),
                'language_code' => $language,
                'old_path' => $oldPath,
                'new_path' => $newPath,
                'reason' => 'publish_path_changed',
                'resource_type' => (string) ($route['resource_type'] ?? 'content_entry'),
                'resource_id' => (int) ($route['resource_id'] ?? 0),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }


    /** @param array<string,mixed> $params @param list<string> $keys @return array<string,mixed> */
    private function only(array $params, array $keys): array
    {
        $selected = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $params)) {
                $selected[$key] = $params[$key];
            }
        }
        return $selected;
    }

    private function purgeTwigCache(): void
    {
        RuntimeCachePurger::purgeTwigCache();
    }

    private function assertSourceRevisionIsPublished(PublishedProjection $projection): void
    {
        $row = $this->db->one(
            "SELECT r.id
             FROM revisions r
             JOIN content_entries ce ON ce.id = r.resource_id
             WHERE r.id = :revision_id
               AND r.resource_type = 'content_entry'
               AND r.resource_id = :resource_id
               AND r.language_code = :language_code
               AND r.workflow_status = 'published'
               AND r.checksum_sha256 = :checksum
               AND ce.site_id = :site_id
             LIMIT 1",
            [
                'revision_id' => $projection->sourcePublishedRevisionId,
                'resource_id' => $projection->resourceId,
                'language_code' => $projection->languageCode,
                'checksum' => $projection->sourceRevisionChecksumSha256,
                'site_id' => $projection->siteId,
            ]
        );
        if (!$row) {
            throw new \RuntimeException('Projection publique refusée : la source n’est pas une révision publiée cohérente.');
        }
    }

    private function publishedAt(int $revisionId): ?string
    {
        $row = $this->db->one('SELECT published_at FROM revisions WHERE id = :id LIMIT 1', ['id' => $revisionId]);
        return $row ? (string) ($row['published_at'] ?? '') : null;
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function releasePublicPathReservations(int $siteId, string $languageCode, string $path, string $now): void
    {
        $this->deactivateRedirectsForPath($siteId, $languageCode, $path, $now);
        $this->deactivateTombstonesForPath($siteId, $languageCode, $path, $now);
    }

    private function deactivateRedirectsForPath(int $siteId, string $languageCode, string $path, string $now): void
    {
        $this->db->run('UPDATE redirects
            SET is_active = 0, updated_at = :updated_at
            WHERE site_id = :site_id
              AND language_code = :language_code
              AND old_path = :old_path
              AND is_active = 1', [
            'updated_at' => $now,
            'site_id' => $siteId,
            'language_code' => $languageCode,
            'old_path' => $path,
        ]);
    }

    private function deactivateTombstonesForPath(int $siteId, string $languageCode, string $path, string $now): void
    {
        $this->db->run('UPDATE tombstones
            SET is_active = 0, updated_at = :updated_at
            WHERE site_id = :site_id
              AND language_code = :language_code
              AND old_path = :old_path
              AND is_active = 1', [
            'updated_at' => $now,
            'site_id' => $siteId,
            'language_code' => $languageCode,
            'old_path' => $path,
        ]);
    }

    private function searchText(PublishedProjection $projection): string
    {
        $parts = [
            $projection->title(),
            (string) $projection->seo->metaTitle,
            (string) $projection->seo->metaDescription,
            (new BlockDocumentNormalizer())->plainText($projection->document),
            $this->taxonomySearchText($projection),
        ];
        $text = implode(' ', array_filter($parts, static fn(string $part): bool => trim($part) !== ''));
        return trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));
    }

    private function taxonomySearchText(PublishedProjection $projection): string
    {
        if ($projection->resourceType !== 'content_entry') {
            return '';
        }
        $rows = $this->db->all(
            "SELECT tx.taxonomy_key, tx.name AS taxonomy_name, tt.term_key, ttl.name AS term_name, ttl.slug AS term_slug
             FROM content_entry_taxonomy_terms cett
             JOIN taxonomy_terms tt ON tt.id = cett.term_id AND tt.is_active = 1
             JOIN taxonomies tx ON tx.id = tt.taxonomy_id AND tx.site_id = :site_id
             LEFT JOIN taxonomy_term_localizations ttl ON ttl.term_id = tt.id AND ttl.language_code = :language_code
             WHERE cett.entry_id = :entry_id
             ORDER BY tx.sort_order, tt.sort_order, tt.term_key",
            [
                'site_id' => $projection->siteId,
                'language_code' => $projection->languageCode,
                'entry_id' => $projection->resourceId,
            ]
        );
        $terms = [];
        foreach ($rows as $row) {
            foreach (['taxonomy_key', 'taxonomy_name', 'term_key', 'term_name', 'term_slug'] as $key) {
                $value = trim((string) ($row[$key] ?? ''));
                if ($value !== '') {
                    $terms[$value] = $value;
                }
            }
        }
        return implode(' ', array_values($terms));
    }
}

