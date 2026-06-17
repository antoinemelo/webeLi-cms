<?php

declare(strict_types=1);

namespace App\Application\PublicApi;

use App\Core\Response;

final class PublicApiResponder
{
    /** @param array<string,mixed> $meta @param array<string,string> $headers */
    public function success(mixed $data, string $contract, array $meta = [], int $status = 200, array $headers = []): Response
    {
        return Response::success($data, $contract, $meta, $status, $this->headers($meta, $headers));
    }

    /** @param array<string,mixed> $details @param array<string,string> $headers */
    public function error(string $code, string $message, int $status, array $details = [], array $headers = []): Response
    {
        return Response::error($code, $message, $status, $details, $this->headers($details, $headers));
    }

    /** @param array<string,list<string>> $fields */
    public function validation(array $fields, string $message = 'Le contenu contient des erreurs.', int $status = 422): Response
    {
        return Response::validation($fields, $message, $status, $this->headers([]));
    }

    /** @param array<string,mixed> $context @param array<string,string> $headers @return array<string,string> */
    public function headers(array $context = [], array $headers = []): array
    {
        $language = strtolower(trim((string) ($context['language_code'] ?? '')));
        $defaults = [
            'Cache-Control' => 'public, max-age=300, stale-while-revalidate=60',
            'Vary' => 'Accept, Accept-Encoding, Authorization, Origin',
        ];
        if ($language !== '') {
            $defaults['Content-Language'] = $language;
        }
        return $headers + $defaults;
    }
}
