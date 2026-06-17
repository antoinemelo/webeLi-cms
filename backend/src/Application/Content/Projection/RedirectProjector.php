<?php

declare(strict_types=1);

namespace App\Application\Content\Projection;

use App\Application\Routing\RedirectRepository;
use App\Domain\Routing\Redirect;

final class RedirectProjector
{
    public function __construct(private readonly RedirectRepository $redirects) {}

    /**
     * @param array<string,mixed> $entry
     * @param list<array<string,mixed>> $oldRoutes
     * @param array<string,string> $pathsByLanguage
     */
    public function projectPathChanges(array $entry, array $oldRoutes, array $pathsByLanguage): void
    {
        foreach ($oldRoutes as $old) {
            $languageCode = (string) $old['language_code'];
            $newPath = $pathsByLanguage[$languageCode] ?? null;
            if ($newPath === null || $newPath === $old['full_path']) {
                continue;
            }

            $this->redirects->replace(new Redirect(
                (int) $entry['site_id'],
                (string) $old['full_path'],
                $newPath,
                $languageCode,
                Redirect::MOVED_PERMANENTLY,
                'path_changed',
                true,
                RouteProjector::RESOURCE_TYPE,
                (int) $entry['id']
            ));
        }
    }
}
