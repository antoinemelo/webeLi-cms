<?php

declare(strict_types=1);

namespace App\Application\Admin;

use App\Application\Iam\PasswordResetService;
use App\Core\Request;
use App\Core\Response;
use App\Repository\AuthRepository;
use App\Mail\MailerInterface;
use App\Security\Csrf;
use App\Security\InputValidator;
use App\Security\RateLimiter;
use App\Security\SessionManager;

final class AdminAuthController
{
    private const RESET_GENERIC_MESSAGE = 'Si un compte actif correspond à cette adresse, un lien de réinitialisation vient d’être envoyé.';

    public function __construct(
        private readonly array $config,
        private readonly Request $request,
        private readonly AuthRepository $auth,
        private readonly PasswordResetService $passwordReset,
        private readonly MailerInterface $mailer,
    ) {}

    public function login(): Response
    {
        $error = '';
        $notice = (string) ($this->request->query['notice'] ?? '');
        $email = '';
        $challenge = 'email';

        if ($this->request->method === 'POST') {
            if (!Csrf::verify((string) $this->request->input('_csrf', ''))) {
                $error = 'Session expirée. Rechargez la page puis réessayez.';
            } else {
                try {
                    $email = InputValidator::email((string) $this->request->input('email', ''));
                    $challenge = (string) $this->request->input('challenge', 'email');
                    $ip = (string) ($this->request->server['REMOTE_ADDR'] ?? 'unknown');
                    $limiter = new RateLimiter($this->auth->database());
                    $rateKey = 'login|' . $ip . '|' . $email;

                    if (!$limiter->hit($rateKey, (int) ($this->config['app']['login_rate_limit_attempts'] ?? 5), (int) ($this->config['app']['login_rate_limit_window'] ?? 900))) {
                        $error = 'Trop de tentatives. Réessayez plus tard.';
                    } elseif ($challenge === 'email') {
                        $lookup = $this->auth->loginChallengeForEmail($email);
                        $challenge = (string) $lookup['challenge'];
                        $email = (string) $lookup['email'];
                        if ($challenge === 'email_code') {
                            $created = $this->auth->createEmailTwoFactorChallenge($email, [
                                'ip' => $ip,
                                'user_agent' => (string) ($this->request->server['HTTP_USER_AGENT'] ?? ''),
                            ]);
                            if (($created['status'] ?? '') !== 'ok' || empty($created['code']) || !$this->sendLoginCodeEmail((string) ($created['email'] ?? $email), (string) $created['code'])) {
                                $error = 'Impossible d’envoyer le code de connexion. Réessayez plus tard.';
                                $challenge = 'email';
                            } else {
                                $notice = 'code_sent';
                            }
                        }
                    } elseif ($challenge === 'email_code' || $challenge === 'email_2fa') {
                        $loginResult = $this->auth->verifyEmailTwoFactorCode($email, (string) ($this->request->input('email_code', '') ?: $this->request->input('totp_code', '')), [
                            'ip' => $ip,
                            'user_agent' => (string) ($this->request->server['HTTP_USER_AGENT'] ?? ''),
                        ]);
                        if ($loginResult['status'] === 'ok') {
                            $limiter->clear($rateKey);
                            return redirect(admin_url_path('/admin/app'));
                        }
                        $challenge = 'email_code';
                        $error = match ($loginResult['status']) {
                            'email_code_required' => 'Code requis pour ce compte.',
                            'expired_email_code' => 'Code expiré. Revenez à l’étape précédente pour recevoir un nouveau code.',
                            'invalid_email_code' => 'Code invalide ou déjà utilisé.',
                            default => 'Identifiants invalides.',
                        };
                    } else {
                        $loginResult = $this->auth->attemptWithTotp($email, (string) $this->request->input('password', ''), (string) $this->request->input('totp_code', ''), [
                            'ip' => $ip,
                            'user_agent' => (string) ($this->request->server['HTTP_USER_AGENT'] ?? ''),
                        ]);
                        if (($loginResult['status'] ?? '') === 'ok') {
                            $limiter->clear($rateKey);
                            return redirect(admin_url_path('/admin/app'));
                        }
                        $challenge = $challenge === 'totp' ? 'totp' : 'password';
                        $error = match ($loginResult['status'] ?? '') {
                            'totp_required' => 'Code TOTP requis pour ce compte.',
                            'invalid_totp' => 'Code TOTP invalide.',
                            default => 'Identifiants invalides.',
                        };
                    }
                } catch (\InvalidArgumentException) {
                    $error = 'Identifiants invalides.';
                    $challenge = 'email';
                }
            }
        }

        return Response::html($this->renderLogin($error, $notice, $email, $challenge), 200, self::authSecurityHeaders());
    }


    private function sendLoginCodeEmail(string $email, string $code): bool
    {
        $subject = 'Code de connexion DEC CMS';
        $text = "Bonjour,

Votre code de connexion est : {$code}

Ce code expire dans 10 minutes. Si vous n’êtes pas à l’origine de cette demande, ignorez ce message.
";
        $htmlCode = e($code);
        $html = '<p>Bonjour,</p><p>Votre code de connexion est :</p><p style="font-size:24px;font-weight:700;letter-spacing:0.18em">' . $htmlCode . '</p><p>Ce code expire dans 10 minutes. Si vous n’êtes pas à l’origine de cette demande, ignorez ce message.</p>';
        return $this->mailer->send($email, $subject, $text, $html);
    }

    public function forgotPassword(): Response
    {
        $error = '';
        $message = '';
        $email = trim((string) $this->request->input('email', ''));

        if ($this->request->method === 'POST') {
            if (!Csrf::verify((string) $this->request->input('_csrf', ''))) {
                $error = 'Session expirée. Rechargez la page puis réessayez.';
            } else {
                $ip = (string) ($this->request->server['REMOTE_ADDR'] ?? 'unknown');
                $emailForRateLimit = strtolower(trim($email));
                $limiter = new RateLimiter($this->auth->database());
                $attempts = (int) ($this->config['app']['password_reset']['rate_limit_attempts'] ?? 5);
                $window = (int) ($this->config['app']['password_reset']['rate_limit_window'] ?? 900);
                $ipAllowed = $limiter->hit('password_reset_ip|' . $ip, $attempts, $window);
                $emailAllowed = $limiter->hit('password_reset_email|' . $emailForRateLimit, $attempts, $window);

                if (!$ipAllowed || !$emailAllowed) {
                    $error = 'Trop de demandes. Réessayez plus tard.';
                } else {
                    if (filter_var($emailForRateLimit, FILTER_VALIDATE_EMAIL) !== false) {
                        $this->passwordReset->requestResetByEmail($emailForRateLimit, [
                            'ip' => $ip,
                            'user_agent' => (string) ($this->request->server['HTTP_USER_AGENT'] ?? ''),
                        ]);
                    }
                    $message = self::RESET_GENERIC_MESSAGE;
                    $email = '';
                }
            }
        }

        return Response::html($this->renderForgotPassword($error, $message, $email), 200, self::authSecurityHeaders());
    }

    public function resetPassword(): Response
    {
        $error = '';
        $message = '';
        $selector = trim((string) ($this->request->input('selector', $this->request->query['selector'] ?? '')));
        $token = trim((string) ($this->request->input('token', $this->request->query['token'] ?? '')));
        $canShowForm = $selector !== '' && $token !== '' && $this->passwordReset->validateToken($selector, $token) !== null;

        if ($this->request->method === 'POST') {
            $canShowForm = true;
            if (!Csrf::verify((string) $this->request->input('_csrf', ''))) {
                $error = 'Session expirée. Rechargez la page puis réessayez.';
            } else {
                $password = (string) $this->request->input('password', '');
                $passwordConfirm = (string) $this->request->input('password_confirm', '');

                if ($password !== $passwordConfirm) {
                    $error = 'Les deux mots de passe ne correspondent pas.';
                } elseif (mb_strlen(trim($password)) < $this->passwordReset->minimumPasswordLength()) {
                    $error = 'Le nouveau mot de passe doit contenir au moins ' . $this->passwordReset->minimumPasswordLength() . ' caractères.';
                } else {
                    try {
                        $this->passwordReset->resetPassword($selector, $token, $password, [
                            'ip' => (string) ($this->request->server['REMOTE_ADDR'] ?? 'unknown'),
                            'user_agent' => (string) ($this->request->server['HTTP_USER_AGENT'] ?? ''),
                        ]);
                        $message = 'Votre mot de passe a été modifié. Vous pouvez maintenant vous connecter.';
                        $canShowForm = false;
                    } catch (\RuntimeException $e) {
                        $error = $e->getMessage() !== '' ? $e->getMessage() : 'Lien invalide ou expiré.';
                    }
                }
            }
        } elseif (!$canShowForm) {
            $error = 'Lien invalide ou expiré.';
        }

        return Response::html($this->renderResetPassword($error, $message, $selector, $token, $canShowForm), 200, self::authSecurityHeaders());
    }

    public function logout(): Response
    {
        try {
            if (!SessionManager::enforceIdleTimeout((int) ($this->config['app']['session_idle_timeout'] ?? 3600))) {
                return redirect(admin_url_path('/admin/login?notice=session_expired'));
            }
            $this->auth->requireAuth();
        } catch (\RuntimeException) {
            return redirect(admin_url_path('/admin/login'));
        }

        if ($this->request->method !== 'POST' || !Csrf::verify((string) $this->request->input('_csrf', ''))) {
            return Response::html('<h1>403</h1>', 403, self::authSecurityHeaders());
        }

        $this->auth->logout();
        return redirect(admin_url_path('/admin/login'));
    }


    /** @return array<string,string> */
    private static function authSecurityHeaders(): array
    {
        return [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private, max-age=0',
            'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Referrer-Policy' => 'same-origin',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; form-action 'self'; frame-ancestors 'self'; base-uri 'self'",
        ];
    }

    private function renderLogin(string $error, string $notice, string $email = '', string $challenge = 'email'): string
    {
        $message = match ($notice) {
            'session_expired' => 'Votre session a expiré. Merci de vous reconnecter.',
            'code_sent' => 'Un code de connexion vient d’être envoyé par email.',
            default => '',
        };
        $errorHtml = $error !== '' ? '<p class="alert alert-error">' . e($error) . '</p>' : '';
        $noticeHtml = $message !== '' ? '<p class="alert alert-notice">' . e($message) . '</p>' : '';
        $action = e(admin_url_path('/admin/login'));
        $forgotPassword = e(admin_url_path('/admin/forgot-password'));
        $csrf = e(Csrf::token());
        $adminApp = e(admin_url_path('/admin/app'));
        $styles = self::authStyles();
        $emailValue = e($email);
        $challengeValue = e($challenge);
        $isEmailStep = $challenge === 'email';
        $title = 'Connexion';
        $hint = $isEmailStep ? '' : 'Compte : ' . $email;
        $hintHtml = $hint !== '' ? '<p class="hint">' . e($hint) . '</p>' : '';
        $buttonLabel = $isEmailStep ? 'Continuer' : ($challenge === 'email_code' ? 'Valider le code' : 'Se connecter');
        $backLink = $isEmailStep ? '' : '<p class="secondary-link"><a href="' . $action . '">Changer d’email</a></p>';
        $credentialFields = '';

        if ($isEmailStep) {
            $credentialFields = '<input type="hidden" name="challenge" value="email"><p class="field"><label for="email">Email</label><input id="email" name="email" type="email" autocomplete="username" value="' . $emailValue . '" required autofocus></p>';
        } elseif ($challenge === 'email_code') {
            $credentialFields = '<input type="hidden" name="challenge" value="email_code"><input type="hidden" name="email" value="' . $emailValue . '"><p class="field"><label for="email_code">Code reçu par email</label><input id="email_code" name="email_code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{4,6}" minlength="4" maxlength="6" placeholder="123456" required autofocus></p>';
        } elseif ($challenge === 'totp') {
            $credentialFields = '<input type="hidden" name="challenge" value="totp"><input type="hidden" name="email" value="' . $emailValue . '"><p class="field"><label for="password">Mot de passe</label><span class="password-field"><input id="password" name="password" type="password" autocomplete="current-password" required autofocus><button class="password-toggle" type="button" data-password-toggle="password" aria-label="Afficher le mot de passe" aria-pressed="false"><svg class="password-toggle__icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8ZM1.173 8A13.133 13.133 0 0 1 8 3.5 13.133 13.133 0 0 1 14.827 8 13.133 13.133 0 0 1 8 12.5 13.133 13.133 0 0 1 1.173 8Z"/><path d="M8 5.5A2.5 2.5 0 1 1 8 10.5 2.5 2.5 0 0 1 8 5.5ZM8 6.5A1.5 1.5 0 1 0 8 9.5 1.5 1.5 0 0 0 8 6.5Z"/></svg></button></span></p><p class="field"><label for="totp_code">Code TOTP ou code de récupération</label><input id="totp_code" name="totp_code" type="text" inputmode="text" autocomplete="one-time-code" minlength="6" maxlength="32" placeholder="123456 ou ABC12-DEF34" required></p>';
        } else {
            $credentialFields = '<input type="hidden" name="challenge" value="password"><input type="hidden" name="email" value="' . $emailValue . '"><p class="field"><label for="password">Mot de passe</label><span class="password-field"><input id="password" name="password" type="password" autocomplete="current-password" required autofocus><button class="password-toggle" type="button" data-password-toggle="password" aria-label="Afficher le mot de passe" aria-pressed="false"><svg class="password-toggle__icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8ZM1.173 8A13.133 13.133 0 0 1 8 3.5 13.133 13.133 0 0 1 14.827 8 13.133 13.133 0 0 1 8 12.5 13.133 13.133 0 0 1 1.173 8Z"/><path d="M8 5.5A2.5 2.5 0 1 1 8 10.5 2.5 2.5 0 0 1 8 5.5ZM8 6.5A1.5 1.5 0 1 0 8 9.5 1.5 1.5 0 0 0 8 6.5Z"/></svg></button></span></p>';
        }

        return <<<HTML
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Connexion — DEC CMS Admin</title>
  {$styles}
</head>
<body>
  <main aria-labelledby="login-title">
    <h1 id="login-title">{$title}</h1>
    {$hintHtml}{$noticeHtml}{$errorHtml}
    <form method="post" action="{$action}" autocomplete="on">
      <input type="hidden" name="_csrf" value="{$csrf}">
      {$credentialFields}
      <button type="submit">{$buttonLabel}</button>
    </form>
    {$backLink}
    <p class="secondary-link"><a href="{$forgotPassword}">Mot de passe oublié ?</a></p>
    <p class="target">Après connexion : <code>{$adminApp}</code></p>
  </main>
  {$this->passwordToggleScript()}
</body>
</html>
HTML;
    }

    private function renderForgotPassword(string $error, string $message, string $email): string
    {
        $errorHtml = $error !== '' ? '<p class="alert alert-error">' . e($error) . '</p>' : '';
        $messageHtml = $message !== '' ? '<p class="alert alert-notice">' . e($message) . '</p>' : '';
        $action = e(admin_url_path('/admin/forgot-password'));
        $login = e(admin_url_path('/admin/login'));
        $csrf = e(Csrf::token());
        $emailValue = e($email);
        $styles = self::authStyles();

        return <<<HTML
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Mot de passe oublié — DEC CMS Admin</title>
  {$styles}
</head>
<body>
  <main aria-labelledby="forgot-title">
    <h1 id="forgot-title">Mot de passe oublié</h1>
    <p class="hint">Indiquez l’e-mail de votre compte administrateur. Si un compte actif existe, un lien à usage unique vous sera envoyé.</p>
    {$messageHtml}{$errorHtml}
    <form method="post" action="{$action}" autocomplete="on">
      <input type="hidden" name="_csrf" value="{$csrf}">
      <p class="field"><label for="email">Email</label><input id="email" name="email" type="email" autocomplete="username" value="{$emailValue}" required autofocus></p>
      <button type="submit">Envoyer le lien de réinitialisation</button>
    </form>
    <p class="secondary-link"><a href="{$login}">Retour à la connexion</a></p>
  </main>
</body>
</html>
HTML;
    }

    private function renderResetPassword(string $error, string $message, string $selector, string $token, bool $canShowForm): string
    {
        $errorHtml = $error !== '' ? '<p class="alert alert-error">' . e($error) . '</p>' : '';
        $messageHtml = $message !== '' ? '<p class="alert alert-notice">' . e($message) . '</p>' : '';
        $action = e(admin_url_path('/admin/reset-password'));
        $login = e(admin_url_path('/admin/login'));
        $csrf = e(Csrf::token());
        $selectorValue = e($selector);
        $tokenValue = e($token);
        $minLength = $this->passwordReset->minimumPasswordLength();
        $styles = self::authStyles();
        $form = '';

        if ($canShowForm) {
            $form = <<<HTML
    <form method="post" action="{$action}" autocomplete="off">
      <input type="hidden" name="_csrf" value="{$csrf}">
      <input type="hidden" name="selector" value="{$selectorValue}">
      <input type="hidden" name="token" value="{$tokenValue}">
      <p class="field"><label for="password">Nouveau mot de passe</label><span class="password-field"><input id="password" name="password" type="password" autocomplete="new-password" minlength="{$minLength}" required autofocus><button class="password-toggle" type="button" data-password-toggle="password" aria-label="Afficher le mot de passe" aria-pressed="false"><svg class="password-toggle__icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8ZM1.173 8A13.133 13.133 0 0 1 8 3.5 13.133 13.133 0 0 1 14.827 8 13.133 13.133 0 0 1 8 12.5 13.133 13.133 0 0 1 1.173 8Z"/><path d="M8 5.5A2.5 2.5 0 1 1 8 10.5 2.5 2.5 0 0 1 8 5.5ZM8 6.5A1.5 1.5 0 1 0 8 9.5 1.5 1.5 0 0 0 8 6.5Z"/></svg></button></span></p>
      <p class="field"><label for="password_confirm">Confirmation</label><span class="password-field"><input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" minlength="{$minLength}" required><button class="password-toggle" type="button" data-password-toggle="password_confirm" aria-label="Afficher le mot de passe" aria-pressed="false"><svg class="password-toggle__icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8ZM1.173 8A13.133 13.133 0 0 1 8 3.5 13.133 13.133 0 0 1 14.827 8 13.133 13.133 0 0 1 8 12.5 13.133 13.133 0 0 1 1.173 8Z"/><path d="M8 5.5A2.5 2.5 0 1 1 8 10.5 2.5 2.5 0 0 1 8 5.5ZM8 6.5A1.5 1.5 0 1 0 8 9.5 1.5 1.5 0 0 0 8 6.5Z"/></svg></button></span></p>
      <button type="submit">Modifier le mot de passe</button>
    </form>
HTML;
        }

        return <<<HTML
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Réinitialisation — DEC CMS Admin</title>
  {$styles}
</head>
<body>
  <main aria-labelledby="reset-title">
    <h1 id="reset-title">Réinitialisation du mot de passe</h1>
    <p class="hint">Choisissez un nouveau mot de passe pour votre compte administrateur.</p>
    {$messageHtml}{$errorHtml}
{$form}
    <p class="secondary-link"><a href="{$login}">Retour à la connexion</a></p>
  </main>
  {$this->passwordToggleScript()}
</body>
</html>
HTML;
    }


    private function passwordToggleScript(): string
    {
        return <<<'HTML'
<script>
document.addEventListener('click', function (event) {
  var button = event.target.closest('[data-password-toggle]');
  if (!button) return;
  var input = document.getElementById(button.getAttribute('data-password-toggle'));
  if (!input) return;
  var show = input.type === 'password';
  input.type = show ? 'text' : 'password';
  button.setAttribute('aria-pressed', show ? 'true' : 'false');
  button.setAttribute('aria-label', show ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
});
</script>
HTML;
    }

    private static function authStyles(): string
    {
        return <<<'HTML'
  <style>
    :root{color-scheme:light;--bg:#f6f7fb;--card:#fff;--text:#172033;--muted:#64748b;--line:#e5e7eb;--primary:#1d4ed8;--danger:#b91c1c;--notice:#075985}
    *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:var(--bg);font:16px/1.55 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--text);padding:24px}
    main{width:min(100%,440px);background:var(--card);border:1px solid var(--line);border-radius:24px;box-shadow:0 24px 70px rgba(15,23,42,.10);padding:28px}
    h1{font-size:1.55rem;line-height:1.2;margin:0 0 8px}.hint{color:var(--muted);margin:0 0 22px}.field{display:grid;gap:6px;margin:0 0 14px}label{font-weight:650}input{width:100%;border:1px solid var(--line);border-radius:12px;padding:11px 12px;font:inherit}.password-field{position:relative;display:block}.password-field input{padding-right:50px}.password-toggle{position:absolute;right:8px;top:50%;transform:translateY(-50%);display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border:1px solid transparent;border-radius:999px;background:transparent;color:var(--muted);padding:0;cursor:pointer;transition:background-color .15s ease,border-color .15s ease,color .15s ease,box-shadow .15s ease}.password-toggle__icon{width:18px;height:18px;fill:currentColor}.password-toggle:hover,.password-toggle:focus-visible,.password-toggle[aria-pressed="true"]{background:#f8fafc;border-color:#cbd5e1;color:var(--primary);box-shadow:0 0 0 3px rgba(29,78,216,.10);outline:0}input:focus{outline:3px solid rgba(29,78,216,.16);border-color:var(--primary)}button{width:100%;border:0;border-radius:12px;background:var(--primary);color:white;font:700 1rem/1 system-ui,sans-serif;padding:13px 16px;cursor:pointer}.alert{border-radius:12px;padding:10px 12px;margin:0 0 14px}.alert-error{background:#fee2e2;color:var(--danger)}.alert-notice{background:#e0f2fe;color:var(--notice)}.target{margin-top:18px;color:var(--muted);font-size:.92rem}.target code{background:#f1f5f9;border-radius:6px;padding:.1rem .35rem;color:var(--text)}.secondary-link{margin:16px 0 0;text-align:center}.secondary-link a{color:var(--primary);font-weight:650;text-decoration:none}.secondary-link a:hover{text-decoration:underline}.optional{color:var(--muted);font-weight:500}
  </style>
HTML;
    }
}
