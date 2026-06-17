<?php

declare(strict_types=1);

namespace App\Application\Content\Projection;

use App\Application\Content\SearchDocumentRepository;
use App\Application\Publication\PublishedProjection;

final class SearchProjector
{
    public function __construct(private readonly SearchDocumentRepository $searchDocuments) {}

    /** @param array<string,mixed> $entry @param list<array<string,mixed>> $localizations @param array<string,string> $pathsByLanguage */
    public function project(array $entry, array $localizations, array $pathsByLanguage): void
    {
        foreach ($localizations as $loc) {
            $languageCode = (string) $loc['language_code'];
            $path = $pathsByLanguage[$languageCode] ?? null;
            if ($path === null) {
                continue;
            }
            $this->searchDocuments->saveContentEntryDocument(
                (int) $entry['site_id'],
                (int) $entry['id'],
                $languageCode,
                $path,
                (string) ($loc['title'] ?? $entry['entry_key']),
                '',
                $this->searchText($loc)
            );
        }
    }

    public function projectPublished(PublishedProjection $projection, string $fallbackTitle = ''): void
    {
        $localization = $projection->localizationRow();
        $this->searchDocuments->saveContentEntryDocument(
            $projection->siteId,
            $projection->resourceId,
            $projection->languageCode,
            $projection->path,
            $projection->title() !== '' ? $projection->title() : $fallbackTitle,
            (string) $projection->seo->metaDescription,
            $this->searchText($localization),
            $projection->sourcePublishedRevisionId,
            $projection->sourceRevisionChecksumSha256
        );
    }

    /** @param array<string,mixed> $loc */
    private function searchText(array $loc): string
    {
        $text = implode(' ', [
            (string) ($loc['title'] ?? ''),
        ]);
        return trim((string) preg_replace('/\s+/', ' ', strip_tags($text)));
    }
}
