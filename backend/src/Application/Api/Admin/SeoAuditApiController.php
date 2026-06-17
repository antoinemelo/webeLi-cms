<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Application\Seo\RunSeoAudit;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;

final class SeoAuditApiController
{
    public function __construct(
        private readonly Request $request,
        private readonly Database $db,
        private readonly SiteRepository $sites,
        private readonly AuthRepository $auth,
        private readonly Authorization $authorization,
        private readonly RunSeoAudit $seoAudit,
    ) {}

    public function index(): Response
    {
        $this->auth->requireAuth();
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null, $this->auth);
        $this->authorization->require('seo.read', (int) $site['id']);
        $languageCode = AdminApiContract::language($this->request, $this->sites, $site);

        $includeResolved = $this->boolQuery('include_resolved', false);
        $includeTechnical = $this->boolQuery('include_technical', true);

        $issues = $this->issueContracts(
            $this->seoAudit->listIssues((int) $site['id'], $languageCode, !$includeResolved),
            $languageCode
        );
        $technicalChecks = $includeTechnical ? $this->technicalChecks((int) $site['id'], $languageCode) : [];
        $pages = $this->pageScores((int) $site['id'], $languageCode, $issues, $technicalChecks);

        return Response::success([
            'filters' => [
                'site_id' => (int) $site['id'],
                'language_code' => $languageCode,
                'include_resolved' => $includeResolved,
                'include_technical' => $includeTechnical,
            ],
            'summary' => $this->summary($issues, $technicalChecks, $pages),
            'pages' => $pages,
            'issues' => $issues,
            'technical_checks' => $technicalChecks,
            'guidance' => $this->guidance(),
        ], 'admin.seo.audit.v1', AdminApiContract::meta($site, $languageCode));
    }

    private function boolQuery(string $key, bool $default): bool
    {
        if (!array_key_exists($key, $this->request->query)) {
            return $default;
        }
        $value = strtolower((string) $this->request->query[$key]);
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function issueContracts(array $rows, string $languageCode): array
    {
        return array_map(function (array $row) use ($languageCode): array {
            $resourceType = (string) ($row['resource_type'] ?? '');
            $resourceId = (int) ($row['resource_id'] ?? 0);
            $path = (string) ($row['public_path'] ?? '');
            $contentTypeKey = (string) ($row['content_type_key'] ?? 'page');
            return [
                'id' => (int) ($row['id'] ?? 0),
                'site_id' => (int) ($row['site_id'] ?? 0),
                'site_key' => (string) ($row['site_key'] ?? ''),
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'language_code' => (string) ($row['language_code'] ?? $languageCode),
                'issue_code' => (string) ($row['issue_code'] ?? ''),
                'severity' => (string) ($row['severity'] ?? 'low'),
                'message' => (string) ($row['message'] ?? ''),
                'is_resolved' => (bool) ((int) ($row['is_resolved'] ?? 0)),
                'detected_at' => (string) ($row['detected_at'] ?? ''),
                'resource' => [
                    'title' => (string) ($row['resource_title'] ?? ''),
                    'entry_key' => (string) ($row['entry_key'] ?? ''),
                    'content_type_key' => $contentTypeKey,
                    'public_path' => $path,
                    'admin_edit_path' => $resourceType === 'content_entry' && $resourceId > 0 ? '/admin/app/contents/' . $contentTypeKey . '/' . $resourceId : '',
                ],
            ];
        }, $rows);
    }

    /** @return list<array<string,mixed>> */
    private function technicalChecks(int $siteId, string $languageCode): array
    {
        $checks = [];

        $invalidRoutes = $this->db->all(
            "SELECT id, full_path FROM routes WHERE site_id = :site_id AND language_code = :language_code AND status = 'active' ORDER BY id",
            ['site_id' => $siteId, 'language_code' => $languageCode]
        );
        $badRoutes = array_values(array_filter($invalidRoutes, fn(array $row): bool => !$this->validPublicPath((string) ($row['full_path'] ?? ''))));
        $checks[] = $this->check('routes.canonical_path_format', 'critical', $badRoutes === [], count($badRoutes), 'Routes actives non canoniques', $badRoutes, 'Normaliser les chemins publics: minuscules, sans espace, sans double slash, sans slash final sauf accueil.');

        $multiCanonical = $this->db->all(
            "SELECT resource_type, resource_id, COUNT(*) AS count
             FROM routes
             WHERE site_id = :site_id AND language_code = :language_code AND status = 'active' AND is_canonical = 1
             GROUP BY resource_type, resource_id
             HAVING COUNT(*) > 1",
            ['site_id' => $siteId, 'language_code' => $languageCode]
        );
        $checks[] = $this->check('routes.single_canonical_per_resource', 'critical', $multiCanonical === [], count($multiCanonical), 'Ressources avec plusieurs routes canoniques actives', $multiCanonical, 'Conserver une seule URL canonique active par ressource et transformer les anciennes URL en redirections 301.');

        $missingSeo = $this->db->all(
            "SELECT r.id, r.resource_type, r.resource_id, r.full_path
             FROM routes r
             LEFT JOIN seo_metadata sm ON sm.site_id = r.site_id AND sm.resource_type = r.resource_type AND sm.resource_id = r.resource_id AND sm.language_code = r.language_code
             WHERE r.site_id = :site_id AND r.language_code = :language_code AND r.status = 'active' AND r.is_canonical = 1
               AND (sm.id IS NULL OR COALESCE(sm.meta_title, '') = '' OR COALESCE(sm.meta_description, '') = '')
             ORDER BY r.id",
            ['site_id' => $siteId, 'language_code' => $languageCode]
        );
        $checks[] = $this->check('seo.metadata_complete_for_canonical_routes', 'high', $missingSeo === [], count($missingSeo), 'Routes canoniques publiées sans SEO complet', $missingSeo, 'Renseigner un meta title unique et une meta description utile pour chaque page publiée.');

        $invalidJsonLd = [];
        foreach ($this->db->all("SELECT id, resource_type, resource_id, json_ld FROM seo_metadata WHERE site_id = :site_id AND language_code = :language_code AND json_ld IS NOT NULL AND trim(json_ld) <> '' ORDER BY id", ['site_id' => $siteId, 'language_code' => $languageCode]) as $row) {
            json_decode((string) $row['json_ld'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $invalidJsonLd[] = ['id' => (int) $row['id'], 'resource_type' => (string) $row['resource_type'], 'resource_id' => (int) $row['resource_id'], 'error' => json_last_error_msg()];
            }
        }
        $checks[] = $this->check('seo.json_ld_valid', 'high', $invalidJsonLd === [], count($invalidJsonLd), 'JSON-LD invalide dans seo_metadata', $invalidJsonLd, 'Corriger ou supprimer le JSON-LD invalide: les moteurs et IA ignorent souvent les données structurées erronées.');

        $noindexSearchDocs = $this->db->all(
            "SELECT sd.id, sd.resource_type, sd.resource_id, sd.path
             FROM search_documents sd
             JOIN seo_metadata sm ON sm.site_id = sd.site_id AND sm.resource_type = sd.resource_type AND sm.resource_id = sd.resource_id AND sm.language_code = sd.language_code
             WHERE sd.site_id = :site_id AND sd.language_code = :language_code AND LOWER(COALESCE(sm.meta_robots, 'index,follow')) LIKE '%noindex%'",
            ['site_id' => $siteId, 'language_code' => $languageCode]
        );
        $checks[] = $this->check('search.noindex_filtered_at_runtime', 'medium', true, count($noindexSearchDocs), 'Documents noindex conservés dans la projection atomique search_documents et filtrés à la lecture', $noindexSearchDocs, 'Conserver le filtrage via seo_metadata.meta_robots dans les requêtes de recherche publiques.');

        $domainRows = $this->db->all("SELECT host, scheme, is_primary, enforce_https, canonical_host_strategy FROM site_domains WHERE site_id = :site_id AND is_active = 1 ORDER BY is_primary DESC, id", ['site_id' => $siteId]);
        $primaryDomain = $domainRows[0] ?? null;
        $weakDomainRows = array_values(array_filter($domainRows, fn(array $row): bool => (int) ($row['enforce_https'] ?? 1) !== 1 || (string) ($row['canonical_host_strategy'] ?? 'primary') === 'none'));
        $checks[] = $this->check('domains.https_and_301_canonical_host', 'high', $primaryDomain !== null && $weakDomainRows === [], count($weakDomainRows), 'Redirections 301 domaine/HTTPS à vérifier', $weakDomainRows, 'Définir un domaine primaire, forcer HTTPS et rediriger les variantes www/non-www vers le même hôte canonique.');

        $mediaSettings = $this->db->one("SELECT value_json FROM site_settings WHERE site_id = :site_id AND namespace = 'media' AND setting_key = 'defaults' LIMIT 1", ['site_id' => $siteId]);
        $mediaValues = $mediaSettings ? json_decode((string) ($mediaSettings['value_json'] ?? '{}'), true) : [];
        $mediaValues = is_array($mediaValues) ? $mediaValues : [];
        $faviconMediaId = isset($mediaValues['favicon_media_id']) ? (int) $mediaValues['favicon_media_id'] : 0;
        $siteLoc = $this->db->one("SELECT apple_touch_icon_media_id FROM site_localizations WHERE site_id = :site_id AND language_code = :language_code", ['site_id' => $siteId, 'language_code' => $languageCode]);
        $appleTouchIconMediaId = $siteLoc !== null && isset($siteLoc['apple_touch_icon_media_id']) ? (int) $siteLoc['apple_touch_icon_media_id'] : 0;
        $hasPublicSiteIcon = $faviconMediaId > 0 || $appleTouchIconMediaId > 0;
        $checks[] = $this->check('site.favicon_declared', 'medium', $hasPublicSiteIcon, $hasPublicSiteIcon ? 0 : 1, 'Favicon / icône de site non déclarée', [], 'Définir media.defaults.favicon_media_id ou site_localizations.apple_touch_icon_media_id afin de générer les balises favicon/apple-touch-icon dans le head public.');

        $languages = $this->db->all("SELECT language_code, hreflang_code FROM site_languages WHERE site_id = :site_id AND is_active = 1 ORDER BY sort_order, language_code", ['site_id' => $siteId]);
        $missingHreflang = array_values(array_filter($languages, fn(array $row): bool => trim((string) ($row['hreflang_code'] ?? '')) === ''));
        $checks[] = $this->check('i18n.hreflang_configured', 'medium', count($languages) <= 1 || $missingHreflang === [], count($missingHreflang), 'Liens alternatifs hreflang incomplets', $missingHreflang, 'Renseigner hreflang pour chaque langue active et publier les variantes localisées.');

        return $checks;
    }

    /** @param list<array<string,mixed>> $evidence */
    private function check(string $code, string $severity, bool $passed, int $count, string $message, array $evidence, string $recommendation): array
    {
        return [
            'code' => $code,
            'severity' => $severity,
            'passed' => $passed,
            'count' => $count,
            'message' => $message,
            'recommendation' => $recommendation,
            'evidence' => array_slice($evidence, 0, 20),
        ];
    }

    private function validPublicPath(string $path): bool
    {
        return $path === '/' || (str_starts_with($path, '/') && !str_contains($path, '//') && !str_contains($path, ' ') && !str_ends_with($path, '/') && $path === strtolower($path));
    }

    /** @param list<array<string,mixed>> $issues @param list<array<string,mixed>> $technicalChecks @return list<array<string,mixed>> */
    private function pageScores(int $siteId, string $languageCode, array $issues, array $technicalChecks): array
    {
        $rows = $this->db->all(
            "SELECT
                pcs.site_id,
                pcs.language_code,
                pcs.resource_type,
                pcs.resource_id,
                pcs.route_path,
                pcs.title,
                pcs.blocks_json,
                pcs.seo_json,
                pcs.block_count,
                pcs.published_at,
                ce.entry_key,
                ct.type_key AS content_type_key,
                sm.meta_title,
                sm.meta_description,
                sm.meta_robots,
                sm.canonical_url,
                sm.json_ld,
                sm.seo_score
             FROM public_content_snapshots pcs
             LEFT JOIN content_entries ce ON ce.id = pcs.resource_id AND pcs.resource_type = 'content_entry'
             LEFT JOIN content_types ct ON ct.id = ce.content_type_id
             LEFT JOIN seo_metadata sm ON sm.site_id = pcs.site_id
                AND sm.language_code = pcs.language_code
                AND sm.resource_type = pcs.resource_type
                AND sm.resource_id = pcs.resource_id
             WHERE pcs.site_id = :site_id AND pcs.language_code = :language_code AND pcs.resource_type = 'content_entry'
             ORDER BY CASE pcs.route_path WHEN '/' THEN 0 ELSE 1 END, pcs.route_path",
            ['site_id' => $siteId, 'language_code' => $languageCode]
        );

        $issueBuckets = [];
        foreach ($issues as $issue) {
            $key = (string) ($issue['resource_type'] ?? '') . ':' . (int) ($issue['resource_id'] ?? 0);
            $issueBuckets[$key][] = $issue;
        }

        $failedGlobal = array_values(array_filter($technicalChecks, fn(array $check): bool => !(bool) ($check['passed'] ?? false)));
        $pages = [];
        foreach ($rows as $row) {
            $resourceType = (string) ($row['resource_type'] ?? 'content_entry');
            $resourceId = (int) ($row['resource_id'] ?? 0);
            $key = $resourceType . ':' . $resourceId;
            $pageIssues = $issueBuckets[$key] ?? [];
            $pageChecks = $this->pageChecks($row, $siteId, $languageCode);
            $recommendations = $this->recommendations($pageChecks, $pageIssues, $failedGlobal, (string) ($row['route_path'] ?? ''));
            $scores = $this->scores($row, $pageChecks, $pageIssues, $failedGlobal);
            $status = $scores['overall'] >= 85 ? 'excellent' : ($scores['overall'] >= 70 ? 'good' : ($scores['overall'] >= 50 ? 'warning' : 'critical'));
            $path = (string) ($row['route_path'] ?? '');
            $contentTypeKey = (string) ($row['content_type_key'] ?? 'page');
            $metaTitle = trim((string) ($row['meta_title'] ?? ''));
            $metaDescription = trim((string) ($row['meta_description'] ?? ''));
            if ($metaTitle === '') { $metaTitle = (string) ($row['title'] ?? ''); }
            if ($metaDescription === '') { $metaDescription = $this->textExcerpt($row); }

            $pages[] = [
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'language_code' => (string) ($row['language_code'] ?? $languageCode),
                'title' => (string) ($row['title'] ?? ''),
                'entry_key' => (string) ($row['entry_key'] ?? ''),
                'content_type_key' => $contentTypeKey,
                'public_path' => $path,
                'admin_edit_path' => '/admin/app/contents/' . $contentTypeKey . '/' . $resourceId,
                'published_at' => (string) ($row['published_at'] ?? ''),
                'scores' => $scores,
                'status' => $status,
                'issue_count' => count($pageIssues) + count(array_filter($pageChecks, fn(array $check): bool => !(bool) ($check['passed'] ?? false))),
                'checks' => $pageChecks,
                'recommendations' => $recommendations,
                'serp_preview' => [
                    'title' => $metaTitle !== '' ? $metaTitle : 'Titre SEO manquant',
                    'url' => $this->absoluteUrl($siteId, $path),
                    'description' => $metaDescription !== '' ? $metaDescription : 'Meta description manquante.',
                ],
                'ai_preview' => [
                    'answer_title' => $metaTitle !== '' ? $metaTitle : (string) ($row['title'] ?? 'Page sans titre SEO'),
                    'summary' => $this->aiSummary($row),
                    'signals' => $this->aiSignals($row, $pageChecks),
                ],
            ];
        }

        return $pages;
    }

    /** @return list<array<string,mixed>> */
    private function pageChecks(array $row, int $siteId, string $languageCode): array
    {
        $title = trim((string) ($row['meta_title'] ?? $row['title'] ?? ''));
        $description = trim((string) ($row['meta_description'] ?? ''));
        $body = $this->textExcerpt($row, 100000);
        $path = (string) ($row['route_path'] ?? '');
        $canonical = trim((string) ($row['canonical_url'] ?? ''));
        $robots = strtolower((string) ($row['meta_robots'] ?? 'index,follow'));
        $jsonLd = trim((string) ($row['json_ld'] ?? ''));
        $blocks = $this->decodeJson((string) ($row['blocks_json'] ?? '[]'));
        $wordCount = str_word_count(strip_tags($body));
        $anchorDuplicates = $this->duplicateAnchors('', $blocks);
        $expectedUrl = $this->absoluteUrl($siteId, $path);

        return [
            $this->pageCheck('meta.title.present', 'high', $title !== '', 'Meta title présent', 'Ajouter un meta title unique et descriptif.'),
            $this->pageCheck('meta.title.length', 'medium', mb_strlen($title) >= 30 && mb_strlen($title) <= 60, 'Longueur du meta title', 'Viser 30 à 60 caractères pour éviter un titre trop faible ou tronqué.', ['length' => mb_strlen($title)]),
            $this->pageCheck('meta.description.present', 'high', $description !== '', 'Meta description présente', 'Rédiger une description qui explique clairement la valeur de la page.'),
            $this->pageCheck('meta.description.length', 'medium', mb_strlen($description) >= 110 && mb_strlen($description) <= 160, 'Longueur de la meta description', 'Viser 110 à 160 caractères, avec bénéfice et contexte.', ['length' => mb_strlen($description)]),
            $this->pageCheck('canonical.self', 'high', $canonical === '' || $canonical === $path || $canonical === $expectedUrl, 'Canonique cohérente', 'La canonique doit pointer vers cette page, sauf cas volontaire de consolidation.', ['canonical_url' => $canonical, 'expected_url' => $expectedUrl]),
            $this->pageCheck('robots.indexable', 'high', !str_contains($robots, 'noindex'), 'Page indexable', 'Retirer noindex pour les pages destinées aux moteurs et assistants IA.'),
            $this->pageCheck('content.word_count', 'medium', $wordCount >= 250, 'Volume de contenu suffisant', 'Ajouter un contenu principal plus explicatif, structuré et utile.', ['words' => $wordCount]),
            $this->pageCheck('content.blocks', 'low', (int) ($row['block_count'] ?? 0) > 0, 'Contenu éditorial présent', 'Ajouter au moins un bloc éditorial lisible.'),
            $this->pageCheck('structured_data.valid', 'medium', $jsonLd === '' || json_decode($jsonLd, true) !== null || json_last_error() === JSON_ERROR_NONE, 'Données structurées valides', 'Corriger le JSON-LD pour améliorer la compréhension machine.'),
            $this->pageCheck('internal_links.anchors_unique', 'low', $anchorDuplicates === [], 'Textes d’ancrage internes non répétitifs', 'Varier les textes d’ancrage qui pointent vers des destinations différentes.', ['duplicates' => $anchorDuplicates]),
        ];
    }

    /** @param array<string,mixed> $details */
    private function pageCheck(string $code, string $severity, bool $passed, string $label, string $recommendation, array $details = []): array
    {
        return ['code' => $code, 'severity' => $severity, 'passed' => $passed, 'label' => $label, 'recommendation' => $recommendation, 'details' => $details];
    }

    /** @param list<array<string,mixed>> $checks @param list<array<string,mixed>> $issues @param list<array<string,mixed>> $globalChecks @return array<string,int> */
    private function scores(array $row, array $checks, array $issues, array $globalChecks): array
    {
        $stored = isset($row['seo_score']) && $row['seo_score'] !== null ? (int) $row['seo_score'] : null;
        $search = $stored !== null ? max(0, min(100, $stored)) : 100;
        $technical = 100;
        $ai = 100;

        foreach ($checks as $check) {
            if ((bool) ($check['passed'] ?? false)) { continue; }
            $penalty = $this->penalty((string) ($check['severity'] ?? 'low'));
            $code = (string) ($check['code'] ?? '');
            if (str_starts_with($code, 'meta.') || str_starts_with($code, 'canonical.') || str_starts_with($code, 'robots.')) { $search -= $penalty; }
            if (str_starts_with($code, 'content.') || str_starts_with($code, 'structured_data.')) { $ai -= $penalty; }
            if (str_starts_with($code, 'canonical.') || str_starts_with($code, 'structured_data.') || str_starts_with($code, 'internal_links.')) { $technical -= $penalty; }
        }
        foreach ($issues as $issue) {
            $search -= $this->penalty((string) ($issue['severity'] ?? 'low'));
        }
        foreach ($globalChecks as $check) {
            $penalty = max(2, (int) floor($this->penalty((string) ($check['severity'] ?? 'low')) / 2));
            $technical -= $penalty;
            if (str_starts_with((string) ($check['code'] ?? ''), 'i18n.') || str_starts_with((string) ($check['code'] ?? ''), 'domains.')) {
                $search -= $penalty;
                $ai -= 2;
            }
        }

        $search = max(0, min(100, $search));
        $technical = max(0, min(100, $technical));
        $ai = max(0, min(100, $ai));
        return [
            'overall' => (int) round(($search * 0.45) + ($technical * 0.25) + ($ai * 0.30)),
            'search_engines' => $search,
            'technical' => $technical,
            'ai_readiness' => $ai,
        ];
    }

    private function penalty(string $severity): int
    {
        return match ($severity) {
            'critical' => 30,
            'high' => 20,
            'medium' => 10,
            default => 5,
        };
    }

    /** @param list<array<string,mixed>> $checks @param list<array<string,mixed>> $issues @param list<array<string,mixed>> $globalChecks @return list<array<string,mixed>> */
    private function recommendations(array $checks, array $issues, array $globalChecks, string $path): array
    {
        $items = [];
        foreach ($checks as $check) {
            if (!(bool) ($check['passed'] ?? false)) {
                $items[] = ['scope' => 'page', 'severity' => (string) $check['severity'], 'code' => (string) $check['code'], 'text' => (string) $check['recommendation']];
            }
        }
        foreach ($issues as $issue) {
            $items[] = ['scope' => 'page', 'severity' => (string) $issue['severity'], 'code' => (string) $issue['issue_code'], 'text' => (string) $issue['message']];
        }
        foreach ($globalChecks as $check) {
            $items[] = ['scope' => 'site', 'severity' => (string) $check['severity'], 'code' => (string) $check['code'], 'text' => (string) ($check['recommendation'] ?? $check['message'] ?? '')];
        }
        usort($items, fn(array $a, array $b): int => $this->penalty((string) $b['severity']) <=> $this->penalty((string) $a['severity']));
        if ($path === '/' && count($items) < 3) {
            $items[] = ['scope' => 'page', 'severity' => 'medium', 'code' => 'home.hero_clarity', 'text' => 'Pour l’accueil, clarifier immédiatement la proposition de valeur, le public visé et les liens vers les sections principales.'];
        }
        return array_slice($items, 0, 8);
    }

    /** @return array<string,mixed> */
    private function guidance(): array
    {
        return [
            'score_model' => 'Score global = 45% moteurs de recherche, 25% technique, 30% lisibilité IA.',
            'priority_order' => ['Indexation et canonique', 'Meta title/description', 'Contenu structuré', 'Données structurées', 'Hreflang et liens internes'],
            'home_examples' => [
                'Utiliser des redirections 301 pour concentrer le trafic sur le domaine canonique, avec ou sans www.',
                'Ajouter une favicon et les icônes de site dans le head.',
                'Corriger toute canonique qui pointe vers une autre page, sauf consolidation volontaire.',
                'Publier les liens alternatifs hreflang pour les langues actives.',
                'Varier les textes d’ancrage internes lorsqu’ils sont utilisés plusieurs fois.',
            ],
        ];
    }

    private function absoluteUrl(int $siteId, string $path): string
    {
        $domain = $this->db->one("SELECT scheme, host, base_path FROM site_domains WHERE site_id = :site_id AND is_active = 1 ORDER BY is_primary DESC, id LIMIT 1", ['site_id' => $siteId]);
        if ($domain === null) {
            return $path !== '' ? $path : '/';
        }
        $basePath = trim((string) ($domain['base_path'] ?? ''), '/');
        $cleanPath = $path === '/' ? '' : ltrim($path, '/');
        $suffix = trim($basePath . '/' . $cleanPath, '/');
        return (string) ($domain['scheme'] ?? 'https') . '://' . (string) ($domain['host'] ?? '') . ($suffix !== '' ? '/' . $suffix : '/');
    }

    private function aiSummary(array $row): string
    {
        $summary = $this->textExcerpt($row);
        if ($summary === '') {
            return 'Résumé IA insuffisant: ajoutez un résumé ou un premier paragraphe explicite.';
        }
        return mb_strlen($summary) > 220 ? mb_substr($summary, 0, 217) . '…' : $summary;
    }

    /** @param list<array<string,mixed>> $checks @return list<string> */
    private function aiSignals(array $row, array $checks): array
    {
        $signals = [];
        $jsonLd = trim((string) ($row['json_ld'] ?? ''));
        if ($jsonLd !== '') { $signals[] = 'Données structurées disponibles'; }
        if (str_word_count($this->textExcerpt($row, 100000)) >= 250) { $signals[] = 'Contenu explicatif suffisant'; }
        if ((int) ($row['block_count'] ?? 0) > 0) { $signals[] = 'Blocs éditoriaux structurés'; }
        foreach ($checks as $check) {
            if (!(bool) ($check['passed'] ?? false) && count($signals) < 3) {
                $signals[] = 'À améliorer: ' . (string) ($check['label'] ?? $check['code'] ?? 'signal faible');
            }
        }
        return array_slice($signals, 0, 4);
    }

    /** @param array<string,mixed> $row */
    private function textExcerpt(array $row, int $maxLength = 320): string
    {
        $blocks = $this->decodeJson((string) ($row['blocks_json'] ?? '[]'));
        $text = trim($this->blocksPlainText($blocks));
        if ($text === '') {
            $text = trim((string) ($row['title'] ?? ''));
        }
        $text = preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '';
        $text = trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($maxLength <= 0 || self::textLength($text) <= $maxLength) {
            return $text;
        }
        return rtrim(self::textSubstr($text, 0, max(0, $maxLength - 1))) . '…';
    }

    /** @param array<mixed> $blocks */
    private function blocksPlainText(array $blocks): string
    {
        $parts = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $parts[] = $this->blockPlainText($block);
        }
        return trim(implode(' ', array_filter($parts, fn(string $part): bool => trim($part) !== '')));
    }

    /** @param array<string,mixed> $block */
    private function blockPlainText(array $block): string
    {
        $content = $block['content'] ?? $block['data'] ?? $block['props'] ?? $block;
        if (!is_array($content)) {
            return '';
        }

        $keys = ['title', 'subtitle', 'eyebrow', 'heading', 'lead', 'text', 'markdown', 'html', 'body', 'caption', 'quote'];
        $parts = [];
        foreach ($keys as $key) {
            if (isset($content[$key]) && is_scalar($content[$key])) {
                $parts[] = (string) $content[$key];
            }
        }
        foreach (['items', 'columns', 'blocks', 'children'] as $childKey) {
            if (isset($content[$childKey]) && is_array($content[$childKey])) {
                $parts[] = $this->recursivePlainText($content[$childKey]);
            }
        }

        return trim(implode(' ', $parts));
    }

    /** @param mixed $value */
    private function recursivePlainText(mixed $value): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }
        if (!is_array($value)) {
            return '';
        }
        $parts = [];
        foreach ($value as $child) {
            $parts[] = $this->recursivePlainText($child);
        }
        return trim(implode(' ', array_filter($parts, fn(string $part): bool => trim($part) !== '')));
    }

    private static function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    private static function textSubstr(string $value, int $start, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, $start, $length) : substr($value, $start, $length);
    }

    /** @return array<mixed> */
    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<mixed> $blocks @return list<string> */
    private function duplicateAnchors(string $body, array $blocks): array
    {
        $html = $body . ' ' . json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/isu', $html, $matches, PREG_SET_ORDER)) {
            return [];
        }
        $anchors = [];
        foreach ($matches as $match) {
            $href = trim((string) $match[1]);
            $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $match[2])) ?? '');
            if ($text === '' || !str_starts_with($href, '/')) { continue; }
            $anchors[mb_strtolower($text)][] = $href;
        }
        $duplicates = [];
        foreach ($anchors as $text => $hrefs) {
            if (count(array_unique($hrefs)) > 1) { $duplicates[] = $text; }
        }
        return $duplicates;
    }

    /** @param list<array<string,mixed>> $issues @param list<array<string,mixed>> $checks @param list<array<string,mixed>> $pages @return array<string,int|float|null> */
    private function summary(array $issues, array $checks, array $pages): array
    {
        $summary = [
            'pages_total' => count($pages),
            'average_score' => null,
            'issues_total' => count($issues),
            'technical_checks_total' => count($checks),
            'technical_checks_failed' => 0,
            'critical' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
            'resolved' => 0,
            'unresolved' => 0,
        ];
        foreach ($issues as $issue) {
            $severity = (string) ($issue['severity'] ?? 'low');
            if (isset($summary[$severity])) { $summary[$severity]++; }
            ((bool) ($issue['is_resolved'] ?? false)) ? $summary['resolved']++ : $summary['unresolved']++;
        }
        foreach ($checks as $check) {
            if (!(bool) ($check['passed'] ?? false)) {
                $summary['technical_checks_failed']++;
                $severity = (string) ($check['severity'] ?? 'low');
                if (isset($summary[$severity])) { $summary[$severity]++; }
            }
        }
        if ($pages !== []) {
            $summary['average_score'] = (int) round(array_sum(array_map(fn(array $page): int => (int) ($page['scores']['overall'] ?? 0), $pages)) / count($pages));
        }
        return $summary;
    }
}
