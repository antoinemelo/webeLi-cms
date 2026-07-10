<?php

declare(strict_types=1);

namespace App\Application\Frontend;

use App\Application\Maintenance\VersionInventoryService;
use App\Core\Response;

final class UpdateManifestController
{
    public function __construct(private readonly VersionInventoryService $versions) {}

    public function show(): Response
    {
        $body = json_encode(
            $this->versions->localManifest('reference'),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($body === false) {
            return Response::error('api.error', 'Manifest de mise à jour indisponible.', 500);
        }

        return new Response(200, $body, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
