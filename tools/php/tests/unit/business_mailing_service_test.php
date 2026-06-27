<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Core\Database;
use App\Modules\Business\Repositories\BusinessCompanyRepository;
use App\Modules\Business\Repositories\BusinessConsentRepository;
use App\Modules\Business\Repositories\BusinessContactRepository;
use App\Modules\Business\Repositories\BusinessMailingRepository;
use App\Modules\Business\Repositories\BusinessMessagingRepository;
use App\Modules\Business\Services\BusinessConsentService;
use App\Modules\Business\Services\BusinessCrmService;
use App\Modules\Business\Services\BusinessMailingService;

$h = new TestHarness();
[$dir, $dbPath] = test_temp_db(__DIR__ . '/../../../../database/modules/business.sql');

try {
    $db = new Database($dbPath, 1000);
    $companies = new BusinessCompanyRepository($db);
    $contacts = new BusinessContactRepository($db);
    $consents = new BusinessConsentRepository($db);
    $mailing = new BusinessMailingRepository($db);
    $messages = new BusinessMessagingRepository($db);
    $crm = new BusinessCrmService($companies, $contacts);
    $consentService = new BusinessConsentService($consents);
    $service = new BusinessMailingService($mailing, $messages);

    $company = $crm->createCompany(1, ['name' => 'Mailing SA'], 1);
    $optIn = $crm->createContact(1, ['company_id' => $company['id'], 'display_name' => 'Opt In'], 1);
    $noConsent = $crm->createContact(1, ['company_id' => $company['id'], 'display_name' => 'No Consent'], 1);
    $consentService->setChannel((int) $optIn['id'], 'email', 'optin@example.test', true, true, 1);
    $consentService->setConsent((int) $optIn['id'], 'email', 'opt_in', 'manual', 'Unit test', 1);

    $list = $mailing->createList(1, ['name' => 'Newsletter', 'channel' => 'email'], 1);
    $h->assertSame('Newsletter', $list['name'], 'mailing list is created');
    $mailing->addMember(1, (int) $list['id'], (int) $optIn['id'], 1, hash('sha256', bin2hex(random_bytes(24))));
    $mailing->addMember(1, (int) $list['id'], (int) $noConsent['id'], 1, hash('sha256', bin2hex(random_bytes(24))));

    $campaign = $mailing->createCampaign(1, [
        'list_id' => $list['id'],
        'name' => 'Campagne test',
        'channel' => 'email',
        'subject' => 'Bonjour',
        'body_text' => 'Message marketing.',
    ], 1);
    $h->assertSame('draft', $campaign['status'], 'campaign starts as draft');

    $preview = $service->previewRecipients(1, (int) $campaign['id']);
    $h->assertSame(1, count($preview['recipients']), 'preview excludes contacts without opt-in');
    $h->assertSame((int) $optIn['id'], $preview['recipients'][0]['contact_id'], 'preview keeps opt-in contact');

    $enqueue = $service->enqueueCampaign(1, (int) $campaign['id'], 1);
    $h->assertSame(1, $enqueue['queued_count'], 'enqueue queues eligible recipient only');
    $h->assertSame('sending', $enqueue['campaign']['status'], 'campaign moves to sending after enqueue');
    $outbox = $messages->listOutbox(1);
    $h->assertSame(1, count($outbox['items']), 'mailing creates outbox message');
    $h->assertSame((int) $campaign['id'], $outbox['items'][0]['mailing_id'], 'outbox keeps mailing id');

    $payload = json_decode((string) $outbox['items'][0]['payload_json'], true);
    $path = (string) ($payload['unsubscribe_path'] ?? '');
    $token = basename($path);
    $h->assertTrue(strlen($token) >= 32, 'unsubscribe token is present only in payload/path');

    $unsubscribe = $mailing->unsubscribeByToken($token);
    $h->assertSame((int) $optIn['id'], $unsubscribe['contact_id'] ?? null, 'unsubscribe token resolves recipient');
    $secondPreview = $service->previewRecipients(1, (int) $campaign['id']);
    $h->assertSame(0, count($secondPreview['recipients']), 'unsubscribe makes contact ineligible for next mailing');
} finally {
    test_remove_tree($dir);
}

exit($h->finish('UNIT business mailing service'));
