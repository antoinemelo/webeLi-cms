<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Application\Api\Admin\BusinessCrmApiController;
use App\Application\Frontend\BusinessMemoShareController;
use App\Core\ApiException;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;
use App\Modules\Business\Repositories\BusinessCompanyRepository;
use App\Modules\Business\Repositories\BusinessConsentRepository;
use App\Modules\Business\Repositories\BusinessContactRepository;
use App\Modules\Business\Repositories\BusinessMemoRepository;
use App\Modules\Business\Repositories\BusinessTagRepository;
use App\Modules\Business\Services\BusinessConsentService;
use App\Modules\Business\Services\BusinessCrmService;
use App\Modules\Business\Services\BusinessCsvService;
use App\Modules\Business\Services\BusinessMemoSharingService;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;

$h = new TestHarness();
[$businessDir, $businessPath, $businessDb] = test_temp_cms_db(__DIR__ . '/../../../../database/migrations/business/0001_init.sql');
[$iamDir, $iamPath] = test_temp_db(__DIR__ . '/../../../../database/iam.sql');
$coreDir = sys_get_temp_dir() . '/amcms-business-api-core-' . bin2hex(random_bytes(6));
mkdir($coreDir, 0775, true);

try {
    $core = new Database($coreDir . '/core.sqlite', 1000);
    $core->run("CREATE TABLE sites(id INTEGER PRIMARY KEY, site_key TEXT NOT NULL, name TEXT NOT NULL, default_language_code TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 1)");
    $core->run("CREATE TABLE site_domains(id INTEGER PRIMARY KEY, site_id INTEGER NOT NULL, host TEXT NOT NULL, base_path TEXT NOT NULL DEFAULT '', scheme TEXT NOT NULL DEFAULT 'https', is_primary INTEGER NOT NULL DEFAULT 1, is_active INTEGER NOT NULL DEFAULT 1, enforce_https INTEGER NOT NULL DEFAULT 0)");
    $core->run("CREATE TABLE languages(code TEXT PRIMARY KEY, name TEXT NOT NULL, is_default INTEGER NOT NULL DEFAULT 0, is_active INTEGER NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0)");
    $core->run("CREATE TABLE site_languages(site_id INTEGER NOT NULL, language_code TEXT NOT NULL, is_default INTEGER NOT NULL DEFAULT 0, is_active INTEGER NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0, fallback_language_code TEXT, url_prefix TEXT, hreflang_code TEXT, is_rtl INTEGER NOT NULL DEFAULT 0)");
    $core->run("INSERT INTO sites(id, site_key, name, default_language_code, is_active) VALUES(1, 'main', 'Main', 'fr', 1)");
    $core->run("INSERT INTO site_domains(id, site_id, host, base_path, scheme, is_primary, is_active) VALUES(1, 1, 'example.test', '', 'https', 1, 1)");
    $core->run("INSERT INTO languages(code, name, is_default, is_active, sort_order) VALUES('fr', 'Français', 1, 1, 1)");
    $core->run("INSERT INTO site_languages(site_id, language_code, is_default, is_active, sort_order) VALUES(1, 'fr', 1, 1, 1)");

    $iam = new Database($iamPath, 1000);
    $iam->run("INSERT INTO iam_users(id,email,email_normalized,password_hash,is_active,login_mode) VALUES(1,'business-admin@example.test','business-admin@example.test','x',1,'password'),(2,'business-viewer@example.test','business-viewer@example.test','x',1,'password')");
    $iam->run("INSERT INTO iam_roles(id,role_key,name) VALUES(1,'business_admin','Business admin'),(2,'business_empty','Business empty')");
    $iam->run("INSERT INTO iam_permissions(id,permission_key,name) VALUES(1,'business.crm.read','Read Business CRM'),(2,'business.crm.manage','Manage Business CRM'),(3,'business.memo.read','Read memos'),(4,'business.memo.manage','Manage memos'),(5,'business.memo.share','Share memos')");
    $iam->run("INSERT INTO iam_role_permissions(role_id,permission_id) VALUES(1,1),(1,2),(1,3),(1,4),(1,5)");
    $iam->run("INSERT INTO iam_user_site_roles(user_id,site_id,role_id) VALUES(1,1,1),(2,1,2)");

    $business = $businessDb;
    $companies = new BusinessCompanyRepository($business);
    $contacts = new BusinessContactRepository($business);
    $tags = new BusinessTagRepository($business);
    $memos = new BusinessMemoRepository($business);
    $consents = new BusinessConsentRepository($business);
    $crm = new BusinessCrmService($companies, $contacts, $tags);
    $consentService = new BusinessConsentService($consents);
    $memoSharing = new BusinessMemoSharingService($memos);
    $csv = new BusinessCsvService($companies, $contacts, $tags, $consents);
    $sites = new SiteRepository($core, ['cms' => ['default_site_key' => 'main'], 'app' => ['default_locale' => 'fr']]);

    $controllerFor = static function (int $userId, string $method, string $path, array $query = [], array $payload = []) use ($iam, $sites, $crm, $companies, $contacts, $tags, $memos, $consents, $consentService, $memoSharing, $csv): BusinessCrmApiController {
        $token = 'business-api-test-token-' . $userId;
        $iam->run('DELETE FROM iam_sessions WHERE user_id = :user_id', ['user_id' => $userId]);
        $iam->run(
            'INSERT INTO iam_sessions(user_id, session_token_hash, ip_address, user_agent, last_seen_at, expires_at, created_at)
             VALUES(:user_id, :hash, :ip, :ua, :last_seen, :expires, :created)',
            [
                'user_id' => $userId,
                'hash' => hash('sha256', $token),
                'ip' => '127.0.0.1',
                'ua' => 'business-api-controller-test',
                'last_seen' => gmdate('Y-m-d H:i:s'),
                'expires' => gmdate('Y-m-d H:i:s', time() + 3600),
                'created' => gmdate('Y-m-d H:i:s', time() - 60),
            ]
        );
        $_SESSION['admin_user'] = ['id' => $userId, 'email' => 'business-' . $userId . '@example.test', 'session_secret' => $token];
        $request = new Request($method, $path, $query, $payload === [] ? [] : ['data' => $payload], ['HTTP_HOST' => 'example.test'], [], []);
        $auth = new AuthRepository($iam);
        return new BusinessCrmApiController($request, $sites, $auth, new Authorization($auth), $crm, $companies, $contacts, $tags, $memos, $consents, $consentService, $memoSharing, $csv);
    };

    $create = $controllerFor(1, 'POST', '/admin/api/business/companies', [], ['name' => 'API Test SA'])->storeCompany();
    $h->assertSame(201, $create->status(), 'business crm manage can create company');
    $payload = json_decode($create->body(), true);
    $h->assertSame('API Test SA', $payload['data']['company']['name'] ?? null, 'company response envelope is usable');

    $index = $controllerFor(1, 'GET', '/admin/api/business/companies')->companies();
    $h->assertSame(200, $index->status(), 'business crm read can list companies');

    $export = $controllerFor(1, 'GET', '/admin/api/business/companies/export.csv')->exportCompaniesCsv();
    $h->assertSame(200, $export->status(), 'business crm manage can export companies CSV');
    $h->assertSame('text/csv; charset=utf-8', $export->headers()['Content-Type'] ?? null, 'companies CSV response has CSV content type');
    $h->assertTrue(str_contains($export->body(), 'API Test SA'), 'companies CSV contains company name');

    $h->expectException(
        fn() => $controllerFor(2, 'GET', '/admin/api/business/companies')->companies(),
        ApiException::class,
        'user without business crm read is forbidden'
    );

    $companyId = (int) ($payload['data']['company']['id'] ?? 0);
    $memo = $memos->create(1, ['company_id' => $companyId, 'title' => 'Mémo partagé', 'body' => 'Contenu public limité.'], 1, 1);
    $publicShareResponse = $controllerFor(1, 'POST', '/admin/api/business/memos/' . $memo['id'] . '/public-share', [], ['label' => 'Client', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)])->createPublicMemoShare((int) $memo['id']);
    $h->assertSame(201, $publicShareResponse->status(), 'business memo share can create public token');
    $publicSharePayload = json_decode($publicShareResponse->body(), true);
    $token = (string) ($publicSharePayload['data']['public_token'] ?? '');
    $h->assertTrue(strlen($token) >= 32, 'public token is returned once');
    $shares = $memos->sharesForMemo(1, (int) $memo['id']);
    $h->assertTrue(($shares[0]['public_token_hash'] ?? '') !== $token, 'public token is not stored in clear');

    $public = new BusinessMemoShareController($memos, new Logger($coreDir . '/business-public-share.log'));
    $publicResponse = $public->show($token);
    $h->assertSame(200, $publicResponse->status(), 'public memo share is readable without admin session');
    $h->assertSame('noindex, nofollow', $publicResponse->headers()['X-Robots-Tag'] ?? '', 'public memo share is noindex nofollow');
    $h->assertTrue(str_contains($publicResponse->body(), 'Mémo partagé'), 'public memo share exposes memo title');

    $h->expectException(
        fn() => $controllerFor(2, 'POST', '/admin/api/business/memos/' . $memo['id'] . '/public-share')->createPublicMemoShare((int) $memo['id']),
        ApiException::class,
        'user without business memo share cannot create public token'
    );

    $revokeResponse = $controllerFor(1, 'DELETE', '/admin/api/business/memos/' . $memo['id'] . '/public-share')->deletePublicMemoShare((int) $memo['id']);
    $h->assertSame(200, $revokeResponse->status(), 'business memo share can revoke public token');
    $h->assertSame(404, $public->show($token)->status(), 'revoked public memo token is refused');
    $h->assertSame(404, $public->show(str_repeat('a', 40))->status(), 'fake public memo token is refused');
} finally {
    $_SESSION = [];
    $businessDb = null;
    $business = null;
    gc_collect_cycles();
    test_remove_tree($businessDir);
    test_remove_tree($iamDir);
    test_remove_tree($coreDir);
}

exit($h->finish('UNIT business CRM admin API'));
