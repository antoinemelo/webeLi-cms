<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Sale\Repositories\SaleEventRepository;
use App\Modules\Sale\Repositories\SaleInventoryRepository;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Modules\Sale\Services\SaleEventService;
use App\Modules\Sale\Services\SaleImportExportReportService;
use App\Modules\Sale\Services\SaleInventoryService;
use App\Modules\Sale\Services\SaleOrderDocumentService;
use App\Modules\Sale\Services\SaleOrderNotificationService;

$h = new TestHarness();
[$dir,$path,$db] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/sale.sql');

try {
    $connection = new SaleDatabaseConnection($path);
    $documents = new SaleOrderDocumentService($connection);
    $events = new SaleEventService(new SaleEventRepository($connection));
    $notifications = new SaleOrderNotificationService($connection,$events);
    $reports = new SaleImportExportReportService($connection,new SaleInventoryService(new SaleInventoryRepository($connection)));
    $channelId = (int)($db->one("SELECT id FROM sale_channels WHERE site_id=1 ORDER BY id LIMIT 1")['id'] ?? 0);

    $policy = $documents->updatePolicy(1,[
        'invoice_trigger'=>'paid','invoice_series'=>'F26','credit_note_series'=>'A26',
        'seller_snapshot'=>['name'=>'Vendeur Démonstration SA','country'=>'CH'],
        'gift_card_policy'=>'redemption','legal_notice'=>'Politique de démonstration à valider juridiquement.',
    ],1);
    $h->assertSame(true,$policy['seller_configured'],'seller identity is explicitly configured');
    $h->assertSame('redemption',$policy['gift_card_policy'],'gift card treatment is policy, not a universal legal assumption');

    $makeOrder = static function (string $number, string $source, int $total, string $type = 'physical') use ($db,$channelId): int {
        $db->run("INSERT INTO sale_orders(site_id,channel_id,order_number,source,status,payment_status,fulfillment_status,currency,customer_snapshot_json,billing_address_json,shipping_address_json,shipping_method_snapshot_json,subtotal_minor,tax_total_minor,discount_total_minor,shipping_total_minor,grand_total_minor,paid_total_minor,placed_at) VALUES(1,?,? ,?,'confirmed','paid','fulfilled','CHF','{\"name\":\"Ada Exemple\",\"email\":\"ada@example.test\"}','{}','{}','{}',?,200,100,0,?,?,CURRENT_TIMESTAMP)",[$channelId,$number,$source,$total-100,$total,$total]);
        $id = (int)$db->lastInsertId();
        $db->run("INSERT INTO sale_order_lines(order_id,line_number,business_product_id,business_variant_id,sellable_id,sku,product_name,product_type,quantity,unit_price_minor,regular_unit_price_minor,currency,line_subtotal_minor,line_tax_minor,line_total_minor,snapshot_json) VALUES(?,1,101,201,201,'SKU-201','Produit figé',?,2,?,?,'CHF',?,200,?,'{\"product_type\":\"".$type."\",\"category_ids\":[5],\"group_ids\":[9]}')",[$id,$type,intdiv($total,2),intdiv($total,2),$total-100,$total]);
        $db->run("INSERT INTO sale_order_tax_lines(order_id,tax_class_code,tax_rate_basis_points,taxable_amount_minor,tax_amount_minor,currency) VALUES(?,'standard',810,?,200,'CHF')",[$id,$total-200]);
        return $id;
    };

    $firstId = $makeOrder('SALE-47-1','ecommerce',3000,'gift_card');
    $secondId = $makeOrder('SALE-47-2','pos',5000);
    $first = $documents->issue($firstId,'invoice','fr',1);
    $second = $documents->issue($secondId,'invoice','fr',1);
    $h->assertSame('F26-1-'.gmdate('Y').'-000001',$first['document_number'],'invoice uses scoped sequential series');
    $h->assertSame('F26-1-'.gmdate('Y').'-000002',$second['document_number'],'sequence advances without order-number coupling');
    $h->assertSame('Vendeur Démonstration SA',$first['snapshot']['seller']['name'],'seller is frozen in invoice snapshot');
    $h->assertSame(810,(int)$first['snapshot']['tax_lines'][0]['tax_rate_basis_points'],'tax evidence is frozen');
    $h->assertTrue(strlen((string)$first['document_hash']) === 64,'document exposes a SHA-256 hash');
    $h->assertSame(true,$documents->issue($firstId,'invoice','fr',1)['replayed'],'same immutable invoice is idempotent');
    $h->expectException(fn() => $db->run("UPDATE sale_order_documents SET text_snapshot='changed' WHERE id=?",[(int)$first['id']]),PDOException::class,'issued invoice content cannot be rewritten');

    $db->run('UPDATE sale_orders SET refunded_total_minor=1000,payment_status=\'partially_refunded\' WHERE id=?',[$firstId]);
    $credit = $documents->issue($firstId,'credit_note','fr',1);
    $h->assertSame('A26-1-'.gmdate('Y').'-000001',$credit['document_number'],'refund correction uses a separate credit-note series');

    $delivery = $notifications->queueDocument(1,$firstId,(int)$first['id'],'fr',1);
    $h->assertSame('queued',$delivery['status'],'invoice delivery is queued through the transactional outbox');
    $h->assertSame(1,(int)($db->one('SELECT COUNT(*) count FROM sale_document_deliveries WHERE document_id=?',[(int)$first['id']])['count'] ?? 0),'document delivery is audited without storing recipient in clear text');

    $dashboard = $reports->salesDashboard(1,['category_id'=>5]);
    $h->assertSame(2,$dashboard['summary']['orders_count'],'snapshot category filter keeps matching orders');
    $h->assertSame(8000,$dashboard['summary']['ordered_minor'],'ordered total definition is reproducible');
    $h->assertSame(1000,$dashboard['summary']['refunded_minor'],'refund indicator is included');
    $h->assertSame(4,$dashboard['summary']['units_count'],'units are aggregated from frozen lines');
    $h->assertSame(2,$dashboard['summary']['gift_card_units'],'gift-card sales are explicit and policy-dependent');
    $csv = $reports->salesDashboardCsv(1,['source'=>'pos']);
    $h->assertTrue(str_contains($csv,'SALE-47-2') && !str_contains($csv,'SALE-47-1'),'controlled export applies the same source filter');
    $reports->auditExport(1,'sales_dashboard',['source'=>'pos'],1,1);
    $h->assertSame(1,(int)($db->one("SELECT COUNT(*) count FROM sale_admin_export_audit WHERE site_id=1 AND export_type='sales_dashboard'")['count'] ?? 0),'client-data export is site-scoped and audited');
} finally {
    $db = null;
    gc_collect_cycles();
    test_remove_tree($dir);
}

exit($h->finish('UNIT M8.9 invoicing sales inventory 47'));
