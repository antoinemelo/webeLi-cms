<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Business\Repositories\BusinessCompanyRepository;
use App\Modules\Business\Repositories\BusinessConsentRepository;
use App\Modules\Business\Repositories\BusinessContactRepository;
use App\Modules\Business\Repositories\BusinessTagRepository;
use App\Modules\Business\Services\BusinessCsvService;

$h = new TestHarness();
[$dir, $path, $db] = test_temp_cms_db(__DIR__ . '/../../../../database/migrations/business/0001_init.sql');

try {
    $companies = new BusinessCompanyRepository($db);
    $contacts = new BusinessContactRepository($db);
    $tags = new BusinessTagRepository($db);
    $consents = new BusinessConsentRepository($db);
    $csv = new BusinessCsvService($companies, $contacts, $tags, $consents);

    $company = $companies->create(1, ['name' => '=Danger Corp', 'email' => 'danger@example.test', 'status' => 'prospect'], 1);
    $contact = $contacts->create(1, [
        'company_id' => $company['id'],
        'display_name' => 'Alice Example',
        'email' => 'alice@example.test',
        'status' => 'client',
    ], 1);
    $tag = $tags->create(1, ['label' => 'VIP'], 1);
    $tags->linkContact((int) $tag['id'], (int) $contact['id'], 1);
    $consents->upsertConsent((int) $contact['id'], 'email', 'opt_in', 'manual', 'test', 1);

    $companiesCsv = $csv->companiesCsv(1, []);
    $h->assertTrue(str_contains($companiesCsv, "'=Danger Corp"), 'company export neutralizes formula injection');

    $contactsCsv = $csv->contactsCsv(1, []);
    $h->assertTrue(str_contains($contactsCsv, 'Alice Example'), 'contact export includes contact');
    $h->assertTrue(str_contains($contactsCsv, 'VIP'), 'contact export includes tags');
    $h->assertTrue(str_contains($contactsCsv, 'opt_in'), 'contact export includes consent status');

    $import = "company_name;display_name;email;status;email_consent;tags\nNew Co;Bob Example;bob@example.test;prospect;yes;Lead\n";
    $dryRun = $csv->importContactsCsv(1, $import, ['dry_run' => '1', 'create_companies' => '1'], 1);
    $h->assertSame(true, $dryRun['dry_run'], 'import dry-run flag is reported');
    $h->assertSame(1, $dryRun['valid_rows'], 'import dry-run validates row');
    $h->assertSame(0, count($contacts->list(1, 'bob@example.test')['items']), 'import dry-run does not write contact');
    $h->assertSame(0, count($companies->list(1, 'New Co')['items']), 'import dry-run does not write company');

    $h->expectException(
        fn() => $csv->importContactsCsv(1, $import, ['dry_run' => '0', 'create_companies' => '1'], 1),
        InvalidArgumentException::class,
        'real import requires explicit confirmation'
    );

    $real = $csv->importContactsCsv(1, $import, ['dry_run' => '0', 'confirm_import' => '1', 'create_companies' => '1'], 1);
    $h->assertSame(false, $real['dry_run'], 'real import flag is reported');
    $h->assertSame(1, $real['created'], 'real import creates contact');
    $bob = $contacts->list(1, 'bob@example.test')['items'][0] ?? null;
    $h->assertTrue(is_array($bob), 'real import writes contact');
    $h->assertSame('opt_in', $consents->consentForContact((int) $bob['id'], 'email')['consent_status'] ?? null, 'real import writes consent');

    $conflict = $csv->importContactsCsv(1, $import, ['dry_run' => '1', 'create_companies' => '1'], 1);
    $h->assertSame(1, $conflict['skipped'], 'existing contact is skipped without explicit update option');
    $h->assertSame('business.import_contact_exists', $conflict['errors'][0]['message'] ?? null, 'existing contact conflict is explicit');
} finally {
    $db = null;
    gc_collect_cycles();
    test_remove_tree($dir);
}

exit($h->finish('UNIT business CSV import/export'));
