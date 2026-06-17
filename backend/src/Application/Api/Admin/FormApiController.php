<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Forms\FormRepository;
use App\Core\ErrorCode;
use App\Core\Request;
use App\Core\Response;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;
use InvalidArgumentException;

final class FormApiController
{
    /** Contract documented for the CSV admin endpoint and checked by tools/python/v_validate_admin_contracts.py. */
    private const EXPORT_CSV_CONTRACT = 'admin.forms.export_csv.v1';

    public function __construct(
        private readonly Request $request,
        private readonly FormRepository $forms,
        private readonly SiteRepository $sites,
        private readonly AuthRepository $auth,
        private readonly Authorization $authorization,
    ) {}

    public function index(): Response
    {
        $this->auth->requireAuth();
        $this->authorization->require('forms.read');
        $site = $this->site();
        $q = trim((string)($this->request->query['q'] ?? ''));
        return Response::success($this->forms->list((int)$site['id'], $q, $this->language($site)), 'admin.forms.index.v1', $this->meta($site));
    }

    public function show(string|int $id): Response
    {
        $this->auth->requireAuth();
        $this->authorization->require('forms.read');
        $site = $this->site();
        $formId = $this->idParam($id);
        $form = $this->forms->findById((int)$site['id'], $formId, $this->language($site));
        if (!$form) { return $this->notFound($id); }
        return Response::success(['form' => $form], 'admin.forms.show.v1', $this->meta($site));
    }

    public function store(): Response
    {
        $this->auth->requireAuth();
        $this->authorization->require('forms.manage');
        $site = $this->site();
        try {
            $form = $this->forms->create((int)$site['id'], $this->payload(), (int)($this->auth->user()['id'] ?? 0));
            return Response::success(['form' => $form], 'admin.forms.write.v1', $this->meta($site), 201);
        } catch (InvalidArgumentException $e) {
            return Response::validation(['form' => [$e->getMessage()]], 'Formulaire invalide.');
        }
    }

    public function update(string|int $id): Response
    {
        $this->auth->requireAuth();
        $this->authorization->require('forms.manage');
        $site = $this->site();
        $formId = $this->idParam($id);
        try {
            $form = $this->forms->update((int)$site['id'], $formId, $this->payload(), (int)($this->auth->user()['id'] ?? 0));
            if (!$form) { return $this->notFound($id); }
            return Response::success(['form' => $form], 'admin.forms.write.v1', $this->meta($site));
        } catch (InvalidArgumentException $e) {
            return Response::validation(['form' => [$e->getMessage()]], 'Formulaire invalide.');
        }
    }

    public function destroy(string|int $id): Response
    {
        $this->auth->requireAuth();
        $this->authorization->require('forms.manage');
        $site = $this->site();
        $formId = $this->idParam($id);
        $this->forms->delete((int)$site['id'], $formId);
        return Response::success(['deleted' => true, 'id' => $formId], 'admin.forms.delete.v1', $this->meta($site));
    }

    public function submissions(string|int $id): Response
    {
        $this->auth->requireAuth();
        $this->authorization->require('forms.read');
        $site = $this->site();
        $limit = (int)($this->request->query['limit'] ?? 100);
        return Response::success($this->forms->submissions((int)$site['id'], $this->idParam($id), $limit), 'admin.forms.submissions.index.v1', $this->meta($site));
    }

    public function exportCsv(string|int $id): Response
    {
        $this->auth->requireAuth();
        $this->authorization->require('forms.read');
        $site = $this->site();
        $formId = $this->idParam($id);
        $rows = $this->forms->csvRows((int)$site['id'], $formId);
        if ($rows === []) { return $this->notFound($id); }
        $fh = fopen('php://temp', 'r+');
        foreach ($rows as $row) { fputcsv($fh, $row, ';'); }
        rewind($fh);
        $csv = stream_get_contents($fh) ?: '';
        fclose($fh);
        return new Response(200, $csv, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="form-' . $formId . '-submissions.csv"',
        ]);
    }

    private function site(): array { return $this->sites->resolveCurrentSite($this->request->server['HTTP_HOST'] ?? ''); }
    private function language(array $site): string { return (string)($this->request->query['lang'] ?? $this->request->query['content_language_code'] ?? $site['default_language_code'] ?? 'fr'); }
    private function payload(): array { $json = $this->request->json(); $data = $json['data'] ?? $json; return is_array($data) ? $data : []; }
    private function meta(array $site): array { return ['site_id' => (int)$site['id'], 'language_code' => $this->language($site)]; }
    private function idParam(string|int $id): int { return max(0, (int)$id); }
    private function notFound(string|int $id): Response { return Response::error(ErrorCode::ROUTE_NOT_FOUND, 'Formulaire introuvable.', 404, ['id' => (string)$id]); }
}
