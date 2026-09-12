<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';

function sale_http_prepare_isolated_databases(): ?string
{
    $source = $_ENV['CMS_TEST_PRISTINE_DATABASE_DIR']
        ?? $_SERVER['CMS_TEST_PRISTINE_DATABASE_DIR']
        ?? getenv('CMS_TEST_PRISTINE_DATABASE_DIR');
    if (!is_string($source) || !is_dir($source)) {
        return null;
    }

    $target = sys_get_temp_dir() . '/amcms-http-sale-databases-' . bin2hex(random_bytes(6));
    if (!mkdir($target, 0775, true) && !is_dir($target)) {
        throw new RuntimeException('Unable to create isolated Sale HTTP database directory.');
    }
    foreach (glob(rtrim($source, DIRECTORY_SEPARATOR) . '/*.sqlite') ?: [] as $database) {
        if (!copy($database, $target . '/' . basename($database))) {
            test_remove_tree($target);
            throw new RuntimeException('Unable to copy pristine test database: ' . $database);
        }
    }

    putenv('CMS_DATABASE_DIR=' . $target);
    $_ENV['CMS_DATABASE_DIR'] = $target;
    $_SERVER['CMS_DATABASE_DIR'] = $target;
    return $target;
}

$saleHttpIsolatedDatabaseDir = sale_http_prepare_isolated_databases();

foreach ([
    'APP_ENV' => 'test',
    'APP_PUBLIC_API_MODULE_ROUTES' => '1',
    'APP_PUBLIC_API_AUTH_ENABLED' => '1',
    'APP_PUBLIC_API_CORS_ENABLED' => '1',
] as $key => $value) {
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "[SKIP] pdo_sqlite unavailable\n";
    exit(0);
}
if (!function_exists('proc_open')) {
    echo "[SKIP] proc_open unavailable\n";
    exit(0);
}

use App\Core\Database;

$h = new TestHarness();

function sale_http_database_dir(): string
{
    $override = $_ENV['CMS_DATABASE_DIR'] ?? $_SERVER['CMS_DATABASE_DIR'] ?? getenv('CMS_DATABASE_DIR');
    if (is_string($override) && trim($override) !== '') {
        return rtrim($override, DIRECTORY_SEPARATOR);
    }
    return base_path('storage/database');
}

/** @return array{dir:string, files:array<string,string>} */
function backup_sale_http_databases(): array
{
    $backupDir = sys_get_temp_dir() . '/amcms-http-sale-backup-' . bin2hex(random_bytes(6));
    if (!mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
        throw new RuntimeException('Unable to create temporary database backup directory.');
    }
    $files = [];
    foreach (glob(sale_http_database_dir() . '/*.sqlite') ?: [] as $source) {
        $target = $backupDir . '/' . basename($source);
        if (!copy($source, $target)) {
            throw new RuntimeException('Unable to backup database: ' . $source);
        }
        $files[$source] = $target;
    }
    return ['dir' => $backupDir, 'files' => $files];
}

/** @param array{dir:string, files:array<string,string>} $backup */
function restore_sale_http_databases(array $backup): void
{
    foreach ($backup['files'] as $source => $target) {
        if (is_file($target)) {
            copy($target, $source);
        }
    }
    test_remove_tree($backup['dir']);
}

/** @return array{status:int, headers:array<string,string>, body:string, json:array<string,mixed>} */
function sale_http_request(string $url, string $method = 'GET', ?string $body = null, array $headers = []): array
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

function sale_find_free_port(): int
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

function sale_wait_for_server(int $port): void
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

function sale_stop_server(mixed $process): void
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

function sale_json_body(array $payload): string
{
    return json_encode(['data' => $payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
}

$backup = backup_sale_http_databases();
$server = null;
$pipes = [];

try {
    $coreDb = new Database(sale_http_database_dir() . '/core.sqlite');
    $saleDb = new Database(sale_http_database_dir() . '/sale.sqlite');
    $iamDb = new Database(sale_http_database_dir() . '/iam.sqlite');
    $coreDb->run(
        "INSERT INTO modules(module_key, name, version, provider_class, is_system, is_installed, is_enabled, updated_at)
         VALUES('sale', 'Ventes', '0.1.0', 'App\\Modules\\Sale\\SaleModuleProvider', 1, 1, 1, CURRENT_TIMESTAMP)
         ON CONFLICT(module_key) DO UPDATE SET
             provider_class = excluded.provider_class,
             is_installed = 1,
             is_enabled = 1,
             updated_at = CURRENT_TIMESTAMP"
    );
    $saleDb->run(
        "INSERT INTO sale_channels(
            site_id,code,name,channel_type,channel_kind,is_default,status,currency,
            default_language,tax_mode,price_tax_included,is_public
         ) VALUES(1,'web-main','Boutique web de test','ecommerce','storefront',1,'active','CHF','fr','tax_included',1,1)
         ON CONFLICT(site_id,code) DO UPDATE SET
            channel_type='ecommerce',channel_kind='storefront',status='active',currency='CHF',
            default_language='fr',tax_mode='tax_included',price_tax_included=1,is_public=1,archived_at=NULL"
    );
    $channelId = (int) ($saleDb->one(
        "SELECT id FROM sale_channels WHERE site_id=1 AND code='web-main' LIMIT 1"
    )['id'] ?? 0);
    if ($channelId < 1) {
        throw new RuntimeException('Unable to prepare the public Sale test channel.');
    }
    $coreDb->run(
        "INSERT INTO cms_shop_configurations(
            site_id,language_code,channel_id,channel_code,status,currency,route_path,theme_key,
            menu_key,menu_label,cart_visible,draft_json,published_json,config_version,published_version
         ) VALUES(1,'fr',?,'web-main','active','CHF','/shop','default','main','Boutique',1,'{}','{}',1,1)
         ON CONFLICT(site_id,language_code) DO UPDATE SET
            channel_id=excluded.channel_id,channel_code='web-main',status='active',currency='CHF',
            route_path='/shop',published_json=COALESCE(cms_shop_configurations.published_json,'{}'),
            published_version=COALESCE(cms_shop_configurations.published_version,1)",
        [$channelId]
    );

    $contentToken = 'sale-http-content-' . bin2hex(random_bytes(12));
    $routeOnlyToken = 'sale-http-routes-' . bin2hex(random_bytes(12));
    $iamDb->run(
        "INSERT INTO api_tokens(name, token_hash, site_id, scopes, is_active) VALUES(?, ?, 1, 'content:read', 1)",
        ['sale-http-content', hash('sha256', $contentToken)]
    );
    $iamDb->run(
        "INSERT INTO api_tokens(name, token_hash, site_id, scopes, is_active) VALUES(?, ?, 1, 'routes:read', 1)",
        ['sale-http-routes', hash('sha256', $routeOnlyToken)]
    );

    $port = sale_find_free_port();
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
    $serverEnvironment = getenv();
    $serverEnvironment = is_array($serverEnvironment) ? $serverEnvironment : [];
    $serverEnvironment['APP_ENV'] = 'test';
    $serverEnvironment['APP_PUBLIC_API_MODULE_ROUTES'] = '1';
    $serverEnvironment['APP_PUBLIC_API_AUTH_ENABLED'] = '1';
    $serverEnvironment['APP_PUBLIC_API_CORS_ENABLED'] = '1';
    $serverEnvironment['CMS_DATABASE_DIR'] = sale_http_database_dir();
    $server = proc_open($command, $descriptors, $pipes, base_path(), $serverEnvironment);
    if (!is_resource($server)) {
        throw new RuntimeException('Unable to start PHP built-in server.');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    sale_wait_for_server($port);

    $appConfig = require base_path('backend/config/app.php');
    $basePath = rtrim((string) ($appConfig['base_path'] ?? ''), '/');
    $baseUrl = 'http://127.0.0.1:' . $port . $basePath;
    $origin = 'https://webe.li';

    foreach (['PATCH', 'DELETE'] as $preflightMethod) {
        $preflight = sale_http_request(
            $baseUrl . '/api/v1/sale/channels/web-main/cart/test-token/lines/1',
            'OPTIONS',
            null,
            [
                'Origin: ' . $origin,
                'Access-Control-Request-Method: ' . $preflightMethod,
                'Access-Control-Request-Headers: Content-Type, Idempotency-Key',
            ]
        );
        $h->assertSame(204, $preflight['status'], 'CORS preflight accepts ' . $preflightMethod . ' on public Sale lines');
        $h->assertTrue(str_contains($preflight['headers']['access-control-allow-methods'] ?? '', $preflightMethod), 'preflight exposes ' . $preflightMethod);
        $h->assertTrue(str_contains($preflight['headers']['access-control-allow-headers'] ?? '', 'Idempotency-Key'), 'preflight exposes Idempotency-Key');
    }

    $bootstrap = sale_http_request($baseUrl . '/api/v1/sale/channels/web-main/bootstrap?lang=fr', 'GET', null, ['Origin: ' . $origin]);
    $h->assertSame(200, $bootstrap['status'], 'anonymous GET Sale bootstrap returns 200');
    $h->assertSame('public.sale.channels.bootstrap.v1', $bootstrap['json']['meta']['contract'] ?? null, 'Sale bootstrap contract is returned');
    $h->assertSame($origin, $bootstrap['headers']['access-control-allow-origin'] ?? null, 'Sale bootstrap returns CORS origin');

    $cart = sale_http_request($baseUrl . '/api/v1/sale/channels/web-main/cart?lang=fr', 'POST', sale_json_body([]), ['Content-Type: application/json']);
    $h->assertSame(201, $cart['status'], 'anonymous POST Sale cart creates a cart');
    $token = (string) ($cart['json']['data']['cart']['token'] ?? '');
    $h->assertTrue(strlen($token) >= 32, 'Sale cart returns an opaque token');

    $variant = (new Database(sale_http_database_dir() . '/business.sqlite'))->one("SELECT id FROM business_product_variants WHERE sku = 'DEMO-GOURDE-BLEU' LIMIT 1");
    $invalid = sale_http_request(
        $baseUrl . '/api/v1/sale/channels/web-main/cart/' . rawurlencode($token) . '/lines',
        'POST',
        sale_json_body(['sellable_id' => (int) ($variant['id'] ?? 0), 'quantity' => 0]),
        ['Content-Type: application/json']
    );
    $h->assertSame(422, $invalid['status'], 'invalid Sale line payload returns 422');
    $h->assertSame('VALIDATION_FAILED', $invalid['json']['error']['code'] ?? null, 'invalid Sale line keeps validation error contract');

    $idempotencyKey = 'sale-http-line-' . bin2hex(random_bytes(4));
    $line = sale_http_request(
        $baseUrl . '/api/v1/sale/channels/web-main/cart/' . rawurlencode($token) . '/lines',
        'POST',
        sale_json_body(['sellable_id' => (int) ($variant['id'] ?? 0), 'quantity' => 1]),
        ['Content-Type: application/json', 'Idempotency-Key: ' . $idempotencyKey]
    );
    $h->assertSame(201, $line['status'], 'anonymous POST Sale line succeeds with Idempotency-Key header');
    $lineId = (int) ($line['json']['data']['line']['id'] ?? 0);
    $h->assertTrue($lineId > 0, 'Sale line response includes line id');
    $keyRow = $saleDb->one('SELECT id FROM sale_idempotency_keys WHERE key_hash = ? LIMIT 1', [hash('sha256', $idempotencyKey)]);
    $h->assertTrue($keyRow !== null, 'Idempotency-Key header is persisted by public Sale');

    $patched = sale_http_request(
        $baseUrl . '/api/v1/sale/channels/web-main/cart/' . rawurlencode($token) . '/lines/' . $lineId,
        'PATCH',
        sale_json_body(['quantity' => 2]),
        ['Content-Type: application/json']
    );
    $h->assertSame(200, $patched['status'], 'anonymous PATCH Sale line succeeds');
    $h->assertSame(2, $patched['json']['data']['line']['quantity'] ?? null, 'PATCH Sale line changes quantity');

    $deleted = sale_http_request($baseUrl . '/api/v1/sale/channels/web-main/cart/' . rawurlencode($token) . '/lines/' . $lineId, 'DELETE');
    $h->assertSame(200, $deleted['status'], 'anonymous DELETE Sale line succeeds');
    $h->assertSame(true, $deleted['json']['data']['deleted'] ?? null, 'DELETE Sale line returns deleted flag');

    sale_http_request(
        $baseUrl . '/api/v1/sale/channels/web-main/cart/' . rawurlencode($token) . '/lines',
        'POST',
        sale_json_body(['sellable_id' => (int) ($variant['id'] ?? 0), 'quantity' => 1, 'idempotency_key' => 'sale-http-line-again']),
        ['Content-Type: application/json']
    );
    $checkoutKey = 'sale-http-checkout-' . bin2hex(random_bytes(4));
    $checkout = sale_http_request(
        $baseUrl . '/api/v1/sale/channels/web-main/checkout',
        'POST',
        sale_json_body([
            'cart_token' => $token,
            'identity' => ['email' => 'http-guest@example.test', 'first_name' => 'HTTP', 'last_name' => 'Guest'],
            'billing_address' => ['line1' => 'Rue du Test 1', 'postal_code' => '1000', 'city' => 'Lausanne', 'country_code' => 'CH'],
            'shipping_same_as_billing' => true,
            'shipping_method' => ['code' => 'standard'],
            'payment' => ['code' => 'bank_transfer'],
            'terms_accepted' => true,
            'marketing_consent' => false,
        ]),
        ['Content-Type: application/json', 'Idempotency-Key: ' . $checkoutKey]
    );
    $h->assertSame(201, $checkout['status'], 'anonymous POST Sale checkout succeeds');
    $h->assertSame('ecommerce', $checkout['json']['data']['order']['source'] ?? null, 'Sale checkout creates ecommerce order');

    $protectedAnonymous = sale_http_request($baseUrl . '/api/v1/content');
    $h->assertSame(401, $protectedAnonymous['status'], 'anonymous protected public API request is rejected');

    $forbidden = sale_http_request($baseUrl . '/api/v1/content', 'GET', null, ['Authorization: Bearer ' . $routeOnlyToken]);
    $h->assertSame(403, $forbidden['status'], 'authenticated public API request without required scope is forbidden');

    $authenticated = sale_http_request($baseUrl . '/api/v1/content', 'GET', null, ['Authorization: Bearer ' . $contentToken]);
    $h->assertSame(200, $authenticated['status'], 'authenticated public API request with required scope succeeds');
} finally {
    sale_stop_server($server);
    foreach ([1, 2] as $idx) {
        if (isset($pipes[$idx]) && is_resource($pipes[$idx])) {
            fclose($pipes[$idx]);
        }
    }
    restore_sale_http_databases($backup);
    if (is_string($saleHttpIsolatedDatabaseDir)) {
        test_remove_tree($saleHttpIsolatedDatabaseDir);
    }
}

exit($h->finish('INTEGRATION public Sale HTTP contracts and CORS'));
