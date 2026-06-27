<?php

declare(strict_types=1);

namespace App\Modules\Business\Services;

use App\Modules\Business\Repositories\BusinessMailingRepository;
use App\Modules\Business\Repositories\BusinessMessagingRepository;
use InvalidArgumentException;

final class BusinessMailingService
{
    public function __construct(
        private readonly BusinessMailingRepository $mailing,
        private readonly BusinessMessagingRepository $messages,
    ) {}

    public function previewRecipients(int $siteId, int $campaignId): array
    {
        $campaign = $this->campaign($siteId, $campaignId);
        return [
            'campaign' => $campaign,
            'recipients' => $this->mailing->eligibleRecipients($siteId, $campaign),
        ];
    }

    public function enqueueCampaign(int $siteId, int $campaignId, ?int $actorId = null): array
    {
        $campaign = $this->campaign($siteId, $campaignId);
        if ((string) ($campaign['status'] ?? '') === 'cancelled') {
            throw new InvalidArgumentException('business.mailing_cancelled');
        }
        $recipients = $this->mailing->eligibleRecipients($siteId, $campaign);
        $queued = [];
        foreach ($recipients as $recipient) {
            $token = bin2hex(random_bytes(24));
            $recipientRow = $this->mailing->upsertRecipient($campaignId, (int) $recipient['contact_id'], isset($recipient['channel_id']) ? (int) $recipient['channel_id'] : null, hash('sha256', $token));
            $bodyText = $this->appendUnsubscribeText((string) ($campaign['body_text'] ?? ''), $token);
            $outbox = $this->messages->createOutbox($siteId, [
                'mailing_id' => $campaignId,
                'contact_id' => (int) $recipient['contact_id'],
                'channel' => (string) $campaign['channel'],
                'recipient_value' => (string) $recipient['recipient_value'],
                'subject' => $campaign['subject'] ?? null,
                'body_text' => $bodyText,
                'body_html' => $campaign['body_html'] ?? null,
                'payload' => [
                    'source' => 'business.mailing',
                    'mailing_id' => $campaignId,
                    'unsubscribe_path' => '/business/unsubscribe/' . $token,
                    'message_purpose' => 'marketing',
                ],
                'status' => 'pending',
            ], $actorId);
            $this->mailing->updateRecipientQueued((int) $recipientRow['id'], (int) $outbox['id']);
            $queued[] = ['recipient' => $recipientRow, 'message' => $outbox, 'unsubscribe_token' => $token];
        }
        $campaign = $this->mailing->setCampaignStatus($siteId, $campaignId, $queued === [] ? 'failed' : 'sending', $actorId);
        return ['campaign' => $campaign, 'queued' => $this->hideTokens($queued), 'queued_count' => count($queued), 'eligible_count' => count($recipients)];
    }

    public function cancelCampaign(int $siteId, int $campaignId, ?int $actorId = null): array
    {
        $campaign = $this->campaign($siteId, $campaignId);
        if (in_array((string) ($campaign['status'] ?? ''), ['sent', 'cancelled'], true)) {
            return ['campaign' => $campaign, 'cancelled' => false];
        }
        return ['campaign' => $this->mailing->setCampaignStatus($siteId, $campaignId, 'cancelled', $actorId), 'cancelled' => true];
    }

    private function campaign(int $siteId, int $campaignId): array
    {
        $campaign = $this->mailing->findCampaign($siteId, $campaignId);
        if ($campaign === null) {
            throw new InvalidArgumentException('business.mailing_campaign_not_found');
        }
        return $campaign;
    }

    private function appendUnsubscribeText(string $body, string $token): string
    {
        return rtrim($body) . "\n\nDésabonnement : " . '/business/unsubscribe/' . $token;
    }

    /** @param list<array<string,mixed>> $queued @return list<array<string,mixed>> */
    private function hideTokens(array $queued): array
    {
        return array_map(static function (array $row): array {
            unset($row['unsubscribe_token']);
            return $row;
        }, $queued);
    }
}
