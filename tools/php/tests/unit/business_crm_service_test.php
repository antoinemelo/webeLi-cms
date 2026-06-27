<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Core\Database;
use App\Modules\Business\Repositories\BusinessCompanyRepository;
use App\Modules\Business\Repositories\BusinessConsentRepository;
use App\Modules\Business\Repositories\BusinessContactRepository;
use App\Modules\Business\Repositories\BusinessMemoRepository;
use App\Modules\Business\Repositories\BusinessMessagingRepository;
use App\Modules\Business\Repositories\BusinessTagRepository;
use App\Modules\Business\Services\BusinessConsentService;
use App\Modules\Business\Services\BusinessCrmService;
use App\Modules\Business\Services\BusinessMemoSharingService;
use App\Modules\Business\Services\BusinessMessagingOutboxService;

$h = new TestHarness();
[$dir, $dbPath] = test_temp_db(__DIR__ . '/../../../../database/modules/business.sql');

try {
    $db = new Database($dbPath);
    $companies = new BusinessCompanyRepository($db);
    $contacts = new BusinessContactRepository($db);
    $tags = new BusinessTagRepository($db);
    $memos = new BusinessMemoRepository($db);
    $consents = new BusinessConsentRepository($db);
    $messages = new BusinessMessagingRepository($db);

    $crm = new BusinessCrmService($companies, $contacts, $tags);
    $memoService = new BusinessMemoSharingService($memos);
    $consentService = new BusinessConsentService($consents);
    $outboxService = new BusinessMessagingOutboxService($consents, $messages);

    $company = $crm->createCompany(1, ['name' => 'Acme SARL', 'email' => 'contact@example.test'], 1);
    $h->assertSame('Acme SARL', $company['name'], 'company is created');

    $statusCompany = $crm->createCompany(1, ['name' => 'Status SA', 'status' => 'client'], 1);
    $h->assertSame('client', $statusCompany['status'], 'company accepts valid CRM status');
    $h->expectException(
        fn() => $crm->createCompany(1, ['name' => 'Bad Status SA', 'status' => 'not-a-status'], 1),
        InvalidArgumentException::class,
        'invalid company CRM status is rejected'
    );
    $crm->archiveCompany(1, (int) $statusCompany['id'], 1);
    $h->assertSame(null, $companies->find(1, (int) $statusCompany['id']), 'archived company is hidden from active lookup');

    $orphanContact = $crm->createContact(1, ['display_name' => 'Ada Solo', 'email' => 'ada@example.test'], 1);
    $h->assertSame('Individus', $orphanContact['company_name'], 'contact without company uses Individus');

    $contact = $crm->createContact(1, [
        'company_id' => $company['id'],
        'first_name' => 'Jean',
        'last_name' => 'Client',
        'email' => 'jean.client@example.test',
        'mobile' => '+41 79 000 00 00',
        'iam_user_id' => 42,
        'status' => 'client',
    ], 1);
    $h->assertSame('Jean Client', $contact['display_name'], 'contact can be attached to company');
    $h->assertSame(42, $contact['iam_user_id'], 'iam user link is optional but stored when provided');
    $h->assertSame('client', $contact['status'], 'contact accepts valid CRM status');

    $h->expectException(
        fn() => $crm->createContact(1, ['company_id' => $company['id'], 'display_name' => 'Bad Status', 'status' => 'not-a-status'], 1),
        InvalidArgumentException::class,
        'invalid contact CRM status is rejected'
    );

    $h->expectException(
        fn() => $crm->createContact(1, ['company_id' => $company['id'], 'display_name' => 'Duplicate IAM', 'iam_user_id' => 42], 1),
        InvalidArgumentException::class,
        'active iam user link remains unique'
    );

    $crm->archiveContact(1, (int) $contact['id'], 1);
    $replacement = $crm->createContact(1, ['company_id' => $company['id'], 'display_name' => 'Replacement IAM', 'iam_user_id' => 42], 1);
    $h->assertSame(42, $replacement['iam_user_id'], 'archived contact releases iam user link');

    $companySearch = $crm->searchCompanies(1, 'acme');
    $h->assertTrue(count($companySearch['items']) >= 1, 'company search returns expected item');

    $contactSearch = $crm->searchContacts(1, 'replacement');
    $h->assertSame('Replacement IAM', $contactSearch['items'][0]['display_name'] ?? null, 'contact search returns expected item');

    $companyMemo = $memoService->createMemo(1, [
        'company_id' => $company['id'],
        'title' => 'Mémo entreprise',
        'body' => 'Cible entreprise seule.',
    ], 1, 1);
    $h->assertSame(null, $companyMemo['contact_id'], 'memo can target company only');

    $contactMemo = $memoService->createMemo(1, [
        'contact_id' => $replacement['id'],
        'title' => 'Mémo contact',
        'body' => 'Cible contact seule.',
    ], 1, 1);
    $h->assertSame(null, $contactMemo['company_id'], 'memo can target contact only');

    $h->expectException(
        fn() => $memoService->createMemo(1, ['title' => 'Sans cible', 'body' => 'Nope.'], 1, 1),
        InvalidArgumentException::class,
        'memo without company or contact target is rejected'
    );

    $otherSiteCompany = $crm->createCompany(2, ['name' => 'Autre site SA'], 1);
    $otherSiteContact = $crm->createContact(2, ['company_id' => $otherSiteCompany['id'], 'display_name' => 'Autre Site'], 1);
    $h->expectException(
        fn() => $memoService->createMemo(1, ['company_id' => $otherSiteCompany['id'], 'title' => 'Cible autre site'], 1, 1),
        InvalidArgumentException::class,
        'memo refuses company target from another site'
    );
    $h->expectException(
        fn() => $memoService->createMemo(1, ['contact_id' => $otherSiteContact['id'], 'title' => 'Contact autre site'], 1, 1),
        InvalidArgumentException::class,
        'memo refuses contact target from another site'
    );

    $mismatchedContact = $crm->createContact(1, ['company_id' => $company['id'], 'display_name' => 'Contact mismatched'], 1);
    $h->expectException(
        fn() => $memoService->createMemo(1, [
            'company_id' => $orphanContact['company_id'],
            'contact_id' => $mismatchedContact['id'],
            'title' => 'Cibles incohérentes',
        ], 1, 1),
        InvalidArgumentException::class,
        'memo refuses mismatched company and contact targets'
    );

    $memo = $memoService->createMemo(1, [
        'company_id' => $company['id'],
        'contact_id' => $replacement['id'],
        'title' => 'Compte rendu',
        'body' => 'Premier contact.',
    ], 1, 1);
    $h->assertSame('Compte rendu', $memo['title'], 'memo can target company and contact');

    $internalShares = $memoService->shareInternally(1, (int) $memo['id'], [7, 8], 1);
    $h->assertSame(2, count($internalShares), 'memo can be shared with IAM users');
    $h->assertSame('iam_user', $internalShares[0]['share_type'] ?? null, 'internal memo share is typed as iam_user');

    $h->expectException(
        fn() => $memoService->createPublicShare(1, (int) $memo['id'], 1, false),
        InvalidArgumentException::class,
        'public memo share requires permission'
    );
    $shareResult = $memoService->createPublicShare(1, (int) $memo['id'], 1, true, 'Lien client');
    $shares = $memos->sharesForMemo(1, (int) $memo['id']);
    $h->assertTrue(strlen($shareResult['token']) >= 32, 'public share returns one-time token');
    $h->assertTrue(($shares[0]['public_token_hash'] ?? '') !== $shareResult['token'], 'public share stores token hash only');

    $tag = $crm->tagCompany(1, (int) $company['id'], ['label' => 'Prioritaire'], 1);
    $h->assertSame('prioritaire', $tag['tag_key'], 'tag key is normalized');

    $emailChannel = $consentService->setChannel((int) $replacement['id'], 'email', 'CLIENT@example.test', true, true, 1);
    $h->assertSame('client@example.test', $emailChannel['normalized_value'], 'email channel is normalized');
    $consentService->setConsent((int) $replacement['id'], 'email', 'opt_in', 'manual', 'Test consent', 1);
    $h->assertTrue($consentService->hasConsent((int) $replacement['id'], 'email'), 'email consent opt-in is stored');

    $consentService->setChannel((int) $replacement['id'], 'whatsapp', '+41 79 111 22 33', true, false, 1);
    $consentService->setConsent((int) $replacement['id'], 'whatsapp', 'opt_in', 'manual', null, 1);
    $h->assertTrue($consentService->hasConsent((int) $replacement['id'], 'whatsapp'), 'whatsapp consent opt-in is stored');

    $consentService->setChannel((int) $replacement['id'], 'telegram', '@client_test', true, false, 1);
    $consentService->setConsent((int) $replacement['id'], 'telegram', 'opt_in', 'manual', null, 1);
    $h->assertTrue($consentService->hasConsent((int) $replacement['id'], 'telegram'), 'telegram consent opt-in is stored');

    $outbox = $outboxService->prepareMessage(1, (int) $replacement['id'], 'email', [
        'subject' => 'Bonjour',
        'body_text' => 'Message de test.',
        'payload' => ['source' => 'unit-test'],
    ], 1);
    $h->assertSame('pending', $outbox['status'], 'message is prepared in outbox');
    $h->assertSame('client@example.test', $outbox['recipient_value'], 'message uses consented primary channel');

    $blockedContact = $crm->createContact(1, ['company_id' => $company['id'], 'display_name' => 'Sans consentement'], 1);
    $h->expectException(
        fn() => $outboxService->prepareMessage(1, (int) $blockedContact['id'], 'email', ['body_text' => 'Nope'], 1),
        InvalidArgumentException::class,
        'message preparation requires channel consent'
    );
} finally {
    test_remove_tree($dir);
}

exit($h->finish('UNIT business CRM services'));
