<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Business\Repositories\BusinessActivityRepository;
use App\Modules\Business\Services\SaleCrmActivityProjectionService;
use App\Modules\Sale\Contracts\CrmActivitySink;
use App\Modules\Sale\Pricing\SalePricingService;
use App\Modules\Sale\Repositories\SaleEventRepository;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Modules\Sale\Services\SaleEventService;

$h = new TestHarness();
[$businessDir, $businessPath, $business] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/business.sql');
[$saleDir, $salePath, $sale] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/sale.sql');

try {
    $business->run("INSERT INTO business_companies(site_id,name,normalized_name,status) VALUES(1,'CRM Test SA','crm test sa','client')");
    $companyId = $business->lastInsertId();
    $business->run("INSERT INTO business_contacts(site_id,company_id,iam_user_id,display_name,normalized_name,status,email) VALUES(1,?,77,'Client Test','client test','client','client@example.test')", [$companyId]);
    $contactId = $business->lastInsertId();

    $webChannelId = (int) ($sale->one("SELECT id FROM sale_channels WHERE site_id=1 AND channel_type='ecommerce' LIMIT 1")['id'] ?? 0);
    $posChannelId = (int) ($sale->one("SELECT id FROM sale_channels WHERE site_id=1 AND channel_type='pos' LIMIT 1")['id'] ?? 0);
    $sale->run("INSERT INTO sale_channels(site_id,code,name,channel_type,channel_kind,status) VALUES(2,'web-2','Web 2','ecommerce','storefront','active')");
    $site2ChannelId = $sale->lastInsertId();

    $sale->run("INSERT INTO sale_orders(site_id,channel_id,order_number,source,status,currency,customer_company_id,customer_contact_id,customer_snapshot_json,grand_total_minor) VALUES(1,?,'WEB-2101','ecommerce','placed','CHF',?,?,?,12900)", [$webChannelId, $companyId, $contactId, '{"display_name":"Frozen Customer"}']);
    $webOrderId = $sale->lastInsertId();
    $sale->run("INSERT INTO sale_orders(site_id,channel_id,order_number,source,status,currency,customer_snapshot_json,grand_total_minor) VALUES(1,?,'POS-2102','pos','placed','CHF','{}',4500)", [$posChannelId]);
    $posOrderId = $sale->lastInsertId();
    $sale->run("INSERT INTO sale_orders(site_id,channel_id,order_number,source,status,currency,customer_snapshot_json,grand_total_minor) VALUES(2,?,'WEB2-2103','ecommerce','placed','CHF','{}',2200)", [$site2ChannelId]);
    $site2OrderId = $sale->lastInsertId();

    $events = new SaleEventService(new SaleEventRepository(new SaleDatabaseConnection($salePath)));
    $events->emit(1, 'sale.order.placed', 'order', $webOrderId, [
        'site_id' => 1, 'order_id' => $webOrderId, 'order_number' => 'WEB-2101',
        'grand_total_minor' => 12900, 'currency' => 'CHF', 'source' => 'ecommerce', 'iam_user_id' => 77,
    ], 77, 'crm-projection-web');
    $events->emit(1, 'sale.payment.failed', 'order', $webOrderId, [
        'site_id' => 1, 'order_id' => $webOrderId, 'transaction_id' => 91,
        'amount_minor' => 12900, 'currency' => 'CHF', 'provider_key' => 'test', 'error_code' => 'declined',
    ], null, 'crm-projection-payment');
    $events->emit(1, 'sale.pos.order.completed', 'order', $posOrderId, [
        'site_id' => 1, 'order_id' => $posOrderId, 'order_number' => 'POS-2102',
        'grand_total_minor' => 4500, 'currency' => 'CHF', 'source' => 'pos',
    ], null, 'crm-projection-pos');
    $events->emit(2, 'sale.order.placed', 'order', $site2OrderId, [
        'site_id' => 2, 'order_id' => $site2OrderId, 'order_number' => 'WEB2-2103',
        'grand_total_minor' => 2200, 'currency' => 'CHF', 'source' => 'ecommerce',
    ], null, 'crm-projection-site2');

    $projection = new SaleCrmActivityProjectionService($business, new SaleDatabaseConnection($salePath));
    $h->assertTrue($projection instanceof CrmActivitySink, 'production CRM projection is the real Sale activity port adapter');
    $first = $projection->consume(1);
    $h->assertSame(3, $first['projected'], 'site-scoped subscriber projects supported web, payment and POS events');
    $h->assertSame(0, (int) ($business->one('SELECT COUNT(*) AS count FROM crm_sale_activities WHERE site_id=2')['count'] ?? -1), 'site-scoped subscriber does not leak another site');

    $replay = $projection->consume(1);
    $h->assertSame(0, $replay['projected'], 'replay does not create a second projection');
    $h->assertSame(3, $replay['replayed'], 'replay recognizes all existing source events');
    $h->assertSame(3, (int) ($business->one('SELECT COUNT(*) AS count FROM crm_sale_activities WHERE site_id=1')['count'] ?? 0), 'source event uniqueness prevents duplicate activities');
    $projection->recordSaleEvent(['site_id'=>1]);
    $h->assertSame(3, (int) ($business->one('SELECT COUNT(*) AS count FROM crm_sale_activities WHERE site_id=1')['count'] ?? 0), 'real activity sink replays idempotently');

    $timeline = (new BusinessActivityRepository($business))->relationActivity(1, 'contact', $contactId, 20, 0);
    $saleTimeline = array_values(array_filter($timeline['items'], static fn(array $item): bool => $item['kind'] === 'sale'));
    $h->assertSame(2, count($saleTimeline), 'web order and payment are visible in the CRM contact timeline');
    $h->assertSame('web', $saleTimeline[0]['metadata']['channel'] ?? null, 'timeline exposes the commercial channel');

    $unlinked = $projection->unlinked(1);
    $h->assertSame(1, $unlinked['total'], 'anonymous POS sale remains unlinked');
    $h->assertSame(null, $unlinked['items'][0]['related_contact_id'] ?? null, 'anonymous sale does not force a fake contact');
    $h->assertSame(1, (int) ($business->one("SELECT COUNT(*) AS count FROM business_contacts WHERE email='client@example.test'")['count'] ?? 0), 'projection never creates a contact from an email');

    $saleBefore = $sale->one('SELECT customer_company_id,customer_contact_id,customer_snapshot_json FROM sale_orders WHERE id=?', [$posOrderId]);
    $linked = $projection->link(1, (int) $unlinked['items'][0]['id'], $contactId, null, 9, 'Client identifié au comptoir');
    $saleAfter = $sale->one('SELECT customer_company_id,customer_contact_id,customer_snapshot_json FROM sale_orders WHERE id=?', [$posOrderId]);
    $h->assertSame('manual', $linked['resolution_strategy'], 'late CRM link is explicitly marked manual');
    $h->assertSame($contactId, $linked['related_contact_id'], 'late CRM link targets the selected contact');
    $h->assertSame($saleBefore, $saleAfter, 'late CRM link never mutates Sale identity or customer snapshot');
    $h->assertSame(1, (int) ($business->one('SELECT COUNT(*) AS count FROM crm_sale_activity_link_audit')['count'] ?? 0), 'late link is recorded in immutable audit');

    $business->run("INSERT INTO business_tags(site_id,tag_key,label) VALUES(1,'vip','VIP')");
    $tagId = $business->lastInsertId();
    $business->run("INSERT INTO business_tag_links(tag_id,target_type,contact_id) VALUES(?,'contact',?)", [$tagId, $contactId]);
    $pricing = new SalePricingService();
    $context = $pricing->customerContext($projection, 1, $contactId);
    $h->assertSame(['vip'], $context->segments, 'Pricing receives read-only CRM segments through a port');
    $h->assertSame(false, $context->marketingAllowed, 'marketing is denied without explicit opt-in');
    $business->run("INSERT INTO crm_consents(contact_id,channel,consent_status,source,granted_at) VALUES(?,'email','opt_in','manual',CURRENT_TIMESTAMP)", [$contactId]);
    $h->assertSame(true, $pricing->customerContext($projection, 1, $contactId)->marketingAllowed, 'marketing context reflects explicit consent');

    $paymentEventId = (int) ($sale->one("SELECT id FROM sale_events WHERE event_type='sale.payment.failed' AND site_id=1")['id'] ?? 0);
    $business->run('DELETE FROM crm_sale_activities WHERE source_event_id=?', [$paymentEventId]);
    $report = $projection->reconcile(1, 9, true);
    $h->assertSame(1, $report['repaired_events'], 'reconciliation repairs a missing activity');
    $h->assertSame(0, $report['missing_events'], 'reconciliation finishes without missing supported event');
    $h->assertSame(0, $report['duplicate_events'], 'reconciliation reports no duplicate');

    $projection->consume(2);
    $site2Activity = $business->one('SELECT id FROM crm_sale_activities WHERE site_id=2 LIMIT 1');
    $h->assertTrue($site2Activity !== null, 'second site can project its own activity');
    $h->expectException(
        fn() => $projection->link(2, (int) $site2Activity['id'], $contactId, null, 9, 'invalid cross-site link'),
        InvalidArgumentException::class,
        'cross-site CRM link is rejected'
    );
} finally {
    $business = null;
    $sale = null;
    gc_collect_cycles();
    test_remove_tree($businessDir);
    test_remove_tree($saleDir);
}

exit($h->finish('UNIT Sale to CRM activity projection'));
