<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Schema\GetEditorSchema;
use App\Application\Schema\ListContentTypes;
use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Core\ErrorCode;
use App\Core\Request;
use App\Core\Response;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;

final class ContentTypeApiController
{
    public function __construct(
        private readonly Request $request,
        private readonly AuthRepository $auth,
        private readonly SiteRepository $sites,
        private readonly Authorization $authorization,
        private readonly ListContentTypes $listContentTypes,
        private readonly GetEditorSchema $getEditorSchema,
    ) {}

    public function index(): Response
    {
        $this->auth->requireAuth();
        $site = AdminApiContract::siteContext($this->request, $this->sites, $this->optionalInt($this->request->input('site_id')), $this->auth);
        $this->authorization->require('content.read', (int) $site['id']);
        return Response::success($this->listContentTypes->execute(true), 'admin.content_types.index.v1', AdminApiContract::meta($site, (string) ($site['default_language_code'] ?? 'fr')));
    }

    public function editorSchema(string $type): Response
    {
        $this->auth->requireAuth();
        $site = AdminApiContract::siteContext($this->request, $this->sites, $this->optionalInt($this->request->input('site_id')), $this->auth);
        $this->authorization->require('content.read', (int) $site['id']);

        $lastError = null;
        $candidates = $this->contentTypeKeyCandidates($type);
        foreach ($candidates as $candidate) {
            try {
                return Response::success($this->getEditorSchema->execute($candidate, (int) $site['id']), 'admin.content_types.editor_schema.v1', AdminApiContract::meta($site, (string) ($site['default_language_code'] ?? 'fr')));
            } catch (\RuntimeException $e) {
                $lastError = $e;
            }
        }

        return Response::error(ErrorCode::CONTENT_TYPE_NOT_FOUND, ErrorCode::message(ErrorCode::CONTENT_TYPE_NOT_FOUND), ErrorCode::httpStatus(ErrorCode::CONTENT_TYPE_NOT_FOUND), [
            'type_key' => $type,
            'normalized_candidates' => $candidates,
            'available_content_types' => array_map(
                static fn(array $item): string => (string) ($item['type_key'] ?? ''),
                $this->listContentTypes->execute(true),
            ),
            'reason' => $lastError?->getMessage(),
        ]);
    }


    private function optionalInt(mixed $value): ?int
    {
        if ($value === null || $value === '') { return null; }
        $int = (int) $value;
        return $int > 0 ? $int : null;
    }

    /**
     * Resolve stable content type keys from admin UI route segments and legacy
     * labels. This deliberately accepts `pages`, `articles`, full UI paths such
     * as `/contents/pages/42`, singular/plural labels and the canonical DB key.
     *
     * @return list<string>
     */
    private function contentTypeKeyCandidates(string $rawType): array
    {
        $rawType = strtolower(trim($rawType));
        $rawType = trim($rawType, " \t\n\r\0\x0B/");
        $rawType = preg_replace('/[^a-z0-9_\-\/]/', '', $rawType) ?: '';

        $segments = array_values(array_filter(explode('/', $rawType), static fn(string $segment): bool => $segment !== ''));
        $lastSemanticSegment = '';
        foreach (array_reverse($segments) as $segment) {
            if (in_array($segment, ['contents', 'content', 'new', 'edit', 'modifier', 'creer', 'créer'], true) || ctype_digit($segment)) {
                continue;
            }
            $lastSemanticSegment = $segment;
            break;
        }

        $normalized = $lastSemanticSegment !== '' ? $lastSemanticSegment : $rawType;
        $aliases = [
            'pages' => 'page',
            'page' => 'page',
            'articles' => 'article',
            'article' => 'article',
        ];

        $candidates = [];
        $this->pushCandidate($candidates, $normalized);
        $this->pushCandidate($candidates, $aliases[$normalized] ?? '');
        $this->pushCandidate($candidates, rtrim($normalized, 's'));

        foreach ($this->listContentTypes->execute(true) as $contentType) {
            $typeKey = (string) ($contentType['type_key'] ?? '');
            if ($typeKey === '') { continue; }
            $tokens = [
                $typeKey,
                (string) ($contentType['name'] ?? ''),
                (string) ($contentType['singular_label'] ?? ''),
                (string) ($contentType['plural_label'] ?? ''),
            ];
            foreach ($tokens as $token) {
                $tokenKey = $this->normalizeToken($token);
                if ($tokenKey !== '' && ($tokenKey === $normalized || rtrim($tokenKey, 's') === rtrim($normalized, 's'))) {
                    $this->pushCandidate($candidates, $typeKey);
                }
            }
        }

        return $candidates === [] ? ['page', 'article'] : $candidates;
    }

    /** @param list<string> $candidates */
    private function pushCandidate(array &$candidates, string $candidate): void
    {
        $candidate = $this->normalizeToken($candidate);
        if ($candidate !== '' && !in_array($candidate, $candidates, true)) {
            $candidates[] = $candidate;
        }
    }

    private function normalizeToken(string $value): string
    {
        $value = strtolower(trim($value));
        $value = strtr($value, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
            'ç' => 'c',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ñ' => 'n',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ý' => 'y', 'ÿ' => 'y',
            'œ' => 'oe', 'æ' => 'ae',
        ]);
        $value = preg_replace('/[^a-z0-9_\-\/]+/', '-', $value) ?: '';
        return trim($value, '-/');
    }
}
