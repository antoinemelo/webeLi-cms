<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Accounting\Services\AccountingChartService;
use App\Modules\Accounting\Services\AccountingDatabaseConnection;

$h = new TestHarness();
[$dir, $dbPath, $db] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/accounting.sql');

try {
    $service = new AccountingChartService(new AccountingDatabaseConnection($dbPath));
    $structure = $service->structure(1);

    $h->assertSame('CHF', (string)$structure['chart']['currency'], 'default Swiss chart uses CHF');
    $h->assertSame(2, count($structure['rules']), 'default chart has the 2 and 3 credit-increase rules');
    $h->assertSame(16, count($structure['accounts']), 'reference chart accounts are imported');
    $h->assertSame('debit', $service->normalSide(1, '1000'), 'cash increases on debit');
    $h->assertSame('credit', $service->normalSide(1, '2000'), 'liabilities increase on credit');
    $h->assertSame('credit', $service->normalSide(1, '3000'), 'sales increase on credit');
    $h->assertSame(12500, $service->balanceMinor(1, '1000', 10000, 5000, 2500), 'debit-normal balance adds debits and subtracts credits');
    $h->assertSame(12500, $service->balanceMinor(1, '3000', 10000, 2500, 5000), 'credit-normal balance subtracts debits and adds credits');

    $cash = array_values(array_filter($structure['accounts'], static fn(array $row): bool => $row['account_number'] === '1000'))[0];
    $h->assertSame('Liquidités', (string)($cash['category']['label'] ?? ''), 'longest category prefix classifies cash as liquidity instead of generic assets');

    $override = $service->saveRule(1, null, ['account_prefix'=>'20','increase_side'=>'debit','label'=>'Exception fournisseurs'], 7);
    $h->assertSame('debit', $service->normalSide(1, '2000'), 'longest balance prefix overrides the generic 2 rule');
    $service->deleteRule(1, (int)$override['id']);
    $h->assertSame('credit', $service->normalSide(1, '2000'), 'deleting override restores generic credit behavior');

    $account = $service->saveAccount(1, null, ['account_number'=>'1001','label'=>'Petite caisse'], 7);
    $periodId = (int)$structure['selected_fiscal_period_id'];
    $service->saveOpeningBalances(1, $periodId, [['account_id'=>(int)$account['id'],'amount_minor'=>12345]], 7);
    $renumbered = $service->saveAccount(1, (int)$account['id'], ['account_number'=>'1002','label'=>'Caisse secondaire'], 7);
    $h->assertSame((int)$account['id'], (int)$renumbered['id'], 'renumbering preserves the stable account id');
    $opening = $db->one('SELECT amount_minor FROM accounting_opening_balances WHERE fiscal_period_id=? AND account_id=?', [$periodId,(int)$account['id']]);
    $h->assertSame(12345, (int)($opening['amount_minor'] ?? 0), 'renumbering preserves opening balance');

    $removed = $service->deleteAccount(1, (int)$account['id'], 7);
    $h->assertSame(true, $removed['archived'], 'an account already used by an opening balance is archived instead of destroyed');
    $h->assertSame(0, (int)($db->one('SELECT is_active FROM accounting_accounts WHERE id=?', [(int)$account['id']])['is_active'] ?? 1), 'archived used account is inactive');

    $unused = $service->saveAccount(1, null, ['account_number'=>'9998','label'=>'Compte temporaire'], 7);
    $deleted = $service->deleteAccount(1, (int)$unused['id'], 7);
    $h->assertSame(true, $deleted['deleted'], 'an unused account can be removed');

    $h->expectException(
        fn() => $service->saveAccount(1, null, ['account_number'=>'ABC','label'=>'Invalide'], 7),
        InvalidArgumentException::class,
        'account numbers accept digits only'
    );
    $h->expectException(
        fn() => $service->saveAccount(1, null, ['account_number'=>'1000','label'=>'Doublon'], 7),
        PDOException::class,
        'account number is unique in a chart'
    );

    $second = $service->structure(2);
    $secondPeriodId = (int)$second['selected_fiscal_period_id'];
    $h->expectException(
        fn() => $db->run(
            'INSERT INTO accounting_opening_balances(fiscal_period_id,account_id,amount_minor) VALUES(?,?,?)',
            [$secondPeriodId,(int)$cash['id'],100]
        ),
        PDOException::class,
        'database prevents an opening balance from referencing an account in another chart'
    );

    $integrity = $db->one('PRAGMA integrity_check');
    $h->assertSame('ok', (string)array_values($integrity ?? [''])[0], 'accounting database passes SQLite integrity check');
    $h->assertSame(0, count($db->all('PRAGMA foreign_key_check')), 'accounting database has no foreign key violation');

    $bootstrapPath = $dir . '/bootstrapped.sqlite';
    $bootstrapConnection = new AccountingDatabaseConnection($bootstrapPath, __DIR__ . '/../../../../database/modules/accounting.sql');
    $bootstrapService = new AccountingChartService($bootstrapConnection);
    $h->assertSame('CHF', (string)$bootstrapService->structure(3)['chart']['currency'], 'first authorized use bootstraps a missing accounting database');
    $h->assertTrue(is_file($bootstrapPath), 'runtime bootstrap creates the dedicated accounting database');
} finally {
    $db = null;
    gc_collect_cycles();
    test_remove_tree($dir);
}

exit($h->finish('UNIT accounting chart service'));
