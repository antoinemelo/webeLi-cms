<?php

declare(strict_types=1);

namespace App\Application\Frontend;

use App\Core\Response;

final class PublicApiDocsController
{
    /** @var array<string,string> */
    private const PUBLIC_EXAMPLES = [
        'headless-next' => 'examples/headless-next/README.md',
        'headless-nuxt' => 'examples/headless-nuxt/README.md',
        'headless-astro' => 'examples/headless-astro/README.md',
        'headless-vanilla' => 'examples/headless-vanilla/README.md',
    ];

    /** @var array<string,string> */
    private const PUBLIC_FILES = [
        'index.html' => 'text/html; charset=utf-8',
        'openapi.v1.json' => 'application/json; charset=utf-8',
        'openapi.v1.yaml' => 'application/yaml; charset=utf-8',
        'quickstart.md' => 'text/markdown; charset=utf-8',
        'authentication.md' => 'text/markdown; charset=utf-8',
        'errors.md' => 'text/markdown; charset=utf-8',
        'examples.md' => 'text/markdown; charset=utf-8',
    ];

    public function index(): Response
    {
        return $this->show('index.html');
    }

    public function discovery(): Response
    {
        $base = url_path(current_site_base_path() . '/api/v1');
        return Response::success([
            'name' => 'DEC CMS Public Headless API',
            'status' => 'available',
            'base_path' => $base,
            'health' => $base . '/health',
            'openapi' => [
                'json' => $base . '/openapi.json',
                'yaml' => $base . '/openapi.yaml',
            ],
        ], 'public.discovery.v1', [], 200, [
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    public function openApiJson(): Response
    {
        return $this->show('openapi.v1.json');
    }

    public function openApiYaml(): Response
    {
        return $this->show('openapi.v1.yaml');
    }


    public function example(string $example): Response
    {
        $example = trim($example);
        if (!isset(self::PUBLIC_EXAMPLES[$example])) {
            return Response::html('<h1>404</h1><p>Exemple headless introuvable.</p>', 404);
        }

        $path = base_path(self::PUBLIC_EXAMPLES[$example]);
        if (!is_file($path)) {
            return Response::html('<h1>404</h1><p>Exemple headless introuvable.</p>', 404);
        }

        $body = file_get_contents($path);
        if ($body === false) {
            return Response::html('<h1>500</h1><p>Exemple headless indisponible.</p>', 500);
        }

        return new Response(200, $body, [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function show(string $file): Response
    {
        $file = basename($file);
        if (!isset(self::PUBLIC_FILES[$file])) {
            return Response::html('<h1>404</h1><p>Documentation publique introuvable.</p>', 404);
        }

        $path = base_path('docs/public-api/' . $file);
        if (!is_file($path)) {
            return Response::html('<h1>404</h1><p>Documentation publique introuvable.</p>', 404);
        }

        $body = file_get_contents($path);
        if ($body === false) {
            return Response::html('<h1>500</h1><p>Documentation publique indisponible.</p>', 500);
        }

        return new Response(200, $body, [
            'Content-Type' => self::PUBLIC_FILES[$file],
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
