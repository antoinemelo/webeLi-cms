<?php

declare(strict_types=1);

namespace App\Application\Iam;

use App\Core\Request;
use App\Mail\MailerInterface;
use App\Repository\AuthRepository;
use RuntimeException;

final class PasswordResetService
{
    public function __construct(
        private readonly AuthRepository $auth,
        private readonly MailerInterface $mailer,
        private readonly array $config,
        private readonly ?Request $request = null,
    ) {}

    public function requestResetByEmail(string $email, array $meta = []): void
    {
        $email = self::normalizeEmail($email);
        if ($email === '') {
            $this->auth->audit(null, 'auth.password_reset.request_ignored', 'iam_user', null, ['reason' => 'empty_email']);
            return;
        }

        $user = $this->auth->findActiveUserByEmail($email);
        if (!$user) {
            $this->auth->audit(null, 'auth.password_reset.request_unknown', 'iam_user', null, [
                'email_hash' => hash('sha256', $email),
                'ip' => $meta['ip'] ?? null,
            ]);
            return;
        }

        $userId = (int) $user['id'];
        if (!$this->auth->canRequestPasswordReset($userId, $this->cooldownSeconds())) {
            $this->auth->audit($userId, 'auth.password_reset.cooldown', 'iam_user', $userId, ['ip' => $meta['ip'] ?? null]);
            return;
        }

        $selector = bin2hex(random_bytes(9));
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $requestedAt = now_utc();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + ($this->tokenLifetimeMinutes() * 60));

        $this->auth->storePasswordResetToken($userId, $selector, $tokenHash, $expiresAt, $requestedAt);
        $link = $this->buildResetLink($selector, $token);

        $displayName = $this->displayName($user);
        $subject = 'Réinitialisation de votre mot de passe';
        $text = $this->buildResetTextEmail($displayName, $link);
        $html = $this->buildResetHtmlEmail($displayName, $link);

        try {
            if ($this->mailer->send((string) $user['email'], $subject, $text, $html)) {
                $this->auth->markPasswordResetSent($userId);
                $this->auth->audit($userId, 'auth.password_reset.sent', 'iam_user', $userId, [
                    'expires_at' => $expiresAt,
                    'ip' => $meta['ip'] ?? null,
                ]);
            } else {
                $this->auth->audit($userId, 'auth.password_reset.mail_failed', 'iam_user', $userId, ['ip' => $meta['ip'] ?? null]);
            }
        } catch (\Throwable $e) {
            $this->auth->audit($userId, 'auth.password_reset.mail_failed', 'iam_user', $userId, ['ip' => $meta['ip'] ?? null]);
        }
    }

    /** @return array<string,mixed>|null */
    public function validateToken(string $selector, string $token): ?array
    {
        $selector = trim($selector);
        $token = trim($token);
        if ($selector === '' || $token === '') {
            return null;
        }

        $user = $this->auth->findUserByPasswordResetSelector($selector);
        if (!$user || (int) ($user['is_active'] ?? 0) !== 1) {
            return null;
        }

        $storedHash = (string) ($user['password_reset_token_hash'] ?? '');
        $expiresAt = (string) ($user['password_reset_expires_at'] ?? '');
        if ($storedHash === '' || $expiresAt === '') {
            return null;
        }

        $computedHash = hash('sha256', $token);
        if (!hash_equals($storedHash, $computedHash)) {
            return null;
        }

        $expiresTs = strtotime($expiresAt . ' UTC');
        if ($expiresTs === false || $expiresTs < time()) {
            return null;
        }

        return $user;
    }

    public function resetPassword(string $selector, string $token, string $newPassword, array $meta = []): void
    {
        $user = $this->validateToken($selector, $token);
        if (!$user) {
            $this->auth->audit(null, 'auth.password_reset.invalid_token', 'iam_user', null, ['ip' => $meta['ip'] ?? null]);
            throw new RuntimeException('Lien invalide ou expiré.');
        }

        $newPassword = trim($newPassword);
        if (mb_strlen($newPassword) < $this->minimumPasswordLength()) {
            throw new RuntimeException('Le nouveau mot de passe doit contenir au moins ' . $this->minimumPasswordLength() . ' caractères.');
        }

        $userId = (int) $user['id'];
        $this->auth->updateUserPasswordAfterReset($userId, password_hash($newPassword, PASSWORD_DEFAULT));
        $this->auth->clearPasswordResetToken($userId);

        if ($this->revokeAllSessionsOnReset()) {
            $this->auth->revokeAllSessionsForUser($userId);
        }

        $this->auth->audit($userId, 'auth.password_reset.completed', 'iam_user', $userId, [
            'sessions_revoked' => $this->revokeAllSessionsOnReset(),
            'ip' => $meta['ip'] ?? null,
        ]);

        $this->sendResetConfirmationEmail($user);
    }

    private static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private function tokenLifetimeMinutes(): int
    {
        return max(5, (int) ($this->config['app']['password_reset']['token_lifetime_minutes'] ?? 30));
    }

    private function cooldownSeconds(): int
    {
        return max(60, (int) ($this->config['app']['password_reset']['cooldown_seconds'] ?? 600));
    }

    public function minimumPasswordLength(): int
    {
        return max(10, (int) ($this->config['app']['password_reset']['minimum_password_length'] ?? 10));
    }

    private function revokeAllSessionsOnReset(): bool
    {
        return (bool) ($this->config['app']['password_reset']['revoke_all_sessions_on_reset'] ?? true);
    }

    private function buildResetLink(string $selector, string $token): string
    {
        $baseUrl = rtrim((string) ($this->config['app']['public_base_url'] ?? ''), '/');
        if ($baseUrl === '') {
            $baseUrl = $this->guessPublicBaseUrl();
        }

        return $baseUrl . url_path('/admin/reset-password') . '?' . http_build_query([
            'selector' => $selector,
            'token' => $token,
        ]);
    }

    private function guessPublicBaseUrl(): string
    {
        $server = $this->request?->server ?? $_SERVER;
        $https = (string) ($server['HTTPS'] ?? '') !== '' && strtolower((string) $server['HTTPS']) !== 'off';
        $scheme = $https ? 'https' : 'http';
        $host = (string) ($server['HTTP_HOST'] ?? 'localhost');
        return $scheme . '://' . $host;
    }

    private function resetValidityLabel(): string
    {
        $minutes = $this->tokenLifetimeMinutes();
        if ($minutes < 60) {
            return $minutes . ' minute' . ($minutes > 1 ? 's' : '');
        }
        $hours = intdiv($minutes, 60);
        $remaining = $minutes % 60;
        if ($remaining === 0) {
            return $hours . ' heure' . ($hours > 1 ? 's' : '');
        }
        return $hours . ' heure' . ($hours > 1 ? 's' : '') . ' et ' . $remaining . ' minute' . ($remaining > 1 ? 's' : '');
    }

    /** @param array<string,mixed> $user */
    private function displayName(array $user): string
    {
        $name = trim(trim((string) ($user['first_name'] ?? '')) . ' ' . trim((string) ($user['last_name'] ?? '')));
        return $name !== '' ? $name : (string) ($user['email'] ?? '');
    }

    private function buildResetTextEmail(string $displayName, string $link): string
    {
        $validity = $this->resetValidityLabel();
        return "Bonjour {$displayName},\n\n"
            . "Une demande de réinitialisation de mot de passe a été effectuée pour votre compte administrateur.\n\n"
            . "Pour choisir un nouveau mot de passe, utilisez le lien suivant :\n{$link}\n\n"
            . "Ce lien est valable pendant {$validity} et ne peut être utilisé qu’une seule fois.\n\n"
            . "Si vous n’êtes pas à l’origine de cette demande, vous pouvez ignorer cet e-mail.\n";
    }

    private function buildResetHtmlEmail(string $displayName, string $link): string
    {
        $validity = $this->resetValidityLabel();
        return '<p>Bonjour ' . e($displayName) . ',</p>'
            . '<p>Une demande de réinitialisation de mot de passe a été effectuée pour votre compte administrateur.</p>'
            . '<p><a href="' . e($link) . '">Choisir un nouveau mot de passe</a></p>'
            . '<p>Ce lien est valable pendant ' . e($validity) . ' et ne peut être utilisé qu’une seule fois.</p>'
            . '<p>Si vous n’êtes pas à l’origine de cette demande, vous pouvez ignorer cet e-mail.</p>';
    }

    /** @param array<string,mixed> $user */
    private function sendResetConfirmationEmail(array $user): void
    {
        $email = trim((string) ($user['email'] ?? ''));
        if ($email === '') {
            return;
        }
        $displayName = $this->displayName($user);
        $subject = 'Votre mot de passe a été modifié';
        $text = "Bonjour {$displayName},\n\n"
            . "Le mot de passe de votre compte administrateur vient d’être modifié.\n\n"
            . "Si vous n’êtes pas à l’origine de cette modification, contactez immédiatement l’administrateur du site.\n";
        $html = '<p>Bonjour ' . e($displayName) . ',</p>'
            . '<p>Le mot de passe de votre compte administrateur vient d’être modifié.</p>'
            . '<p>Si vous n’êtes pas à l’origine de cette modification, contactez immédiatement l’administrateur du site.</p>';

        try {
            $this->mailer->send($email, $subject, $text, $html);
        } catch (\Throwable) {
            // Le mot de passe est déjà changé : l'échec de notification ne doit pas casser le flux.
        }
    }
}
