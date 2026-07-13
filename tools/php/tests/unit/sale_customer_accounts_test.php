<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Business\Repositories\BusinessCompanyRepository;
use App\Modules\Business\Repositories\BusinessConsentRepository;
use App\Modules\Business\Repositories\BusinessContactRepository;
use App\Modules\Sale\Exceptions\SaleValidationException;
use App\Modules\Sale\Repositories\SaleInventoryRepository;
use App\Modules\Sale\Services\SaleCustomerAccountService;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Modules\Sale\Services\SaleInventoryService;
use App\Modules\Sale\Services\SaleReturnService;
use App\Modules\Sale\Services\SaleStateMachineService;

$h = new TestHarness();
[$iamDir, $iamPath, $iam] = test_temp_cms_db(__DIR__ . '/../../../../database/iam.sql');
[$businessDir, $businessPath, $business] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/business.sql');
[$saleDir, $salePath, $sale] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/sale.sql');

try {
    $connection = new SaleDatabaseConnection($salePath);
    $companies = new BusinessCompanyRepository($business);
    $contacts = new BusinessContactRepository($business);
    $accounts = new SaleCustomerAccountService(
        $iam, $connection, $companies, $contacts, new BusinessConsentRepository($business),
        new SaleReturnService($connection, new SaleStateMachineService($sale), new SaleInventoryService(new SaleInventoryRepository($connection)))
    );
    $channelId = (int) ($sale->one('SELECT id FROM sale_channels WHERE site_id=1 ORDER BY id LIMIT 1')['id'] ?? 0);
    $insertOrder = static function (int $siteId, int $channel, string $number, string $email) use ($sale): int {
        $identity = json_encode(['email' => $email, 'first_name' => 'Alice', 'last_name' => $number]);
        $address = json_encode(['line1' => 'Rue 1', 'postal_code' => '1000', 'city' => 'Lausanne', 'country_code' => 'CH']);
        $sale->run("INSERT INTO sale_orders(site_id,channel_id,order_number,source,status,payment_status,currency,customer_snapshot_json,billing_address_json,shipping_address_json,grand_total_minor,placed_at) VALUES(?,?,?,'ecommerce','placed','paid','CHF',?,?,?,?,CURRENT_TIMESTAMP)", [$siteId, $channel, $number, $identity, $address, $address, 1000]);
        $orderId = (int) $sale->lastInsertId();
        $sale->run("INSERT INTO sale_order_lines(order_id,line_number,business_product_id,business_variant_id,product_name,quantity,unit_price_minor,regular_unit_price_minor,currency,line_subtotal_minor,line_total_minor,snapshot_json) VALUES(?,1,1,1,'Produit',1,1000,1000,'CHF',1000,1000,'{}')", [$orderId]);
        return $orderId;
    };

    $individuals = $companies->ensureSystemIndividualsCompany(1);
    $contacts->create(1, ['company_id' => $individuals['id'], 'first_name' => 'Doublon', 'last_name' => 'Un', 'email' => 'alice@example.test']);
    $contacts->create(1, ['company_id' => $individuals['id'], 'first_name' => 'Doublon', 'last_name' => 'Deux', 'email' => 'alice@example.test']);

    $order1 = $insertOrder(1, $channelId, 'P13-001', 'alice@example.test');
    $proof1 = $accounts->issueClaimProof($order1);
    $registered = $accounts->registerWithProof(1, $proof1['token'], 'mot-de-passe-solide');
    $user1 = (int) $registered['user']['id'];
    $h->assertTrue($user1 > 0, 'post-purchase proof creates IAM account');
    $h->assertSame(3, (int) $business->one("SELECT COUNT(*) AS c FROM business_contacts WHERE site_id=1 AND email='alice@example.test'")['c'], 'unverified CRM duplicates remain distinct');
    $h->assertSame(0, (int) $business->one('SELECT COUNT(*) AS c FROM crm_consents')['c'], 'account creation never manufactures CRM consent');
    $h->assertSame(1, count($accounts->orders(1, $user1)), 'customer sees only explicitly linked orders');
    $snapshotBefore = (string) $sale->one('SELECT customer_snapshot_json FROM sale_orders WHERE id=?', [$order1])['customer_snapshot_json'];

    $otherOrder = $insertOrder(1, $channelId, 'P13-THIEF', 'victim@example.test');
    $otherProof = $accounts->issueClaimProof($otherOrder);
    $h->expectException(fn() => $accounts->claimForAuthenticatedAccount(1, $user1, $otherProof['token']), SaleValidationException::class, 'account cannot claim another customer order');

    $sale->run("INSERT INTO sale_channels(site_id,code,name,channel_type,channel_kind,status,is_public,currency,default_language,tax_mode) VALUES(2,'web-2','Web 2','ecommerce','storefront','active',1,'CHF','fr','tax_included')");
    $channel2 = (int) $sale->lastInsertId();
    $order2 = $insertOrder(2, $channel2, 'P13-SITE2', 'alice@example.test');
    $site2 = $accounts->registerWithProof(2, $accounts->issueClaimProof($order2)['token'], 'mot-de-passe-solide');
    $h->assertSame($user1, (int) $site2['user']['id'], 'verified IAM identity is reused across sites');
    $h->assertSame(1, count($accounts->orders(2, $user1)), 'site account exposes only its site orders');

    $accounts->changeEmail(1, $user1, 'mot-de-passe-solide', 'alice.new@example.test');
    $h->assertSame(null, $iam->one('SELECT email_verified_at FROM iam_users WHERE id=?', [$user1])['email_verified_at'], 'email change requires verification');
    $h->assertSame(1, (int) $iam->one('SELECT COUNT(*) AS c FROM iam_customer_email_changes WHERE user_id=?', [$user1])['c'], 'email history is retained');
    $h->assertSame($snapshotBefore, (string) $sale->one('SELECT customer_snapshot_json FROM sale_orders WHERE id=?', [$order1])['customer_snapshot_json'], 'order snapshot remains immutable');

    $order3 = $insertOrder(1, $channelId, 'P13-002', 'bob@example.test');
    $user2 = (int) $accounts->registerWithProof(1, $accounts->issueClaimProof($order3)['token'], 'autre-mot-de-passe')['user']['id'];
    $audit = $accounts->mergeAccounts(1, $user1, $user2, 99, 'Doublon confirmé par administrateur');
    $h->assertSame('applied', $audit['status'], 'administrator merge writes audit');
    $h->assertSame(2, count($accounts->orders(1, $user2)), 'controlled merge transfers explicit order links');
    $h->assertSame('merged', $iam->one('SELECT status FROM iam_customer_site_accounts WHERE user_id=? AND site_id=1', [$user1])['status'], 'source site identity is disabled after merge');

    $order4 = $insertOrder(1, $channelId, 'P13-DELETE', 'delete@example.test');
    $user3 = (int) $accounts->registerWithProof(1, $accounts->issueClaimProof($order4)['token'], 'suppression-solide')['user']['id'];
    $iam->run('DELETE FROM iam_users WHERE id=?', [$user3]);
    $h->assertSame(1, (int) $sale->one('SELECT COUNT(*) AS c FROM sale_customer_order_links WHERE order_id=? AND iam_user_id=?', [$order4, $user3])['c'], 'IAM deletion preserves Sale linkage');
    $h->assertSame(1, (int) $sale->one('SELECT COUNT(*) AS c FROM sale_orders WHERE id=?', [$order4])['c'], 'IAM deletion preserves order history');
} finally {
    $iam = $business = $sale = null;
    test_remove_tree($iamDir); test_remove_tree($businessDir); test_remove_tree($saleDir);
}

exit($h->finish('UNIT sale customer accounts IAM CRM Sale'));
