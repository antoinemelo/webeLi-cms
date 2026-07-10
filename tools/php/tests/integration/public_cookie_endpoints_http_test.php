<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "[SKIP] pdo_sqlite unavailable\n";
    exit(0);
}
if (!function_exists('proc_open')) {
    echo "[SKIP] proc_open unavailable\n";
    exit(0);
}

$h = new TestHarness();

/** @return array{dir:string, files:array<string,string>} */
function backup_sqlite_databases(): array
{
    $backupDir = sys_get_temp_dir() . '/amcms-http-cookie-backup-' . bin2hex(random_bytes(6));
    if (!mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
        throw new RuntimeException('Unable to create temporary database backup directory.');
    }
    $files = [];
    foreach (glob(base_path('storage/database/*.sqlite')) ?: [] as $source) {
        $target = $backupDir . '/' . basename($source);
        if (!copy($source, $target)) {
            throw new RuntimeException('Unable to backup database: ' . $source);
        }
        $files[$source] = $target;
    }
    return ['dir' => $backupDir, 'files' => $files];
}

/** @param array{dir:string, files:array<string,string>} $backup */
function restore_sqlite_databases(array $backup): void
{
    foreach ($backup['files'] as $source => $target) {
        if (is_file($target)) {
            copy($target, $source);
        }
    }
    test_remove_tree($backup['dir']);
}

/** @return array{status:int, headers:array<string,string>, body:string, json:array<string,mixed>} */
function http_request(string $url, string $method = 'GET', ?string $body = null, array $headers = []): array
{
    $headerLines = array_merge(['Accept: application/json'], $headers);
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'content' => $body ?? '',
            'ignore_errors' => true,
            'timeout' => 5,
        ],
    ]);

    $responseBody = file_get_contents($url, false, $context);
    $rawHeaders = $http_response_header ?? [];
    $status = 0;
    $parsedHeaders = [];
    foreach ($rawHeaders as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
            $status = (int) $matches[1];
            continue;
        }
        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $parsedHeaders[strtolower(trim($name))] = trim($value);
        }
    }

    $json = json_decode(is_string($responseBody) ? $responseBody : '', true);
    return [
        'status' => $status,
        'headers' => $parsedHeaders,
        'body' => is_string($responseBody) ? $responseBody : '',
        'json' => is_array($json) ? $json : [],
    ];
}

function find_free_port(): int
{
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (is_resource($socket)) {
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        if (is_string($name) && str_contains($name, ':')) {
            $port = (int) substr(strrchr($name, ':'), 1);
            if ($port > 0) {
                return $port;
            }
        }
    }
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $port = random_int(30000, 45000);
        $socket = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
        if (is_resource($socket)) {
            fclose($socket);
            return $port;
        }
    }
    echo "[SKIP] TCP bind unavailable for PHP built-in server\n";
    exit(0);
}

function wait_for_server(int $port): void
{
    $deadline = microtime(true) + 5.0;
    do {
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 0.15);
        if (is_resource($socket)) {
            fclose($socket);
            return;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('PHP built-in server did not start.');
}

function stop_server(mixed $process): void
{
    if (!is_resource($process)) {
        return;
    }
    $status = proc_get_status($process);
    if (($status['running'] ?? false) === true) {
        proc_terminate($process, 15);
        usleep(100000);
        $status = proc_get_status($process);
        if (($status['running'] ?? false) === true) {
            proc_terminate($process, 9);
        }
    }
    proc_close($process);
}

$backup = backup_sqlite_databases();
$server = null;
$pipes = [];
try {
    $port = find_free_port();
    $command = [
        PHP_BINARY,
        '-S',
        '127.0.0.1:' . $port,
        '-t',
        base_path('backend/public'),
        base_path('backend/public/index.php'),
    ];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $server = proc_open($command, $descriptors, $pipes, base_path());
    if (!is_resource($server)) {
        throw new RuntimeException('Unable to start PHP built-in server.');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    wait_for_server($port);

    $appConfig = require base_path('backend/config/app.php');
    $basePath = rtrim((string) ($appConfig['base_path'] ?? ''), '/');
    $baseUrl = 'http://127.0.0.1:' . $port . $basePath;

    $configResponse = http_request($baseUrl . '/api/v1/cookies/config?lang=fr');
    $h->assertSame(200, $configResponse['status'], 'anonymous GET /api/v1/cookies/config returns 200');
    $h->assertSame('public.cookies.config.v1', $configResponse['json']['meta']['contract'] ?? null, 'cookie config contract is returned');
    $h->assertTrue(array_key_exists('categories', $configResponse['json']['data'] ?? []), 'cookie config exposes public categories');
    $h->assertSame('nosniff', strtolower($configResponse['headers']['x-content-type-options'] ?? ''), 'security headers still apply on anonymous cookie config');
    $h->assertTrue(str_contains($configResponse['headers']['content-security-policy'] ?? '', "default-src 'none'"), 'API CSP still applies on anonymous cookie config');
    $h->assertTrue(str_contains(strtolower($configResponse['headers']['cache-control'] ?? ''), 'no-store'), 'API no-store cache header still applies on anonymous cookie config');

    $payload = json_encode([
        'data' => [
            'consent_uid' => 'http-cookie-test-' . bin2hex(random_bytes(4)),
            'action' => 'save_choices',
            'choices' => [
                'necessary' => true,
                'statistics' => false,
                'marketing' => false,
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $consentResponse = http_request(
        $baseUrl . '/api/v1/cookies/consent?lang=fr',
        'POST',
        $payload,
        ['Content-Type: application/json']
    );
    $h->assertSame(200, $consentResponse['status'], 'anonymous POST /api/v1/cookies/consent returns 200');
    $h->assertSame(true, $consentResponse['json']['data']['logged'] ?? null, 'anonymous cookie consent is logged');
    $h->assertSame('public.cookies.log.v1', $consentResponse['json']['meta']['contract'] ?? null, 'cookie consent contract is returned');

    $invalidJsonResponse = http_request(
        $baseUrl . '/api/v1/cookies/consent?lang=fr',
        'POST',
        '{"data":',
        ['Content-Type: application/json']
    );
    $h->assertSame(422, $invalidJsonResponse['status'], 'anonymous cookie consent still validates malformed JSON');
    $h->assertSame('INVALID_JSON', $invalidJsonResponse['json']['error']['code'] ?? null, 'malformed JSON keeps the public API validation error contract');

    $mediaResponse = http_request($baseUrl . '/api/v1/media?limit=10');
    $h->assertSame(200, $mediaResponse['status'], 'anonymous GET /api/v1/media?limit=10 returns 200');
    $h->assertSame('public.media.index.v1', $mediaResponse['json']['meta']['contract'] ?? null, 'anonymous media index contract is returned');
    $h->assertTrue(array_key_exists('pagination', $mediaResponse['json']['data'] ?? []), 'anonymous media index exposes pagination');
    $h->assertSame(10, $mediaResponse['json']['data']['pagination']['limit'] ?? null, 'anonymous media index keeps requested limit');

    $typedMediaResponse = http_request($baseUrl . '/api/v1/media?type=image&limit=10');
    $h->assertSame(200, $typedMediaResponse['status'], 'anonymous GET /api/v1/media?type=image&limit=10 returns 200');
    $h->assertSame('public.media.index.v1', $typedMediaResponse['json']['meta']['contract'] ?? null, 'anonymous typed media index contract is returned');
    $h->assertSame('image', $typedMediaResponse['json']['meta']['media_type'] ?? null, 'anonymous typed media index keeps the type alias filter');
    $h->assertTrue(array_key_exists('pagination', $typedMediaResponse['json']['data'] ?? []), 'anonymous typed media index exposes pagination');

    $protectedResponse = http_request($baseUrl . '/api/v1/content');
    $h->assertSame(401, $protectedResponse['status'], 'anonymous GET /api/v1/content remains protected');
    $h->assertSame('AUTH_REQUIRED', $protectedResponse['json']['error']['code'] ?? null, 'protected endpoint returns auth-required error');
    $h->assertTrue(str_contains($protectedResponse['headers']['www-authenticate'] ?? '', 'Bearer'), 'protected endpoint still advertises Bearer authentication');
} finally {
    stop_server($server);
    foreach ([1, 2] as $idx) {
        if (isset($pipes[$idx]) && is_resource($pipes[$idx])) {
            fclose($pipes[$idx]);
        }
    }
    restore_sqlite_databases($backup);
}

exit($h->finish('INTEGRATION anonymous public cookie HTTP endpoints'));
