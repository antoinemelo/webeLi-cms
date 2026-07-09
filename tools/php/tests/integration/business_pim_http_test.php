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

function pim_http_free_port(): int
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
    echo "[SKIP] TCP bind unavailable for PHP built-in server\n";
    exit(0);
}

function pim_http_wait_for_server(int $port): void
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

function pim_http_stop_server(mixed $process): void
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

/** @return array{status:int, body:string, json:array<string,mixed>} */
function pim_http_request(string $url): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Accept: application/json\r\n",
            'ignore_errors' => true,
            'timeout' => 8,
        ],
    ]);
    $body = file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
            $status = (int) $matches[1];
        }
    }
    $json = json_decode(is_string($body) ? $body : '', true);
    return [
        'status' => $status,
        'body' => is_string($body) ? $body : '',
        'json' => is_array($json) ? $json : [],
    ];
}

$server = null;
$pipes = [];
try {
    $port = pim_http_free_port();
    $server = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', base_path('backend/public'), base_path('backend/public/index.php')],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        base_path()
    );
    if (!is_resource($server)) {
        throw new RuntimeException('Unable to start PHP built-in server.');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    pim_http_wait_for_server($port);

    $appConfig = require base_path('backend/config/app.php');
    $basePath = rtrim((string) ($appConfig['base_path'] ?? ''), '/');
    $baseUrl = 'http://127.0.0.1:' . $port . $basePath;

    foreach ([
        '/admin/api/business/pim/sellable-variants',
        '/admin/api/business/pim/products/1/assets',
        '/admin/api/business/pim/products/1/completeness',
        '/admin/api/business/pim/variants/1/sellable-snapshot',
    ] as $path) {
        $response = pim_http_request($baseUrl . $path);
        $h->assertTrue(in_array($response['status'], [401, 403], true), 'admin PIM route is declared and protected: ' . $path);
        $h->assertTrue($response['status'] !== 500, 'admin PIM route does not return 500 when unauthenticated: ' . $path);
    }

    foreach ([
        '/api/v1/business/pim/products',
        '/api/v1/business/pim/attributes',
        '/api/v1/business/pim/sellable-variants',
        '/api/v1/business/sellable-variant-snapshot',
        '/api/v1/pim/products',
        '/api/v1/pim/attributes',
        '/api/v1/pim/sellable-variant-snapshot',
    ] as $path) {
        $response = pim_http_request($baseUrl . $path);
        $h->assertTrue($response['status'] !== 200, 'public PIM route is not exposed anonymously: ' . $path);
        $h->assertTrue($response['status'] !== 500, 'public PIM route does not fail with 500: ' . $path);
    }
} finally {
    foreach ([1, 2] as $pipeIndex) {
        if (isset($pipes[$pipeIndex]) && is_resource($pipes[$pipeIndex])) {
            fclose($pipes[$pipeIndex]);
        }
    }
    pim_http_stop_server($server);
}

exit($h->finish('INTEGRATION business PIM HTTP protections'));
