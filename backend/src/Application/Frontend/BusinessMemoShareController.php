<?php

declare(strict_types=1);

namespace App\Application\Frontend;

use App\Core\Logger;
use App\Core\Response;
use App\Modules\Business\Repositories\BusinessMemoRepository;

final class BusinessMemoShareController
{
    public function __construct(
        private readonly BusinessMemoRepository $memos,
        private readonly Logger $logger,
    ) {}

    public function show(string $token): Response
    {
        $share = $this->memos->publicShareByToken($token);
        if ($share === null) {
            $this->audit('business.memo_share.public_refused', ['reason' => 'not_found']);
            return $this->notFound();
        }

        $shareId = (int) ($share['id'] ?? 0);
        $memoId = (int) ($share['memo_id'] ?? 0);
        if (($share['revoked_at'] ?? null) !== null && (string) $share['revoked_at'] !== '') {
            $this->audit('business.memo_share.public_refused', ['reason' => 'revoked', 'share_id' => $shareId, 'memo_id' => $memoId]);
            return $this->notFound();
        }
        if ($this->isExpired($share['expires_at'] ?? null)) {
            $this->audit('business.memo_share.public_expired', ['share_id' => $shareId, 'memo_id' => $memoId]);
            return $this->notFound();
        }

        $this->memos->markPublicShareAccessed($shareId);
        $this->audit('business.memo_share.public_accessed', ['share_id' => $shareId, 'memo_id' => $memoId]);

        return new Response(200, $this->html($share), $this->headers());
    }

    private function notFound(): Response
    {
        return new Response(404, $this->shell('Mémo indisponible', '<p>Ce lien est invalide, expiré ou révoqué.</p>'), $this->headers());
    }

    /** @param array<string,mixed> $share */
    private function html(array $share): string
    {
        $title = $this->esc((string) ($share['title'] ?? 'Mémo partagé'));
        $body = nl2br($this->esc((string) ($share['body'] ?? '')), false);
        $createdAt = $this->esc((string) ($share['memo_created_at'] ?? $share['created_at'] ?? ''));
        $updatedAt = $this->esc((string) ($share['memo_updated_at'] ?? ''));
        $context = [];
        if (!empty($share['company_name'])) {
            $context[] = 'Entreprise : ' . $this->esc((string) $share['company_name']);
        }
        if (!empty($share['contact_name'])) {
            $context[] = 'Contact : ' . $this->esc((string) $share['contact_name']);
        }
        $contextHtml = $context === [] ? '' : '<p class="context">' . implode(' · ', $context) . '</p>';
        $updatedHtml = $updatedAt !== '' ? '<span>Mis à jour : ' . $updatedAt . '</span>' : '';

        return $this->shell($title, <<<HTML
<article class="memo">
  <p class="eyebrow">Mémo partagé en lecture seule</p>
  <h1>{$title}</h1>
  {$contextHtml}
  <div class="meta"><span>Créé : {$createdAt}</span>{$updatedHtml}</div>
  <div class="body">{$body}</div>
  <p class="notice">Ce lien est confidentiel. Ne le transférez pas sans accord de la personne qui vous l’a communiqué.</p>
</article>
HTML);
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
    :root { color-scheme: light; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #18202f; background: #f5f6f8; }
    body { margin: 0; padding: 2rem 1rem; }
    main { width: min(760px, 100%); margin: 0 auto; background: #fff; border: 1px solid #d9dde5; border-radius: 8px; padding: clamp(1rem, 4vw, 2rem); }
    h1 { margin: .35rem 0 1rem; font-size: clamp(1.6rem, 4vw, 2.4rem); line-height: 1.15; letter-spacing: 0; }
    .eyebrow { margin: 0; color: #596274; font-size: .88rem; text-transform: uppercase; letter-spacing: .08em; }
    .context, .meta, .notice { color: #596274; }
    .meta { display: flex; flex-wrap: wrap; gap: .75rem; border-bottom: 1px solid #e4e7ec; padding-bottom: 1rem; margin-bottom: 1.25rem; font-size: .95rem; }
    .body { white-space: normal; line-height: 1.65; }
    .notice { margin-top: 2rem; border-top: 1px solid #e4e7ec; padding-top: 1rem; font-size: .95rem; }
  </style>
</head>
<body><main>{$content}</main></body>
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

    private function isExpired(mixed $expiresAt): bool
    {
        $value = trim((string) $expiresAt);
        return $value !== '' && strtotime($value) !== false && strtotime($value) <= time();
    }

    /** @param array<string,mixed> $context */
    private function audit(string $event, array $context = []): void
    {
        $this->logger->info($event, $context);
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
