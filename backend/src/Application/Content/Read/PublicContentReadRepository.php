<?php

declare(strict_types=1);

namespace App\Application\Content\Read;

interface PublicContentReadRepository
{
    public function listPublishedByType(int $siteId, string $typeKey, string $languageCode): array;
    public function listPublishedArticlesIndex(int $siteId, string $languageCode, int $limit = 6, int $offset = 0, ?string $categorySlug = null, ?string $tagSlug = null): array;
    public function listPublishedHeadless(int $siteId, string $languageCode, array $filters = []): array;
    public function searchPublishedHeadless(int $siteId, string $languageCode, string $query, array $filters = []): array;
    public function listPublicMedia(int $siteId, string $languageCode, int $limit = 24, int $offset = 0, string $type = ''): array;
    public function getPublishedById(int $entryId, string $languageCode): ?array;
    public function getPublishedByPath(int $siteId, string $path, string $languageCode): ?array;
    public function getPublishedByTypeAndSlug(int $siteId, string $typeKey, string $slug, string $languageCode): ?array;
}
