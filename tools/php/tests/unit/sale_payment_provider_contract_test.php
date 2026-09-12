<?php

declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Sale\Payments\PaymentProviderContractV1;
use App\Modules\Sale\Payments\PaymentProviderRegistry;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Modules\Sale\Services\SalePaymentMethodService;

$h = new TestHarness();
[$dir, $path, $db] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/sale.sql');

try {
    $connection = new SaleDatabaseConnection($path);
    $registry = new PaymentProviderRegistry(null, $connection->database(), 'provider-contract-test-secret', 'test');
    $descriptors = array_column($registry->descriptors(), null, 'key');
    $h->assertSame(PaymentProviderContractV1::VERSION, $descriptors['sandbox']['contract_version'] ?? null, 'provider registry exposes one versioned canonical contract');
    foreach (['create_payment_session','update_payment_session','authorize','capture','cancel','refund','webhook','reconcile'] as $responsibility) {
        $h->assertTrue(array_key_exists($responsibility, $descriptors['sandbox']['capabilities'] ?? []), 'canonical provider capability is explicit: ' . $responsibility);
    }

    $channelId = (int) ($db->one("SELECT id FROM sale_channels WHERE site_id=1 AND code='web-main'")['id'] ?? 0);
    $methods = new SalePaymentMethodService($connection, $registry);
    $available = $methods->availableMethods(1, $channelId, 'en', 'CHF', 2900);
    $byCode = array_column($available, null, 'code');
    $h->assertSame('Bank transfer', $byCode['bank_transfer']['label'] ?? null, 'payment method label follows requested language');
    $h->assertSame('redirect', $byCode['sandbox_online']['next_action'] ?? null, 'shop receives the next customer action');
    $h->assertSame(true, $byCode['sandbox_online']['capabilities']['online'] ?? null, 'shop receives safe provider capabilities');
    $h->assertSame(PaymentProviderContractV1::VERSION, $byCode['sandbox_online']['contract_version'] ?? null, 'shop method declares provider contract version');

    $productionRegistry = new PaymentProviderRegistry(null, $connection->database(), 'provider-contract-test-secret', 'production');
    $h->assertTrue(!in_array('sandbox', $productionRegistry->keys(), true), 'production never registers the sandbox payment provider');

    $db->run("UPDATE sale_payment_methods SET max_amount_minor=1000 WHERE channel_id=? AND code='sandbox_online'", [$channelId]);
    $limited = array_column($methods->availableMethods(1, $channelId, 'fr', 'CHF', 2900), null, 'code');
    $h->assertTrue(!isset($limited['sandbox_online']), 'amount constraints remove an unavailable payment method');
    $db->run("UPDATE sale_payment_methods SET status='disabled' WHERE channel_id=? AND code='manual'", [$channelId]);
    $disabled = array_column($methods->availableMethods(1, $channelId, 'fr', 'CHF', 500), null, 'code');
    $h->assertTrue(!isset($disabled['manual']), 'disabled payment method is never exposed to Shop');
} finally {
    $db = null;
    test_remove_tree($dir);
}

$h->finish('UNIT payment provider contract and Shop availability');
