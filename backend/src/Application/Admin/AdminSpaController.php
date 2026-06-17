<?php

declare(strict_types=1);

namespace App\Application\Admin;

use App\Core\Response;
use App\Repository\AuthRepository;
use App\Security\SessionManager;

final class AdminSpaController
{
    public function __construct(
        private readonly array $config,
        private readonly AuthRepository $auth,
    ) {}

    public function index(): Response
    {
        try {
            if (!SessionManager::enforceIdleTimeout((int) ($this->config['app']['session_idle_timeout'] ?? 3600))) {
                return redirect(admin_url_path('/admin/login?notice=session_expired'));
            }
            $this->auth->requireAuth();
        } catch (\RuntimeException) {
            return redirect(admin_url_path('/admin/login'));
        }

        $manifestPath = base_path('admin-app/.vite/manifest.json');
        if (!is_file($manifestPath)) {
            return Response::html($this->notBuiltHtml());
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $entry = is_array($manifest) ? ($manifest['index.html'] ?? null) : null;
        if (!is_array($entry) || empty($entry['file'])) {
            return Response::html($this->notBuiltHtml('Manifest Vite incomplet.'));
        }

        $css = '';
        foreach (($entry['css'] ?? []) as $file) {
            $href = asset_path('/admin-app/' . ltrim((string) $file, '/'));
            $css .= '<link rel="stylesheet" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">';
        }
        $script = asset_path('/admin-app/' . ltrim((string) $entry['file'], '/'));
        $runtimeConfig = json_encode([
            'basePath' => app_base_path(),
            'siteBasePath' => current_site_base_path(),
            'adminAppPath' => admin_url_path('/admin/app'),
            'apiBasePath' => admin_url_path('/admin/api'),
            'logoutPath' => admin_url_path('/admin/logout'),
            'loginPath' => admin_url_path('/admin/login'),
            'user' => $this->runtimeUser(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($runtimeConfig)) {
            $runtimeConfig = '{}';
        }

        return Response::html('<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>DEC CMS Admin</title>' . $css . '</head><body><div id="app"></div><script>window.__AMCMS_ADMIN__=' . $runtimeConfig . ';</script><script type="module" src="' . htmlspecialchars($script, ENT_QUOTES, 'UTF-8') . '"></script></body></html>', 200, self::adminSecurityHeaders());
    }


    /** @return array{id:int,email:string,name:string} */
    private function runtimeUser(): array
    {
        $user = $this->auth->user() ?? [];
        $profile = $this->auth->currentUserProfile();
        $profileName = trim(((string) ($profile['first_name'] ?? '')) . ' ' . ((string) ($profile['last_name'] ?? '')));
        $name = trim((string) ($user['name'] ?? ''));
        if ($name === '') {
            $name = $profileName;
        }

        return [
            'id' => (int) ($user['id'] ?? $profile['id'] ?? 0),
            'email' => (string) ($user['email'] ?? $profile['email'] ?? ''),
            'name' => $name !== '' ? $name : (string) ($user['email'] ?? $profile['email'] ?? ''),
        ];
    }

    private function notBuiltHtml(string $message = ''): string
    {
        $detail = $message !== '' ? '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>' : '';
        return '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>DEC CMS Admin Vue</title><style>body{font:16px/1.55 system-ui,sans-serif;background:#f6f7fb;color:#172033;padding:2rem;max-width:850px;margin:auto}.card{background:white;border:1px solid #e5e7eb;border-radius:16px;padding:1.2rem;box-shadow:0 10px 30px rgba(15,23,42,.06)}code{background:#f1f5f9;border-radius:6px;padding:.1rem .35rem}</style><div class="card"><h1>Back-office Vue non compilé</h1>' . $detail . '<p>Compilez les assets avant d’utiliser <code>/admin/app</code> :</p><pre>cd frontend/admin-vue\nnpm install\nnpm run build</pre><p><code>/admin</code> redirige maintenant vers le back-office natif. L’application Vue reste servie par <code>/admin/app</code>.</p></div></html>';
    }

    /** @return array<string,string> */
    private static function adminSecurityHeaders(): array
    {
        return [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private, max-age=0',
            'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Referrer-Policy' => 'same-origin',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'",
        ];
    }
}
