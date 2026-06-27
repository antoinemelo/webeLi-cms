<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Core\Logger;
use App\Mail\MailerInterface;
use App\Modules\Business\Messaging\TelegramBotProvider;
use App\Modules\Business\Messaging\WhatsAppCloudApiProvider;
use App\Modules\Business\Repositories\BusinessCompanyRepository;
use App\Modules\Business\Repositories\BusinessConsentRepository;
use App\Modules\Business\Repositories\BusinessContactRepository;
use App\Modules\Business\Repositories\BusinessMessagingRepository;
use App\Modules\Business\Services\BusinessConsentService;
use App\Modules\Business\Services\BusinessCrmService;
use App\Modules\Business\Services\BusinessMessagingOutboxService;
use App\Modules\Business\Services\BusinessMessagingProviderManager;

final class BusinessMessagingTestMailer implements MailerInterface
{
    public array $sent = [];

    public function send(string $to, string $subject, string $textBody, ?string $htmlBody = null): bool
    {
        $this->sent[] = compact('to', 'subject', 'textBody', 'htmlBody');
        return true;
    }
}

$h = new TestHarness();
[$dir, $dbPath, $db] = test_temp_cms_db(__DIR__ . '/../../../../database/migrations/business/0001_init.sql');

try {
    $companies = new BusinessCompanyRepository($db);
    $contacts = new BusinessContactRepository($db);
    $consents = new BusinessConsentRepository($db);
    $messages = new BusinessMessagingRepository($db);
    $crm = new BusinessCrmService($companies, $contacts);
    $consentService = new BusinessConsentService($consents);
    $outbox = new BusinessMessagingOutboxService($consents, $messages);
    $mailer = new BusinessMessagingTestMailer();
    $manager = new BusinessMessagingProviderManager($messages, $mailer, new Logger($dir . '/business-messaging.log'));

    $providers = $manager->providers(1);
    $h->assertTrue(count($providers['runtime']) >= 4, 'runtime providers are exposed');
    $h->assertTrue((new WhatsAppCloudApiProvider())->validateConfiguration([]) !== [], 'whatsapp provider is disabled without config');
    $h->assertTrue((new TelegramBotProvider())->validateConfiguration([]) !== [], 'telegram provider is disabled without config');

    $queued = $messages->createOutbox(1, [
        'channel' => 'email',
        'recipient_value' => 'test@example.test',
        'subject' => 'Hello',
        'body_text' => 'Body',
    ], 1);
    $h->assertSame('pending', $queued['status'], 'message starts in outbox before send attempt');
    $result = $manager->sendOutboxMessage(1, (int) $queued['id']);
    $h->assertSame('sent', $result['message']['status'] ?? null, 'log-only provider sends without external secret');
    $events = $messages->deliveryEvents((int) $queued['id']);
    $h->assertSame(2, count($events), 'delivery attempt creates queued and sent events');

    $db->run(
        "INSERT INTO crm_messaging_providers(site_id, provider_key, name, channel, provider_type, config_json, is_enabled, is_default)
         VALUES(1, 'broken_email', 'Broken email', 'email', 'smtp', '{}', 1, 1)"
    );
    $broken = $messages->createOutbox(1, [
        'channel' => 'email',
        'recipient_value' => 'not-an-email',
        'subject' => 'Broken',
        'body_text' => 'This provider attempt must fail without external API.',
    ], 1);
    $failed = $manager->sendOutboxMessage(1, (int) $broken['id'], 'broken_email');
    $h->assertSame('failed', $failed['message']['status'] ?? null, 'provider error marks message as failed');
    $h->assertSame('business.messaging.email_recipient_invalid', $failed['message']['last_error'] ?? null, 'provider error is stored on outbox');
    $failedEvents = $messages->deliveryEvents((int) $broken['id']);
    $h->assertSame('failed', $failedEvents[1]['event_type'] ?? null, 'provider error is journaled as delivery event');

    $company = $crm->createCompany(1, ['name' => 'Messaging SA'], 1);
    $contact = $crm->createContact(1, ['company_id' => $company['id'], 'display_name' => 'Contact Messaging'], 1);
    $h->expectException(
        fn() => $outbox->prepareMessage(1, (int) $contact['id'], 'email', ['body_text' => 'No consent'], 1),
        InvalidArgumentException::class,
        'contact message requires compatible consent'
    );

    $consentService->setChannel((int) $contact['id'], 'email', 'contact@example.test', true, true, 1);
    $consentService->setConsent((int) $contact['id'], 'email', 'opt_in', 'manual', 'Unit test', 1);
    $prepared = $outbox->prepareMessage(1, (int) $contact['id'], 'email', ['subject' => 'Consent OK', 'body_text' => 'Hello'], 1);
    $h->assertSame('pending', $prepared['status'], 'consented contact message is prepared in outbox');
    $sent = $manager->sendOutboxMessage(1, (int) $prepared['id']);
    $h->assertSame('sent', $sent['message']['status'] ?? null, 'consented contact message can be sent through provider manager');
} finally {
    $db = null;
    gc_collect_cycles();
    test_remove_tree($dir);
}

exit($h->finish('UNIT business messaging providers'));
