<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Application\Frontend\PublicApiDocsController;
use App\Core\Router;

$h = new TestHarness();
$routes = require base_path('backend/routes/api.php');
$router = new Router();

foreach (['/api/v1', '/api/v1/openapi.json', '/api/v1/openapi.yaml', '/api/v1/cookies/config', '/api/v1/forms/contact'] as $path) {
    $h->assertTrue($router->match('GET', $path, $routes) !== null, 'public API route is registered: ' . $path);
}
$h->assertTrue($router->match('POST', '/api/v1/forms/contact/submit', $routes) !== null, 'public API route is registered: /api/v1/forms/contact/submit');
$h->assertTrue($router->match('POST', '/api/v1/cookies/consent', $routes) !== null, 'public API route is registered: /api/v1/cookies/consent');

$controller = new PublicApiDocsController();
$discovery = $controller->discovery();
$payload = json_decode($discovery->body(), true);
$h->assertSame(200, $discovery->status(), 'API discovery responds successfully');
$h->assertSame('public.discovery.v1', $payload['meta']['contract'] ?? null, 'API discovery contract is explicit');
$h->assertTrue(str_ends_with((string) ($payload['data']['openapi']['json'] ?? ''), '/api/v1/openapi.json'), 'discovery exposes JSON specification');
$h->assertTrue(str_ends_with((string) ($payload['data']['openapi']['yaml'] ?? ''), '/api/v1/openapi.yaml'), 'discovery exposes YAML specification');

$_ENV['APP_BASE_PATH'] = '/mod';
$prefixedPayload = json_decode($controller->discovery()->body(), true);
$h->assertSame('/mod/api/v1', $prefixedPayload['data']['base_path'] ?? null, 'discovery preserves the production application base path');
$h->assertSame('/mod/api/v1/openapi.json', $prefixedPayload['data']['openapi']['json'] ?? null, 'JSON specification preserves the production application base path');
$h->assertSame('/mod/api/v1/openapi.yaml', $prefixedPayload['data']['openapi']['yaml'] ?? null, 'YAML specification preserves the production application base path');
unset($_ENV['APP_BASE_PATH']);

$json = $controller->openApiJson();
$jsonPayload = json_decode($json->body(), true);
$h->assertSame(200, $json->status(), 'OpenAPI JSON is served');
$h->assertTrue(str_contains((string) ($json->headers()['Content-Type'] ?? ''), 'application/json'), 'OpenAPI JSON MIME is correct');
$h->assertSame('3.1.0', $jsonPayload['openapi'] ?? null, 'OpenAPI JSON payload is valid');

$publicCookieOperations = [
    ['/api/v1/cookies/config', 'get'],
    ['/api/v1/cookies/consent', 'post'],
];
foreach ($publicCookieOperations as [$path, $method]) {
    $operation = $jsonPayload['paths'][$path][$method] ?? null;
    $h->assertSame([], $operation['security'] ?? null, 'canonical OpenAPI keeps cookies endpoint anonymous: ' . strtoupper($method) . ' ' . $path);
}
$h->assertSame([['BearerAuth' => []]], $jsonPayload['paths']['/api/v1/content']['get']['security'] ?? null, 'canonical OpenAPI keeps protected content endpoint behind Bearer auth');

$referenceOpenApi = json_decode((string) file_get_contents(base_path('docs/reference/contracts/public-api/openapi.v1.json')), true);
foreach ($publicCookieOperations as [$path, $method]) {
    $operation = $referenceOpenApi['paths'][$path][$method] ?? null;
    $h->assertSame([], $operation['security'] ?? null, 'reference OpenAPI keeps cookies endpoint anonymous: ' . strtoupper($method) . ' ' . $path);
}

$yaml = $controller->openApiYaml();
$h->assertSame(200, $yaml->status(), 'OpenAPI YAML is served');
$h->assertTrue(str_contains((string) ($yaml->headers()['Content-Type'] ?? ''), 'application/yaml'), 'OpenAPI YAML MIME is correct');
$h->assertTrue(str_contains($yaml->body(), 'openapi: "3.1.0"'), 'OpenAPI YAML payload is valid');

$config = require base_path('backend/config/app.php');
$publicPatterns = $config['public_api_auth']['public_paths'] ?? [];
foreach (['/api/v1/health', '/api/v1/openapi.json', '/api/v1/openapi.yaml', '/api/v1/cookies/config', '/api/v1/cookies/consent', '/api/v1/forms/contact', '/api/v1/forms/contact/submit'] as $path) {
    $public = array_filter($publicPatterns, static fn(string $pattern): bool => preg_match($pattern, $path) === 1);
    $h->assertTrue($public !== [], 'public endpoint does not require a Bearer token: ' . $path);
}

$htaccess = (string) file_get_contents(base_path('.htaccess'));
$openApiException = strpos($htaccess, 'api/v1/openapi\\.(json|yaml)');
$genericJsonBlock = strpos($htaccess, 'jsonl?|zip');
$h->assertTrue($openApiException !== false, 'Apache explicitly routes public OpenAPI JSON/YAML through PHP');
$h->assertTrue($genericJsonBlock !== false && $openApiException < $genericJsonBlock, 'OpenAPI Apache exception precedes the generic JSON/YAML deny rule');

exit($h->finish('UNIT public API discovery and OpenAPI exposure'));
