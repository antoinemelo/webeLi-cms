<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Core\ErrorCode;
use App\Core\MarkdownRenderer;
use App\Core\Request;
use App\Core\Response;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;

final class DocsApiController
{
    /** @var array<string,array<string,mixed>> */
    private array $sections;

    public function __construct(
        private readonly Request $request,
        private readonly SiteRepository $sites,
        private readonly AuthRepository $auth,
    ) {
        $this->sections = $this->sectionPolicy();
    }

    public function index(): Response
    {
        $this->auth->requireAuth();
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null, $this->auth);
        $documents = $this->accessibleDocuments((int) $site['id']);

        $sections = [];
        $visibleSectionKeys = [];
        foreach ($this->sections as $key => $section) {
            $items = array_values(array_filter($documents, static fn(array $doc): bool => ($doc['section_key'] ?? '') === $key));
            if ($items === []) {
                continue;
            }
            $visibleSectionKeys[] = $key;
            $sections[] = [
                'key' => $key,
                'label' => (string) $section['label'],
                'description' => (string) $section['description'],
                'audience_label' => (string) $section['audience_label'],
                'documents' => $items,
            ];
        }

        return Response::success([
            'sections' => $sections,
            'documents' => $documents,
            'policy' => $this->policySummary($visibleSectionKeys),
        ], 'admin.docs.index.v1', AdminApiContract::meta($site, AdminApiContract::language($this->request, $this->sites, $site)));
    }

    public function show(string $id): Response
    {
        $this->auth->requireAuth();
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null, $this->auth);
        $documents = $this->accessibleDocuments((int) $site['id']);
        $document = null;
        foreach ($documents as $candidate) {
            if (($candidate['id'] ?? '') === $id) {
                $document = $candidate;
                break;
            }
        }

        if ($document === null) {
            return Response::error(ErrorCode::ROUTE_NOT_FOUND, 'Document introuvable ou non autorisé.', ErrorCode::httpStatus(ErrorCode::ROUTE_NOT_FOUND));
        }

        $absolute = $this->docsRoot() . '/' . $document['relative_path'];
        if (!is_file($absolute)) {
            return Response::error(ErrorCode::ROUTE_NOT_FOUND, 'Document introuvable.', ErrorCode::httpStatus(ErrorCode::ROUTE_NOT_FOUND));
        }

        $source = (string) file_get_contents($absolute);
        [$frontMatter, $body] = $this->splitFrontMatter($source);

        $html = $this->withResolvedMarkdownLinks(MarkdownRenderer::toHtml($body), $document, $documents);

        return Response::success([
            'document' => $document + [
                'front_matter' => $frontMatter,
                'markdown' => $body,
                'html' => $html,
            ],
            'navigation' => $documents,
        ], 'admin.docs.show.v1', AdminApiContract::meta($site, AdminApiContract::language($this->request, $this->sites, $site), ['document_id' => $id]));
    }

    /** @return list<array<string,mixed>> */
    private function accessibleDocuments(int $siteId): array
    {
        $isSuperAdmin = $this->auth->currentUserIsSuperAdmin($siteId) || $this->auth->hasPermission('*', $siteId);
        $permissions = $this->auth->permissions($siteId);
        $documents = [];

        foreach ($this->markdownFiles() as $relativePath) {
            $sectionKey = $this->sectionKeyForPath($relativePath);
            if ($sectionKey === null || !isset($this->sections[$sectionKey])) {
                continue;
            }
            $section = $this->sections[$sectionKey];
            if (!$this->canAccessDocument($relativePath, $section, $permissions, $isSuperAdmin)) {
                continue;
            }
            $absolute = $this->docsRoot() . '/' . $relativePath;
            $source = (string) file_get_contents($absolute);
            [$frontMatter, $body] = $this->splitFrontMatter($source);
            $documents[] = $this->documentContract($relativePath, $sectionKey, $frontMatter, $body, $absolute);
        }

        usort($documents, function (array $a, array $b): int {
            $sectionOrder = ((int) ($this->sections[(string) $a['section_key']]['sort_order'] ?? 999)) <=> ((int) ($this->sections[(string) $b['section_key']]['sort_order'] ?? 999));
            if ($sectionOrder !== 0) {
                return $sectionOrder;
            }
            $aIsReadme = str_ends_with((string) $a['relative_path'], 'README.md') ? 0 : 1;
            $bIsReadme = str_ends_with((string) $b['relative_path'], 'README.md') ? 0 : 1;
            if ($aIsReadme !== $bIsReadme) {
                return $aIsReadme <=> $bIsReadme;
            }
            return strcmp((string) $a['title'], (string) $b['title']);
        });

        return $documents;
    }

    /** @param array<string,mixed> $section @param list<string> $permissions */
    private function canAccessDocument(string $relativePath, array $section, array $permissions, bool $isSuperAdmin): bool
    {
        if ($isSuperAdmin) {
            return true;
        }
        if (in_array($relativePath, (array) ($section['superadmin_documents'] ?? []), true)) {
            return false;
        }
        if (!empty($section['superadmin_only'])) {
            return false;
        }
        foreach ((array) ($section['any_permission'] ?? []) as $permission) {
            if (in_array((string) $permission, $permissions, true)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private function markdownFiles(): array
    {
        $root = $this->docsRoot();
        if (!is_dir($root)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            if (!str_ends_with(strtolower($path), '.md')) {
                continue;
            }
            $relative = ltrim(substr($path, strlen($root)), '/');
            if (str_starts_with($relative, 'evaluation/machine-readable/')) {
                continue;
            }
            $files[] = $relative;
        }
        sort($files, SORT_NATURAL | SORT_FLAG_CASE);
        return $files;
    }

    private function sectionKeyForPath(string $relativePath): ?string
    {
        $first = explode('/', $relativePath, 2)[0] ?? '';
        return isset($this->sections[$first]) ? $first : null;
    }

    /** @param array<string,mixed> $frontMatter */
    private function documentContract(string $relativePath, string $sectionKey, array $frontMatter, string $body, string $absolute): array
    {
        $title = trim((string) ($frontMatter['title'] ?? ''));
        if ($title === '') {
            $title = $this->firstHeading($body) ?: $this->titleFromPath($relativePath);
        }
        $section = $this->sections[$sectionKey];

        return [
            'id' => $this->documentId($relativePath),
            'section_key' => $sectionKey,
            'section_label' => (string) $section['label'],
            'title' => $title,
            'summary' => $this->summary($body),
            'relative_path' => $relativePath,
            'source_path' => 'docs/' . $relativePath,
            'format' => 'markdown',
            'audience' => $frontMatter['audience'] ?? [],
            'audience_label' => (string) $section['audience_label'],
            'status' => (string) ($frontMatter['status'] ?? ''),
            'version' => (string) ($frontMatter['version'] ?? ''),
            'last_verified' => (string) ($frontMatter['last_verified'] ?? ''),
            'document_type' => (string) ($frontMatter['document_type'] ?? ''),
            'generated' => (bool) ($frontMatter['generated'] ?? false),
            'is_section_index' => basename($relativePath) === 'README.md',
            'updated_at' => gmdate('Y-m-d H:i:s', max(0, (int) @filemtime($absolute))),
        ];
    }

    /** @return array{0:array<string,mixed>,1:string} */
    private function splitFrontMatter(string $source): array
    {
        $source = str_replace(["\r\n", "\r"], "\n", $source);
        if (!str_starts_with($source, "---\n")) {
            return [[], $source];
        }
        $end = strpos($source, "\n---\n", 4);
        if ($end === false) {
            return [[], $source];
        }
        $yaml = substr($source, 4, $end - 4);
        $body = ltrim(substr($source, $end + 5), "\n");
        return [$this->parseSimpleYaml($yaml), $body];
    }

    /** @return array<string,mixed> */
    private function parseSimpleYaml(string $yaml): array
    {
        $result = [];
        $currentList = null;
        foreach (explode("\n", $yaml) as $line) {
            if (preg_match('/^([A-Za-z0-9_ -]+):\s*(.*)$/', $line, $m)) {
                $key = trim(str_replace('-', '_', $m[1]));
                $value = trim($m[2]);
                if ($value === '') {
                    $result[$key] = [];
                    $currentList = $key;
                    continue;
                }
                if (in_array(strtolower($value), ['true', 'false'], true)) {
                    $result[$key] = strtolower($value) === 'true';
                } else {
                    $result[$key] = trim($value, "\"'");
                }
                $currentList = null;
                continue;
            }
            if ($currentList !== null && preg_match('/^\s+-\s*(.+)$/', $line, $m)) {
                $result[$currentList][] = trim(trim($m[1]), "\"'");
            }
        }
        return $result;
    }

    private function firstHeading(string $markdown): string
    {
        if (preg_match('/^#\s+(.+)$/m', $markdown, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    private function summary(string $markdown): string
    {
        $clean = preg_replace('/```.*?```/s', '', $markdown) ?? $markdown;
        $clean = preg_replace('/^#{1,6}\s+.*$/m', '', $clean) ?? $clean;
        $clean = preg_replace('/\[[^\]]+\]\([^)]+\)/', '', $clean) ?? $clean;
        $clean = trim(preg_replace('/\s+/', ' ', strip_tags($clean)) ?? '');
        if ($clean === '') {
            return '';
        }
        return function_exists('mb_substr') ? mb_substr($clean, 0, 220) : substr($clean, 0, 220);
    }

    private function titleFromPath(string $path): string
    {
        $base = basename($path, '.md');
        if ($base === 'README') {
            $parts = explode('/', $path);
            $base = count($parts) > 1 ? $parts[count($parts) - 2] : 'documentation';
        }
        return ucfirst(str_replace(['-', '_'], ' ', $base));
    }

    private function documentId(string $relativePath): string
    {
        $id = preg_replace('/\.md$/i', '', $relativePath) ?? $relativePath;
        $id = str_replace('/README', '/index', $id);
        $id = str_replace('/', '~', $id);
        return preg_replace('/[^A-Za-z0-9._~-]+/', '-', $id) ?? $id;
    }

    /**
     * Ajoute un identifiant de document aux liens Markdown internes déjà autorisés.
     * Le viewer peut ainsi naviguer sans dépendre du chemin relatif rendu dans le HTML.
     * Les liens vers des documents non autorisés ou absents restent inchangés et ne
     * révèlent aucun identifiant côté client.
     *
     * @param array<string,mixed> $current
     * @param list<array<string,mixed>> $documents
     */
    private function withResolvedMarkdownLinks(string $html, array $current, array $documents): string
    {
        $resolved = preg_replace_callback('/<a\s+href="([^"]*)"([^>]*)>(.*?)<\/a>/is', function (array $m) use ($current, $documents): string {
            $href = html_entity_decode((string) $m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $target = $this->documentForMarkdownHref($href, $current, $documents);
            if ($target === null) {
                return $m[0];
            }

            $title = '';
            if (preg_match('/\stitle="([^"]*)"/i', (string) $m[2], $titleMatch)) {
                $title = ' title="' . $this->escAttr(html_entity_decode((string) $titleMatch[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '"';
            }

            $id = (string) ($target['id'] ?? '');
            return '<a href="#docs/' . $this->escAttr($id) . '" data-doc-id="' . $this->escAttr($id) . '" data-doc-path="' . $this->escAttr((string) ($target['source_path'] ?? '')) . '"' . $title . '>' . $m[3] . '</a>';
        }, $html);

        return is_string($resolved) ? $resolved : $html;
    }

    /**
     * @param array<string,mixed> $current
     * @param list<array<string,mixed>> $documents
     * @return array<string,mixed>|null
     */
    private function documentForMarkdownHref(string $href, array $current, array $documents): ?array
    {
        $candidates = $this->markdownHrefCandidates($href, $current);
        if ($candidates === []) {
            return null;
        }

        foreach ($documents as $document) {
            $relative = $this->normalizedDocLookupPath((string) ($document['relative_path'] ?? ''));
            $source = $this->normalizedDocLookupPath((string) ($document['source_path'] ?? ''));
            $id = (string) ($document['id'] ?? '');
            if (in_array($relative, $candidates, true) || in_array($source, $candidates, true) || in_array($id, $candidates, true)) {
                return $document;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $current @return list<string> */
    private function markdownHrefCandidates(string $href, array $current): array
    {
        $clean = trim(explode('#', explode('?', $href, 2)[0], 2)[0]);
        if ($clean === '' || str_starts_with($clean, '#')) {
            return [];
        }
        $decoded = rawurldecode($clean);
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $decoded)) {
            return [];
        }

        $explicitDocsPath = null;
        if (preg_match('~(?:^|/)docs/(.+)$~', $decoded, $m)) {
            $explicitDocsPath = $m[1];
        }
        if (str_starts_with($decoded, '/') && $explicitDocsPath === null) {
            return [];
        }

        $currentPath = $this->normalizedDocLookupPath((string) ($current['relative_path'] ?? $current['source_path'] ?? ''));
        $currentDirectory = dirname($currentPath);
        if ($currentDirectory === '.' || $currentDirectory === '\\') {
            $currentDirectory = '';
        }

        if ($explicitDocsPath !== null) {
            $resolved = $explicitDocsPath;
        } elseif (str_starts_with($decoded, 'docs/')) {
            $resolved = substr($decoded, 5);
        } else {
            $resolved = $this->normalizeDocPath(trim($currentDirectory . '/' . $decoded, '/'));
        }

        $base = str_ends_with($resolved, '/') ? $resolved . 'README.md' : $resolved;
        $items = [
            $base,
            preg_replace('~^docs/~', '', $base) ?? $base,
            preg_replace('~/index\.md$~i', '/README.md', $base) ?? $base,
        ];

        if (!preg_match('/\.md$/i', $base)) {
            $items[] = $base . '.md';
            $items[] = rtrim($base, '/') . '/README.md';
        }

        $normalized = [];
        foreach ($items as $item) {
            $value = $this->normalizedDocLookupPath($item);
            if ($value !== '' && !in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }
        return $normalized;
    }

    private function normalizeDocPath(string $path): string
    {
        $output = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($output);
                continue;
            }
            $output[] = $part;
        }
        return implode('/', $output);
    }

    private function normalizedDocLookupPath(string $path): string
    {
        return preg_replace('~^docs/~', '', $this->normalizeDocPath($path)) ?? $path;
    }

    private function escAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function docsRoot(): string
    {
        return dirname(__DIR__, 5) . '/docs';
    }

    /** @return array<string,array<string,mixed>> */
    private function sectionPolicy(): array
    {
        return [
            'getting-started' => [
                'label' => 'Découvrir et installer localement',
                'description' => 'Parcours de prise en main pour installer et comprendre une instance locale.',
                'audience_label' => 'Admin',
                'sort_order' => 10,
                'any_permission' => ['settings.read', 'modules.read', 'users.read', 'roles.read'],
            ],
            'user-guide' => [
                'label' => 'Créer, réviser et publier',
                'description' => 'Documentation opérationnelle pour les contenus, médias, menus, publication et SEO éditorial.',
                'audience_label' => 'Éditeur · Publication · SEO',
                'sort_order' => 20,
                'any_permission' => ['content.read', 'content.update', 'content.publish', 'content.approve', 'seo.read', 'seo.simple', 'seo.manage', 'media.read', 'menu.read', 'taxonomy.read', 'forms.read', 'imports_exports.read', 'settings.read'],
                'superadmin_documents' => ['user-guide/README.md'],
            ],
            'administration' => [
                'label' => 'Administrer sites, langues, rôles et modules',
                'description' => 'Réglages fonctionnels, gouvernance, sécurité applicative et administration des modules.',
                'audience_label' => 'Admin',
                'sort_order' => 30,
                'any_permission' => ['settings.read', 'settings.manage', 'users.read', 'roles.read', 'modules.read', 'modules.manage', 'blueprints.read', 'security.tokens.read', 'security.webhooks.read', 'security.cors.read'],
            ],
            'installation' => [
                'label' => 'Installer une release',
                'description' => 'Préparer, installer ou vérifier une release distribuable.',
                'audience_label' => 'Superadmin',
                'sort_order' => 40,
                'superadmin_only' => true,
            ],
            'operations' => [
                'label' => 'Exploiter, sauvegarder, déployer et diagnostiquer',
                'description' => 'Runbooks, sauvegardes, déploiement, santé applicative, export statique et dépannage.',
                'audience_label' => 'Admin · Superadmin',
                'sort_order' => 50,
                'any_permission' => ['settings.read', 'settings.manage', 'maintenance.manage', 'modules.manage', 'imports_exports.manage', 'security.tokens.manage'],
            ],
            'api' => [
                'label' => 'Intégrer l’API publique ou consulter les contrats internes',
                'description' => 'Présentation des API et des contrats pour intégration contrôlée.',
                'audience_label' => 'Admin',
                'sort_order' => 60,
                'any_permission' => ['settings.read', 'modules.read', 'blueprints.read', 'security.tokens.read', 'security.webhooks.read'],
            ],
            'public-api' => [
                'label' => 'API publique',
                'description' => 'Documentation OpenAPI et exemples pour les endpoints publics.',
                'audience_label' => 'Admin',
                'sort_order' => 61,
                'any_permission' => ['settings.read', 'modules.read', 'blueprints.read', 'security.tokens.read'],
            ],
            'development' => [
                'label' => 'Développer et étendre le CMS',
                'description' => 'Architecture, conventions, extensions, modules, tests et outillage développeur.',
                'audience_label' => 'Superadmin',
                'sort_order' => 70,
                'superadmin_only' => true,
            ],
            'reference' => [
                'label' => 'Consulter les inventaires techniques',
                'description' => 'Inventaires générés, schémas, commandes, routes, permissions et contrats.',
                'audience_label' => 'Superadmin',
                'sort_order' => 80,
                'superadmin_only' => true,
            ],
            'evaluation' => [
                'label' => 'Évaluer les capacités et limites',
                'description' => 'Parcours d’évaluation factuel, preuves, limites, matrices et contrôles reproductibles.',
                'audience_label' => 'Admin',
                'sort_order' => 90,
                'any_permission' => ['settings.read', 'settings.manage', 'modules.read', 'modules.manage'],
            ],
        ];
    }

    /** @param list<string> $visibleSectionKeys @return list<array<string,string>> */
    private function policySummary(array $visibleSectionKeys): array
    {
        $visible = array_flip($visibleSectionKeys);
        $items = [];
        foreach ($this->sections as $key => $section) {
            if (!isset($visible[$key])) {
                continue;
            }
            $items[] = [
                'key' => $key,
                'label' => (string) $section['label'],
                'audience_label' => (string) $section['audience_label'],
            ];
        }
        return $items;
    }
}
