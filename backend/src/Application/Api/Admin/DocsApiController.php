<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Core\ErrorCode;
use App\Core\MarkdownRenderer;
use App\Core\Request;
use App\Core\Response;
use App\Repository\AuthRepository;

final class DocsApiController
{
    /** @var list<string> */
    private const DOCUMENT_EXTENSIONS = ['md', 'json', 'yaml', 'yml', 'html', 'txt'];
    private const CATALOG_READ_LIMIT = 32768;

    /** @var array<string,array<string,mixed>> */
    private array $sections;

    public function __construct(
        private readonly Request $request,
        private readonly AuthRepository $auth,
    ) {
        $this->sections = $this->sectionPolicy();
    }

    public function index(): Response
    {
        $this->auth->requireAuth();
        // Documentation is global to the installation. A valid back-office session
        // is sufficient: site assignments and IAM permissions never filter it.
        $documents = $this->documents();

        $sections = [];
        foreach ($this->sections as $key => $section) {
            $items = array_values(array_filter($documents, static fn(array $doc): bool => ($doc['section_key'] ?? '') === $key));
            if ($items === []) {
                continue;
            }
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
            'access' => [
                'authentication_required' => true,
                'permission_filtering' => false,
                'all_documentation_exposed' => true,
            ],
        ], 'admin.docs.index.v1', $this->documentationMeta());
    }

    public function resolve(): Response
    {
        $id = isset($this->request->query['id']) ? (string) $this->request->query['id'] : '';
        return $this->show($id);
    }

    public function show(string $id): Response
    {
        $this->auth->requireAuth();
        $documents = $this->documents();
        $fromId = isset($this->request->query['from_id']) ? (string) $this->request->query['from_id'] : '';
        $linkPath = isset($this->request->query['link_path']) ? (string) $this->request->query['link_path'] : '';
        $document = $this->resolveRequestedDocument($id, $documents, $fromId);
        if ($document === null && $linkPath !== '') {
            $document = $this->resolveRequestedDocument($linkPath, $documents, $fromId);
        }

        if ($document === null) {
            return Response::error(ErrorCode::ROUTE_NOT_FOUND, 'Document introuvable.', ErrorCode::httpStatus(ErrorCode::ROUTE_NOT_FOUND));
        }

        $absolute = $this->docsRoot() . '/' . $document['relative_path'];
        if (!is_file($absolute)) {
            return Response::error(ErrorCode::ROUTE_NOT_FOUND, 'Document introuvable.', ErrorCode::httpStatus(ErrorCode::ROUTE_NOT_FOUND));
        }

        $source = (string) file_get_contents($absolute);
        if (($document['format'] ?? '') === 'markdown') {
            [$frontMatter, $body] = $this->splitFrontMatter($source);
            $html = $this->withResolvedMarkdownLinks(MarkdownRenderer::toHtml($body), $document, $documents);
        } else {
            $frontMatter = [];
            $body = $source;
            $html = $this->renderSourceDocument($source, (string) ($document['format'] ?? 'text'));
        }

        return Response::success([
            'document' => $document + [
                'front_matter' => $frontMatter,
                'markdown' => ($document['format'] ?? '') === 'markdown' ? $body : null,
                'source' => $body,
                'html' => $html,
            ],
            'navigation' => $documents,
        ], 'admin.docs.show.v1', $this->documentationMeta(['document_id' => $id]));
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function documentationMeta(array $extra = []): array
    {
        return [
            'contract_version' => AdminApiContract::VERSION,
            'scope' => 'installation',
        ] + $extra;
    }

    /** @return list<array<string,mixed>> */
    private function documents(): array
    {
        $documents = [];

        foreach ($this->documentationFiles() as $relativePath) {
            $sectionKey = $this->sectionKeyForPath($relativePath);
            if ($sectionKey === null || !isset($this->sections[$sectionKey])) {
                continue;
            }
            $absolute = $this->docsRoot() . '/' . $relativePath;
            // The catalogue only needs front matter and a short summary. Large
            // OpenAPI/contracts are read in full only when the user opens them.
            $source = (string) file_get_contents($absolute, false, null, 0, self::CATALOG_READ_LIMIT);
            [$frontMatter, $body] = $this->formatForPath($relativePath) === 'markdown'
                ? $this->splitFrontMatter($source)
                : [[], $source];
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

    /**
     * Résout un document demandé depuis le viewer Markdown.
     *
     * Les liens rendus peuvent arriver sous plusieurs formes selon le navigateur,
     * le cache d'assets ou l'état du contenu : identifiant canonique, chemin
     * source, chemin relatif, variante #docs/<id> ou ancien identifiant calculé
     * depuis un lien relatif. La résolution reste limitée aux documents déjà
     * indexés depuis le répertoire documentaire canonique.
     *
     * @param list<array<string,mixed>> $documents
     * @return array<string,mixed>|null
     */
    private function resolveRequestedDocument(string $requestedId, array $documents, string $fromId = ''): ?array
    {
        $requestedId = $this->decodeRequestToken($requestedId);

        foreach ($documents as $document) {
            if ((string) ($document['id'] ?? '') === $requestedId) {
                return $document;
            }
        }

        $from = null;
        if ($fromId !== '') {
            $fromId = $this->decodeRequestToken($fromId);
            foreach ($documents as $document) {
                if ((string) ($document['id'] ?? '') === $fromId) {
                    $from = $document;
                    break;
                }
            }
        }

        $candidates = $this->requestDocumentCandidates($requestedId, $from);
        if ($candidates === []) {
            return null;
        }

        $matches = [];
        foreach ($documents as $document) {
            if ($this->documentMatchesCandidates($document, $candidates, true)) {
                $matches[(string) ($document['id'] ?? '')] = $document;
            }
        }
        if (count($matches) === 1) {
            return reset($matches) ?: null;
        }
        if (count($matches) > 1) {
            foreach ($matches as $document) {
                if ($this->documentMatchesCandidates($document, $candidates, false)) {
                    return $document;
                }
            }
        }

        return null;
    }

    private function decodeRequestToken(string $value): string
    {
        $value = trim($value);
        for ($i = 0; $i < 2; $i++) {
            $decoded = rawurldecode($value);
            if ($decoded === $value) {
                break;
            }
            $value = $decoded;
        }
        if (str_starts_with($value, '#docs/')) {
            $value = substr($value, 6);
        }
        return trim($value);
    }

    /** @param array<string,mixed>|null $from @return list<string> */
    private function requestDocumentCandidates(string $requested, ?array $from): array
    {
        $requested = $this->decodeRequestToken($requested);
        if ($requested === '' || preg_match('/^[a-z][a-z0-9+.-]*:/i', $requested)) {
            return [];
        }

        $rawValues = [$requested];
        if (str_starts_with($requested, 'docs/')) {
            $rawValues[] = substr($requested, 5);
        }
        if (str_contains($requested, '~')) {
            $rawValues[] = str_replace('~', '/', $requested);
        }

        if ($from !== null) {
            foreach ($rawValues as $value) {
                $fromCandidates = $this->markdownHrefCandidates($value, $from);
                foreach ($fromCandidates as $candidate) {
                    $rawValues[] = $candidate;
                }
            }
        }

        $candidates = [];
        foreach ($rawValues as $value) {
            $value = $this->normalizedDocLookupPath($value);
            if ($value === '') {
                continue;
            }
            $variants = [$value];
            if (str_ends_with($value, '/index')) {
                $variants[] = substr($value, 0, -strlen('/index')) . '/README.md';
            }
            if (str_ends_with($value, '/index.md')) {
                $variants[] = substr($value, 0, -strlen('/index.md')) . '/README.md';
            }
            if (!preg_match('/\.md$/i', $value)) {
                $variants[] = $value . '.md';
                $variants[] = rtrim($value, '/') . '/README.md';
            }
            foreach ($variants as $variant) {
                $normalized = $this->normalizedDocLookupPath($variant);
                if ($normalized !== '' && !in_array($normalized, $candidates, true)) {
                    $candidates[] = $normalized;
                }
                $asId = $this->documentId($normalized);
                if ($asId !== '' && !in_array($asId, $candidates, true)) {
                    $candidates[] = $asId;
                }
            }
        }

        return $candidates;
    }

    /** @param array<string,mixed> $document @param list<string> $candidates */
    private function documentMatchesCandidates(array $document, array $candidates, bool $allowSuffix): bool
    {
        $id = (string) ($document['id'] ?? '');
        $relative = $this->normalizedDocLookupPath((string) ($document['relative_path'] ?? ''));
        $source = $this->normalizedDocLookupPath((string) ($document['source_path'] ?? ''));
        foreach ($candidates as $candidate) {
            $candidate = $this->normalizedDocLookupPath($candidate);
            if ($candidate === '') {
                continue;
            }
            if ($candidate === $id || $candidate === $relative || $candidate === $source || $this->documentId($candidate) === $id) {
                return true;
            }
            if ($allowSuffix && !str_contains($candidate, '/') && !str_contains($candidate, '~')) {
                continue;
            }
            if ($allowSuffix && (str_ends_with($relative, '/' . $candidate) || str_ends_with($source, '/' . $candidate))) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private function documentationFiles(): array
    {
        $root = $this->docsRoot();
        if (!is_dir($root)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            $relative = ltrim(substr($path, strlen($root)), '/');
            $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
            if (!in_array($extension, self::DOCUMENT_EXTENSIONS, true)) {
                continue;
            }
            $files[] = $relative;
        }
        sort($files, SORT_NATURAL | SORT_FLAG_CASE);
        return $files;
    }

    private function sectionKeyForPath(string $relativePath): ?string
    {
        if (!str_contains($relativePath, '/')) {
            return 'overview';
        }
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
            'format' => $this->formatForPath($relativePath),
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

    private function formatForPath(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'md' => 'markdown',
            'json' => 'json',
            'yaml', 'yml' => 'yaml',
            'html' => 'html-source',
            default => 'text',
        };
    }

    private function renderSourceDocument(string $source, string $format): string
    {
        if ($format === 'json') {
            $decoded = json_decode($source, true);
            if (is_array($decoded)) {
                $encoded = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (is_string($encoded)) {
                    $source = $encoded;
                }
            }
        }
        $language = match ($format) {
            'json' => 'json',
            'yaml' => 'yaml',
            'html-source' => 'html',
            default => 'text',
        };
        return '<pre><code class="language-' . $language . '">' . htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>';
    }

    private function documentId(string $relativePath): string
    {
        $id = preg_replace('/\.md$/i', '', $relativePath) ?? $relativePath;
        $id = str_replace('/README', '/index', $id);
        $id = str_replace('/', '~', $id);
        return preg_replace('/[^A-Za-z0-9._~-]+/', '-', $id) ?? $id;
    }

    /**
     * Ajoute un identifiant de document aux liens documentaires internes indexés.
     * Le viewer peut ainsi naviguer sans dépendre du chemin relatif rendu dans le HTML.
     * Les liens vers des documents absents restent inchangés.
     *
     * @param array<string,mixed> $current
     * @param list<array<string,mixed>> $documents
     */
    private function withResolvedMarkdownLinks(string $html, array $current, array $documents): string
    {
        $resolved = preg_replace_callback('~<a\b([^>]*)\bhref=("|\')([^"\']*)\2([^>]*)>(.*?)</a>~is', function (array $m) use ($current, $documents): string {
            $before = (string) $m[1];
            $quote = (string) $m[2];
            $rawHref = (string) $m[3];
            $after = (string) $m[4];
            $label = (string) $m[5];
            $href = html_entity_decode($rawHref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $target = $this->documentForMarkdownHref($href, $current, $documents);
            if ($target === null) {
                return $m[0];
            }

            $id = (string) ($target['id'] ?? '');
            if ($id === '') {
                return $m[0];
            }

            $attrs = $this->cleanAnchorAttributes($before . ' ' . $after);
            $docPath = (string) ($target['source_path'] ?? '');

            return '<a href=' . $quote . '#docs/' . $this->escAttr($id) . $quote
                . ' data-doc-id="' . $this->escAttr($id) . '"'
                . ' data-doc-path="' . $this->escAttr($docPath) . '"'
                . ($attrs !== '' ? ' ' . $attrs : '')
                . '>' . $label . '</a>';
        }, $html);

        return is_string($resolved) ? $resolved : $html;
    }

    private function cleanAnchorAttributes(string $attributes): string
    {
        $attributes = preg_replace('~\s(?:href|data-doc-id|data-doc-path)=("[^"]*"|\'[^\']*\'|[^\s>]+)~i', '', $attributes) ?? '';
        $attributes = trim(preg_replace('/\s+/', ' ', $attributes) ?? '');
        return $attributes;
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
            'overview' => [
                'label' => 'Documentation',
                'description' => 'Point d’entrée global de la documentation.',
                'audience_label' => 'Tous',
                'sort_order' => 0,
            ],
            'getting-started' => [
                'label' => 'Découvrir et installer localement',
                'description' => 'Parcours de prise en main pour installer et comprendre une instance locale.',
                'audience_label' => 'Tous',
                'sort_order' => 10,
            ],
            'user-guide' => [
                'label' => 'Créer, réviser et publier',
                'description' => 'Documentation opérationnelle pour les contenus, médias, menus, publication et SEO éditorial.',
                'audience_label' => 'Tous',
                'sort_order' => 20,
            ],
            'administration' => [
                'label' => 'Administrer sites, langues, rôles et modules',
                'description' => 'Réglages fonctionnels, gouvernance, sécurité applicative et administration des modules.',
                'audience_label' => 'Tous',
                'sort_order' => 30,
            ],
            'installation' => [
                'label' => 'Installer une release',
                'description' => 'Préparer, installer ou vérifier une release distribuable.',
                'audience_label' => 'Tous',
                'sort_order' => 40,
            ],
            'operations' => [
                'label' => 'Exploiter, sauvegarder, déployer et diagnostiquer',
                'description' => 'Runbooks, sauvegardes, déploiement, santé applicative, export statique et dépannage.',
                'audience_label' => 'Tous',
                'sort_order' => 50,
            ],
            'api' => [
                'label' => 'Intégrer l’API publique ou consulter les contrats internes',
                'description' => 'Présentation des API et des contrats pour intégration contrôlée.',
                'audience_label' => 'Tous',
                'sort_order' => 60,
            ],
            'public-api' => [
                'label' => 'API publique',
                'description' => 'Documentation OpenAPI et exemples pour les endpoints publics.',
                'audience_label' => 'Tous',
                'sort_order' => 61,
            ],
            'development' => [
                'label' => 'Développer et étendre le CMS',
                'description' => 'Architecture, conventions, extensions, modules, tests et outillage développeur.',
                'audience_label' => 'Tous',
                'sort_order' => 70,
            ],
            'reference' => [
                'label' => 'Consulter les inventaires techniques',
                'description' => 'Inventaires générés, schémas, commandes, routes, permissions et contrats.',
                'audience_label' => 'Tous',
                'sort_order' => 80,
            ],
            'evaluation' => [
                'label' => 'Évaluer les capacités et limites',
                'description' => 'Parcours d’évaluation factuel, preuves, limites, matrices et contrôles reproductibles.',
                'audience_label' => 'Tous',
                'sort_order' => 90,
            ],
        ];
    }

}
