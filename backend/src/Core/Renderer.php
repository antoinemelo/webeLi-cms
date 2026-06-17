<?php

declare(strict_types=1);

namespace App\Core;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class Renderer
{
    public function __construct(
        private readonly string $basePath,
        private readonly array $globals = [],
        private readonly array $options = [],
    ) {}

    public function render(string $template, array $data = []): string
    {
        if (!class_exists(Environment::class) || !class_exists(FilesystemLoader::class)) {
            return NativeHtmlRenderer::render($template, $data);
        }

        if (!is_dir($this->basePath)) {
            throw new \RuntimeException('Répertoire de templates introuvable: ' . $this->basePath);
        }

        $loader = new FilesystemLoader($this->basePath);
        $cachePath = $this->options['cache_path'] ?? null;
        if (is_string($cachePath) && $cachePath !== '') {
            if (!is_dir($cachePath)) {
                @mkdir($cachePath, 0775, true);
            }
            if (!is_dir($cachePath) || !is_writable($cachePath)) {
                throw new \RuntimeException('Le cache Twig n’est pas accessible en écriture: ' . $cachePath);
            }
        }

        $twig = new Environment($loader, [
            'cache' => $this->options['cache'] ?? false,
            'debug' => (bool) ($this->options['debug'] ?? false),
            'autoescape' => 'html',
            'strict_variables' => (bool) ($this->options['strict_variables'] ?? false),
        ]);

        $twig->addFunction(new TwigFunction('url', fn(string $path = '/') => url_path($path)));
        $twig->addFunction(new TwigFunction('localized_url', fn(string $path = '/', string $languageCode = '') => localized_path($path, $languageCode)));
        $twig->addFunction(new TwigFunction('absolute_url', fn(string $path = '/', string $baseUrl = '') => absolute_url($path, $baseUrl)));
        $twig->addFunction(new TwigFunction('asset', fn(string $path = '') => asset_path($path)));
        $twig->addFilter(new TwigFilter('markdown_to_html', fn(string $markdown = '') => MarkdownRenderer::toHtml($markdown), ['is_safe' => ['html']]));

        foreach ($this->globals as $key => $value) {
            $twig->addGlobal($key, $value);
        }

        return $twig->render(ltrim($template, '/') . '.twig', $data);
    }
}
