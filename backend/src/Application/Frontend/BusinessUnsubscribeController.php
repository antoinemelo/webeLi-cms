<?php

declare(strict_types=1);

namespace App\Application\Frontend;

use App\Core\Logger;
use App\Core\Response;
use App\Modules\Business\Repositories\BusinessMailingRepository;

final class BusinessUnsubscribeController
{
    public function __construct(
        private readonly BusinessMailingRepository $mailing,
        private readonly Logger $logger,
    ) {}

    public function show(string $token): Response
    {
        return new Response(200, $this->shell('Confirmer le désabonnement', $this->form($token)), $this->headers());
    }

    public function confirm(string $token): Response
    {
        $result = $this->mailing->unsubscribeByToken($token);
        $this->logger->info('business.mailing.unsubscribe', [
            'token_hash' => hash('sha256', trim($token)),
            'status' => $result === null ? 'not_found' : 'opt_out',
            'contact_id' => $result['contact_id'] ?? null,
            'mailing_id' => $result['mailing_id'] ?? null,
            'channel' => $result['channel'] ?? null,
        ]);
        return new Response(200, $this->shell('Désabonnement enregistré', '<p>Si ce lien correspond à une inscription active, le désabonnement a été enregistré.</p>'), $this->headers());
    }

    private function form(string $token): string
    {
        $action = '/business/unsubscribe/' . $this->esc($token);
        return <<<HTML
<p>Confirmez que vous ne souhaitez plus recevoir ce type de message marketing.</p>
<form method="post" action="{$action}">
  <button type="submit">Confirmer le désabonnement</button>
</form>
HTML;
    }

    private function shell(string $title, string $content): string
    {
        $safeTitle = $this->esc($title);
        return <<<HTML
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>{$safeTitle}</title>
  <style>
    :root { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #172033; background: #f6f7f9; }
    body { margin: 0; padding: 2rem 1rem; }
    main { width: min(620px, 100%); margin: 0 auto; background: #fff; border: 1px solid #d8dde6; border-radius: 8px; padding: 2rem; }
    h1 { margin-top: 0; letter-spacing: 0; }
    button { border: 1px solid #172033; background: #172033; color: #fff; border-radius: 6px; padding: .7rem 1rem; font: inherit; }
  </style>
</head>
<body><main><h1>{$safeTitle}</h1>{$content}</main></body>
</html>
HTML;
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ];
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
