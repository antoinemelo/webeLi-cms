<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Business\Repositories\BusinessActivityRepository;
use App\Modules\Business\Repositories\BusinessCompanyRepository;
use App\Modules\Business\Repositories\BusinessConsentRepository;
use App\Modules\Business\Repositories\BusinessContactRepository;
use App\Modules\Business\Services\BusinessConsentService;
use App\Modules\Business\Services\BusinessSegmentationService;
$h = new TestHarness();
[$dir, $path, $db] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/business.sql');

try {
    $companies = new BusinessCompanyRepository($db);
    $contacts = new BusinessContactRepository($db);
    $consents = new BusinessConsentRepository($db);
    $consentService = new BusinessConsentService($consents);
    $segments = new BusinessSegmentationService($db);
    $activities = new BusinessActivityRepository($db);
    $company = $companies->create(1, ['name' => 'Segmentation SA'], 1);
    $alice = $contacts->create(1, ['company_id' => $company['id'], 'display_name' => 'Alice Réelle'], 1);
    $bob = $contacts->create(1, ['company_id' => $company['id'], 'display_name' => 'Bob Réel'], 1);

    $eventId = 0;
    $insertActivity = static function (int $contactId, string $type, string $channel, array $metadata, string $occurred = '2026-07-10 10:00:00') use ($db, &$eventId): void {
        ++$eventId;
        $db->run(
            "INSERT INTO crm_sale_activities(site_id,activity_type,occurred_at,channel,related_company_id,related_contact_id,source_event_id,source_outbox_id,source_id,source_event_type,source_aggregate_type,source_aggregate_id,summary,status,resolution_strategy,metadata_json)
             VALUES(1,?,?,?,?,?,?,?,?,?,'order',?,'Activité test','completed','explicit_event',?)",
            [$type, $occurred, $channel, 1, $contactId, $eventId, $eventId, (string) $eventId, 'sale.' . $type, $eventId, json_encode($metadata)]
        );
    };
    $insertActivity((int) $alice['id'], 'order.placed', 'web', ['order_id' => 10, 'amount_minor' => 10000, 'product_ids' => [5], 'category_ids' => [8]]);
    $insertActivity((int) $alice['id'], 'order.placed', 'web', ['order_id' => 11, 'amount_minor' => 5000, 'product_ids' => [6], 'category_ids' => [8]], '2026-07-11 10:00:00');
    $insertActivity((int) $alice['id'], 'return.created', 'web', ['order_id' => 11, 'return_id' => 2], '2026-07-12 10:00:00');
    $insertActivity((int) $alice['id'], 'gift_card.issued', 'web', ['gift_card_id' => 4], '2026-07-12 11:00:00');
    $insertActivity((int) $bob['id'], 'order.placed', 'pos', ['order_id' => 20, 'amount_minor' => 2000, 'product_ids' => [7]], '2026-07-12 12:00:00');

    $preview = $segments->preview(1, ['criterion' => 'customer_type', 'operator' => 'eq', 'value' => 'recurring']);
    $h->assertSame(1, $preview['count'], 'preview counts recurring contacts from projected activities');
    $h->assertTrue(!str_contains(json_encode($preview['examples']), 'Alice'), 'preview examples are anonymized');
    $h->assertSame('crm_sale_activities', $preview['data_source'], 'preview identifies the projection read model');

    $segment = $segments->create(1, ['name' => 'Clients récurrents', 'criterion' => 'customer_type', 'operator' => 'eq', 'value' => 'recurring', 'retention_days' => 90], 1);
    $h->assertSame('calculated', $segment['segment_kind'], 'calculated and manual segments are distinct');
    $calculation = $segments->recalculate(1, (int) $segment['id'], true);
    $h->assertSame(1, $calculation['segment']['result_count'], 'full calculation stores one recurring member');
    $h->assertTrue((string) $calculation['segment']['last_calculated_at'] !== '', 'calculation date is retained');
    $h->assertSame(1, $calculation['segment']['rule_version'], 'rule version is retained');
    $members = $segments->members(1, (int) $segment['id']);
    $h->assertSame((int) $alice['id'], (int) $members[0]['contact_id'], 'calculated membership is explainable');
    $h->assertTrue(str_contains((string) $members[0]['explanation_json'], 'crm_sale_activities'), 'membership records its projected source');

    $insertActivity((int) $bob['id'], 'order.placed', 'pos', ['order_id' => 21, 'amount_minor' => 3000, 'product_ids' => [7]], '2026-07-13 12:00:00');
    $incremental = $segments->recalculate(1, (int) $segment['id'], false);
    $h->assertSame('incremental', $incremental['mode'], 'incremental recalculation is explicit');
    $h->assertSame(1, $incremental['evaluated_contacts'], 'incremental calculation evaluates only changed contacts');
    $h->assertSame(2, $incremental['segment']['result_count'], 'incremental calculation updates membership without a Sale read');

    $updated = $segments->update(1, (int) $segment['id'], ['criterion' => 'total_spent_minor', 'operator' => 'gte', 'value' => 10000], 1);
    $h->assertSame(2, $updated['rule_version'], 'changing a rule increments its version');
    $h->assertTrue(str_contains((string) $updated['explanation'], 'Montant cumulé'), 'rule explanation is readable');

    $manual = $segments->create(1, ['name' => 'Ambassadeurs', 'segment_kind' => 'manual'], 1);
    $segments->addManualMember(1, (int) $manual['id'], (int) $bob['id'], 1);
    $h->assertSame(1, count($segments->members(1, (int) $manual['id'])), 'manual membership is stored separately');
    $segments->removeManualMember(1, (int) $manual['id'], (int) $bob['id']);
    $h->assertSame([], $segments->members(1, (int) $manual['id']), 'manual membership can be removed');
    $h->expectException(fn() => $segments->preview(1, ['criterion' => '', 'operator' => '', 'value' => '']), InvalidArgumentException::class, 'empty segment rule is rejected');

    $h->expectException(
        fn() => $consentService->setConsent((int) $bob['id'], 'email', 'opt_in', 'checkout', 'Checkout checkbox', 1),
        InvalidArgumentException::class,
        'checkout cannot create a CRM marketing opt-in'
    );
    $h->assertSame(null, $consents->consentForContact((int) $bob['id'], 'email'), 'purchase and account flows leave marketing consent unknown');
    $consentService->setConsent((int) $alice['id'], 'email', 'opt_in', 'form', 'Formulaire newsletter #42', 1, 'marketing', 730);
    $consentService->setConsent((int) $alice['id'], 'email', 'opt_out', 'unsubscribe', 'Lien de désabonnement #43', 1, 'marketing', 730);
    $current = $consents->consentForContact((int) $alice['id'], 'email');
    $history = $consents->historyForContact((int) $alice['id']);
    $h->assertSame('opt_out', $current['consent_status'] ?? null, 'withdrawal updates the current state');
    $h->assertSame(2, count($history), 'withdrawal retains the legally necessary consent history');
    $h->assertSame('withdrawn', $history[0]['event_type'] ?? null, 'withdrawal is explicit in the ledger');
    $h->assertSame('marketing', $history[0]['purpose'] ?? null, 'marketing purpose remains separate and visible');
    $h->assertSame('site', $history[0]['scope_type'] ?? null, 'consent scope is visible');
    $h->assertTrue(str_contains((string) ($history[0]['proof_json'] ?? ''), 'acteur') === false, 'proof contains no invented personal data');

    $preference = $consents->upsertPreference(1, (int) $bob['id'], ['preferred_channel' => 'whatsapp', 'contact_window' => 'Après 17h', 'do_not_contact' => true, 'source' => 'manual'], 1);
    $h->assertSame('whatsapp', $preference['preferred_channel'] ?? null, 'contact preference is stored independently');
    $h->assertSame(true, $preference['do_not_contact'] ?? null, 'do-not-contact preference is explicit');
    $h->assertSame(null, $consents->consentForContact((int) $bob['id'], 'whatsapp'), 'a contact preference never creates consent');

    $timeline = $activities->relationActivity(1, 'contact', (int) $alice['id']);
    $consentItems = array_values(array_filter($timeline['items'], static fn(array $item): bool => $item['kind'] === 'consent'));
    $h->assertSame(2, count($consentItems), 'consent grants and withdrawals appear in the relation chronology');
    $h->assertSame('unsubscribe', $consentItems[0]['metadata']['source'] ?? null, 'chronology exposes consent provenance');

    $source = file_get_contents(__DIR__ . '/../../../../backend/src/Modules/Business/Services/BusinessSegmentationService.php') ?: '';
    $h->assertTrue(!str_contains($source, 'sale_orders') && !str_contains($source, 'sale_order_lines'), 'segmentation has no coupling to Sale transaction tables');

} finally {
    $db = null;
    gc_collect_cycles();
    test_remove_tree($dir);
}

exit($h->finish('UNIT business segmentation, preferences and consent chronology'));
