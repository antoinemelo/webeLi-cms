<?php

declare(strict_types=1);

namespace App\Application\Content;

interface EntryPayloadValidationRepository
{
    public function entryKeyExists(int $siteId, string $entryKey, ?int $excludeEntryId = null): bool;
    public function slugExists(int $siteId, string $languageCode, string $slug, ?int $excludeEntryId = null): bool;
    public function uniqueFieldValueExists(int $siteId, int $contentTypeId, string $fieldKey, string $languageCode, string $normalizedValue, ?int $excludeEntryId = null): bool;
    public function mediaExists(int $siteId, int $mediaId): bool;
    public function mediaMimeType(int $siteId, int $mediaId): ?string;
    public function mediaType(int $siteId, int $mediaId): ?string;
    public function mediaHasAlt(int $siteId, int $mediaId, string $languageCode): bool;
    public function contentEntryExists(int $siteId, int $entryId): bool;
    public function taxonomyTermExists(int $siteId, int $termId, ?string $taxonomyKey = null): bool;

    /**
     * @return list<array{id:int,taxonomy_key:string,name:string,is_required:int,max_terms:int|null}>
     */
    public function contentTypeTaxonomyPolicies(int $siteId, int $contentTypeId): array;

    /**
     * @param list<int> $termIds
     * @return array<int,array{term_id:int,taxonomy_id:int,taxonomy_key:string}>
     */
    public function taxonomyTermsById(int $siteId, array $termIds): array;
    /** @param array<string,string> $uniqueValues */
    public function replaceUniqueFieldValues(int $siteId, int $contentTypeId, int $entryId, string $languageCode, array $uniqueValues): void;
    public function reserveSlug(int $siteId, int $entryId, string $languageCode, string $slug): void;
}
