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

    $configuredProvider = $messages->saveProvider(1, [
        'provider_key' => 'smtp_main',
        'name' => 'SMTP principal',
        'channel' => 'email',
        'provider_type' => 'smtp',
        'config' => ['host' => 'smtp.example.test', 'password_secret' => 'do-not-expose'],
        'secret_ref' => 'env:BUSINESS_SMTP_PASSWORD',
        'is_enabled' => true,
        'is_default' => true,
    ], 1);
    $h->assertTrue((int) ($configuredProvider['id'] ?? 0) > 0, 'configured messaging provider can be created');
    $h->assertSame('smtp_main', $configuredProvider['provider_key'] ?? null, 'configured provider keeps stable key');
    $configuredList = $manager->providers(1);
    $safeProvider = $configuredList['configured'][0] ?? [];
    $h->assertSame(false, array_key_exists('secret_ref', $safeProvider), 'configured provider secret ref is not exposed');
    $h->assertSame('***', $safeProvider['config']['password_secret'] ?? null, 'configured provider secret-like config values are masked');
    $updatedProvider = $messages->saveProvider(1, [
        'id' => $configuredProvider['id'],
        'provider_key' => 'smtp_main',
        'name' => 'SMTP principal modifié',
        'channel' => 'email',
        'provider_type' => 'smtp',
        'config' => ['host' => 'smtp2.example.test'],
        'is_enabled' => false,
        'is_default' => false,
    ], 1);
    $h->assertSame('SMTP principal modifié', $updatedProvider['name'] ?? null, 'configured messaging provider can be updated inline');
    $h->assertSame(false, $updatedProvider['is_enabled'] ?? true, 'configured messaging provider enabled flag can be updated');
    $messages->deleteProvider(1, (int) $configuredProvider['id']);
    $h->assertSame(null, $messages->findProvider(1, (int) $configuredProvider['id']), 'configured messaging provider can be deleted');

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

    $emailRuntime = $messages->createOutbox(1, [
        'channel' => 'email',
        'recipient_value' => 'runtime@example.test',
        'subject' => 'Runtime email',
        'body_text' => 'Body',
    ], 1);
    $runtimeSent = $manager->sendOutboxMessage(1, (int) $emailRuntime['id'], 'email');
    $h->assertSame('sent', $runtimeSent['message']['status'] ?? null, 'explicit runtime email provider can be selected');
    $h->assertSame('runtime@example.test', $mailer->sent[0]['to'] ?? null, 'runtime email provider uses the mailer');

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

    $missingChannelPreview = $outbox->previewMessage((int) $contact['id'], 'email');
    $h->assertSame(false, $missingChannelPreview['can_send'], 'message preview blocks contacts without recipient channel');
    $h->assertSame('business.messaging_channel_missing', $missingChannelPreview['reason'], 'message preview explains missing recipient channel');

    $consentService->setChannel((int) $contact['id'], 'email', 'contact@example.test', true, true, 1);
    $missingConsentPreview = $outbox->previewMessage((int) $contact['id'], 'email');
    $h->assertSame(false, $missingConsentPreview['can_send'], 'message preview blocks contacts without opt-in');
    $h->assertSame('business.messaging_consent_required', $missingConsentPreview['reason'], 'message preview explains missing opt-in');

    $consentService->setConsent((int) $contact['id'], 'email', 'opt_in', 'manual', 'Unit test', 1);
    $readyPreview = $outbox->previewMessage((int) $contact['id'], 'email');
    $h->assertSame(true, $readyPreview['can_send'], 'message preview allows an opted-in primary channel');
    $h->assertSame('contact@example.test', $readyPreview['recipient_value'], 'message preview exposes selected recipient');

    $prepared = $outbox->prepareMessage(1, (int) $contact['id'], 'email', ['subject' => 'Consent OK', 'body_text' => 'Hello'], 1);
    $h->assertSame('pending', $prepared['status'], 'consented contact message is prepared in outbox');
    $sent = $manager->sendOutboxMessage(1, (int) $prepared['id']);
    $h->assertSame('sent', $sent['message']['status'] ?? null, 'consented contact message can be sent through provider manager');
    $sentOutbox = $messages->listOutbox(1, 'sent', 50, 0, ['channel' => 'email', 'q' => 'Contact Messaging']);
    $h->assertSame(1, count($sentOutbox['items']), 'outbox filters sent messages by channel and relation name');
    $h->assertSame('Contact Messaging', $sentOutbox['items'][0]['contact_name'] ?? null, 'outbox exposes contact context for sent messages');
    $contactMessages = $messages->relationMessages(1, 'contact', (int) $contact['id']);
    $h->assertSame(1, count($contactMessages['items']), 'relation messages list includes the contact outbox');
    $companyMessages = $messages->relationMessages(1, 'company', (int) $company['id']);
    $h->assertSame(1, count($companyMessages['items']), 'relation messages list includes company contact outbox');
} finally {
    $db = null;
    gc_collect_cycles();
    test_remove_tree($dir);
}

exit($h->finish('UNIT business messaging providers'));
