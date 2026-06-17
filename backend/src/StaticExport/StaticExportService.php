<?php

declare(strict_types=1);

namespace App\StaticExport;

use App\EditorialPackage\EditorialExportService;

final class StaticExportService
{
    /** @var list<string> */
    private array $errors = [];
    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        private readonly SqlStaticExportSource $source,
        private readonly StaticExportRouteCollector $collector,
        private readonly StaticExportRenderer $renderer,
        private readonly StaticAssetCollector $assets,
        private readonly StaticExportManifestWriter $writer,
        private readonly ?StaticFormRenderer $forms = null,
        private readonly ?EditorialExportService $editorialExporter = null,
    ) {}

    /**
     * @param array{site?:string|null,lang?:string|null,all_languages?:bool,route?:string|null,output?:string|null,dry_run?:bool,create_zip?:bool,trigger?:string|null} $options
     * @return array<string,mixed>
     */
    public function export(array $options): array
    {
        $started = microtime(true);
        $releaseId = gmdate('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $releaseDir = $this->safeReleaseDirectory((string) ($options['output'] ?? ''), $releaseId);
        $publicDir = $releaseDir . '/public';
        $siteKey = trim((string) ($options['site'] ?? '')) ?: null;
        $routePath = trim((string) ($options['route'] ?? '')) ?: null;
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $lang = trim((string) ($options['lang'] ?? '')) ?: null;
        $allLanguages = (bool) ($options['all_languages'] ?? false);
        if ($allLanguages) {
            $lang = null;
        }
        $mode = $routePath !== null ? 'route' : ($siteKey !== null ? ($lang !== null ? 'language' : 'site') : 'site');
        $trigger = $dryRun ? 'dry_run' : (trim((string) ($options['trigger'] ?? '')) ?: 'cli');
        $createZip = (bool) ($options['create_zip'] ?? false);

        $routes = $this->collector->collect($siteKey, $lang, $routePath);
        $ignored = $this->collector->ignoredRoutes();
        $exported = [];
        $files = [];
        $media = [];
        $assetFiles = [];
        $formKeys = [];
        $formRuntimeRequired = false;

        if (!$dryRun) {
            $this->prepareReleaseDirectory($releaseDir, $publicDir);
            $this->writeMinimalStaticCss($publicDir);
        }

        $mediaRows = $this->source->listPublicMediaAssets($siteKey);
        $routeTargets = $this->routeTargets($routes);
        foreach ($routes as $route) {
            $site = $this->source->siteById($route->siteId);
            if (!$site) {
                $ignored[] = $route->toArray() + ['reason' => 'site_not_found'];
                continue;
            }
            try {
                $response = $this->renderer->renderRoute($route, $site + $this->siteRuntimeDefaults($site));
                if (!in_array($response->status(), [200, 410], true)) {
                    $ignored[] = $route->toArray() + ['reason' => 'non_exportable_status_' . $response->status()];
                    continue;
                }
                $prepared = $this->prepareHtmlForStaticOutput($response->body(), $route, $site + $this->siteRuntimeDefaults($site), $routeTargets, $this->isSingleLanguageExport($routes, $routePath, $lang));
                $html = $prepared['html'];
                $formKeys = array_values(array_unique(array_merge($formKeys, $prepared['forms'])));
                $formRuntimeRequired = $formRuntimeRequired || $prepared['form_runtime_required'];
                $this->warnings = array_values(array_unique(array_merge($this->warnings, $prepared['warnings'])));
                $target = $publicDir . '/' . $route->outputPath;
                if (!$dryRun) {
                    $this->writeFile($target, $html);
                    $copied = $this->assets->copyForHtml($html, $publicDir, $mediaRows);
                    $assetFiles = array_values(array_unique(array_merge($assetFiles, $copied['assets'])));
                    $media = array_values(array_unique(array_merge($media, $copied['media'])));
                    $this->warnings = array_values(array_unique(array_merge($this->warnings, $copied['warnings'])));
                }
                $exported[] = $route->toArray() + ['status' => $response->status(), 'file' => $route->outputPath];
                $files[] = $route->outputPath;
            } catch (\Throwable $e) {
                $this->errors[] = $route->path . ': ' . $e->getMessage();
                $ignored[] = $route->toArray() + ['reason' => 'render_error'];
            }
        }

        $completeExport = $routePath === null;
        if (!$dryRun && $completeExport && $routes !== []) {
            $firstSite = $this->source->siteById($routes[0]->siteId);
            if ($firstSite) {
                $this->writeFile($publicDir . '/sitemap.xml', $this->renderer->renderSitemapXml($routes, $firstSite + $this->siteRuntimeDefaults($firstSite), $lang));
                $this->writeFile($publicDir . '/robots.txt', $this->renderer->renderRobotsTxt());
                $files[] = 'sitemap.xml';
                $files[] = 'robots.txt';
            }
            $this->writeRedirectFiles($releaseDir, $this->source->listRedirects($siteKey));
            $this->writeTombstoneFiles($releaseDir, $this->source->listTombstones($siteKey));
        }

        if (!$dryRun) {
            $assetFiles = array_values(array_unique(array_merge($assetFiles, ['assets/static-export.css'])));
            $files[] = 'assets/static-export.css';
            if ($formRuntimeRequired) {
                $this->writeStaticFormRuntime($publicDir);
                $assetFiles = array_values(array_unique(array_merge($assetFiles, ['assets/static-export-forms.js'])));
                $files[] = 'assets/static-export-forms.js';
            }
        }

        $zipPath = null;
        if (!$dryRun && $createZip) {
            $zipPath = $this->createZipArchive($releaseDir, $publicDir);
            if ($zipPath !== null) {
                $files[] = 'static-export.zip';
            }
        }

        $editorialArtifact = null;
        $editorialError = null;
        if (!$dryRun && $createZip && $this->editorialExporter !== null && $exported !== []) {
            try {
                $editorialArtifact = $this->editorialExporter->export(
                    $releaseDir,
                    $releaseId,
                    $routes,
                    $mode === 'route' ? 'page' : $mode,
                    $routePath ?? $lang ?? $siteKey,
                    $this->cmsVersion(),
                );
                $files[] = 'editorial-export.zip';
            } catch (\Throwable $e) {
                $editorialError = $e->getMessage();
                $this->errors[] = 'Editorial Package: ' . $editorialError;
            }
        }

        $staticArtifact = $zipPath !== null && is_file($zipPath) ? [
            'filename' => basename($zipPath),
            'sha256' => hash_file('sha256', $zipPath),
            'size' => filesize($zipPath) ?: 0,
        ] : null;

        $languages = array_values(array_unique(array_map(static fn(StaticExportRoute $r): string => $r->languageCode, $routes)));
        sort($languages);
        $report = [
            'release_id' => $releaseId,
            'dry_run' => $dryRun,
            'routes_exported' => $exported,
            'routes_ignored' => $ignored,
            'files_written' => $files,
            'media_copied' => $media,
            'assets_copied' => $assetFiles,
            'zip_path' => $zipPath,
            'redirects' => $this->source->listRedirects($siteKey),
            'tombstones' => $this->source->listTombstones($siteKey),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'artifacts' => ['static' => $staticArtifact, 'editorial' => $editorialArtifact],
            'editorial_error' => $editorialError,
            'known_mvp_limits' => [
                'Intégration back-office légère disponible dans Production éditoriale > Imports / Exports, sans déclenchement automatique complet par défaut.',
                'Les redirects sont documentés en JSON/_redirects, mais leur application dépend de l’hébergeur statique.',
                'Les formulaires publiés sont matérialisés dans l’HTML statique; leur soumission nécessite un endpoint backend public accessible.',
                'Le sitemap MVP est généré depuis les routes exportées indexables, sans ping moteur ni index de sitemaps multisite avancé.',
                'Le rollback statique reste préparatoire: l’historique permet d’identifier une release, mais la réactivation automatique n’est pas encore câblée.',
            ],
            'forms_static' => [
                'forms_rendered' => $formKeys,
                'submission_endpoint' => 'public_api_v2_forms_submit_when_backend_available',
                'runtime' => $formRuntimeRequired ? 'assets/static-export-forms.js' : null,
            ],
            'editorial_integration' => [
                'placement' => 'Production éditoriale > Imports / Exports',
                'optional' => true,
                'trigger' => $trigger,
                'route' => $routePath,
                'webhook_outbox_topic' => 'static_export.succeeded',
                'static_rollback_is_editorial_rollback' => false,
            ],
        ];
        $manifest = new StaticExportManifest(
            $releaseId,
            $siteKey,
            $languages,
            $mode,
            count($routes) + count($ignored),
            count($exported),
            count($ignored),
            count($assetFiles),
            count($media),
            $this->errors,
            $this->warnings,
            gmdate('c'),
            $publicDir,
            $this->cmsVersion(),
            round(microtime(true) - $started, 3),
            $this->errors === [] ? 'succeeded' : (($zipPath !== null || $exported !== []) ? 'partial' : 'failed'),
            $zipPath,
            $trigger,
            $routePath,
            ['static' => $staticArtifact, 'editorial' => $editorialArtifact],
        );

        if (!$dryRun) {
            $this->writer->write($releaseDir, $manifest, $report);
        }

        return [
            'release_dir' => $releaseDir,
            'public_dir' => $publicDir,
            'manifest' => $manifest->toArray(),
            'report' => $report,
        ];
    }

    /** @param list<StaticExportRoute> $routes */
    private function isSingleLanguageExport(array $routes, ?string $routePath, ?string $lang): bool
    {
        if ($routePath === null && $lang === null) {
            return false;
        }
        $languages = array_values(array_unique(array_map(static fn(StaticExportRoute $r): string => $r->languageCode, $routes)));
        return count($languages) <= 1;
    }

    /** @param array<string,mixed> $site */
    private function siteRuntimeDefaults(array $site): array
    {
        $baseUrl = (string) ($site['base_url'] ?? '');
        return [
            'base_url' => $baseUrl,
            'current_base_url' => $baseUrl,
            'matched_base_path' => '',
            'is_canonical_domain' => true,
            'should_redirect_https' => false,
        ];
    }

    private function safeReleaseDirectory(string $customOutput, string $releaseId): string
    {
        $dir = $customOutput !== '' ? $customOutput : base_path('storage/exports/static/' . $releaseId);
        if (!str_starts_with($dir, DIRECTORY_SEPARATOR)) {
            $dir = base_path($dir);
        }
        $normalized = rtrim(str_replace('\\', '/', $dir), '/');
        if (preg_match('#/(tools|database|ops|storage/database|storage/logs|storage/cache|node_modules)(/|$)#', $normalized)) {
            throw new \InvalidArgumentException('Dossier de sortie refusé pour raison de sécurité: ' . $dir);
        }
        return $normalized;
    }

    private function prepareReleaseDirectory(string $releaseDir, string $publicDir): void
    {
        if (is_dir($releaseDir)) {
            $this->removeDirectory($releaseDir);
        }
        if (!mkdir($publicDir, 0775, true) && !is_dir($publicDir)) {
            throw new \RuntimeException('Impossible de créer le dossier public d’export: ' . $publicDir);
        }
    }

    private function writeFile(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Impossible de créer le dossier: ' . $dir);
        }
        file_put_contents($path, $content);
    }

    /** @param list<array<string,mixed>> $redirects */
    private function writeRedirectFiles(string $releaseDir, array $redirects): void
    {
        $lines = [];
        foreach ($redirects as $redirect) {
            $lines[] = trim((string) ($redirect['old_path'] ?? '/')) . ' ' . trim((string) ($redirect['new_path'] ?? '/')) . ' ' . (int) ($redirect['http_code'] ?? 301);
        }
        $this->writeFile($releaseDir . '/redirects.json', json_encode($redirects, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        $this->writeFile($releaseDir . '/public/_redirects', implode("\n", $lines) . ($lines ? "\n" : ''));
    }

    /** @param list<array<string,mixed>> $tombstones */
    private function writeTombstoneFiles(string $releaseDir, array $tombstones): void
    {
        $this->writeFile($releaseDir . '/tombstones.json', json_encode($tombstones, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }


    private function writeMinimalStaticCss(string $publicDir): void
    {
        $css = <<<'CSS'
:root{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#0f172a;background:#f8fafc;line-height:1.55}*{box-sizing:border-box}body{margin:0;background:#f8fafc;color:#0f172a}main,.page,.container{width:min(1120px,calc(100% - 2rem));margin-inline:auto}header,footer,section,article{max-width:100%}a{color:#db2777;text-decoration-thickness:.08em;text-underline-offset:.16em}a:hover{text-decoration-thickness:.14em}img,video,svg{max-width:100%;height:auto}h1,h2,h3{line-height:1.15;color:#0b1220}h1{font-size:clamp(2rem,5vw,4rem)}h2{font-size:clamp(1.55rem,3vw,2.4rem)}p{max-width:70ch}.btn,button,input,select,textarea{font:inherit}.btn,button{border-radius:.8rem;border:1px solid #cbd5e1;padding:.65rem 1rem;background:#fff;color:#0f172a}table{border-collapse:collapse;width:100%}th,td{border-bottom:1px solid #e2e8f0;padding:.65rem;text-align:left}
CSS;
        $this->writeFile($publicDir . '/assets/static-export.css', $css . "\n");
    }

    /** @param array<string,mixed> $site @param array<string,string> $routeTargets @return array{html:string,warnings:list<string>,forms:list<string>,form_runtime_required:bool} */
    private function prepareHtmlForStaticOutput(string $html, StaticExportRoute $route, array $site, array $routeTargets, bool $singleLanguageExport = false): array
    {
        $warnings = [];
        $forms = [];
        $formRuntimeRequired = false;
        $html = $this->removeAdminOnlyControls($html);
        if ($singleLanguageExport) {
            $html = $this->removeLanguageSwitchControls($html);
        }
        if ($this->forms !== null) {
            $formResult = $this->forms->render($html, $route, $site);
            $html = $formResult['html'];
            $warnings = $formResult['warnings'];
            $forms = $formResult['forms'];
            $formRuntimeRequired = (bool) $formResult['runtime_required'];
        }
        $html = $this->injectStaticCss($html, $route->outputPath);
        if ($formRuntimeRequired) {
            $html = $this->injectStaticFormRuntime($html, $route->outputPath);
        }
        $html = $this->makeLocalReferencesRelative($html, $route->outputPath, $routeTargets);
        return ['html' => $html, 'warnings' => $warnings, 'forms' => $forms, 'form_runtime_required' => $formRuntimeRequired];
    }

    private function removeAdminOnlyControls(string $html): string
    {
        // A static export must never expose an administration/login shortcut.
        // The rendered SSR HTML may contain /admin/login, /admin/app, or the
        // same URLs prefixed by the CMS installation directory such as /mod.
        $adminHref = '[^"\']*/admin(?:/[^"\']*)?';
        $patterns = [
            '#<a\b(?=[^>]*\bhref=["\']' . $adminHref . '["\'])[^>]*>.*?</a>#is',
            '#<a\b(?=[^>]*\bclass=["\'][^"\']*\blogin-shortcut\b)[^>]*>.*?</a>#is',
            '#<form\b(?=[^>]*\baction=["\']' . $adminHref . '["\'])[^>]*>.*?</form>#is',
            '#<button\b(?=[^>]*\bformaction=["\']' . $adminHref . '["\'])[^>]*>.*?</button>#is',
        ];
        foreach ($patterns as $pattern) {
            $html = preg_replace($pattern, '', $html) ?? $html;
        }
        return $html;
    }


    private function removeLanguageSwitchControls(string $html): string
    {
        // A page/language scoped static export should not expose a language
        // switcher, because sibling language pages are not guaranteed to be
        // part of the exported release. Keep the page content and main menu.
        $patterns = [
            '#<div\b(?=[^>]*\bclass=["\'][^"\']*\blanguage-switch\b[^"\']*["\'])[^>]*>.*?</div>#is',
            '#<nav\b(?=[^>]*\bclass=["\'][^"\']*\blanguage-switch-mobile\b[^"\']*["\'])[^>]*>.*?</nav>#is',
            '#<ul\b(?=[^>]*\bclass=["\'][^"\']*\blanguage-switch__menu\b[^"\']*["\'])[^>]*>.*?</ul>#is',
            '#<button\b(?=[^>]*\bclass=["\'][^"\']*\blanguage-switch__button\b[^"\']*["\'])[^>]*>.*?</button>#is',
        ];
        foreach ($patterns as $pattern) {
            $html = preg_replace($pattern, '', $html) ?? $html;
        }
        return $html;
    }

    private function injectStaticCss(string $html, string $outputPath): string
    {
        if (str_contains($html, 'assets/static-export.css')) {
            return $html;
        }
        $href = $this->relativePath($outputPath, 'assets/static-export.css');
        $link = '<link rel="stylesheet" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">';
        if (preg_match('/<\/head>/i', $html)) {
            return preg_replace('/<\/head>/i', "  {$link}\n</head>", $html, 1) ?? $html;
        }
        return $link . "\n" . $html;
    }


    private function injectStaticFormRuntime(string $html, string $outputPath): string
    {
        if (str_contains($html, 'assets/static-export-forms.js')) {
            return $html;
        }
        $src = $this->relativePath($outputPath, 'assets/static-export-forms.js');
        $script = '<script src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" defer></script>';
        if (preg_match('/<\/body>/i', $html)) {
            return preg_replace('/<\/body>/i', "  {$script}
</body>", $html, 1) ?? $html;
        }
        return $html . "
" . $script . "
";
    }

    private function writeStaticFormRuntime(string $publicDir): void
    {
        $js = <<<'JS'
(function(){
  function show(form, message, ok){
    var box = form.querySelector('[data-form-message]');
    if (!box) return;
    box.textContent = message || '';
    box.classList.remove('is-success','is-error');
    box.classList.add(ok ? 'is-success' : 'is-error');
  }
  function clearErrors(form){
    form.querySelectorAll('[data-error-for]').forEach(function(node){ node.textContent = ''; });
  }
  function setErrors(form, errors){
    if (!errors || typeof errors !== 'object') return;
    Object.keys(errors).forEach(function(key){
      var node = form.querySelector('[data-error-for="' + CSS.escape(key) + '"]');
      if (node) node.textContent = Array.isArray(errors[key]) ? errors[key].join(' ') : String(errors[key] || '');
    });
  }
  document.addEventListener('submit', function(event){
    var form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-static-form]')) return;
    event.preventDefault();
    clearErrors(form);
    var url = form.getAttribute('data-static-submit-url') || form.getAttribute('action') || '';
    if (!url) { show(form, 'Le formulaire ne peut pas être envoyé depuis cette copie statique.', false); return; }
    var button = form.querySelector('button[type="submit"]');
    if (button) button.disabled = true;
    fetch(url, { method: 'POST', body: new FormData(form), headers: { 'Accept': 'application/json' } })
      .then(function(response){ return response.json().catch(function(){ return {}; }).then(function(data){ return { ok: response.ok, data: data }; }); })
      .then(function(result){
        if (result.ok) {
          form.reset();
          show(form, form.getAttribute('data-static-success-message') || 'Merci, votre message a été envoyé.', true);
        } else {
          setErrors(form, result.data && (result.data.errors || result.data.fields));
          show(form, (result.data && (result.data.message || result.data.error)) || 'Le formulaire n’a pas pu être envoyé.', false);
        }
      })
      .catch(function(){ show(form, 'Le formulaire n’a pas pu être envoyé. Vérifiez que le backend public est accessible.', false); })
      .finally(function(){ if (button) button.disabled = false; });
  });
})();
JS;
        $this->writeFile($publicDir . '/assets/static-export-forms.js', $js . "
");
    }

    /** @param array<string,string> $routeTargets */
    private function makeLocalReferencesRelative(string $html, string $outputPath, array $routeTargets): string
    {
        $self = $this;
        return preg_replace_callback('/\b(src|href|poster|action|srcset)=("|\')([^"\']+)(\2)/i', static function (array $match) use ($self, $outputPath, $routeTargets): string {
            $attribute = strtolower((string) $match[1]);
            $quote = $match[2];
            $value = html_entity_decode((string) $match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $rewritten = $attribute === 'srcset'
                ? $self->rewriteSrcsetReferences($value, $outputPath, $routeTargets)
                : $self->rewriteLocalReference($value, $outputPath, $routeTargets);
            return $match[1] . '=' . $quote . htmlspecialchars($rewritten, ENT_QUOTES | ENT_HTML5, 'UTF-8') . $quote;
        }, $html) ?? $html;
    }

    /** @param array<string,string> $routeTargets */
    private function rewriteSrcsetReferences(string $value, string $outputPath, array $routeTargets): string
    {
        $parts = array_map('trim', explode(',', $value));
        $rewritten = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $segments = preg_split('/\s+/', $part, 2);
            $url = $segments[0] ?? '';
            $descriptor = $segments[1] ?? '';
            $rewritten[] = trim($this->rewriteLocalReference($url, $outputPath, $routeTargets) . ' ' . $descriptor);
        }
        return implode(', ', $rewritten);
    }

    /** @param array<string,string> $routeTargets */
    private function rewriteLocalReference(string $value, string $outputPath, array $routeTargets): string
    {
        $trimmed = trim($value);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || preg_match('#^(https?:)?//#i', $trimmed) || str_starts_with($trimmed, 'data:') || str_starts_with($trimmed, 'mailto:') || str_starts_with($trimmed, 'tel:')) {
            return $value;
        }
        $parts = parse_url($trimmed);
        if ($parts === false || empty($parts['path']) || !str_starts_with((string) $parts['path'], '/')) {
            return $value;
        }
        $path = (string) $parts['path'];
        $target = $this->publicPathToExportTarget($path, $routeTargets);
        $relative = $this->relativePath($outputPath, $target);
        if (isset($parts['query']) && $parts['query'] !== '') {
            $relative .= '?' . $parts['query'];
        }
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $relative .= '#' . $parts['fragment'];
        }
        return $relative;
    }

    /** @param array<string,string> $routeTargets */
    private function publicPathToExportTarget(string $path, array $routeTargets): string
    {
        $clean = $this->stripRuntimeBasePath('/' . ltrim($path, '/'));
        $clean = $this->stripKnownExportBasePath($clean, $routeTargets);
        if ($clean === '/') {
            return $routeTargets['/'] ?? 'index.html';
        }

        if (isset($routeTargets[$clean])) {
            return $routeTargets[$clean];
        }

        $relative = ltrim($clean, '/');
        if (!is_file(base_path($relative))) {
            $parts = explode('/', $relative, 2);
            if (count($parts) === 2 && in_array(explode('/', $parts[1], 2)[0], ['frontend', 'storage'], true)) {
                $relative = $parts[1];
                $clean = '/' . $relative;
            }
        }
        $extension = pathinfo($clean, PATHINFO_EXTENSION);
        if ($extension !== '') {
            return ltrim($clean, '/');
        }
        return trim($clean, '/') . '/index.html';
    }


    /** @param list<StaticExportRoute> $routes @return array<string,string> */
    private function routeTargets(array $routes): array
    {
        $targets = [];
        foreach ($routes as $route) {
            $path = '/' . trim($route->path, '/');
            $path = $path === '/' ? '/' : rtrim($path, '/');
            $prefix = trim((string) ($route->metadata['url_prefix'] ?? ''), '/');
            $localizedPath = SqlStaticExportSource::localizedPublicPath($path, $prefix);
            $targets[$localizedPath] = $route->outputPath;
            if ($prefix === '') {
                $targets[$path] = $route->outputPath;
            }
        }
        return $targets;
    }

    /** @param array<string,string> $routeTargets */
    private function stripKnownExportBasePath(string $path, array $routeTargets): string
    {
        $path = '/' . ltrim($path, '/');
        if ($path === '/' || isset($routeTargets[$path])) {
            return $path;
        }

        $relative = ltrim($path, '/');
        $parts = explode('/', $relative, 2);
        if ($parts[0] === '') {
            return $path;
        }

        $installDir = basename(str_replace('\\', '/', rtrim(base_path(), '/')));
        if ($installDir !== '' && $parts[0] === $installDir) {
            $candidate = count($parts) === 2 ? '/' . ltrim($parts[1], '/') : '/';
            if ($this->isExportTargetCandidate($candidate, $routeTargets)) {
                return $candidate;
            }
        }

        if (count($parts) === 2) {
            $candidate = '/' . ltrim($parts[1], '/');
            if ($this->isExportTargetCandidate($candidate, $routeTargets)) {
                return $candidate;
            }
        }

        return $path;
    }


    /** @param array<string,string> $routeTargets */
    private function isExportTargetCandidate(string $candidate, array $routeTargets): bool
    {
        if ($candidate === '/' || isset($routeTargets[$candidate])) {
            return true;
        }
        $candidateRelative = ltrim($candidate, '/');
        return is_file(base_path($candidateRelative))
            || str_starts_with($candidateRelative, 'frontend/')
            || str_starts_with($candidateRelative, 'storage/media/');
    }

    private function stripRuntimeBasePath(string $path): string
    {
        $basePath = function_exists('app_base_path') ? (string) app_base_path() : '';
        if ($basePath !== '' && str_starts_with($path, rtrim($basePath, '/') . '/')) {
            $path = substr($path, strlen(rtrim($basePath, '/')));
        }
        return $path === '' ? '/' : $path;
    }

    private function relativePath(string $fromOutputPath, string $toPublicPath): string
    {
        $fromDir = trim(str_replace('\\', '/', dirname($fromOutputPath)), '.');
        $to = array_values(array_filter(explode('/', trim(str_replace('\\', '/', $toPublicPath), '/')), static fn(string $part): bool => $part !== ''));
        $from = $fromDir === '' || $fromDir === '/' ? [] : array_values(array_filter(explode('/', trim($fromDir, '/')), static fn(string $part): bool => $part !== ''));
        while ($from !== [] && $to !== [] && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }
        $relative = array_merge(array_fill(0, count($from), '..'), $to);
        return $relative === [] ? './' : implode('/', $relative);
    }

    private function removeDirectory(string $dir): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }

    private function createZipArchive(string $releaseDir, string $publicDir): ?string
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->warnings[] = 'Extension ZipArchive indisponible: static-export.zip non généré.';
            return null;
        }
        $zipPath = $releaseDir . '/static-export.zip';
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $this->warnings[] = 'Impossible de créer static-export.zip.';
            return null;
        }
        $deny = '#(^|/)(\.env|node_modules|tools|database|ops|storage/database|storage/logs|storage/cache|backups?|\.git)(/|$)|\.(sqlite|sqlite-wal|sqlite-shm|sqlite-journal|log|bak|tmp)$#i';
        $baseLen = strlen(rtrim($publicDir, DIRECTORY_SEPARATOR)) + 1;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($publicDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), $baseLen));
            if ($rel === '' || preg_match($deny, $rel)) {
                continue;
            }
            $zip->addFile($file->getPathname(), $rel);
        }
        $zip->close();
        return is_file($zipPath) ? $zipPath : null;
    }

    private function cmsVersion(): ?string
    {
        $path = base_path('config/release.json');
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? (string) ($data['technical_version'] ?? $data['version'] ?? $data['release'] ?? '') ?: null : null;
    }
}
