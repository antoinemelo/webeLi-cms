<?php

declare(strict_types=1);

namespace App\Application\PublicApi;

use App\Application\Forms\FormRepository;
use App\Application\Forms\FormValidationException;
use App\Core\ErrorCode;
use App\Core\Request;
use App\Core\Response;
use App\Repository\SiteRepository;
use InvalidArgumentException;

final class PublicFormApiHandler
{
    public function __construct(
        private readonly Request $request,
        private readonly FormRepository $forms,
        private readonly SiteRepository $sites,
    ) {}

    public function show(string $key): Response
    {
        $site = $this->site();
        $lang = $this->language($site);
        $form = $this->forms->findPublic((int)$site['id'], $key, $lang);
        if (!$form) {
            return Response::error(ErrorCode::ROUTE_NOT_FOUND, 'Formulaire introuvable.', 404, ['form_key' => $key]);
        }
        return Response::success(['form' => $form], 'public.forms.show.v1', ['site_id' => (int)$site['id'], 'language_code' => $lang]);
    }

    public function submit(string $key): Response
    {
        $site = $this->site();
        $lang = $this->language($site);
        try {
            $result = $this->forms->submit((int)$site['id'], $key, $lang, $this->payload(), $this->context());
            return Response::success($result, 'public.forms.submit.v1', ['site_id' => (int)$site['id'], 'language_code' => $lang]);
        } catch (FormValidationException $e) {
            return Response::validation($e->fields(), 'Veuillez corriger les champs indiqués.');
        } catch (InvalidArgumentException $e) {
            return Response::error(ErrorCode::ROUTE_NOT_FOUND, 'Formulaire introuvable.', 404, ['form_key' => $key]);
        }
    }

    private function site(): array { return $this->sites->resolveCurrentSite($this->request->server['HTTP_HOST'] ?? ''); }
    private function language(array $site): string { return (string)($this->request->query['lang'] ?? $site['default_language_code'] ?? 'fr'); }
    private function payload(): array { $json = $this->request->json(); $data = $json['data'] ?? $json; return is_array($data) ? $data : []; }
    private function context(): array
    {
        $ip = (string)($this->request->server['REMOTE_ADDR'] ?? '');
        return [
            'ip_hash' => $ip !== '' ? hash('sha256', $ip . '|' . (string)($this->request->server['HTTP_HOST'] ?? '')) : '',
            'user_agent' => substr((string)($this->request->server['HTTP_USER_AGENT'] ?? ''), 0, 500),
            'referer_url' => substr((string)($this->request->server['HTTP_REFERER'] ?? ''), 0, 1000),
        ];
    }
}
