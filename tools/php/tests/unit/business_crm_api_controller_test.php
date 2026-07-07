<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Application\Api\Admin\BusinessCrmApiController;
use App\Application\Frontend\BusinessMemoShareController;
use App\Application\Iam\IamAdminRepository;
use App\Core\ApiException;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;
use App\Modules\Business\Repositories\BusinessCompanyRepository;
use App\Modules\Business\Repositories\BusinessActivityRepository;
use App\Modules\Business\Repositories\BusinessConsentRepository;
use App\Modules\Business\Repositories\BusinessContactRepository;
use App\Modules\Business\Repositories\BusinessDashboardRepository;
use App\Modules\Business\Repositories\BusinessMemoRepository;
use App\Modules\Business\Repositories\BusinessRelationReadRepository;
use App\Modules\Business\Repositories\BusinessRelationRepository;
use App\Modules\Business\Repositories\BusinessSearchRepository;
use App\Modules\Business\Repositories\BusinessTagRepository;
use App\Modules\Business\Services\BusinessConsentService;
use App\Modules\Business\Services\BusinessCrmService;
use App\Modules\Business\Services\BusinessCsvService;
use App\Modules\Business\Services\BusinessMemoSharingService;
use App\Modules\Business\Services\BusinessRelationSummaryService;
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
    $activity = new BusinessActivityRepository($business);
    $contacts = new BusinessContactRepository($business);
    $dashboard = new BusinessDashboardRepository($business);
    $legacyRelations = new BusinessRelationRepository($business);
    $search = new BusinessSearchRepository($business);
    $tags = new BusinessTagRepository($business);
    $memos = new BusinessMemoRepository($business);
    $consents = new BusinessConsentRepository($business);
    $crm = new BusinessCrmService($companies, $contacts, $tags);
    $consentService = new BusinessConsentService($consents);
    $memoSharing = new BusinessMemoSharingService($memos);
    $csv = new BusinessCsvService($companies, $contacts, $tags, $consents);
    $relations = new BusinessRelationReadRepository($legacyRelations, $memos);
    $relationSummary = new BusinessRelationSummaryService($relations, $activity);
    $iamAdmin = new IamAdminRepository($iam, $core);
    $sites = new SiteRepository($core, ['cms' => ['default_site_key' => 'main'], 'app' => ['default_locale' => 'fr']]);

    $controllerFor = static function (int $userId, string $method, string $path, array $query = [], array $payload = [], array $files = []) use ($iam, $sites, $activity, $crm, $companies, $contacts, $dashboard, $relations, $search, $tags, $memos, $consents, $consentService, $memoSharing, $csv, $relationSummary, $iamAdmin): BusinessCrmApiController {
        if ($userId > 0) {
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
        } else {
            $_SESSION = [];
        }
        $request = new Request($method, $path, $query, $payload === [] ? [] : ['data' => $payload], ['HTTP_HOST' => 'example.test'], $files, []);
        $auth = new AuthRepository($iam);
        return new BusinessCrmApiController($request, $sites, $auth, new Authorization($auth), $activity, $crm, $companies, $contacts, $dashboard, $relations, $search, $tags, $memos, $consents, $consentService, $memoSharing, $csv, $relationSummary, $iamAdmin);
    };

    $create = $controllerFor(1, 'POST', '/admin/api/business/companies', [], ['name' => 'API Test SA'])->storeCompany();
    $h->assertSame(201, $create->status(), 'business crm manage can create company');
    $payload = json_decode($create->body(), true);
    $h->assertSame('API Test SA', $payload['data']['company']['name'] ?? null, 'company response envelope is usable');
    $companyId = (int) ($payload['data']['company']['id'] ?? 0);

    $index = $controllerFor(1, 'GET', '/admin/api/business/companies')->companies();
    $h->assertSame(200, $index->status(), 'business crm read can list companies');

    $h->expectException(
        fn() => $controllerFor(0, 'GET', '/admin/api/business/relations')->relations(),
        ApiException::class,
        'anonymous user cannot access business admin relations'
    );

    $contactResponse = $controllerFor(1, 'POST', '/admin/api/business/contacts', [], ['company_id' => $companyId, 'display_name' => 'Ada Relation', 'email' => 'ada.relation@example.test', 'phone' => '+41 21 000 00 00', 'status' => 'client'])->storeContact();
    $h->assertSame(201, $contactResponse->status(), 'business crm manage can create contact for unified relations');
    $contactPayload = json_decode($contactResponse->body(), true);
    $contactId = (int) ($contactPayload['data']['contact']['id'] ?? 0);
    $relationTag = $tags->create(1, ['label' => 'Important'], 1);
    $tags->linkContact((int) $relationTag['id'], $contactId, 1);

    $defaultCompanyContactResponse = $controllerFor(1, 'POST', '/admin/api/business/contacts', [], ['display_name' => 'Default Company Contact', 'email' => 'default.company.contact@example.test', 'status' => 'prospect'])->storeContact();
    $h->assertSame(201, $defaultCompanyContactResponse->status(), 'business crm can create an individual attached to default system company');

    $relationsResponse = $controllerFor(1, 'GET', '/admin/api/business/relations', ['q' => 'relation', 'sort' => 'name_asc'])->relations();
    $h->assertSame(200, $relationsResponse->status(), 'business crm read can list unified relations');
    $relationsPayload = json_decode($relationsResponse->body(), true);
    $relationTypes = array_column($relationsPayload['data']['relations'] ?? [], 'type');
    $h->assertTrue(in_array('contact', $relationTypes, true), 'unified relations include contacts');
    $h->assertSame('Ada Relation', $relationsPayload['data']['relations'][0]['display_name'] ?? null, 'unified relation has display name');
    $h->assertTrue(array_key_exists('pagination', $relationsPayload['data'] ?? []), 'unified relations expose pagination');
    $h->assertTrue(array_key_exists('memo_count', $relationsPayload['data']['relations'][0] ?? []), 'unified relations expose memo count');
    $h->assertTrue(array_key_exists('shared_memo_count', $relationsPayload['data']['relations'][0] ?? []), 'unified relations expose shared memo count');
    $h->assertTrue(in_array('Important', $relationsPayload['data']['relations'][0]['tags'] ?? [], true), 'unified relation exposes compact tags');
    $h->assertSame('unknown', $relationsPayload['data']['relations'][0]['email_consent_status'] ?? null, 'unified relation exposes email consent status');
    $allRelationsResponse = $controllerFor(1, 'GET', '/admin/api/business/relations', ['sort' => 'name_asc'])->relations();
    $allRelationsPayload = json_decode($allRelationsResponse->body(), true);
    $systemCompanyRelations = array_values(array_filter($allRelationsPayload['data']['relations'] ?? [], static fn(array $relation): bool => ($relation['type'] ?? '') === 'company' && (($relation['company']['is_system_individuals'] ?? false) === true || ($relation['display_name'] ?? '') === 'Individus')));
    $h->assertSame([], $systemCompanyRelations, 'unified relations list hides the default system company');
    $filteredRelationsResponse = $controllerFor(1, 'GET', '/admin/api/business/relations', ['type' => 'individual', 'status' => 'client', 'sort' => 'name_asc'])->relations();
    $filteredRelationsPayload = json_decode($filteredRelationsResponse->body(), true);
    $h->assertTrue(in_array($contactId, array_map('intval', array_column($filteredRelationsPayload['data']['relations'] ?? [], 'id')), true), 'relations API accepts type, status and sort filters');

    $withEmailResponse = $controllerFor(1, 'GET', '/admin/api/business/relations', ['has_email' => '1'])->relations();
    $withEmailPayload = json_decode($withEmailResponse->body(), true);
    $h->assertTrue(in_array($contactId, array_map('intval', array_column($withEmailPayload['data']['relations'] ?? [], 'id')), true), 'relations filter can require email');
    $withPhoneResponse = $controllerFor(1, 'GET', '/admin/api/business/relations', ['has_phone' => '1'])->relations();
    $withPhonePayload = json_decode($withPhoneResponse->body(), true);
    $h->assertTrue(in_array($contactId, array_map('intval', array_column($withPhonePayload['data']['relations'] ?? [], 'id')), true), 'relations filter can require phone or mobile');
    $withoutConsentResponse = $controllerFor(1, 'GET', '/admin/api/business/relations', ['missing_email_consent' => '1'])->relations();
    $withoutConsentPayload = json_decode($withoutConsentResponse->body(), true);
    $h->assertTrue(in_array($contactId, array_map('intval', array_column($withoutConsentPayload['data']['relations'] ?? [], 'id')), true), 'relations filter can find contacts without email opt-in');
    $kindResponse = $controllerFor(1, 'GET', '/admin/api/business/relations', ['kind' => 'contact'])->relations();
    $kindPayload = json_decode($kindResponse->body(), true);
    $h->assertTrue(in_array('contact', array_column($kindPayload['data']['relations'] ?? [], 'type'), true), 'relations API accepts kind alias');
    $tagResponse = $controllerFor(1, 'GET', '/admin/api/business/relations', ['tag' => 'important'])->relations();
    $tagPayload = json_decode($tagResponse->body(), true);
    $h->assertTrue(in_array($contactId, array_map('intval', array_column($tagPayload['data']['relations'] ?? [], 'id')), true), 'relations API filters by tag');
    $updatedAfterResponse = $controllerFor(1, 'GET', '/admin/api/business/relations', ['updated_after' => '1970-01-01 00:00:00'])->relations();
    $h->assertSame(200, $updatedAfterResponse->status(), 'relations API accepts updated_after filter');

    $storeConsentResponse = $controllerFor(1, 'POST', '/admin/api/business/consents', [], ['contact_id' => $contactId, 'channel' => 'email', 'status' => 'opted_in', 'source' => 'manual'])->storeConsent();
    $h->assertSame(201, $storeConsentResponse->status(), 'business global consent endpoint can create opt-in consent');
    $storeConsentPayload = json_decode($storeConsentResponse->body(), true);
    $consentId = (int) ($storeConsentPayload['data']['consent']['id'] ?? 0);
    $h->assertSame('opt_in', $storeConsentPayload['data']['consent']['consent_status'] ?? null, 'global consent endpoint normalizes opted_in to storage status');
    $consentsResponse = $controllerFor(1, 'GET', '/admin/api/business/consents', ['channel' => 'email', 'status' => 'opted_in'])->consents();
    $h->assertSame(200, $consentsResponse->status(), 'business global consent endpoint lists consents');
    $consentsPayload = json_decode($consentsResponse->body(), true);
    $h->assertTrue(in_array($consentId, array_map('intval', array_column($consentsPayload['data']['consents'] ?? [], 'id')), true), 'business global consent list accepts canonical status filter');
    $updateConsentResponse = $controllerFor(1, 'PATCH', '/admin/api/business/consents/' . $consentId, [], ['status' => 'opted_out', 'source' => 'manual'])->updateConsent($consentId);
    $h->assertSame(200, $updateConsentResponse->status(), 'business global consent endpoint updates consent');
    $updateConsentPayload = json_decode($updateConsentResponse->body(), true);
    $h->assertSame('opt_out', $updateConsentPayload['data']['consent']['consent_status'] ?? null, 'global consent endpoint normalizes opted_out to storage status');
    $h->assertTrue((string) ($updateConsentPayload['data']['consent']['revoked_at'] ?? '') !== '', 'global consent revocation is timestamped');

    $relationShow = $controllerFor(1, 'GET', '/admin/api/business/relations/individual/' . $contactId)->showRelation('individual', $contactId);
    $h->assertSame(200, $relationShow->status(), 'business crm read can show an individual relation alias');
    $relationPayload = json_decode($relationShow->body(), true);
    $h->assertSame('ada.relation@example.test', $relationPayload['data']['relation']['primary_email'] ?? null, 'typed relation exposes primary email');
    $h->assertTrue(array_key_exists('memo_count', $relationPayload['data']['relation'] ?? []), 'typed relation exposes memo counter');
    $h->assertTrue(array_key_exists('created_by', $relationPayload['data']['relation'] ?? []) || array_key_exists('created_by_iam_user_id', $relationPayload['data']['relation'] ?? []), 'typed relation exposes creation audit information when available');

    $availableUsersResponse = $controllerFor(1, 'GET', '/admin/api/business/iam/available-users')->availableIamUsers();
    $h->assertSame(200, $availableUsersResponse->status(), 'business relation IAM available users endpoint is readable');
    $availableUsersPayload = json_decode($availableUsersResponse->body(), true);
    $h->assertTrue(count($availableUsersPayload['data']['users'] ?? []) >= 1, 'business relation IAM available users returns active users');
    $h->assertSame(false, array_key_exists('password_hash', $availableUsersPayload['data']['users'][0] ?? []), 'available users do not expose IAM secrets');

    $storeRelationResponse = $controllerFor(1, 'POST', '/admin/api/business/relations', [], ['type' => 'company', 'name' => 'Relation API SA', 'status' => 'prospect'])->storeRelation();
    $h->assertSame(201, $storeRelationResponse->status(), 'business relation aggregate API can create an organization');
    $storeRelationPayload = json_decode($storeRelationResponse->body(), true);
    $aggregateCompanyId = (int) ($storeRelationPayload['data']['relation']['id'] ?? 0);
    $updateRelationResponse = $controllerFor(1, 'PATCH', '/admin/api/business/relations/company/' . $aggregateCompanyId, [], ['name' => 'Relation API Client SA', 'status' => 'client'])->updateRelation('company', $aggregateCompanyId);
    $h->assertSame(200, $updateRelationResponse->status(), 'business relation aggregate API can update an organization');
    $updateRelationPayload = json_decode($updateRelationResponse->body(), true);
    $h->assertSame('client', $updateRelationPayload['data']['relation']['status'] ?? null, 'business relation aggregate update returns fresh relation');
    $deleteRelationResponse = $controllerFor(1, 'DELETE', '/admin/api/business/relations/company/' . $aggregateCompanyId)->deleteRelation('company', $aggregateCompanyId);
    $h->assertSame(200, $deleteRelationResponse->status(), 'business relation aggregate delete endpoint archives active relation first');
    $deleteRelationPayload = json_decode($deleteRelationResponse->body(), true);
    $h->assertSame(false, $deleteRelationPayload['data']['deleted'] ?? null, 'business relation aggregate delete does not physically delete an active relation');
    $h->assertSame(true, $deleteRelationPayload['data']['archived'] ?? null, 'business relation aggregate delete archives active relation');
    $restoreRelationResponse = $controllerFor(1, 'POST', '/admin/api/business/relations/company/' . $aggregateCompanyId . '/restore')->restoreRelation('company', $aggregateCompanyId);
    $h->assertSame(200, $restoreRelationResponse->status(), 'business relation aggregate restore endpoint restores archived relation');
    $restoreRelationPayload = json_decode($restoreRelationResponse->body(), true);
    $h->assertSame(true, $restoreRelationPayload['data']['restored'] ?? null, 'business relation aggregate restore returns restored flag');
    $deleteRestoredRelationResponse = $controllerFor(1, 'DELETE', '/admin/api/business/relations/company/' . $aggregateCompanyId)->deleteRelation('company', $aggregateCompanyId);
    $h->assertSame(200, $deleteRestoredRelationResponse->status(), 'business relation aggregate delete re-archives restored relation');
    $deleteArchivedRelationResponse = $controllerFor(1, 'DELETE', '/admin/api/business/relations/company/' . $aggregateCompanyId)->deleteRelation('company', $aggregateCompanyId);
    $h->assertSame(200, $deleteArchivedRelationResponse->status(), 'business relation aggregate delete removes archived relation');
    $deleteArchivedRelationPayload = json_decode($deleteArchivedRelationResponse->body(), true);
    $h->assertSame(true, $deleteArchivedRelationPayload['data']['deleted'] ?? null, 'business relation aggregate delete physically deletes an archived relation');

    $globalSearch = $controllerFor(1, 'GET', '/admin/api/business/search', ['q' => 'Ada'])->search();
    $h->assertSame(200, $globalSearch->status(), 'business global search endpoint is readable');
    $globalSearchPayload = json_decode($globalSearch->body(), true);
    $h->assertTrue(count($globalSearchPayload['data']['relations'] ?? []) >= 1, 'business global search returns grouped relation results');
    $h->assertSame([], $globalSearchPayload['data']['future_documents'] ?? null, 'business global search exposes stable future documents group');

    $dashboardResponse = $controllerFor(1, 'GET', '/admin/api/business/dashboard')->dashboard();
    $h->assertSame(200, $dashboardResponse->status(), 'business dashboard endpoint is readable');
    $dashboardPayload = json_decode($dashboardResponse->body(), true);
    $h->assertTrue(($dashboardPayload['data']['counters']['relations'] ?? 0) >= 2, 'business dashboard returns relation counters');
    $h->assertTrue(count($dashboardPayload['data']['recent_relations'] ?? []) >= 1, 'business dashboard returns recent relations');
    $h->assertTrue(array_key_exists('alerts', $dashboardPayload['data']), 'business dashboard returns alerts block');

    $schemaResponse = $controllerFor(1, 'GET', '/admin/api/business/schema')->schema();
    $h->assertSame(200, $schemaResponse->status(), 'business schema endpoint is readable');
    $schemaPayload = json_decode($schemaResponse->body(), true);
    $h->assertSame(false, $schemaPayload['data']['headless_public'] ?? true, 'business schema is admin scoped, not public headless');
    $h->assertTrue(in_array('contact', $schemaPayload['data']['entities']['relation']['kinds'] ?? [], true), 'business schema exposes contact relation kind');
    $schemaActionKeys = array_column($schemaPayload['data']['actions'] ?? [], 'key');
    $h->assertTrue(in_array('relations.summary', $schemaActionKeys, true), 'business schema exposes relation summary action');
    $h->assertSame(false, $schemaPayload['data']['ai']['summary']['external_call_by_default'] ?? true, 'business schema makes external AI opt-in only');

    $export = $controllerFor(1, 'GET', '/admin/api/business/companies/export.csv')->exportCompaniesCsv();
    $h->assertSame(200, $export->status(), 'business crm manage can export companies CSV');
    $h->assertSame('text/csv; charset=utf-8', $export->headers()['Content-Type'] ?? null, 'companies CSV response has CSV content type');
    $h->assertTrue(str_contains($export->body(), 'API Test SA'), 'companies CSV contains company name');

    $relationsExport = $controllerFor(1, 'GET', '/admin/api/business/export/relations')->exportRelations();
    $h->assertSame(200, $relationsExport->status(), 'business relations export alias returns CSV');
    $h->assertTrue(str_contains($relationsExport->body(), 'Ada Relation'), 'business relations export contains relation display name');
    $contactsExport = $controllerFor(1, 'GET', '/admin/api/business/export/contacts')->exportContacts();
    $h->assertSame(200, $contactsExport->status(), 'business contacts export alias returns CSV');
    $companiesExport = $controllerFor(1, 'GET', '/admin/api/business/export/companies')->exportCompanies();
    $h->assertSame(200, $companiesExport->status(), 'business companies export alias returns CSV');

    $relationsImportFile = $coreDir . '/relations-import.csv';
    file_put_contents($relationsImportFile, "display_name;email;company_name;status\nImport Relation;import.relation@example.test;API Test SA;prospect\n");
    $relationsImport = $controllerFor(1, 'POST', '/admin/api/business/import/relations', [], ['dry_run' => true, 'create_companies' => true], ['file' => ['error' => UPLOAD_ERR_OK, 'tmp_name' => $relationsImportFile, 'size' => filesize($relationsImportFile)]])->importRelations();
    $h->assertSame(200, $relationsImport->status(), 'business relations import endpoint validates CSV in dry-run');
    $relationsImportPayload = json_decode($relationsImport->body(), true);
    $h->assertSame(true, $relationsImportPayload['data']['dry_run'] ?? null, 'business relations import reports dry-run');
    $h->assertSame(1, $relationsImportPayload['data']['valid_rows'] ?? null, 'business relations import reports valid rows');

    $companiesImportFile = $coreDir . '/companies-import.csv';
    file_put_contents($companiesImportFile, "name;email;status\nImport Company;import.company@example.test;prospect\n");
    $companiesImport = $controllerFor(1, 'POST', '/admin/api/business/import/companies', [], ['dry_run' => true], ['file' => ['error' => UPLOAD_ERR_OK, 'tmp_name' => $companiesImportFile, 'size' => filesize($companiesImportFile)]])->importCompanies();
    $h->assertSame(200, $companiesImport->status(), 'business companies import endpoint validates CSV in dry-run');

    $h->expectException(
        fn() => $controllerFor(2, 'GET', '/admin/api/business/companies')->companies(),
        ApiException::class,
        'user without business crm read is forbidden'
    );

    $memo = $memos->create(1, ['company_id' => $companyId, 'title' => 'Mémo partagé', 'body' => 'Contenu public limité.'], 1, 1);
    $directMemoResponse = $controllerFor(1, 'POST', '/admin/api/business/memos', [], ['contact_id' => $contactId, 'title' => 'Mémo direct', 'body' => 'Mémo rattaché à la relation individuelle.'])->storeMemo();
    $h->assertSame(201, $directMemoResponse->status(), 'business memos endpoint can create memo for a relation');
    $directMemoPayload = json_decode($directMemoResponse->body(), true);
    $directMemoId = (int) ($directMemoPayload['data']['memo']['id'] ?? 0);
    $memoIndexResponse = $controllerFor(1, 'GET', '/admin/api/business/memos', ['relation_type' => 'individual', 'relation_id' => (string) $contactId, 'q' => 'direct', 'sort' => 'created_desc'])->memos();
    $h->assertSame(200, $memoIndexResponse->status(), 'business memos endpoint accepts relation filters');
    $memoIndexPayload = json_decode($memoIndexResponse->body(), true);
    $h->assertTrue(in_array($directMemoId, array_map('intval', array_column($memoIndexPayload['data']['memos'] ?? [], 'id')), true), 'business memos relation filter returns matching memo');
    $memoUpdateResponse = $controllerFor(1, 'PATCH', '/admin/api/business/memos/' . $directMemoId, [], ['title' => 'Mémo direct modifié', 'body' => 'Mémo modifié.'])->updateMemo($directMemoId);
    $h->assertSame(200, $memoUpdateResponse->status(), 'business memos endpoint can update memo');
    $memoUpdatePayload = json_decode($memoUpdateResponse->body(), true);
    $h->assertSame('Mémo direct modifié', $memoUpdatePayload['data']['memo']['title'] ?? null, 'business memos update returns changed title');
    $commentResponse = $controllerFor(1, 'POST', '/admin/api/business/memos/' . $memo['id'] . '/comments', [], ['body' => 'Commentaire initial'])->storeMemoComment((int) $memo['id']);
    $h->assertSame(201, $commentResponse->status(), 'business memo comment can be created from admin API');
    $commentPayload = json_decode($commentResponse->body(), true);
    $commentId = (int) ($commentPayload['data']['comment']['id'] ?? 0);
    $updatedCommentResponse = $controllerFor(1, 'PATCH', '/admin/api/business/memos/' . $memo['id'] . '/comments/' . $commentId, [], ['body' => 'Commentaire corrigé'])->updateMemoComment((int) $memo['id'], $commentId);
    $h->assertSame(200, $updatedCommentResponse->status(), 'business memo comment can be edited from admin API');
    $updatedCommentPayload = json_decode($updatedCommentResponse->body(), true);
    $h->assertSame('Commentaire corrigé', $updatedCommentPayload['data']['comment']['body'] ?? null, 'business memo edited comment body is returned');

    $activityResponse = $controllerFor(1, 'GET', '/admin/api/business/relations/company/' . $companyId . '/activity')->relationActivity('company', $companyId);
    $h->assertSame(200, $activityResponse->status(), 'business relation activity is readable');
    $activityPayload = json_decode($activityResponse->body(), true);
    $activityActions = array_column($activityPayload['data']['activity'] ?? [], 'action');
    $h->assertTrue(in_array('business.memo.created', $activityActions, true), 'business relation activity includes memo creation');
    $h->assertTrue(in_array('business.memo.commented', $activityActions, true) || in_array('business.memo_comment.updated', $activityActions, true), 'business relation activity includes memo comments');

    $summaryResponse = $controllerFor(1, 'POST', '/admin/api/business/relations/company/' . $companyId . '/summary')->summarizeRelation('company', $companyId);
    $h->assertSame(200, $summaryResponse->status(), 'business relation local summary is generated');
    $summaryPayload = json_decode($summaryResponse->body(), true);
    $h->assertSame('local_summary', $summaryPayload['data']['provider'] ?? null, 'business relation summary uses local provider by default');
    $h->assertSame(false, $summaryPayload['data']['external_call'] ?? true, 'business relation summary performs no external call by default');
    $h->assertSame($companyId, $summaryPayload['data']['relation']['id'] ?? null, 'business relation summary references the summarized relation');
    $h->assertTrue(strlen((string) ($summaryPayload['data']['summary'] ?? '')) > 10, 'business relation summary body is non-empty');
    $h->assertTrue(($summaryPayload['data']['sources']['memos_count'] ?? 0) >= 1, 'business relation summary reports memo sources');
    $h->assertTrue(($summaryPayload['data']['sources']['activity_count'] ?? 0) >= 1, 'business relation summary reports activity sources');
    $missingSummaryResponse = $controllerFor(1, 'POST', '/admin/api/business/relations/company/999999/summary')->summarizeRelation('company', 999999);
    $h->assertSame(404, $missingSummaryResponse->status(), 'business relation summary returns not found for missing relation');

    $relationMemosResponse = $controllerFor(1, 'GET', '/admin/api/business/relations/company/' . $companyId . '/memos')->relationMemos('company', $companyId);
    $h->assertSame(200, $relationMemosResponse->status(), 'business relation memos aggregate endpoint is readable');
    $relationMemosPayload = json_decode($relationMemosResponse->body(), true);
    $h->assertTrue(in_array((int) $memo['id'], array_map('intval', array_column($relationMemosPayload['data']['memos'] ?? [], 'id')), true), 'business relation memos endpoint returns relation memos');
    $relationCommentsResponse = $controllerFor(1, 'GET', '/admin/api/business/relations/company/' . $companyId . '/comments')->relationComments('company', $companyId);
    $h->assertSame(200, $relationCommentsResponse->status(), 'business relation comments aggregate endpoint is readable');
    $relationCommentsPayload = json_decode($relationCommentsResponse->body(), true);
    $h->assertTrue(in_array($commentId, array_map('intval', array_column($relationCommentsPayload['data']['comments'] ?? [], 'id')), true), 'business relation comments endpoint returns comments with memo context');
    $storeRelationMemoResponse = $controllerFor(1, 'POST', '/admin/api/business/relations/contact/' . $contactId . '/memos', [], ['title' => 'Mémo relation', 'body' => 'Mémo depuis endpoint relation.'])->storeRelationMemo('contact', $contactId);
    $h->assertSame(201, $storeRelationMemoResponse->status(), 'business relation aggregate API can create memo for relation');
    $storeRelationMemoPayload = json_decode($storeRelationMemoResponse->body(), true);
    $contactMemoId = (int) ($storeRelationMemoPayload['data']['memo']['id'] ?? 0);
    $companyRelationMemosResponse = $controllerFor(1, 'GET', '/admin/api/business/relations/company/' . $companyId . '/memos')->relationMemos('company', $companyId);
    $companyRelationMemosPayload = json_decode($companyRelationMemosResponse->body(), true);
    $h->assertTrue(in_array($contactMemoId, array_map('intval', array_column($companyRelationMemosPayload['data']['memos'] ?? [], 'id')), true), 'business company relation memos include linked contact memos');

    $publicShareResponse = $controllerFor(1, 'POST', '/admin/api/business/memos/' . $memo['id'] . '/public-share', [], ['label' => 'Client', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)])->createPublicMemoShare((int) $memo['id']);
    $h->assertSame(201, $publicShareResponse->status(), 'business memo share can create public token');
    $publicSharePayload = json_decode($publicShareResponse->body(), true);
    $token = (string) ($publicSharePayload['data']['public_token'] ?? '');
    $h->assertTrue(strlen($token) >= 32, 'public token is returned once');
    $shares = $memos->sharesForMemo(1, (int) $memo['id']);
    $h->assertTrue(($shares[0]['public_token_hash'] ?? '') !== $token, 'public token is not stored in clear');
    $normalMemoListing = $controllerFor(1, 'GET', '/admin/api/business/memos', ['company_id' => (string) $companyId])->memos();
    $h->assertSame(200, $normalMemoListing->status(), 'business memos normal listing is readable after share');
    $h->assertTrue(!str_contains($normalMemoListing->body(), $token), 'business memos normal listing does not expose raw public token');
    $memosExport = $controllerFor(1, 'GET', '/admin/api/business/export/memos')->exportMemos();
    $h->assertSame(200, $memosExport->status(), 'business memos export alias returns CSV');
    $h->assertTrue(!str_contains($memosExport->body(), $token), 'business memos export does not expose raw public token');
    $h->assertTrue(!str_contains($memosExport->body(), (string) ($shares[0]['public_token_hash'] ?? '')), 'business memos export does not expose public token hash');
    $updatedExpiry = gmdate('Y-m-d H:i:00', time() + 7200);
    $updateShareResponse = $controllerFor(1, 'PATCH', '/admin/api/business/memos/' . $memo['id'] . '/shares/' . $shares[0]['id'], [], ['label' => 'Client final', 'expires_at' => str_replace(' ', 'T', substr($updatedExpiry, 0, 16))])->updateMemoShare((int) $memo['id'], (int) $shares[0]['id']);
    $h->assertSame(200, $updateShareResponse->status(), 'business memo public share can be updated');
    $updateSharePayload = json_decode($updateShareResponse->body(), true);
    $h->assertSame('Client final', $updateSharePayload['data']['share']['public_label'] ?? null, 'business memo public share label can be updated');
    $h->assertSame($updatedExpiry, $updateSharePayload['data']['share']['expires_at'] ?? null, 'business memo public share normalizes datetime-local expiration');

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
