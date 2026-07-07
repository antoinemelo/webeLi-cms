<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Business\BusinessModuleProvider;

$h = new TestHarness();
$provider = new BusinessModuleProvider();
$blueprints = $provider->blueprints();
$byKey = [];

foreach ($blueprints as $blueprint) {
    $byKey[(string) ($blueprint['blueprint_key'] ?? '')] = $blueprint;
}

function fieldsByKey(array $blueprint): array
{
    return array_column($blueprint['fields'] ?? [], null, 'key');
}

function assertBlueprintFields(TestHarness $h, array $blueprint, array $expectedFields, string $messagePrefix): void
{
    $fields = fieldsByKey($blueprint);
    foreach ($expectedFields as $field) {
        $h->assertTrue(isset($fields[$field]), $messagePrefix . ' declares field ' . $field);
    }
}

function assertEnum(TestHarness $h, array $blueprint, string $fieldKey, array $expected, string $message): void
{
    $fields = fieldsByKey($blueprint);
    $h->assertSame($expected, $fields[$fieldKey]['validation']['enum'] ?? null, $message);
}

$expectedKeys = [
    'business_relation',
    'business_company',
    'business_contact',
    'business_memo',
    'business_memo_comment',
    'business_message',
    'business_consent',
    'business_mailing_list',
    'business_mailing_list_member',
];
$expectedResourceKeys = [
    'business.relation',
    'business.company',
    'business.contact',
    'business.memo',
    'business.memo_comment',
    'business.message',
    'business.consent',
    'business.mailing_list',
    'business.mailing_list_member',
];
$permissionKeys = array_column($provider->permissions(), 'key');

$h->assertSame($expectedKeys, array_keys($byKey), 'CRM admin blueprints are declared in the expected order');
$h->assertSame([], $provider->publicHeadlessRoutes(), 'Business CRM still exposes no public headless routes');

$adminRoutes = $provider->adminRoutes();
$adminRouteKeys = array_map(static fn(array $route): string => strtoupper((string) $route[0]) . ' ' . (string) $route[1], $adminRoutes);
$h->assertTrue(count($adminRoutes) >= 70, 'Business CRM declares a broad private admin endpoint surface');
$h->assertSame([], $provider->apiRoutes(), 'Business CRM declares no generic module API routes');

foreach ($expectedKeys as $index => $key) {
    $blueprint = $byKey[$key] ?? [];
    $h->assertSame($expectedResourceKeys[$index], $blueprint['contract']['resource_key'] ?? null, $key . ' exposes the expected business resource key');
    $h->assertTrue((string) ($blueprint['blueprint_key'] ?? '') !== '', $key . ' has a stable blueprint key');
    $h->assertTrue((string) ($blueprint['label'] ?? '') !== '', $key . ' has a readable label');
    $h->assertTrue((string) ($blueprint['description'] ?? '') !== '', $key . ' has a description');
    $h->assertSame('module_resource', $blueprint['resource_type'] ?? null, $key . ' is a module resource');
    $h->assertSame('business', $blueprint['storage']['database'] ?? null, $key . ' uses business.sqlite');
    $h->assertTrue(($blueprint['fields'] ?? []) !== [], $key . ' declares fields');
    $h->assertTrue(($blueprint['permissions'] ?? []) !== [], $key . ' declares permissions');
    foreach (($blueprint['permissions'] ?? []) as $action => $permissionKey) {
        $h->assertTrue(in_array($permissionKey, $permissionKeys, true), $key . ' permission ' . $action . ' is declared by provider');
    }
    $h->assertSame(true, $blueprint['capabilities']['admin'] ?? null, $key . ' is admin-capable');
    $h->assertSame(false, $blueprint['headless']['enabled'] ?? null, $key . ' disables headless exposure');
    $h->assertSame(false, $blueprint['headless']['public'] ?? null, $key . ' disables public exposure');
    $h->assertSame(false, $blueprint['capabilities']['headless'] ?? null, $key . ' disables headless capability');
    $h->assertSame(false, $blueprint['capabilities']['public'] ?? null, $key . ' disables public capability');
    $h->assertSame(false, $blueprint['admin']['schema_driven'] ?? null, $key . ' keeps dedicated CRM UX');
    $h->assertSame(false, $blueprint['admin']['generated_form'] ?? null, $key . ' does not generate CRM forms');
    $h->assertSame('/admin/api/modules/business/blueprints', $blueprint['contract']['admin_blueprints_endpoint'] ?? null, $key . ' documents admin blueprint endpoint');
    $h->assertSame([], $blueprint['contract']['public_headless_routes'] ?? null, $key . ' documents no public headless route');
}

$relation = $byKey['business_relation'];
$h->assertSame('aggregate_read_model', $relation['storage']['mode'] ?? null, 'relation blueprint is an aggregate read model');
$h->assertSame(false, $relation['admin']['create'] ?? null, 'relation aggregate is not direct-create');
$h->assertSame(false, $relation['admin']['update'] ?? null, 'relation aggregate is not direct-update');
$h->assertSame(['business_companies', 'business_contacts', 'crm_memos', 'crm_memo_shares'], $relation['storage']['source_tables'] ?? [], 'relation aggregate documents source tables');
assertBlueprintFields($h, $relation, ['relation_type', 'relation_id', 'display_name', 'status', 'email', 'phone', 'mobile', 'memo_count', 'shared_memo_count', 'linked_contacts_count'], 'business_relation');
assertEnum($h, $relation, 'relation_type', ['individual', 'company'], 'relation type enum is declared');
assertEnum($h, $relation, 'status', ['prospect', 'client', 'supplier', 'former_client', 'other'], 'relation status enum is declared');

$company = $byKey['business_company'];
assertBlueprintFields($h, $company, ['name', 'status', 'email', 'phone', 'city', 'country', 'vat_number'], 'business_company');
assertEnum($h, $company, 'status', ['prospect', 'client', 'supplier', 'former_client', 'other'], 'company status enum is declared');

$contact = $byKey['business_contact'];
assertBlueprintFields($h, $contact, ['company_id', 'iam_user_id', 'first_name', 'last_name', 'display_name', 'email', 'mobile', 'status'], 'business_contact');
assertEnum($h, $contact, 'status', ['prospect', 'client', 'supplier', 'former_client', 'other'], 'contact status enum is declared');

$memo = $byKey['business_memo'];
$memoFields = array_column($memo['fields'], null, 'key');
assertBlueprintFields($h, $memo, ['company_id', 'contact_id', 'title', 'body', 'is_shared', 'share_expires_at'], 'business_memo');
$h->assertTrue(isset($memoFields['share_token_hash']), 'memo blueprint documents share token hash');
$h->assertSame(true, $memoFields['share_token_hash']['sensitive'] ?? null, 'share token hash is sensitive');
$h->assertSame(false, $memoFields['share_token_hash']['exportable'] ?? null, 'share token hash is not exportable');
$h->assertSame(true, $memo['contract']['noindex_public_share'] ?? null, 'public memo shares remain noindex');

$message = $byKey['business_message'];
$messageFields = array_column($message['fields'], null, 'key');
assertBlueprintFields($h, $message, ['provider', 'channel', 'recipient', 'subject', 'body', 'status'], 'business_message');
$h->assertSame(['email', 'whatsapp', 'telegram'], $messageFields['channel']['validation']['enum'] ?? null, 'message channels are declared');
$h->assertSame('business.messaging.send', $message['permissions']['send'] ?? null, 'message send permission is declared');

$consent = $byKey['business_consent'];
$consentFields = array_column($consent['fields'], null, 'key');
assertBlueprintFields($h, $consent, ['channel', 'status', 'confirmed_at', 'revoked_at'], 'business_consent');
$h->assertSame(['email', 'whatsapp', 'telegram'], $consentFields['channel']['validation']['enum'] ?? null, 'consent channels are declared');
$h->assertSame(['unknown', 'opted_in', 'opted_out'], $consentFields['status']['validation']['enum'] ?? null, 'consent status exposes canonical vocabulary');
$h->assertSame(['unknown', 'opt_in', 'opt_out'], $consentFields['status']['validation']['storage_values'] ?? null, 'consent status documents storage vocabulary');

$forbiddenPublicRoutes = [
    '/api/v1/business/relations',
    '/api/v1/business/contacts',
    '/api/v1/business/companies',
    '/api/v1/business/memos',
    '/api/v1/crm/relations',
    '/api/v1/crm/contacts',
    '/api/v1/crm/companies',
    '/api/v1/crm/memos',
];
$apiRoutes = file_get_contents(__DIR__ . '/../../../../backend/routes/api.php') ?: '';
$webRoutes = file_get_contents(__DIR__ . '/../../../../backend/routes/web.php') ?: '';
foreach ($forbiddenPublicRoutes as $route) {
    $h->assertTrue(!str_contains($apiRoutes, $route), 'public API route is absent: ' . $route);
    $h->assertTrue(!str_contains($webRoutes, $route), 'public web route is absent: ' . $route);
}

$expectedAdminEndpoints = [
    'GET /admin/api/business/relations',
    'POST /admin/api/business/relations',
    'GET /admin/api/business/relations/{type}/{id}',
    'PATCH /admin/api/business/relations/{type}/{id}',
    'POST /admin/api/business/relations/{type}/{id}/archive',
    'DELETE /admin/api/business/relations/{type}/{id}',
    'GET /admin/api/business/companies',
    'POST /admin/api/business/companies',
    'GET /admin/api/business/companies/{id}/contacts',
    'DELETE /admin/api/business/companies/{id}',
    'GET /admin/api/business/contacts',
    'GET /admin/api/business/contacts/available-iam-users',
    'DELETE /admin/api/business/contacts/{id}',
    'GET /admin/api/business/memos',
    'POST /admin/api/business/memos',
    'DELETE /admin/api/business/memos/{id}',
    'POST /admin/api/business/memos/{id}/share',
    'DELETE /admin/api/business/memos/{id}/share',
    'GET /admin/api/business/memos/{id}/shares',
    'POST /admin/api/business/memos/{id}/shares/users',
    'DELETE /admin/api/business/memos/{id}/shares/users/{iamUserId}',
    'GET /admin/api/business/consents',
    'POST /admin/api/business/consents',
    'PATCH /admin/api/business/consents/{id}',
    'POST /admin/api/business/import/relations',
    'POST /admin/api/business/import/contacts',
    'POST /admin/api/business/import/companies',
    'GET /admin/api/business/export/relations',
    'GET /admin/api/business/export/contacts',
    'GET /admin/api/business/export/companies',
    'GET /admin/api/business/export/memos',
    'GET /admin/api/business/messages',
    'POST /admin/api/business/messages',
    'GET /admin/api/business/messages/{id}',
    'POST /admin/api/business/messages/test',
    'GET /admin/api/business/messaging/providers',
    'GET /admin/api/business/contacts/{id}/consents',
    'PATCH /admin/api/business/contacts/{id}/consents/{channel}',
    'GET /admin/api/business/search',
];
foreach ($expectedAdminEndpoints as $endpoint) {
    $h->assertTrue(in_array($endpoint, $adminRouteKeys, true), 'provider declares admin endpoint ' . $endpoint);
}

$fileRouteKeys = [];
foreach (require __DIR__ . '/../../../../backend/routes/api.php' as $route) {
    if (str_starts_with((string) ($route[1] ?? ''), '/admin/api/business/')) {
        $fileRouteKeys[] = strtoupper((string) $route[0]) . ' ' . (string) $route[1];
    }
}
foreach ($adminRouteKeys as $endpoint) {
    $h->assertTrue(in_array($endpoint, $fileRouteKeys, true), 'provider admin endpoint exists in router: ' . $endpoint);
}

$contracts = $provider->apiContracts();
$contractEndpointKeys = array_map(static fn(array $contract): string => strtoupper((string) $contract['method']) . ' ' . (string) $contract['path'], $contracts);
foreach ($expectedAdminEndpoints as $endpoint) {
    $h->assertTrue(in_array($endpoint, $contractEndpointKeys, true), 'admin contract declares endpoint ' . $endpoint);
}
foreach ($contracts as $contract) {
    if (str_starts_with((string) ($contract['path'] ?? ''), '/admin/api/business/')) {
        $h->assertSame('admin', $contract['scope'] ?? null, 'business contract is admin scoped: ' . ($contract['key'] ?? 'unknown'));
        $h->assertTrue((string) ($contract['permission'] ?? '') !== '', 'business contract declares permission: ' . ($contract['key'] ?? 'unknown'));
    }
}

$publicContracts = array_values(array_filter(
    $contracts,
    static fn(array $contract): bool => ($contract['scope'] ?? 'admin') !== 'admin'
        && preg_match('#^/api/v1/(business|crm)(/|$)#', (string) ($contract['path'] ?? '')) === 1
));
$h->assertSame([], $publicContracts, 'Business CRM declares no public /api/v1 business or crm contracts');

exit($h->finish('UNIT business CRM blueprints'));
