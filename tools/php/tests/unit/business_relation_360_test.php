<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Application\Forms\FormRelationAddressToken;
use App\Application\Forms\FormRepository;
use App\Core\Database;
use App\Modules\Business\Repositories\BusinessActivityRepository;
use App\Modules\Business\Repositories\BusinessCompanyRepository;
use App\Modules\Business\Repositories\BusinessContactRepository;
use App\Modules\Business\Repositories\BusinessMemoRepository;
use App\Modules\Business\Repositories\BusinessRelationReadRepository;
use App\Modules\Business\Repositories\BusinessRelationRepository;
use App\Modules\Business\Repositories\BusinessTagRepository;
use App\Modules\Business\Services\BusinessCrmService;
use App\Modules\Business\Services\BusinessRelation360Service;
use App\Modules\Business\Services\FormSubmissionRelationProjectionService;

$h = new TestHarness();
[$businessDir, $businessPath, $business] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/business.sql');
[$formsDir, $formsPath, $forms] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/forms.sql');
[$upgradeDir, $upgradePath, $upgrade] = test_temp_cms_db(__DIR__ . '/../../../../database/migrations/business/0001_init.sql');

try {
    $upgradeSql = (string) file_get_contents(__DIR__ . '/../../../../database/migrations/business/0013_relation_360.sql');
    $upgrade->pdo()->exec($upgradeSql);
    $upgrade->pdo()->exec($upgradeSql);
    foreach (['business_relation_roles', 'business_relation_tasks', 'crm_form_submission_activities', 'crm_form_submission_link_audit'] as $table) {
        $h->assertTrue($upgrade->tableExists($table), 'installed Business database upgrades idempotently: ' . $table);
    }
    $companies = new BusinessCompanyRepository($business);
    $contacts = new BusinessContactRepository($business);
    $crm = new BusinessCrmService($companies, $contacts, new BusinessTagRepository($business));
    $company = $crm->createCompany(1, ['name' => 'Relation 360 SA', 'status' => 'client'], 1);
    $contact = $crm->createContact(1, ['company_id' => $company['id'], 'display_name' => 'Ada Relation', 'email' => 'ada@example.test', 'status' => 'client'], 1);
    $standalone = $crm->createContact(1, ['display_name' => 'Personne autonome', 'email' => 'person@example.test', 'status' => 'prospect'], 1);
    $otherSite = $crm->createCompany(2, ['name' => 'Autre site'], 1);

    $business->run("INSERT INTO business_relation_roles(site_id,relation_type,contact_id,role_key,source) VALUES(1,'contact',?,'client','operator'),(1,'contact',?,'supplier','operator')", [$contact['id'], $contact['id']]);
    $business->run("INSERT INTO business_relation_tasks(site_id,relation_type,contact_id,title,due_at,priority,created_by_iam_user_id) VALUES(1,'contact',?,'Rappeler Ada','2026-08-01 09:00:00','high',1)", [$contact['id']]);

    $saleRows = [
        ['order.placed', 'order', 'Commande WEB-42 créée', 'placed', 'web', 42, 'WEB-42'],
        ['order.placed', 'order', 'Commande POS-43 créée', 'placed', 'pos', 43, 'POS-43'],
        ['order.placed', 'order', 'Commande différée ADM-44 créée', 'placed', 'admin', 44, 'ADM-44'],
        ['payment.failed', 'payment', 'Paiement refusé', 'failed', 'web', 42, 'WEB-42'],
        ['payment.completed', 'payment', 'Paiement reçu', 'paid', 'web', 42, 'WEB-42'],
        ['invoice.issued', 'invoice', 'Facture en attente', 'unpaid', 'web', 42, 'WEB-42'],
        ['invoice.paid', 'invoice', 'Facture payée', 'paid', 'web', 42, 'WEB-42'],
        ['refund.completed', 'refund', 'Remboursement effectué', 'refunded', 'web', 42, 'WEB-42'],
        ['fulfillment.delivered', 'fulfillment', 'Commande livrée', 'delivered', 'admin', 42, 'WEB-42'],
    ];
    foreach ($saleRows as $index => [$event, $activityType, $summary, $status, $channel, $orderId, $reference]) {
        $business->run(
            "INSERT INTO crm_sale_activities(site_id,activity_type,occurred_at,channel,related_company_id,related_contact_id,source_event_id,source_outbox_id,source_id,source_event_type,source_aggregate_type,source_aggregate_id,source_reference,summary,status,resolution_strategy,metadata_json)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [1, $activityType, sprintf('2026-07-15 10:00:%02d', $index), $channel, $company['id'], $contact['id'], $index + 1, $index + 1, 'event-' . $index, $event, 'order', $orderId, $reference, $summary, $status, 'explicit_event', json_encode(['order_id' => $orderId, 'channel' => $channel])]
        );
    }

    $projector = new FormSubmissionRelationProjectionService($business);
    $projector->recordFormSubmission([
        'site_id' => 1, 'form_id' => 7, 'form_key' => 'support', 'submission_id' => 96, 'status' => 'received',
        'addressed_relation' => ['type' => 'contact', 'id' => $standalone['id']],
    ]);
    $h->assertSame('explicit', $projector->forRelation(1, 'contact', (int) $standalone['id'])[0]['resolution_strategy'] ?? null, 'an independent person without an organisation accepts an explicit form projection');
    $projector->recordFormSubmission([
        'site_id' => 1, 'form_id' => 7, 'form_key' => 'support', 'form_name' => 'Support',
        'submission_id' => 99, 'status' => 'received', 'addressed_relation' => ['type' => 'contact', 'id' => $contact['id']],
        'payload' => ['secret' => 'must-not-be-copied'],
    ]);
    $projected = $projector->forRelation(1, 'contact', (int) $contact['id']);
    $h->assertSame('explicit', $projected[0]['resolution_strategy'] ?? null, 'signed explicit form relation is projected');
    $columns = array_column($business->all('PRAGMA table_info(crm_form_submission_activities)'), 'name');
    $h->assertSame(false, in_array('payload_json', $columns, true), 'form payload has no column in CRM projection');

    $business->run("INSERT INTO crm_contact_channels(contact_id,channel,channel_value,normalized_value,is_primary,is_verified) VALUES(?,'email','verified@example.test','verified@example.test',1,1)", [$contact['id']]);
    $projector->recordFormSubmission(['site_id' => 1, 'form_id' => 7, 'form_key' => 'support', 'submission_id' => 98, 'status' => 'received', 'verified_email' => 'verified@example.test']);
    $certain = array_values(array_filter($projector->forRelation(1, 'contact', (int) $contact['id']), static fn(array $row): bool => (int) $row['submission_id'] === 98))[0] ?? [];
    $h->assertSame('verified_email', $certain['resolution_strategy'] ?? null, 'one unique verified CRM email is sufficient documented evidence');
    $ambiguousContact = $crm->createContact(1, ['company_id' => $company['id'], 'display_name' => 'Autre profil', 'email' => 'other@example.test', 'status' => 'prospect'], 1);
    $business->run("INSERT INTO crm_contact_channels(contact_id,channel,channel_value,normalized_value,is_primary,is_verified) VALUES(?,'email','verified@example.test','verified@example.test',1,1)", [$ambiguousContact['id']]);
    $projector->recordFormSubmission(['site_id' => 1, 'form_id' => 7, 'form_key' => 'support', 'submission_id' => 97, 'status' => 'received', 'verified_email' => 'verified@example.test']);
    $ambiguous = array_values(array_filter($projector->pending(1), static fn(array $row): bool => (int) $row['submission_id'] === 97))[0] ?? [];
    $h->assertSame(2, (int) ($ambiguous['candidate_count'] ?? 0), 'multiple verified candidates remain in the review queue without automatic merge');

    $projector->recordFormSubmission(['site_id' => 1, 'form_id' => 7, 'form_key' => 'support', 'submission_id' => 100, 'status' => 'received']);
    $pending = $projector->pending(1);
    $pendingSubmission = array_values(array_filter($pending, static fn(array $row): bool => (int) $row['submission_id'] === 100))[0] ?? [];
    $h->assertSame(2, count($pending), 'submissions without reliable or unique identity are queued');
    $decision = $projector->decide(1, (int) $pendingSubmission['id'], 'link', 'contact', (int) $contact['id'], 1, 'Adresse confirmée par le client');
    $h->assertSame('manual', $decision['resolution_strategy'] ?? null, 'operator can auditably link an ambiguous submission');
    $projector->recordFormSubmission(['site_id' => 1, 'form_id' => 7, 'form_key' => 'support', 'submission_id' => 101, 'status' => 'received']);
    $projector->recordFormSubmission(['site_id' => 1, 'form_id' => 7, 'form_key' => 'support', 'submission_id' => 102, 'status' => 'received']);
    $pendingBySubmission = [];
    foreach ($projector->pending(1) as $item) $pendingBySubmission[(int) $item['submission_id']] = $item;
    $dismissed = $projector->decide(1, (int) $pendingBySubmission[101]['id'], 'unlink', null, null, 1, 'Aucune relation commerciale');
    $postponed = $projector->decide(1, (int) $pendingBySubmission[102]['id'], 'postpone', null, null, 1, 'Vérification ultérieure requise');
    $h->assertSame('dismissed', $dismissed['resolution_strategy'] ?? null, 'operator can dismiss an ambiguous submission with a reason');
    $h->assertSame('postponed', $postponed['resolution_strategy'] ?? null, 'operator can postpone an ambiguous submission with a reason');
    $h->assertSame(1, count($projector->pending(1)), 'resolved and postponed submissions leave the queue while ambiguity remains visible');
    $h->expectException(fn() => $business->run('UPDATE crm_form_submission_link_audit SET reason=? WHERE id=1', ['rewrite']), PDOException::class, 'form link audit is immutable');

    $relations = new BusinessRelationReadRepository(new BusinessRelationRepository($business), new BusinessMemoRepository($business));
    $service = new BusinessRelation360Service($business, $relations, new BusinessActivityRepository($business), $projector);
    $business->run("INSERT INTO crm_consent_events(site_id,contact_id,channel,scope_id,consent_status,event_type,source) VALUES(1,?,'email',1,'opt_out','withdrawn','manual')", [$contact['id']]);
    $view = $service->view(1, 'contact', (int) $contact['id'], false);
    $personView = $service->view(1, 'contact', (int) $standalone['id'], false);
    $h->assertSame('Personne autonome', $personView['relation']['display_name'] ?? null, 'a standalone person has a Relation 360 view');
    $companyRoles = $service->replaceRoles(1, 'company', (int) $company['id'], ['supplier'], 1);
    $h->assertSame('supplier', $companyRoles[0]['role_key'] ?? null, 'an organisation can be managed as a supplier');
    $h->assertSame(['client', 'supplier'], array_column($view['relation']['roles'], 'role_key'), 'one relation supports several business roles');
    $h->assertSame('Rappeler Ada', $view['next_action']['title'] ?? null, 'explicit follow-up task is the next action');
    $h->assertSame(false, in_array('consent', array_column($view['timeline'], 'kind'), true), 'withdrawn consent evidence is hidden without its dedicated permission');
    $consentedView = $service->view(1, 'contact', (int) $contact['id'], true);
    $h->assertTrue(in_array('consent', array_column($consentedView['timeline'], 'kind'), true), 'withdrawn consent is visible with permission');
    $h->assertSame('Rappeler Ada', $consentedView['next_action']['title'] ?? null, 'withdrawn marketing consent does not block transactional follow-up');
    $h->assertSame(3, count($view['orders']), 'e-commerce, POS and deferred orders are exposed through Sale projections');
    $webOrder = array_values(array_filter($view['orders'], static fn(array $row): bool => (int) $row['order_id'] === 42))[0] ?? [];
    $h->assertSame('/sale/orders?order_id=42&return_to=%2Fbusiness%2Frelations%3Frelation_type%3Dcontact%26relation_id%3D' . $contact['id'], $webOrder['owner_link'] ?? null, 'order projection preserves an encoded return to the relation');
    $h->assertSame(5, count($view['financial']), 'paid, unpaid, failed and refunded financial events are exposed through Sale projections');
    $h->assertSame(['refunded', 'paid', 'unpaid', 'paid', 'failed'], array_column($view['financial'], 'status'), 'financial projection preserves business statuses');
    $h->assertSame(1, count($view['fulfillments']), 'fulfillment event is exposed through Sale projection');
    $h->assertSame('delivered', $view['fulfillments'][0]['status'] ?? null, 'delivery status is visible');
    $h->assertTrue(in_array('form', array_column($view['timeline'], 'kind'), true), 'form projection joins the common timeline');
    $h->assertSame('sale', $view['ownership']['orders'] ?? null, 'Sale remains canonical owner of orders');
    $h->assertSame('core_forms', $view['ownership']['forms'] ?? null, 'Forms remains canonical owner of submissions');
    $degraded = $service->degradedView(1, 'contact', (int) $contact['id'], false, 'forms');
    $h->assertSame('Ada Relation', $degraded['relation']['display_name'] ?? null, 'projection failure preserves the canonical CRM relation');
    $h->assertSame(['sale' => 'available', 'forms' => 'unavailable', 'degraded' => true], $degraded['projection_health'] ?? null, 'projection failure is explicit without claiming Sale data loss');
    $roles = $service->replaceRoles(1, 'contact', (int) $contact['id'], ['client', 'partner'], 1);
    $h->assertSame(['client', 'partner'], array_column($roles, 'role_key'), 'operator can replace the explicit multi-role set');
    $h->expectException(fn() => $service->assertExists(1, 'company', (int) $otherSite['id']), InvalidArgumentException::class, 'a form address cannot be issued for a relation from another site');
    $h->expectException(fn() => $service->view(1, 'company', (int) $otherSite['id'], false), InvalidArgumentException::class, 'relation view is isolated by site');

    $list = (new BusinessRelationRepository($business))->list(1, ['view' => 'follow_up']);
    $h->assertTrue(in_array((int) $contact['id'], array_map('intval', array_column($list['items'], 'id')), true), 'follow-up view uses open relation tasks');

    $tokenService = new FormRelationAddressToken('unit-secret');
    $token = $tokenService->issue(1, 'contact', 'contact', (int) $contact['id']);
    $h->assertSame(['type' => 'contact', 'id' => (int) $contact['id']], $tokenService->verify($token, 1, 'contact'), 'relation address token is site and form scoped');
    $h->assertSame(null, $tokenService->verify($token, 2, 'contact'), 'relation address token cannot cross sites');

    $forms->run("INSERT INTO forms(id,site_id,form_key,status,is_active,store_submissions,notification_enabled,min_submit_seconds) VALUES(1,1,'contact','published',1,1,0,0)");
    $forms->run("INSERT INTO form_translations(form_id,language_code,name,submit_label,success_message) VALUES(1,'fr','Contact','Envoyer','Merci')");
    $forms->run("INSERT INTO form_fields(id,form_id,field_key,field_type,is_required,is_active) VALUES(1,1,'email','email',1,1)");
    $forms->run("INSERT INTO form_field_translations(field_id,language_code,label) VALUES(1,'fr','E-mail')");
    $formRepository = new FormRepository($forms, $projector, $tokenService);
    $result = $formRepository->submit(1, 'contact', 'fr', ['_relation_token' => $token, 'values' => ['email' => 'new@example.test']], ['ip_hash' => 'unit']);
    $h->assertTrue((int) ($result['submission_id'] ?? 0) > 0, 'canonical form submission succeeds with relation address');
    $h->assertSame(4, count($projector->forRelation(1, 'contact', (int) $contact['id'])), 'form submission emits the privacy-minimised CRM activity');
} finally {
    $business = null; $forms = null; $upgrade = null;
    gc_collect_cycles();
    test_remove_tree($businessDir);
    test_remove_tree($formsDir);
    test_remove_tree($upgradeDir);
}

exit($h->finish('UNIT business Relation 360'));
