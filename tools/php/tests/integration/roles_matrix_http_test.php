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
function roles_matrix_backup_databases(): array
{
    $backupDir = sys_get_temp_dir() . '/amcms-roles-matrix-backup-' . bin2hex(random_bytes(6));
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
function roles_matrix_restore_databases(array $backup): void
{
    foreach ($backup['files'] as $source => $target) {
        if (is_file($target)) {
            copy($target, $source);
        }
    }
    test_remove_tree($backup['dir']);
}

function roles_matrix_free_port(): int
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

function roles_matrix_wait_for_server(int $port): void
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

function roles_matrix_stop_server(mixed $process): void
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

/** @return array{roles:array<string,int>, site_a:int, site_b:int} */
function roles_matrix_seed_users(): array
{
    $core = new PDO('sqlite:' . base_path('storage/database/core.sqlite'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $iam = new PDO('sqlite:' . base_path('storage/database/iam.sqlite'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $sites = $core->query('SELECT id FROM sites WHERE is_active = 1 ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
    if (count($sites) < 2) {
        throw new RuntimeException('P1-03 requires at least two active sites in the rebuilt fixture.');
    }
    $siteA = (int) $sites[0];
    $siteB = (int) $sites[1];

    $roles = [];
    foreach ($iam->query('SELECT id, role_key FROM iam_roles') as $row) {
        $roles[(string) $row['role_key']] = (int) $row['id'];
    }
    foreach (['super_admin', 'admin', 'editor', 'translator', 'publication', 'seo', 'user'] as $roleKey) {
        if (!isset($roles[$roleKey])) {
            throw new RuntimeException('Missing IAM role: ' . $roleKey);
        }
    }

    $ids = [
        'super_admin' => 9101,
        'admin' => 9102,
        'editor' => 9103,
        'translator' => 9104,
        'publication' => 9105,
        'seo' => 9106,
        'user' => 9107,
    ];
    $passwordHash = password_hash('RoleMatrix123!', PASSWORD_DEFAULT);
    $iam->exec('DELETE FROM iam_sessions WHERE user_id BETWEEN 9101 AND 9107');
    $iam->exec('DELETE FROM iam_audit_logs WHERE actor_user_id BETWEEN 9101 AND 9107');
    $iam->exec('DELETE FROM iam_user_site_roles WHERE user_id BETWEEN 9101 AND 9107');
    $iam->exec('DELETE FROM iam_user_roles WHERE user_id BETWEEN 9101 AND 9107');
    $iam->exec('DELETE FROM iam_users WHERE id BETWEEN 9101 AND 9107');

    $insertUser = $iam->prepare(
        'INSERT INTO iam_users(id, email, email_normalized, password_hash, first_name, last_name, locale, is_active, login_mode, created_at, updated_at)
         VALUES(:id, :email, :email_normalized, :password_hash, :first_name, :last_name, :locale, 1, :login_mode, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
    );
    $insertGlobalRole = $iam->prepare('INSERT INTO iam_user_roles(user_id, role_id) VALUES(:user_id, :role_id)');
    $insertSiteRole = $iam->prepare('INSERT INTO iam_user_site_roles(user_id, site_id, role_id, created_at) VALUES(:user_id, :site_id, :role_id, CURRENT_TIMESTAMP)');

    foreach ($ids as $roleKey => $userId) {
        $email = match ($roleKey) {
            'super_admin' => 'superadmin@example.test',
            'admin' => 'admin.role@example.test',
            default => $roleKey . '@example.test',
        };
        $insertUser->execute([
            'id' => $userId,
            'email' => $email,
            'email_normalized' => $email,
            'password_hash' => $passwordHash,
            'first_name' => 'P1-03',
            'last_name' => $roleKey,
            'locale' => 'fr-CH',
            'login_mode' => 'password',
        ]);
        if ($roleKey === 'editor') {
            $insertSiteRole->execute(['user_id' => $userId, 'site_id' => $siteA, 'role_id' => $roles[$roleKey]]);
        } else {
            $insertGlobalRole->execute(['user_id' => $userId, 'role_id' => $roles[$roleKey]]);
        }
    }

    return ['roles' => $roles, 'site_a' => $siteA, 'site_b' => $siteB];
}

final class RolesMatrixHttpClient
{
    /** @var array<string,string> */
    private array $cookies = [];

    public function __construct(private readonly string $baseUrl) {}

    /** @param array<string,string> $headers @return array{status:int, headers:array<string,list<string>>, body:string, json:array<string,mixed>} */
    public function request(string $path, string $method = 'GET', ?string $body = null, array $headers = []): array
    {
        $headerLines = ['Accept: application/json'];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        if ($this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $headerLines[] = 'Cookie: ' . implode('; ', $pairs);
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => $body ?? '',
                'ignore_errors' => true,
                'follow_location' => 0,
                'timeout' => 8,
            ],
        ]);

        $responseBody = file_get_contents($this->baseUrl . $path, false, $context);
        $rawHeaders = $http_response_header ?? [];
        $status = 0;
        $parsedHeaders = [];
        foreach ($rawHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
                $status = (int) $matches[1];
                continue;
            }
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $lower = strtolower(trim($name));
            $parsedHeaders[$lower] ??= [];
            $parsedHeaders[$lower][] = trim($value);
            if ($lower === 'set-cookie') {
                $cookie = explode(';', trim($value), 2)[0] ?? '';
                if (str_contains($cookie, '=')) {
                    [$cookieName, $cookieValue] = explode('=', $cookie, 2);
                    $this->cookies[trim($cookieName)] = trim($cookieValue);
                }
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

    public function csrfToken(): string
    {
        $response = $this->request('/admin/api/context');
        if ($response['status'] !== 200) {
            throw new RuntimeException('Unable to fetch admin context for CSRF token, status=' . $response['status']);
        }
        return (string) ($response['json']['data']['csrf_token'] ?? '');
    }
}

function roles_matrix_login(string $baseUrl, string $email): RolesMatrixHttpClient
{
    $client = new RolesMatrixHttpClient($baseUrl);
    $login = $client->request('/admin/login', 'GET', null, ['Accept' => 'text/html']);
    if ($login['status'] !== 200 || !preg_match('/name="_csrf"\s+value="([^"]+)"/', $login['body'], $matches)) {
        throw new RuntimeException('Unable to read login CSRF token for ' . $email);
    }
    $body = http_build_query([
        '_csrf' => html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'),
        'challenge' => 'password',
        'email' => $email,
        'password' => 'RoleMatrix123!',
    ]);
    $result = $client->request('/admin/login', 'POST', $body, [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Accept' => 'text/html',
    ]);
    if (!in_array($result['status'], [302, 303], true)) {
        throw new RuntimeException('Login failed for ' . $email . ', status=' . $result['status']);
    }
    return $client;
}

function roles_matrix_assert_not_500(TestHarness $h, array $response, string $message): void
{
    $h->assertTrue($response['status'] < 500, $message . ' does not return 500');
}

function roles_matrix_assert_allowed(TestHarness $h, array $response, string $message): void
{
    roles_matrix_assert_not_500($h, $response, $message);
    $h->assertTrue(!in_array($response['status'], [401, 403], true), $message . ' is authorized');
}

function roles_matrix_assert_forbidden(TestHarness $h, array $response, string $message): void
{
    roles_matrix_assert_not_500($h, $response, $message);
    $h->assertSame(403, $response['status'], $message . ' is forbidden');
}

$backup = roles_matrix_backup_databases();
$server = null;
$pipes = [];
try {
    $fixture = roles_matrix_seed_users();
    $siteA = $fixture['site_a'];
    $siteB = $fixture['site_b'];

    $port = roles_matrix_free_port();
    $command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', base_path('backend/public'), base_path('backend/public/index.php')];
    $server = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
    if (!is_resource($server)) {
        throw new RuntimeException('Unable to start PHP built-in server.');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    roles_matrix_wait_for_server($port);

    $appConfig = require base_path('backend/config/app.php');
    $basePath = rtrim((string) ($appConfig['base_path'] ?? ''), '/');
    $baseUrl = 'http://127.0.0.1:' . $port . $basePath;

    $superAdmin = roles_matrix_login($baseUrl, 'superadmin@example.test');
    roles_matrix_assert_allowed($h, $superAdmin->request('/admin/api/iam/users?site_id=' . $siteA), 'super_admin GET /admin/api/iam/users');

    $admin = roles_matrix_login($baseUrl, 'admin.role@example.test');
    roles_matrix_assert_allowed($h, $admin->request('/admin/api/configuration?site_id=' . $siteA), 'admin GET /admin/api/configuration');
    roles_matrix_assert_forbidden($h, $admin->request('/admin/api/iam/users?site_id=' . $siteA), 'admin GET /admin/api/iam/users');

    $editor = roles_matrix_login($baseUrl, 'editor@example.test');
    roles_matrix_assert_allowed($h, $editor->request('/admin/api/entries?site_id=' . $siteA), 'site-scoped editor GET own-site entries');
    roles_matrix_assert_forbidden($h, $editor->request('/admin/api/entries?site_id=' . $siteB), 'site-scoped editor GET other-site entries');
    roles_matrix_assert_forbidden($h, $editor->request('/admin/api/iam/users?site_id=' . $siteA), 'editor GET /admin/api/iam/users');

    $translator = roles_matrix_login($baseUrl, 'translator@example.test');
    roles_matrix_assert_allowed($h, $translator->request('/admin/api/entries?site_id=' . $siteA), 'translator GET /admin/api/entries');
    $translatorCsrf = $translator->csrfToken();
    roles_matrix_assert_forbidden(
        $h,
        $translator->request('/admin/api/entries/999999/publish', 'POST', json_encode(['data' => ['site_id' => $siteA, 'revision_id' => 1]], JSON_UNESCAPED_SLASHES), ['Content-Type' => 'application/json', 'X-CSRF-Token' => $translatorCsrf]),
        'translator POST /admin/api/entries/{id}/publish'
    );

    $publication = roles_matrix_login($baseUrl, 'publication@example.test');
    $publicationCsrf = $publication->csrfToken();
    roles_matrix_assert_allowed(
        $h,
        $publication->request('/admin/api/entries/999999/publish', 'POST', json_encode(['data' => ['site_id' => $siteA, 'revision_id' => 1]], JSON_UNESCAPED_SLASHES), ['Content-Type' => 'application/json', 'X-CSRF-Token' => $publicationCsrf]),
        'publication POST /admin/api/entries/{id}/publish reaches route authorization'
    );
    roles_matrix_assert_forbidden($h, $publication->request('/admin/api/iam/users?site_id=' . $siteA), 'publication GET /admin/api/iam/users');

    $seo = roles_matrix_login($baseUrl, 'seo@example.test');
    roles_matrix_assert_allowed($h, $seo->request('/admin/api/seo/audit?site_id=' . $siteA), 'seo GET /admin/api/seo/audit');
    $seoCsrf = $seo->csrfToken();
    roles_matrix_assert_forbidden(
        $h,
        $seo->request('/admin/api/entries/999999/publish', 'POST', json_encode(['data' => ['site_id' => $siteA, 'revision_id' => 1]], JSON_UNESCAPED_SLASHES), ['Content-Type' => 'application/json', 'X-CSRF-Token' => $seoCsrf]),
        'seo POST /admin/api/entries/{id}/publish'
    );

    $user = roles_matrix_login($baseUrl, 'user@example.test');
    roles_matrix_assert_allowed($h, $user->request('/admin/api/profile'), 'user GET /admin/api/profile');
    roles_matrix_assert_forbidden($h, $user->request('/admin/api/entries?site_id=' . $siteA), 'user GET /admin/api/entries');
    roles_matrix_assert_forbidden($h, $user->request('/admin/api/iam/users?site_id=' . $siteA), 'user GET /admin/api/iam/users');
} finally {
    roles_matrix_stop_server($server);
    foreach ([1, 2] as $idx) {
        if (isset($pipes[$idx]) && is_resource($pipes[$idx])) {
            fclose($pipes[$idx]);
        }
    }
    roles_matrix_restore_databases($backup);
}

exit($h->finish('INTEGRATION P1-03 roles matrix HTTP smoke'));
